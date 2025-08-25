<?php

namespace App\Http\Controllers\Helpers;

class Utf8Helper {
	

    static function convertir_utf8($value) {
        if (is_object($value)) {
            $value = (array)$value;
        }
        if(is_array($value)) {
            foreach($value as $key => $val) {
                $value[$key] = Self::convertir_utf8($val);
            }
            return $value;
        } else if(is_string($value)) {
            $value = Self::limpiar_cadena($value);
            $value = str_replace("\'", "", $value);
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } else {
            return $value;
        }
    }

    static function limpiar_cadena($value) {
        return preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '', $value);
    }
}