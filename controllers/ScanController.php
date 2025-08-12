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
    private function recognizeText(string $filePath): array
    {
        try {
            $apiResponse = \Yii::$app->ocr->parseImage($filePath, 'rus');

            // Ошибка на стороне OCR.space
            if (!empty($apiResponse['IsErroredOnProcessing'])) {
                $msg = $apiResponse['ErrorMessage'] ?? $apiResponse['ErrorDetails'] ?? 'OCR: ошибка обработки';
                return ['error' => $msg, 'full_response' => $apiResponse];
            }

            $results = $apiResponse['ParsedResults'][0] ?? null;
            if (!$results) {
                return ['error' => 'Пустой ответ OCR', 'full_response' => $apiResponse];
            }

            // Плоская форма — как ждут твои extract-методы
            return [
                'ParsedText'  => $results['ParsedText']  ?? '',
                'TextOverlay' => $results['TextOverlay'] ?? ['Lines' => []],
                'full_response' => $apiResponse, // оставим для debug
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

            // Приведение типов + чистка
            foreach ($words as &$w) {
                $w['WordText'] = (string)($w['WordText'] ?? '');
                // оставляем цифры и , . (убираем валюты/буквы)
                $w['WordText'] = preg_replace('~[^\d.,\s]~u', '', $w['WordText']);
                $w['Height']   = isset($w['Height']) ? (int)$w['Height'] : 0;
                $w['Left']     = isset($w['Left'])   ? (int)$w['Left']   : 0;
                $w['Top']      = isset($w['Top'])    ? (int)$w['Top']    : 0;
                $w['Width']    = isset($w['Width'])  ? (int)$w['Width']  : 0; // ВАЖНО: ширина для площади
            }
            unset($w);

            $group = [];
            $flush = function () use (&$group, $norm, &$bestValue, &$bestScore) {
                if (!count($group)) return;

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
                    $hasSep   = (bool)preg_match('~[.,]~', $raw);
                    $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $raw);
                    $digits   = preg_match_all('~\d~', $raw);

                    // Базовый счёт — площадь bbox (а не высота одного токена)
                    $score = $area;

                    // Бонусы: копейки и достаточное кол-во цифр (чтобы «480» в тексте не обыгрывало «599.99»)
                    if ($hasCents) $score *= 1.40;
                    if ($digits >= 3) $score *= 1.15;

                    // Штраф слишком мелким/подозрительным значениям
                    if ($val < 1.0)  $score *= 0.2;   // отрезаем «.50» и т.п.
                    if ($digits <= 2 && !$hasCents) $score *= 0.6;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestValue = $val;
                    }
                }

                $group = [];
            };

            foreach ($words as $w) {
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
     * Мягкая предобработка без «черных заливов».
     * - Сохраняем цвет (на тёплом фоне).
     * - Минимально чистим шум и чуть повышаем резкость.
     * - Без агрессивного контраста и бинаризации.
     */
    private function preprocessImage(string $filePath): bool
    {
        Yii::info('Обработка изображения (soft) начата', __METHOD__);
        try {
            $im = new \Imagick($filePath);
            $im->setImageFormat('jpeg');
            $im->autoOrient(); // если EXIF есть

            // 1) Resize (чуть больше, чем 1024 — OCR любит 1200–1600 по ширине)
            $w = $im->getImageWidth();
            if ($w > 1280) {
                $im->resizeImage(1280, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            // 2) Определим "тёплый фон" грубо по средней насыщенности/оттенку (очень дешёвая эвристика)
            $thumb = clone $im;
            $thumb->resizeImage(64, 64, \Imagick::FILTER_BOX, 1);
            $thumb->setImageColorspace(\Imagick::COLORSPACE_HSL);
            $stats = $thumb->getImageChannelMean(\Imagick::CHANNEL_SATURATION);
            $avgSat = $stats['mean'] / \Imagick::getQuantum(); // 0..1
            $thumb->destroy();

            $isWarmBg = $avgSat > 0.20; // «насыщенный фон» -> считаем, что цвет лучше не ломать

            // 3) Минимальная чистка
            if (!$isWarmBg) {
                // На «холодном» фоне можно аккуратно в Ч/Б (без сильного контраста)
                $im->setImageColorspace(\Imagick::COLORSPACE_GRAY);
                $im->setImageType(\Imagick::IMGTYPE_GRAYSCALE);

                // Чуть повысим микроконтраст, но мягко
                $im->contrastImage(true);              // +1 шаг
                $im->brightnessContrastImage(0, 5);    // +5 контраст (Imagick 7+; игнор, если нет)
            } else {
                // На жёлтом/красном фоне оставляем цвет и слегка уменьшаем насыщенность,
                // чтобы OCR лучше видел контуры текста
                $im->modulateImage(100, 90, 100);      // яркость 100%, сатурация -10%, тон 0
            }

            // 4) Небольшая резкость, без «перешарпа»
            $im->unsharpMaskImage(0.5, 0.5, 1.0, 0.02);

            // 5) Больше не режем 5% по краям — можно срезать цену. Если очень нужно:
            // $crop = 0.02; ... но я бы сейчас отключил
            // (оставляем как есть)

            $ok = $im->writeImage($filePath);
            $im->clear();
            $im->destroy();

            Yii::info('Soft-обработка завершена', __METHOD__);
            return $ok;
        } catch (\Throwable $e) {
            Yii::error('Ошибка обработки изображения: '.$e->getMessage(), __METHOD__);
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
            $sizeLimit = 1024 * 1024; // 1 МБ для OCR.space

            // --- Сохраняем сырой файл
            $rawPath = \Yii::getAlias('@runtime/' . uniqid('scan_raw_') . '.' . $ext);
            if (!$image->saveAs($rawPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }

            // --- Готовим копию под предобработку (soft)
            $procPath = \Yii::getAlias('@runtime/' . uniqid('scan_proc_') . '.' . $ext);
            @copy($rawPath, $procPath);
            $procOk = $this->preprocessImage($procPath); // твоя "мягкая" версия

            if (!$procOk) {
                @unlink($procPath);
                $procPath = null;
            }

            // --- Контроль размера: если обработанный > 1 МБ — выбрасываем его
            if ($procPath && @filesize($procPath) > $sizeLimit) {
                @unlink($procPath);
                $procPath = null;
            }

            // Если и сырой >1 МБ, а обработанного нет — сразу ошибка (OCR.space не примет)
            if ((!$procPath) && @filesize($rawPath) > $sizeLimit) {
                @unlink($rawPath);
                return ['success' => false, 'error' => 'Размер файла превышает 1 МБ'];
            }

            // --- Обёртка: запустить OCR и вытащить сумму
            $run = function (string $path) {
                $recognized = $this->recognizeText($path);
                if (isset($recognized['error'])) {
                    // пробрасываем типичные причины
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

            // --- Проход 1: по обработанному изображению (если есть)
            $usedPass = 'processed';
            $r1 = $procPath ? $run($procPath) : ['error' => 'preprocess_failed', 'reason' => 'preprocess'];

            // --- Если не нашли сумму — Проход 2: по сырому изображению
            if (empty($r1['amount'])) {
                $usedPass = 'raw';
                // Если сырой файл >1 МБ, второй проход делать нельзя
                if (@filesize($rawPath) > $sizeLimit) {
                    // выбираем понятную ошибку
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);
                    return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                }

                $r2 = $run($rawPath);
                if (empty($r2['amount'])) {
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);

                    // Приоритизируем причины
                    if (($r1['reason'] ?? '') === 'ocr' || ($r2['reason'] ?? '') === 'ocr') {
                        return ['success' => false, 'error' => 'Ошибка OCR', 'reason' => 'ocr'];
                    }
                    if (($r1['reason'] ?? '') === 'no_amount' || ($r2['reason'] ?? '') === 'no_amount') {
                        return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                    }
                    return ['success' => false, 'error' => 'Текст не распознан', 'reason' => 'empty'];
                }

                // успех по raw
                @unlink($rawPath);
                if ($procPath) @unlink($procPath);
                return [
                    'success'           => true,
                    'recognized_amount' => $r2['amount'],
                    'parsed_text'       => $r2['recognized']['ParsedText'] ?? '',
                    'pass'              => $usedPass, // 'raw'
                ];
            }

            // успех по processed
            @unlink($rawPath);
            if ($procPath) @unlink($procPath);
            return [
                'success'           => true,
                'recognized_amount' => $r1['amount'],
                'parsed_text'       => $r1['recognized']['ParsedText'] ?? '',
                'pass'              => $usedPass, // 'processed'
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
            $amount   = Yii::$app->request->post('amount');
            $qty      = Yii::$app->request->post('qty', 1);
            $note     = Yii::$app->request->post('note', '');
            $text     = Yii::$app->request->post('parsed_text', '');
            $category = Yii::$app->request->post('category', null);

            // валидация входа
            if (!is_numeric($amount) || (float)$amount <= 0) {
                return ['success' => false, 'error' => 'Неверная сумма'];
            }
            if (!is_numeric($qty) || (float)$qty <= 0) {
                $qty = 1;
            }

            $entry = new \app\models\PriceEntry();
            $entry->user_id           = Yii::$app->user->id;
            $entry->amount            = (float)$amount;
            $entry->qty               = (float)$qty;
            $entry->category          = $category ?: null;
            $entry->note              = (string)$note;
            $entry->recognized_text   = (string)$text;
            $entry->recognized_amount = (float)$amount;
            $entry->source            = 'price_tag';
            $entry->created_at        = time();
            $entry->updated_at        = time();

            if (!$entry->save()) {
                // вернём детали, чтобы видеть, что не понравилось валидации
                return ['success' => false, 'error' => 'Ошибка сохранения', 'details' => $entry->errors];
            }

            // считаем total для пользователя прямо тут
            $db = Yii::$app->db;
            $total = (float)$db->createCommand(
                'SELECT COALESCE(SUM(amount * qty),0) FROM price_entry WHERE user_id=:u',
                [':u' => Yii::$app->user->id]
            )->queryScalar();

            return [
                'success' => true,
                'entry' => [
                    'id'      => $entry->id,
                    'amount'  => $entry->amount,
                    'qty'     => $entry->qty,
                    'category'=> $entry->category,
                ],
                'total' => $total,
            ];

        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }
    public function actionUpdate($id)
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $m = \app\models\PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$m) {
            Yii::$app->response->statusCode = 404;
            return ['success' => false, 'error' => 'Запись не найдена'];
        }

        $amount = Yii::$app->request->post('amount', null);
        $qty    = Yii::$app->request->post('qty', null);

        if ($amount !== null) $m->amount = (float)$amount;
        if ($qty !== null)    $m->qty    = (float)$qty;

        $m->updated_at = time();

        if (!$m->save(false, ['amount','qty','updated_at'])) {
            return ['success' => false, 'error' => 'Не удалось сохранить', 'details' => $m->errors];
        }

        $total = (float)Yii::$app->db->createCommand(
            'SELECT COALESCE(SUM(amount * qty),0) FROM price_entry WHERE user_id=:u',
            [':u' => Yii::$app->user->id]
        )->queryScalar();

        return [
            'success' => true,
            'entry'   => ['id'=>$m->id,'amount'=>$m->amount,'qty'=>$m->qty],
            'total'   => $total,
        ];
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

        $total = (float)Yii::$app->db->createCommand(
            'SELECT COALESCE(SUM(amount * qty),0) FROM price_entry WHERE user_id=:u',
            [':u' => Yii::$app->user->id]
        )->queryScalar();

        return ['success' => true, 'total' => $total];
    }

}
