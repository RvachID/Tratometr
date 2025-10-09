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

    /**
     * Главный вебхук навыка.
     * Просто логируем входящий JSON и эхо-ответом возвращаем то, что услышали.
     */
    public function actionWebhook()
    {
        // --- простейшая защита секретом из params (или ?s=..)
        $cfgSecret = (string) (Yii::$app->params['alice']['webhookSecret'] ?? '');
        $reqSecret = (string) Yii::$app->request->get('s', '');
        if ($cfgSecret !== '' && $reqSecret !== $cfgSecret) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'forbidden'];
        }

        // --- читаем сырой JSON
        $raw = file_get_contents('php://input') ?: '';
        $json = [];
        if ($raw !== '') {
            try { $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
            catch (\Throwable $e) { /* оставим пустым */ }
        }

        // --- вытаскиваем полезные поля (если есть)
        $ver     = (string)($json['version'] ?? '1.0');
        $session = $json['session'] ?? [];
        $req     = $json['request'] ?? [];
        $utt     = trim((string)($req['original_utterance'] ?? ''));
        $cmd     = trim((string)($req['command'] ?? ''));
        $tokens  = (array)($req['nlu']['tokens'] ?? []);

        // --- пишем лог (runtime/alice_webhook.log)
        $logLine = sprintf(
            "[%s] %s\nRAW: %s\nPARSED: %s\n\n",
            date('Y-m-d H:i:s'),
            $_SERVER['REMOTE_ADDR'] ?? '-',
            $raw,
            json_encode(['utterance'=>$utt,'command'=>$cmd,'tokens'=>$tokens], JSON_UNESCAPED_UNICODE)
        );
        @file_put_contents(Yii::getAlias('@runtime/alice_webhook.log'), $logLine, FILE_APPEND);

        // --- формируем простой ответ Алисе
        // протокол Диалогов ждёт {"response": {...}, "version":"1.0"}
        $say = $utt !== '' ? "Слышал: «{$utt}»" : 'Скажи, что добавить в список покупок.';
        if ($cmd !== '' && $cmd !== $utt) {
            $say .= " (команда: {$cmd})";
        }
        if (!empty($tokens)) {
            $say .= ' Токены: ' . implode(', ', $tokens) . '.';
        }

        $resp = [
            'response' => [
                'text'         => $say,
                'tts'          => $say,
                'end_session'  => false,
            ],
            'version' => $ver ?: '1.0',
        ];

        // можно вернуть ещё debug-блок (Алиса его игнорит, но удобно в curl)
        if (YII_ENV_DEV) {
            $resp['_debug'] = [
                'session' => $session,
                'request' => $req,
            ];
        }

        return $resp;
    }
}
