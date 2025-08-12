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
     * Берём число из OCR Overlay по наибольшему "шрифту" (Height).
     * Возвращает float или null, если в Overlay ничего подходящего.
     */
    private function extractAmountByOverlay(array $recognized): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines) || !count($lines)) {
            return null;
        }

        $bestValue = null;
        $bestScore = -INF;

        // локальная обёртка на твою нормализацию
        $norm = function (string $raw): ?float {
            return $this->normalizeOcrNumber($raw);
        };

        foreach ($lines as $line) {
            $words = $line['Words'] ?? [];
            if (!is_array($words) || !count($words)) {
                continue;
            }

            // приведение типов и очистка текста
            foreach ($words as &$w) {
                $w['WordText'] = (string)($w['WordText'] ?? '');
                // уберём валюты/мусор внутри слова (₽, р, руб и т.п.)
                $w['WordText'] = preg_replace('~[^\d.,\s]~u', '', $w['WordText']);
                $w['Height']   = isset($w['Height']) ? (int)$w['Height'] : 0;
                $w['Left']     = isset($w['Left'])   ? (int)$w['Left']   : 0;
                $w['Top']      = isset($w['Top'])    ? (int)$w['Top']    : 0;
            }
            unset($w);

            // собираем подряд идущие «числовые» токены в группы
            $group = [];
            $flush = function () use (&$group, $norm, &$bestValue, &$bestScore) {
                if (!count($group)) return;

                // склейка без пробелов: "1", "299", ",", "90" => "1299,90"
                $raw = implode('', array_column($group, 'WordText'));
                $val = $norm($raw);
                $groupHeights = array_map(fn($g) => (int)$g['Height'], $group);
                $maxH = $groupHeights ? max($groupHeights) : 0;

                if ($val !== null) {
                    // признаки «похоже на цену»
                    $rawStr = $raw;
                    $hasSep   = (bool)preg_match('~[.,]~', $rawStr);      // есть разделитель
                    $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $rawStr); // ровно 2 цифры после разделителя

                    // счёт: высота — главный фактор, бонусы за разделитель/копейки
                    // маленькие значения (<1) слегка штрафуем, чтобы не брать «.50» и т.п.
                    $penaltySmall = ($val < 1.0) ? 20 : 0;

                    $score = $maxH * 10
                        + ($hasCents ? 50 : 0)
                        + ($hasSep   ? 20 : 0)
                        - $penaltySmall;

                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestValue = $val;
                    }
                }

                $group = [];
            };

            foreach ($words as $w) {
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
    private function preprocessImage(string $filePath): bool
    {
        Yii::info('Обработка изображения начата', __METHOD__);
        try {
            $image = new \Imagick($filePath);
            $image->setImageFormat('jpeg');

            // ресайз по ширине до 1024
            $width = $image->getImageWidth();
            if ($width > 1024) {
                $image->resizeImage(1024, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            // Ч/Б + контраст/яркость/резкость
            $image->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $image->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
            $image->sigmoidalContrastImage(true, 10, 0.5);
            $image->modulateImage(120, 100, 100);
            $image->sharpenImage(2, 1);

            // обрезка 5% по краям
            $w = $image->getImageWidth();
            $h = $image->getImageHeight();
            $cropX = (int)($w * 0.05);
            $cropY = (int)($h * 0.05);
            $image->cropImage($w - 2*$cropX, $h - 2*$cropY, $cropX, $cropY);
            $image->setImagePage(0, 0, 0, 0);

            $ok = $image->writeImage($filePath);
            $image->clear();
            $image->destroy();

            Yii::info('Обработка изображения завершена успешно', __METHOD__);
            return $ok;
        } catch (\Throwable $e) {
            Yii::error('Ошибка обработки изображения: '.$e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function actionRecognize()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            $image = \yii\web\UploadedFile::getInstanceByName('image');
            if (!$image) {
                return ['success' => false, 'error' => 'Изображение не загружено'];
            }

            $tmpPath = Yii::getAlias('@runtime/' . uniqid('scan_') . '.' . $image->extension);
            if (!$image->saveAs($tmpPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }

            if (!$this->preprocessImage($tmpPath)) {
                @unlink($tmpPath);
                return ['success' => false, 'error' => 'Ошибка при обработке изображения'];
            }

            if (filesize($tmpPath) > 1024*1024) {
                @unlink($tmpPath);
                return ['success' => false, 'error' => 'Размер файла превышает 1 МБ'];
            }

            $recognizedData = $this->recognizeText($tmpPath);
            @unlink($tmpPath);

            if (isset($recognizedData['error'])) {
                // 429 от rate limiter словится раньше, но на всякий случай
                return ['success' => false, 'error' => $recognizedData['error'], 'reason' => 'ocr'];
            }

            if (empty($recognizedData['ParsedText'])) {
                return ['success' => false, 'error' => 'Текст не распознан', 'reason' => 'empty'];
            }

            $amount = $this->extractAmountByOverlay($recognizedData);
            if ($amount === null) {
                $amount = $this->extractAmount($recognizedData['ParsedText']);
            }
            if (!$amount) {
                return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
            }

            return [
                'success'           => true,
                'recognized_amount' => $amount,
                'parsed_text'       => $recognizedData['ParsedText'],
            ];

        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
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

}
