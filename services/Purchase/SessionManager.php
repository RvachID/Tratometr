<?php

namespace app\services\Purchase;

use app\components\PurchaseSessionService;
use app\models\PriceEntry;
use app\models\PurchaseSession;
use DomainException;
use Yii;
use yii\db\Connection;

/**
 * Сервис работы с сессиями покупок.
 * Оборачивает компонент PurchaseSessionService и добавляет прикладную логику.
 */
class SessionManager
{
    private PurchaseSessionService $service;
    private Connection $db;

    public function __construct(?PurchaseSessionService $service = null, ?Connection $db = null)
    {
        $this->service = $service ?? Yii::$app->ps;
        $this->db = $db ?? Yii::$app->db;
    }

    public function getActive(int $userId): ?PurchaseSession
    {
        return $this->service->active($userId);
    }

    public function requireActive(int $userId): PurchaseSession
    {
        $session = $this->getActive($userId);
        if (!$session) {
            throw new DomainException('Активная сессия не найдена');
        }
        return $session;
    }

    public function closeActive(int $userId, string $reason = 'manual'): bool
    {
        return $this->service->closeActive($userId, $reason);
    }

    public function begin(int $userId, string $store, ?string $category, ?string $limitRaw): PurchaseSession
    {
        $limit = $this->parseMoney($limitRaw);
        return $this->service->begin(
            $userId,
            $store,
            $category ?: null,
            $limit
        );
    }

    public function finalize(PurchaseSession $session, string $reason = 'manual'): void
    {
        $this->service->finalize($session, $reason);
    }

    public function deleteSession(PurchaseSession $session): void
    {
        $tx = $this->db->beginTransaction();
        try {
            PriceEntry::deleteAll(['user_id' => $session->user_id, 'session_id' => $session->id]);
            $session->delete(false);
            $tx->commit();
        } catch (\Throwable $e) {
            $tx->rollBack();
            throw $e;
        }
    }

    public function touch(PurchaseSession $session): void
    {
        $this->service->touch($session);
    }

    public function lastActivityTimestamp(PurchaseSession $session): int
    {
        return $this->service->lastActivityTs($session);
    }

    public function parseMoney(?string $raw): ?float
    {
        $s = trim((string)$raw);
        if ($s === '') {
            return null;
        }
        $s = str_replace([' ', ','], ['', '.'], $s);
        if (!is_numeric($s)) {
            return null;
        }
        return round((float)$s, 2);
    }

    public function buildStatus(int $userId): array
    {
        $session = $this->getActive($userId);
        if (!$session) {
            return [
                'session' => null,
                'needPrompt' => true,
                'idleSeconds' => null,
                'limitRub' => null,
            ];
        }

        $idle = time() - $this->lastActivityTimestamp($session);
        $limitRub = $session->limit_amount !== null ? round(((int)$session->limit_amount) / 100, 2) : null;

        return [
            'session' => $session,
            'needPrompt' => false,
            'idleSeconds' => $idle,
            'limitRub' => $limitRub,
        ];
    }

    public function formatLimit(PurchaseSession $session): ?float
    {
        if ($session->limit_amount === null) {
            return null;
        }
        return round(((int)$session->limit_amount) / 100, 2);
    }

    public function formatTotals(PurchaseSession $session): array
    {
        $fmt = Yii::$app->formatter;

        $totalRub = $fmt->asDecimal(((int)$session->total_amount) / 100, 2);
        $leftRub = null;
        if ($session->limit_amount !== null) {
            $leftRub = $fmt->asDecimal(((int)$session->limit_left) / 100, 2);
        }

        return [$totalRub, $leftRub];
    }
}
