<?php
namespace app\components;

final class PriceParser
{
    /**
     * Извлекает цену из OCR-текста. Возвращает float (руб/rsd) или null.
     * $opts: ['curr'=>[...], 'units'=>[...]] — набор «якорей» валют/единиц (необязательно).
     */
    public static function parse(string $text, array $opts = []): ?float
    {
        $raw = trim((string)$text);
        if ($raw === '') return null;

        $curr = $opts['curr']  ?? ['rsd','din','dinara','руб','rub','₽','р','uah','₴','byn','br','kzt','₸','pln','zl','eur','€','usd','$'];
        $units= $opts['units'] ?? ['kom','шт','pcs','kg','кг','g','гр','l','л','ml','мл','ком'];

        // нормализация пробелов/разделителей
        $norm  = preg_replace('/\x{00A0}|\x{202F}|\x{2009}/u', ' ', $raw); // NBSP, thin space
        $norm  = preg_replace('~[^\S\r\n]+~u', ' ', $norm);
        $lower = mb_strtolower($norm, 'UTF-8');

        // 0) классика "123,45" / "123.45"
        if (preg_match('/\b(\d{1,7})[.,](\d{2})\b/u', $lower, $m)) {
            return self::toAmount($m[1], $m[2]);
        }

        $currRe = self::altRegex($curr);
        $unitRe = self::altRegex($units);

        // 1) «мелкие копейки»: целая часть + (до 3 пробелов/знак) + две цифры
        $cands = [];
        if (preg_match_all('/\b(\d{1,7})\b(?:\s{0,3}[\.\,]?)\s*(\d{2})\b/u', $lower, $ms, PREG_OFFSET_CAPTURE)) {
            foreach ($ms[0] as $i => $mAll) {
                $int  = $ms[1][$i][0];
                $cent = $ms[2][$i][0];
                $pos  = $mAll[1];

                $amount = self::toAmount($int, $cent);
                if ($amount === null) continue;

                $slice = mb_substr($lower, $pos, 60, 'UTF-8'); // окно вперёд
                $score = 0;
                if ($currRe && preg_match($currRe, $slice)) $score += 2;
                if ($unitRe && preg_match($unitRe, $slice)) $score += 1;

                // 2-4 цифры в целой — бонус, чаще всего реальная цена
                $len = strlen($int);
                if ($len >= 2 && $len <= 4) $score++;

                $cands[] = [$amount, $score];
            }
        }
        if ($cands) {
            usort($cands, fn($a,$b) => $b[1] <=> $a[1]);
            return $cands[0][0];
        }

        // 2) фолбэк: «целая + валюта»
        if ($currRe && preg_match('/\b(\d{1,7})\b\s{0,3}'. $currRe .'/u', $lower, $m2)) {
            $v = (int)$m2[1];
            if ($v > 0) return (float)$v;
        }

        return null;
    }

    private static function toAmount($iPart, $cPart): ?float
    {
        $i = (int)$iPart;
        $c = (int)$cPart;
        if ($i < 0 || $c < 0 || $c > 99) return null;
        if ($i > 9999999) return null;
        return $i + ($c / 100.0);
    }

    private static function altRegex(array $alts): ?string
    {
        if (!$alts) return null;
        $escaped = array_map(fn($s) => preg_quote($s, '/'), $alts);
        return '/\b(' . implode('|', $escaped) . ')\b/u';
    }
}
