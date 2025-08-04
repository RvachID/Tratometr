<?php

namespace app\controllers;

use yii\web\Controller;

class LogController extends Controller
{
    public $enableCsrfValidation = false;

    public function actionWebhook()
    {
        $logPath = __DIR__ . '/../../runtime/webhook-debug.log';

        if (!file_exists($logPath)) {
            return 'Файл логов не найден.';
        }

        return '<pre>' . htmlspecialchars(file_get_contents($logPath)) . '</pre>';
    }
}
