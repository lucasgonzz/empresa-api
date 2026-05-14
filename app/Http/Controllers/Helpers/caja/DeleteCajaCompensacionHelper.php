<?php

namespace App\Http\Controllers\Helpers\caja;

use App\Models\Caja;
use App\Models\CurrentAcount;
use App\Models\Expense;
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
     * @return void
     */
    public function crear_movimientos_compensacion($payment_methods, $model_type, $from_model_name, $notas_base)
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

            if ($model_type === self::MODEL_TYPE_SALE) {
                $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_VENTA;
                $data['ingreso']                   = null;
                $data['egreso']                    = $monto;
            } elseif ($model_type === self::MODEL_TYPE_EXPENSE) {
                $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_GASTO;
                $data['ingreso']                   = $monto;
                $data['egreso']                    = null;
            } elseif ($model_type === self::MODEL_TYPE_CURRENT_ACOUNT) {
                if ($from_model_name === 'client') {
                    $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_PAGO_CLIENTE;
                    $data['ingreso']                   = null;
                    $data['egreso']                    = $monto;
                } else {
                    $data['concepto_movimiento_caja_id'] = self::CONCEPTO_ELIMINACION_PAGO_PROVEEDOR;
                    $data['ingreso']                   = $monto;
                    $data['egreso']                    = null;
                }
            } else {
                continue;
            }

            $movimiento_helper->crear_movimiento($data);
        }
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
