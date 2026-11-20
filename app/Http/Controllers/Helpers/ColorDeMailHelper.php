<?php

namespace App\Http\Controllers\Helpers;

class ColorDeMailHelper
{
    public static function palette($value)
    {
        $color = is_string($value) ? trim($value) : '';
        if (preg_match('/^#[a-f0-9]{3}$/i', $color)) {
            $color = '#'.$color[1].$color[1].$color[2].$color[2].$color[3].$color[3];
        }
        if (!preg_match('/^#[a-f0-9]{6}$/i', $color)) {
            $color = '#111827';
        }
        $l = self::luminance($color);
        return ['background' => $color, 'text' => ($l + .05) / .05 >= 4.5 ? '#000000' : '#ffffff'];
    }

    public static function luminance($color)
    {
        $parts = [];
        foreach ([1, 3, 5] as $offset) {
            $v = hexdec(substr($color, $offset, 2)) / 255;
            $parts[] = $v <= .04045 ? $v / 12.92 : pow(($v + .055) / 1.055, 2.4);
        }
        return .2126 * $parts[0] + .7152 * $parts[1] + .0722 * $parts[2];
    }
}
