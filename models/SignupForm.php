<?php

namespace app\models;

use yii\base\Model;

class SignupForm extends Model
{
    public $email;
    public $password;
    public $password_repeat;

    public function rules()
    {
        return [
            [['email', 'password', 'password_repeat'], 'required'],

            ['email', 'email'],
            ['email', 'unique', 'targetClass' => User::class, 'message' => 'Этот e-mail уже используется.'],

            // пароль: минимум 8, обязательно буквы и цифры
            ['password', 'string', 'min' => 8, 'max' => 72],
            ['password', 'match', 'pattern' => '/^(?=.*[A-Za-z])(?=.*\d).+$/',
                'message' => 'Пароль должен содержать буквы и цифры.'],

            ['password_repeat', 'compare', 'compareAttribute' => 'password'],
        ];
    }

    public function signup()
    {
        if (!$this->validate()) {
            return null;
        }

        $user = new User();
        $user->email = $this->email;
        $user->setPassword($this->password); // <- используем хеширование
        $user->created_at = time();
        $user->updated_at = time();

        return $user->save() ? $user : null;
    }
}
