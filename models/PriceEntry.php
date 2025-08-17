<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property float $amount
 * @property float $qty
 * @property string|null $store
 * @property string|null $category
 * @property string $source
 * @property string|null $note
 * @property string|null $photo_path
 * @property string|null $recognized_text
 * @property float|null $recognized_amount
 * @property int $created_at
 * @property int $updated_at
 * @property int $session_id
 */
class PriceEntry extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%price_entry}}';
    }

    public function rules()
    {
        return [
            [['user_id', 'amount', 'qty', 'source', 'created_at', 'updated_at'], 'required'],
            [['user_id', 'created_at', 'updated_at'], 'integer'],
            [['amount', 'qty', 'recognized_amount'], 'number'],
            [['recognized_text'], 'string'],
            [['store', 'category', 'source', 'note', 'photo_path'], 'string', 'max' => 255],
            ['session_id', 'integer'],
            [['source'], 'in', 'range' => ['manual', 'price_tag', 'receipt']],
        ];
    }

    public function beforeSave($insert)
    {
        if ($insert) {
            $this->created_at = time();
            if (!$this->source) {
                $this->source = 'manual';
            }
        }
        $this->updated_at = time();
        return parent::beforeSave($insert);
    }

    public function getTotal()
    {
        return $this->amount * $this->qty;
    }

    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
    public function getSession() {
        return $this->hasOne(PurchaseSession::class, ['id' => 'session_id']);
    }

}
