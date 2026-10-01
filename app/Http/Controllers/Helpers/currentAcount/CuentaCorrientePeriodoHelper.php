<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Models\CurrentAcount;
use Carbon\Carbon;

/**
 * Período de una cuenta corriente (misión cuenta-corriente-periodo, 1/10/2026).
 *
 * El listado de la cuenta corriente y el PDF de "Resumen / Con desglose" tienen que mostrar
 * EXACTAMENTE los mismos movimientos cuando el usuario elige un período; si cada uno armara su
 * consulta por su lado, el PDF y la pantalla se desalinean con el primer cambio. Por eso la lógica
 * vive acá y los dos controladores solo la llaman.
 *
 * 🔴 Los límites del período son por DATETIME (`created_at >= 'desde 00:00:00'` y
 * `created_at <= 'hasta 23:59:59'`), NO con `whereDate()`: `whereDate` envuelve la columna en
 * DATE(), la consulta deja de usar el índice `cc_cuenta_orden_idx (credit_account_id,
 * is_provisorio, created_at, id)` y en las cuentas con decenas de miles de movimientos
 * (Fenix, Ferretotal) vuelve a leer la tabla entera.
 *
 * Igual que el listado de siempre, NO filtra `is_provisorio`: este endpoint hoy devuelve también los
 * provisorios y el período no cambia qué filas se ven, solo cuántas.
 */
class CuentaCorrientePeriodoHelper {

    /**
     * Tope de filas del listado por período. La SPA renderiza todo lo que recibe, y un período
     * ancho en una cuenta con decenas de miles de movimientos (el "Todo" de Fenix) la dejaría
     * colgada. Cuando se pasa, se devuelven las más recientes y `periodo.truncado` avisa.
     */
    const LIMITE_LISTADO = 2000;

    /**
     * Tope vigente del listado. Es una propiedad y no solo la constante para que los tests puedan
     * bajarlo sin sembrar 2000 filas; NO se expone por query, nadie de afuera lo puede cambiar.
     *
     * @var int
     */
    public static $limite_listado = self::LIMITE_LISTADO;

    /**
     * Parsea una fecha en formato estricto `Y-m-d`. Cualquier otra cosa (vacío, otro formato, un
     * array de la query, un día que no existe como 2026-02-30) devuelve null: el llamador lo trata
     * como "no vino" y se queda con el comportamiento de siempre.
     *
     * @param  mixed  $valor
     * @return string|null  La misma fecha normalizada (`Y-m-d`) o null.
     */
    static function fecha($valor) {

        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        $fecha = Carbon::createFromFormat('!Y-m-d', $valor);

        // createFromFormat() "arregla" 2026-02-30 a marzo; si al volver a formatear no da lo mismo,
        // la fecha no existía.
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            return null;
        }

        return $valor;
    }

    /**
     * Entero >= 1 o null. Es el mínimo de movimientos que la carga por defecto quiere mostrar.
     *
     * @param  mixed  $valor
     * @return int|null
     */
    static function minimo($valor) {

        if (is_null($valor) || $valor === '' || is_array($valor) || !is_numeric($valor)) {
            return null;
        }

        $entero = (int) $valor;

        return $entero >= 1 ? $entero : null;
    }

    /**
     * Los movimientos de una cuenta en un período, del más nuevo al más viejo (`created_at DESC,
     * id DESC`), con la ampliación por `minimo` si se pidió.
     *
     * Ampliación: si en [desde, hasta] hay menos de `minimo` movimientos, se toman los `minimo` más
     * recientes con `created_at <= hasta`, el período se corre al día del más viejo de esos y se
     * vuelve a consultar desde ese día. Se consulta por DÍA y no por cantidad para no partir un día
     * por la mitad: si el movimiento número `minimo` comparte día con otros, entran todos. Si la
     * cuenta entera tiene menos de `minimo`, devuelve todos los que haya hasta `hasta`.
     *
     * @param  int          $credit_account_id
     * @param  string       $desde   `Y-m-d` ya validado con fecha().
     * @param  string|null  $hasta   `Y-m-d` ya validado, o null para no poner tope superior.
     * @param  int|null     $minimo  Cantidad mínima, o null para mostrar exactamente el período.
     * @param  array        $with    Relaciones a cargar.
     * @param  int|null     $limite  Tope de filas, o null para no ponerlo (el PDF imprime todo el
     *                               período). Si hay más, se devuelven las `$limite` más recientes y
     *                               `periodo.truncado` queda en true.
     * @return array  ['models' => Collection, 'periodo' => ['desde', 'hasta', 'ampliado', 'cantidad', 'truncado']]
     */
    static function consultar($credit_account_id, $desde, $hasta = null, $minimo = null, $with = [], $limite = null) {

        $ampliado = false;

        if (!is_null($minimo)) {

            $en_el_rango = self::base($credit_account_id, $desde, $hasta)->count();

            if ($en_el_rango < $minimo) {

                $recientes = self::base($credit_account_id, null, $hasta)
                                    ->orderBy('created_at', 'DESC')
                                    ->orderBy('id', 'DESC')
                                    ->take($minimo)
                                    ->pluck('created_at');

                if ($recientes->count()) {

                    $dia_del_mas_viejo = Carbon::parse($recientes->last())->format('Y-m-d');

                    // Solo se corre hacia atrás: si toda la cuenta cabe en el rango no hay nada que
                    // ampliar, y desde nunca avanza.
                    if ($dia_del_mas_viejo < $desde) {
                        $desde = $dia_del_mas_viejo;
                        $ampliado = true;
                    }
                }
            }
        }

        $consulta = self::base($credit_account_id, $desde, $hasta)
                        ->orderBy('created_at', 'DESC')
                        ->orderBy('id', 'DESC')
                        ->with($with);

        $truncado = false;

        if (!is_null($limite)) {

            // Una fila de más: es lo que dice si había más que el tope, sin un COUNT aparte.
            $models = $consulta->take($limite + 1)->get();

            if ($models->count() > $limite) {
                $models = $models->take($limite)->values();
                $truncado = true;
            }

        } else {
            $models = $consulta->get();
        }

        return [
            'models'    => $models,
            'periodo'   => [
                'desde'     => $desde,
                'hasta'     => $hasta,
                'ampliado'  => $ampliado,
                'cantidad'  => $models->count(),
                'truncado'  => $truncado,
            ],
        ];
    }

    /**
     * Consulta base de la cuenta con los límites por datetime.
     *
     * @param  int          $credit_account_id
     * @param  string|null  $desde
     * @param  string|null  $hasta
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected static function base($credit_account_id, $desde, $hasta) {

        $query = CurrentAcount::where('credit_account_id', $credit_account_id);

        if (!is_null($desde)) {
            $query->where('created_at', '>=', $desde.' 00:00:00');
        }

        if (!is_null($hasta)) {
            $query->where('created_at', '<=', $hasta.' 23:59:59');
        }

        return $query;
    }
}
