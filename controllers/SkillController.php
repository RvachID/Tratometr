<?php

namespace app\controllers;

use app\services\Alice\AliceListService;
use Yii;
use yii\web\Controller;

final class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    private const WEB_USER_ID = 3; // временно: твой user_id в Тратометре

    public function actionWebhook()
    {
        $expected = $this->getExpectedSecret();
        $gotKey = (string)Yii::$app->request->get('key', '');
        if ($expected !== '' && !hash_equals($expected, $gotKey)) {
            return $this->jsonOut([
                'version' => '1.0',
                'session' => new \stdClass(),
                'response' => ['text' => 'Unauthorized', 'end_session' => true],
            ]);
        }

        $raw = Yii::$app->request->getRawBody();
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = [];

        $session = $data['session'] ?? [];
        $request = $data['request'] ?? [];
        $command = mb_strtolower(trim($request['command'] ?? ''), 'UTF-8');

        $service = new AliceListService();
        $userId = self::WEB_USER_ID;

        $text = 'Навык подключён. Скажи: "добавь молоко" или "добавь хлеб и сахар", чтобы добавить продукт в список.';

        try {
            if ($command !== '') {
                $text = $this->handleCommand($service, $userId, $command);
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
                'tts' => $text,
                'end_session' => false,
            ],
        ]);
    }

    // --- простейший разбор команд ---

    private function handleCommand(AliceListService $service, int $userId, string $command): string
    {
        // 1) "добавь молоко", "добавь молоко, яйца, краску"
        if (preg_match('~^добав(ь|ить)\b~u', $command)) {
            $added = $service->addFromCommand($userId, $command);
            if (!$added) {
                return 'Не поняла, что добавить в список.';
            }

            $titlesAdded = array_map(fn($i) => $i->title, $added);
            $list = $service->getActiveList($userId);
            $count = count($list);

            $addedText = implode(', ', $titlesAdded);

            if ($count === 1 && count($titlesAdded) === 1) {
                return 'Добавила в список: ' . $addedText . '. В списке одна позиция.';
            }

            return 'Добавила в список: ' . $addedText . ". Сейчас в списке {$count} позиций.";
        }

        // 2) "что в списке", "что купить", "список покупок"

// ===== НОРМАЛИЗАЦИЯ КОМАНДЫ =====
        $cmd = mb_strtolower($command, 'utf-8');

// убираем обращения к ассистенту и вежливый шум
        $cmd = preg_replace(
            '~\b(алиса|яндекс|слушай|пожалуйста|плиз|ok|ок)\b~u',
            '',
            $cmd
        );

// чистим знаки и пробелы
        $cmd = trim($cmd, " \t\n\r\0\x0B.!?,");
        $cmd = preg_replace('~\s+~u', ' ', $cmd);

// ===== ИНТЕНТ: ОЧИСТИТЬ СПИСОК =====
        $clearListPattern = '~\b(
    очист(и|ить|ка|и всё)? |
    удал(и|ить|яй)? |
    сброс(ь|ить)? |
    убер(и|ать)? |
    отметь.*куплен |
    всё\s+куплен
)\b
.*\b(
    список |
    всё |
    все |
    покупки
)\b~ux';

        if (preg_match($clearListPattern, $cmd)) {

            $affected = $service->completeAll($userId);

            if ($affected === 0) {
                return 'В списке уже нет активных покупок.';
            }

            return "Отметила как купленные {$affected} позиций. Список очищен.";
        }

// ===== ДЕФОЛТ =====
        return 'Я могу вести список покупок. Скажи: "добавь молоко" или "добавь хлеб и сахар", либо скажи "очисти список", чтобы всё отметить как купленное.';

    }


    // ===== helpers =====
    private function getExpectedSecret(): string
    {
        $env = getenv('ALICE_WEBHOOK_SECRET');
        return is_string($env) ? trim($env) : '';
    }

    private function jsonOut(array $payload)
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8', true, 200);
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        exit;
    }


}

