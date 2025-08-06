<?php
if (is_file(__DIR__ . '/web' . $_SERVER["REQUEST_URI"])) {
    return false;
}

require __DIR__ . '/web/index.php';
