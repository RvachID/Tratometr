<?php

namespace app\controllers;

use yii\web\Controller;
use yii\web\Response;

class LogController extends Controller
{
    public function actionWebhook()
    {
        \Yii::$app->response->format = Response::FORMAT_HTML;

        $logPath = \Yii::getAlias('@app/runtime/logs/webhook.log');

        // ✅ Проверка и автосоздание файла
        if (!file_exists($logPath)) {
            if (!is_dir(dirname($logPath))) {
                mkdir(dirname($logPath), 0777, true);
            }
            file_put_contents($logPath, ''); // создаём пустой файл
        }

        $content = file_get_contents($logPath);
        return $content ?: 'Файл логов пуст.';
    }
}
