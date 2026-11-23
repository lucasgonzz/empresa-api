<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use Carbon\Carbon;

/**
 * Cuándo es la próxima vez que se despierta una tarea del asistente (misión agente-tareas-programadas,
 * 21/11/2026). Es PURO: no toca la base ni lee el reloj; recibe «ahora» (o la ocurrencia anterior) y la tarea
 * como array (o como modelo) y devuelve un Carbon en la zona de la app, o null si ya no hay próxima.
 *
 * Todo se calcula en America/Argentina/Buenos_Aires (la zona de la app, config/app.php): el dueño dice «a las
 * 9» y es a las 9 de Argentina aunque el servidor esté en UTC.
 *
 * La lengua natural («el viernes que viene», «a la tarde», «todos los días a primera hora») la interpreta el
 * modelo con la lista de días que ya tiene el prompt; este helper sólo recibe fecha, hora, franja y días ya
 * resueltos.
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class ProximaEjecucionIaHelper
{
    /** La zona horaria con la que se calcula todo. */
    const ZONA = 'America/Argentina/Buenos_Aires';

    /** Hasta cuántos días hacia adelante se busca una ocurrencia (alcanza de sobra para «un día a la semana»). */
    const DIAS_DE_BUSQUEDA = 400;

    /** Horarios de fábrica de cada franja, y la hora preferida si no hay franja ni hora. */
    const HORARIOS_DE_FABRICA = [
        'manana'         => '09:00',
        'mediodia'       => '13:00',
        'tarde'          => '17:00',
        'noche'          => '20:30',
        'hora_preferida' => '09:00',
    ];

    /**
     * Los horarios efectivos del dueño: los de fábrica pisados por lo que guardó.
     *
     * @param  array|null  $guardados  El json decodificado de `users.agente_horarios` (o null).
     * @return array{manana: string, mediodia: string, tarde: string, noche: string, hora_preferida: string, dias_sin_mensajes: array<int,int>}
     */
    public static function horarios($guardados)
    {
        $horarios = self::HORARIOS_DE_FABRICA;

        $dias_sin_mensajes = [];

        if (is_array($guardados)) {

            foreach (self::HORARIOS_DE_FABRICA as $clave => $hora) {

                if (isset($guardados[$clave]) && self::es_hora((string) $guardados[$clave])) {

                    $horarios[$clave] = self::normalizar_hora((string) $guardados[$clave]);
                }
            }

            if (isset($guardados['dias_sin_mensajes']) && is_array($guardados['dias_sin_mensajes'])) {

                $dias_sin_mensajes = self::dias_validos($guardados['dias_sin_mensajes']);
            }
        }

        $horarios['dias_sin_mensajes'] = $dias_sin_mensajes;

        return $horarios;
    }

    /**
     * La hora de la tarea: la explícita gana; si no, la de la franja con los horarios del dueño; si no, la preferida.
     *
     * @param  string|null  $franja  manana|mediodia|tarde|noche o null.
     * @param  string|null  $hora  "HH:MM" o null.
     * @param  array  $horarios  Lo que devuelve horarios().
     * @return string  "HH:MM".
     */
    public static function hora_de($franja, $hora, array $horarios)
    {
        if (!is_null($hora) && self::es_hora((string) $hora)) {

            return self::normalizar_hora((string) $hora);
        }

        if (!is_null($franja) && isset($horarios[(string) $franja]) && in_array((string) $franja, ['manana', 'mediodia', 'tarde', 'noche'], true)) {

            return $horarios[(string) $franja];
        }

        return $horarios['hora_preferida'];
    }

    /**
     * La primera ejecución de una tarea recién creada o reprogramada.
     *
     * Una vez: la fecha de inicio a su hora (que sea futura lo valida quien la crea). Recurrente: la primera
     * ocurrencia posterior a «ahora» desde la fecha de inicio.
     *
     * @param  array|object  $tarea
     * @param  \Carbon\Carbon  $ahora
     * @param  array  $dias_sin_mensajes  Días ISO en los que el dueño no quiere mensajes (sólo cuentan en las diarias).
     * @return \Carbon\Carbon|null
     */
    public static function primera($tarea, Carbon $ahora, array $dias_sin_mensajes = [])
    {
        $datos = self::datos($tarea);

        if ($datos['recurrencia'] === 'una_vez') {

            if (is_null($datos['fecha_inicio'])) {

                return null;
            }

            return self::con_hora($datos['fecha_inicio'], $datos['hora']);
        }

        return self::siguiente($tarea, $ahora, $dias_sin_mensajes);
    }

    /**
     * La ocurrencia que sigue estrictamente a `$despues_de`, o null si no hay más (una vez, o pasada la fecha fin).
     *
     * @param  array|object  $tarea
     * @param  \Carbon\Carbon  $despues_de
     * @param  array  $dias_sin_mensajes
     * @return \Carbon\Carbon|null
     */
    public static function siguiente($tarea, Carbon $despues_de, array $dias_sin_mensajes = [])
    {
        $datos = self::datos($tarea);

        if ($datos['recurrencia'] === 'una_vez') {

            return null;
        }

        $despues_de = $despues_de->copy()->setTimezone(self::ZONA);

        $dia = $despues_de->copy()->startOfDay();

        if (!is_null($datos['fecha_inicio']) && $datos['fecha_inicio']->gt($dia)) {

            $dia = $datos['fecha_inicio']->copy();
        }

        for ($i = 0; $i < self::DIAS_DE_BUSQUEDA; $i++, $dia->addDay()) {

            if (!is_null($datos['fecha_fin']) && $dia->gt($datos['fecha_fin'])) {

                return null;
            }

            $iso = (int) $dia->dayOfWeekIso;

            if ($datos['recurrencia'] === 'diaria' && in_array($iso, $dias_sin_mensajes, true)) {

                continue;
            }

            if (in_array($datos['recurrencia'], ['dias_semana', 'semanal'], true) && !in_array($iso, $datos['dias'], true)) {

                continue;
            }

            $candidata = self::con_hora($dia, $datos['hora']);

            if ($candidata->gt($despues_de)) {

                return $candidata;
            }
        }

        return null;
    }

    /**
     * true si es "H:MM" o "HH:MM" válida.
     *
     * @param  string  $hora
     * @return bool
     */
    public static function es_hora($hora)
    {
        return (bool) preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim((string) $hora));
    }

    /**
     * "9:05" → "09:05".
     *
     * @param  string  $hora
     * @return string
     */
    public static function normalizar_hora($hora)
    {
        $partes = explode(':', trim((string) $hora));

        return str_pad($partes[0], 2, '0', STR_PAD_LEFT) . ':' . $partes[1];
    }

    /**
     * Los días ISO (1..7) válidos de una lista, sin repetir y ordenados.
     *
     * @param  array  $dias
     * @return array<int,int>
     */
    public static function dias_validos(array $dias)
    {
        $validos = [];

        foreach ($dias as $dia) {

            if (is_numeric($dia) && (int) $dia >= 1 && (int) $dia <= 7) {

                $validos[(int) $dia] = (int) $dia;
            }
        }

        sort($validos);

        return array_values($validos);
    }

    /**
     * Los campos de la tarea que importan para el cálculo, ya normalizados.
     *
     * @param  array|object  $tarea
     * @return array{recurrencia: string, dias: array<int,int>, hora: string, fecha_inicio: \Carbon\Carbon|null, fecha_fin: \Carbon\Carbon|null}
     */
    protected static function datos($tarea)
    {
        $a = is_array($tarea) ? $tarea : $tarea->getAttributes();

        $dias = isset($a['dias']) && trim((string) $a['dias']) !== '' ? self::dias_validos(explode(',', (string) $a['dias'])) : [];

        return [
            'recurrencia'  => isset($a['recurrencia']) ? (string) $a['recurrencia'] : 'una_vez',
            'dias'         => $dias,
            'hora'         => isset($a['hora']) && self::es_hora((string) $a['hora']) ? self::normalizar_hora((string) $a['hora']) : '09:00',
            'fecha_inicio' => self::dia_de($a, 'fecha_inicio'),
            'fecha_fin'    => self::dia_de($a, 'fecha_fin'),
        ];
    }

    /**
     * Un campo de fecha de la tarea como Carbon a las 00:00 de la zona de la app, o null.
     *
     * @param  array  $atributos
     * @param  string  $campo
     * @return \Carbon\Carbon|null
     */
    protected static function dia_de(array $atributos, $campo)
    {
        if (!isset($atributos[$campo]) || trim((string) $atributos[$campo]) === '') {

            return null;
        }

        return Carbon::parse(substr((string) $atributos[$campo], 0, 10), self::ZONA)->startOfDay();
    }

    /**
     * El día dado a la hora dada ("HH:MM"), en la zona de la app.
     *
     * @param  \Carbon\Carbon  $dia
     * @param  string  $hora
     * @return \Carbon\Carbon
     */
    protected static function con_hora(Carbon $dia, $hora)
    {
        $partes = explode(':', $hora);

        return Carbon::create((int) $dia->year, (int) $dia->month, (int) $dia->day, (int) $partes[0], (int) $partes[1], 0, self::ZONA);
    }
}
