<?php

namespace app\controllers;

use app\models\PriceEntry;
use app\models\PurchaseSession;
use Yii;
use yii\filters\RateLimiter;
use yii\web\Controller;
use yii\web\Response;

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

            // Нормализуем слова, оставляя только цифры/разделители, при этом ЗАПОМИНАЕМ, был ли %.
            foreach ($words as &$w) {
                $orig = (string)($w['WordText'] ?? '');

                $w['WordText']        = preg_replace('~[^\d.,\s]~u', '', $orig); // % тоже убираем
                $w['__hadPercent']    = (strpos($orig, '%') !== false);          // флажок для .99
                $w['IsStrikethrough'] = !empty($w['IsStrikethrough']);
                $w['Height']          = isset($w['Height']) ? (int)$w['Height'] : 0;
                $w['Left']            = isset($w['Left']) ? (int)$w['Left'] : 0;
                $w['Top']             = isset($w['Top']) ? (int)$w['Top'] : 0;
                $w['Width']           = isset($w['Width']) ? (int)$w['Width'] : 0;
            }
            unset($w);

            $group = [];
            $flush = function () use (&$group, $norm, &$bestValue, &$bestScore) {
                if (!count($group)) return;

                // Собираем «сырое» число без пробелов
                $raw = implode('', array_map(fn($g) => preg_replace('~\s+~u', '', $g['WordText']), $group));
                $val = $norm($raw);

                // bbox группы
                $minL = min(array_column($group, 'Left'));
                $maxR = max(array_map(fn($g) => $g['Left'] + $g['Width'], $group));
                $minT = min(array_column($group, 'Top'));
                $maxB = max(array_map(fn($g) => $g['Top'] + $g['Height'], $group));

                $gWidth  = max(1, $maxR - $minL);
                $gHeight = max(1, $maxB - $minT);
                $area    = $gWidth * $gHeight;

                if ($val !== null) {
                    $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $raw);
                    $digits   = preg_match_all('~\d~', $raw);

                    $score = (float)$area;
                    if ($hasCents) $score *= 1.40;
                    if ($digits >= 3) $score *= 1.15;
                    if ($val < 1.0) $score *= 0.2;
                    if ($digits <= 2 && !$hasCents) $score *= 0.6;

                    // NEW: если в группе встречался %, а число целое — считаем это как ".99"
                    $groupHadPercent = false;
                    foreach ($group as $g) { if (!empty($g['__hadPercent'])) { $groupHadPercent = true; break; } }
                    if ($groupHadPercent && abs($val - floor($val)) < 0.0001) {
                        $val   = floor($val) + 0.99;
                        $score *= 1.25; // небольшой бонус
                    }

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestValue = $val;
                    }
                }

                $group = [];
            };

            foreach ($words as $w) {
                if ($w['IsStrikethrough']) { $flush(); continue; }

                $t = preg_replace('~\s+~u', '', $w['WordText']);
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
    /**
     * Умный разбор суммы из распознанного текста.
     * Добавлено: паттерн "307%" трактуем как 307.99 (с приоритетом),
     * а для 4–6-значных слепленных чисел предпочитаем вариант /100 (3079 -> 30.79, 30799 -> 307.99).
     */
    private function extractAmount(string $text): float
    {
        // Нормализация пробелов и похожих символов
        $text = str_replace(["\xC2\xA0", ' ', '﻿'], ' ', $text);

        $cands = [];

        // 0) Спец-кейс: "XXX%" -> XXX.99  (если XXX >= 100; валюта рядом усиливает)
        $lower = mb_strtolower($text, 'UTF-8');
        if (preg_match_all('/\b(\d{2,6})\s*%/u', $lower, $mp, PREG_OFFSET_CAPTURE)) {
            foreach ($mp[1] as $i => $m) {
                $intStr = $m[0];
                $pos    = $mp[0][$i][1];
                $intVal = (int)$intStr;
                if ($intVal >= 100 && $intVal <= 9999) {
                    $win   = mb_substr($lower, $pos, 40, 'UTF-8');
                    $score = 2.5;
                    if (preg_match('/\b(rs?d|din|dinara|руб|rub|₽|eur|€|usd|\$)\b/u', $win)) $score += 0.8;
                    $cands[] = [$intVal + 0.99, $score];
                }
            }
        }

        // 1) Пометить разделитель копеек, убрать тысячные разделители
        $tmp = $text;
        $tmp = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $tmp);   // 307 99 / 307,99 -> 307#99
        $tmp = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $tmp);     // 1 299,90 -> 1299,90
        $tmp = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $tmp);
        $normalized = str_replace('#', '.', $tmp);

        // 2) Явные десятичные
        if (preg_match_all('/\d+(?:\.\d{1,2})/', $normalized, $m1)) {
            foreach ($m1[0] as $s) {
                $v = (float)$s;
                if ($v < 0.01 || $v > 99999) continue;

                $score = 1.5;
                $frac  = (int)round(($v - floor($v)) * 100);
                $ilen  = strlen((string)floor($v));

                // подозрительно: ".00" у длинной целой — возможно, склейка
                if ($frac === 0 && $ilen >= 4) $score -= 1.0;
                if ($v > 9999) $score -= 1.0;

                $cands[] = [$v, $score];

                // исправляющая гипотеза для "30799.00" -> 307.99
                if ($frac === 0 && $ilen >= 4) {
                    $v2 = floor($v) / 100.0;
                    if ($v2 > 0 && $v2 <= 9999) $cands[] = [$v2, $score + 1.5];
                }
            }
        }

        // 3) Чистые целые 3–6 цифр (в т.ч. склейка копеек)
        if (preg_match_all('/\b\d{3,6}\b/', $normalized, $m2)) {
            foreach ($m2[0] as $raw) {
                $n   = (int)$raw;
                $len = strlen($raw);

                $asIs    = (float)$n;            // 3079 -> 3079.00
                $asCents = ($len >= 4) ? $n/100.0 : 0.0; // 3079 -> 30.79; 30799 -> 307.99

                if ($asIs > 0 && $asIs <= 99999) {
                    $cands[] = [$asIs, ($asIs >= 1000 ? 0.3 : 0.8)];
                }
                if ($asCents > 0 && $asCents <= 9999) {
                    $cands[] = [$asCents, 2.0]; // предпочитаем «со склеенными копейками»
                }
            }
        }

        if (!$cands) return 0.0;
        usort($cands, fn($a,$b) => $b[1] <=> $a[1]); // по убыванию score
        return $cands[0][0];
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
                // 0) Сначала пытаемся пройти «новым» путём: фото → OCR → PriceParser
                try {
                    /** @var \app\components\OcrClient $ocr */
                    $ocr = \Yii::$app->ocr;

                    $res = $ocr->extractPriceFromImage($path, 'rus', [
                        'isOverlayRequired' => true,
                        'OCREngine'         => 2,
                        'scale'             => true,
                        'detectOrientation' => true,
                    ]); // parserOpts не передаём — дефолты внутри PriceParser

                    if (!empty($res['success']) && $res['success'] === true && !empty($res['amount'])) {
                        // Отдаём в том же формате, который ожидает нижний код
                        return [
                            'amount'     => (float)$res['amount'],
                            'recognized' => [
                                'ParsedText' => (string)($res['text'] ?? ''),
                                // TextOverlay не обязателен на этом пути
                            ],
                        ];
                    }
                } catch (\Throwable $e) {
                    \Yii::warning('extractPriceFromImage failed: ' . $e->getMessage(), __METHOD__);
                    // Пойдём по старому пути
                }

                // 1) Фолбэк: старый путь — OCR → Overlay → (bbox-скоринг) → строковый парсер
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
            $qty = Yii::$app->request->post('qty', 1);
            $note = (string)Yii::$app->request->post('note', '');
            $text = (string)Yii::$app->request->post('parsed_text', '');

            if (!is_numeric($amount) || (float)$amount <= 0) return ['success' => false, 'error' => 'Неверная сумма'];
            if (!is_numeric($qty) || (float)$qty <= 0) $qty = 1;

            $entry = new PriceEntry();
            $entry->user_id = Yii::$app->user->id;
            $entry->session_id = $ps->id;                // ВАЖНО
            $entry->amount = (float)$amount;
            $entry->qty = (float)$qty;
            $entry->store = $ps->shop;              // из сессии
            $entry->category = $ps->category ?: null;  // из сессии
            $entry->note = $note;
            $entry->recognized_text = $text;
            $entry->recognized_amount = (float)$amount;
            $entry->source = 'price_tag';
            $entry->created_at = time();
            $entry->updated_at = time();

            if (!$entry->save(false)) {
                return ['success' => false, 'error' => 'Ошибка сохранения'];
            }

            $ps->updateAttributes(['updated_at' => time()]);

            $total = (float)PriceEntry::find()
                ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
                ->sum('amount * qty');

            return [
                'success' => true,
                'entry' => [
                    'id' => $entry->id,
                    'amount' => $entry->amount,
                    'qty' => $entry->qty,
                    'note' => (string)$entry->note,
                    'store' => (string)$entry->store,
                    'category' => $entry->category,
                ],
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    /** Автосохранение суммы/qty из строки списка */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->load(Yii::$app->request->post(), '');
        // фиксируем принадлежность к текущей серверной сессии
        $m->user_id = Yii::$app->user->id;
        $m->session_id = $ps->id;
        $m->store = $ps->shop;
        $m->category = $ps->category ?: null;
        $m->updated_at = time();

        if (!$m->save(false)) {
            return ['success' => false, 'error' => 'Не удалось сохранить'];
        }

        Yii::$app->ps->touch($ps);

        $total = (float)PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return ['success' => true, 'total' => number_format($total, 2, '.', '')];
    }

    /** Удаление строки из списка */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id, 'session_id' => $ps->id]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->delete();

        $total = (float)PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return ['success' => true, 'total' => number_format($total, 2, '.', '')];
    }


}
