<?php

namespace App\Services\Zipnova;

/**
 * Arma los `items` que Zipnova necesita para cotizar o crear un envío a partir de los artículos
 * de un carrito o pedido (misión zipnova-envios, 14/9/2026).
 *
 * Unidades: `articles.peso` está en KILOGRAMOS y `alto/ancho/profundidad` en CENTÍMETROS (son los
 * campos que sincroniza Tienda Nube, `TiendaNubeProductService`). Zipnova pide `weight` en GRAMOS
 * entero (10 a 10.000.000) y `height/width/length` en cm entero (1 a 5000). Un artículo sin peso o
 * medidas (o con cero) usa el bulto por defecto del comercio; si el comercio tampoco cargó uno, el
 * fallback fijo de `BULTO_FALLBACK`. Mejor una cotización aproximada que ninguna: el comercio ve en
 * la tarjeta de la integración que le conviene cargar los datos reales.
 *
 * Una línea = un artículo con su cantidad. Zipnova quiere unidades individuales y las empaqueta
 * sola, así que el ítem se repite `amount` veces (tope de 1000 ítems por cotización). Los
 * artículos con `requires_shipping = 0` (digitales, servicios) no viajan; `null` cuenta como "sí
 * requiere envío" porque es el valor que tienen todos los artículos cargados antes de que
 * existiera el campo.
 *
 * No se manda `sku`: Zipnova lo buscaría en SU catálogo (que no se sincroniza) y, si no lo
 * encuentra, exige las medidas igual.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api`. Se compara token a token.
 */
class ZipnovaPaquetesHelper
{
    /** Bulto que se usa cuando ni el artículo ni el comercio tienen medidas: 500 g, 10×10×10 cm. */
    const BULTO_FALLBACK = ['peso' => 0.5, 'alto' => 10, 'ancho' => 10, 'profundidad' => 10];

    /** Límites de Zipnova por ítem. */
    const PESO_MIN_GRAMOS = 10;
    const PESO_MAX_GRAMOS = 10000000;
    const MEDIDA_MIN_CM = 1;
    const MEDIDA_MAX_CM = 5000;

    /** Tope de ítems por cotización/envío que acepta Zipnova. */
    const MAX_ITEMS = 1000;

    /** Largo máximo de la descripción de un ítem. */
    const MAX_DESCRIPCION = 60;

    /**
     * Ítems para Zipnova a partir de las líneas.
     *
     * @param array $lineas Cada una `['article' => objeto con peso/alto/ancho/profundidad/requires_shipping/name, 'amount' => int]`.
     * @param array $bulto_default `{peso (kg), alto, ancho, profundidad (cm)}` del comercio; puede venir incompleto.
     * @return array Lista de `{weight, height, width, length, description}`; vacía si nada requiere envío.
     */
    public static function items_desde_lineas(array $lineas, array $bulto_default = [])
    {
        $bulto = self::bulto_default_completo($bulto_default);
        $items = [];

        foreach ($lineas as $linea) {
            if (!isset($linea['article']) || !is_object($linea['article'])) {
                continue;
            }
            $article = $linea['article'];
            $amount = isset($linea['amount']) ? (int) $linea['amount'] : 1;
            if ($amount < 1) {
                continue;
            }
            if (!self::requiere_envio($article)) {
                continue;
            }

            $item = self::item_desde_articulo($article, $bulto);

            for ($i = 0; $i < $amount; $i++) {
                if (count($items) >= self::MAX_ITEMS) {
                    break 2;
                }
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Un ítem de Zipnova a partir de un artículo, con el bulto por defecto como respaldo campo
     * por campo (un artículo puede tener el peso cargado y las medidas no).
     *
     * @param object $article
     * @param array $bulto Bulto por defecto ya completo (ver `bulto_default_completo`).
     * @return array `{weight, height, width, length, description}`
     */
    public static function item_desde_articulo($article, array $bulto)
    {
        $peso_kg = self::valor_o_default(isset($article->peso) ? $article->peso : null, $bulto['peso']);
        $alto = self::valor_o_default(isset($article->alto) ? $article->alto : null, $bulto['alto']);
        $ancho = self::valor_o_default(isset($article->ancho) ? $article->ancho : null, $bulto['ancho']);
        $largo = self::valor_o_default(isset($article->profundidad) ? $article->profundidad : null, $bulto['profundidad']);

        $nombre = isset($article->name) ? trim((string) $article->name) : '';
        if ($nombre === '') {
            $nombre = 'Artículo';
        }

        return [
            'weight'      => self::gramos($peso_kg),
            'height'      => self::centimetros($alto),
            'width'       => self::centimetros($ancho),
            'length'      => self::centimetros($largo),
            'description' => mb_substr($nombre, 0, self::MAX_DESCRIPCION),
        ];
    }

    /**
     * Completa el bulto por defecto del comercio con el fallback fijo, campo por campo.
     *
     * @param array $bulto_default
     * @return array `{peso, alto, ancho, profundidad}` con los cuatro valores numéricos y positivos.
     */
    public static function bulto_default_completo(array $bulto_default)
    {
        $bulto = [];
        foreach (self::BULTO_FALLBACK as $campo => $fallback) {
            $bulto[$campo] = self::valor_o_default(isset($bulto_default[$campo]) ? $bulto_default[$campo] : null, $fallback);
        }

        return $bulto;
    }

    /**
     * True si el artículo viaja: `requires_shipping` null o distinto de 0.
     *
     * @param object $article
     * @return bool
     */
    public static function requiere_envio($article)
    {
        if (!isset($article->requires_shipping) || is_null($article->requires_shipping)) {
            return true;
        }

        return (int) $article->requires_shipping !== 0;
    }

    /**
     * Hash de las líneas (artículo + cantidad), para saber si hace falta re-cotizar. No entra el
     * precio: un cambio de precio no cambia el paquete.
     *
     * @param array $lineas Mismo formato que `items_desde_lineas`.
     * @return string md5.
     */
    public static function hash_de_lineas(array $lineas)
    {
        $partes = [];
        foreach ($lineas as $linea) {
            if (!isset($linea['article']) || !is_object($linea['article'])) {
                continue;
            }
            $id = isset($linea['article']->id) ? (string) $linea['article']->id : '0';
            $amount = isset($linea['amount']) ? (int) $linea['amount'] : 1;
            $partes[] = $id . 'x' . $amount;
        }
        sort($partes);

        return md5(implode(',', $partes));
    }

    /**
     * Kilogramos (decimal) a gramos enteros dentro de los límites de Zipnova.
     *
     * @param float $kg
     * @return int
     */
    public static function gramos($kg)
    {
        $gramos = (int) round(((float) $kg) * 1000);

        return max(self::PESO_MIN_GRAMOS, min(self::PESO_MAX_GRAMOS, $gramos));
    }

    /**
     * Centímetros (decimal) a entero, redondeando hacia arriba, dentro de los límites de Zipnova.
     *
     * @param float $cm
     * @return int
     */
    public static function centimetros($cm)
    {
        $entero = (int) ceil((float) $cm);

        return max(self::MEDIDA_MIN_CM, min(self::MEDIDA_MAX_CM, $entero));
    }

    /**
     * El valor si es numérico y positivo; si no, el default.
     *
     * @param mixed $valor
     * @param float $default
     * @return float
     */
    protected static function valor_o_default($valor, $default)
    {
        if (is_numeric($valor) && (float) $valor > 0) {
            return (float) $valor;
        }

        return (float) $default;
    }
}
