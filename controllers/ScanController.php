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
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        if (Yii::$app->user->isGuest) {
            throw new \yii\web\UnauthorizedHttpException('Пользователь не авторизован');
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

        $image = UploadedFile::getInstanceByName('image');
        if (!$image) {
            throw new BadRequestHttpException('Изображение не загружено');
        }

        $tmpPath = Yii::getAlias('@runtime/' . uniqid('scan_') . '.' . $image->extension);
        $image->saveAs($tmpPath);

        $recognizedText = $this->recognizeText($tmpPath);
        unlink($tmpPath);

        $amount = $this->extractAmount($recognizedText);

        $entry = new PriceEntry([
            'user_id' => Yii::$app->user->id,
            'amount' => $amount,
            'qty' => 1,
            'recognized_text' => $recognizedText,
        ]);

        $entry->save();

        return [
            'success' => true,
            'text' => $recognizedText,
            'amount' => $amount,
            'entry_id' => $entry->id,
        ];
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
