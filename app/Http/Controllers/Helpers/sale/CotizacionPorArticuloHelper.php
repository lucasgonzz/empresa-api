<?php

namespace App\Http\Controllers\Helpers\sale;

use Illuminate\Support\Facades\Schema;
use App\Exceptions\CotizacionPorArticuloInvalidaException as ValidationException;

/** Fotos monetarias opcionales. Una ficha actual nunca interpreta un costo histórico. */
class CotizacionPorArticuloHelper
{
    private static $esquema = [];

    public static function limpiar_cache() { self::$esquema = []; }

    const CAMPOS = ['cotizacion_aplicada', 'cotizacion_origen', 'moneda_base_precio', 'moneda_base_costo'];

    public static function disponible($tabla = 'article_sale')
    {
        if (array_key_exists($tabla, self::$esquema)) return self::$esquema[$tabla];
        foreach (self::CAMPOS as $campo) {
            if (!Schema::hasColumn($tabla, $campo)) {
                self::$esquema[$tabla] = false;
                return false;
            }
        }
        self::$esquema[$tabla] = true;
        return true;
    }

    public static function columnas($columnas, $tabla)
    {
        return self::disponible($tabla) ? array_merge($columnas, self::CAMPOS) : $columnas;
    }

    public static function datos($item)
    {
        $item = is_object($item) ? $item->toArray() : $item;
        if (array_key_exists('cotizacion_aplicada', $item) && ($item['cotizacion_aplicada'] !== null || !empty($item['cotizacion_origen']))) {
            return array_intersect_key($item, array_flip(self::CAMPOS));
        }
        if (isset($item['pivot'])) {
            $pivot = is_object($item['pivot']) ? $item['pivot']->toArray() : $item['pivot'];
            return !empty($pivot['cotizacion_origen']) ? array_intersect_key($pivot, array_flip(self::CAMPOS)) : [];
        }
        return [];
    }

    public static function positiva($valor)
    {
        return is_numeric($valor) && is_finite((float) $valor) && (float) $valor >= 0.000001 && (float) $valor < 100000000000000;
    }

    public static function agregar($pivot, $item, $tabla)
    {
        $datos = self::datos($item);
        if (!$datos || !self::disponible($tabla)) {
            return $pivot;
        }
        // NULL describe un histórico sin cotización conocida; nunca se completa con la de hoy.
        if (!self::positiva($datos['cotizacion_aplicada'] ?? null) && !(($datos['cotizacion_origen'] ?? null) === 'historica' && ($datos['cotizacion_aplicada'] ?? null) === null)) {
            throw ValidationException::withMessages(['cotizacion_aplicada' => 'La cotización de cada artículo debe ser un número mayor que cero.']);
        }
        if (!isset($datos['cotizacion_origen']) || !in_array($datos['cotizacion_origen'], ['proveedor', 'vender', 'manual', 'historica'], true)) {
            throw ValidationException::withMessages(['cotizacion_origen' => 'El origen de la cotización del artículo no es válido.']);
        }
        if (isset($datos['cotizacion_aplicada']) && round((float) $datos['cotizacion_aplicada'], 6) !== (float) $datos['cotizacion_aplicada']) {
            throw ValidationException::withMessages(['cotizacion_aplicada' => 'La cotización del artículo admite hasta seis decimales.']);
        }
        foreach (['moneda_base_precio', 'moneda_base_costo'] as $campo) {
            if (!isset($datos[$campo]) || !is_scalar($datos[$campo]) || is_bool($datos[$campo]) || !in_array((string) $datos[$campo], ['1', '2'], true)) {
                throw ValidationException::withMessages([$campo => 'La moneda de origen del artículo no es válida.']);
            }
        }
        $datos['moneda_base_precio'] = (int) $datos['moneda_base_precio'];
        $datos['moneda_base_costo'] = (int) $datos['moneda_base_costo'];
        return array_merge($pivot, $datos);
    }

    /** Recupera metadatos aunque una SPA anterior no conozca el contrato nuevo. */
    public static function preservar($item, &$previos)
    {
        $candidatos = [];
        foreach ($previos as $indice => $anterior) {
            if ((int) $anterior->id !== (int) $item['id']) continue;
            $pivot = $anterior->pivot->toArray();
            $variante = $item['article_variant_id'] ?? ($item['pivot']['article_variant_id'] ?? null);
            if ((int) ($pivot['article_variant_id'] ?? 0) !== (int) $variante) continue;
            $candidatos[$indice] = $pivot;
        }
        $precio = $item['price_vender'] ?? ($item['pivot']['price'] ?? ($item['price'] ?? null));
        $elegido = null;
        $puntaje_maximo = -1;
        foreach ($candidatos as $indice => $pivot) {
            $puntaje = 0;
            if (isset($item['pivot']['id'], $pivot['id']) && (int) $item['pivot']['id'] === (int) $pivot['id']) $puntaje += 100;
            if ($precio !== null && abs((float) $precio - (float) $pivot['price']) < 0.000001) $puntaje += 1;
            $costo_entrante = $item['pivot']['cost'] ?? null;
            if ($costo_entrante !== null && abs((float) $costo_entrante - (float) $pivot['cost']) < 0.000001) $puntaje += 2;
            $cantidad = $item['amount'] ?? ($item['pivot']['amount'] ?? null);
            if ($cantidad !== null && abs((float) $cantidad - (float) $pivot['amount']) < 0.000001) $puntaje += 2;
            $datos_entrantes = self::datos($item);
            if ($datos_entrantes && $datos_entrantes == self::datos(['pivot' => $pivot])) $puntaje += 8;
            if ($puntaje > $puntaje_maximo) {
                $puntaje_maximo = $puntaje;
                $elegido = $indice;
            }
        }
        if ($elegido === null && count($candidatos) === 1) $elegido = key($candidatos);
        if ($elegido === null) return $item;
        $pivot = $candidatos[$elegido];
        $datos = self::datos($item);
        $guardados = self::datos(['pivot' => $pivot]);
        if (!$datos) $item = array_merge($item, $guardados);
        $costo = $item['pivot']['cost'] ?? null;
        $misma_cotizacion = !$datos || !$guardados || $datos == $guardados;
        if ($precio !== null && abs((float) $precio - (float) $pivot['price']) < 0.000001
            && $misma_cotizacion && ($costo === null || abs((float) $costo - (float) $pivot['cost']) < 0.000001)) {
            $item['_foto_monetaria_guardada'] = $pivot;
        }
        unset($previos[$elegido]);
        return $item;
    }
}
