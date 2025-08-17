<?php
namespace app\models;

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
}
