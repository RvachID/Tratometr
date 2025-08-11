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

            $amount = $this->extractAmount($recognizedData['ParsedText']);
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
     * Вытаскиваем сумму из распознанного текста
     */
    private function extractAmount($text)
    {
        // Убираем всё, кроме чисел
        preg_match_all('/\d+[.,]?\d*/', $text, $matches);

        if (empty($matches[0])) {
            return 0;
        }

        // Преобразуем в float и ищем максимум
        $nums = array_map(fn($s) => floatval(str_replace(',', '.', $s)), $matches[0]);
        return max($nums);
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
