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
            $apiResponse = \Yii::$app->ocr->parseImage($filePath, 'rus', [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 2,
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
                'ParsedText'  => (string)($results['ParsedText'] ?? ''),
                'TextOverlay' => $results['TextOverlay'] ?? ['Lines' => []],
                'full_response' => $apiResponse,
            ];
        } catch (\Throwable $e) {
            \Yii::error($e->getMessage(), __METHOD__);
            return ['error' => 'Сбой OCR: ' . $e->getMessage()];
        }
    }


    /**
     * Берём число из OCR Overlay по наибольшей площади.
     * Возвращает float или null, если в Overlay ничего подходящего.
     */
    /**
     * Берём цену из OCR Overlay по максимальной ПЛОЩАДИ bbox группы числовых токенов.
     * Учитываем бонусы за копейки, штрафуем короткие/«копеечные» значения.
     */
    /**
     * Достаём цену из Overlay по ПЛОЩАДИ bbox ГРУППЫ числовых токенов.
     * Фильтруем перечёркнутые и проценты. Бонусы за копейки.
     */

    /**
     * Главная цена = числовая группа с максимальной площадью bbox.
     * Копейки = маленький токен (1–2 цифры или «%») справа-сверху от этой группы.
     * Никакого деления на 100 — только если реально нашли «хвост».
     */
    /**
     * Главная цена = группа числовых токенов с максимальной площадью bbox.
     * Копейки = отдельный мелкий токен справа-сверху от этой группы.
     * ВАЖНО: токены, у которых высота заметно ниже основной строки, в группу не склеиваем.
     */
    private function extractAmountByOverlay(array $recognized): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines)) return null;

        // Собираем токены
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

        // Сортируем слева-направо, сверху-вниз
        usort($tokens, function($a,$b){
            $dy = $a['T'] - $b['T'];
            if (abs($dy) > 8) return $dy;
            return $a['L'] <=> $b['L'];
        });

        // --- ГРУППИРОВКА ЧИСЛОВЫХ ТОКЕНОВ ---
        // Ключевое изменение: НЕ склеиваем токен в группу, если его высота < 88% базовой.
        $groups = [];
        $cur = [];
        $curTop = null; $curBaseH = 0;

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
            $digits = preg_match_all('~\d~', $raw);

            $score = (float)($W*$H);
            if ($hasCents) $score *= 1.35;
            if ($digits >= 3) $score *= 1.12;
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

            if (!$cur) {
                $cur = [$tk]; $curTop = $tk['T']; $curBaseH = $tk['H'];
                continue;
            }

            $sameBaseline = abs($tk['T'] - $curTop) <= max(6, (int)round(0.35 * $curBaseH));
            $prev = $cur[count($cur)-1];
            $gapX = $tk['L'] - $prev['R'];
            $nearX = $gapX <= max(10, (int)round(0.5 * $curBaseH));

            // НОВОЕ: не присоединяем "мелкий" токен в основную группу
            $ratioH = $tk['H'] / max(1,$curBaseH);
            $heightOk = ($ratioH >= 0.88); // 88% и выше считаем одним шрифтом/строкой

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

        // Выбираем лучшую группу (по score) с валидным числом
        usort($groups, fn($a,$b) => $b['score'] <=> $a['score']);
        $main = null;
        foreach ($groups as $g) { if ($g['val'] !== null) { $main = $g; break; } }
        if (!$main) return null;

        // Если у группы уже есть копейки — возвращаем
        if ($main['hasCents']) return $main['val'];

        // --- Ищем "мелкий хвост" справа-сверху ---
        $bbox = $main['bbox']; $baseH = $main['baseH'];
        $R = $bbox['R']; $T = $bbox['T']; $H = $bbox['H']; $W = $bbox['W'];

        $zoneL = $R - (int)round(0.05 * $W);
        $zoneR = $R + (int)round(0.90 * $W);
        $zoneT = $T - (int)round(0.60 * $H);
        $zoneB = $T + (int)round(0.35 * $H);

        $bestCents = null; $bestRank = -INF;
        foreach ($tokens as $tk) {
            // короткие цифровые 1–2 символа или токен с '%'
            $digits = preg_replace('~\D+~u', '', $tk['orig']);
            $len = strlen($digits);
            $isShort = ($len >= 1 && $len <= 2);
            $isPct = $tk['hasPct'];

            if (!$isShort && !$isPct) continue;
            if ($tk['L'] < $zoneL || $tk['L'] > $zoneR) continue;
            if ($tk['T'] < $zoneT || $tk['T'] > $zoneB) continue;

            // должен быть существенно меньше основной высоты
            $ratio = $tk['H'] / max(1,$baseH);
            if ($ratio > 0.83) continue;

            $cents = null;
            if ($isPct)        $cents = 99;
            elseif ($len === 2)$cents = (int)$digits;
            else               $cents = (int)$digits * 10;

            if ($cents >= 0 && $cents <= 99) {
                $rank = (0.8 - $ratio) * 100;
                $rank -= abs(($tk['T'] - $T)) / max(1,$H);
                $rank -= abs(($tk['L'] - $R)) / max(1,$W);
                if ($isPct) $rank += 40;
                if ($len === 2) $rank += 10;
                if ($rank > $bestRank) { $bestRank = $rank; $bestCents = $cents; }
            }
        }

        if ($bestCents !== null) {
            return floor($main['val']) + ($bestCents / 100.0);
        }

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

            $L = (int)$bbox['left'];
            $T = (int)$bbox['top'];
            $Wg= (int)$bbox['width'];
            $Hg= (int)$bbox['height'];

            // ROI: справа-вверх от основной группы (как на ценниках «мелкие копейки»)
            $x = (int)round($L + $Wg * 1.02);
            $y = (int)round($T - $Hg * 0.25);
            $w = (int)round(max($Wg * 0.60, 40));
            $h = (int)round(max($Hg * 0.90, 32));

            // границы
            $x = max(0, min($x, $W - 1));
            $y = max(0, min($y, $H - 1));
            if ($x + $w > $W) $w = $W - $x;
            if ($y + $h > $H) $h = $H - $y;
            if ($w < 16 || $h < 16) return null;

            // crop + upscale + лёгкая резкость (без агрессивного контраста)
            $roi = clone $im;
            $roi->cropImage($w, $h, $x, $y);
            $roi->setImagePage(0,0,0,0);
            $roi->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $roi->unsharpMaskImage(0.5, 0.5, 1.2, 0.02);
            $roi->setImageFormat('jpeg');
            $roi->setImageCompressionQuality(92);

            $tmp = \Yii::getAlias('@runtime/' . uniqid('roi_', true) . '.jpg');
            $roi->writeImage($tmp);
            $roi->clear(); $roi->destroy();
            $im->clear();  $im->destroy();

            // Второй OCR-проход по ROI — движок 1, он чаще «дробит» мелкие цифры
            $raw = \Yii::$app->ocr->parseImage($tmp, 'eng', [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 1,
            ]);
            @unlink($tmp);

            $res  = $raw['ParsedResults'][0] ?? [];
            $text = (string)($res['ParsedText'] ?? '');

            // Явный «%» возле копеек — трактуем как 99
            if (strpos($text, '%') !== false) return 99;

            // Сначала ищем строго две цифры как отдельное «слово»
            if (preg_match('/\b(\d{2})\b/u', $text, $m)) {
                $c = (int)$m[1];
                if ($c >= 0 && $c <= 99) return $c;
            }

            // Если Overlay есть — выбираем самый «цифровой» токен длиной 1–2
            $best = null;
            if (!empty($res['TextOverlay']['Lines'])) {
                foreach ($res['TextOverlay']['Lines'] as $ln) {
                    foreach (($ln['Words'] ?? []) as $w) {
                        $t = preg_replace('~\D+~u', '', (string)($w['WordText'] ?? ''));
                        if ($t === '') continue;
                        if (strlen($t) <= 2) {
                            $c = (int)$t;
                            if ($c >= 0 && $c <= 99) {
                                // предпочитаем ровно 2 цифры
                                if (strlen($t) === 2) return $c;
                                $best = $c; // запасной вариант (одна цифра)
                            }
                        }
                    }
                }
            }
            if ($best !== null) return $best * 10; // «9» ≈ «90»

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
        if ($s === '' || preg_match('/[%\/]/u', $s)) return null; // отсекаем проценты и дроби

        // разные пробелы → обычный
        $s = str_replace(["\xC2\xA0", ' ', ' '], ' ', $s);

        // помечаем разделитель копеек между цифрами (перед ровно 2 цифрами в конце «слова»)
        $s = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $s);

        // убираем тысячные разделители (пробел/точка/цент.точка перед 3 цифрами)
        $s = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $s);

        // чистим любые оставшиеся пробелы между цифрами
        $s = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $s);

        // финальный разделитель копеек — точка
        $s = str_replace('#', '.', $s);

        // валидный формат: целое или с копейками
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) {
            $v = (float)$s;
            return ($v > 0 && $v <= 99999) ? $v : null;
        }

        // если OCR «съел» точку: 4–6 цифр как копейки (44999 → 449.99)
        if (preg_match('/^\d{4,6}$/', $s)) {
            $n = (int)$s;
            $v = $n / 100.0;
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
        // Быстрый кейс: "307%" → 307.99 (для цен с мелкими копейками, склеенных в один токен)
        if (preg_match_all('/\b(\d{3,6})\s*%/u', $text, $mp)) {
            foreach ($mp[1] as $s) {
                $n = (int)$s;
                if ($n >= 100 && $n <= 999999) return floor($n) + 0.99;
            }
        }

        // Нормализация
        $text = str_replace(["\xC2\xA0", ' ', '﻿'], ' ', $text);
        // Помечаем возможный разделитель копеек: 307 99 / 307,99 / 307·99 → 307#99
        $tmp = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $text);
        // Убираем тысячные разделители
        $tmp = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $tmp);
        // Убираем пробелы внутри числа
        $tmp = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $tmp);
        $normalized = str_replace('#', '.', $tmp);

        // 1) Явные десятичные
        if (preg_match_all('/\d+(?:\.\d{1,2})/', $normalized, $m1)) {
            $best = 0.0;
            foreach ($m1[0] as $s) {
                $v = (float)$s;
                if ($v > 0.0 && $v <= 9999999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        // 2) «целое + 2 цифры» через пробел/запятую (без Overlay): 307 99 / 307, 99
        if (preg_match_all('/\b(\d{1,5})\b(?:\s{0,3}[.,]?)\s*(\d{2})\b/u', $text, $m2)) {
            $best = 0.0;
            foreach ($m2[1] as $i => $int) {
                $cent = $m2[2][$i];
                $v = (int)$int + ((int)$cent)/100.0;
                if ($v > 0.0 && $v <= 9999999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        // 3) Глухие слитые числа из 4–6 цифр: считаем, что "съелся" разделитель → делим на 100
        if (preg_match_all('/\b(\d{4,6})\b/u', $normalized, $m3)) {
            $best = 0.0;
            foreach ($m3[1] as $raw) {
                $v = ((int)$raw) / 100.0;
                if ($v > 0.0 && $v <= 99999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        // 4) В крайнем случае — просто максимальное целое «разумного» размера (1–5 цифр)
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
                // 0) Сначала пытаемся пройти «новым» путём: фото → OCR → PriceParser
                try {
                    /** @var \app\components\OcrClient $ocr */
                    $ocr = \Yii::$app->ocr;

                    $res = $ocr->extractPriceFromImage($path, 'rus', [
                        'isOverlayRequired' => true,
                        'OCREngine'         => 2,
                        'scale'             => true,
                        'detectOrientation' => true,
                    ]); // parserOpts не передаём — дефолты внутри PriceParser

                    if (!empty($res['success']) && $res['success'] === true && !empty($res['amount'])) {
                        // Отдаём в том же формате, который ожидает нижний код
                        return [
                            'amount'     => (float)$res['amount'],
                            'recognized' => [
                                'ParsedText' => (string)($res['text'] ?? ''),
                                // TextOverlay не обязателен на этом пути
                            ],
                        ];
                    }
                } catch (\Throwable $e) {
                    \Yii::warning('extractPriceFromImage failed: ' . $e->getMessage(), __METHOD__);
                    // Пойдём по старому пути
                }

                // 1) Фолбэк: старый путь — OCR → Overlay → (bbox-скоринг) → строковый парсер
                $recognized = $this->recognizeText($path);
                if (isset($recognized['error'])) {
                    return ['error' => $recognized['error'], 'reason' => 'ocr', 'recognized' => $recognized];
                }

                $amount = $this->extractAmountByOverlay($recognized, $path);
                if ($amount === null || $amount === 0.0) {
                    $amount = $this->extractAmount($recognized['ParsedText'] ?? '');
                }
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
                    'pass' => $usedPass, // 'raw'
                ];
            }

            @unlink($rawPath);
            if ($procPath) @unlink($procPath);
            return [
                'success' => true,
                'recognized_amount' => $r1['amount'],
                'parsed_text' => $r1['recognized']['ParsedText'] ?? '',
                'pass' => $usedPass, // 'processed'
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


}
