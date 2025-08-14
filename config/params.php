<?php
$localSecrets = [];
$secretsFile = __DIR__ . '/_secrets.php';
if (is_file($secretsFile)) {
    // ожидаем: return ['OCR_API_KEY' => 'xxx', 'OCR_ENDPOINT' => '...'];
    $localSecrets = require $secretsFile;
}

return [
    'appName' => 'Тратометр',
    'version' => '0.1.0',

    'ocr' => [
        'endpoint' => getenv('OCR_ENDPOINT') ?: ($localSecrets['OCR_ENDPOINT'] ?? 'https://api.ocr.space/parse/image'),
        'apiKey'   => getenv('OCR_API_KEY')   ?: ($localSecrets['OCR_API_KEY']   ?? null),
    ],
];
