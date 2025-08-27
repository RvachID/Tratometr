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
        $this->apiKey = $p['apiKey'] ?? null;

        if ((defined('YII_ENV') ? YII_ENV !== 'dev' : true) && empty($this->apiKey)) {
            throw new \yii\base\InvalidConfigException('OCR_API_KEY отсутствует.');
        }

        $this->http = new Client([
            'timeout' => 45,   // общий таймаут запроса
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

    public function parseImage(string $filePath, string $lang = 'rus', array $options = []): array
    {
        // Значения по умолчанию, как в старом фронте + улучшения точности
        $defaults = [
            'isOverlayRequired' => true,
            'scale' => true,
            'detectOrientation' => true,
            'OCREngine' => 2,   // у OCR.space обычно даёт лучший Overlay
            // при необходимости добавишь сюда другие поля OCR.space:
            // 'isTable' => false, 'isCreateSearchablePdf' => false, и т.п.
        ];
        $opts = array_merge($defaults, $options);

        // нормализуем значения под multipart (bool -> 'true'/'false')
        $toString = static function ($v): string {
            if (is_bool($v)) return $v ? 'true' : 'false';
            return (string)$v;
        };

        $multipart = [
            ['name' => 'language', 'contents' => $lang],
            // опции OCR
            ...array_map(
                fn($k) => ['name' => $k, 'contents' => $toString($opts[$k])],
                array_keys($opts)
            ),
            // сам файл
            ['name' => 'file', 'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
        ];

        if ($this->apiKey) {
            $multipart[] = ['name' => 'apikey', 'contents' => $this->apiKey];
        }

        $lastEx = null;
        // 3 попытки с экспоненциальным бэкоффом: 0.5с, 1с, 2с
        for ($i = 0; $i < 3; $i++) {
            try {
                $res = $this->http->post($this->endpoint, ['multipart' => $multipart]);
                return json_decode((string)$res->getBody(), true) ?: [];
            } catch (\GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\RequestException $e) {
                $lastEx = $e;
                usleep([500000, 1000000, 2000000][$i]);
            }
        }
        throw $lastEx ?: new \RuntimeException('OCR: неизвестная ошибка сети');
    }

}
