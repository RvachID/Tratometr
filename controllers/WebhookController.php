<?php


namespace app\controllers;

use Yii;
use yii\web\Controller;

class WebhookController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex()
    {
        $update = json_decode(Yii::$app->request->rawBody, true);
        if (!$update) return 'empty';

        $chat_id = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? '';

        if ($chat_id && $text) {
            file_get_contents("https://api.telegram.org/bot" . getenv('BOT_TOKEN') .
                "/sendMessage?chat_id=$chat_id&text=" . urlencode("Ты написал: $text"));
        }

        return 'ok';
    }
}
