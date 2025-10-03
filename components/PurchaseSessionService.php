<?php
namespace app\components;

use app\models\PurchaseSession;
use Yii;
use yii\base\Component;
use yii\db\Expression;
use yii\db\Transaction;

class PurchaseSessionService extends Component
{
    /** @var int через DI в config: 'autocloseSeconds' => 10800 */
    public int $autocloseSeconds = 10800;

    /**
     * Вернуть активную сессию пользователя.
     * Если «протухла» (updated_at старше now - TTL) — автозакрываем (finalize) и возвращаем null.
     */
    public function active(int $userId): ?\app\models\PurchaseSession
    {
        $ps = \app\models\PurchaseSession::find()
            ->where([
                'user_id' => $userId,
                'status'  => \app\models\PurchaseSession::STATUS_ACTIVE,
            ])
            ->orderBy(['updated_at' => SORT_DESC, 'id' => SORT_DESC])
            ->limit(1)
            ->one();

        if (!$ps) {
            return null;
        }

        // TTL: если «протухла» — финализируем и возвращаем null
        if ($ps->updated_at <= time() - (int)$this->autocloseSeconds) {
            $this->finalize($ps, 'auto-ttl');
            return null;
        }

        return $ps;
    }


    /** Обновить updated_at у активной сессии (без автозакрытия). */
    public function touch(PurchaseSession $ps): void
    {
        $ps->updateAttributes(['updated_at' => time()]);
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
        $ps->limit_amount = ($limitRub !== null) ? (int)round($limitRub * 100) : null; // в копейках
        $ps->total_amount = 0;
        $ps->limit_left = $ps->limit_amount === null ? null : $ps->limit_amount;
        $ps->closed_at = null;

        if (!$ps->save(false)) {
            throw new \RuntimeException('Не удалось начать сессию');
        }
        return $ps;
    }

    /**
     * Финализировать сессию: посчитать сумму, записать кэш и статус=9.
     * Идём транзакцией и блокируем строку FOR UPDATE, чтобы избежать гонок.
     *
     * @param PurchaseSession|int $session модель или ID
     * @param string $reason для логов
     */
    public function finalize($session, string $reason = 'manual'): void
    {
        /** @var PurchaseSession|null $ps */
        $ps = $session instanceof PurchaseSession
            ? $session
            : PurchaseSession::findOne((int)$session);

        if (!$ps) throw new \RuntimeException('Сессия не найдена');
        if ((int)$ps->status === PurchaseSession::STATUS_CLOSED) return; // уже закрыта

        $db = Yii::$app->db;
        $tx = $db->beginTransaction(Transaction::SERIALIZABLE);
        try {
            // Повторно читаем и блокируем
            $ps = PurchaseSession::find()
                ->where(['id' => $ps->id])
                ->forUpdate()
                ->one();

            if (!$ps) throw new \RuntimeException('Сессия не найдена (repeatable read)');
            if ((int)$ps->status === PurchaseSession::STATUS_CLOSED) { $tx->commit(); return; }

            // Сумма в рублях
            $sumRub = (float)(new \yii\db\Query())
                ->from('price_entry')
                ->where(['user_id' => $ps->user_id, 'session_id' => $ps->id])
                ->sum(new Expression('amount * qty'));

            $sumCents = (int)round($sumRub * 100);

            $limitLeft = null;
            if ($ps->limit_amount !== null) {
                $limitLeft = (int)$ps->limit_amount - $sumCents;
            }

            // Обновляем кэш полей в таблице purchase_session
            $db->createCommand()->update('{{%purchase_session}}', [
                'total_amount' => $sumCents,
                'limit_left'   => $limitLeft,
                'status'       => PurchaseSession::STATUS_CLOSED,
                'closed_at'    => $ps->closed_at ?: time(),
                'updated_at'   => time(),
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
