<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\User;

class AuthController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionTgLogin()
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        try {
            $body = json_decode(Yii::$app->request->getRawBody(), true) ?: [];
            $initData = $body['initData'] ?? '';
            $botToken = getenv('BOT_TOKEN') ?: (Yii::$app->params['botToken'] ?? null);

            if (!$botToken) {
                Yii::error(['stage' => 'token', 'msg' => 'BOT_TOKEN is not set'], 'tgLogin');
                return ['error' => 'BOT_TOKEN is not set'];
            }

            // 1) verify
            if (!$this->verifyInitData($initData, $botToken)) {
                Yii::error([
                    'stage'    => 'verify',
                    'msg'      => 'Bad signature',
                    'initData' => substr($initData, 0, 1000),
                ], 'tgLogin');
                return ['error' => 'Bad signature'];
            }

            // 2) parse user
            parse_str($initData, $q);
            $tgUser = isset($q['user']) ? json_decode($q['user'], true) : null;
            if (!$tgUser || empty($tgUser['id'])) {
                Yii::error([
                    'stage'    => 'user',
                    'msg'      => 'No user in initData',
                    'initData' => substr($initData, 0, 1000),
                ], 'tgLogin');
                return ['error' => 'No user'];
            }

            $tgId = (string)$tgUser['id'];

            // 3) find/create (без findByTelegramId, чтобы исключить фатал)
            $user = User::findOne(['telegram_id' => $tgId]);

            if (!$user) {
                $user = new User();
                $user->telegram_id  = $tgId;
                // password_hash и auth_key зададутся в beforeValidate(), если добавляли
                if (!$user->save()) {
                    Yii::error([
                        'stage'  => 'save',
                        'msg'    => 'User save failed',
                        'errors' => $user->getErrors(),
                    ], 'tgLogin');
                    return ['error' => current($user->firstErrors) ?: 'Save failed'];
                }
                $status = 'new';
                Yii::info(['stage' => 'save', 'msg' => 'New user created', 'user_id' => $user->id, 'tg' => $tgId], 'tgLogin');
            } else {
                $status = 'ok';
                Yii::info(['stage' => 'login', 'msg' => 'User found', 'user_id' => $user->id, 'tg' => $tgId], 'tgLogin');
            }

            // 4) login
            Yii::$app->user->login($user, 3600 * 24 * 30);
            return ['status' => $status, 'user_id' => $user->id];

        } catch (\Throwable $e) {
            // перехватим любые фаталы/ошибки и выведем их в Railway Logs
            Yii::error([
                'stage'   => 'exception',
                'type'    => get_class($e),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ], 'tgLogin');
            // короткий ответ на фронт (без деталей)
            return ['error' => 'Internal error'];
        }
    }

    private function verifyInitData(string $initData, string $botToken): bool
    {
        parse_str($initData, $params);
        if (!isset($params['hash'])) return false;

        $hash = $params['hash'];
        unset($params['hash']);
        ksort($params);

        $dataCheckString = implode("\n", array_map(
            fn($k, $v) => "$k=$v",
            array_keys($params),
            $params
        ));

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calcHash  = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($calcHash, $hash);
    }
}
