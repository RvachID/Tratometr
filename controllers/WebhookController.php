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
            return ['status' => 'error', 'message' => 'Empty body'];
        }

        $chat_id = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? null;

        if (!$chat_id || !$text) {
            return ['status' => 'error', 'message' => 'Missing chat_id or text'];
        }

        $botToken = getenv('BOT_TOKEN');
        if (!$botToken) {
            return ['status' => 'error', 'message' => 'Bot token not set'];
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage?" . http_build_query([
                'chat_id' => $chat_id,
                'text' => "Ты написал: $text"
            ]);

        file_get_contents($url);

        return ['status' => 'ok', 'chat_id' => $chat_id, 'text' => $text];
    }
}
