<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\helpers\Html;

class LogController extends Controller
{
    /** ----------------- ВЕБХУК ЛОГИ ----------------- */

    public function actionWebhook()
    {
        $logFile = Yii::getAlias('@runtime/webhook.log');
        if (!file_exists($logFile)) {
            return 'Файл логов не найден.';
        }
        $contents = file_get_contents($logFile);
        if ($contents === '' || $contents === false) {
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
        if ($contents === '' || $contents === false) {
            return 'Файл ошибок пуст.';
        }
        return nl2br(Html::encode($contents));
    }

    /** ----------------- МИГРАЦИИ ----------------- */

    /**
     * Запуск миграций через веб с защитой по ключу.
     * Пример: /log/migrate?key=СЕКРЕТ
     */
    public function actionMigrate($key = null)
    {
        // ❗️Секретный ключ берем из переменной окружения (добавь в Railway: MIGRATE_KEY)
        $expected = getenv('MIGRATE_KEY') ?: '';
        if (!$expected || $key !== $expected) {
            Yii::$app->response->statusCode = 403;
            return 'Forbidden';
        }

        // На всякий случай увеличим лимит
        @set_time_limit(300);

        $appRoot   = Yii::getAlias('@app');
        $yiiScript = $appRoot . '/yii';

        // Команда для запуска миграций
        $cmd = 'php ' . escapeshellarg($yiiScript) . ' migrate --interactive=0';

        // Логи
        $outFile = Yii::getAlias('@runtime/migrate.log');
        $errFile = Yii::getAlias('@runtime/migrate_error.log');

        // Подготовим дескрипторы
        $descriptors = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $appRoot);
        if (!is_resource($process)) {
            file_put_contents($errFile, self::stamp("Не удалось запустить процесс: {$cmd}"), FILE_APPEND);
            return 'Не удалось запустить процесс миграции.';
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        // Пишем в логи с метками времени
        if ($stdout !== false && $stdout !== '') {
            file_put_contents($outFile, self::stamp($stdout), FILE_APPEND);
        } else {
            file_put_contents($outFile, self::stamp('[stdout пуст]'), FILE_APPEND);
        }

        if ($stderr !== false && $stderr !== '') {
            file_put_contents($errFile, self::stamp($stderr), FILE_APPEND);
        }

        $summary = "Команда: {$cmd}\nКод выхода: {$exitCode}\n";
        file_put_contents($outFile, self::stamp($summary), FILE_APPEND);

        return nl2br(Html::encode("Готово. Код выхода: {$exitCode}\nСмотри:\n/log/migrate-log\n/log/migrate-error"));
    }

    /**
     * Просмотр stdout миграций.
     * /log/migrate-log
     */
    public function actionMigrateLog()
    {
        $logFile = Yii::getAlias('@runtime/migrate.log');
        if (!file_exists($logFile)) {
            return 'Файл логов миграций не найден.';
        }
        $contents = file_get_contents($logFile);
        if ($contents === '' || $contents === false) {
            return 'Файл логов миграций пуст.';
        }
        return nl2br(Html::encode($contents));
    }

    /**
     * Просмотр stderr миграций.
     * /log/migrate-error
     */
    public function actionMigrateError()
    {
        $logFile = Yii::getAlias('@runtime/migrate_error.log');
        if (!file_exists($logFile)) {
            return 'Файл ошибок миграций не найден.';
        }
        $contents = file_get_contents($logFile);
        if ($contents === '' || $contents === false) {
            return 'Файл ошибок миграций пуст.';
        }
        return nl2br(Html::encode($contents));
    }

    /** Хелпер для префикса времени */
    private static function stamp(string $text): string
    {
        $ts = date('Y-m-d H:i:s');
        return "[{$ts}] {$text}\n";
    }
}
