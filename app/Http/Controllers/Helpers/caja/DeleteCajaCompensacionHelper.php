<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\Expense;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Model;

/**
 * Centraliza la verificación de cajas abiertas y la creación de movimientos de caja compensatorios
 * al eliminar modelos que impactaron caja vía métodos de pago (pivote con `caja_id` y montos).
 */
class DeleteCajaCompensacionHelper
{
    /** Concepto: reversión de ingreso por venta (egreso en caja). */
    const CONCEPTO_ELIMINACION_VENTA = 7;

    /** Concepto: reversión de egreso por gasto (ingreso en caja). */
    const CONCEPTO_ELIMINACION_GASTO = 8;

    /** Concepto: reversión de ingreso por pago de cliente (egreso en caja). */
    const CONCEPTO_ELIMINACION_PAGO_CLIENTE = 9;

    /** Concepto: reversión de egreso por pago a proveedor (ingreso en caja). */
    const CONCEPTO_ELIMINACION_PAGO_PROVEEDOR = 10;

    /**
     * Tipo de modelo origen para armar líneas de compensación desde la relación `current_acount_payment_methods`.
     */
    const MODEL_TYPE_SALE = 'sale';

    const MODEL_TYPE_EXPENSE = 'expense';

    const MODEL_TYPE_CURRENT_ACOUNT = 'current_acount';

    /**
     * Devuelve nombres de cajas que no están abiertas entre las líneas con `caja_id` válido.
     *
     * @param \Illuminate\Support\Collection|array $payment_methods Relación cargada `current_acount_payment_methods` del modelo.
     * @return array Lista de strings (nombre o fallback "Caja N° {num}") de cajas cerradas.
     */
    public function verificar_cajas_abiertas($payment_methods)
    {
        /** Acumulador de nombres de cajas cerradas (sin duplicar por id). */
        $cerradas_por_id = [];

        foreach ($payment_methods as $payment_method) {
            /** Identificador de caja en el pivote; null o 0 implica que no hubo impacto en caja. */
            $caja_id = $payment_method->pivot->caja_id ?? null;
            if (is_null($caja_id) || (int) $caja_id === 0) {
                continue;
            }

            /** Registro de caja para leer bandera `abierta` y datos de etiqueta. */
            $caja = Caja::find($caja_id);
            if (is_null($caja)) {
                continue;
            }

            if ((int) $caja->abierta !== 1) {
                $label = $caja->name ? $caja->name : ('Caja N° '.$caja->num);
                $cerradas_por_id[$caja->id] = $label;
            }
        }

        return array_values($cerradas_por_id);
    }

    /**
     * Resuelve el monto que impactó caja: prioriza `amount_cotizado` del pivote si está definido.
     *
     * @param object $pivot Objeto pivote de la relación many-to-many.
     * @return float Monto positivo a usar en el movimiento compensatorio.
     */
    public function resolver_monto_desde_pivot($pivot)
    {
        /** Valor cotizado opcional: si viene informado (no null ni string vacío), es el que impactó caja. */
        $amount_cotizado = $pivot->amount_cotizado ?? null;
        if (! is_null($amount_cotizado) && $amount_cotizado !== '') {
            return (float) $amount_cotizado;
        }

        return (float) ($pivot->amount ?? 0);
    }

    /**
     * Crea movimientos de caja inversos por cada línea con caja y monto > 0.
     *
     * @param \Illuminate\Support\Collection|array $payment_methods Relación `current_acount_payment_methods` cargada.
     * @param string $model_type Una de las constantes MODEL_TYPE_*.
     * @param string|null $from_model_name En pagos CC: `client` u otro (proveedor).
     * @param string $notas_base Texto libre para el campo `notas` del movimiento (contexto de eliminación).
     * @param int|null $model_id Grupo 223 · Prompt 02: id de la venta o del pago de CC que se está
     *        eliminando (`Sale::$id` / `CurrentAcount::$id`), para poder ubicar el movimiento de
     *        caja original y revertir su gasto de comisión. Null para gastos (nunca tuvieron comisión).
     * @return void
     */
    public function crear_movimientos_compensacion($payment_methods, $model_type, $from_model_name, $notas_base, $model_id = null)
    {
        /** Helper de persistencia de movimientos y saldos de caja. */
        $movimiento_helper = new MovimientoCajaHelper();

        foreach ($payment_methods as $payment_method) {
            $caja_id = $payment_method->pivot->caja_id ?? null;
            if (is_null($caja_id) || (int) $caja_id === 0) {
                continue;
            }

            // $monto = $this->resolver_monto_desde_pivot($payment_method->pivot);
            $monto = $payment_method->pivot->amount;
            if ($monto <= 0) {
                continue;
            }

            /** Payload base para `crear_movimiento`. */
            $data = [
                'caja_id'                     => (int) $caja_id,
                'notas'                       => $notas_base,
            ];

            /** Grupo 223 · Prompt 02: solo estos dos casos revierten un cobro real (venta o pago de cliente). */
            $es_reversion_de_cobro = false;

            if ($model_type === self::MODEL_TYPE_SALE) {
                $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_VENTA;
                $data['ingreso']                   = null;
                $data['egreso']                    = $monto;
                $es_reversion_de_cobro = true;
            } elseif ($model_type === self::MODEL_TYPE_EXPENSE) {
                $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_GASTO;
                $data['ingreso']                   = $monto;
                $data['egreso']                    = null;
            } elseif ($model_type === self::MODEL_TYPE_CURRENT_ACOUNT) {
                if ($from_model_name === 'client') {
                    $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_PAGO_CLIENTE;
                    $data['ingreso']                   = null;
                    $data['egreso']                    = $monto;
                    $es_reversion_de_cobro = true;
                } else {
                    $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_PAGO_PROVEEDOR;
                    $data['ingreso']                   = $monto;
                    $data['egreso']                    = null;
                }
            } else {
                continue;
            }

            $movimiento_compensatorio = $movimiento_helper->crear_movimiento($data);

            if ($es_reversion_de_cobro && !is_null($model_id)) {
                $this->revertir_comision_del_original($movimiento_compensatorio, $model_type, $model_id, (int) $caja_id, $monto);
            }
        }
    }

    /**
     * Busca el movimiento original que generó el cobro que se está revirtiendo y, si tuvo un gasto
     * de comisión asociado, lo borra y copia su `monto_neto_estimado` al movimiento compensatorio.
     *
     * El movimiento compensatorio mantiene `egreso` en BRUTO (el saldo contable tiene que cerrar en
     * cero), pero copiar el neto original permite que `saldo_disponible` también cierre en cero —
     * de lo contrario, eliminar una venta comisionada dejaría el saldo disponible corrido para
     * siempre por el valor de la comisión.
     *
     * @param \App\Models\MovimientoCaja $movimiento_compensatorio Movimiento recién creado (egreso/ingreso inverso).
     * @param string $model_type MODEL_TYPE_SALE o MODEL_TYPE_CURRENT_ACOUNT.
     * @param int $model_id Id de la venta o del pago de CC original.
     * @param int $caja_id Caja donde impactó el movimiento original.
     * @param float $monto Monto del pivote, usado solo en el fallback de búsqueda por CC.
     * @return void
     */
    protected function revertir_comision_del_original($movimiento_compensatorio, $model_type, $model_id, $caja_id, $monto)
    {
        $movimiento_original = $this->buscar_movimiento_original($model_type, $model_id, $caja_id, $monto);

        if (is_null($movimiento_original) || is_null($movimiento_original->comision_expense_id)) {
            return;
        }

        // Borrar el gasto automático de comisión: la venta/cobro que lo generó se está revirtiendo,
        // no puede quedar un gasto de comisión de algo que ya no existe.
        Expense::where('id', $movimiento_original->comision_expense_id)->delete();

        // Prompt 380/03: no alcanza con copiar monto_neto_estimado. calcular_saldos_liquidez()
        // clasifica cada movimiento en el balde "disponible" o "a liquidar" SEGUN SU PROPIA
        // fecha_liquidacion_estimada -- el compensatorio, creado por crear_movimiento() sin pasar
        // por la liquidacion (no manda aplica_liquidacion), nace con fecha null ("disponible"). Si
        // el original estaba "a liquidar" (fecha futura, ej. Mercado Pago a 14 dias), el egreso
        // compensatorio caia en el balde equivocado: restaba de "disponible" un monto que nunca
        // habia entrado ahi, y no tocaba "a liquidar", que es donde en realidad habia que
        // cancelarlo. Copiar tambien la fecha hace que el compensatorio aterrice en el MISMO balde
        // que el ingreso que esta anulando.
        $movimiento_compensatorio->fecha_liquidacion_estimada = $movimiento_original->fecha_liquidacion_estimada;
        $movimiento_compensatorio->monto_neto_estimado = $movimiento_original->monto_neto_estimado;
        $movimiento_compensatorio->save();
    }

    /**
     * Ubica el movimiento de caja original de un cobro (venta o pago de cliente) que se está
     * revirtiendo.
     *
     * Para ventas, se busca directo por `sale_id`. Para pagos de cuenta corriente, se prioriza
     * `current_acount_id` si estuviera poblado; hoy `CurrentAcountCajaHelper::guardar_pago()` no lo
     * completa (queda comentado), así que se cae a un fallback por caja + monto + concepto de cobro
     * a cliente. Si ninguno matchea, se devuelve null sin romper: la reversión de caja sigue
     * funcionando igual que antes de este prompt, solo sin poder revertir una comisión que no se
     * pudo ubicar.
     *
     * @param string $model_type MODEL_TYPE_SALE o MODEL_TYPE_CURRENT_ACOUNT.
     * @param int $model_id
     * @param int $caja_id
     * @param float $monto
     * @return \App\Models\MovimientoCaja|null
     */
    protected function buscar_movimiento_original($model_type, $model_id, $caja_id, $monto)
    {
        if ($model_type === self::MODEL_TYPE_SALE) {

            return MovimientoCaja::where('caja_id', $caja_id)
                                    ->where('sale_id', $model_id)
                                    ->first();
        }

        // MODEL_TYPE_CURRENT_ACOUNT (cobro a cliente): preferir current_acount_id si está poblado.
        $movimiento = MovimientoCaja::where('caja_id', $caja_id)
                                        ->where('current_acount_id', $model_id)
                                        ->first();

        if (!is_null($movimiento)) {
            return $movimiento;
        }

        // Fallback: current_acount_id no se completa hoy al crear el pago. Se resuelve por caja +
        // monto + concepto de cobro a cliente (concepto 3). Si hay varios matches, se toma el más
        // reciente; si no hay ninguno, se devuelve null sin romper.
        return MovimientoCaja::where('caja_id', $caja_id)
                                ->where('concepto_movimiento_caja_id', 3)
                                ->where('ingreso', $monto)
                                ->orderBy('created_at', 'DESC')
                                ->first();
    }

    /**
     * Carga la relación de métodos de pago según el tipo de modelo (para reutilizar la misma API).
     *
     * @param Model $model Instancia de Sale, Expense o CurrentAcount.
     * @param string $model_type Una de MODEL_TYPE_*.
     * @return void
     */
    public function cargar_metodos_de_pago_para_compensacion(Model $model, $model_type)
    {
        if ($model_type === self::MODEL_TYPE_SALE) {
            /** @var Sale $model */
            $model->loadMissing('current_acount_payment_methods');
        } elseif ($model_type === self::MODEL_TYPE_EXPENSE) {
            /** @var Expense $model */
            $model->loadMissing('current_acount_payment_methods');
        } elseif ($model_type === self::MODEL_TYPE_CURRENT_ACOUNT) {
            /** @var CurrentAcount $model */
            $model->loadMissing('current_acount_payment_methods');
        }
    }
}
