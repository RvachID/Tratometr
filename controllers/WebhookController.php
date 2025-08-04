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

        $rawBody = Yii::$app->request->rawBody;

        file_put_contents(
            Yii::getAlias('@app/runtime/logs/webhook-error.log'),
            date('c') . ' | ' . $rawBody . "\n",
            FILE_APPEND
        );

        $update = json_decode($rawBody, true);

        if (!$update) {
            return ['status' => 'empty', 'rawBody' => $rawBody];
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
        }

        return ['status' => 'no message data'];
    }

}
