<?php


namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\behaviors\TimestampBehavior;
use yii\behaviors\BlameableBehavior;

class PriceEntry extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%price_entry}}';
    }

    public function behaviors()
    {
        return [
            TimestampBehavior::class,
            [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'user_id',
                'updatedByAttribute' => false,
            ],
        ];
    }

    public function rules()
    {
        return [
            [['amount'], 'required'],
            [['amount', 'qty', 'recognized_amount'], 'number', 'min' => 0],
            [['store', 'category', 'source', 'note', 'photo_path'], 'string', 'max' => 255],
            [['recognized_text'], 'string'],
            [['created_at', 'updated_at', 'user_id'], 'integer'],
        ];
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getTotal()
    {
        return (float)$this->amount * (float)$this->qty;
    }
}
