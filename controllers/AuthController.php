<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\User;

class AuthController extends Controller
{
    public $enableCsrfValidation = false; // принимаем JSON без CSRF

    public function actionTgLogin()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $body = json_decode(Yii::$app->request->getRawBody(), true) ?: [];
        $initData = $body['initData'] ?? '';
        $botToken = getenv('BOT_TOKEN') ?: (Yii::$app->params['botToken'] ?? null);
        if (!$botToken) return ['error' => 'BOT_TOKEN is not set'];

        // 1) проверяем подпись
        if (!$this->verifyInitData($initData, $botToken)) {
            return ['error' => 'Bad signature'];
        }

        // 2) достаём user из initData
        parse_str($initData, $q);
        $tgUser = isset($q['user']) ? json_decode($q['user'], true) : null;
        if (!$tgUser || empty($tgUser['id'])) return ['error' => 'No user'];

        $tgId = (string)$tgUser['id'];

        // 3) ищем/создаём пользователя
        $user = User::findByTelegramId($tgId);
        if (!$user) {
            $user = new User();
            $user->telegram_id = $tgId;
            // email оставляем пустым; password_hash/auth_key сгенерируются в beforeValidate
            if (!$user->save()) return ['error' => current($user->firstErrors)];
            $status = 'new';
        } else {
            $status = 'ok';
        }

        // 4) логиним сессией Yii
        Yii::$app->user->login($user, 3600 * 24 * 30);

        return ['status' => $status, 'user_id' => $user->id];
    }

    private function verifyInitData(string $initData, string $botToken): bool
    {
        parse_str($initData, $params);
        if (!isset($params['hash'])) return false;
        $hash = $params['hash']; unset($params['hash']);
        ksort($params);
        $dataCheckString = implode("\n", array_map(
            fn($k,$v)=>$k.'='.$v,
            array_keys($params), $params
        ));
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calc = hash_hmac('sha256', $dataCheckString, $secretKey);
        return hash_equals($calc, $hash);
    }
}
