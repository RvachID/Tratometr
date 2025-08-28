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

        // ТОЛЬКО валюты
        $curr = $opts['curr'] ?? [
                'rsd','din','dinara','руб','rub','₽','р',
                'uah','₴','byn','br','kzt','₸','pln','zl','eur','€','usd','$'
            ];
        $currRe = self::altRegex($curr);

        // Нормализация
        $norm  = preg_replace('/\x{00A0}|\x{202F}|\x{2009}/u', ' ', $raw);
        $norm  = preg_replace('~[^\S\r\n]+~u', ' ', $norm);
        $lower = mb_strtolower($norm, 'UTF-8');

        $cands = [];

        // Спец-кейс: "XXX%" -> XXX.99
        if (preg_match_all('/\b(\d{2,6})\s*%/u', $lower, $mp, PREG_OFFSET_CAPTURE)) {
            foreach ($mp[1] as $i => $m) {
                $pos = $mp[0][$i][1];
                $n   = (int)$m[0];
                if ($n >= 100 && $n <= 9999) {
                    $slice = mb_substr($lower, $pos, 40, 'UTF-8');
                    $score = 2.5 + (preg_match('~'.$currRe.'~u', $slice) ? 0.8 : 0);
                    $cands[] = [$n + 0.99, $score];
                }
            }
        }

        // 1) 123,45 / 123.45
        if (preg_match_all('/\b(\d{1,7})[.,](\d{1,2})\b/u', $lower, $m, PREG_OFFSET_CAPTURE)) {
            for ($i=0,$n=count($m[0]); $i<$n; $i++) {
                $int  = $m[1][$i][0]; $frac = $m[2][$i][0]; $pos = $m[0][$i][1];
                $f = (int)$frac; if (strlen($frac)===1) $f *= 10;
                $val = (int)$int + $f/100.0;

                $slice  = mb_substr($lower, $pos, 60, 'UTF-8');
                $ilen   = strlen($int);
                $score  = 1.5 + (preg_match('~'.$currRe.'~u', $slice) ? 1.0 : 0);
                if ($ilen>=2 && $ilen<=4) $score += 0.6;
                if ($val>9999) $score -= 1.0;
                if ($f===0 && $ilen>=4) $score -= 1.0;

                $cands[] = [$val, $score];

                // фикса "30799.00" -> 307.99
                if ($f===0 && $ilen>=4) {
                    $v2 = ((int)$int)/100.0;
                    if ($v2>0 && $v2<=9999) $cands[] = [$v2, $score + 1.2];
                }
            }
        }

        // 2) «мелкие копейки»: 123 45 / 123, 45
        if (preg_match_all('/\b(\d{1,7})\b(?:\s{0,3}[.,]?)\s*(\d{2})\b/u', $lower, $ms, PREG_OFFSET_CAPTURE)) {
            for ($i=0,$n=count($ms[0]); $i<$n; $i++) {
                $int  = $ms[1][$i][0]; $cent = $ms[2][$i][0]; $pos = $ms[0][$i][1];
                $val  = (int)$int + ((int)$cent)/100.0;
                $slice= mb_substr($lower, $pos, 60, 'UTF-8');
                $ilen = strlen($int);
                $score= 1.8 + (preg_match('~'.$currRe.'~u', $slice) ? 1.0 : 0);
                if ($ilen>=2 && $ilen<=4) $score += 0.6;
                if ($val>9999) $score -= 1.0;
                $cands[] = [$val, $score];
            }
        }

        // 3) Фолбэк: целое + валюта поблизости (и альтернатива /100)
        if (preg_match_all('/\b(\d{1,7})\b(?:(?!\d).){0,20}'.$currRe.'/u', $lower, $m3, PREG_OFFSET_CAPTURE)) {
            for ($i=0,$n=count($m3[0]); $i<$n; $i++) {
                $int = $m3[1][$i][0]; $ilen=strlen($int); $val=(float)$int;
                $score = 0.8 + ($ilen>=2 && $ilen<=4 ? 0.4 : 0) - ($val>9999 ? 1.0 : 0);
                $cands[] = [$val, $score];
                if ($ilen>=4) {
                    $v2 = ((int)$int)/100.0;
                    if ($v2>0 && $v2<=9999) $cands[] = [$v2, $score + 1.2];
                }
            }
        }

        if (!$cands) return null;
        usort($cands, fn($a,$b) => $b[1] <=> $a[1]);
        return $cands[0][0];
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
