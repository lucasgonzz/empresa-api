<?php

namespace App\Services\Zipnova;

/**
 * Decide si el comercio absorbe el costo del envío (misión zipnova-envios, 14/9/2026).
 *
 * Dos reglas, cualquiera alcanza:
 *  1. Todos los artículos que viajan tienen `free_shipping = 1` (el campo que ya existía para
 *     Tienda Nube). Los que no requieren envío no cuentan.
 *  2. El comercio cargó "envío gratis a partir de $X" (`envio_gratis_desde` en la config del
 *     conector) y el subtotal de artículos llega a ese monto.
 *
 * Cuando aplica, la opción se le muestra al comprador con precio 0 y `precio_original` conserva
 * lo que Zipnova le va a cobrar al comercio igual.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api`. Se compara token a token.
 */
class ZipnovaEnvioGratisHelper
{
    /**
     * @param array $lineas `[['article' => objeto con free_shipping/requires_shipping, 'amount' => int], ...]`.
     * @param float $subtotal Subtotal de artículos (lo que ya suma el carrito, sin envío).
     * @param array $config Config del conector (`envio_gratis_desde`).
     * @return bool
     */
    public static function aplica(array $lineas, $subtotal, array $config = [])
    {
        if (self::todos_con_envio_gratis($lineas)) {
            return true;
        }

        $desde = isset($config['envio_gratis_desde']) ? $config['envio_gratis_desde'] : null;
        if (is_numeric($desde) && (float) $desde > 0 && (float) $subtotal >= (float) $desde) {
            return true;
        }

        return false;
    }

    /**
     * True si hay al menos un artículo que viaja y todos los que viajan tienen envío gratis.
     *
     * @param array $lineas
     * @return bool
     */
    public static function todos_con_envio_gratis(array $lineas)
    {
        $viajan = 0;

        foreach ($lineas as $linea) {
            if (!isset($linea['article']) || !is_object($linea['article'])) {
                continue;
            }
            $article = $linea['article'];
            if (!ZipnovaPaquetesHelper::requiere_envio($article)) {
                continue;
            }
            $viajan++;
            $gratis = isset($article->free_shipping) ? $article->free_shipping : null;
            if (is_null($gratis) || (int) $gratis !== 1) {
                return false;
            }
        }

        return $viajan > 0;
    }
}
