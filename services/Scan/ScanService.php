<?php

namespace app\services\Scan;

use app\components\OcrClient;
use Yii;
use yii\web\UploadedFile;

/**
 * Сервис распознавания цен по изображениям ценников/чеков.
 * Инкапсулирует весь pipeline, ранее располагавшийся в ScanController.
 */
class ScanService
{
    private const SIZE_LIMIT_BYTES = 1048576;

    private OcrClient $ocrClient;

    public function __construct(?OcrClient $ocrClient = null)
    {
        $this->ocrClient = $ocrClient ?? Yii::$app->ocr;
    }

    /**
     * Основной вход: принимает загруженный файл и возвращает результат распознавания.
     */
    public function recognize(UploadedFile $image): RecognizeResult
    {
        if (!$image) {
            return RecognizeResult::failure('Файл изображения не найден', 'no_file');
        }

        if (!in_array($image->type, ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'], true)) {
            return RecognizeResult::failure('Неподдерживаемый тип изображения', 'bad_mime');
        }

        $ext = strtolower($image->extension ?: 'jpg');

        $rawPath = Yii::getAlias('@runtime/' . uniqid('scan_raw_', true) . '.' . $ext);
        $procPath = null;

        try {
            if (!$image->saveAs($rawPath)) {
                return RecognizeResult::failure('Не удалось сохранить загруженный файл', 'save_failed');
            }
            $this->enforceSizeLimit($rawPath, self::SIZE_LIMIT_BYTES);

            $procPath = Yii::getAlias('@runtime/' . uniqid('scan_proc_', true) . '.' . $ext);
            if (!@copy($rawPath, $procPath)) {
                $procPath = null;
            }

            if ($procPath && !$this->preprocessImage($procPath)) {
                @unlink($procPath);
                $procPath = null;
            }
            if ($procPath) {
                $this->enforceSizeLimit($procPath, self::SIZE_LIMIT_BYTES);
            }

            $primary = $procPath ? $this->runRecognition($procPath) : ['error' => 'preprocess_failed', 'reason' => 'preprocess'];
            if (!empty($primary['amount'])) {
                return RecognizeResult::success(
                    (float)$primary['amount'],
                    (string)($primary['recognized']['ParsedText'] ?? ''),
                    'processed',
                    $primary
                );
            }

            if (@filesize($rawPath) > self::SIZE_LIMIT_BYTES) {
                return RecognizeResult::failure('Не удалось обработать изображение: превышен лимит размера', 'size_limit');
            }

            $fallback = $this->runRecognition($rawPath);
            if (!empty($fallback['amount'])) {
                return RecognizeResult::success(
                    (float)$fallback['amount'],
                    (string)($fallback['recognized']['ParsedText'] ?? ''),
                    'raw',
                    $fallback
                );
            }

            $reason = $primary['reason'] ?? $fallback['reason'] ?? 'empty';
            if ($reason === 'ocr') {
                return RecognizeResult::failure('Сбой OCR сервиса', 'ocr');
            }
            if ($reason === 'no_amount') {
                return RecognizeResult::failure('Не удалось извлечь сумму из изображения', 'no_amount');
            }
            return RecognizeResult::failure('Сервис не смог распознать цену', $reason, [
                'primary' => $primary,
                'fallback' => $fallback,
            ]);
        } catch (\Throwable $e) {
            Yii::error('ScanService recognize error: ' . $e->getMessage(), __METHOD__);
            return RecognizeResult::failure('Внутренняя ошибка распознавания', 'exception');
        } finally {
            if ($rawPath && is_file($rawPath)) {
                @unlink($rawPath);
            }
            if ($procPath && is_file($procPath)) {
                @unlink($procPath);
            }
        }
    }

    /**
     * Выполняет полный pipeline распознавания для указанного файла.
     * Возвращает массив с ключами:
     *  - amount (float)
     *  - recognized (array)
     *  - reason/error при неуспехе
     */
    private function runRecognition(string $path): array
    {
        try {
            $res = $this->ocrClient->extractPriceFromImage($path, ['eng', 'rus'], [
                'isOverlayRequired' => true,
                'OCREngine'         => 2,
                'scale'             => true,
                'detectOrientation' => true,
            ]);

            if (!empty($res['success']) && $res['success'] === true && !empty($res['amount'])) {
                return [
                    'amount'     => (float)$res['amount'],
                    'recognized' => [
                        'ParsedText' => (string)($res['text'] ?? ''),
                    ],
                ];
            }
        } catch (\Throwable $e) {
            Yii::warning('extractPriceFromImage failed: ' . $e->getMessage(), __METHOD__);
        }

        $recognized = $this->recognizeText($path);
        if (isset($recognized['error'])) {
            return ['error' => $recognized['error'], 'reason' => 'ocr', 'recognized' => $recognized];
        }

        $amount = $this->extractAmountByOverlay($recognized, $path);
        if ($amount !== null && $amount > 0.0) {
            return ['amount' => $amount, 'recognized' => $recognized];
        }

        $cleanText = $this->stripStrikethroughText($recognized, $recognized['ParsedText'] ?? '');
        $amount = $this->extractAmount($cleanText, false);
        if (!$amount) {
            return ['error' => 'no_amount', 'reason' => 'no_amount', 'recognized' => $recognized];
        }

        return ['amount' => $amount, 'recognized' => $recognized];
    }

    private function recognizeText(string $filePath): array
    {
        try {
            $apiResponse = $this->ocrClient->parseImage($filePath, ['eng', 'rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
            ]);

            if (!empty($apiResponse['IsErroredOnProcessing'])) {
                $msg = $apiResponse['ErrorMessage'] ?? $apiResponse['ErrorDetails'] ?? 'OCR: ошибка обработки';
                return ['error' => $msg, 'full_response' => $apiResponse];
            }

            $results = $apiResponse['ParsedResults'][0] ?? null;
            if (!$results) {
                return ['error' => 'Пустой ответ OCR', 'full_response' => $apiResponse];
            }

            return [
                'ParsedText'    => (string)($results['ParsedText'] ?? ''),
                'TextOverlay'   => $results['TextOverlay'] ?? ['Lines' => []],
                'full_response' => $apiResponse,
            ];
        } catch (\Throwable $e) {
            Yii::error('recognizeText failed: ' . $e->getMessage(), __METHOD__);
            return ['error' => 'Ошибка OCR: ' . $e->getMessage()];
        }
    }

    private function extractAmountByOverlay(array $recognized, string $imagePath): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines)) {
            return null;
        }

        $tokens = [];
        foreach ($lines as $ln) {
            foreach (($ln['Words'] ?? []) as $w) {
                $orig = (string)($w['WordText'] ?? '');
                $L = (int)($w['Left'] ?? 0);
                $T = (int)($w['Top'] ?? 0);
                $H = (int)($w['Height'] ?? 0);
                $W = (int)($w['Width'] ?? 0);
                if ($H <= 0 || $W <= 0 || !empty($w['IsStrikethrough'])) {
                    continue;
                }

                $tokens[] = [
                    'orig'   => $orig,
                    'text'   => preg_replace('~[^\d.,\s]~u', '', $orig),
                    'hasPct' => (strpos($orig, '%') !== false),
                    'L' => $L,
                    'T' => $T,
                    'H' => $H,
                    'W' => $W,
                    'R' => $L + $W,
                    'B' => $T + $H,
                ];
            }
        }

        if (!$tokens) {
            return null;
        }

        usort($tokens, function ($a, $b) {
            $dy = $a['T'] - $b['T'];
            if (abs($dy) > 8) {
                return $dy;
            }
            return $a['L'] <=> $b['L'];
        });

        $groups = [];
        $cur = [];
        $curTop = null;
        $curBaseH = 0;
        $isNumLike = fn($t) => $t !== '' && preg_match('~^[\d.,\s]+$~u', $t) && preg_match('~\d~', $t);

        $flush = function () use (&$cur, &$groups, &$curTop, &$curBaseH) {
            if (!$cur) {
                return;
            }

            $minL = min(array_column($cur, 'L'));
            $maxR = max(array_column($cur, 'R'));
            $minT = min(array_column($cur, 'T'));
            $maxB = max(array_column($cur, 'B'));
            $W = max(1, $maxR - $minL);
            $H = max(1, $maxB - $minT);

            $raw = implode('', array_map(fn($g) => preg_replace('~\s+~u', '', $g['text']), $cur));

            // Пытаемся распознать "слитые" копейки типа 9999 = 99.99
            $mergedVal = $this->parseMergedCents($raw);
            if ($mergedVal !== null) {
                $val = $mergedVal;
                $hasCents = true; // считаем, что копейки есть, чтобы не лезть в доп. OCR
            } else {
                $val = $this->normalizeOcrNumber($raw, false);
                $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $raw);
            }

            $digits   = preg_match_all('~\d~', $raw);
            $score = (float)($W * $H);
            $score *= (1.0 + min(1.0, $H / 48.0) * 0.35);
            if ($hasCents) {
                $score *= 1.35;
            }
            if ($digits <= 2) {
                $score *= 0.4;
            }
            if ($val !== null && $val < 1.0) {
                $score *= 0.3;
            }

            $groups[] = [
                'tokens'   => $cur,
                'val'      => $val,
                'raw'      => $raw,
                'bbox'     => ['L' => $minL, 'T' => $minT, 'W' => $W, 'H' => $H, 'R' => $maxR, 'B' => $maxB],
                'baseH'    => max(1, $curBaseH),
                'hasCents' => $hasCents,
                'score'    => $score,
            ];
            $cur = [];
            $curTop = null;
            $curBaseH = 0;
        };

        foreach ($tokens as $tk) {
            if (!$isNumLike($tk['text'])) {
                $flush();
                continue;
            }

            if (!$cur) {
                $cur = [$tk];
                $curTop = $tk['T'];
                $curBaseH = $tk['H'];
                continue;
            }

            $sameBaseline = abs($tk['T'] - $curTop) <= max(6, (int)round(0.35 * $curBaseH));
            $prev  = $cur[count($cur) - 1];
            $gapX  = $tk['L'] - $prev['R'];
            $nearX = $gapX <= max(8, (int)round(0.40 * $curBaseH));
            $heightOk = ($tk['H'] / max(1, $curBaseH)) >= 0.92;

            if ($sameBaseline && $nearX && $heightOk) {
                $cur[] = $tk;
                $curBaseH = max($curBaseH, $tk['H']);
            } else {
                $flush();
                $cur = [$tk];
                $curTop = $tk['T'];
                $curBaseH = $tk['H'];
            }
        }
        $flush();

        if (!$groups) {
            return null;
        }

        usort($groups, fn($a, $b) => $b['score'] <=> $a['score']);
        $main = null;
        foreach ($groups as $g) {
            if ($g['val'] !== null) {
                $main = $g;
                break;
            }
        }
        if (!$main) {
            return null;
        }

        if ($main['hasCents']) {
            return $main['val'];
        }

        $digitsInGroup = preg_match_all('~\d~', (string)$main['raw']);
        $refined = $this->refinePriceFromCrop($imagePath, $main['bbox'], $digitsInGroup);
        if ($refined !== null) {
            return $refined;
        }

        foreach ($main['tokens'] as $tk) {
            if (!empty($tk['hasPct'])) {
                return floor($main['val']) + 0.99;
            }
        }

        $bbox = [
            'left'   => $main['bbox']['L'],
            'top'    => $main['bbox']['T'],
            'width'  => $main['bbox']['W'],
            'height' => $main['bbox']['H'],
        ];

        $cents = $this->tryFindCentsViaRoi($imagePath, $bbox);
        if ($cents !== null && $main['val'] !== null) {
            return floor($main['val']) + ($cents / 100.0);
        }

        return $main['val'];
    }

    private function normalizeOcrNumber(string $s, bool $allowDiv100 = false): ?float
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // Уберём только пробелы для анализа формата
        $noSpaces = str_replace(' ', '', $s);

        // 1) Формат тысяч с точкой: 3.999 / 12.345 / 1.234.567
        if (preg_match('~^\d{1,3}(\.\d{3})+$~', $noSpaces)) {
            $digits = str_replace('.', '', $noSpaces);
            if ($digits === '') {
                return null;
            }
            // Это целая сумма в рублях, без копеек
            return (float) $digits;
        }

        // 2) Формат тысяч с пробелом: 1 299 / 12 345
        if (preg_match('~^\d{1,3}( \d{3})+$~', $s)) {
            $digits = str_replace(' ', '', $s);
            if ($digits === '') {
                return null;
            }
            return (float) $digits;
        }

        // 3) Обычный путь: десятичная точка/запятая
        $s = str_replace([' ', ','], ['', '.'], $s);
        if (!preg_match('~^\d+(?:\.\d+)?$~', $s)) {
            return null;
        }

        $value = (float)$s;

        // Старый хак "делим на 100 только при allowDiv100"
        if ($allowDiv100 && $value > 1000) {
            $value /= 100.0;
        }

        return round($value, 2);
    }

    private function extractAmount(string $text, bool $allowDiv100 = false): float
    {
        $candidates = [];

        // 0) Сначала — числа с разделителем тысяч (3.999, 12.345, 1 299)
        if (preg_match_all('~\b\d{1,3}(?:[.\s]\d{3})+\b~', $text, $mThousands)) {
            foreach ($mThousands[0] as $hit) {
                $val = $this->normalizeOcrNumber($hit, false);
                if ($val !== null) {
                    $candidates[] = $val;
                }
            }
        }

        // 1) Классический формат с копейками 29.99 / 29,99
        if (preg_match_all('~(\d+[.,]\d{2})~', $text, $m)) {
            foreach ($m[1] as $hit) {
                $val = $this->normalizeOcrNumber($hit, $allowDiv100);
                if ($val !== null) {
                    $candidates[] = $val;
                }
            }
        }

        if ($candidates) {
            rsort($candidates, SORT_NUMERIC);
            return (float)$candidates[0];
        }

        // 2) Сухие числа 2–4 цифры: тут включаем и "99⁹⁹" → 99.99
        if (preg_match_all('~\b(\d{2,4})\b~', $text, $m)) {
            foreach ($m[1] as $hit) {
                $digitsOnly = preg_replace('~\D~', '', $hit);

                // Хак "99⁹⁹ без точки" включаем только когда НЕ делим на 100
                if (!$allowDiv100) {
                    $merged = $this->parseMergedCents($digitsOnly);
                    if ($merged !== null) {
                        $candidates[] = $merged;
                        continue; // не добавляем это же число как целое
                    }
                }

                $n = (int)$digitsOnly;
                if ($n >= 2 && $n <= 1000) {
                    $candidates[] = $n;
                }
            }
        }

        if ($candidates) {
            rsort($candidates, SORT_NUMERIC);
            $best = (float)$candidates[0];
            if ($allowDiv100) {
                return round($best / 100.0, 2);
            }
            return $best;
        }

        return 0.0;
    }


    private function preprocessImage(string $filePath): bool
    {
        try {
            $im = new \Imagick($filePath);
            $im->autoOrient();
            $im->modulateImage(100, 120, 100);
            $im->unsharpMaskImage(0.5, 0.5, 1.0, 0.02);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(92);
            $im->writeImage($filePath);
            $im->clear();
            $im->destroy();
            return true;
        } catch (\Throwable $e) {
            Yii::warning('preprocessImage failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function enforceSizeLimit(string $path, int $bytes = self::SIZE_LIMIT_BYTES): bool
    {
        try {
            if (@filesize($path) <= $bytes) {
                return true;
            }

            $im = new \Imagick($path);
            $im->autoOrient();

            for ($i = 0; $i < 3; $i++) {
                $im->resizeImage((int)round($im->getImageWidth() * 0.85), 0, \Imagick::FILTER_LANCZOS, 1);
                $im->setImageCompressionQuality(max(60, 85 - $i * 10));
                $im->setImageFormat('jpeg');
                $im->writeImage($path);
                if (@filesize($path) <= $bytes) {
                    $im->clear();
                    $im->destroy();
                    return true;
                }
            }
            $im->clear();
            $im->destroy();
            return @filesize($path) <= $bytes;
        } catch (\Throwable $e) {
            Yii::warning('enforceSizeLimit fail: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    private function refinePriceFromCrop(string $imagePath, array $mainBbox, int $digitsInGroup = 0): ?float
    {
        try {
            $im = new \Imagick($imagePath);
            $im->autoOrient();
            $W = $im->getImageWidth();
            $H = $im->getImageHeight();

            $L  = (int)$mainBbox['L'];
            $T  = (int)$mainBbox['T'];
            $Wg = (int)$mainBbox['W'];
            $Hg = (int)$mainBbox['H'];

            $padL = max((int)round($Wg * 0.30), 28);
            $padT = max((int)round($Hg * 0.18), 12);
            $padR = max((int)round($Wg * 1.35), 60);
            $padB = max((int)round($Hg * 0.25), 14);

            $x = max(0, $L - $padL);
            $y = max(0, $T - $padT);
            $w = min($W - $x, $Wg + $padL + $padR);
            $h = min($H - $y, $Hg + $padT + $padB);
            if ($w < 24 || $h < 24) {
                return null;
            }

            $crop = clone $im;
            $crop->cropImage($w, $h, $x, $y);
            $crop->setImagePage(0, 0, 0, 0);

            $crop->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $crop->normalizeImage();
            $crop->modulateImage(100, 110, 100);
            $crop->unsharpMaskImage(0.6, 0.6, 1.2, 0.02);

            $crop->setImageFormat('jpeg');
            $crop->setImageCompressionQuality(92);
            $tmp = Yii::getAlias('@runtime/' . uniqid('price_roi_', true) . '.jpg');
            $crop->writeImage($tmp);
            $crop->clear();
            $crop->destroy();
            $im->clear();
            $im->destroy();

            $raw = $this->ocrClient->parseImage($tmp, ['eng', 'rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 2,
            ]);

            @unlink($tmp);

            $text = '';
            if (!empty($raw['ParsedResults'][0]['ParsedText'])) {
                $text = $raw['ParsedResults'][0]['ParsedText'];
            }

            $amount = $this->extractAmount($text, $digitsInGroup <= 3);
            if ($amount <= 0) {
                return null;
            }
            return $amount;
        } catch (\Throwable $e) {
            Yii::warning('refinePriceFromCrop failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    private function tryFindCentsViaRoi(string $imagePath, array $bbox): ?int
    {
        try {
            $im = new \Imagick($imagePath);
            $im->autoOrient();

            $roiW = max(20, (int)round($bbox['width'] * 0.60));
            $roiH = max(22, (int)round($bbox['height'] * 0.50));
            $roiX = min(max(0, (int)round($bbox['left'] + $bbox['width'] + ($bbox['width'] * 0.10))), $im->getImageWidth() - $roiW);
            $roiY = max(0, (int)round($bbox['top'] - ($bbox['height'] * 0.20)));
            if ($roiY + $roiH > $im->getImageHeight()) {
                $roiY = $im->getImageHeight() - $roiH;
            }

            if ($roiW < 20 || $roiH < 20) {
                $im->clear();
                $im->destroy();
                return null;
            }

            $crop = clone $im;
            $crop->cropImage($roiW, $roiH, $roiX, $roiY);
            $crop->setImagePage(0, 0, 0, 0);

            $crop->resizeImage($roiW * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $crop->normalizeImage();
            $crop->modulateImage(100, 125, 100);
            $crop->unsharpMaskImage(0.5, 0.5, 1.5, 0.05);

            $crop->setImageFormat('jpeg');
            $crop->setImageCompressionQuality(92);
            $tmp = Yii::getAlias('@runtime/' . uniqid('price_roi_cents_', true) . '.jpg');
            $crop->writeImage($tmp);
            $crop->clear();
            $crop->destroy();
            $im->clear();
            $im->destroy();

            $raw = $this->ocrClient->parseImage($tmp, ['eng', 'rus'], [
                'isOverlayRequired' => false,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 2,
            ]);

            @unlink($tmp);

            $text = '';
            if (!empty($raw['ParsedResults'][0]['ParsedText'])) {
                $text = $raw['ParsedResults'][0]['ParsedText'];
            }
            $text = preg_replace('~[^\d]~', '', (string)$text);
            if ($text === '' || strlen($text) > 2) {
                return null;
            }

            $cents = (int)$text;
            if ($cents >= 0 && $cents <= 99) {
                return $cents;
            }
            return null;
        } catch (\Throwable $e) {
            Yii::warning('tryFindCentsViaRoi failed: ' . $e->getMessage(), __METHOD__);
            return null;
        }
    }

    private function stripStrikethroughText(array $recognized, string $parsedText): string
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? [];
        if (!$lines) {
            return $parsedText;
        }

        $striked = [];
        foreach ($lines as $line) {
            foreach ($line['Words'] ?? [] as $word) {
                if (!empty($word['IsStrikethrough']) && !empty($word['WordText'])) {
                    $striked[] = $word['WordText'];
                }
            }
        }

        if (!$striked) {
            return $parsedText;
        }

        $clean = $parsedText;
        foreach ($striked as $token) {
            $token = preg_quote($token, '~');
            $clean = preg_replace('~' . $token . '~u', '', $clean);
        }

        return $clean;
    }

    private function parseMergedCents(string $raw): ?float
    {
        // Если уже есть десятичный разделитель — не трогаем
        // (чтобы не мешать "29.99" и похожим форматам).
        if (strpos($raw, '.') !== false || strpos($raw, ',') !== false) {
            return null;
        }

        $digits = preg_replace('~\D~', '', $raw);
        if (strlen($digits) !== 4) {
            return null;
        }

        // Берём только классический маркетинговый формат XX99
        if (substr($digits, -2) !== '99') {
            return null;
        }

        $rub = (int) substr($digits, 0, 2);
        $kop = (int) substr($digits, 2, 2);

        return $rub + $kop / 100.0; // рубли
    }


}
