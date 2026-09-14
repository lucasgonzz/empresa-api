<?php

namespace App\Http\Controllers\Helpers\agenda;

use App\Models\Pending;
use App\Models\PendingCompleted;
use Carbon\Carbon;

/**
 * Toda la lógica de ocurrencias de la Agenda (misión agenda-tareas-calendario, 14/9/2026), en un
 * solo lugar y sin `Request`: el controller parsea fechas y responde, acá se calcula.
 *
 * Una tarea recurrente es una REGLA (fecha base + cada N unidades + fin opcional); sus ocurrencias
 * no se guardan, se expanden al vuelo. Una ocurrencia se identifica por `pending_id` + fecha, y
 * está "hecha" si existe un PendingCompleted con ese `pending_id` y esa fecha en
 * `fecha_realizacion`.
 *
 * Todas las fechas se razonan por día (startOfDay) en la zona de la app
 * (America/Argentina/Buenos_Aires): la hora no forma parte del modelo.
 */
class AgendaHelper {

    /**
     * Máximo de ocurrencias vencidas que se devuelven por tarea. Sin tope, una tarea diaria
     * ignorada durante un año son 365 filas de ruido tapando todo lo demás; se devuelven las 30
     * más recientes y se informa cuántas quedaron afuera en `vencidas_omitidas`.
     */
    const TOPE_VENCIDAS_POR_TAREA = 30;

    /**
     * Unidades que sabe expandir, con el método de Carbon que suma cada una. Son los slugs de
     * UnidadFrecuenciaSeeder. Para mes y año se usan las variantes "NoOverflow": ver ocurrencia().
     *
     * @var array<string,string>
     */
    const METODO_POR_UNIDAD = [
        'day'   => 'addDays',
        'week'  => 'addWeeks',
        'month' => 'addMonthsNoOverflow',
        'year'  => 'addYearsNoOverflow',
    ];

    /**
     * Fecha de la k-ésima ocurrencia de una tarea (k = 0 es la fecha base).
     *
     * Se calcula SIEMPRE desde la fecha base multiplicando por k, nunca sumando una unidad sobre
     * la ocurrencia anterior. Iterar arrastra el desborde: 31/1 + 1 mes = 3/3 (febrero no tiene
     * 31), y de ahí en adelante la tarea "del 31" queda clavada en el 3 de cada mes. Con
     * addMonthsNoOverflow desde la base, "el 31 de cada mes" cae el 28/29 en febrero, el 30 en
     * abril y vuelve al 31 en mayo, que es lo que cualquiera entiende por "el 31 de cada mes".
     * Con año pasa lo mismo el 29/2. Para día y semana el desborde no existe, pero se calcula
     * igual desde la base para que las dos ramas sean la misma operación.
     *
     * @param  \App\Models\Pending  $pending
     * @param  int  $k
     * @return \Carbon\Carbon
     */
    static function ocurrencia($pending, $k, Carbon $base = null) {

        // La base se puede pasar ya parseada: en las caminatas se llama cientos de veces por
        // tarea y Carbon::parse en cada vuelta era lo que más pesaba.
        $fecha = is_null($base) ? self::fecha_base($pending) : $base->copy();

        if ($k <= 0 || !self::regla_valida($pending)) {

            return $fecha;
        }

        $metodo = self::METODO_POR_UNIDAD[$pending->unidad_frecuencia->slug];

        return $fecha->{$metodo}((int) $pending->cantidad_frecuencia * (int) $k);
    }

    /**
     * Primer k cuya ocurrencia PUEDE caer en o después de `$desde`, calculado y no caminado.
     *
     * Sin esto, las dos caminatas (rango y vencidas) arrancaban en k = 0: una tarea diaria
     * creada hace dos años eran ~700 vueltas por tarea, por request y por caminata. Se calcula
     * por debajo (nunca por encima): para día y semana la cuenta es exacta; para mes y año la
     * aritmética "sin desborde" puede mover el día, así que se resta uno y se deja que el loop
     * avance lo que falte. El loop de arriba sigue siendo el que decide qué entra: esto solo le
     * ahorra las vueltas que seguro quedan antes del rango.
     *
     * @param  \App\Models\Pending  $pending  Con la regla válida (ver regla_valida()).
     * @param  \Carbon\Carbon  $base
     * @param  \Carbon\Carbon  $desde
     * @return int
     */
    static function k_inicial($pending, Carbon $base, Carbon $desde) {

        if ($desde->lte($base)) {

            return 0;
        }

        $n = max(1, (int) $pending->cantidad_frecuencia);

        switch ($pending->unidad_frecuencia->slug) {

            case 'day':
                $k = intdiv($base->diffInDays($desde), $n);
                break;

            case 'week':
                $k = intdiv($base->diffInDays($desde), 7 * $n);
                break;

            case 'month':
                $k = intdiv($base->diffInMonths($desde), $n) - 1;
                break;

            case 'year':
                $k = intdiv($base->diffInYears($desde), $n) - 1;
                break;

            default:
                $k = 0;
        }

        return max(0, $k);
    }

    /**
     * ¿`$fecha` es una ocurrencia real de la tarea? Una puntual solo tiene su fecha base; una
     * recurrente, las que genera su regla (respetando el fin). Lo usa completar() para no dejar
     * un PendingCompleted colgado de una fecha que ningún cálculo va a mirar (la SPA nunca lo
     * manda; es defensa contra un llamador directo).
     *
     * @param  \App\Models\Pending  $pending
     * @param  \Carbon\Carbon  $fecha
     * @return bool
     */
    static function es_ocurrencia($pending, Carbon $fecha) {

        $fecha = $fecha->copy()->startOfDay();
        $base = self::fecha_base($pending);

        if (!self::regla_valida($pending)) {

            return $fecha->eq($base);
        }

        $fin = self::fecha_fin($pending);

        if (!is_null($fin) && $fecha->gt($fin)) {

            return false;
        }

        for ($k = self::k_inicial($pending, $base, $fecha); ; $k++) {

            $ocurrencia = self::ocurrencia($pending, $k, $base);

            if ($ocurrencia->eq($fecha)) {

                return true;
            }

            if ($ocurrencia->gt($fecha)) {

                return false;
            }
        }
    }

    /**
     * Ocurrencias de las tareas de un usuario cuya fecha cae en [desde, hasta] (inclusive): las
     * puntuales por su `fecha_realizacion` (aunque estén completadas, vienen con
     * `completado = true`), las recurrentes expandidas con ocurrencia() desde k = 0 mientras la
     * fecha no pase de `hasta` ni de `fecha_fin_recurrencia`.
     *
     * Ordenadas por fecha y después por id de la tarea.
     *
     * @param  int  $user_id
     * @param  \Carbon\Carbon  $desde
     * @param  \Carbon\Carbon  $hasta
     * @return array<int,array>
     */
    static function ocurrencias_entre($user_id, Carbon $desde, Carbon $hasta) {

        $desde = $desde->copy()->startOfDay();
        $hasta = $hasta->copy()->startOfDay();
        $hoy = Carbon::today();

        /*
         * La query acota lo que puede caer en el rango: una puntual por su fecha, una recurrente
         * si su base no es posterior al rango y su fin (si lo tiene) no es anterior. El resto de
         * la expansión se hace en memoria.
         */
        $pendings = Pending::where('user_id', $user_id)
                            ->where(function ($q) use ($desde, $hasta) {

                                $q->where(function ($q) use ($desde, $hasta) {

                                    $q->where('es_recurrente', 1)
                                        ->where('fecha_realizacion', '<=', $hasta->format('Y-m-d 23:59:59'))
                                        ->where(function ($q) use ($desde) {
                                            $q->whereNull('fecha_fin_recurrencia')
                                                ->orWhere('fecha_fin_recurrencia', '>=', $desde->format('Y-m-d'));
                                        });

                                })->orWhere(function ($q) use ($desde, $hasta) {

                                    $q->where(function ($q) {
                                            $q->whereNull('es_recurrente')
                                                ->orWhere('es_recurrente', 0);
                                        })
                                        ->whereBetween('fecha_realizacion', [
                                            $desde->format('Y-m-d 00:00:00'),
                                            $hasta->format('Y-m-d 23:59:59'),
                                        ]);
                                });
                            })
                            ->withAll()
                            ->orderBy('id')
                            ->get();

        $completadas = self::indice_de_completadas($user_id, $desde, $hasta);

        $ocurrencias = [];

        foreach ($pendings as $pending) {

            if (!self::regla_valida($pending)) {

                // Puntual (o recurrente con la regla rota: se la trata como puntual para que al
                // menos aparezca en su fecha base y se pueda corregir desde la interfaz).
                $fecha = self::fecha_base($pending);

                if ($fecha->between($desde, $hasta)) {

                    $ocurrencias[] = self::armar_ocurrencia($pending, $fecha, self::completada_de($completadas, $pending, $fecha), $hoy);
                }

                continue;
            }

            $fin = self::fecha_fin($pending);
            $base = self::fecha_base($pending);

            for ($k = self::k_inicial($pending, $base, $desde); ; $k++) {

                $fecha = self::ocurrencia($pending, $k, $base);

                if ($fecha->gt($hasta) || (!is_null($fin) && $fecha->gt($fin))) {

                    break;
                }

                if ($fecha->lt($desde)) {

                    continue;
                }

                $ocurrencias[] = self::armar_ocurrencia($pending, $fecha, self::completada_de($completadas, $pending, $fecha), $hoy);
            }
        }

        return self::ordenar($ocurrencias);
    }

    /**
     * Ocurrencias NO completadas con fecha anterior a `hoy`. Puntuales: `completado = 0` y
     * `fecha_realizacion < hoy`. Recurrentes: se camina desde k = 0 hasta la última ocurrencia
     * anterior a hoy (respetando `fecha_fin_recurrencia`) y quedan las que no tienen
     * PendingCompleted, con el tope de TOPE_VENCIDAS_POR_TAREA por tarea (las más recientes).
     *
     * @param  int  $user_id
     * @param  \Carbon\Carbon  $hoy
     * @return array<int,array>
     */
    static function vencidas($user_id, Carbon $hoy) {

        $hoy = $hoy->copy()->startOfDay();

        $pendings = Pending::where('user_id', $user_id)
                            ->where('fecha_realizacion', '<', $hoy->format('Y-m-d 00:00:00'))
                            ->where(function ($q) {

                                $q->where('es_recurrente', 1)
                                    ->orWhere(function ($q) {
                                        $q->where(function ($q) {
                                                $q->whereNull('es_recurrente')
                                                    ->orWhere('es_recurrente', 0);
                                            })
                                            ->where(function ($q) {
                                                $q->whereNull('completado')
                                                    ->orWhere('completado', 0);
                                            });
                                    });
                            })
                            ->withAll()
                            ->orderBy('id')
                            ->get();

        // Una sola query para todas las completadas anteriores a hoy, indexadas en memoria.
        $completadas = self::indice_de_completadas($user_id, null, $hoy->copy()->subDay());

        $vencidas = [];

        foreach ($pendings as $pending) {

            if (!self::regla_valida($pending)) {

                $fecha = self::fecha_base($pending);

                // Una puntual "completada" solo por PendingCompleted (sin el flag) no cuenta como
                // vencida: armar_ocurrencia() la marca hecha y acá se la deja afuera.
                $completada = self::completada_de($completadas, $pending, $fecha);

                if ($fecha->lt($hoy) && is_null($completada)) {

                    $vencidas[] = self::armar_ocurrencia($pending, $fecha, null, $hoy);
                }

                continue;
            }

            $fin = self::fecha_fin($pending);
            $base = self::fecha_base($pending);

            $de_esta_tarea = [];

            // Acá sí se arranca en k = 0: vencida es toda ocurrencia desde la base que no se
            // hizo, y la base es lo que la regla dice. Con la base ya parseada, cada vuelta es
            // una suma de Carbon y nada más.
            for ($k = 0; ; $k++) {

                $fecha = self::ocurrencia($pending, $k, $base);

                if ($fecha->gte($hoy) || (!is_null($fin) && $fecha->gt($fin))) {

                    break;
                }

                if (is_null(self::completada_de($completadas, $pending, $fecha))) {

                    $de_esta_tarea[] = self::armar_ocurrencia($pending, $fecha, null, $hoy);
                }
            }

            $omitidas = max(0, count($de_esta_tarea) - self::TOPE_VENCIDAS_POR_TAREA);

            if ($omitidas > 0) {

                // Las más recientes son las últimas del array (k creciente = fecha creciente).
                $de_esta_tarea = array_slice($de_esta_tarea, $omitidas);

                foreach ($de_esta_tarea as $i => $ocurrencia) {

                    $de_esta_tarea[$i]['vencidas_omitidas'] = $omitidas;
                }
            }

            foreach ($de_esta_tarea as $ocurrencia) {

                $vencidas[] = $ocurrencia;
            }
        }

        return self::ordenar($vencidas);
    }

    /**
     * Arma el array de una ocurrencia con la forma del contrato api ↔ spa (§2 del plan).
     *
     * @param  \App\Models\Pending  $pending  Con `unidad_frecuencia` y `expense_concept` cargados.
     * @param  \Carbon\Carbon  $fecha
     * @param  \App\Models\PendingCompleted|null  $completed  La completada de esa fecha, si existe.
     * @param  \Carbon\Carbon  $hoy
     * @return array
     */
    static function armar_ocurrencia($pending, Carbon $fecha, $completed, Carbon $hoy) {

        $es_recurrente = (bool) $pending->es_recurrente;

        /*
         * Una puntual está hecha si tiene su PendingCompleted O si tiene el flag `completado`: la
         * SPA vieja, al deshacer, borraba el PendingCompleted sin bajar el flag, así que hay filas
         * en producción con una sola de las dos marcas. Una recurrente solo por PendingCompleted.
         */
        $completado = !is_null($completed) || (!$es_recurrente && (bool) $pending->completado);

        $unidad = $pending->unidad_frecuencia;
        $concepto = $pending->expense_concept;

        return [
            'key'                   => $pending->id.'_'.$fecha->format('Y-m-d'),
            'pending_id'            => $pending->id,
            'detalle'               => $pending->detalle,
            'notas'                 => $pending->notas,
            'fecha'                 => $fecha->format('Y-m-d'),
            'es_recurrente'         => $es_recurrente,
            'unidad_frecuencia_id'  => is_null($pending->unidad_frecuencia_id) ? null : (int) $pending->unidad_frecuencia_id,
            'unidad_frecuencia'     => is_null($unidad) ? null : [
                'id'    => $unidad->id,
                'name'  => $unidad->name,
                'slug'  => $unidad->slug,
            ],
            'cantidad_frecuencia'   => is_null($pending->cantidad_frecuencia) ? null : (int) $pending->cantidad_frecuencia,
            'fecha_fin_recurrencia' => is_null($pending->fecha_fin_recurrencia) ? null : Carbon::parse($pending->fecha_fin_recurrencia)->format('Y-m-d'),
            'expense_concept_id'    => is_null($pending->expense_concept_id) ? null : (int) $pending->expense_concept_id,
            'expense_concept'       => is_null($concepto) ? null : [
                'id'    => $concepto->id,
                'name'  => $concepto->name,
            ],
            'expense_amount'        => is_null($pending->expense_amount) ? null : (float) $pending->expense_amount,
            'completado'            => $completado,
            'pending_completed_id'  => is_null($completed) ? null : $completed->id,
            'expense_id'            => is_null($completed) || is_null($completed->expense_id) ? null : (int) $completed->expense_id,
            'vencida'               => !$completado && $fecha->lt($hoy),
            'vencidas_omitidas'     => 0,
        ];
    }

    /**
     * Fecha base de la tarea (solo la fecha, sin hora).
     *
     * @param  \App\Models\Pending  $pending
     * @return \Carbon\Carbon
     */
    static function fecha_base($pending) {

        return Carbon::parse($pending->fecha_realizacion)->startOfDay();
    }

    /**
     * Fin de la recurrencia como fecha (startOfDay), o null si no tiene.
     *
     * @param  \App\Models\Pending  $pending
     * @return \Carbon\Carbon|null
     */
    static function fecha_fin($pending) {

        if (is_null($pending->fecha_fin_recurrencia)) {

            return null;
        }

        return Carbon::parse($pending->fecha_fin_recurrencia)->startOfDay();
    }

    /**
     * Una tarea se expande solo si es recurrente Y su regla está completa: unidad conocida y
     * cantidad ≥ 1. Con cantidad 0 el loop de expansión no avanzaría nunca; con una unidad
     * desconocida no habría cómo sumar. PendingController valida esto al guardar, pero las filas
     * de 2024 no pasaron por esa validación.
     *
     * @param  \App\Models\Pending  $pending
     * @return bool
     */
    static function regla_valida($pending) {

        if (!$pending->es_recurrente) {

            return false;
        }

        if (is_null($pending->unidad_frecuencia) || !isset(self::METODO_POR_UNIDAD[$pending->unidad_frecuencia->slug])) {

            return false;
        }

        return (int) $pending->cantidad_frecuencia >= 1;
    }

    /**
     * Todas las PendingCompleted del usuario con `fecha_realizacion` en [desde, hasta] (cualquiera
     * de los dos puede ser null = sin límite), indexadas por `pending_id + fecha`. Es UNA query por
     * rango en vez de una por ocurrencia: un mes de calendario con veinte tareas recurrentes son
     * cientos de ocurrencias, y una query por cada una era lo que hacía el index() de 2024.
     *
     * @param  int  $user_id
     * @param  \Carbon\Carbon|null  $desde
     * @param  \Carbon\Carbon|null  $hasta
     * @return array<string,\App\Models\PendingCompleted>
     */
    static function indice_de_completadas($user_id, $desde, $hasta) {

        $query = PendingCompleted::where('user_id', $user_id);

        if (!is_null($desde)) {

            $query->where('fecha_realizacion', '>=', $desde->format('Y-m-d 00:00:00'));
        }

        if (!is_null($hasta)) {

            $query->where('fecha_realizacion', '<=', $hasta->format('Y-m-d 23:59:59'));
        }

        $indice = [];

        foreach ($query->get() as $completada) {

            $clave = self::clave($completada->pending_id, Carbon::parse($completada->fecha_realizacion));

            // Si por algún motivo hubiera dos filas para la misma ocurrencia (el candado de
            // AgendaCompletarHelper lo impide de acá en adelante), gana la primera: la que
            // registró el gasto, si lo hubo.
            if (!isset($indice[$clave])) {

                $indice[$clave] = $completada;
            }
        }

        return $indice;
    }

    /**
     * @param  array<string,\App\Models\PendingCompleted>  $indice
     * @param  \App\Models\Pending  $pending
     * @param  \Carbon\Carbon  $fecha
     * @return \App\Models\PendingCompleted|null
     */
    static function completada_de($indice, $pending, Carbon $fecha) {

        $clave = self::clave($pending->id, $fecha);

        return isset($indice[$clave]) ? $indice[$clave] : null;
    }

    /**
     * @param  int  $pending_id
     * @param  \Carbon\Carbon  $fecha
     * @return string
     */
    static function clave($pending_id, Carbon $fecha) {

        return $pending_id.'_'.$fecha->format('Y-m-d');
    }

    /**
     * Por fecha ascendente y después por id de la tarea, para que dos ocurrencias del mismo día
     * salgan siempre en el mismo orden.
     *
     * @param  array<int,array>  $ocurrencias
     * @return array<int,array>
     */
    static function ordenar($ocurrencias) {

        usort($ocurrencias, function ($a, $b) {

            if ($a['fecha'] === $b['fecha']) {

                return $a['pending_id'] - $b['pending_id'];
            }

            return strcmp($a['fecha'], $b['fecha']);
        });

        return $ocurrencias;
    }
}
