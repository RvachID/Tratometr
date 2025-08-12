<?php

namespace app\components;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;

class OcrClient
{
    private Client $http;
    private string $endpoint;
    private ?string $apiKey;

    public function __construct()
    {
        $p = \Yii::$app->params['ocr'] ?? [];
        $this->endpoint = $p['endpoint'] ?? 'https://api.ocr.space/parse/image';
        $this->apiKey   = $p['apiKey']   ?? null;

        if ((defined('YII_ENV') ? YII_ENV !== 'dev' : true) && empty($this->apiKey)) {
            throw new \yii\base\InvalidConfigException('OCR_API_KEY отсутствует.');
        }

        $this->http = new Client([
            'timeout'         => 45,   // общий таймаут запроса
            'connect_timeout' => 10,   // отдельно коннект
            'headers' => [
                'User-Agent' => 'Tratometr/1.0 (+Railway)',
            ],
            // Иногда помогает против глюков с IPv6 в хостингах
            'curl' => [
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        ]);
    }

    public function parseImage(string $filePath, string $lang = 'rus'): array
    {
        $multipart = [
            ['name' => 'language',          'contents' => $lang],
            ['name' => 'isOverlayRequired', 'contents' => 'true'],
            ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];
        if ($this->apiKey) {
            $multipart[] = ['name' => 'apikey', 'contents' => $this->apiKey];
        }

        $lastEx = null;
        // 3 попытки с бэкоффом: 0.5с, 1с, 2с
        for ($i = 0; $i < 3; $i++) {
            try {
                $res = $this->http->post($this->endpoint, ['multipart' => $multipart]);
                return json_decode((string)$res->getBody(), true) ?: [];
            } catch (ConnectException|RequestException $e) {
                $lastEx = $e;
                usleep([500000, 1000000, 2000000][$i]); // бэкофф
            }
        }
        throw $lastEx ?: new \RuntimeException('OCR: неизвестная ошибка сети');
    }
}
