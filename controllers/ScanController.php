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
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $image = UploadedFile::getInstanceByName('image');
            if (!$image) {
                return ['success' => false, 'error' => 'Изображение не загружено'];
            }

            $tmpPath = Yii::getAlias('@runtime/' . uniqid('scan_') . '.' . $image->extension);
            if (!$image->saveAs($tmpPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }

            // Обрабатываем изображение
            $preprocessedPath = Yii::getAlias('@runtime/' . uniqid('preprocessed_') . '.jpg');
            $this->preprocessImage($tmpPath, $preprocessedPath);
            unlink($tmpPath);

            // Распознаём текст
            $recognizedText = $this->recognizeText($preprocessedPath);
            unlink($preprocessedPath);

            if (empty($recognizedData['ParsedText'])) {
                return ['success' => false, 'error' => 'Текст не распознан'];
            }

            $amount = $this->extractAmount($recognizedData['ParsedText']);
            if (!$amount) {
                return ['success' => false, 'error' => 'Не удалось извлечь сумму'];
            }

            $entry = new PriceEntry([
                'user_id' => Yii::$app->user->id,
                'amount' => $amount,
                'qty' => 1,
                'recognized_text' => $recognizedData['ParsedText'],
                'source' => 'price_tag',
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            if (!$entry->save()) {
                return ['success' => false, 'error' => 'Ошибка сохранения', 'details' => $entry->errors];
            }

            return [
                'success' => true,
                'text' => $recognizedData['ParsedText'],
                'amount' => $amount,
                'entry_id' => $entry->id,
            ];

        } catch (\Throwable $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
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

        $response = $client->request('POST', 'https://api.ocr.space/parse/image', [
            'headers' => ['apikey' => $apiKey],
            'multipart' => [
                ['name' => 'file', 'contents' => fopen($filePath, 'r')],
                ['name' => 'language', 'contents' => 'rus'],
                ['name' => 'isOverlayRequired', 'contents' => 'true'],

            ],
        ]);

        $body = json_decode($response->getBody(), true);
        return $body['ParsedResults'][0] ?? [];

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
    private function processImage($filePath)
    {
        Yii::info('Обработка изображения прошла', __METHOD__);
        try {
            $image = new \Imagick($filePath);

            // Преобразуем в ЧБ
            $image->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $image->setImageType(\Imagick::IMGTYPE_GRAYSCALE);

            // Повышаем контраст
            $image->sigmoidalContrastImage(true, 10, 0.5);

            // Повышаем яркость и резкость
            $image->modulateImage(120, 100, 100); // яркость +20%
            $image->sharpenImage(2, 1); // усиление чёткости

            // Обрезаем 5% по краям
            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $cropX = intval($width * 0.05);
            $cropY = intval($height * 0.05);
            $cropW = $width - 2 * $cropX;
            $cropH = $height - 2 * $cropY;
            $image->cropImage($cropW, $cropH, $cropX, $cropY);
            $image->setImagePage(0, 0, 0, 0); // сброс ограничений

            // Сохраняем поверх
            $image->writeImage($filePath);
            $image->destroy();

            Yii::info('Обработка изображения завершена успешно', __METHOD__);

        } catch (\Exception $e) {
            Yii::error('Ошибка обработки изображения: ' . $e->getMessage(), __METHOD__);
        }
    }


// временный вывод
    private function recognizeTextWithRaw($filePath)
    {
        $apiKey = 'K84434625588957';
        $client = new \GuzzleHttp\Client();

        $response = $client->request('POST', 'https://api.ocr.space/parse/image', [
            'headers' => ['apikey' => $apiKey],
            'multipart' => [
                ['name' => 'file', 'contents' => fopen($filePath, 'r')],
                ['name' => 'language', 'contents' => 'rus'],
                ['name' => 'isOverlayRequired', 'contents' => 'true'],
            ],
        ]);

        $body = json_decode($response->getBody(), true);

        return $body; // 🔍 вернём весь ответ целиком
    }

}
