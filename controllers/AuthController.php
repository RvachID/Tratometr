<?php

namespace app\controllers;

use app\models\SignupForm;
use app\services\Auth\AuthSecurityService;
use Yii;
use yii\web\Controller;

class AuthController extends Controller
{
    private AuthSecurityService $securityService;

    public function init()
    {
        parent::init();
        $this->securityService = Yii::$app->get('authSecurity');
    }

    public function actionLogin()
    {
        $this->layout = '@app/views/layouts/guest';

        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        if (Yii::$app->request->isPost) {
            $email    = trim((string)Yii::$app->request->post('email'));
            $password = (string)Yii::$app->request->post('password');
            $hp       = (string)Yii::$app->request->post('hp');
            $renderTs = (int)Yii::$app->request->post('render_ts', 0);
            $ip       = Yii::$app->request->userIP ?? '0.0.0.0';

            if ($hp !== '') {
                Yii::warning("Honeypot triggered on login from IP={$ip}", __METHOD__);
                Yii::$app->session->setFlash('error', 'Ошибка формы. Попробуйте снова.');
                return $this->render('login');
            }

            if ($this->securityService->tooFastSubmit($renderTs, 2)) {
                Yii::$app->session->setFlash('error', 'Форма отправлена слишком быстро. Попробуйте ещё раз.');
                return $this->render('login');
            }

            if (!$this->securityService->allowAttempt('login', $email, $ip, 5, 900)) {
                Yii::$app->session->setFlash('error', 'Превышено количество попыток входа. Подождите 15 минут.');
                return $this->render('login');
            }

            $user = \app\models\User::findByEmail($email);
            if ($user && $user->validatePassword($password)) {
                Yii::$app->user->login($user, 3600 * 24 * 30);

                $tz = Yii::$app->request->post('tz');
                if (!$tz) {
                    $tz = Yii::$app->request->cookies->getValue('tz');
                }
                $tz = $this->securityService->sanitizeTimezone($tz);
                if ($tz) {
                    $this->securityService->rememberTimezone($user->id, $tz);
                    $user->timezone = $tz;
                }

                return $this->goHome();
            }

            $this->securityService->backoffOnError('login', $email, $ip);
            Yii::$app->session->setFlash('error', 'Неверный e-mail или пароль.');
        }

        return $this->render('login');
    }

    public function actionSignup()
    {
        $model = new SignupForm();

        if ($model->load(Yii::$app->request->post())) {
            $hp       = (string)Yii::$app->request->post('hp');
            $renderTs = (int)Yii::$app->request->post('render_ts', 0);
            $ip       = Yii::$app->request->userIP ?? '0.0.0.0';

            if ($hp !== '' || $this->securityService->tooFastSubmit($renderTs, 3)) {
                Yii::$app->session->setFlash('error', 'Ошибка формы. Попробуйте снова.');
                return $this->render('signup', ['model' => $model]);
            }

            if (property_exists($model, 'email') && $model->email && $this->securityService->isDisposableEmail($model->email)) {
                Yii::$app->session->setFlash('error', 'Временный e-mail не поддерживается.');
                return $this->render('signup', ['model' => $model]);
            }

            if ($model->signup()) {
                $tz = Yii::$app->request->post('tz');
                if (!$tz) {
                    $tz = Yii::$app->request->cookies->getValue('tz');
                }
                $tz = $this->securityService->sanitizeTimezone($tz);

                if ($tz) {
                    $user = null;
                    if (property_exists($model, 'email') && $model->email) {
                        $user = \app\models\User::findByEmail($model->email);
                    }
                    if (!$user && !Yii::$app->user->isGuest) {
                        $user = Yii::$app->user->identity;
                    }
                    if ($user) {
                        $this->securityService->rememberTimezone($user->id, $tz);
                        $user->timezone = $tz;
                    }
                }

                Yii::$app->session->setFlash('success', 'Регистрация прошла успешно, авторизуйтесь.');
                return $this->redirect(['login']);
            }
        }

        return $this->render('signup', ['model' => $model]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }
}
