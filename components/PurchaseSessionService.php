<?php
namespace app\components;

use app\models\PurchaseSession;
use app\models\PriceEntry;
use Yii;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Transaction;

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

        if ($ps->updated_at <= (time() - $this->autocloseSeconds)) {
            $this->finalize($ps, 'auto-ttl'); // закроем и больше её не вернём
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

    /** Обновить updated_at у сессии (продлеваем «жизнь»). */
    public function touch(PurchaseSession $ps): void
    {
        $ps->updateAttributes(['updated_at' => time()]);
    }

    /**
     * Закрыть текущую активную сессию пользователя, если есть.
     * Возвращает true, если что-то закрыли.
     */
    public function closeActive(int $userId, string $reason = 'manual'): bool
    {
        $ps = PurchaseSession::find()
            ->where(['user_id' => $userId, 'status' => PurchaseSession::STATUS_ACTIVE])
            ->orderBy(['updated_at' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$ps) {
            return false;
        }
        return $this->finalize($ps, $reason);
    }

    /**
     * Начать новую активную сессию (лимит в РУБЛЯХ, сохраняем в копейках).
     */
    public function begin(int $userId, string $shop, ?string $category = null, ?float $limitRub = null): PurchaseSession
    {
        $now = time();

        $ps = new PurchaseSession();
        $ps->user_id     = $userId;
        $ps->shop        = $shop;
        $ps->category    = $category ?: null;
        $ps->status      = PurchaseSession::STATUS_ACTIVE;
        $ps->started_at  = $now;
        $ps->updated_at  = $now;
        $ps->total_amount = 0;
        $ps->closed_at    = null;

        if ($limitRub !== null) {
            $ps->limit_amount = (int) round($limitRub * 100);     // копейки
            $ps->limit_left   = $ps->limit_amount;
        } else {
            $ps->limit_amount = null;
            $ps->limit_left   = null;
        }

        if (!$ps->save(false)) {
            throw new \RuntimeException('Не удалось начать сессию');
        }

        return $ps;
    }

    /**
     * Финализировать сессию: посчитать сумму, записать кэш (в копейках), закрыть.
     * Возвращает true, если успешно.
     */
    public function finalize(PurchaseSession $ps, string $reason = 'manual'): bool
    {
        if ((int)$ps->status === PurchaseSession::STATUS_CLOSED) {
            return true; // уже закрыта
        }

        $db = Yii::$app->db;
        $tx = $db->beginTransaction(Transaction::SERIALIZABLE);

        try {
            // перечитываем и блокируем строку
            $ps = PurchaseSession::find()
                ->where(['id' => $ps->id])
                ->forUpdate()
                ->one();

            if (!$ps) {
                throw new \RuntimeException('Сессия не найдена (повторное чтение)');
            }
            if ((int)$ps->status === PurchaseSession::STATUS_CLOSED) {
                $tx->commit();
                return true;
            }

            // сумма в РУБЛЯХ по позициям
            $sumRub = (float)(new \yii\db\Query())
                ->from('{{%price_entry}}')
                ->where(['user_id' => $ps->user_id, 'session_id' => $ps->id])
                ->sum(new Expression('amount * qty'));

            $totalCents = (int) round($sumRub * 100);

            $limitLeft = null;
            if ($ps->limit_amount !== null) {
                $limitLeft = (int)$ps->limit_amount - $totalCents;
            }

            $db->createCommand()->update('{{%purchase_session}}', [
                'total_amount' => $totalCents,
                'limit_left'   => $limitLeft,
                'status'       => PurchaseSession::STATUS_CLOSED,
                'closed_at'    => $ps->closed_at ?: time(),
                'updated_at'   => time(),
            ], ['id' => (int)$ps->id])->execute();

            $tx->commit();

            Yii::info("PS#{$ps->id} finalized ({$reason}), total_cents={$totalCents}, limit_left=" . ($limitLeft ?? 'null'), __METHOD__);
            return true;
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error("Finalize failed: " . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
