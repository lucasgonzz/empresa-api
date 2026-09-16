<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Http\Controllers\Helpers\Numbers;
use Carbon\Carbon;

/**
 * Cómo escribe fechas y montos el asistente de IA en las tarjetas, en las respuestas de sus
 * herramientas y en el prompt (misión asistente-ia-acciones, 15/9/2026).
 *
 * Vive en un solo lugar porque la tarjeta, el resumen que lee la IA y la lista de días del prompt
 * tienen que decir lo mismo: si una dijera "viernes 18/09/2026" y otra "18-09", la IA terminaría
 * mezclando formatos frente a la persona.
 */
class FormatoIaHelper {

    /**
     * Nombres de los días indexados por Carbon::dayOfWeek (0 = domingo).
     *
     * 🔴 Van escritos a mano y NO salen de `translatedFormat()` ni del locale: el locale de PHP en
     * los servidores no está garantizado, y un "Friday" en el prompt es justo lo que hace que la IA
     * calcule mal "este viernes" (la captura del pedido: el 15/9/2026 es martes y contestó
     * "viernes 19/09", que es sábado).
     *
     * @var array<int,string>
     */
    const DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    /**
     * Moneda en dólares (monedas.id). Pesos es 1, y una moneda null se trata como pesos.
     */
    const MONEDA_DOLARES = 2;

    /**
     * "martes"
     *
     * @param  \Carbon\Carbon  $fecha
     * @return string
     */
    static function dia_de_la_semana(Carbon $fecha) {

        return self::DIAS[(int) $fecha->dayOfWeek];
    }

    /**
     * "viernes 18/09/2026"
     *
     * @param  \Carbon\Carbon  $fecha
     * @return string
     */
    static function fecha_con_dia(Carbon $fecha) {

        return self::dia_de_la_semana($fecha).' '.$fecha->format('d/m/Y');
    }

    /**
     * Los próximos días después de `$hoy`, con su nombre: "miércoles 16/09, jueves 17/09, ...".
     * Es la lista que lleva el prompt para que "este viernes" o "el lunes" se conviertan contra
     * fechas ya calculadas y no contra la aritmética de la IA.
     *
     * @param  \Carbon\Carbon  $hoy
     * @param  int  $cantidad
     * @return string
     */
    static function proximos_dias(Carbon $hoy, $cantidad = 7) {

        $dias = [];

        for ($i = 1; $i <= $cantidad; $i++) {

            $dia = $hoy->copy()->startOfDay()->addDays($i);

            $dias[] = self::dia_de_la_semana($dia).' '.$dia->format('d/m');
        }

        return implode(', ', $dias);
    }

    /**
     * Los días ANTERIORES a `$hoy`, con su nombre: "lunes 14/09, domingo 13/09, ...". Existe por el
     * mismo motivo que proximos_dias(): el prompt manda convertir "ayer" o "el viernes pasado" contra
     * fechas ya calculadas, y sin esta lista la IA volvía a hacer la aritmética a mano (que es el
     * defecto que originó la misión: contestó "viernes 19/09" siendo el 15/09 martes).
     *
     * @param  \Carbon\Carbon  $hoy
     * @param  int  $cantidad
     * @return string
     */
    static function dias_anteriores(Carbon $hoy, $cantidad = 7) {

        $dias = [];

        for ($i = 1; $i <= $cantidad; $i++) {

            $dia = $hoy->copy()->startOfDay()->subDays($i);

            $dias[] = self::dia_de_la_semana($dia).' '.$dia->format('d/m');
        }

        return implode(', ', $dias);
    }

    /**
     * "$ 5.000" en pesos, "US$ 100" en dólares. El número sale de Numbers::price(), que es como lo
     * escribe el resto del sistema (sin decimales cuando son ,00).
     *
     * @param  float|int|string  $monto
     * @param  int|null  $moneda_id  2 = dólares; cualquier otro valor (o null) = pesos.
     * @return string
     */
    static function monto($monto, $moneda_id = null) {

        $prefijo = (int) $moneda_id === self::MONEDA_DOLARES ? 'US$ ' : '$ ';

        /*
         * En dolares se muestran SIEMPRE los dos decimales, como en el resto del sistema. No se
         * delega en Numbers::price($monto, false, $moneda_id) porque esa firma agrega su propio
         * simbolo ('$1.200' / 'USD 100,00'), distinto del que usa la tarjeta, y quedaba el signo
         * duplicado.
         */
        if ((int) $moneda_id === self::MONEDA_DOLARES) {

            return $prefijo.number_format(round((float) $monto, 2), 2, ',', '.');
        }

        return $prefijo.Numbers::price(round((float) $monto, 2));
    }

    /**
     * "cada 1 mes", "cada 2 semanas", "cada 3 días". La cantidad va siempre en número (también el 1)
     * para que la tarjeta diga exactamente lo que queda guardado en la regla.
     *
     * @param  int  $cantidad  cantidad_frecuencia.
     * @param  string  $slug  Slug de UnidadFrecuencia: day | week | month | year.
     * @return string
     */
    static function repeticion($cantidad, $slug) {

        $nombres = [
            'day'   => ['día', 'días'],
            'week'  => ['semana', 'semanas'],
            'month' => ['mes', 'meses'],
            'year'  => ['año', 'años'],
        ];

        $cantidad = (int) $cantidad;

        $par = isset($nombres[$slug]) ? $nombres[$slug] : [(string) $slug, (string) $slug];

        return 'cada '.$cantidad.' '.($cantidad === 1 ? $par[0] : $par[1]);
    }

    /**
     * "pesos" o "dólares" para una moneda_id (null = pesos).
     *
     * @param  int|null  $moneda_id
     * @return string
     */
    static function nombre_de_moneda($moneda_id) {

        return (int) $moneda_id === self::MONEDA_DOLARES ? 'dólares' : 'pesos';
    }
}
