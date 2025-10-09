<?php
// File: app/controllers/SkillController.php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

final class SkillController extends Controller
{
    /** Вебхуку CSRF не нужен */
    public $enableCsrfValidation = false;

    /** Гарантируем JSON-ответ */
    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    /** Простой healthcheck: https://.../index.php?r=skill/ping */
    public function actionPing()
    {
        return $this->asJson([
            'ok' => 1,
            'ts' => time(),
            'env' => [
                'YII_ENV'   => defined('YII_ENV') ? YII_ENV : null,
                'YII_DEBUG' => defined('YII_DEBUG') ? (int)YII_DEBUG : null,
            ],
        ]);
    }

    /** Главный вебхук для Алисы */
    public function actionWebhook()
    {
        try {
            // 1) Читаем raw-тело и парсим вручную (обход JsonParser)
            $raw = Yii::$app->request->getRawBody();
            $this->logToFile('webhook.log', 'HIT RAW=' . substr($raw ?? '', 0, 1000));
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = [];
            }

            // 2) (опционально) Проверка секрета через Authorization: Bearer <secret>
            // Включится, если в конфиге есть components['alice']['webhookSecret'] или переменная окружения
            $secret = $this->getAliceSecret();
            if ($secret !== '') {
                $auth = (string)Yii::$app->request->headers->get('Authorization', '');
                if ($auth !== ('Bearer ' . $secret)) {
                    $this->logToFile('webhook.log', 'UNAUTHORIZED: ' . $auth);
                    return $this->asJson([
                        'version'  => '1.0',
                        'session'  => $data['session'] ?? new \stdClass(),
                        'response' => [
                            'text'        => 'Unauthorized',
                            'end_session' => true,
                        ],
                    ]);
                }
            }

            // 3) Простейший ответ для песочницы
            $text = 'Навык подключён. Скажи: «добавь молоко».';

            return $this->asJson([
                'version'  => '1.0',
                'session'  => $data['session'] ?? new \stdClass(),
                'response' => [
                    'text'        => $text,
                    'tts'         => $text,
                    'end_session' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logToFile('webhook_error.log', 'ERR ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            // Даже при ошибке возвращаем валидный JSON (200)
            return $this->asJson([
                'version'  => '1.0',
                'session'  => new \stdClass(),
                'response' => [
                    'text'        => 'Временная ошибка, попробуйте ещё раз.',
                    'end_session' => false,
                ],
            ]);
        }
    }

    /** Достаём секрет из env или конфига (components.alice.webhookSecret) */
    private function getAliceSecret(): string
    {
        $env = getenv('ALICE_WEBHOOK_SECRET');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $cfg = Yii::$app->alice->webhookSecret ?? '';
        return is_string($cfg) ? $cfg : '';
    }

    /** Быстрый лог в runtime/ */
    private function logToFile(string $file, string $line): void
    {
        try {
            @file_put_contents(
                Yii::getAlias('@runtime/' . $file),
                '[' . date('c') . '] ' . $line . PHP_EOL,
                FILE_APPEND
            );
        } catch (\Throwable $e) {
            // игнор
        }
    }
}
