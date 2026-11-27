<?php

namespace App\Http\Controllers\Helpers;

use Carbon\Carbon;

/**
 * Validación del rango de fechas (Desde / Hasta) que mandan los reportes de la pantalla Reportes
 * (misión soporte-t177, ticket 177).
 *
 * 🔴 POR QUÉ EXISTE. Antes ningún endpoint validaba las fechas: un texto cualquiera (`abc`,
 * `undefined`) llegaba a `Carbon::parse()` y devolvía 500 ("Server Error"), `2026-02-30` se corría
 * en silencio a marzo y un "Desde" posterior a "Hasta" se invertía sin avisar. Ahora cada endpoint
 * llama a `validar()` al principio y, si hay un problema, responde 422 con un mensaje en castellano
 * que dice qué hacer.
 *
 * 🔴 POR QUÉ NO `$request->validate()`. (Razón fuerte documentada, regla de CLAUDE.md.) Las fechas
 * de `api/company-performance/{desde}/{hasta}` llegan como parámetros de RUTA, no del request; el
 * mensaje es de negocio (qué hacer) y no de campo; y el mismo chequeo lo usan cinco controllers.
 * La respuesta lleva `errors` con el formato de Laravel para que el interceptor de la SPA la
 * reconozca como validación.
 *
 * Solo lectura de parámetros: no toca la base ni la sesión (cron-safe).
 */
class RangoFechasReporteHelper
{
    /** Primer año aceptado (un año anterior es siempre un error de tipeo en este sistema). */
    const ANIO_MINIMO = 2000;

    /** Último año aceptado (el input de fecha de Chrome deja escribir años de cinco dígitos). */
    const ANIO_MAXIMO = 2100;

    /** Tope de días de Gráficos (company-performance): un año calendario, incluso bisiesto. */
    const MAX_DIAS_GRAFICOS = 366;

    /**
     * Valida un rango de fechas.
     *
     * Reglas, en orden (devuelve el primer problema que encuentra):
     *  1. "Desde" y "Hasta" con formato exacto AAAA-MM-DD, fecha que existe en el calendario y año
     *     entre 2000 y 2100.
     *  2. "Desde" no posterior a "Hasta".
     *  3. Si se pasa `$max_dias`, el rango (ambos días inclusive) no puede tenerlos de más.
     *
     * @param  mixed    $desde    Valor recibido para "Desde" (puede ser null, texto, etc.).
     * @param  mixed    $hasta    Valor recibido para "Hasta".
     * @param  int|null $max_dias Tope de días del rango (inclusive) o null si no hay tope.
     * @return \Illuminate\Http\JsonResponse|null null si el rango es válido; si no, la respuesta 422
     *                                            `{message, errors: {desde|hasta|rango: [mensaje]}}`.
     */
    public static function validar($desde, $hasta, $max_dias = null)
    {
        if (!self::fecha_es_valida($desde)) {

            return self::respuesta_422(
                'desde',
                'La fecha "Desde" no es válida. Elegila de nuevo en el calendario (día/mes/año).'
            );
        }

        if (!self::fecha_es_valida($hasta)) {

            return self::respuesta_422(
                'hasta',
                'La fecha "Hasta" no es válida. Elegila de nuevo en el calendario (día/mes/año).'
            );
        }

        // El formato AAAA-MM-DD ordena igual como texto que como fecha.
        if (strcmp($desde, $hasta) > 0) {

            return self::respuesta_422(
                'rango',
                'La fecha "Desde" es posterior a "Hasta". Cambiá una de las dos y volvé a buscar.'
            );
        }

        if (!is_null($max_dias)) {

            // Días entre las dos fechas, contando ambas puntas (un solo día = 1).
            $dias_del_rango = Carbon::createFromFormat('Y-m-d', $desde)->startOfDay()
                ->diffInDays(Carbon::createFromFormat('Y-m-d', $hasta)->startOfDay()) + 1;

            if ($dias_del_rango > $max_dias) {

                return self::respuesta_422(
                    'rango',
                    'Los gráficos se calculan hasta 12 meses por búsqueda. Elegí un rango de 12 meses o menos (el Estado de Resultados sí acepta rangos más largos).'
                );
            }
        }

        return null;
    }

    /**
     * Indica si un valor es una fecha AAAA-MM-DD real (existe en el calendario) con año entre
     * ANIO_MINIMO y ANIO_MAXIMO.
     *
     * @param  mixed $fecha
     * @return bool
     */
    protected static function fecha_es_valida($fecha)
    {
        if (!is_string($fecha) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $partes)) {

            return false;
        }

        $anio = (int) $partes[1];
        $mes  = (int) $partes[2];
        $dia  = (int) $partes[3];

        if ($anio < self::ANIO_MINIMO || $anio > self::ANIO_MAXIMO) {

            return false;
        }

        // checkdate descarta el 30 de febrero y el mes 13 (que Carbon::parse corría o rompía).
        return checkdate($mes, $dia, $anio);
    }

    /**
     * Arma la respuesta 422 con `message` y `errors` (formato de validación de Laravel).
     *
     * @param  string $campo   Clave dentro de `errors`: 'desde', 'hasta' o 'rango'.
     * @param  string $mensaje Texto en castellano para el usuario.
     * @return \Illuminate\Http\JsonResponse
     */
    protected static function respuesta_422($campo, $mensaje)
    {
        return response()->json([
            'message' => $mensaje,
            'errors'  => [$campo => [$mensaje]],
        ], 422);
    }
}
