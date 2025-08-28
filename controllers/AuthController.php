<?php

namespace app\controllers;

use app\models\SignupForm;
use Yii;
use yii\web\Controller;

class AuthController extends Controller
{
    /** ========== МИНИ-ХЕЛПЕРЫ БЕЗОПАСНОСТИ ========== */

    /** Быстрая отправка формы (боты/скрипты) */
    private function tooFastSubmit(int $renderTs, int $minSec = 2): bool
    {
        return ($renderTs === 0) || (time() - $renderTs < $minSec);
    }

    /** Ключ для лимита по роуту+IP+email */
    private function rateLimitKey(string $route, string $email = ''): string
    {
        $ip = Yii::$app->request->userIP ?? '0.0.0.0';
        return 'rl:' . $route . ':' . sha1($ip . '|' . mb_strtolower(trim((string)$email)));
    }

    /**
     * Разрешает попытку в окне. Простейший storage — Yii::$app->cache (APC/Redis/File).
     * @return bool true если можно продолжать
     */
    private function allowAttempt(string $route, string $email, int $limit, int $windowSec): bool
    {
        $key   = $this->rateLimitKey($route, $email);
        $cache = Yii::$app->cache;

        $data = $cache->get($key);
        if (!$data) {
            $cache->set($key, ['c' => 1, 'ts' => time()], $windowSec);
            return true;
        }
        $count = (int)$data['c'];
        $ts    = (int)$data['ts'];

        if (time() - $ts > $windowSec) {
            $cache->set($key, ['c' => 1, 'ts' => time()], $windowSec);
            return true;
        }
        if ($count >= $limit) {
            return false;
        }
        $data['c'] = $count + 1;
        $cache->set($key, $data, $windowSec);
        return true;
    }

    /** Микро-backoff на ошибках (нарастающий, но ≤0.5с) */
    private function backoffOnError(string $route, string $email): void
    {
        $key   = $this->rateLimitKey($route, $email) . ':err';
        $cache = Yii::$app->cache;
        $n     = (int)$cache->get($key) + 1;
        $cache->set($key, $n, 900);
        usleep(min(500000, 100000 * $n)); // 0.1s..0.5s
    }

    /** Простая проверка «одноразовых» доменов (опционально) */
    private function isDisposableEmail(string $email): bool
    {
        $email = mb_strtolower(trim($email));
        return (bool)preg_match('~@(?:mailinator\.com|guerrillamail\.com|10minutemail\.com|tempmail\.|yopmail\.com)$~', $email);
    }

    /** ================================================ */

    public function actionLogin()
    {
        $this->layout = '@app/views/layouts/guest';

        if (!Yii::$app->user->isGuest) {
            return $this->goHome();
        }

        if (Yii::$app->request->isPost) {
            $email    = trim((string)Yii::$app->request->post('email'));
            $password = (string)Yii::$app->request->post('password');
            $hp       = (string)Yii::$app->request->post('hp'); // honeypot
            $renderTs = (int)Yii::$app->request->post('render_ts', 0);

            // 1) honeypot
            if ($hp !== '') {
                Yii::warning("Honeypot triggered on login from IP=" . Yii::$app->request->userIP, __METHOD__);
                Yii::$app->session->setFlash('error', 'Ошибка. Попробуйте ещё раз.');
                return $this->render('login');
            }

            // 2) слишком быстрый submit
            if ($this->tooFastSubmit($renderTs, 2)) {
                Yii::$app->session->setFlash('error', 'Слишком быстро. Попробуйте ещё раз.');
                return $this->render('login');
            }

            // 3) rate-limit: 5 попыток за 15 минут для пары (ip,email)
            if (!$this->allowAttempt('login', $email, 5, 900)) {
                Yii::$app->session->setFlash('error', 'Слишком много попыток. Подождите 15 минут.');
                return $this->render('login');
            }

            // 4) базовая логика авторизации
            $user = \app\models\User::findByEmail($email);
            if ($user && $user->validatePassword($password)) {
                Yii::$app->user->login($user, 3600 * 24 * 30);

                // 1) POST, 2) cookie — сохраняем TZ
                $tz = Yii::$app->request->post('tz');
                if (!$tz) {
                    $tz = Yii::$app->request->cookies->getValue('tz');
                }
                if ($tz) $tz = urldecode($tz);

                if ($tz && in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                    $aff = Yii::$app->db->createCommand()
                        ->update('{{%user}}', ['timezone' => $tz], ['id' => $user->id])
                        ->execute();
                    $user->timezone = $tz; // синхронизация объекта
                    Yii::info("Saved timezone on login uid={$user->id}: {$tz}, affected={$aff}", __METHOD__);
                }

                return $this->goHome();
            }

            // ошибка — небольшой backoff
            $this->backoffOnError('login', $email);
            Yii::$app->session->setFlash('error', 'Неверный e-mail или пароль.');
        }

        return $this->render('login');
    }

    public function actionSignup()
    {
        $model = new SignupForm();

        if ($model->load(Yii::$app->request->post())) {
            $hp       = (string)Yii::$app->request->post('hp'); // honeypot
            $renderTs = (int)Yii::$app->request->post('render_ts', 0);

            // 1) honeypot/быстрый submit
            if ($hp !== '' || $this->tooFastSubmit($renderTs, 3)) {
                Yii::$app->session->setFlash('error', 'Ошибка. Попробуйте ещё раз.');
                return $this->render('signup', ['model' => $model]);
            }

            // 2) одноразовые домены (минимальная отсечка)
            if (property_exists($model, 'email') && $model->email && $this->isDisposableEmail($model->email)) {
                Yii::$app->session->setFlash('error', 'Этот e-mail не принимается.');
                return $this->render('signup', ['model' => $model]);
            }

            if ($model->signup()) {

                // 1) POST, 2) cookie — сохраняем TZ
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
        }

        return $this->render('signup', ['model' => $model]);
    }

    public function actionLogout()
    {
        Yii::$app->user->logout();
        return $this->goHome();
    }
}
