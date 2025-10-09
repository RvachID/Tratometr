<?php
namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;

class SkillController extends Controller
{
    public $enableCsrfValidation = false;

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;
        return parent::beforeAction($action);
    }

    public function actionWebhook()
    {
        try {
            // Тело запроса (работает даже без JsonParser)
            $raw  = Yii::$app->request->getRawBody();
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                $data = Yii::$app->request->getBodyParams();
                if (!is_array($data)) $data = [];
            }

            // Очень простой ответ — чтобы песочница перестала видеть 500
            $text = 'Навык подключён. Скажи, например: "добавь молоко".';

            // Возвращаем строго JSON (через helper), без сторонних побочек
            return $this->asJson([
                'version'  => '1.0',
                'response' => [
                    'text'        => $text,
                    'tts'         => $text,
                    'end_session' => false,
                ],
            ]);
        } catch (\Throwable $e) {
            // Логируем фатал/исключение и всё равно отвечаем 200
            Yii::error('ALICE webhook exception: '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine(), __METHOD__);
            return $this->asJson([
                'version'  => '1.0',
                'response' => [
                    'text'        => 'Упс, что-то пошло не так, попробуй ещё раз.',
                    'end_session' => false,
                ],
            ]);
        }
    }
}
