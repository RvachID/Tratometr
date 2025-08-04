<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class WebhookController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $data = Yii::$app->request->getRawBody();

            // Сохраняем лог
            $logFile = Yii::getAlias('@runtime/webhook.log');
            file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $data . PHP_EOL, FILE_APPEND);

            // Пробуем разобрать JSON
            $update = json_decode($data, true);
            if (!$update) {
                return ['status' => 'empty', 'reason' => 'invalid json'];
            }

            $chat_id = $update['message']['chat']['id'] ?? null;
            $text = $update['message']['text'] ?? '';

            if ($chat_id && $text) {
                $botToken = getenv('BOT_TOKEN');
                file_get_contents("https://api.telegram.org/bot{$botToken}/sendMessage?" . http_build_query([
                        'chat_id' => $chat_id,
                        'text' => "Ты написал: $text"
                    ]));
            }

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            $errorFile = Yii::getAlias('@runtime/webhook_error.log');
            file_put_contents($errorFile, date('Y-m-d H:i:s') . ' ERROR: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
}
