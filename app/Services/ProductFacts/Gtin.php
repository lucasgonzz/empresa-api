<?php

namespace App\Services\ProductFacts;

/** GTIN comercial: formato y verificador, sin códigos internos ni de balanza. */
class Gtin
{
    public static function canonical($code)
    {
        $code = trim((string) $code);
        if (!preg_match('/^(?:\d{8}|\d{12}|\d{13}|\d{14})$/D', $code) || !preg_match('/[1-9]/', $code)) {
            return null;
        }
        $sum = 0;
        for ($i = strlen($code) - 2, $weight = 3; $i >= 0; $i--, $weight = 4 - $weight) {
            $sum += (int) $code[$i] * $weight;
        }
        if ((10 - $sum % 10) % 10 !== (int) substr($code, -1)) {
            return null;
        }
        $canonical = str_pad($code, 14, '0', STR_PAD_LEFT);
        $prefix = (int) substr($canonical, 1, 3);
        if (($prefix >= 20 && $prefix <= 29) || ($prefix >= 40 && $prefix <= 49) || ($prefix >= 200 && $prefix <= 299)) {
            return null;
        }
        return $canonical;
    }

    public static function equivalents($canonical)
    {
        $codes = [$canonical];
        foreach ([13, 12, 8] as $length) {
            if (substr($canonical, 0, 14 - $length) === str_repeat('0', 14 - $length)) {
                $codes[] = substr($canonical, -$length);
            }
        }
        return array_unique($codes);
    }
}
