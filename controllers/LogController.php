<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\helpers\Html;

class LogController extends Controller
{
    public function actionWebhook()
    {
        $logFile = Yii::getAlias('@runtime/webhook.log');
        if (!file_exists($logFile)) {
            return 'Файл логов не найден.';
        }

        $contents = file_get_contents($logFile);
        if (empty($contents)) {
            return 'Файл логов пуст.';
        }

        return nl2br(Html::encode($contents));
    }

    public function actionWebhookError()
    {
        $logFile = Yii::getAlias('@runtime/webhook_error.log');
        if (!file_exists($logFile)) {
            return 'Файл ошибок не найден.';
        }

        $contents = file_get_contents($logFile);
        if (empty($contents)) {
            return 'Файл ошибок пуст.';
        }

        return nl2br(Html::encode($contents));
    }
}
