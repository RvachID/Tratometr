<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use app\models\PriceEntry;
use yii\filters\RateLimiter;
use app\models\PurchaseSession;

class ScanController extends Controller
{


    public $enableCsrfValidation = true;

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($action->id === 'recognize') {
            $this->enableCsrfValidation = false; // только для upload/recognize
        }

        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = ['success' => false, 'error' => 'Не авторизован'];
            return false;
        }
        return parent::beforeAction($action);
    }


    public function behaviors()
    {
        $b = parent::behaviors();
        $b['rateLimiter'] = [
            'class' => RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'only' => ['recognize'], // ограничиваем только upload
        ];
        return $b;
    }


    /**
     * Распознавание текста через OCR API
     */
    /**
     * Распознавание OCR.space с нужными флагами.
     * ВАЖНО: компонент \Yii::$app->ocr->parseImage должен уметь принимать 3-й аргумент (массив опций POST).
     */
    private function recognizeText(string $filePath): array
    {
        try {
            $apiResponse = \Yii::$app->ocr->parseImage($filePath, 'rus', [
                'isOverlayRequired' => true,
                'scale' => true,   // апскейл для мелкого текста
                'detectOrientation' => true,
                'OCREngine' => 2,      // у OCR.space обычно точнее Overlay
            ]);

            if (!empty($apiResponse['IsErroredOnProcessing'])) {
                $msg = $apiResponse['ErrorMessage'] ?? $apiResponse['ErrorDetails'] ?? 'OCR: ошибка обработки';
                return ['error' => $msg, 'full_response' => $apiResponse];
            }

            $results = $apiResponse['ParsedResults'][0] ?? null;
            if (!$results) {
                return ['error' => 'Пустой ответ OCR', 'full_response' => $apiResponse];
            }

            return [
                'ParsedText' => $results['ParsedText'] ?? '',
                'TextOverlay' => $results['TextOverlay'] ?? ['Lines' => []],
                'full_response' => $apiResponse,
            ];
        } catch (\Throwable $e) {
            \Yii::error($e->getMessage(), __METHOD__);
            return ['error' => 'Сбой OCR: ' . $e->getMessage()];
        }
    }

    /**
     * Берём число из OCR Overlay по наибольшей площади.
     * Возвращает float или null, если в Overlay ничего подходящего.
     */
    /**
     * Берём цену из OCR Overlay по максимальной ПЛОЩАДИ bbox группы числовых токенов.
     * Учитываем бонусы за копейки, штрафуем короткие/«копеечные» значения.
     */
    /**
     * Достаём цену из Overlay по ПЛОЩАДИ bbox ГРУППЫ числовых токенов.
     * Фильтруем перечёркнутые и проценты. Бонусы за копейки.
     */
    private function extractAmountByOverlay(array $recognized): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines) || !count($lines)) {
            return null;
        }

        $bestValue = null;
        $bestScore = -INF;

        $norm = function (string $raw): ?float {
            return $this->normalizeOcrNumber($raw);
        };

        foreach ($lines as $line) {
            $words = $line['Words'] ?? [];
            if (!is_array($words) || !count($words)) continue;

            // приведение типов + чистка
            foreach ($words as &$w) {
                $w['WordText'] = (string)($w['WordText'] ?? '');
                $w['IsStrikethrough'] = !empty($w['IsStrikethrough']);
                $w['Height'] = isset($w['Height']) ? (int)$w['Height'] : 0;
                $w['Left'] = isset($w['Left']) ? (int)$w['Left'] : 0;
                $w['Top'] = isset($w['Top']) ? (int)$w['Top'] : 0;
                $w['Width'] = isset($w['Width']) ? (int)$w['Width'] : 0;

                // убираем валюты/буквы внутри слова, оставляем цифры и , .
                $w['WordText'] = preg_replace('~[^\d.,\s%]~u', '', $w['WordText']);
            }
            unset($w);

            $group = [];
            $flush = function () use (&$group, $norm, &$bestValue, &$bestScore) {
                if (!count($group)) return;

                // объединяем в одну строку без пробелов
                $raw = implode('', array_map(fn($g) => preg_replace('~\s+~u', '', $g['WordText']), $group));
                $val = $norm($raw);

                // bbox группы
                $minL = min(array_column($group, 'Left'));
                $maxR = max(array_map(fn($g) => $g['Left'] + $g['Width'], $group));
                $minT = min(array_column($group, 'Top'));
                $maxB = max(array_map(fn($g) => $g['Top'] + $g['Height'], $group));

                $gWidth = max(1, $maxR - $minL);
                $gHeight = max(1, $maxB - $minT);
                $area = $gWidth * $gHeight;

                if ($val !== null) {
                    $hasSep = (bool)preg_match('~[.,]~', $raw);
                    $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $raw);
                    $digits = preg_match_all('~\d~', $raw);

                    // базовый скор: площадь bbox
                    $score = (float)$area;

                    // бонусы
                    if ($hasCents) $score *= 1.40;
                    if ($digits >= 3) $score *= 1.15;

                    // штрафы
                    if ($val < 1.0) $score *= 0.2;           // .50 и т.п.
                    if ($digits <= 2 && !$hasCents) $score *= 0.6;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestValue = $val;
                    }
                }

                $group = [];
            };

            foreach ($words as $w) {
                // отбрасываем перечеркнутые (старые цены) и проценты
                if ($w['IsStrikethrough']) {
                    $flush();
                    continue;
                }
                if (strpos($w['WordText'], '%') !== false) {
                    $flush();
                    continue;
                }

                $t = preg_replace('~\s+~u', '', $w['WordText']); // внутри слова
                if ($t !== '' && preg_match('~^[\d.,]+$~u', $t)) {
                    $group[] = $w;
                } else {
                    $flush();
                }
            }
            $flush();
        }

        return $bestValue;
    }


    /**
     * Нормализуем «числовое» слово из OCR в float.
     * Чиним: '449 99' / '449,99' / '449·99' / '1 299,90' / '44999' → 449.99.
     * Отсекаем проценты/дроби, мусор и нереалистичные значения.
     */
    private function normalizeOcrNumber(string $s): ?float
    {
        $s = trim($s);
        if ($s === '' || preg_match('/[%\/]/u', $s)) return null; // отсекаем проценты и дроби

        // разные пробелы → обычный
        $s = str_replace(["\xC2\xA0", ' ', ' '], ' ', $s);

        // помечаем разделитель копеек между цифрами (перед ровно 2 цифрами в конце «слова»)
        $s = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $s);

        // убираем тысячные разделители (пробел/точка/цент.точка перед 3 цифрами)
        $s = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $s);

        // чистим любые оставшиеся пробелы между цифрами
        $s = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $s);

        // финальный разделитель копеек — точка
        $s = str_replace('#', '.', $s);

        // валидный формат: целое или с копейками
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) {
            $v = (float)$s;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        // если OCR «съел» точку: 4–6 цифр как копейки (44999 → 449.99)
        if (preg_match('/^\d{4,6}$/', $s)) {
            $n = (int)$s;
            $v = $n / 100.0;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        // просто целое: допустим
        if (preg_match('/^\d+$/', $s)) {
            $v = (float)$s;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        return null;
    }

    /**
     * Умный разбор суммы из распознанного текста.
     * Правит '449 99' / '449,99' → '449.99', убирает тысячные разделители,
     * пытается восстановить копейки из 4–6-значных целых (44999 → 449.99).
     */
    private function extractAmount(string $text): float
    {
        // 1) Приведём похожие символы к обычным
        $text = str_replace(
            ["\xC2\xA0", ' ', ' ', '﻿'], // NBSP, thin space, hair space, BOM
            ' ',
            $text
        );

        // 2) Помечаем возможный разделитель копеек (между цифрами перед ровно 2 цифрами в конце "слова")
        // 449 99 / 449,99 / 449·99 / 449•99  -> 449#99
        $tmp = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $text);

        // 3) Убираем тысячные разделители внутри числа (пробелы/точки/тонкие пробелы перед 3 цифрами)
        $tmp = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $tmp);

        // 4) Удалим любые оставшиеся пробелы внутри числа
        $tmp = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $tmp);

        // 5) Возвращаем точку как разделитель копеек
        $normalized = str_replace('#', '.', $tmp);

        // --- Сначала пытаемся взять числа с копейками
        if (preg_match_all('/\d+(?:\.\d{1,2})/', $normalized, $m1)) {
            $candidates = array_map('floatval', $m1[0]);
            // Отфильтруем разумные цены (1..99999), возьмём максимум
            $candidates = array_filter($candidates, fn($v) => $v >= 0.01 && $v <= 99999);
            if (!empty($candidates)) {
                return max($candidates);
            }
        }

        // --- Если копеек нигде нет, пробуем восстановить их из целых 4–6-значных чисел
        if (preg_match_all('/\d{3,6}/', $normalized, $m2)) {
            $best = 0.0;
            foreach ($m2[0] as $raw) {
                $n = (int)$raw;

                // Кандидат как есть (цена может быть целой)
                $asIs = (float)$n;

                // Кандидат как цена с копейками (последние 2 цифры — копейки), только для 4–6 знаков
                $asCents = ($n >= 1000 && $n <= 999999) ? $n / 100.0 : 0.0;

                // Оба варианта должны быть в разумных пределах
                foreach ([$asIs, $asCents] as $val) {
                    if ($val >= 0.01 && $val <= 99999 && $val > $best) {
                        $best = $val;
                    }
                }
            }
            if ($best > 0) {
                return $best;
            }
        }

        return 0.0;
    }

    /**
     * Предобработка изображения: ресайз, ч/б, контраст
     */
    /**
     * Мягкая и стабильная предобработка:
     * - сохраняем цвет;
     * - только resize + лёгкая резкость;
     * - без контраста, без GRAY, без кропа.
     */
    private function preprocessImage(string $filePath): bool
    {
        Yii::info('Обработка изображения (safe, keep-color) начата', __METHOD__);
        try {
            $im = new \Imagick($filePath);
            $im->autoOrient(); // если есть EXIF

            // OCR обычно лучше на 1000–1600 px по ширине
            $w = $im->getImageWidth();
            if ($w > 1280) {
                $im->resizeImage(1280, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            // Очень мягкая резкость без «перешарпа»
            $im->unsharpMaskImage(0.5, 0.5, 0.8, 0.01);

            // JPEG качество
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);

            $ok = $im->writeImage($filePath);
            $im->clear();
            $im->destroy();

            Yii::info('Safe-обработка завершена', __METHOD__);
            return $ok;
        } catch (\Throwable $e) {
            Yii::error('Ошибка обработки изображения: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function actionRecognize()
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            $image = \yii\web\UploadedFile::getInstanceByName('image');
            if (!$image) {
                return ['success' => false, 'error' => 'Изображение не загружено'];
            }

            $ext = strtolower($image->extension ?: 'jpg');
            $sizeLimit = 1024 * 1024; // лимит OCR.space

            // сохраняем сырой
            $rawPath = \Yii::getAlias('@runtime/' . uniqid('scan_raw_') . '.' . $ext);
            if (!$image->saveAs($rawPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }

            // копия под soft-предобработку
            $procPath = \Yii::getAlias('@runtime/' . uniqid('scan_proc_') . '.' . $ext);
            @copy($rawPath, $procPath);
            if (!$this->preprocessImage($procPath)) {
                @unlink($procPath);
                $procPath = null;
            }

            // контроль размера
            if ($procPath && @filesize($procPath) > $sizeLimit) {
                @unlink($procPath);
                $procPath = null;
            }
            if (!$procPath && @filesize($rawPath) > $sizeLimit) {
                @unlink($rawPath);
                return ['success' => false, 'error' => 'Размер файла превышает 1 МБ'];
            }

            $run = function (string $path) {
                $recognized = $this->recognizeText($path);
                if (isset($recognized['error'])) {
                    return ['error' => $recognized['error'], 'reason' => 'ocr', 'recognized' => $recognized];
                }

                $amount = $this->extractAmountByOverlay($recognized);
                if ($amount === null || $amount === 0.0) {
                    $amount = $this->extractAmount($recognized['ParsedText'] ?? '');
                }
                if (!$amount) {
                    return ['error' => 'no_amount', 'reason' => 'no_amount', 'recognized' => $recognized];
                }

                return ['amount' => $amount, 'recognized' => $recognized];
            };

            // проход 1: обработанное
            $usedPass = 'processed';
            $r1 = $procPath ? $run($procPath) : ['error' => 'preprocess_failed', 'reason' => 'preprocess'];

            // если не нашли — проход 2: сырое
            if (empty($r1['amount'])) {
                $usedPass = 'raw';
                if (@filesize($rawPath) > $sizeLimit) {
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);
                    return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                }

                $r2 = $run($rawPath);
                if (empty($r2['amount'])) {
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);

                    if (($r1['reason'] ?? '') === 'ocr' || ($r2['reason'] ?? '') === 'ocr') {
                        return ['success' => false, 'error' => 'Ошибка OCR', 'reason' => 'ocr'];
                    }
                    if (($r1['reason'] ?? '') === 'no_amount' || ($r2['reason'] ?? '') === 'no_amount') {
                        return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                    }
                    return ['success' => false, 'error' => 'Текст не распознан', 'reason' => 'empty'];
                }

                @unlink($rawPath);
                if ($procPath) @unlink($procPath);
                return [
                    'success' => true,
                    'recognized_amount' => $r2['amount'],
                    'parsed_text' => $r2['recognized']['ParsedText'] ?? '',
                    'pass' => $usedPass, // 'raw'
                ];
            }

            @unlink($rawPath);
            if ($procPath) @unlink($procPath);
            return [
                'success' => true,
                'recognized_amount' => $r1['amount'],
                'parsed_text' => $r1['recognized']['ParsedText'] ?? '',
                'pass' => $usedPass, // 'processed'
            ];

        } catch (\Throwable $e) {
            \Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    public function actionStore()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            if (Yii::$app->user->isGuest) {
                return ['success' => false, 'error' => 'Требуется вход'];
            }

            // Активная серверная сессия (без методов другого контроллера)
            $ps = PurchaseSession::find()
                ->where(['user_id' => Yii::$app->user->id, 'status' => PurchaseSession::STATUS_ACTIVE])
                ->orderBy(['updated_at' => SORT_DESC])
                ->limit(1)->one();

            if (!$ps) {
                return ['success' => false, 'error' => 'Нет активной покупки. Начните или возобновите сессию.'];
            }

            $amount = Yii::$app->request->post('amount');
            $qty    = Yii::$app->request->post('qty', 1);
            $note   = (string)Yii::$app->request->post('note', '');
            $text   = (string)Yii::$app->request->post('parsed_text', '');

            if (!is_numeric($amount) || (float)$amount <= 0) return ['success' => false, 'error' => 'Неверная сумма'];
            if (!is_numeric($qty)    || (float)$qty    <= 0) $qty = 1;

            $entry = new PriceEntry();
            $entry->user_id           = Yii::$app->user->id;
            $entry->session_id        = $ps->id;                // ВАЖНО
            $entry->amount            = (float)$amount;
            $entry->qty               = (float)$qty;
            $entry->store             = $ps->shop;              // из сессии
            $entry->category          = $ps->category ?: null;  // из сессии
            $entry->note              = $note;
            $entry->recognized_text   = $text;
            $entry->recognized_amount = (float)$amount;
            $entry->source            = 'price_tag';
            $entry->created_at        = time();
            $entry->updated_at        = time();

            if (!$entry->save(false)) {
                return ['success' => false, 'error' => 'Ошибка сохранения'];
            }

            $ps->updateAttributes(['updated_at' => time()]);

            $total = (float) PriceEntry::find()
                ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
                ->sum('amount * qty');

            return [
                'success' => true,
                'entry'   => [
                    'id'       => $entry->id,
                    'amount'   => $entry->amount,
                    'qty'      => $entry->qty,
                    'note'     => (string)$entry->note,
                    'store'    => (string)$entry->store,
                    'category' => $entry->category,
                ],
                'total'   => $total,
            ];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    public function actionUpdate($id)
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $ps = \app\models\PurchaseSession::activeForUser(Yii::$app->user->id);
        if (!$ps) return ['success'=>false, 'error'=>'Нет активной покупки.'];

        $model = \app\models\PriceEntry::findOne([
            'id' => (int)$id,
            'user_id' => Yii::$app->user->id,
            // редактировать позволяем только в рамках активной сессии
            // 'session_id' => $ps->id, // можно включить, если записи уже все с сессией
        ]);
        if (!$model) return ['success'=>false, 'error'=>'Запись не найдена'];

        $model->load(Yii::$app->request->post(), '');

        // Жёстко фиксируем владельца и привязку к сессии
        $model->user_id    = Yii::$app->user->id;
        if ((int)$model->session_id !== (int)$ps->id) {
            $model->session_id = $ps->id;
            // на всякий — синхронизируем контекст
            $model->store    = $ps->shop;
            $model->category = $ps->category ?: null;
        }

        if (!$model->validate()) {
            return ['success'=>false, 'error'=>current($model->firstErrors) ?: 'Ошибка валидации'];
        }
        $model->save(false);

        // total только по текущей сессии
        $total = (float)\app\models\PriceEntry::find()
            ->where(['user_id'=>Yii::$app->user->id, 'session_id'=>$ps->id])
            ->sum('amount * qty');

        $ps->updateAttributes(['updated_at'=>time()]);

        return ['success'=>true, 'total'=>number_format($total, 2, '.', '')];
    }

    public function actionDelete($id)
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        if (!Yii::$app->request->isPost) {
            Yii::$app->response->statusCode = 405;
            return ['success' => false, 'error' => 'Метод не поддерживается'];
        }

        $m = \app\models\PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$m) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Запись не найдена'];
        }

        if ($m->delete() === false) {
            return ['success' => false, 'error' => 'Не удалось удалить'];
        }

        // ---- ТОТАЛ ТОЛЬКО ПО ТЕКУЩЕЙ СЕССИИ ----
        $sess        = Yii::$app->session->get('shopSession', []);
        $storeSess   = (string)($sess['store'] ?? '');
        $catSess     = (string)($sess['category'] ?? '');
        $startedSess = (int)($sess['started_at'] ?? 0);

        $db    = Yii::$app->db;
        $total = 0.0;

        if ($storeSess !== '' && $startedSess > 0) {
            if ($catSess === '') {
                $total = (float)$db->createCommand(
                    'SELECT COALESCE(SUM(amount*qty),0)
                 FROM price_entry
                 WHERE user_id=:u AND store=:s AND category IS NULL AND created_at>=:from',
                    [':u' => Yii::$app->user->id, ':s' => $storeSess, ':from' => $startedSess]
                )->queryScalar();
            } else {
                $total = (float)$db->createCommand(
                    'SELECT COALESCE(SUM(amount*qty),0)
                 FROM price_entry
                 WHERE user_id=:u AND store=:s AND category=:c AND created_at>=:from',
                    [':u' => Yii::$app->user->id, ':s' => $storeSess, ':c' => $catSess, ':from' => $startedSess]
                )->queryScalar();
            }
        }

        return ['success' => true, 'total' => $total];
    }


}
