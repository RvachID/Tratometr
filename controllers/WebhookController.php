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

        $update = json_decode(Yii::$app->request->rawBody, true);
        if (!$update) {
            return ['status' => 'empty', 'rawBody' => Yii::$app->request->rawBody];
        }

        $chat_id = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? '';

        if ($chat_id && $text) {
            return [
                'status' => 'ready_to_send',
                'chat_id' => $chat_id,
                'text' => $text,
                'env' => getenv('BOT_TOKEN') ? 'token_loaded' : 'token_missing'
            ];

            // После проверки можно будет раскомментировать отправку:
            /*
            file_get_contents("https://api.telegram.org/bot" . getenv('BOT_TOKEN') .
                "/sendMessage?chat_id=$chat_id&text=" . urlencode("Ты написал: $text"));
            */
        }

        return ['status' => 'no message data'];
    }
}
