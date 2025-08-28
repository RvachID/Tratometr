<?php

namespace app\controllers;

use app\models\SignupForm;
use Yii;
use yii\web\Controller;

class AuthController extends Controller
{

    public function actionLogin()
    {
        $this->layout = '@app/views/layouts/guest';

        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        if (Yii::$app->request->isPost) {
            $email    = Yii::$app->request->post('email');
            $password = Yii::$app->request->post('password');

            $user = \app\models\User::findByEmail($email);
            if ($user && $user->validatePassword($password)) {
                Yii::$app->user->login($user, 3600 * 24 * 30);

                // 1) пробуем взять TZ из POST, 2) если нет — из cookie
                $tz = Yii::$app->request->post('tz');
                if (!$tz) {
                    $tz = Yii::$app->request->cookies->getValue('tz');
                }
                if ($tz) $tz = urldecode($tz);

                if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                    // прямой UPDATE — обходит кэш схемы
                    $aff = Yii::$app->db->createCommand()
                        ->update('{{%user}}', ['timezone' => $tz], ['id' => $user->id])
                        ->execute();
                    $user->timezone = $tz; // синхронизируем объект
                    Yii::info("Saved timezone on login uid={$user->id}: {$tz}, affected={$aff}", __METHOD__);
                }

                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Неверный e-mail или пароль.');
        }

        return $this->render('login');
    }

    public function actionSignup()
    {
        $model = new SignupForm();

        if ($model->load(Yii::$app->request->post()) && $model->signup()) {

            // 1) POST, 2) cookie
            $tz = Yii::$app->request->post('tz');
            if (!$tz) {
                $tz = Yii::$app->request->cookies->getValue('tz');
            }
            if ($tz) $tz = urldecode($tz);

            if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                // Ищем только что созданного пользователя
                $u = null;
                if (property_exists($model, 'email') && $model->email) {
                    $u = \app\models\User::findByEmail($model->email);
                }
                if (!$u && !Yii::$app->user->isGuest) {
                    $u = Yii::$app->user->identity;
                }
                if ($u) {
                    $aff = Yii::$app->db->createCommand()
                        ->update('{{%user}}', ['timezone' => $tz], ['id' => $u->id])
                        ->execute();
                    $u->timezone = $tz;
                    Yii::info("Saved timezone on signup uid={$u->id}: {$tz}, affected={$aff}", __METHOD__);
                }
            }

            Yii::$app->session->setFlash('success', 'Регистрация успешна, войдите в систему.');
            return $this->redirect(['login']);
        }

        return $this->render('signup', ['model' => $model]);
    }


    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }
}
