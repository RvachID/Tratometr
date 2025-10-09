<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class SkillController extends Controller
{
    public $enableCsrfValidation = false; // вебхук без CSRF

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionWebhook(): array
    {
        try {
            // читаем СЫРОЙ JSON и декодим сами (обходим Yii JsonParser)
            $raw = Yii::$app->request->getRawBody();
            if ($raw === '' || $raw === false) {
                $raw = @file_get_contents('php://input') ?: '';
            }

            $req = json_decode($raw, true);
            if (!is_array($req)) {
                $req = [];
            }

            // лог — максимально безопасный
            try {
                Yii::info('ALICE RAW: ' . $raw, __METHOD__);
            } catch (\Throwable $e) {
                // игнор
            }

            // вытаскиваем обязательные поля с жёсткой типизацией
            $ses = $req['session'] ?? [];
            $sessionId = (string)($ses['session_id'] ?? '');
            $messageId = (int)   ($ses['message_id'] ?? 0);
            $userId    = (string)($ses['user_id'] ?? '');

            // текст пользователя
            $utterance = trim((string)($req['request']['original_utterance'] ?? ''));

            // простой ответ, чтобы убедиться, что канал жив
            $text = $utterance !== ''
                ? 'Вы сказали: ' . $utterance
                : 'Навык подключён. Скажи, например: «добавь молоко».';

            $resp = [
                'version' => (string)($req['version'] ?? '1.0'),
                'session' => [
                    'session_id' => $sessionId,
                    'message_id' => $messageId,
                    'user_id'    => $userId,
                ],
                'response' => [
                    'text'         => $text,
                    'tts'          => $text,
                    'end_session'  => false,
                ],
            ];

            // финальный лог (не критичен)
            try {
                Yii::info('ALICE RESP: ' . json_encode($resp, JSON_UNESCAPED_UNICODE), __METHOD__);
            } catch (\Throwable $e) {}

            return $resp;

        } catch (\Throwable $e) {
            // даже при любой внутренней ошибке отвечаем валидным JSON (чтобы в тестере не было 500)
            Yii::error('Alice webhook failed: ' . $e->getMessage(), __METHOD__);

            return [
                'version' => '1.0',
                'session' => [
                    'session_id' => '',
                    'message_id' => 0,
                    'user_id'    => '',
                ],
                'response' => [
                    'text'        => 'Упс, что-то пошло не так. Попробуй ещё раз.',
                    'end_session' => false,
                ],
            ];
        }
    }
}
