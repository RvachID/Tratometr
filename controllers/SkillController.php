<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\services\Alice\AliceListService;

final class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    private const WEB_USER_ID = 3; // временно: твой user_id в Тратометре

    public function actionWebhook()
    {
        $expected = $this->getExpectedSecret();
        $gotKey   = (string)Yii::$app->request->get('key', '');
        if ($expected !== '' && !hash_equals($expected, $gotKey)) {
            return $this->jsonOut([
                'version'  => '1.0',
                'session'  => new \stdClass(),
                'response' => ['text' => 'Unauthorized', 'end_session' => true],
            ]);
        }

        $raw  = Yii::$app->request->getRawBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];

        $session = $data['session'] ?? [];
        $request = $data['request'] ?? [];
        $command = mb_strtolower(trim($request['command'] ?? ''), 'UTF-8');

        $service = new AliceListService();
        $userId  = self::WEB_USER_ID;

        $text = 'Навык подключён. Скажи: «добавь молоко».';

        try {
            if ($command !== '') {
                $text = $this->handleCommand($service, $userId, $command);
            }
        } catch (\Throwable $e) {
            Yii::error('Alice error: ' . $e->getMessage(), __METHOD__);
            $text = 'Произошла внутренняя ошибка навыка, попробуй ещё раз позже.';
        }

        return $this->jsonOut([
            'version'  => '1.0',
            'session'  => $session ?: new \stdClass(),
            'response' => [
                'text'        => $text,
                'tts'         => $text,
                'end_session' => false,
            ],
        ]);
    }

    // --- простейший разбор команд ---

    private function handleCommand(AliceListService $service, int $userId, string $command): string
    {
        // 1) "добавь молоко", "добавить хлеб"
        if (preg_match('~^добав(ь|ить)\s+(.+)$~u', $command, $m)) {
            $title = trim($m[2]);
            $item  = $service->addItem($userId, $title);
            $list  = $service->getActiveList($userId);
            $count = count($list);

            if ($count === 1) {
                return 'Добавила в список: ' . $item->title . '. В списке одна позиция.';
            }
            return 'Добавила в список: ' . $item->title . ". Сейчас в списке {$count} позиций.";
        }

        // 2) "что в списке", "что купить", "список покупок"
        if (preg_match('~(что в списке|что купить|список покупок|список)$~u', $command)) {
            $list = $service->getActiveList($userId);
            if (!$list) {
                return 'Список покупок пуст. Скажи: добавь молоко.';
            }

            $names = array_map(fn($i) => $i->title, $list);
            $joined = implode(', ', $names);

            return 'В списке: ' . $joined . '.';
        }

        // 3) "очисти список", "удали всё"
        if (preg_match('~(очисти список|удали всё|удали все)$~u', $command)) {
            $affected = $service->completeAll($userId);
            if ($affected === 0) {
                return 'И так всё куплено, список уже пуст.';
            }
            return "Отметила как купленные {$affected} позиций. Список очищен.";
        }

        // дефолт
        return 'Я могу вести список покупок. Скажи: «добавь молоко» или «что в списке».';
    }

    // ===== helpers =====
    private function getExpectedSecret(): string
    {
        $env = getenv('ALICE_WEBHOOK_SECRET');
        return is_string($env) ? trim($env) : '';
    }

    private function jsonOut(array $payload)
    {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 200);
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        exit;
    }
}

