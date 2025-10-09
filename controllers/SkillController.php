<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

/**
 * Вебхук Яндекс.Диалогов (Алиса).
 * URL: /index.php?r=skill/webhook&s=ВАШ_СЕКРЕТ
 */
class SkillController extends Controller
{
    public $enableCsrfValidation = false; // вебхук — без CSRF

    public function beforeAction($action)
    {
        // Всегда отдаём JSON
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionWebhook()
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        // читаем JSON (на случай, если парсер выключен — fallback на getRawBody)
        $data = \Yii::$app->request->getBodyParams();
        if (empty($data)) {
            $raw  = \Yii::$app->request->getRawBody();
            $data = json_decode($raw, true) ?: [];
        }

        // (секрет пока не проверяем — включим после теста)
        // $cfgSecret = \Yii::$app->params['alice']['webhookSecret'] ?? '';
        // $auth = \Yii::$app->request->headers->get('Authorization', '');
        // if ($cfgSecret !== '' && !hash_equals("Bearer {$cfgSecret}", $auth)) { ... }

        // лог всего, что пришло (для отладки в Railway logs)
        try {
            $hdrs = \Yii::$app->request->getHeaders()->toArray();
            \Yii::info(
                'ALICE INCOME headers=' . json_encode($hdrs, JSON_UNESCAPED_UNICODE)
                . ' payload=' . json_encode($data, JSON_UNESCAPED_UNICODE),
                __METHOD__
            );
        } catch (\Throwable $e) {
            \Yii::warning('Alice logging failed: '.$e->getMessage(), __METHOD__);
        }

        // простой ответ чтобы песочница перестала ругаться на 500
        $text = 'Навык подключён. Скажи: «добавь молоко».';

        return [
            'version'  => '1.0',
            'session'  => $data['session'] ?? [],
            'response' => [
                'text'        => $text,
                'tts'         => $text,
                'end_session' => false,
            ],
        ];
    }
}
