<?php

namespace App\Services\Zipnova;

/**
 * Convierte la respuesta cruda de `POST /shipments/quote` de Zipnova en la lista de opciones que
 * entienden la tienda y el ERP (misión zipnova-envios, 14/9/2026).
 *
 * Forma de cada opción (es la que se persiste en `carts.envio_opcion` / `orders.envio_opcion` y
 * la que viaja al SPA, en español, mismas claves en los dos proyectos):
 *
 *   key               "56|standard_delivery|carrier_dropoff" (carrier_id|service_type|logistic_type)
 *   carrier_id, carrier_name, carrier_logo
 *   service_type      código que después se manda al crear el envío (ej. standard_delivery)
 *   service_name      nombre legible (ej. "Envío a domicilio")
 *   logistic_type     forma de despacho (carrier_dropoff, crossdock, ...)
 *   es_punto_de_retiro true cuando service_type es pickup_point; point_id lo completa el SPA
 *   precio            lo que paga el comprador (amounts.price_incl_tax), ya con envío gratis aplicado
 *   precio_original   el mismo sin aplicar envío gratis
 *   envio_gratis      bool
 *   estimated_delivery fecha máxima de entrega (ISO 8601) o null
 *   dias_min, dias_max días hábiles (de delivery_time.times.total, o de los min/max deprecados)
 *   tags              ["cheapest", "fastest"] tal como los manda Zipnova
 *   puntos_de_retiro  hasta 5 sucursales cuando es_punto_de_retiro (point_id, description, dirección, horario)
 *
 * Se toman los `all_results` con `selectable = true` (los `results` son un subconjunto: la mejor
 * opción por tipo de servicio, y el comprador quiere ver TODOS los correos) y se ordenan por
 * precio y después por días. Los no seleccionables se descartan: Zipnova ya dice por qué en
 * `impediments`, pero al comprador no le sirve una opción que no puede elegir.
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api`. Se compara token a token.
 */
class ZipnovaQuoteNormalizer
{
    /** Código de servicio de Zipnova para "retiro en sucursal del correo". */
    const SERVICE_PICKUP_POINT = 'pickup_point';

    /**
     * Normaliza la respuesta de cotización.
     *
     * @param array $respuesta Respuesta cruda de Zipnova.
     * @param bool $envio_gratis Si aplica la regla de envío gratis del comercio (precio 0 para el comprador).
     * @return array `{zipcode, city, state, envio_gratis, opciones: [...]}`
     */
    public static function normalizar(array $respuesta, $envio_gratis = false)
    {
        $destino = isset($respuesta['destination']) && is_array($respuesta['destination']) ? $respuesta['destination'] : [];

        $crudas = [];
        if (isset($respuesta['all_results']) && is_array($respuesta['all_results'])) {
            $crudas = $respuesta['all_results'];
        } elseif (isset($respuesta['results']) && is_array($respuesta['results'])) {
            $crudas = array_values($respuesta['results']);
        }

        $opciones = [];
        foreach ($crudas as $cruda) {
            if (!is_array($cruda)) {
                continue;
            }
            if (!isset($cruda['selectable']) || !$cruda['selectable']) {
                continue;
            }
            $opcion = self::opcion_desde_resultado($cruda, (bool) $envio_gratis);
            if (!is_null($opcion)) {
                $opciones[] = $opcion;
            }
        }

        usort($opciones, function ($a, $b) {
            if ($a['precio'] != $b['precio']) {
                return $a['precio'] < $b['precio'] ? -1 : 1;
            }
            $dias_a = is_null($a['dias_min']) ? PHP_INT_MAX : $a['dias_min'];
            $dias_b = is_null($b['dias_min']) ? PHP_INT_MAX : $b['dias_min'];
            if ($dias_a != $dias_b) {
                return $dias_a < $dias_b ? -1 : 1;
            }

            return strcmp($a['key'], $b['key']);
        });

        return [
            'zipcode'      => isset($destino['zipcode']) ? (string) $destino['zipcode'] : null,
            'city'         => isset($destino['city']) ? (string) $destino['city'] : null,
            'state'        => isset($destino['state']) ? (string) $destino['state'] : null,
            'envio_gratis' => (bool) $envio_gratis,
            'opciones'     => $opciones,
        ];
    }

    /**
     * Arma la clave estable de una opción. Es lo que el SPA devuelve al elegir y lo que el
     * servidor busca al re-cotizar: no depende del orden de la lista ni del precio.
     *
     * @param int|string $carrier_id
     * @param string $service_type
     * @param string $logistic_type
     * @return string
     */
    public static function key($carrier_id, $service_type, $logistic_type)
    {
        return (string) $carrier_id . '|' . (string) $service_type . '|' . (string) $logistic_type;
    }

    /**
     * Busca una opción por su `key` en una lista ya normalizada.
     *
     * @param array $opciones
     * @param string $key
     * @return array|null
     */
    public static function buscar(array $opciones, $key)
    {
        foreach ($opciones as $opcion) {
            if (isset($opcion['key']) && (string) $opcion['key'] === (string) $key) {
                return $opcion;
            }
        }

        return null;
    }

    /**
     * Una opción normalizada a partir de un elemento de `all_results`.
     *
     * @param array $r
     * @param bool $envio_gratis
     * @return array|null null si al resultado le faltan los datos mínimos (carrier, servicio, precio).
     */
    protected static function opcion_desde_resultado(array $r, $envio_gratis)
    {
        $carrier = isset($r['carrier']) && is_array($r['carrier']) ? $r['carrier'] : [];
        $service = isset($r['service_type']) && is_array($r['service_type']) ? $r['service_type'] : [];
        $amounts = isset($r['amounts']) && is_array($r['amounts']) ? $r['amounts'] : [];
        $tiempo = isset($r['delivery_time']) && is_array($r['delivery_time']) ? $r['delivery_time'] : [];

        if (!isset($carrier['id']) || !isset($service['code'])) {
            return null;
        }

        $precio_original = null;
        if (isset($amounts['price_incl_tax']) && is_numeric($amounts['price_incl_tax'])) {
            $precio_original = round((float) $amounts['price_incl_tax'], 2);
        } elseif (isset($amounts['price']) && is_numeric($amounts['price'])) {
            $precio_original = round((float) $amounts['price'], 2);
        }
        if (is_null($precio_original)) {
            return null;
        }

        $logistic_type = isset($r['logistic_type']) ? (string) $r['logistic_type'] : '';
        $service_type = (string) $service['code'];
        $es_punto_de_retiro = $service_type === self::SERVICE_PICKUP_POINT;

        $dias = self::dias_habiles($tiempo);

        $tags = [];
        if (isset($r['tags']) && is_array($r['tags'])) {
            foreach ($r['tags'] as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }

        return [
            'key'                => self::key($carrier['id'], $service_type, $logistic_type),
            'carrier_id'         => (int) $carrier['id'],
            'carrier_name'       => isset($carrier['name']) ? (string) $carrier['name'] : '',
            'carrier_logo'       => isset($carrier['logo']) && is_string($carrier['logo']) ? $carrier['logo'] : null,
            'service_type'       => $service_type,
            'service_name'       => isset($service['name']) ? (string) $service['name'] : $service_type,
            'logistic_type'      => $logistic_type,
            'es_punto_de_retiro' => $es_punto_de_retiro,
            'point_id'           => null,
            'precio'             => $envio_gratis ? 0.0 : $precio_original,
            'precio_original'    => $precio_original,
            'envio_gratis'       => (bool) $envio_gratis,
            'estimated_delivery' => isset($tiempo['estimated_delivery']) && is_string($tiempo['estimated_delivery']) ? $tiempo['estimated_delivery'] : null,
            'dias_min'           => $dias['min'],
            'dias_max'           => $dias['max'],
            'tags'               => $tags,
            'puntos_de_retiro'   => $es_punto_de_retiro ? self::puntos_de_retiro($r) : [],
        ];
    }

    /**
     * Días hábiles mínimos y máximos de una opción. Primero `times.total.{min,max}` (duraciones
     * ISO 8601, `P3D` = 3 días), después los `min`/`max` que la doc marca como deprecados.
     *
     * @param array $tiempo `delivery_time` de Zipnova.
     * @return array{min: int|null, max: int|null}
     */
    protected static function dias_habiles(array $tiempo)
    {
        $min = null;
        $max = null;

        if (isset($tiempo['times']['total']) && is_array($tiempo['times']['total'])) {
            $total = $tiempo['times']['total'];
            $min = isset($total['min']) ? self::duracion_a_dias($total['min']) : null;
            $max = isset($total['max']) ? self::duracion_a_dias($total['max']) : null;
        } elseif (isset($tiempo['times']['total']) && is_string($tiempo['times']['total'])) {
            $min = self::duracion_a_dias($tiempo['times']['total']);
            $max = $min;
        }

        if (is_null($min) && isset($tiempo['min']) && is_numeric($tiempo['min'])) {
            $min = (int) $tiempo['min'];
        }
        if (is_null($max) && isset($tiempo['max']) && is_numeric($tiempo['max'])) {
            $max = (int) $tiempo['max'];
        }
        if (is_null($max) && !is_null($min)) {
            $max = $min;
        }
        if (is_null($min) && !is_null($max)) {
            $min = $max;
        }

        return ['min' => $min, 'max' => $max];
    }

    /**
     * Duración ISO 8601 a días enteros (`P3D` → 3, `P1W` → 7, `P0D` → 0). Las horas se ignoran.
     *
     * @param mixed $duracion
     * @return int|null
     */
    public static function duracion_a_dias($duracion)
    {
        if (is_numeric($duracion)) {
            return (int) $duracion;
        }
        if (!is_string($duracion) || !preg_match('/^P(?:(\d+)W)?(?:(\d+)D)?/i', $duracion, $m)) {
            return null;
        }

        $semanas = isset($m[1]) && $m[1] !== '' ? (int) $m[1] : 0;
        $dias = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;

        return $semanas * 7 + $dias;
    }

    /**
     * Sucursales de retiro de una opción `pickup_point`, aplanadas para el SPA.
     *
     * @param array $r
     * @return array
     */
    protected static function puntos_de_retiro(array $r)
    {
        if (!isset($r['pickup_points']) || !is_array($r['pickup_points'])) {
            return [];
        }

        $puntos = [];
        foreach ($r['pickup_points'] as $punto) {
            if (!is_array($punto) || !isset($punto['point_id'])) {
                continue;
            }
            $location = isset($punto['location']) && is_array($punto['location']) ? $punto['location'] : [];
            $puntos[] = [
                'point_id'      => (int) $punto['point_id'],
                'description'   => isset($punto['description']) ? (string) $punto['description'] : '',
                'open_hours'    => isset($punto['open_hours']) && is_string($punto['open_hours']) ? $punto['open_hours'] : null,
                'phone'         => isset($punto['phone']) && is_string($punto['phone']) ? $punto['phone'] : null,
                'street'        => isset($location['street']) ? (string) $location['street'] : '',
                'street_number' => isset($location['street_number']) ? (string) $location['street_number'] : '',
                'city'          => isset($location['city']) ? (string) $location['city'] : '',
                'state'         => isset($location['state']) ? (string) $location['state'] : '',
                'zipcode'       => isset($location['zipcode']) && is_string($location['zipcode']) ? $location['zipcode'] : null,
            ];
        }

        return $puntos;
    }
}
