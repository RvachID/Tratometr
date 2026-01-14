<?php

namespace app\components;

use app\models\PurchaseSession;
use Yii;
use yii\base\Component;
use yii\db\Expression;

class PurchaseSessionService extends Component
{
    /** TTL автозакрытия, по умолчанию 3 часа; можно переопределить в params['ps']['autocloseSeconds'] */
    public int $autocloseSeconds = 10800;

    public function init(): void
    {
        parent::init();
        $p = Yii::$app->params['ps']['autocloseSeconds'] ?? null;
        if (is_int($p) && $p > 0) {
            $this->autocloseSeconds = $p;
        }
    }

    /**
     * Вернуть активную сессию пользователя или null.
     * Если «протухла» — аккуратно финализируем и вернём null.
     * ВНИМАНИЕ: никаких вызовов Yii::$app->ps->active() внутри — только прямой запрос!
     */
    /**
     * Вернуть активную сессию пользователя.
     * Если «протухла» (updated_at старше now - TTL) — аккуратно автозакрываем и возвращаем null.
     */
    public function active(int $userId): ?PurchaseSession
    {
        $ps = PurchaseSession::find()
            ->where(['user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$ps) {
            return null;
        }

        // TTL-автозакрытие
        if ($ps->updated_at <= (time() - $this->autocloseSeconds)) {
            $this->finalize($ps, 'auto-ttl'); // закрываем и не возвращаем
            return null;
        }

        return $ps;
    }


    /**
     * Последнее «движение» в сессии:
     * - для закрытых: момент закрытия
     * - для активных: max(created_at из price_entry) или updated_at, или started_at
     */
    public function lastActivityTs(\app\models\PurchaseSession $ps): int
    {
        if ((int)$ps->status === \app\models\PurchaseSession::STATUS_CLOSED) {
            return (int)$ps->closed_at ?: (int)$ps->updated_at ?: (int)$ps->started_at;
        }

        $lastScan = (new \yii\db\Query())
            ->from('{{%price_entry}}')
            ->where(['user_id' => $ps->user_id, 'session_id' => $ps->id])
            ->max('created_at');

        return (int)($lastScan ?: $ps->updated_at ?: $ps->started_at);
    }

    /** Обновить updated_at у активной сессии (без автозакрытия). */
    public function touch(PurchaseSession $ps): void
    {
        $ps->updateAttributes(['updated_at' => time()]);
    }


    /**
     * Закрыть текущую активную сессию пользователя (если есть).
     * Возвращает true, если активной не было или закрыли успешно.
     */
    public function closeActive(int $userId, string $reason = 'manual'): bool
    {
        $ps = PurchaseSession::find()
            ->where(['user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$ps) {
            return true; // нечего закрывать
        }

        $this->finalize($ps, $reason); // бросит исключение при проблеме
        return true;
    }

    /**
     * Начать новую сессию (с опц. лимитом в рублях).
     * Возвращает созданную активную сессию.
     */
    public function begin(int $userId, string $shop, ?string $category = null, ?float $limitRub = null): PurchaseSession
    {
        $now = time();

        $ps = new PurchaseSession();
        $ps->user_id = $userId;
        $ps->shop = $shop;
        $ps->category = $category ?: null;
        $ps->status = PurchaseSession::STATUS_ACTIVE;
        $ps->started_at = $now;
        $ps->updated_at = $now;
        $ps->limit_amount = ($limitRub !== null) ? (int)round($limitRub * 100) : null; // копейки
        $ps->total_amount = 0;
        $ps->limit_left = $ps->limit_amount === null ? null : $ps->limit_amount;
        $ps->closed_at = null;

        if (!$ps->save(false)) {
            throw new \RuntimeException('Не удалось начать сессию');
        }

        return $ps;
    }

    /**
     * Финализировать сессию: посчитать сумму, записать кэш и поставить статус=9.
     * Работает транзакцией. Возвращает void, при ошибке бросает исключение.
     *
     * @param PurchaseSession|int $session модель или ID
     */
    public function finalize($session, string $reason = 'manual'): void
    {
        /** @var PurchaseSession|null $ps */
        $ps = $session instanceof PurchaseSession
            ? $session
            : PurchaseSession::findOne((int)$session);

        if (!$ps) {
            throw new \RuntimeException('Сессия не найдена');
        }
        if ((int)$ps->status === PurchaseSession::STATUS_CLOSED) {
            return; // уже закрыта
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction(); // без forUpdate, чтобы не ловить UnknownMethod

        try {
            // Сумма в рублях (float)
            $sumRub = (float)(new \yii\db\Query())
                ->from('price_entry')
                ->where(['user_id' => $ps->user_id, 'session_id' => $ps->id])
                ->sum(new Expression('amount * qty'));

            $sumCents = (int)round($sumRub * 100);

            $limitLeft = null;
            if ($ps->limit_amount !== null) {
                $limitLeft = (int)$ps->limit_amount - $sumCents;
            }

            // Прямое обновление кэш-колонок
            $db->createCommand()->update('{{%purchase_session}}', [
                'total_amount' => $sumCents,
                'limit_left' => $limitLeft,
                'status' => PurchaseSession::STATUS_CLOSED,
                'closed_at' => $ps->closed_at ?: time(),
                'updated_at' => time(),
            ], ['id' => (int)$ps->id])->execute();

            $tx->commit();
            Yii::info("PS#{$ps->id} finalized ({$reason}), total={$sumCents}, limit_left=" . ($limitLeft ?? 'null'), __METHOD__);
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error("Finalize failed: " . $e->getMessage(), __METHOD__);
            throw $e;
        }
    }


}
