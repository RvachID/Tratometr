<?php
namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\web\IdentityInterface;
use yii\filters\RateLimitInterface;


class User extends \yii\db\ActiveRecord implements \yii\web\IdentityInterface, RateLimitInterface
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
    // 10 запросов за 60 сек
    public function getRateLimit($request, $action)
    {
        // Лимит только для OCR-аплоада: иначе — «без лимита»
        if ($request->get('r') === 'scan/upload') {
            return [1, 60]; // [макс-токенов, окно(сек)] тест
           /* return [10, 60];*/ // [макс-токенов, окно(сек)] боевой
        }
        return [PHP_INT_MAX, 1]; // по сути, без ограничений
    }

    public function loadAllowance($request, $action)
    {
        return [(int)$this->ocr_allowance, (int)$this->ocr_allowance_updated_at];
    }

    public function saveAllowance($request, $action, $allowance, $timestamp)
    {
        $this->ocr_allowance = (int)$allowance;
        $this->ocr_allowance_updated_at = (int)$timestamp;
        // без валидации, только эти поля
        $this->update(false, ['ocr_allowance','ocr_allowance_updated_at']);
    }
}

