<?php
namespace app\models;

use yii\base\Model;

class SignupForm extends Model
{
    public $email;
    public $pin_code;

    public function rules()
    {
        return [
            [['email', 'pin_code'], 'required'],
            ['email', 'email'],
            ['email', 'unique', 'targetClass' => User::class],
            ['pin_code', 'match', 'pattern' => '/^\d{4,6}$/'],
        ];
    }

    public function signup()
    {
        if (!$this->validate()) {
            return null;
        }
        $user = new User();
        $user->email = $this->email;
        $user->pin_code = $this->pin_code;
        return $user->save() ? $user : null;
    }
}
