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

        if ((defined('YII_ENV') ? YII_ENV !== 'dev' : true) && empty($this->apiKey)) {
            throw new \yii\base\InvalidConfigException('OCR_API_KEY отсутствует.');
        }

        $this->http = new Client([
            'timeout'         => 45,
            'connect_timeout' => 10,
            'headers'         => [
                'User-Agent' => 'Tratometr/1.0 (+Railway)',
                // apikey лучше слать в заголовке — так рекомендует OCR.space
                'apikey'     => $this->apiKey ?? '',
            ],
            'curl'            => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
        ]);
    }

    /**
     * Базовый вызов OCR.space.
     * $lang — строка ("eng") ИЛИ массив языков ['eng','rus'] для автом. перебора.
     * $options — form-data флаги OCR.space.
     *
     * Возвращает «сырой» JSON как массив (последняя успешная попытка).
     * Бросает исключение, если все попытки провалились.
     */
    public function parseImage(string $filePath, string|array $lang = 'eng', array $options = []): array
    {
        // порядок перебора: eng → rus (если просили массив)
        $langsToTry = is_array($lang) ? array_values(array_unique($lang)) : [$lang];

        // сначала OCREngine=2, потом 1 (часто 1 лучше «дробит» мелкие символы)
        $engines = [];
        $engineFromOpts = $options['OCREngine'] ?? null;
        if ($engineFromOpts === 1 || $engineFromOpts === 2) {
            $engines = [(int)$engineFromOpts];
        } else {
            $engines = [2, 1];
        }

        // Значения по умолчанию
        $defaults = [
            'isOverlayRequired' => true,
            'scale'             => true,
            'detectOrientation' => true,
        ];

        $toString = static function ($v): string {
            if (is_bool($v)) return $v ? 'true' : 'false';
            return (string)$v;
        };

        $lastErr = null;

        foreach ($langsToTry as $langTry) {
            foreach ($engines as $engine) {
                $opts = array_merge($defaults, $options, ['OCREngine' => $engine]);

                $multipart = [
                    ['name' => 'language', 'contents' => $langTry],
                ];
                foreach ($opts as $k => $v) {
                    $multipart[] = ['name' => $k, 'contents' => $toString($v)];
                }
                $multipart[] = [
                    'name'     => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ];

                try {
                    $res = $this->http->post($this->endpoint, ['multipart' => $multipart]);
                    $json = json_decode((string)$res->getBody(), true) ?: [];

                    // Корректно разворачиваем ошибки OCR.space
                    if (!empty($json['IsErroredOnProcessing'])) {
                        $msg = $json['ErrorMessage'] ?? $json['ErrorDetails'] ?? 'OCR: ошибка обработки';
                        // некоторые сообщения приходят массивом
                        if (is_array($msg)) $msg = implode('; ', array_filter(array_map('strval', $msg)));
                        $lastErr = new \RuntimeException(sprintf(
                            'OCR [%s, engine=%d]: %s',
                            $langTry, $engine, $msg
                        ));
                        // пробуем следующий вариант
                        continue;
                    }

                    // успех
                    return $json;
                } catch (\GuzzleHttp\Exception\ConnectException | \GuzzleHttp\Exception\RequestException $e) {
                    $lastErr = $e;
                    // простая экспоненциальная задержка
                    usleep(500000);
                    continue;
                }
            }
        }

        throw $lastErr ?: new \RuntimeException('OCR: все попытки распознавания неудачны.');
    }
}
