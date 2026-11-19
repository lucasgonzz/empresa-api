<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Imputa un crédito económico ya registrado; nunca lo convierte en cobro ni en NC fiscal. */
class CreditosHistoricosHelper
{
    static function tiene_creditos($cuenta_id)
    {
        return CurrentAcount::where('credit_account_id', $cuenta_id)->where('debe', '<', 0)->exists();
    }

    /** Solo lectura. Los importes se consumen en centavos para no crear imputaciones residuales. */
    static function plan($cuenta, $movimientos = null)
    {
        if (is_null($movimientos)) {
            $movimientos = CurrentAcount::where('credit_account_id', $cuenta->id)
                ->orderBy('created_at')->orderBy('id')->get();
        }
        $movimientos->loadMissing('sale', 'provider_order');
        $ids = $movimientos->filter(function ($movimiento) { return !(int) $movimiento->is_provisorio; })->pluck('id');
        $ajenos = DB::table('pagado_por')->where(function ($q) use ($ids) {
            $q->whereIn('debe_id', $ids)->whereNotIn('haber_id', $ids);
        })->orWhere(function ($q) use ($ids) {
            $q->whereIn('haber_id', $ids)->whereNotIn('debe_id', $ids);
        })->exists();
        if ($ajenos) {
            throw ValidationException::withMessages(['cuenta_corriente' => 'Esta cuenta tiene imputaciones vinculadas con otra cuenta, moneda o un movimiento provisorio. Pedile a soporte que revise esos vínculos; no cargues otro pago para cambiar los estados.']);
        }
        $debitos = [];
        $fuentes = [];
        $estados = [];
        $saldo = 0;
        $credito_total = 0;
        foreach ($movimientos as $movimiento) {
            if ((int) $movimiento->is_provisorio) {
                continue;
            }
            self::validar($cuenta, $movimiento);
            $debe = self::centavos($movimiento->debe);
            $haber = self::centavos($movimiento->haber);
            $saldo += $debe - $haber;
            if ($debe > 0) {
                $debitos[$movimiento->id] = ['movimiento' => $movimiento, 'total' => $debe, 'pendiente' => $debe];
            } elseif ($debe < 0) {
                $credito_total -= $debe;
                $fuentes[] = ['movimiento' => $movimiento, 'total' => -$debe];
                $estados[] = ['id' => $movimiento->id, 'status' => 'pagado', 'pagandose' => 0];
            } elseif ($haber > 0) {
                $fuentes[] = ['movimiento' => $movimiento, 'total' => $haber];
            } elseif (!is_null($movimiento->debe)) {
                $estados[] = ['id' => $movimiento->id, 'status' => 'pagado', 'pagandose' => 0];
            } elseif (is_null($movimiento->debe) && is_null($movimiento->haber)) {
                self::error($movimiento, 'tiene un saldo de arranque sin importe en Debe o Haber. Revisá el saldo inicial con soporte antes de recalcular');
            }
        }

        $imputaciones = [];
        $remanente = 0;
        foreach ($fuentes as $fuente) {
            $movimiento = $fuente['movimiento'];
            $fondos = $fuente['total'];
            $dirigidos = self::destinos($movimiento);
            foreach ($dirigidos as $dirigido) {
                if (!isset($debitos[$dirigido])) {
                    self::error($movimiento, 'está dirigido al movimiento #'.$dirigido.' que no es una deuda válida de esta cuenta y moneda. Pedile a soporte que revise ese destino antes de volver a imputar');
                }
            }
            // Los destinos elegidos conservan el mismo orden cronológico que el motor normal.
            $orden = array_keys($debitos);
            $orden = array_merge(array_values(array_filter($orden, function ($id) use ($dirigidos) {
                return in_array((int) $id, $dirigidos, true);
            })), array_values(array_filter($orden, function ($id) use ($dirigidos) {
                return !in_array((int) $id, $dirigidos, true);
            })));
            foreach ($orden as $id) {
                if ($fondos === 0) {
                    break;
                }
                $pendiente = $debitos[$id]['pendiente'];
                if ($pendiente <= 0) {
                    continue;
                }
                $pagado = min($fondos, $pendiente);
                $imputaciones[] = ['debe_id' => $id, 'haber_id' => $movimiento->id,
                    'pagado' => $pagado / 100, 'total_pago' => $fuente['total'] / 100,
                    'a_cubrir' => $pendiente / 100, 'fondos_iniciales' => $fondos / 100,
                    'nuevos_fondos' => ($fondos - $pagado) / 100, 'remantente' => ($pendiente - $pagado) / 100,
                    'created_at' => $movimiento->created_at->format('Y-m-d H:i:s')];
                $debitos[$id]['pendiente'] -= $pagado;
                $fondos -= $pagado;
            }
            $remanente += $fondos;
        }
        $pendiente = 0;
        foreach ($debitos as $id => $debito) {
            $pendiente += $debito['pendiente'];
            $estados[] = ['id' => $id,
                'status' => $debito['pendiente'] === 0 ? 'pagado' : ($debito['pendiente'] === $debito['total'] ? 'sin_pagar' : 'pagandose'),
                'pagandose' => ($debito['total'] - $debito['pendiente']) / 100];
        }
        // Invariante: ninguna fila pendiente agrega deuda por encima del saldo económico.
        if ($pendiente - $remanente !== $saldo) {
            throw ValidationException::withMessages(['cuenta_corriente' => 'Los importes de esta cuenta no se pueden conciliar. Pedile a soporte que revise el historial antes de registrar otro pago.']);
        }
        return ['estados' => $estados, 'imputaciones' => $imputaciones,
            'credito_total' => $credito_total / 100, 'saldo_economico' => $saldo / 100,
            'pendiente' => $pendiente / 100, 'remanente' => $remanente / 100];
    }

    /** Requiere transacción y candado del dueño. No modifica saldo, economía, comisiones ni puntos. */
    static function aplicar($cuenta)
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('La conciliación requiere una transacción y el candado de la cuenta.');
        }
        CuentaCorrienteLock::bloquear($cuenta->model_name, $cuenta->model_id);
        $movimientos = CurrentAcount::where('credit_account_id', $cuenta->id)
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        // Validar todo antes de borrar una imputación o cambiar un estado.
        $plan = self::plan($cuenta, $movimientos);
        $ids = $movimientos->filter(function ($movimiento) { return !(int) $movimiento->is_provisorio; })->pluck('id');
        DB::table('pagado_por')->whereIn('debe_id', $ids)->orWhereIn('haber_id', $ids)->delete();
        foreach ($plan['estados'] as $estado) {
            DB::table('current_acounts')->where('id', $estado['id'])->update(['status' => $estado['status'], 'pagandose' => $estado['pagandose']]);
        }
        foreach (array_chunk($plan['imputaciones'], 500) as $lote) {
            DB::table('pagado_por')->insert($lote);
        }
        return $plan;
    }

    private static function centavos($importe)
    {
        return (int) round((float) $importe * 100);
    }

    private static function destinos($movimiento)
    {
        $lista = $movimiento->to_pay_ids;
        if (!is_null($movimiento->getRawOriginal('to_pay_ids')) && !is_array($lista)) {
            self::error($movimiento, 'tiene una selección de destinos que no se puede interpretar. Pedile a soporte que revise esa selección');
        }
        if (empty($lista)) {
            $lista = is_null($movimiento->to_pay_id) ? [] : [$movimiento->to_pay_id];
        }
        $ids = [];
        foreach ($lista as $id) {
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id <= 0) {
                self::error($movimiento, 'tiene un destino de imputación inválido. Pedile a soporte que revise la selección antes de recalcular');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        if (count($ids) === 1 && !is_null($movimiento->to_pay_id) && $ids[0] !== (int) $movimiento->to_pay_id) {
            self::error($movimiento, 'tiene dos destinos distintos para una selección individual. Pedile a soporte que confirme cuál corresponde antes de recalcular');
        }
        return $ids;
    }

    private static function validar($cuenta, $movimiento)
    {
        $columna = $cuenta->model_name.'_id';
        $otra = $cuenta->model_name === 'client' ? 'provider_id' : 'client_id';
        if ((int) $movimiento->credit_account_id !== (int) $cuenta->id || (int) $movimiento->$columna !== (int) $cuenta->model_id || !is_null($movimiento->$otra)) {
            self::error($movimiento, 'no coincide con el titular de esta cuenta. Pedile a soporte que revise la vinculación del movimiento');
        }
        $moneda = $movimiento->getRawOriginal('moneda_id');
        if (!is_null($moneda) && (int) $moneda !== (int) $cuenta->moneda_id) {
            self::error($movimiento, 'está registrado en otra moneda. Revisá la moneda con soporte; no se pueden mezclar pesos y dólares sin una conversión');
        }
        if (!is_null($movimiento->sale_id)) {
            $venta = $movimiento->sale;
            if (is_null($venta) || (int) ($venta->moneda_id ?: 1) !== (int) $cuenta->moneda_id
                || (int) $venta->client_id !== (int) $cuenta->model_id || $cuenta->model_name !== 'client'
                || (int) $venta->user_id !== (int) $cuenta->user_id) {
                self::error($movimiento, 'está vinculado a una venta que no coincide con esta cuenta, titular o moneda. Pedile a soporte que revise la venta vinculada');
            }
        }
        if (!is_null($movimiento->provider_order_id)) {
            $compra = $movimiento->provider_order;
            if (is_null($compra) || (int) ($compra->moneda_id ?: 1) !== (int) $cuenta->moneda_id
                || (int) $compra->provider_id !== (int) $cuenta->model_id || $cuenta->model_name !== 'provider'
                || (int) $compra->user_id !== (int) $cuenta->user_id) {
                self::error($movimiento, 'está vinculado a una compra que no coincide con esta cuenta, titular o moneda. Pedile a soporte que revise la compra vinculada');
            }
        }
        if ((!is_null($movimiento->debe) && !is_numeric($movimiento->debe)) || (!is_null($movimiento->haber) && !is_numeric($movimiento->haber)) || (float) $movimiento->haber < 0 || (!is_null($movimiento->debe) && (float) $movimiento->haber != 0)) {
            self::error($movimiento, 'tiene importes incompatibles en Debe y Haber. Revisá el comprobante con soporte antes de recalcular');
        }
    }

    private static function error($movimiento, $motivo)
    {
        throw ValidationException::withMessages(['cuenta_corriente' => 'El movimiento #'.$movimiento->id.' '.$motivo.'. No se modificaron sus importes ni se registraron pagos.']);
    }
}
