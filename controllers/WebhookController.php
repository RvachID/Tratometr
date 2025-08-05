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

        $raw = Yii::$app->request->getRawBody();
        $this->log('@runtime/webhook.log', $raw);

        try {
            // 1) Разбираем JSON апдейта
            $u = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            // Берём стандартные поля из message (на тест хватит)
            $msg          = $u['message'] ?? [];
            $chatId       = $msg['chat']['id']    ?? null;
            $userId       = $msg['from']['id']    ?? null;
            $username     = $msg['from']['username'] ?? null;
            $firstName    = $msg['from']['first_name'] ?? null;
            $text         = $msg['text'] ?? '';
            $date         = $msg['date'] ?? null;

            // 2) Пишем в БД (таблица telegram_message из миграции)
            //    Без модели, напрямую через DAO, чтобы не плодить классы
            if ($chatId || $userId || $text) {
                Yii::$app->db->createCommand()->insert('telegram_message', [
                    'chat_id'    => $chatId,
                    'user_id'    => $userId,
                    'username'   => $username,
                    'first_name' => $firstName,
                    'text'       => $text,
                    'date'       => $date,
                    // created_at заполняется по умолчанию CURRENT_TIMESTAMP из миграции
                ])->execute();
            }

            // 3) Эхо‑ответ пользователю (тестовый функционал)
            if ($chatId && $text !== '') {
                $botToken = getenv('BOT_TOKEN');
                if (!$botToken) {
                    throw new \RuntimeException('BOT_TOKEN is not set');
                }

                // простой запрос без зависимостей
                @file_get_contents(
                    'https://api.telegram.org/bot' . $botToken . '/sendMessage?' .
                    http_build_query([
                        'chat_id' => $chatId,
                        'text'    => 'Ты написал: ' . $text,
                    ])
                );
            }

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            // логируем полную ошибку со стеком
            $this->log('@runtime/webhook_error.log',
                sprintf("[%s] %s\n%s", date('Y-m-d H:i:s'), $e->getMessage(), $e->getTraceAsString())
            );

            // удобный ответ для проверки через /log/migrate-error
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function log(string $aliasPath, string $line): void
    {
        $file = Yii::getAlias($aliasPath);
        @file_put_contents($file, sprintf("[%s] %s\n", date('Y-m-d H:i:s'), $line), FILE_APPEND);
    }
}
