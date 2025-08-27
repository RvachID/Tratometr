<?php

namespace app\models;

use Yii;
use yii\db\ActiveRecord;
use yii\filters\RateLimitInterface;
use yii\web\IdentityInterface;

class User extends ActiveRecord implements IdentityInterface, RateLimitInterface
{
    public static function tableName()
    {
        return '{{%user}}';
    }

    /** Валидации только для того, что реально хранится в таблице */
    public function rules()
    {
        return [
            ['email', 'required'],
            ['email', 'email'],
            ['email', 'unique'],
            // password_hash хранится уже захешированным — валидируем на notEmpty при сохранении
            ['password_hash', 'required'],
        ];
    }

    /* ---------- IdentityInterface ---------- */
    public static function findIdentity($id)
    {
        return static::findOne($id);
    }

    public static function findIdentityByAccessToken($token, $type = null)
    {
        return null; // не используем
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

    /* ---------- Пароли ---------- */

    /** Устанавливает новый пароль (хеширует) */
    public function setPassword(string $password): void
    {
        $this->password_hash = Yii::$app->security->generatePasswordHash($password);
        $this->password_updated_at = time();
        // Инвалидируем старые «запомнить меня»
        $this->auth_key = Yii::$app->security->generateRandomString();
    }

    /** Проверяет пароль против хеша */
    public function validatePassword(string $password): bool
    {
        return $this->password_hash !== '' &&
            Yii::$app->security->validatePassword($password, $this->password_hash);
    }

    /* ---------- Техничка ---------- */
    public function beforeSave($insert)
    {
        if ($insert) {
            if (empty($this->auth_key)) {
                $this->auth_key = Yii::$app->security->generateRandomString();
            }
            $this->created_at = time();
        }
        $this->updated_at = time();
        return parent::beforeSave($insert);
    }

    /* ---------- RateLimitInterface (OCR лимиты оставляем как есть) ---------- */
    public function getRateLimit($request, $action)
    {
        if ($action && $action->uniqueId === 'scan/recognize') {
            return [50, 60]; // тестовый лимит (макс, окно)
            // return [10, 60]; // боевой лимит
        }
        return [PHP_INT_MAX, 1];
    }

    public function loadAllowance($request, $action)
    {
        return [(int)$this->ocr_allowance, (int)$this->ocr_allowance_updated_at];
    }

    public function saveAllowance($request, $action, $allowance, $timestamp)
    {
        $this->ocr_allowance = (int)$allowance;
        $this->ocr_allowance_updated_at = (int)$timestamp;
        $this->update(false, ['ocr_allowance', 'ocr_allowance_updated_at']);
    }
}
