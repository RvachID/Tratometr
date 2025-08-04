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
        $logPath = Yii::getAlias('@runtime/webhook.log');
        file_put_contents($logPath, date('c') . ' - RAW BODY: ' . Yii::$app->request->rawBody . PHP_EOL, FILE_APPEND);

        $update = json_decode(Yii::$app->request->rawBody, true);
        if (!$update) return 'empty';

        $chat_id = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? '';

        if ($chat_id && $text) {
            file_put_contents($logPath, date('c') . " - Received message: $text from chat $chat_id" . PHP_EOL, FILE_APPEND);

            file_get_contents("https://api.telegram.org/bot" . getenv('BOT_TOKEN') .
                "/sendMessage?chat_id=$chat_id&text=" . urlencode("Ты написал: $text"));
        }

        return 'ok';
    }
}
