<?php

namespace app\components;

use GuzzleHttp\Client;
use yii\base\InvalidConfigException;

class OcrClient
{
    private Client $http;
    private string $endpoint;
    private ?string $apiKey;

    public function __construct()
    {
        $params = \Yii::$app->params['ocr'] ?? [];
        $this->endpoint = $params['endpoint'] ?? '';
        $this->apiKey = $params['apiKey'] ?? null;

        if (YII_ENV_PROD && empty($this->apiKey)) {
            throw new InvalidConfigException('OCR_API_KEY отсутствует (env или config/_secrets.php).');
        }
        $this->http = new Client(['timeout' => 20]);
    }

    public function parseImage(string $filePath, string $lang = 'rus'): array
    {
        $multipart = [
            ['name' => 'language', 'contents' => $lang],
            ['name' => 'isOverlayRequired', 'contents' => 'true'],
            ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($this->apiKey) {
            $multipart[] = ['name' => 'apikey', 'contents' => $this->apiKey];
        }

        $res = $this->http->post($this->endpoint, ['multipart' => $multipart]);
        return json_decode((string)$res->getBody(), true) ?: [];
    }
}
