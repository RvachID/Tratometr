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
     * Базовый OCR-запрос с Overlay
     */
    private function recognizeText(string $filePath): array
    {
        try {
            $apiResponse = \Yii::$app->ocr->parseImage($filePath, ['eng','rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                // клиент может сам фолбечить по движкам 2 -> 1
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

    /**
     * Overlay → группировка → кроп-рефайн → ROI-копейки → фолбэк целой части
     */
    private function extractAmountByOverlay(array $recognized, string $imagePath): ?float
    {
        $lines = $recognized['TextOverlay']['Lines'] ?? null;
        if (!$lines || !is_array($lines)) return null;

        // Токены (сразу отбрасываем зачёркнутые)
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

        // Сортировка «строкой»
        usort($tokens, function($a,$b){
            $dy = $a['T'] - $b['T'];
            if (abs($dy) > 8) return $dy;
            return $a['L'] <=> $b['L'];
        });

        // Группировка по строкам
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

            // ⛔️ Больше НЕ делим на 100 на этом шаге
            $val = $this->normalizeOcrNumber($raw, false);

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

        // Выбор главной группы
        usort($groups, fn($a,$b) => $b['score'] <=> $a['score']);
        $main = null;
        foreach ($groups as $g) { if ($g['val'] !== null) { $main = $g; break; } }
        if (!$main) return null;

        if ($main['hasCents']) return $main['val'];

        // Кроп-рефайн (тут уже можно аккуратно решать «слепленное»)
        $digitsInGroup = preg_match_all('~\d~', (string)$main['raw']);
        $refined = $this->refinePriceFromCrop($imagePath, $main['bbox'], $digitsInGroup);
        if ($refined !== null) return $refined;

        // «307%» → .99
        foreach ($main['tokens'] as $tk) {
            if (!empty($tk['hasPct'])) {
                return floor($main['val']) + 0.99;
            }
        }

        // ROI: мелкие копейки справа-сверху
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

        // Нет копеек — возвращаем целую часть БЕЗ деления
        return $main['val'];
    }


    /**
     * Нормализуем «числовое» слово из OCR в float.
     * Деление на 100 для слитых 4–6 цифр включаем только при $allowDiv100=true.
     */
    private function normalizeOcrNumber(string $s, bool $allowDiv100 = false): ?float
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

        // Делить на 100 только по явному разрешению
        if ($allowDiv100 && preg_match('/^\d{4,6}$/', $s)) {
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
     * Фолбэк парсер из общего текста
     */
    /**
     * Фолбэк из общего текста.
     * Деление 4–6 цифр на 100 — ТОЛЬКО если $allowDiv100=true.
     */
    private function extractAmount(string $text, bool $allowDiv100 = false): float
    {
        // Нормализация
        $text = str_replace(["\xC2\xA0", ' ', '﻿'], ' ', $text);
        // Помечаем возможный разделитель копеек: 307 99 / 307,99 / 307·99 → 307#99
        $tmp  = preg_replace('/(?<=\d)[\s,\.·•](?=\d{2}\b)/u', '#', $text);
        // Убираем тысячные разделители
        $tmp  = preg_replace('/(?<=\d)[\s\.·•](?=\d{3}\b)/u', '', $tmp);
        // Убираем пробелы внутри числа
        $tmp  = preg_replace('/(?<=\d)\s+(?=\d)/u', '', $tmp);
        $normalized = str_replace('#', '.', $tmp);

        // 1) Явные десятичные — выбираем по скорингу (штраф «перевёртышам» .66 и слишком малым)
        if (preg_match_all('/\d+(?:\.\d{1,2})/', $normalized, $m1)) {
            $best = 0.0; $bestScore = 0.0;
            foreach ($m1[0] as $s) {
                $v = (float)$s;
                if ($v <= 0.0 || $v > 9999999) continue;

                $frac  = (int)round(($v - floor($v)) * 100);
                $score = 1.0;
                if (in_array($frac, [99,95,90,89], true)) $score *= 1.25;
                if ($v < 100 && preg_match('~\b\d{3,}\b~', $normalized)) $score *= 0.6;

                // штраф «все шестерки»
                $sDigits = preg_replace('/\D/','', number_format($v, 2, '.', ''));
                if ($sDigits !== '') {
                    $ratio6 = substr_count($sDigits, '6') / strlen($sDigits);
                    if ($ratio6 >= 0.7) $score *= 0.45;
                }

                if ($score > $bestScore || ($score === $bestScore && $v > $best)) {
                    $best = $v; $bestScore = $score;
                }
            }
            if ($best > 0) return $best;
        }

        // 2) «целое + 2 цифры» рядом (без Overlay): 307 99 / 307, 99
        if (preg_match_all('/\b(\d{1,5})\b(?:\s{0,3}[.,]?)\s*(\d{2})\b/u', $text, $m2)) {
            $best = 0.0;
            foreach ($m2[1] as $i => $int) {
                $cent = $m2[2][$i];
                $v = (int)$int + ((int)$cent)/100.0;
                if ($v > 0.0 && $v <= 9999999 && $v > $best) $best = $v;
            }
            if ($best > 0) return $best;
        }

        // 3) Глухие слитые 4–6 цифр → ÷100 — ТОЛЬКО если явно позволили
        if ($allowDiv100 && !preg_match('/\b\d{1,5}\D{0,3}\d{2}\b/u', $normalized)) {
            if (preg_match_all('/\b(\d{4,6})\b/u', $normalized, $m3)) {
                $best = 0.0;
                foreach ($m3[1] as $raw) {
                    $v = ((int)$raw) / 100.0;
                    if ($v > 0.0 && $v <= 99999 && $v > $best) $best = $v;
                }
                if ($best > 0) return $best;
            }
        }

        // 4) Последний шанс — максимум целое разумного размера (1–5 цифр)
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
     * Мягкая предобработка исходника
     */
    private function preprocessImage(string $filePath): bool
    {
        Yii::info('Обработка изображения (safe, keep-color) начата', __METHOD__);
        try {
            $im = new \Imagick($filePath);
            $im->autoOrient(); // если есть EXIF

            $w = $im->getImageWidth();
            if ($w > 1280) {
                $im->resizeImage(1280, 0, \Imagick::FILTER_LANCZOS, 1);
            }

            $im->unsharpMaskImage(0.5, 0.5, 0.8, 0.01);

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

    /**
     * Принудительно загоняет файл под лимит размера (JPEG, ресайз + компрессия)
     */
    private function enforceSizeLimit(string $path, int $bytes = 1048576): bool
    {
        try {
            if (!is_file($path)) return false;
            if (@filesize($path) <= $bytes) return true;

            $im = new \Imagick($path);
            $im->autoOrient();

            for ($i=0; $i<3; $i++) {
                $im->resizeImage((int)round($im->getImageWidth()*0.85), 0, \Imagick::FILTER_LANCZOS, 1);
                $im->setImageCompressionQuality(max(60, 85 - $i*10));
                $im->setImageFormat('jpeg');
                $im->writeImage($path);
                if (@filesize($path) <= $bytes) { $im->clear(); $im->destroy(); return true; }
            }
            $im->clear(); $im->destroy();
            return @filesize($path) <= $bytes;
        } catch (\Throwable $e) {
            \Yii::warning('enforceSizeLimit fail: '.$e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Главный экшен распознавания
     */
    public function actionRecognize()
    {
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $rawPath = null;
        $procPath = null;

        try {
            $image = \yii\web\UploadedFile::getInstanceByName('image');
            if (!$image) {
                return ['success' => false, 'error' => 'Изображение не загружено'];
            }

            if (!in_array($image->type, ['image/jpeg','image/png','image/webp','image/heic','image/heif'], true)) {
                return ['success'=>false,'error'=>'Неверный формат изображения'];
            }

            $ext = strtolower($image->extension ?: 'jpg');
            $sizeLimit = 1024 * 1024; // лимит OCR.space

            // сохраняем сырой
            $rawPath = \Yii::getAlias('@runtime/' . uniqid('scan_raw_') . '.' . $ext);
            if (!$image->saveAs($rawPath)) {
                return ['success' => false, 'error' => 'Не удалось сохранить изображение'];
            }
            $this->enforceSizeLimit($rawPath, $sizeLimit);

            // копия под soft-предобработку
            $procPath = \Yii::getAlias('@runtime/' . uniqid('scan_proc_') . '.' . $ext);
            @copy($rawPath, $procPath);
            if (!$this->preprocessImage($procPath)) {
                @unlink($procPath);
                $procPath = null;
            }
            if ($procPath) {
                $this->enforceSizeLimit($procPath, $sizeLimit);
            }

            // Раннер одного прохода
            $run = function (string $path) {
                try {
                    /** @var \app\components\OcrClient $ocr */
                    $ocr = \Yii::$app->ocr;

                    // Если у тебя есть компонентный извлекатель — используем его как первый путь
                    $res = $ocr->extractPriceFromImage($path, ['eng','rus'], [
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
                                // Overlay на этом пути не обязателен
                            ],
                        ];
                    }
                } catch (\Throwable $e) {
                    \Yii::warning('extractPriceFromImage failed: ' . $e->getMessage(), __METHOD__);
                    // Пойдём по старому пути
                }

                // Фолбэк: OCR → Overlay → (bbox-скоринг/кроп/ROI) → строковый парсер
                $recognized = $this->recognizeText($path);
                if (isset($recognized['error'])) {
                    return ['error' => $recognized['error'], 'reason' => 'ocr', 'recognized' => $recognized];
                }

                $amount = $this->extractAmountByOverlay($recognized, $path);
                if ($amount !== null && $amount > 0.0) {
                    return ['amount' => $amount, 'recognized' => $recognized];
                }

                // строковый фолбэк с вычитанием зачёркнутых слов
                $cleanText = $this->stripStrikethroughText($recognized, $recognized['ParsedText'] ?? '');
                $amount = $this->extractAmount($cleanText, false);
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
                    return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                }

                $r2 = $run($rawPath);
                if (empty($r2['amount'])) {
                    if (($r1['reason'] ?? '') === 'ocr' || ($r2['reason'] ?? '') === 'ocr') {
                        return ['success' => false, 'error' => 'Ошибка OCR', 'reason' => 'ocr'];
                    }
                    if (($r1['reason'] ?? '') === 'no_amount' || ($r2['reason'] ?? '') === 'no_amount') {
                        return ['success' => false, 'error' => 'Не удалось извлечь сумму', 'reason' => 'no_amount'];
                    }
                    return ['success' => false, 'error' => 'Текст не распознан', 'reason' => 'empty'];
                }

                return [
                    'success' => true,
                    'recognized_amount' => (float)$r2['amount'],
                    'parsed_text' => (string)($r2['recognized']['ParsedText'] ?? ''),
                    'pass' => $usedPass,
                ];
            }

            return [
                'success' => true,
                'recognized_amount' => (float)$r1['amount'],
                'parsed_text' => (string)($r1['recognized']['ParsedText'] ?? ''),
                'pass' => $usedPass,
            ];
        } catch (\Throwable $e) {
            \Yii::error($e->getMessage(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        } finally {
            if ($rawPath && is_file($rawPath)) @unlink($rawPath);
            if ($procPath && is_file($procPath)) @unlink($procPath);
        }
    }

    /**
     * Сохранение позиции в активной сессии
     */
    public function actionStore()
    {
        Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        try {
            if (Yii::$app->user->isGuest) {
                return ['success' => false, 'error' => 'Требуется вход'];
            }

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
            $entry->session_id = $ps->id;
            $entry->amount = (float)$amount;
            $entry->qty = (float)$qty;
            $entry->store = $ps->shop;
            $entry->category = $ps->category ?: null;
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
                'total' => number_format($total, 2, '.', ''), // единый формат
            ];
        } catch (\Throwable $e) {
            Yii::error($e->getMessage() . "\n" . $e->getTraceAsString(), __METHOD__);
            return ['success' => false, 'error' => 'Внутренняя ошибка сервера'];
        }
    }

    /**
     * Автосохранение суммы/qty
     */
    public function actionUpdate($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne([
            'id' => (int)$id,
            'user_id' => Yii::$app->user->id,
            'session_id' => $ps->id, // важно!
        ]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->load(Yii::$app->request->post(), '');
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

    /**
     * Удаление строки
     */
    public function actionDelete($id)
    {
        Yii::$app->response->format = Response::FORMAT_JSON;

        $ps = Yii::$app->ps->active(Yii::$app->user->id);
        if (!$ps) return ['success' => false, 'error' => 'Нет активной покупки.'];

        $m = PriceEntry::findOne([
            'id' => (int)$id,
            'user_id' => Yii::$app->user->id,
            'session_id' => $ps->id,
        ]);
        if (!$m) return ['success' => false, 'error' => 'Запись не найдена'];

        $m->delete();

        $total = (float)PriceEntry::find()
            ->where(['user_id' => Yii::$app->user->id, 'session_id' => $ps->id])
            ->sum('amount * qty');

        return ['success' => true, 'total' => number_format($total, 2, '.', '')];
    }

    /**
     * Кроп вокруг основной цены и повторный OCR только на этом участке.
     * Делает извлечение кандидатов (X.XX / "целое+две" / 4–6 цифр ÷100 по условию),
     * вычищает зачёркнутые слова из текста кропа и выбирает лучший по скору.
     * $digitsInGroup — сколько цифр нашли в основной группе Overlay (ожидаемая длина целой части).
     * Деление «слитных» 4–6 цифр на 100 разрешаем ТОЛЬКО если $digitsInGroup <= 3.
     */
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

            // Увеличенный левый паддинг — чтобы не терять первый разряд
            $padL = max((int)round($Wg * 0.30), 28);
            $padT = max((int)round($Hg * 0.18), 12);
            $padR = max((int)round($Wg * 1.35), 60); // хвост копеек
            $padB = max((int)round($Hg * 0.25), 14);

            $x = max(0, $L - $padL);
            $y = max(0, $T - $padT);
            $w = min($W - $x, $Wg + $padL + $padR);
            $h = min($H - $y, $Hg + $padT + $padB);
            if ($w < 24 || $h < 24) return null;

            $crop = clone $im;
            $crop->cropImage($w, $h, $x, $y);
            $crop->setImagePage(0,0,0,0);

            // Аккуратная локальная обработка
            $crop->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $crop->normalizeImage();
            $crop->modulateImage(100, 110, 100);
            $crop->unsharpMaskImage(0.6, 0.6, 1.2, 0.02);

            $crop->setImageFormat('jpeg');
            $crop->setImageCompressionQuality(92);
            $tmp = \Yii::getAlias('@runtime/' . uniqid('price_roi_', true) . '.jpg');
            $crop->writeImage($tmp);
            $crop->clear(); $crop->destroy();
            $im->clear();   $im->destroy();

            // OCR только по кропу
            $raw = \Yii::$app->ocr->parseImage($tmp, ['eng','rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 2,
            ]);
            @unlink($tmp);

            $res   = $raw['ParsedResults'][0] ?? [];
            $text0 = (string)($res['ParsedText'] ?? '');
            // Вырезаем зачёркнутые слова, если они попали в кроп
            $text  = $this->stripStrikethroughText(['TextOverlay' => $res['TextOverlay'] ?? []], $text0);

            // Собираем кандидатов
            $cands = [];

            // (A) Явные X.XX
            if (preg_match_all('/\b\d+(?:[.,]\d{2})\b/u', $text, $ma)) {
                foreach ($ma[0] as $s) {
                    $v = (float)str_replace(',', '.', $s);
                    if ($v > 0 && $v < 100000) $cands[] = $v;
                }
            }

            // (B) «целое + любые 2 цифры» рядом
            if (preg_match_all('/\b(\d{1,6})\D{0,3}(\d{2})\b/u', $text, $mb)) {
                foreach ($mb[1] as $i => $int) {
                    $v = (int)$int + ((int)$mb[2][$i]) / 100.0;
                    if ($v > 0 && $v < 100000) $cands[] = $v;
                }
            }

            // (C) 4–6 цифр слитно → ÷100 (ТОЛЬКО если целая часть в основной группе короткая)
            if (empty($cands) && $digitsInGroup <= 3) {
                if (preg_match_all('/\b(\d{4,6})\b/u', $text, $mc)) {
                    foreach ($mc[1] as $rawNum) {
                        $v = ((int)$rawNum) / 100.0;
                        if ($v > 0 && $v < 100000) $cands[] = $v;
                    }
                }
            }

            if (!$cands) return null;

            // Скоринг кандидатов: бонус .99, штраф «все шестерки», штраф за слишком малое значение
            $expectIntDigits = max(0, min(6, $digitsInGroup));
            $bestV = null; $bestScore = -INF;

            $scoreOf = function(float $v) use ($expectIntDigits): float {
                $score = 1.0;

                $frac = (int)round(($v - floor($v)) * 100);
                if (in_array($frac, [99,95,90,89], true)) $score += 0.6;

                $sDigits = preg_replace('/\D/','', number_format($v, 2, '.', ''));
                if ($sDigits !== '') {
                    $ratio6 = substr_count($sDigits, '6') / strlen($sDigits);
                    if ($ratio6 >= 0.7) $score *= 0.45; // «666.66»
                }

                // Если ожидаем ≥2–3 цифры в целой части, а значение слишком малое — штраф (ловим 1.09 вместо 109.99)
                if ($v < 10 && $expectIntDigits >= 2)  $score *= 0.25;
                if ($v < 100 && $expectIntDigits >= 3) $score *= 0.35;

                // Лёгкий бонус за большее число (помогает 99.99 победить 9.99)
                $score += min(0.4, log10(max($v, 1.0)) * 0.15);

                return $score;
            };

            foreach ($cands as $v) {
                $sc = $scoreOf($v);
                if ($sc > $bestScore) { $bestScore = $sc; $bestV = $v; }
            }

            return $bestV ?? null;
        } catch (\Throwable $e) {
            \Yii::warning('refinePriceFromCrop failed: '.$e->getMessage(), __METHOD__);
            return null;
        }
    }


    /**
     * Узкий ROI справа-сверху от основной цены для вычитки копеек (2 цифры)
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

            // узкая зона «хвостика» копеек
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

            // локальная обработка для мелкого шрифта
            $roi->resizeImage($w * 3, 0, \Imagick::FILTER_LANCZOS, 1);
            $roi->normalizeImage();
            $roi->modulateImage(100, 120, 100);
            $roi->adaptiveSharpenImage(1, 0.8);

            $roi->setImageFormat('jpeg');
            $roi->setImageCompressionQuality(92);
            $tmp = \Yii::getAlias('@runtime/' . uniqid('roi_', true) . '.jpg');
            $roi->writeImage($tmp);
            $roi->clear(); $roi->destroy();
            $im->clear();  $im->destroy();

            // OCR по ROI (движок 1 любит «дробить» мелкие цифры)
            $raw = \Yii::$app->ocr->parseImage($tmp, ['eng','rus'], [
                'isOverlayRequired' => true,
                'scale'             => true,
                'detectOrientation' => true,
                'OCREngine'         => 1,
            ]);
            @unlink($tmp);

            $res   = $raw['ParsedResults'][0] ?? [];
            $text0 = (string)($res['ParsedText'] ?? '');
            // чистим зачёркнутые токены из текста ROI
            $text  = $this->stripStrikethroughText(['TextOverlay' => $res['TextOverlay'] ?? []], $text0);

            // % в ROI → 99
            if (strpos($text, '%') !== false) return 99;

            // две цифры словом
            if (preg_match('/\b(\d{2})\b/u', $text, $m)) {
                $c = (int)$m[1];
                if ($c >= 0 && $c <= 99) return $c;
            }

            // короткие цифровые токены из Overlay ROI
            if (!empty($res['TextOverlay']['Lines'])) {
                $best = null;
                foreach ($res['TextOverlay']['Lines'] as $ln) {
                    foreach (($ln['Words'] ?? []) as $w) {
                        if (!empty($w['IsStrikethrough'])) continue;
                        $t = preg_replace('~\D+~u', '', (string)($w['WordText'] ?? ''));
                        if ($t === '') continue;

                        if (strlen($t) === 2) {
                            $c = (int)$t; if ($c >= 0 && $c <= 99) return $c;
                        } elseif (strlen($t) === 1) {
                            $best = max($best ?? 0, (int)$t); // одну цифру трактуем как десятки
                        }
                    }
                }
                if ($best !== null) return min(99, $best * 10);
            }

            return null;
        } catch (\Throwable $e) {
            \Yii::warning('ROI cents OCR failed: '.$e->getMessage(), __METHOD__);
            return null;
        }
    }

    /**
     * Удаляет из ParsedText все слова, помеченные в Overlay как IsStrikethrough=true
     */
    private function stripStrikethroughText(array $recognized, string $parsedText): string
    {
        if (empty($recognized['TextOverlay']['Lines']) || $parsedText === '') return $parsedText;

        $needles = [];
        foreach ($recognized['TextOverlay']['Lines'] as $ln) {
            foreach (($ln['Words'] ?? []) as $w) {
                if (!empty($w['IsStrikethrough']) && !empty($w['WordText'])) {
                    $t = trim((string)$w['WordText']);
                    if ($t !== '') $needles[$t] = true;
                }
            }
        }
        if (!$needles) return $parsedText;

        foreach (array_keys($needles) as $t) {
            $q = preg_quote($t, '/');
            $parsedText = preg_replace('/\b'.$q.'\b/u', ' ', $parsedText);
        }
        $parsedText = preg_replace('/\s{2,}/u', ' ', $parsedText);
        return trim($parsedText);
    }
}
