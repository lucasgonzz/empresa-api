<?php

namespace App\Services\OfertasClientes;

use Illuminate\Support\Facades\DB;

/**
 * ¿Este cliente paga bien o mal? Modificador del motor de ofertas (§A.5.4), no criterio de
 * selección: acá no se elige a nadie, se etiqueta.
 *
 * 🔴 SE REUSA EL ÚNICO CRITERIO DE "PAGA BIEN O MAL" QUE EL SISTEMA YA USA, el de
 * SaleController::ventas_sin_cobrar() (app/Http/Controllers/SaleController.php:764-823). Inventar
 * uno nuevo sería la clase de error que CriterioDePrecioHelper vino a cerrar: el mismo invariante
 * escrito dos veces con dos criterios distintos, y cada copia envejeciendo por su lado.
 *
 * Un cliente es MAL PAGADOR si tiene al menos una venta que cumple las dos condiciones:
 *   1. su current_acount tiene debe > 0, status IN ('sin_pagar','pagandose') y
 *      (pagandose IS NULL OR debe - pagandose > 300)          -> SaleController.php:789-796
 *   2. la venta tiene más de N días, con N = COALESCE(sales.dias_alerta_venta_no_cobrada_personalizado,
 *      users.dias_alertar_administradores_ventas_no_cobradas) -> SaleController.php:806-809
 *
 * 🔴 LO QUE NO EXISTE Y NO SE INVENTA (medido el 15/8/2026): no hay fecha de vencimiento en
 * current_acounts, no hay plazo ni días de crédito en clients ni en payment_methods, no hay mora y
 * no hay score crediticio. client_reputations es solo id + name, una etiqueta de texto libre que
 * carga el comerciante. clients.pagos_checkeados existe pero su escritura está casi toda
 * comentada: no se usa. Por eso LA ANTIGÜEDAD DE LA DEUDA SE CUENTA DESDE LA FECHA DE LA VENTA:
 * el vencimiento pactado no existe en el modelo de datos.
 *
 * 🔴 Y por eso mismo el resultado tiene TRES estados y no dos: true (paga al día), false (mal
 * pagador) y null (no hay ninguna venta en cuenta corriente suya: sin datos, que no es lo mismo
 * que paga bien). offer_suggestion_lines.es_buen_pagador es nullable justamente por esto.
 *
 * Este servicio no escribe nada en la base. PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class HistorialCrediticioService
{
    /**
     * El colchón de SaleController.php:795: una venta que se está pagando y a la que le quedan
     * menos de $300 no cuenta como impaga. Es el mismo número del original, a propósito.
     */
    const TOLERANCIA_PAGANDOSE = 300;

    /**
     * Días de alerta cuando el comercio no tiene cargado
     * users.dias_alertar_administradores_ventas_no_cobradas. Sin este default, un null convertía la
     * condición del paso 2 en "toda venta de hoy o antes está vencida": todos malos pagadores.
     */
    const DIAS_ALERTA_POR_DEFECTO = 30;

    /** @var mixed Dueño del comercio (el owner: de él sale el parámetro de días) */
    protected $user;

    /** @var int */
    protected $user_id;

    /**
     * @param mixed $user Dueño del comercio.
     */
    public function __construct($user)
    {
        $this->user    = $user;
        $this->user_id = (int) $user->id;
    }

    /**
     * @param  array $client_ids
     * @return array Mapa client_id => true (paga al día) | false (mal pagador) | null (sin datos)
     */
    public function evaluar(array $client_ids): array
    {
        $evaluacion = [];

        if (empty($client_ids)) {
            return $evaluacion;
        }

        $client_ids           = array_values(array_unique(array_map('intval', $client_ids)));
        $malos                = $this->malos_pagadores($client_ids);
        $con_cuenta_corriente = $this->clientes_con_cuenta_corriente($client_ids);

        foreach ($client_ids as $client_id) {
            if (in_array($client_id, $malos)) {
                $evaluacion[$client_id] = false;
                continue;
            }

            // Sin una sola venta en cuenta corriente no hay con qué opinar: null, no true.
            $evaluacion[$client_id] = in_array($client_id, $con_cuenta_corriente) ? true : null;
        }

        return $evaluacion;
    }

    /**
     * Los client_id que tienen al menos una venta vencida sin cobrar.
     *
     * @param  array $client_ids
     * @return array Lista de int
     */
    public function malos_pagadores(array $client_ids): array
    {
        if (empty($client_ids)) {
            return [];
        }

        $dias = $this->dias_de_alerta();

        $ids = $this->base($client_ids)
            /*
             * ⚠️ DIVERGENCIA INTENCIONAL CON EL ORIGINAL, Y ES UN ARREGLO, NO UN CAPRICHO.
             * SaleController.php:789-796 escribe este mismo bloque SIN agrupar el orWhere:
             *
             *     $q->where('debe', '>', 0)
             *       ->where('status', 'sin_pagar')
             *       ->orWhere('status', 'pagandose')
             *       ->where(function ($query) { ... });
             *
             * y SQL lo agrupa como (debe > 0 AND status='sin_pagar') OR (status='pagandose' AND (...)),
             * o sea que una venta 'pagandose' entra SIN exigir debe > 0. Acá se agrupa bien: debe > 0
             * vale para los dos estados. La divergencia es a propósito y está declarada en el
             * informe de la misión como hallazgo fuera de alcance.
             *
             * 🔴 SaleController.php NO se toca: es un archivo grande y compartido, y arreglarlo
             * excede el alcance de esta misión. Extraer el criterio a un helper y que los dos lo
             * usen es lo correcto a largo plazo y queda anotado como deuda.
             */
            ->where('current_acounts.debe', '>', 0)
            ->where(function ($sub) {
                $sub->where('current_acounts.status', 'sin_pagar')
                    ->orWhere('current_acounts.status', 'pagandose');
            })
            ->where(function ($sub) {
                $sub->whereNull('current_acounts.pagandose')
                    ->orWhereRaw('current_acounts.debe - current_acounts.pagandose > ?', [self::TOLERANCIA_PAGANDOSE]);
            })
            // Copia literal de SaleController.php:806-809, con el mismo COALESCE por venta.
            ->whereRaw(
                'DATE(`sales`.`created_at`) <= DATE_SUB(CURDATE(), INTERVAL COALESCE(`sales`.`dias_alerta_venta_no_cobrada_personalizado`, ?) DAY)',
                [$dias]
            )
            ->distinct()
            ->pluck('sales.client_id');

        return array_map('intval', $ids->all());
    }

    /**
     * Los client_id que tienen al menos una venta con cuenta corriente en este comercio, esté
     * pagada o no. Es lo que separa "paga al día" de "no tengo datos".
     *
     * @param  array $client_ids
     * @return array Lista de int
     */
    protected function clientes_con_cuenta_corriente(array $client_ids): array
    {
        $ids = $this->base($client_ids)->distinct()->pluck('sales.client_id');

        return array_map('intval', $ids->all());
    }

    /**
     * El join base: las ventas de ESTE comercio, de estos clientes, con su cuenta corriente.
     *
     * whereNull('sales.deleted_at') no está en el original porque ahí la consulta sale del modelo
     * Sale y el scope de SoftDeletes lo agrega solo; acá el FROM es la tabla cruda, así que hay que
     * escribirlo. Es el mismo comportamiento, no una condición de más.
     *
     * @param  array $client_ids
     * @return \Illuminate\Database\Query\Builder
     */
    protected function base(array $client_ids)
    {
        return DB::table('sales')
            ->join('current_acounts', 'current_acounts.sale_id', '=', 'sales.id')
            ->where('sales.user_id', $this->user_id)
            ->whereIn('sales.client_id', $client_ids)
            ->whereNotNull('sales.client_id')
            ->whereNull('sales.deleted_at')
            ->select('sales.client_id');
    }

    /**
     * N del paso 2. SaleController elige entre el parámetro de administradores y el de empleados
     * según quién esté mirando la pantalla; acá no hay nadie mirando —corre en un job— así que
     * manda siempre el del owner, que es el criterio más laxo de los dos y el que el propio
     * controller usa para el dueño (:773).
     *
     * @return int
     */
    protected function dias_de_alerta()
    {
        $dias = $this->user->dias_alertar_administradores_ventas_no_cobradas;

        if (is_null($dias) || (int) $dias <= 0) {
            return self::DIAS_ALERTA_POR_DEFECTO;
        }

        return (int) $dias;
    }
}
