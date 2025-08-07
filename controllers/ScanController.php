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

            $recognizedText = $this->recognizeText($tmpPath);
            unlink($tmpPath);

            if (!$recognizedText) {
                return ['success' => false, 'error' => 'Текст не распознан'];
            }

            $amount = $this->extractAmount($recognizedText);
            if (!$amount) {
                return ['success' => false, 'error' => 'Не удалось извлечь сумму'];
            }

            $entry = new PriceEntry([
                'user_id' => Yii::$app->user->id,
                'amount' => $amount,
                'qty' => 1,
                'recognized_text' => $recognizedText,
                'source' => 'price_tag',
                'created_at' => time(),
                'updated_at' => time(),
            ]);

            if (!$entry->save()) {
                return ['success' => false, 'error' => 'Ошибка сохранения', 'details' => $entry->errors];
            }

            return [
                'success' => true,
                'text' => $recognizedText,
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
                ['name' => 'isOverlayRequired', 'contents' => 'false'],
            ],
        ]);

        $body = json_decode($response->getBody(), true);
        return $body['ParsedResults'][0]['ParsedText'] ?? '';
    }

    /**
     * Вытаскиваем сумму из распознанного текста
     */
    private function extractAmount($text)
    {
        preg_match_all('/\d+[.,]?\d*/', $text, $matches);
        if (empty($matches[0])) {
            return 0;
        }

// ищем наибольшую сумму
        $nums = array_map(fn($s) => floatval(str_replace(',', '.', $s)), $matches[0]);
        return max($nums);
    }
}
