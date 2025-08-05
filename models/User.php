<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use yii\behaviors\TimestampBehavior;

class User extends ActiveRecord implements IdentityInterface
{
    public static function tableName() { return '{{%user}}'; }

    public function behaviors()
    {
        return [TimestampBehavior::class]; // проставит created_at/updated_at (int)
    }

    public function rules()
    {
        return [
            // email теперь НЕ обязателен
            ['email', 'email'],
            ['email', 'unique'],

            // telegram_id — строка и уникален (может быть null)
            ['telegram_id', 'string', 'max' => 255],
            ['telegram_id', 'unique'],

            [['password_hash', 'auth_key'], 'required'],
            [['email', 'password_hash', 'auth_key'], 'string', 'max' => 255],
            [['created_at', 'updated_at'], 'integer'],
        ];
    }

    public function beforeValidate()
    {
        if (empty($this->auth_key)) {
            $this->auth_key = Yii::$app->security->generateRandomString();
        }
        if (empty($this->password_hash)) {
            // ставим «случайный» хеш, т.к. логин идёт через Telegram
            $this->password_hash = Yii::$app->security->generatePasswordHash(Yii::$app->security->generateRandomString());
        }
        return parent::beforeValidate();
    }

    // IdentityInterface
    public static function findIdentity($id) { return static::findOne($id); }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return $this->getPrimaryKey(); }
    public function getAuthKey() { return $this->auth_key; }
    public function validateAuthKey($authKey) { return $this->auth_key === $authKey; }

    // Удобный хелпер
    public static function findByTelegramId(string $tgId): ?self
    {
        return static::findOne(['telegram_id' => $tgId]);
    }
}
