<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    // ВАЖНО: совместимая сигнатура с типом возврата
    public function beforeAction($action): bool
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionWebhook()
    {
        try {
            // Читаем тело запроса (работает c JsonParser и без него)
            $data = Yii::$app->request->getBodyParams();
            if (!is_array($data) || !$data) {
                $data = json_decode(Yii::$app->request->getRawBody(), true) ?: [];
            }

            // Простейший ответ для песочницы
            $text = 'Навык подключён. Скажи: «добавь молоко».';

            return [
                'version'  => '1.0',
                'session'  => $data['session'] ?? (object)[],
                'response' => [
                    'text'        => $text,
                    'tts'         => $text,
                    'end_session' => false,
                ],
            ];
        } catch (\Throwable $e) {
            Yii::error('ALICE webhook exception: ' . $e->getMessage(), __METHOD__);
            // даже при ошибке отдаём 200 с валидным JSON
            return [
                'version'  => '1.0',
                'response' => [
                    'text'        => 'Упс, что-то пошло не так. Попробуй ещё раз.',
                    'end_session' => false,
                ],
            ];
        }
    }
}
