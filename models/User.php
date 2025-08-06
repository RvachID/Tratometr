<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;

class User extends ActiveRecord implements IdentityInterface
{
    public static function tableName()
    {
        return '{{%user}}';
    }

    public function rules()
    {
        return [
            ['email', 'required'],
            ['email', 'email'],
            ['email', 'unique'],
            ['pin_code', 'required'],
            ['pin_code', 'match', 'pattern' => '/^\d{4,6}$/', 'message' => 'PIN должен быть от 4 до 6 цифр'],
        ];
    }

    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null;
    }

    public static function findByEmail($email)
    {
        return static::findOne(['email' => $email]);
    }

    public function getId()
    {
        return $this->id;
    }

    public function getAuthKey()
    {
        return $this->auth_key;
    }

    public function validateAuthKey($authKey)
    {
        return $this->auth_key === $authKey;
    }

    public function validatePin($pin)
    {
        return $this->pin_code === $pin;
    }

    public function beforeSave($insert)
    {
        if ($insert) {
            $this->auth_key = Yii::$app->security->generateRandomString();
            $this->created_at = time();
        }
        $this->updated_at = time();
        return parent::beforeSave($insert);
    }
}

