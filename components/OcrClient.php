<?php
namespace app\components;

use GuzzleHttp\Client;

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

        // В проде ключ обязателен
        if ((defined('YII_ENV') ? YII_ENV !== 'dev' : true) && empty($this->apiKey)) {
            throw new \yii\base\InvalidConfigException('OCR_API_KEY отсутствует.');
        }

        $this->http = new Client([
            'timeout'         => 45,
            'connect_timeout' => 10,
            'headers'         => ['User-Agent' => 'Tratometr/1.0 (+Railway)'],
            // Иногда помогает против глюков с IPv6 на хостинге
            'curl'            => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
        ]);
    }

    /**
     * Базовый вызов OCR.space (возвращает «сырой» JSON как массив).
     * $options прокидываются как form-data (булевые приводятся к 'true'/'false').
     */
    public function parseImage(string $filePath, string $lang = 'rus', array $options = []): array
    {
        $defaults = [
            'isOverlayRequired' => true,
            'scale'             => true,
            'detectOrientation' => true,
            'OCREngine'         => 2,
        ];
        $opts = array_merge($defaults, $options);

        $toString = static function ($v): string {
            if (is_bool($v)) return $v ? 'true' : 'false';
            return (string)$v;
        };

        $multipart = [
            ['name' => 'language', 'contents' => $lang],
        ];
        foreach ($opts as $k => $v) {
            $multipart[] = ['name' => $k, 'contents' => $toString($v)];
        }
        $multipart[] = ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)];
        if ($this->apiKey) {
            $multipart[] = ['name' => 'apikey', 'contents' => $this->apiKey];
        }

        $lastEx = null;
        for ($i = 0; $i < 3; $i++) {
            try {
                $res = $this->http->post($this->endpoint, ['multipart' => $multipart]);
                return json_decode((string)$res->getBody(), true) ?: [];
            } catch (\GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\RequestException $e) {
                $lastEx = $e;
                usleep([500000, 1000000, 2000000][$i]); // 0.5s, 1s, 2s
            }
        }
        throw $lastEx ?: new \RuntimeException('OCR: неизвестная ошибка сети');
    }
}
