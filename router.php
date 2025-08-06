<?php
// Абсолютный путь до папки web
$webRoot = __DIR__ . '/web';

// Если запрашивается реальный файл (css/js/img) — отдать напрямую
if (is_file($webRoot . $_SERVER['REQUEST_URI'])) {
    return false;
}

// Всё остальное — через index.php
require $webRoot . '/index.php';