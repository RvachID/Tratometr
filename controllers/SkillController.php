<?php
// File: app/controllers/SkillController.php
namespace app\controllers;

use Yii;
use yii\web\Controller;

/**
 * Алиса вебхук. Проверка секрета по GET: ?key=...
 * Жёсткая отдача JSON через echo+exit, чтобы исключить пустые ответы.
 */
final class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionWebhook()
    {
        // --- 1) Проверка секрета через GET ---
        $expected = $this->getExpectedSecret();              // из ENV ALICE_WEBHOOK_SECRET (если задан)
        $gotKey   = (string)Yii::$app->request->get('key', '');
        if ($expected !== '' && !hash_equals($expected, $gotKey)) {
            return $this->jsonOut([
                'version'  => '1.0',
                'session'  => new \stdClass(),
                'response' => ['text' => 'Unauthorized', 'end_session' => true],
            ]);
        }

        // --- 2) Безопасный парсинг тела (может быть пустым) ---
        $raw  = Yii::$app->request->getRawBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];

        // --- 3) Минимально валидный ответ для Алисы ---
        $text = 'Навык подключён. Скажи: «добавь молоко».';

        return $this->jsonOut([
            'version'  => '1.0',
            'session'  => $data['session'] ?? new \stdClass(),
            'response' => [
                'text'        => $text,
                'tts'         => $text,
                'end_session' => false,
            ],
        ]);
    }

    // ===== helpers =====
    private function getExpectedSecret(): string
    {
        $env = getenv('ALICE_WEBHOOK_SECRET');
        return is_string($env) ? trim($env) : '';
    }

    private function jsonOut(array $payload)
    {
        // Сброс буферов и явные заголовки
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 200);
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        exit; // важен — ничего дальше не дописываем в ответ
    }
}
