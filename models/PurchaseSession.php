<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;

class PurchaseSession extends ActiveRecord
{
    const STATUS_ACTIVE = 1;
    const STATUS_CLOSED = 9;

    public static function tableName(): string { return 'purchase_session'; }

    public function rules(): array
    {
        return [
            [['user_id','status','started_at','updated_at'], 'integer'],
            [['user_id','shop','category'], 'required'],
            [['limit_amount'], 'integer'],
            [['shop','category'], 'string', 'max' => 120],
        ];
    }

    public function beforeSave($insert)
    {
        $now = time();
        if ($insert && !$this->started_at) $this->started_at = $now;
        $this->updated_at = $now;
        return parent::beforeSave($insert);
    }
    /**
     * Финализировать сессию: посчитать total_amount/limit_left и закрыть.
     * Безопасно вызывать повторно — посчитает заново.
     */
    public function finalize(): bool
    {
        if ($this->isNewRecord) return false;

        $tx = Yii::$app->db->beginTransaction();
        try {
            // Сумма по записям в КОПЕЙКАХ
            $sumK = (new \yii\db\Query())
                ->from('{{%price_entry}}')
                ->where(['session_id' => $this->id])
                ->select(new \yii\db\Expression('COALESCE(SUM(CAST(ROUND(amount * qty * 100) AS SIGNED)),0)'))
                ->scalar();
            $sumK = (int)$sumK;

            $this->total_amount = $sumK;
            if ($this->limit_amount === null) {
                $this->limit_left = null;
            } else {
                $left = (int)$this->limit_amount - $sumK;
                $this->limit_left = $left > 0 ? $left : 0;
            }

            $this->status     = self::STATUS_CLOSED;
            $this->closed_at  = time();
            $this->updated_at = $this->closed_at;

            $this->save(false, ['total_amount','limit_left','status','closed_at','updated_at']);
            $tx->commit();
            return true;
        } catch (\Throwable $e) {
            $tx->rollBack();
            Yii::error($e->getMessage(), __METHOD__);
            return false;
        }
    }

}
