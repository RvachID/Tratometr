<?php

namespace app\controllers;

use app\models\SignupForm;
use Yii;
use yii\web\Controller;

class AuthController extends Controller
{
    public function actionSignup()
    {
        $model = new SignupForm();

        if ($model->load(Yii::$app->request->post()) && $model->signup()) {

            // Проставим таймзону новому пользователю из cookie (если валидна)
            $cookieTz = Yii::$app->request->cookies->getValue('tz');
            if ($cookieTz) $cookieTz = urldecode($cookieTz);

            if ($cookieTz && in_array($cookieTz, \DateTimeZone::listIdentifiers(), true)) {
                // Ищем только что созданного пользователя (по email из формы)
                $user = null;
                if (property_exists($model, 'email') && $model->email) {
                    $user = \app\models\User::findByEmail($model->email);
                }
                // На случай, если signup авторизует сразу
                if (!$user && !Yii::$app->user->isGuest) {
                    $user = Yii::$app->user->identity;
                }

                if ($user) {
                    // Сохраняем напрямую (обходит кэш схемы AR)
                    $aff = Yii::$app->db->createCommand()
                        ->update('{{%user}}', ['timezone' => $cookieTz], ['id' => $user->id])
                        ->execute();

                    // синхронизируем объект в памяти и логируем
                    $user->timezone = $cookieTz;
                    Yii::info("Saved timezone on signup uid={$user->id}: {$cookieTz}, affected={$aff}", __METHOD__);
                }
            }

            Yii::$app->session->setFlash('success', 'Регистрация успешна, войдите в систему.');
            return $this->redirect(['login']);
        }

        return $this->render('signup', ['model' => $model]);
    }


    public function actionLogin()
    {
        $this->layout = '@app/views/layouts/guest';

        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        if (Yii::$app->request->isPost) {
            $email = Yii::$app->request->post('email');
            $password = Yii::$app->request->post('password');

            $user = \app\models\User::findByEmail($email);
            if ($user && $user->validatePassword($password)) {
                Yii::$app->user->login($user, 3600 * 24 * 30);

                $cookieTz = Yii::$app->request->cookies->getValue('tz');
                if ($cookieTz) $cookieTz = urldecode($cookieTz);

                if ($cookieTz && in_array($cookieTz, \DateTimeZone::listIdentifiers(), true)) {
                    // Пытаемся обновить прямой командой (в обход AR + кэша схемы)
                    $aff = Yii::$app->db->createCommand()
                        ->update('{{%user}}', ['timezone' => $cookieTz], ['id' => $user->id])
                        ->execute();

                    // На всякий случай — синхронизируем AR-объект и пишем в лог
                    $user->timezone = $cookieTz;
                    Yii::info("Saved timezone for uid={$user->id}: {$cookieTz}, affected={$aff}", __METHOD__);
                }


                return $this->goHome();
            }
            Yii::$app->session->setFlash('error', 'Неверный e-mail или пароль.');
        }

        return $this->render('login');
    }


    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }
}
