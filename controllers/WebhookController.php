<?php
use Yii;
use yii\web\Controller;
use yii\web\Response;

class WebhookController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionIndex()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $body = Yii::$app->request->rawBody;
        $update = json_decode($body, true);

        if (!$update) {
            $this->log("Empty or invalid JSON: $body");
            return ['status' => 'error', 'message' => 'empty or invalid payload'];
        }

        $chat_id = $update['message']['chat']['id'] ?? null;
        $text = $update['message']['text'] ?? '';

        if ($chat_id && $text) {
            $this->log("Received from $chat_id: $text");

            $token = getenv('BOT_TOKEN') ?: Yii::$app->params['botToken'] ?? null;

            if (!$token) {
                $this->log("BOT_TOKEN not set");
                return ['status' => 'error', 'message' => 'BOT_TOKEN not set'];
            }

            $url = "https://api.telegram.org/bot$token/sendMessage";
            $params = [
                'chat_id' => $chat_id,
                'text' => "Ты написал: $text",
            ];

            $result = file_get_contents($url . '?' . http_build_query($params));
            $this->log("Response: $result");

            return ['status' => 'ok'];
        }

        $this->log("Missing chat_id or text in: " . print_r($update, true));
        return ['status' => 'ignored'];
    }

    private function log($message)
    {
        $file = Yii::getAlias('@runtime') . '/webhook.log';
        file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL, FILE_APPEND);
    }
}
