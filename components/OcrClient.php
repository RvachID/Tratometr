<?php
namespace app\components;

use GuzzleHttp\Client;

class OcrClient
{
    private Client $http;
    private string $endpoint;
    private ?string $apiKey;
    /** @var array настройки якорей парсера из params['ocr']['parser'] (опционально) */
    private array $parserOpts = [];

    public function __construct()
    {
        $p = \Yii::$app->params['ocr'] ?? [];
        $this->endpoint   = $p['endpoint'] ?? 'https://api.ocr.space/parse/image';
        $this->apiKey     = $p['apiKey']   ?? null;
        $this->parserOpts = $p['parser']   ?? [];   // можно не задавать

        if ((defined('YII_ENV') ? YII_ENV !== 'dev' : true) && empty($this->apiKey)) {
            throw new \yii\base\InvalidConfigException('OCR_API_KEY отсутствует.');
        }

        $this->http = new Client([
            'timeout'         => 45,
            'connect_timeout' => 10,
            'headers'         => ['User-Agent' => 'Tratometr/1.0 (+Railway)'],
            'curl'            => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
        ]);
    }

    /** Базовый вызов OCR.space — оставляем для совместимости */
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
        if ($this->apiKey) $multipart[] = ['name' => 'apikey', 'contents' => $this->apiKey];

        $lastEx = null;
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

    /**
     * Полный цикл "фото → текст → цена".
     * @param array|null $parserOpts если null — возьмём из $this->parserOpts (params.php)
     * @return array {success:bool, amount:?float, text:string, raw:array}
     */
    public function extractPriceFromImage(
        string $filePath,
        string $lang = 'rus',
        array $options = [],
        ?array $parserOpts = null
    ): array {
        $raw = $this->parseImage($filePath, $lang, $options);

        // 1) ParsedText
        $parsedText = '';
        if (!empty($raw['ParsedResults'][0]['ParsedText'])) {
            $parsedText = (string)$raw['ParsedResults'][0]['ParsedText'];
        }

        // 2) TextOverlay -> собрать построчно (часто лучше сегментация)
        $overlayText = '';
        if (!empty($raw['ParsedResults'][0]['TextOverlay']['Lines']) && is_array($raw['ParsedResults'][0]['TextOverlay']['Lines'])) {
            $lines = [];
            foreach ($raw['ParsedResults'][0]['TextOverlay']['Lines'] as $line) {
                if (empty($line['Words']) || !is_array($line['Words'])) continue;
                $words = [];
                foreach ($line['Words'] as $w) {
                    $t = trim((string)($w['WordText'] ?? ''));
                    if ($t !== '') $words[] = $t;
                }
                if ($words) $lines[] = implode(' ', $words);
            }
            $overlayText = implode("\n", $lines);
        }

        // 3) Объединяем источники для парсинга
        $text = trim($parsedText . "\n" . $overlayText);

        // 4) Парсим цену (опции из аргумента или из params)
        $opts = $parserOpts ?? $this->parserOpts;
        $amount = \app\components\PriceParser::parse($text, $opts);

        return [
            'success' => $amount !== null,
            'amount'  => $amount,
            'text'    => $text,
            'raw'     => $raw,
        ];
    }
}
