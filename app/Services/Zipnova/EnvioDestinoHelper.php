<?php

namespace App\Services\Zipnova;

/**
 * El destino de un envío (destinatario + dirección) tal como lo guardan `carts.envio_destino`,
 * `orders.envio_destino` y `envios.destino`, y su traducción a lo que Zipnova pide
 * (misión zipnova-envios, 14/9/2026).
 *
 * Claves (en español, mismas en los dos proyectos):
 *   nombre, apellido, documento, email, telefono,
 *   calle, numero, piso_depto, localidad, provincia, codigo_postal, referencia,
 *   lat, lng, point_id
 *
 * 🔴 ARCHIVO ESPEJO ENTRE `empresa-api` Y `tienda-api`. Se compara token a token.
 */
class EnvioDestinoHelper
{
    /** Claves conocidas del destino, en el orden en que se guardan. */
    const CLAVES = [
        'nombre', 'apellido', 'documento', 'email', 'telefono',
        'calle', 'numero', 'piso_depto', 'localidad', 'provincia', 'codigo_postal', 'referencia',
        'lat', 'lng', 'point_id',
    ];

    /** Campos que siempre tienen que venir. */
    const OBLIGATORIOS = ['nombre', 'documento', 'email', 'telefono', 'localidad', 'provincia', 'codigo_postal'];

    /** Campos que además hacen falta cuando el envío va a domicilio (no a una sucursal). */
    const OBLIGATORIOS_DOMICILIO = ['calle', 'numero'];

    /**
     * Largo máximo de cada campo de texto. El destino lo escribe un endpoint público en un json
     * del carrito y después en `orders.address` (TEXT): sin tope, un body de 100 KB en
     * `referencia` es bloat gratis por request y un "Data too long" al crear el pedido.
     */
    const LARGOS = [
        'nombre'        => 80,
        'apellido'      => 80,
        'documento'     => 11,
        'email'         => 120,
        'telefono'      => 30,
        'calle'         => 120,
        'numero'        => 20,
        'piso_depto'    => 40,
        'localidad'     => 80,
        'provincia'     => 80,
        'codigo_postal' => 8,
        'referencia'    => 200,
    ];

    /**
     * Deja solo las claves conocidas, recortadas y con los tipos que corresponden.
     *
     * @param array $destino
     * @return array
     */
    public static function normalizar(array $destino)
    {
        $limpio = [];

        foreach (self::CLAVES as $clave) {
            $valor = isset($destino[$clave]) ? $destino[$clave] : null;

            if ($clave === 'lat' || $clave === 'lng') {
                $limpio[$clave] = is_numeric($valor) ? (float) $valor : null;
                continue;
            }
            if ($clave === 'point_id') {
                $limpio[$clave] = is_numeric($valor) && (int) $valor > 0 ? (int) $valor : null;
                continue;
            }

            $texto = is_scalar($valor) ? trim((string) $valor) : '';
            if ($clave === 'documento') {
                // "30.111.222" y "20-30111222-3" son la forma en que la gente escribe su DNI o
                // CUIT: se sacan puntos, guiones y espacios antes de validar los dígitos.
                $texto = preg_replace('/[\s.\-]/', '', $texto);
            }
            if ($clave === 'codigo_postal') {
                $texto = preg_replace('/\s+/', '', $texto);
            }
            if (isset(self::LARGOS[$clave])) {
                $texto = mb_substr($texto, 0, self::LARGOS[$clave]);
            }
            $limpio[$clave] = $texto === '' ? null : $texto;
        }

        return $limpio;
    }

    /**
     * Nombres de los campos obligatorios que faltan o son inválidos.
     *
     * @param array $destino Ya normalizado.
     * @param bool $es_punto_de_retiro Si el envío va a una sucursal del correo.
     * @return array Lista vacía cuando el destino está completo.
     */
    public static function faltantes(array $destino, $es_punto_de_retiro = false)
    {
        $faltan = [];

        $obligatorios = self::OBLIGATORIOS;
        if (!$es_punto_de_retiro) {
            $obligatorios = array_merge($obligatorios, self::OBLIGATORIOS_DOMICILIO);
        }

        foreach ($obligatorios as $clave) {
            if (!isset($destino[$clave]) || $destino[$clave] === '' || is_null($destino[$clave])) {
                $faltan[] = $clave;
            }
        }

        if (isset($destino['documento']) && !is_null($destino['documento']) && !preg_match('/^\d{7,11}$/', $destino['documento'])) {
            $faltan[] = 'documento';
        }
        if (isset($destino['email']) && !is_null($destino['email']) && filter_var($destino['email'], FILTER_VALIDATE_EMAIL) === false) {
            $faltan[] = 'email';
        }
        if (isset($destino['telefono']) && !is_null($destino['telefono']) && strlen(preg_replace('/\D/', '', $destino['telefono'])) < 8) {
            $faltan[] = 'telefono';
        }
        if ($es_punto_de_retiro && (!isset($destino['point_id']) || is_null($destino['point_id']))) {
            $faltan[] = 'point_id';
        }

        return array_values(array_unique($faltan));
    }

    /**
     * Objeto `destination` para `POST /shipments` de Zipnova.
     *
     * @param array $destino Ya normalizado y completo.
     * @param bool $es_punto_de_retiro
     * @return array
     */
    public static function a_zipnova(array $destino, $es_punto_de_retiro = false)
    {
        $extras = trim(self::texto($destino, 'piso_depto') . ' ' . self::texto($destino, 'referencia'));

        $destination = [
            'name'     => trim(self::texto($destino, 'nombre') . ' ' . self::texto($destino, 'apellido')),
            'document' => self::texto($destino, 'documento'),
            'email'    => self::texto($destino, 'email'),
            'phone'    => self::texto($destino, 'telefono'),
            'city'     => self::texto($destino, 'localidad'),
            'state'    => self::texto($destino, 'provincia'),
            'zipcode'  => self::texto($destino, 'codigo_postal'),
        ];

        if ($es_punto_de_retiro) {
            $destination['point_id'] = isset($destino['point_id']) ? (int) $destino['point_id'] : null;
        } else {
            $destination['street'] = self::texto($destino, 'calle');
            $destination['street_number'] = self::texto($destino, 'numero');
            if ($extras !== '') {
                $destination['street_extras'] = mb_substr($extras, 0, 120);
            }
        }

        return $destination;
    }

    /**
     * La dirección en una sola línea, para `orders.address` (el ERP viejo la muestra tal cual),
     * los mails y el WhatsApp del pedido.
     *
     * @param array $destino
     * @return string
     */
    public static function como_texto(array $destino)
    {
        $direccion = trim(self::texto($destino, 'calle') . ' ' . self::texto($destino, 'numero') . ' ' . self::texto($destino, 'piso_depto'));
        $direccion = preg_replace('/\s+/', ' ', $direccion);

        $partes = [];
        if ($direccion !== '') {
            $partes[] = $direccion;
        }
        if (self::texto($destino, 'localidad') !== '') {
            $partes[] = self::texto($destino, 'localidad');
        }
        $provincia = self::texto($destino, 'provincia');
        $cp = self::texto($destino, 'codigo_postal');
        if ($provincia !== '' || $cp !== '') {
            $partes[] = trim($provincia . ($cp !== '' ? ' (CP ' . $cp . ')' : ''));
        }

        $persona = trim(self::texto($destino, 'nombre') . ' ' . self::texto($destino, 'apellido'));
        $datos = [];
        if ($persona !== '') {
            $datos[] = $persona;
        }
        if (self::texto($destino, 'documento') !== '') {
            $datos[] = 'DNI ' . self::texto($destino, 'documento');
        }
        if (self::texto($destino, 'telefono') !== '') {
            $datos[] = 'tel ' . self::texto($destino, 'telefono');
        }

        $texto = implode(', ', $partes);
        if (count($datos) > 0) {
            $texto .= ($texto !== '' ? ' — ' : '') . implode(', ', $datos);
        }
        if (self::texto($destino, 'referencia') !== '') {
            $texto .= ' (' . self::texto($destino, 'referencia') . ')';
        }

        return $texto;
    }

    /**
     * Valor del destino como texto recortado, o cadena vacía.
     *
     * @param array $destino
     * @param string $clave
     * @return string
     */
    protected static function texto(array $destino, $clave)
    {
        if (!isset($destino[$clave]) || is_null($destino[$clave]) || !is_scalar($destino[$clave])) {
            return '';
        }

        return trim((string) $destino[$clave]);
    }
}
