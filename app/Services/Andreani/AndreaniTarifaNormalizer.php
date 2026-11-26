<?php

namespace App\Services\Andreani;

/**
 * Cálculo puro de Andreani (misión envios-andreani, 25/11/2026): de los ítems de un carrito a los bultos que se
 * cotizan, los topes de paquetería, las sucursales de retiro y la opción de envío que ve el comprador. Sin HTTP y
 * sin base: todo estático, como `ZipnovaQuoteNormalizer`.
 *
 * 🔴 POR QUÉ LOS TOPES VIVEN ACÁ: el cotizador público de Andreani NO rechaza por tamaño (medido el 25/11/2026:
 * 5 m3 y 80 kg dan 200 con el mismo precio). Si no los aplicáramos nosotros, la tienda ofrecería un envío que
 * Andreani después no puede hacer (más de 50 kg, un lado de más de 165 cm o suma de lados de más de 300 cm ya es
 * otro servicio, "Bigger", fuera del alcance de esta integración).
 *
 * La opción que se devuelve tiene la MISMA forma que las de `ZipnovaQuoteNormalizer` (la tienda las pinta igual)
 * más las claves aditivas `proveedor` y `andreani`. El número de cliente y el contrato NO viajan en ella: son
 * datos comerciales del comercio y el ERP los lee de su tarjeta.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api` (solo cambia el `namespace`/`use`). Se compara con `diff`.
 */
class AndreaniTarifaNormalizer
{
    /** Valor de `proveedor` de las opciones y de `orders.envio_proveedor`. */
    const PROVEEDOR = 'andreani';

    /** Tipos de servicio. */
    const TIPO_DOMICILIO = 'domicilio';
    const TIPO_SUCURSAL = 'sucursal';

    /** Máximo de bultos por consulta de tarifa (con 30 Andreani responde 200, se midió el 25/11/2026). */
    const MAX_BULTOS = 30;

    /** Topes de paquetería por bulto. */
    const MAX_KILOS = 50;
    const MAX_LADO_CM = 165;
    const MAX_SUMA_LADOS_CM = 300;

    /** Máximo de sucursales de retiro que se ofrecen. */
    const MAX_PUNTOS_DE_RETIRO = 5;

    /**
     * El código postal numérico de 4 dígitos que Andreani entiende, sacado de lo que escribió o eligió el comprador.
     *
     * 🔴 Andreani responde 400 al CPA (`cpDestino=X5000ABC` y `codigoPostal=X5000ABC`; medido: `cpDestino=1425` da
     * 200), y Google Places devuelve justamente el CPA ("X5000ABC", "C1425ABC") o letra + 4 dígitos ("X5000").
     * Zipnova sí acepta el CPA: este recorte vale SÓLO para lo que se le manda a Andreani. Si no tiene forma de CP
     * argentino (ni 4 dígitos ni letra + 4 dígitos + 3 letras) se devuelve limpio y en mayúsculas, sin adivinar
     * dígitos: Andreani lo rechaza con su 400 y la cotización lo informa como `sin_tarifa`.
     *
     * @param mixed $codigo_postal "5000", "X5000", "X5000ABC", " x5000-abc "...
     * @return string Los 4 dígitos ("5000"), o el texto limpio si no tiene esa forma ('' si no es texto ni número).
     */
    public static function codigo_postal_numerico($codigo_postal)
    {
        if (!is_scalar($codigo_postal)) {
            return '';
        }

        // Sin espacios ni signos, en mayúsculas (el teclado del teléfono agrega " " y "-").
        $limpio = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $codigo_postal));

        if (preg_match('/^[A-Z]?(\d{4})(?:[A-Z]{3})?$/', $limpio, $partes)) {
            return $partes[1];
        }

        return $limpio;
    }

    /**
     * Pasa los ítems de Zipnova (gramos y cm) a bultos de Andreani (kilos, cm y volumen en cm3).
     *
     * El valor declarado total se reparte por unidad con 2 decimales y el resto del redondeo cae en el último
     * bulto, así la suma da exactamente el total.
     *
     * @param array $items_zipnova Lista de `{weight (g), height, width, length (cm), description}`.
     * @param float|int $valor_declarado_total Valor declarado de toda la compra.
     * @return array `{bultos: [{kilos, alto_cm, ancho_cm, largo_cm, volumen_cm3, valor_declarado}], motivo: null|'demasiados_bultos'}`
     */
    public static function bultos_desde_items(array $items_zipnova, $valor_declarado_total)
    {
        $cantidad = count($items_zipnova);

        if ($cantidad > self::MAX_BULTOS) {
            return ['bultos' => [], 'motivo' => 'demasiados_bultos'];
        }

        $total = max(0, (float) $valor_declarado_total);
        $por_unidad = $cantidad > 0 ? round($total / $cantidad, 2) : 0;

        $bultos = [];
        $indice = 0;

        foreach ($items_zipnova as $item) {
            $indice++;

            $alto = (int) (isset($item['height']) ? $item['height'] : 0);
            $ancho = (int) (isset($item['width']) ? $item['width'] : 0);
            $largo = (int) (isset($item['length']) ? $item['length'] : 0);
            $gramos = (float) (isset($item['weight']) ? $item['weight'] : 0);

            // El último bulto se lleva el resto del redondeo.
            $valor = $indice === $cantidad ? round($total - $por_unidad * ($cantidad - 1), 2) : $por_unidad;

            $bultos[] = [
                'kilos'           => round($gramos / 1000, 3),
                'alto_cm'         => $alto,
                'ancho_cm'        => $ancho,
                'largo_cm'        => $largo,
                'volumen_cm3'     => $alto * $ancho * $largo,
                'valor_declarado' => $valor,
            ];
        }

        return ['bultos' => $bultos, 'motivo' => null];
    }

    /**
     * True si el bulto pasa algún tope de Andreani: más de 50 kg, algún lado de más de 165 cm o suma de lados de
     * más de 300 cm. Justo en el borde entra (100x100x100 suma 300).
     *
     * @param array $bulto `{kilos, alto_cm, ancho_cm, largo_cm}`.
     * @return bool
     */
    public static function excede_limites(array $bulto)
    {
        $kilos = (float) (isset($bulto['kilos']) ? $bulto['kilos'] : 0);
        $lados = [
            (float) (isset($bulto['alto_cm']) ? $bulto['alto_cm'] : 0),
            (float) (isset($bulto['ancho_cm']) ? $bulto['ancho_cm'] : 0),
            (float) (isset($bulto['largo_cm']) ? $bulto['largo_cm'] : 0),
        ];

        if ($kilos > self::MAX_KILOS) {
            return true;
        }

        if (max($lados) > self::MAX_LADO_CM) {
            return true;
        }

        return array_sum($lados) > self::MAX_SUMA_LADOS_CM;
    }

    /**
     * Bultos con las claves que pide la API de tarifas (`valorDeclarado`, `volumen`, `kilos`).
     *
     * @param array $bultos Bultos de `bultos_desde_items`.
     * @param bool $declarar_valor Si es false el valor declarado va en 0 (el comercio no asegura la mercadería).
     * @return array
     */
    public static function bultos_para_consulta(array $bultos, $declarar_valor = true)
    {
        $consulta = [];

        foreach ($bultos as $bulto) {
            $consulta[] = [
                'valorDeclarado' => $declarar_valor ? round((float) $bulto['valor_declarado'], 2) : 0,
                'volumen'        => (int) $bulto['volumen_cm3'],
                'kilos'          => (float) $bulto['kilos'],
            ];
        }

        return $consulta;
    }

    /**
     * Precio con IVA de una respuesta de tarifa (`tarifaConIva.total`), o null si no vino.
     *
     * @param array $tarifa Respuesta cruda de `GET /v1/tarifas`.
     * @return float|null
     */
    public static function precio_de_tarifa(array $tarifa)
    {
        if (!isset($tarifa['tarifaConIva']['total']) || !is_numeric($tarifa['tarifaConIva']['total'])) {
            return null;
        }

        return round((float) $tarifa['tarifaConIva']['total'], 2);
    }

    /**
     * Sucursales que sirven para retiro: las que entregan envíos Y atienden al cliente, con la forma de
     * `puntos_de_retiro` de las opciones de Zipnova. Como mucho 5.
     *
     * @param array $sucursales Lista cruda de `GET /v2/sucursales`.
     * @return array Lista de `{point_id, description, open_hours, phone, street, street_number, city, state, zipcode}`.
     */
    public static function puntos_de_retiro(array $sucursales)
    {
        $puntos = [];

        foreach ($sucursales as $sucursal) {
            if (!is_array($sucursal) || !isset($sucursal['id'])) {
                continue;
            }

            $adicionales = isset($sucursal['datosAdicionales']) && is_array($sucursal['datosAdicionales']) ? $sucursal['datosAdicionales'] : [];

            if (empty($adicionales['entregaEnvios']) || empty($adicionales['seHaceAtencionAlCliente'])) {
                continue;
            }

            $direccion = isset($sucursal['direccion']) && is_array($sucursal['direccion']) ? $sucursal['direccion'] : [];
            $telefonos = isset($sucursal['telefonos']) && is_array($sucursal['telefonos']) ? array_values($sucursal['telefonos']) : [];

            $puntos[] = [
                'point_id'      => (int) $sucursal['id'],
                'description'   => isset($sucursal['descripcion']) ? (string) $sucursal['descripcion'] : '',
                'open_hours'    => isset($sucursal['horarioDeAtencion']) ? (string) $sucursal['horarioDeAtencion'] : null,
                'phone'         => count($telefonos) > 0 ? (string) $telefonos[0] : null,
                'street'        => isset($direccion['calle']) ? trim((string) $direccion['calle']) : null,
                'street_number' => isset($direccion['numero']) ? trim((string) $direccion['numero']) : null,
                'city'          => isset($direccion['localidad']) ? (string) $direccion['localidad'] : null,
                'state'         => isset($direccion['provincia']) ? (string) $direccion['provincia'] : null,
                'zipcode'       => isset($direccion['codigoPostal']) ? (string) $direccion['codigoPostal'] : null,
            ];

            if (count($puntos) >= self::MAX_PUNTOS_DE_RETIRO) {
                break;
            }
        }

        return $puntos;
    }

    /**
     * La opción de envío de Andreani, con la forma de las de Zipnova más `proveedor` y `andreani`.
     *
     * @param string $tipo `domicilio` o `sucursal`.
     * @param array $tarifa Respuesta cruda de `GET /v1/tarifas`.
     * @param bool $envio_gratis Si aplica la regla de envío gratis del comercio (precio 0 para el comprador).
     * @param array $puntos Puntos de retiro (sólo para `sucursal`).
     * @param array $bultos Bultos de `bultos_desde_items`.
     * @param float|int $valor_declarado_total Valor declarado de toda la compra.
     * @return array|null null si la tarifa no trae precio.
     */
    public static function opcion($tipo, array $tarifa, $envio_gratis, array $puntos, array $bultos, $valor_declarado_total)
    {
        $precio_original = self::precio_de_tarifa($tarifa);

        if (is_null($precio_original)) {
            return null;
        }

        $es_sucursal = $tipo === self::TIPO_SUCURSAL;

        return [
            'key'                => self::PROVEEDOR . '|' . $tipo,
            'proveedor'          => self::PROVEEDOR,
            'carrier_id'         => null,
            'carrier_name'       => 'Andreani',
            'carrier_logo'       => null,
            'service_type'       => $tipo,
            'service_name'       => $es_sucursal ? 'Retiro en sucursal Andreani' : 'Envío a domicilio',
            'logistic_type'      => self::PROVEEDOR,
            'es_punto_de_retiro' => $es_sucursal,
            'point_id'           => null,
            'precio'             => $envio_gratis ? 0.0 : $precio_original,
            'precio_original'    => $precio_original,
            'envio_gratis'       => (bool) $envio_gratis,
            // La tarifa no devuelve plazos: no se inventan.
            'estimated_delivery' => null,
            'dias_min'           => null,
            'dias_max'           => null,
            'tags'               => [],
            'puntos_de_retiro'   => $es_sucursal ? array_values($puntos) : [],
            'andreani'           => [
                'tipo'                  => $tipo,
                'bultos'                => array_values($bultos),
                'valor_declarado_total' => round((float) $valor_declarado_total, 2),
            ],
        ];
    }
}
