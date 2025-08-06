<?php
$webRoot = __DIR__ . '/web';

// Если запрашивается существующий файл — отдаём его напрямую
if (is_file($webRoot . $_SERVER["REQUEST_URI"])) {
    return false;
}

// Запускаем index.php как entry script
require $webRoot . '/index.php';
