<?php

namespace app\controllers;

use app\models\PriceEntry;
use app\models\PurchaseSession;
use Yii;
use yii\filters\RateLimiter;
use yii\web\Controller;
use yii\web\Response;

class ScanController extends Controller
{


    public $enableCsrfValidation = true;

    public function beforeAction($action)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        if ($action->id === 'recognize') {
            $this->enableCsrfValidation = false; // только для upload/recognize
        }

        if (Yii::$app->user->isGuest) {
            Yii::$app->response->statusCode = 401;
            Yii::$app->response->data = ['success' => false, 'error' => 'Не авторизован'];
            return false;
        }
        return parent::beforeAction($action);
    }


    public function behaviors()
    {
        $b = parent::behaviors();
        $b['rateLimiter'] = [
            'class' => RateLimiter::class,
            'enableRateLimitHeaders' => true,
            'only' => ['recognize'], // ограничиваем только upload
        ];
        return $b;
    }


    /**
     * Распознавание текста через OCR API
     */
    /**
     * Распознавание OCR.space с нужными флагами.
     * ВАЖНО: компонент \Yii::$app->ocr->parseImage должен уметь принимать 3-й аргумент (массив опций POST).
     */
    private function recognizeText(string $filePath): array
    {
        try {
            // ВАЖНО: теперь передаём массив языков ['eng','rus'],
            // а перебор по движкам делает сам клиент.
            $apiResponse = \Yii::$app->ocr->parseImage($filePath, ['eng','rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                // можно не указывать OCREngine — клиент сам попробует 2 → 1
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
            \Yii::error($e->getMessage(), __METHOD__);
            return ['error' => 'Сбой OCR: ' . $e->getMessage()];
        }
    }

    private function extractAmountByOverlay(array $recognized, string $imagePath): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines)) return null;

        // --- Собираем токены из Overlay
        $tokens = [];
        foreach ($lines as $ln) {
            foreach (($ln['Words'] ?? []) as $w) {
                $orig = (string)($w['WordText'] ?? '');
                $L = (int)($w['Left'] ?? 0);
                $T = (int)($w['Top'] ?? 0);
                $H = (int)($w['Height'] ?? 0);
                $W = (int)($w['Width'] ?? 0);
                if ($H <= 0 || $W <= 0 || !empty($w['IsStrikethrough'])) continue;

                $tokens[] = [
                    'orig'   => $orig,
                    'text'   => preg_replace('~[^\d.,\s]~u', '', $orig),
                    'hasPct' => (strpos($orig, '%') !== false),
                    'L' => $L, 'T' => $T, 'H' => $H, 'W' => $W, 'R' => $L + $W, 'B' => $T + $H,
                ];
            }
        }
        if (!$tokens) return null;

        // --- Сортировка «строкой»
        usort($tokens, function($a,$b){
            $dy = $a['T'] - $b['T'];
            if (abs($dy) > 8) return $dy;
            return $a['L'] <=> $b['L'];
        });

        // --- Группировка по строкам с антисклейкой «99»
        $groups = [];
        $cur = []; $curTop = null; $curBaseH = 0;
        $isNumLike = fn($t) => $t !== '' && preg_match('~^[\d.,\s]+$~u', $t) && preg_match('~\d~', $t);

        $flush = function() use (&$cur, &$groups, &$curTop, &$curBaseH) {
            if (!$cur) return;

            $minL = min(array_column($cur, 'L'));
            $maxR = max(array_column($cur, 'R'));
            $minT = min(array_column($cur, 'T'));
            $maxB = max(array_column($cur, 'B'));
            $W = max(1, $maxR - $minL);
            $H = max(1, $maxB - $minT);

            $raw = implode('', array_map(fn($g)=>preg_replace('~\s+~u','',$g['text']), $cur));
            $val = $this->normalizeOcrNumber($raw);

            $hasCents = (bool)preg_match('~[.,]\d{2}\b~', $raw);
            $digits   = preg_match_all('~\d~', $raw);

            $score = (float)($W*$H);
            $score *= (1.0 + min(1.0, $H / 48.0) * 0.35);
            if ($hasCents) $score *= 1.35;
            if ($digits <= 2) $score *= 0.4;
            if ($val !== null && $val < 1.0) $score *= 0.3;

            $groups[] = [
                'tokens'   => $cur,
                'val'      => $val,
                'raw'      => $raw,
                'bbox'     => ['L'=>$minL,'T'=>$minT,'W'=>$W,'H'=>$H,'R'=>$maxR,'B'=>$maxB],
                'baseH'    => max(1,$curBaseH),
                'hasCents' => $hasCents,
                'score'    => $score,
            ];
            $cur = []; $curTop = null; $curBaseH = 0;
        };

        foreach ($tokens as $tk) {
            if (!$isNumLike($tk['text'])) { $flush(); continue; }

            if (!$cur) { $cur = [$tk]; $curTop = $tk['T']; $curBaseH = $tk['H']; continue; }

            $sameBaseline = abs($tk['T'] - $curTop) <= max(6, (int)round(0.35 * $curBaseH));
            $prev  = $cur[count($cur)-1];
            $gapX  = $tk['L'] - $prev['R'];
            $nearX = $gapX <= max(8, (int)round(0.40 * $curBaseH));
            $heightOk = ($tk['H'] / max(1,$curBaseH)) >= 0.92;

            if ($sameBaseline && $nearX && $heightOk) {
                $cur[] = $tk;
                $curBaseH = max($curBaseH, $tk['H']);
            } else {
                $flush();
                $cur = [$tk]; $curTop = $tk['T']; $curBaseH = $tk['H'];
            }
        }
        $flush();

        if (!$groups) return null;

        // --- Выбираем «главную» группу
        usort($groups, fn($a,$b) => $b['score'] <=> $a['score']);
        $main = null;
        foreach ($groups as $g) { if ($g['val'] !== null) { $main = $g; break; } }
        if (!$main) return null;

        // Если внутри уже есть копейки — готово
        if ($main['hasCents']) return $main['val'];

        // --- НОВОЕ: кропаем область цены и перепроверяем «X.XX» только по кропу
        $refined = $this->refinePriceFromCrop($imagePath, $main['bbox']);
        if ($refined !== null) return $refined;

        // «307%» как артефакт верстки — считаем .99
        foreach ($main['tokens'] as $tk) {
            if (!empty($tk['hasPct'])) {
                return floor($main['val']) + 0.99;
            }
        }

        // ROI: отдельный хвостик «копеек»
        $bbox = [
            'left'   => $main['bbox']['L'],
            'top'    => $main['bbox']['T'],
            'width'  => $main['bbox']['W'],
            'height' => $main['bbox']['H'],
        ];
        $cents = $this->tryFindCentsViaRoi($imagePath, $bbox);
        if ($cents !== null) {
            return floor($main['val']) + min(99, max(0, (int)$cents))/100.0;
        }

        // Не нашли копейки — отдаём целую часть
        return $main['val'];
    }



    /**
     * Пробуем вычитать копейки из маленького «хвоста» справа от основной цены.
     * Возвращает 0..99 или null.
     */
    private function tryFindCentsViaRoi(string $imagePath, array $bbox): ?int
    {
        try {
            $im = new \Imagick($imagePath);
            $im->autoOrient();

            $W = $im->getImageWidth();
            $H = $im->getImageHeight();

            $L  = (int)$bbox['left'];
            $T  = (int)$bbox['top'];
            $Wg = (int)$bbox['width'];
            $Hg = (int)$bbox['height'];

            // ROI справа-вверх от большой цены
            $x = (int)round($L + $Wg * 1.02);
            $y = (int)round($T - $Hg * 0.25);
            $w = (int)round(max($Wg * 0.60, 40));
            $h = (int)round(max($Hg * 0.90, 32));

            $x = max(0, min($x, $W - 1));
            $y = max(0, min($y, $H - 1));
            if ($x + $w > $W) $w = $W - $x;
            if ($y + $h > $H) $h = $H - $y;
            if ($w < 16 || $h < 16) return null;

            $roi = clone $im;
            $roi->cropImage($w, $h, $x, $y);
            $roi->setImagePage(0,0,0,0);

            // Усиление для мелкого текста (только в ROI!)
            $roi->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $roi->modulateImage(100, 120, 100);   // немного насыщенности
            $roi->normalizeImage();                // авто-нормализация уровней
            $roi->adaptiveSharpenImage(1, 0.8);    // аккуратная резкость
            $roi->setImageFormat('jpeg');
            $roi->setImageCompressionQuality(92);

            $tmp = \Yii::getAlias('@runtime/' . uniqid('roi_', true) . '.jpg');
            $roi->writeImage($tmp);
            $roi->clear(); $roi->destroy();
            $im->clear();  $im->destroy();

            // Второй проход по ROI: движок 1, ENG
            $raw = \Yii::$app->ocr->parseImage($tmp, 'eng', [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 1,
            ]);
            @unlink($tmp);

            $res  = $raw['ParsedResults'][0] ?? [];
            $text = (string)($res['ParsedText'] ?? '');

            if (strpos($text, '%') !== false) return 99;

            if (preg_match('/\b(\d{2})\b/u', $text, $m)) {
                $c = (int)$m[1];
                if ($c >= 0 && $c <= 99) return $c;
            }

            $best = null;
            if (!empty($res['TextOverlay']['Lines'])) {
                foreach ($res['TextOverlay']['Lines'] as $ln) {
                    foreach (($ln['Words'] ?? []) as $w) {
                        $t = preg_replace('~\D+~u', '', (string)($w['WordText'] ?? ''));
                        if ($t === '') continue;
                        if (strlen($t) <= 2) {
                            $c = (int)$t;
                            if ($c >= 0 && $c <= 99) {
                                if (strlen($t) === 2) return $c;
                                $best = $c; // одна цифра → ~90
                            }
                        }
                    }
                }
            }
            if ($best !== null) return $best * 10;

            return null;
        } catch (\Throwable $e) {
            \Yii::warning('ROI cents OCR failed: '.$e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Нормализуем «числовое» слово из OCR в float.
     * Чиним: '449 99' / '449,99' / '449·99' / '1 299,90' / '44999' → 449.99.
     * Отсекаем проценты/дроби, мусор и нереалистичные значения.
     */
    private function normalizeOcrNumber(string $s): ?float
    {
        $s = trim($s);
        if ($s === '' || preg_match('/[%\/]/u', $s)) return null; // проценты/дроби отбрасываем

        // разные пробелы → обычный
        $s = str_replace(["\xC2\xA0", ' ', ' '], ' ', $s);

        // помечаем разделитель копеек между цифрами (ровно 2 цифры в конце «слова»)
        $s = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $s);

        // убираем тысячные разделители (пробел/точка/цент.точка перед 3 цифрами)
        $s = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $s);

        // чистим любые оставшиеся пробелы между цифрами
        $s = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $s);

        // --- ВАЖНО: сначала кейс «слилось в 4–6 цифр» → считаем, что пропал разделитель копеек
        if (preg_match('/^\d{4,6}$/', $s)) {
            $v = ((int)$s) / 100.0;                 // 30799 → 307.99
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        // финальный разделитель копеек — точка
        $s = str_replace('#', '.', $s);

        // Явно валидное число: целое или с копейками
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) {
            $v = (float)$s;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        // просто целое: допустим
        if (preg_match('/^\d+$/', $s)) {
            $v = (float)$s;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        return null;
    }


    /**
     * Умный разбор суммы из распознанного текста.
     * Правит '449 99' / '449,99' → '449.99', убирает тысячные разделители,
     * пытается восстановить копейки из 4–6-значных целых (44999 → 449.99).
     */
    /**
     * Умный разбор суммы из распознанного текста.
     * Добавлено: паттерн "307%" трактуем как 307.99 (с приоритетом),
     * а для 4–6-значных слепленных чисел предпочитаем вариант /100 (3079 -> 30.79, 30799 -> 307.99).
     */
    private function extractAmount(string $text): float
    {
        // Кейс "307%" → 307.99
        if (preg_match_all('/\b(\d{3,6})\s*%/u', $text, $mp)) {
            foreach ($mp[1] as $s) {
                $n = (int)$s;
                if ($n >= 100 && $n <= 999999) return floor($n) + 0.99;
            }
        }

        // Нормализация
        $text = str_replace(["\xC2\xA0", ' ', '﻿'], ' ', $text);
        $tmp  = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $text);
        $tmp  = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $tmp);
        $tmp  = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $tmp);
        $normalized = str_replace('#', '.', $tmp);

        // (1) Явные десятичные — но выбираем по score, чтобы не брать "99.45"
        if (preg_match_all('/\d+(?:\.\d{1,2})/', $normalized, $m1)) {
            $best = 0.0; $bestScore = 0.0;
            foreach ($m1[0] as $s) {
                $v = (float)$s;
                if ($v <= 0.0 || $v > 9999999) continue;

                $frac  = (int)round(($v - floor($v)) * 100);
                $score = 1.0;

                // Частые дроби цен: 99/95/90/89 — бонус
                if (in_array($frac, [99,95,90,89], true)) $score *= 1.25;

                // Если в тексте вообще встречаются 3+ значные числа и текущая целая часть < 100 — штраф (перевёртыш)
                if ($v < 100 && preg_match('~\b\d{3,}\b~', $normalized)) $score *= 0.6;

                // Рядом встречается валюта — небольшой бонус
                if (preg_match('~RSD|DIN|КОМ~ui', $text)) $score *= 1.05;

                if ($score > $bestScore || ($score === $bestScore && $v > $best)) {
                    $best = $v; $bestScore = $score;
                }
            }
            if ($best > 0) return $best;
        }

        // (2) «целое + 2 цифры» без явной точки/запятой
        if (preg_match_all('/\b(\d{1,5})\b(?:\s{0,3}[.,]?)\s*(\d{2})\b/u', $text, $m2)) {
            $best = 0.0;
            foreach ($m2[1] as $i => $int) {
                $cent = $m2[2][$i];
                $v = (int)$int + ((int)$cent)/100.0;
                if ($v > 0.0 && $v <= 9999999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        // (3) Делим 4–6 цифр на 100 ТОЛЬКО если нет «целое+две цифры» рядом
        if (!preg_match('/\b\d{1,5}\D{0,3}\d{2}\b/u', $normalized)) {
            if (preg_match_all('/\b(\d{4,6})\b/u', $normalized, $m3)) {
                $best = 0.0;
                foreach ($m3[1] as $raw) {
                    $v = ((int)$raw) / 100.0;
                    if ($v > 0.0 && $v <= 99999 && $v > $best) $best = $v;
                }
                if ($best > 0) return $best;
            }
        }

        // (4) Последний шанс — максимальное целое разумного размера
        if (preg_match_all('/\b(\d{1,5})\b/u', $normalized, $m4)) {
            $best = 0.0;
            foreach ($m4[1] as $raw) {
                $v = (float)$raw;
                if ($v > 0.0 && $v <= 99999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        return 0.0;
    }


    /**
     * Предобработка изображения: ресайз, ч/б, контраст
     */
    /**
     * Мягкая и стабильная предобработка:
     * - сохраняем цвет;
     * - только resize + лёгкая резкость;
     * - без контраста, без GRAY, без кропа.
     */
    private function preprocessImage(string $filePath): bool
    {
        Yii::info('Обработка изображения (safe, keep-color) начата', __METHOD__);
        try {
            $im = new \Imagick($filePath);
            $im->autoOrient(); // если есть EXIF

            // OCR обычно лучше на 1000–1600 px по ширине
            $w = $im->getImageWidth();
            if ($w > 1280) {
                $im->resizeImage(1280, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            // Очень мягкая резкость без «перешарпа»
            $im->unsharpMaskImage(0.5, 0.5, 0.8, 0.01);

            // JPEG качество
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(85);

            $ok = $im->writeImage($filePath);
            $im->clear();
            $im->destroy();

            Yii::info('Safe-обработка завершена', __METHOD__);
            return $ok;
        } catch (\Throwable $e) {
            Yii::error('Ошибка обработки изображения: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    public function actionRecognize()
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            $image = \yii\web\UploadedFile::getInstanceByName('image');
            if (!$image) {
                return ['success' => false, 'error' => 'Изображение не загружено'];
            }

            $ext = strtolower($image->extension ?: 'jpg');
            $sizeLimit = 1024 * 1024; // лимит OCR.space

            // сохраняем сырой
            $rawPath = \Yii::getAlias('@runtime/' . uniqid('scan_raw_') . '.' . $ext);
            if (!$image->saveAs($rawPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }

            // копия под soft-предобработку
            $procPath = \Yii::getAlias('@runtime/' . uniqid('scan_proc_') . '.' . $ext);
            @copy($rawPath, $procPath);
            if (!$this->preprocessImage($procPath)) {
                @unlink($procPath);
                $procPath = null;
            }

            // контроль размера
            if ($procPath && @filesize($procPath) > $sizeLimit) {
                @unlink($procPath);
                $procPath = null;
            }
            if (!$procPath && @filesize($rawPath) > $sizeLimit) {
                @unlink($rawPath);
                return ['success' => false, 'error' => 'Размер файла превышает 1 МБ'];
            }

            $run = function (string $path) {
                try {
                    /** @var \app\components\OcrClient $ocr */
                    $ocr = \Yii::$app->ocr;

                    $res = $ocr->extractPriceFromImage($path, 'eng', [
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
                    \Yii::warning('extractPriceFromImage failed: ' . $e->getMessage(), __METHOD__);
                }

                // Фолбэк: OCR → Overlay → (bbox-скоринг/ROI) → строковый парсер
                $recognized = $this->recognizeText($path);
                if (isset($recognized['error'])) {
                    return ['error' => $recognized['error'], 'reason' => 'ocr', 'recognized' => $recognized];
                }

                // Новый вызов: Overlay/ROI
                $amount = $this->extractAmountByOverlay($recognized, $path);

                if ($amount !== null && $amount > 0.0) {
                    return ['amount' => $amount, 'recognized' => $recognized];
                }

                // Крайний случай — строковый парсер
                $amount = $this->extractAmount($recognized['ParsedText'] ?? '');
                if (!$amount) {
                    return ['error' => 'no_amount', 'reason' => 'no_amount', 'recognized' => $recognized];
                }

                return ['amount' => $amount, 'recognized' => $recognized];
            };

            // проход 1: обработанное
            $usedPass = 'processed';
            $r1 = $procPath ? $run($procPath) : ['error' => 'preprocess_failed', 'reason' => 'preprocess'];

            // если не нашли — проход 2: сырое
            if (empty($r1['amount'])) {
                $usedPass = 'raw';
                if (@filesize($rawPath) > $sizeLimit) {
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);
                    return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                }

                $r2 = $run($rawPath);
                if (empty($r2['amount'])) {
                    @unlink($rawPath);
                    if ($procPath) @unlink($procPath);

                    if (($r1['reason'] ?? '') === 'ocr' || ($r2['reason'] ?? '') === 'ocr') {
                        return ['success' => false, 'error' => 'Ошибка OCR', 'reason' => 'ocr'];
                    }
                    if (($r1['reason'] ?? '') === 'no_amount' || ($r2['reason'] ?? '') === 'no_amount') {
                        return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                    }
                    return ['success' => false, 'error' => 'Текст не распознан', 'reason' => 'empty'];
                }

                @unlink($rawPath);
                if ($procPath) @unlink($procPath);
                return [
                    'success' => true,
                    'recognized_amount' => $r2['amount'],
                    'parsed_text' => $r2['recognized']['ParsedText'] ?? '',
                    'pass' => $usedPass,
                ];
            }

            @unlink($rawPath);
            if ($procPath) @unlink($procPath);
            return [
                'success' => true,
                'recognized_amount' => $r1['amount'],
                'parsed_text' => $r1['recognized']['ParsedText'] ?? '',
                'pass' => $usedPass,
            ];

        } catch (\Throwable $e) {
            \Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    public function actionStore()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            if (Yii::$app->user->isGuest) {
                return ['success' => false, 'error' => 'Требуется вход'];
            }

            // Активная серверная сессия (без методов другого контроллера)
            $ps = PurchaseSession::find()
                ->where(['user_id' => Yii::$app->user->id, 'status' => PurchaseSession::STATUS_ACTIVE])
                ->orderBy(['updated_at' => SORT_DESC])
                ->limit(1)->one();

            if (!$ps) {
                return ['success' => false, 'error' => 'Нет активной покупки. Начните или возобновите сессию.'];
            }

            $amount = Yii::$app->request->post('amount');
            $qty = Yii::$app->request->post('qty', 1);
            $note = (string)Yii::$app->request->post('note', '');
            $text = (string)Yii::$app->request->post('parsed_text', '');

            if (!is_numeric($amount) || (float)$amount <= 0) return ['success' => false, 'error' => 'Неверная сумма'];
            if (!is_numeric($qty) || (float)$qty <= 0) $qty = 1;

            $entry = new PriceEntry();
            $entry->user_id = Yii::$app->user->id;
            $entry->session_id = $ps->id;                // ВАЖНО
            $entry->amount = (float)$amount;
            $entry->qty = (float)$qty;
            $entry->store = $ps->shop;              // из сессии
            $entry->category = $ps->category ?: null;  // из сессии
            $entry->note = $note;
            $entry->recognized_text = $text;
            $entry->recognized_amount = (float)$amount;
            $entry->source = 'price_tag';
            $entry->created_at = time();
            $entry->updated_at = time();

            if (!$entry->save(false)) {
                return ['success' => false, 'error' => 'Ошибка сохранения'];
            }

            $ps->updateAttributes(['updated_at' => time()]);

            $total = (float)PriceEntry::find()
                ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
                ->sum('amount * qty');

            return [
                'success' => true,
                'entry' => [
                    'id' => $entry->id,
                    'amount' => $entry->amount,
                    'qty' => $entry->qty,
                    'note' => (string)$entry->note,
                    'store' => (string)$entry->store,
                    'category' => $entry->category,
                ],
                'total' => $total,
            ];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    /** Автосохранение суммы/qty из строки списка */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->load(Yii::$app->request->post(), '');
        // фиксируем принадлежность к текущей серверной сессии
        $m->user_id = Yii::$app->user->id;
        $m->session_id = $ps->id;
        $m->store = $ps->shop;
        $m->category = $ps->category ?: null;
        $m->updated_at = time();

        if (!$m->save(false)) {
            return ['success' => false, 'error' => 'Не удалось сохранить'];
        }

        Yii::$app->ps->touch($ps);

        $total = (float)PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return ['success' => true, 'total' => number_format($total, 2, '.', '')];
    }

    /** Удаление строки из списка */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne(['id' => (int)$id, 'user_id' => Yii::$app->user->id, 'session_id' => $ps->id]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->delete();

        $total = (float)PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return ['success' => true, 'total' => number_format($total, 2, '.', '')];
    }

    /**
     * Делает кроп вокруг основной цены (с захватом копеек справа-сверху),
     * локально усиливает контраст и повторно запускает OCR только на этом фрагменте.
     * Возвращает число X.XX если удалось извлечь; иначе null.
     */
    private function refinePriceFromCrop(string $imagePath, array $mainBbox): ?float
    {
        try {
            $im = new \Imagick($imagePath);
            $im->autoOrient();

            $W = $im->getImageWidth();
            $H = $im->getImageHeight();

            $L = (int)$mainBbox['L'];
            $T = (int)$mainBbox['T'];
            $Wg= (int)$mainBbox['W'];
            $Hg= (int)$mainBbox['H'];

            // --- Расширяем окно: берём всю основную цену + область копеек справа-сверху
            // слева/сверху небольшой отступ, вправо сильно шире, вниз чуть-чуть
            $padL = (int)round($Wg * 0.08);
            $padT = (int)round($Hg * 0.15);
            $padR = (int)round($Wg * 1.20);   // главное — захватить «99»
            $padB = (int)round($Hg * 0.25);

            $x = max(0, $L - $padL);
            $y = max(0, $T - $padT);
            $w = min($W - $x, $Wg + $padL + $padR);
            $h = min($H - $y, $Hg + $padT + $padB);

            if ($w < 24 || $h < 24) return null;

            $crop = clone $im;
            $crop->cropImage($w, $h, $x, $y);
            $crop->setImagePage(0,0,0,0);

            // --- Локальная обработка только для кропа
            // 1) апскейл для OCR
            $crop->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            // 2) легкая нормализация уровней/контраст, не «пережечь»
            $crop->normalizeImage();
            $crop->modulateImage(100, 110, 100);    // +немного насыщенности
            $crop->unsharpMaskImage(0.6, 0.6, 1.2, 0.02);
            // 3) мягкая бинаризация (улучшает мелкие "99")
            $crop->setImageColorspace(\Imagick::COLORSPACE_GRAY);
            $crop->adaptiveThresholdImage(255, 25, 25); // win-параметры под мелкий шрифт
            // сохраняем во временный файл
            $crop->setImageFormat('jpeg');
            $crop->setImageCompressionQuality(92);

            $tmp = \Yii::getAlias('@runtime/' . uniqid('price_roi_', true) . '.jpg');
            $crop->writeImage($tmp);
            $crop->clear(); $crop->destroy();
            $im->clear();   $im->destroy();

            // --- Второй OCR только по кропу
            $raw = \Yii::$app->ocr->parseImage($tmp, ['eng','rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 2, // сначала 2, внутри клиента упадёт на 1 при надобности
            ]);
            @unlink($tmp);

            $res  = $raw['ParsedResults'][0] ?? [];
            $text = (string)($res['ParsedText'] ?? '');

            // Прямые X.XX — приоритет
            if (preg_match_all('/\b\d+(?:[.,]\d{2})\b/u', $text, $m)) {
                $best = 0.0;
                foreach ($m[0] as $s) {
                    $v = (float)str_replace(',', '.', $s);
                    if ($v > $best && $v < 100000) $best = $v;
                }
                if ($best > 0) return $best;
            }

            // «целое + маленькие 2 цифры» с пробелом/шумом
            if (preg_match('/\b(\d{1,5})\D{0,3}(\d{2})\b/u', $text, $m2)) {
                $v = (int)$m2[1] + ((int)$m2[2])/100.0;
                if ($v > 0 && $v < 100000) return $v;
            }

            // Слитно 4–6 цифр (типа 30799) — делим на 100
            if (preg_match('/\b(\d{4,6})\b/u', $text, $m3)) {
                $v = ((int)$m3[1]) / 100.0;
                if ($v > 0 && $v < 100000) return $v;
            }

            return null;
        } catch (\Throwable $e) {
            \Yii::warning('refinePriceFromCrop failed: '.$e->getMessage(), __METHOD__);
            return null;
        }
    }

}
