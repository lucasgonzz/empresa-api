<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Models\CajaLiquidacionConfig;
use Illuminate\Support\Facades\DB;

/**
 * Grupo 223 · Prompt 02 (tesorería — lógica de liquidación y comisión).
 *
 * Helper PURO: resuelve la configuración de liquidación/comisión de una caja (con la cascada de
 * overrides por método de pago) y hace los cálculos de fecha de liquidación, neto y comisión.
 * No escribe nada en base de datos ni crea gastos — eso lo hace `MovimientoCajaHelper`, que es
 * quien decide (con el flag explícito `aplica_liquidacion`) si corresponde llamar a este helper.
 */
class CajaLiquidacionHelper {

    /**
     * Resuelve la configuración efectiva de liquidación/comisión para una caja y un método de
     * pago, aplicando la cascada de 2 niveles:
     *
     * 1) Si existe un override en `caja_liquidacion_configs` para (caja, método de pago) y el
     *    campo puntual no es null, se usa ese valor.
     * 2) Si no, se usa el valor por defecto de la caja (`cajas.<campo>`).
     * 3) Si el de la caja también es null, el campo queda en null (sin liquidación diferida /
     *    sin comisión, según corresponda).
     *
     * Importante: la cascada es POR CAMPO, no por registro completo. Un override que solo define
     * `comision_porcentaje` hereda `dias_liquidacion` de la caja. `0` es un valor explícito válido
     * y NO se hereda: un override con `dias_liquidacion = 0` significa liquidación inmediata para
     * ese método de pago, aunque la caja tenga otro valor.
     *
     * @param \App\Models\Caja $caja Caja dueña de la configuración por defecto.
     * @param int $current_acount_payment_method_id Método de pago con el que entró la plata.
     * @return array Array asociativo con los 5 parámetros resueltos: dias_liquidacion,
     *               comision_porcentaje, expense_concept_id, comision_iva_alicuota,
     *               comision_iva_incluido.
     */
    static function resolve_config($caja, $current_acount_payment_method_id) {

        // Override puntual de esta caja + método de pago, si fue configurado (puede no existir).
        $override = CajaLiquidacionConfig::where('caja_id', $caja->id)
                                            ->where('current_acount_payment_method_id', $current_acount_payment_method_id)
                                            ->first();

        return [
            // Días corridos hasta que la plata está disponible. null = liquidación inmediata.
            'dias_liquidacion' => (!is_null($override) && !is_null($override->dias_liquidacion))
                ? $override->dias_liquidacion
                : $caja->dias_liquidacion,

            // Porcentaje que se queda la entidad de cobro. null/0 = sin comisión.
            'comision_porcentaje' => (!is_null($override) && !is_null($override->comision_porcentaje))
                ? $override->comision_porcentaje
                : $caja->comision_porcentaje,

            // Concepto de gasto para el gasto automático de comisión. null = no se puede crear el gasto.
            'expense_concept_id' => (!is_null($override) && !is_null($override->expense_concept_id))
                ? $override->expense_concept_id
                : $caja->expense_concept_id,

            // Alícuota de IVA de la comisión (ej: 21).
            'comision_iva_alicuota' => (!is_null($override) && !is_null($override->comision_iva_alicuota))
                ? $override->comision_iva_alicuota
                : $caja->comision_iva_alicuota,

            // Si la comisión ya trae el IVA incluido (true) o es neto (false).
            'comision_iva_incluido' => (!is_null($override) && !is_null($override->comision_iva_incluido))
                ? $override->comision_iva_incluido
                : $caja->comision_iva_incluido,
        ];
    }

    /**
     * Calcula la fecha de liquidación estimada, el neto y la comisión de un ingreso de $monto,
     * ocurrido en $fecha, para la caja y método de pago dados. No persiste nada (helper puro):
     * quien llama decide qué hacer con el resultado.
     *
     * @param \App\Models\Caja $caja
     * @param int $current_acount_payment_method_id
     * @param float $monto Monto bruto del ingreso.
     * @param \Carbon\Carbon $fecha Fecha del movimiento (se usa como base para sumar los días de liquidación).
     * @return array Array con fecha_liquidacion_estimada (Carbon), monto_neto_estimado (float) y
     *               comision_calculada (float).
     */
    static function calcular($caja, $current_acount_payment_method_id, $monto, $fecha) {

        // Configuración efectiva ya resuelta por la cascada de 2 niveles.
        $config = Self::resolve_config($caja, $current_acount_payment_method_id);

        // Días corridos (no hábiles) hasta que la plata está disponible. null o 0 -> disponible ya,
        // la fecha de liquidación es la misma del movimiento.
        $dias_liquidacion = $config['dias_liquidacion'];
        if (is_null($dias_liquidacion) || (int) $dias_liquidacion === 0) {
            $fecha_liquidacion_estimada = $fecha->copy();
        } else {
            $fecha_liquidacion_estimada = $fecha->copy()->addDays((int) $dias_liquidacion);
        }

        // Comisión sobre el monto bruto. null o 0 -> sin comisión, el neto es igual al monto.
        $comision_porcentaje = $config['comision_porcentaje'];
        $comision_calculada = 0;
        if (!is_null($comision_porcentaje) && (float) $comision_porcentaje > 0) {
            $comision_calculada = Numbers::redondear(((float) $monto) * ((float) $comision_porcentaje) / 100);
        }

        // Redondeo final (no en los pasos intermedios).
        $monto_neto_estimado = Numbers::redondear(((float) $monto) - $comision_calculada);

        return [
            'fecha_liquidacion_estimada' => $fecha_liquidacion_estimada,
            'monto_neto_estimado'        => $monto_neto_estimado,
            'comision_calculada'         => $comision_calculada,
        ];
    }

    /**
     * Calcula el importe de IVA discriminado de una comisión, según si el porcentaje configurado
     * ya trae el IVA incluido o es neto.
     *
     * - Incluido: la comisión ES el total. importe_iva = comision * alicuota / (100 + alicuota).
     * - Neto: importe_iva = comision * alicuota / 100 (y el total a pagar sería comision + iva,
     *   eso lo resuelve quien arma el `Expense`, no este método).
     *
     * @param float $comision Comisión calculada (bruta).
     * @param float $alicuota Alícuota de IVA (ej: 21 para 21%).
     * @param bool $incluido true = comisión con IVA incluido; false = comisión neta de IVA.
     * @return float Importe de IVA, redondeado a 2 decimales.
     */
    static function calcular_iva_comision($comision, $alicuota, $incluido) {

        // Sin alícuota configurada no hay IVA que discriminar.
        if (is_null($alicuota)) {
            $alicuota = 0;
        }

        if ($incluido) {
            return Numbers::redondear(((float) $comision) * ((float) $alicuota) / (100 + (float) $alicuota));
        }

        return Numbers::redondear(((float) $comision) * ((float) $alicuota) / 100);
    }

    /**
     * Calcula los tres saldos de liquidez de una caja con una sola query de agregación SQL (sin
     * traer movimientos a memoria): contable (no se toca, ya lo calcula `set_saldos()`), disponible
     * (ya liquidado) y a liquidar (en tránsito).
     *
     * Regla de compatibilidad: un movimiento con `fecha_liquidacion_estimada = null` (histórico o
     * de liquidación inmediata) cuenta como ya disponible. Así una caja de efectivo sin configurar
     * muestra los tres saldos iguales y no cambia nada para los clientes que no usan esto.
     *
     * El COALESCE aplica igual a ingresos y a egresos: `monto_neto_estimado`, cuando existe, es el
     * monto que impacta liquidez sin importar el signo del movimiento (así cierra en cero la
     * reversión de una venta comisionada, ver `DeleteCajaCompensacionHelper`).
     *
     * @param int $caja_id
     * @return array Array con saldo_disponible y saldo_a_liquidar (floats).
     */
    static function calcular_saldos_liquidez($caja_id) {

        $hoy = date('Y-m-d');

        $agregado = DB::table('movimiento_cajas')
                        ->where('caja_id', $caja_id)
                        ->selectRaw(
                            'SUM(CASE WHEN ingreso IS NOT NULL AND (fecha_liquidacion_estimada IS NULL OR fecha_liquidacion_estimada <= ?) THEN COALESCE(monto_neto_estimado, ingreso) ELSE 0 END) as ingresos_disponibles,
                             SUM(CASE WHEN egreso IS NOT NULL THEN COALESCE(monto_neto_estimado, egreso) ELSE 0 END) as egresos_totales,
                             SUM(CASE WHEN ingreso IS NOT NULL AND fecha_liquidacion_estimada > ? THEN COALESCE(monto_neto_estimado, ingreso) ELSE 0 END) as ingresos_a_liquidar',
                            [$hoy, $hoy]
                        )
                        ->first();

        $ingresos_disponibles = (float) ($agregado->ingresos_disponibles ?? 0);
        $egresos_totales = (float) ($agregado->egresos_totales ?? 0);
        $ingresos_a_liquidar = (float) ($agregado->ingresos_a_liquidar ?? 0);

        return [
            'saldo_disponible'  => Numbers::redondear($ingresos_disponibles - $egresos_totales),
            'saldo_a_liquidar'  => Numbers::redondear($ingresos_a_liquidar),
        ];
    }
}
