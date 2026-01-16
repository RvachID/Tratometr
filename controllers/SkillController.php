<?php

namespace app\controllers;

use app\services\Alice\AliceListService;
use Yii;
use yii\web\Controller;

final class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    private const WEB_USER_ID = 3; // временно

    public function actionWebhook()
    {
        // --- защита по секрету ---
        $expected = $this->getExpectedSecret();
        $gotKey = (string)Yii::$app->request->get('key', '');

        if ($expected !== '' && !hash_equals($expected, $gotKey)) {
            return $this->jsonOut($this->errorResponse('Unauthorized', true));
        }

        // --- входные данные ---
        $data = json_decode(Yii::$app->request->getRawBody(), true) ?: [];
        $session = $data['session'] ?? [];
        $request = $data['request'] ?? [];

        $command = trim((string)($request['command'] ?? ''));
        $userId  = self::WEB_USER_ID;

        $service = new AliceListService();

        $text = null;

        try {
            // ===== ОСНОВНАЯ ЛОГИКА =====
            if ($command !== '') {
                $reply = $service->handleCommand($userId, $command);
                if (is_string($reply) && $reply !== '') {
                    $text = $reply;
                }
            }

            // ===== КОНТЕКСТНЫЕ ДЕФОЛТЫ =====
            if ($text === null) {

                $isNewSession = ($session['new'] ?? false) === true;
                $activeCount = count($service->getActiveList($userId));

                if ($isNewSession) {
                    // первый запуск навыка
                    $text = 'Я помогу вести список покупок. '
                        . 'Скажи: "добавь хлеб и молоко" чтобы добавить продукты, или спроси "что в списке" или скомандуй "очисти список".';

                } elseif ($activeCount === 0) {
                    // навык активен, но список пуст
                    $text = 'Список покупок сейчас пуст. '
                        . 'Скажи, что добавить. Например: "добавь хлеб и молоко".';

                } else {
                    // навык активен, список уже есть
                    $text = 'Могу добавить товары, показать список или отметить всё как купленное просто скажи "очисти список".';
                }
            }

        } catch (\Throwable $e) {
            Yii::error('Alice error: ' . $e->getMessage(), __METHOD__);
            $text = 'Произошла внутренняя ошибка навыка, попробуй ещё раз позже.';
        }

        return $this->jsonOut([
            'version' => '1.0',
            'session' => $session ?: new \stdClass(),
            'response' => [
                'text' => $text,
                'tts'  => $text,
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

    private function errorResponse(string $text, bool $end = false): array
    {
        return [
            'version' => '1.0',
            'session' => new \stdClass(),
            'response' => [
                'text' => $text,
                'end_session' => $end,
            ],
        ];
    }

    private function jsonOut(array $payload)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        exit;
    }
}
