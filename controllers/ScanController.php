<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use yii\web\UploadedFile;
use yii\web\NotFoundHttpException;
use yii\web\BadRequestHttpException;
use app\models\PriceEntry;

class ScanController extends Controller
{
    public $enableCsrfValidation = true;

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) {
            Yii::$app->response->format = Response::FORMAT_JSON;
            Yii::$app->response->statusCode = 401;
            Yii::$app->end(json_encode(['success' => false, 'error' => 'Не авторизован']));
        }
        if ($action->id === 'upload') {
            Yii::$app->request->enableCsrfValidation = false;
        }
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON; // 💥 ВОТ ЭТО ОБЯЗАТЕЛЬНО

        return parent::beforeAction($action);
    }

    /**
     * Получение изображения → распознавание → сохранение → ответ
     */
    public function actionUpload()
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

            // предобработка «на месте»
            if (!$this->preprocessImage($tmpPath)) {
                @unlink($tmpPath);
                return ['success' => false, 'error' => 'Ошибка при обработке изображения'];
            }

            // контроль размера до 1 МБ
            if (filesize($tmpPath) > 1024*1024) {
                @unlink($tmpPath);
                return ['success' => false, 'error' => 'Размер файла превышает 1 МБ'];
            }

            // распознаём
            $recognizedData = $this->recognizeText($tmpPath);
            @unlink($tmpPath);

            if (isset($recognizedData['error'])) {
                return [
                    'success' => false,
                    'error'   => $recognizedData['error'],
                    'debug'   => $recognizedData['full_response'] ?? 'Нет подробностей',
                ];
            }

            if (empty($recognizedData['ParsedText'])) {
                return ['success' => false, 'error' => 'Текст не распознан'];
            }

            $amount = $this->extractAmountByOverlay($recognizedData);
            if ($amount === null) {
                // fallback по тексту, если Overlay не помог
                $amount = $this->extractAmount($recognizedData['ParsedText']);
            }
            if (!$amount) {
                return ['success' => false, 'error' => 'Не удалось извлечь сумму'];
            }

            $entry = new \app\models\PriceEntry([
                'user_id'         => Yii::$app->user->id,
                'amount'          => $amount,
                'qty'             => 1,
                'recognized_text' => $recognizedData['ParsedText'],
                'source'          => 'price_tag',
                'created_at'      => time(),
                'updated_at'      => time(),
            ]);

            if (!$entry->save()) {
                return ['success' => false, 'error' => 'Ошибка сохранения', 'details' => $entry->errors];
            }

            return [
                'success'  => true,
                'text'     => $recognizedData['ParsedText'],
                'amount'   => $amount,
                'entry_id' => $entry->id,
            ];

        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера', 'debug' => $e->getMessage()];
        }
    }


    /**
     * Обновление суммы / количества / категории
     */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $entry = PriceEntry::findOne(['id' => $id, 'user_id' => Yii::$app->user->id]);
        if (!$entry) {
            throw new NotFoundHttpException('Запись не найдена');
        }

        $entry->load(Yii::$app->request->post(), '');
        if ($entry->save()) {
            return ['ok' => true];
        } else {
            return ['ok' => false, 'errors' => $entry->getErrors()];
        }
    }

    /**
     * Распознавание текста через OCR API
     */
    private function recognizeText($filePath)
    {
        $apiKey = 'K84434625588957';
        $client = new \GuzzleHttp\Client();

        try {
            $response = $client->request('POST', 'https://api.ocr.space/parse/image', [
                'headers' => ['apikey' => $apiKey],
                'multipart' => [
                    ['name' => 'file', 'contents' => fopen($filePath, 'r')],
                    ['name' => 'language', 'contents' => 'rus'],
                    ['name' => 'isOverlayRequired', 'contents' => 'true'],
                ],
            ]);

            $body = json_decode($response->getBody(), true);

            // 💾 Сохраняем полный ответ в лог для анализа
            $logPath = Yii::getAlias('@runtime/ocr_raw_response.json');
            file_put_contents($logPath, json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            // ✅ Проверяем есть ли ParsedResults
            if (!isset($body['ParsedResults'][0])) {
                return [
                    'error' => 'Нет результата распознавания',
                    'full_response' => $body,
                ];
            }

            return $body['ParsedResults'][0];
        } catch (\Throwable $e) {
            // ⚠️ Логируем исключение
            Yii::error($e->getMessage(), __METHOD__);
            return [
                'error' => 'Исключение при распознавании: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Берём число из OCR Overlay по наибольшему "шрифту" (Height).
     * Возвращает float или null, если в Overlay ничего подходящего.
     */
    private function extractAmountByOverlay(array $recognizedData): ?float
    {
        $lines = $recognizedData['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines)) {
            return null;
        }

        $bestScore = -1.0;
        $bestValue = null;

        foreach ($lines as $line) {
            foreach (($line['Words'] ?? []) as $w) {
                $token = (string)($w['WordText'] ?? '');
                $height = isset($w['Height']) ? (float)abs($w['Height']) : 0.0;
                if ($height <= 0) continue;

                $val = $this->normalizeOcrNumber($token);
                if ($val === null) continue;

                // вес = высота шрифта; небольшой бонус за наличие разделителя копеек
                $score = $height;
                if (preg_match('/[.,]\d{2}\b/u', $token)) {
                    $score += $height * 0.4;
                }

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestValue = $val;
                }
            }
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


}
