<?php
$webRoot = __DIR__ . '/web';

// Если запрашивается существующий файл (css/js/png и т.п.) — отдаём напрямую
if (is_file($webRoot . $_SERVER["REQUEST_URI"])) {
    return false;
}

// Всё остальное отдаём в index.php
require $webRoot . '/index.php';
