<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use app\models\SignupForm;
use app\models\User;

class AuthController extends Controller
{
    public function actionSignup()
    {
        $model = new SignupForm();
        if ($model->load(Yii::$app->request->post()) && $model->signup()) {
            Yii::$app->session->setFlash('success', 'Регистрация успешна, войдите в систему.');
            return $this->redirect(['login']);
        }
        return $this->render('signup', ['model' => $model]);
    }

    public function actionLogin()
    {
        if (Yii::$app->request->isPost) {
            $email = Yii::$app->request->post('email');
            $pin = Yii::$app->request->post('pin_code');

            $user = User::findByEmail($email);
            if ($user && $user->validatePin($pin)) {
                Yii::$app->user->login($user, 3600 * 24 * 30);
                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Неверный e‑mail или PIN.');
        }
        return $this->render('login');
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }
}
