<?php

namespace App\Http\Controllers\Helpers\currentAcount;

use App\Models\CurrentAcount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        $pivotes_huerfanos = self::vinculos_ajenos($cuenta, $ids);
        $debitos = [];
        $fuentes = [];
        $estados = [];
        $saldo = 0;
        $credito_total = 0;
        // Arrastre (en centavos) de un saldo inicial legado: suma al saldo pero no se imputa (ver es_saldo_inicial_legado).
        $arrastre_legado = 0;
        $id_saldo_inicial_legado = null;
        foreach ($movimientos as $movimiento) {
            if ((int) $movimiento->is_provisorio) {
                continue;
            }
            self::validar($cuenta, $movimiento);
            $debe = self::centavos($movimiento->debe);
            $haber = self::centavos($movimiento->haber);
            $saldo += $debe - $haber;
            if (self::es_saldo_inicial_legado($movimiento)) {
                // No es débito ni fuente: queda fuera de estados e imputaciones. Un segundo legado en la misma
                // cuenta es un dato que no conocemos: se rechaza en vez de arrastrarlo en silencio.
                if (!is_null($id_saldo_inicial_legado)) {
                    self::error($movimiento, 'es un segundo saldo inicial con Haber negativo en esta cuenta (el primero es el #'.$id_saldo_inicial_legado.'). Pedile a soporte que revise los saldos iniciales antes de recalcular');
                }
                $id_saldo_inicial_legado = $movimiento->id;
                $arrastre_legado -= $haber;
                continue;
            }
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
        // Destinos guardados que se ignoraron por estar fuera de la cuenta (ver destinos_fuera_de_la_cuenta).
        $destinos_ignorados = [];
        foreach ($fuentes as $fuente) {
            $movimiento = $fuente['movimiento'];
            $fondos = $fuente['total'];
            $dirigidos = self::destinos($movimiento);
            // Destinos que el motor normal ignoraría (otra cuenta o inexistentes): no bloquean, se informan.
            $ignorados = self::destinos_fuera_de_la_cuenta($cuenta, $dirigidos, $debitos);
            foreach ($ignorados as $ignorado) {
                $destinos_ignorados[] = ['haber_id' => $movimiento->id, 'destino_id' => $ignorado];
            }
            $dirigidos = array_values(array_diff($dirigidos, $ignorados));
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
        // Invariante: ninguna fila pendiente agrega deuda por encima del saldo económico. El arrastre del saldo
        // inicial legado es deuda que el saldo cuenta pero que ninguna venta recibe.
        if ($pendiente - $remanente + $arrastre_legado !== $saldo) {
            throw ValidationException::withMessages(['cuenta_corriente' => 'Los importes de esta cuenta no se pueden conciliar. Pedile a soporte que revise el historial antes de registrar otro pago.']);
        }
        return ['estados' => $estados, 'imputaciones' => $imputaciones,
            'credito_total' => $credito_total / 100, 'saldo_economico' => $saldo / 100,
            'pendiente' => $pendiente / 100, 'remanente' => $remanente / 100,
            'arrastre_legado' => $arrastre_legado / 100, 'destinos_ignorados' => $destinos_ignorados,
            'pivotes_huerfanos' => $pivotes_huerfanos];
    }

    /**
     * Solo lectura. La vía de checkPagos() sin créditos no pasa por plan(), pero su borrado de imputaciones se
     * llevaría igual un cruce real (San Cayetano 427: #18320>#18338 de la cuenta 3867). Misma guarda, mismo mensaje.
     * Devuelve los huérfanos (no bloquean) y lanza si hay un cruce real o un provisorio, en cualquiera de los dos lados.
     *
     * @param  \App\Models\CreditAccount  $cuenta
     * @return array<int,array{debe_id:int,haber_id:int,pagado:float}>
     * @throws \Illuminate\Validation\ValidationException
     */
    static function verificar_vinculos($cuenta)
    {
        $ids = CurrentAcount::where('credit_account_id', $cuenta->id)->get(['id', 'is_provisorio'])
            ->filter(function ($movimiento) { return !(int) $movimiento->is_provisorio; })->pluck('id');
        return self::vinculos_ajenos($cuenta, $ids);
    }

    /**
     * Vínculos `pagado_por` con una sola punta en esta cuenta. Devuelve los HUÉRFANOS (la otra punta ya no
     * existe) y lanza si hay un cruce real.
     *
     * 🔴 Huérfano y cruce real NO se tratan igual, aunque la consulta los encuentre juntos:
     *  - Huérfano: resto de un borrado viejo (antes de 76ca74d3 el borrado no desenganchaba el pivote). Nada
     *    se puede imputar a una fila que no existe, y aplicar() ya borra todo pivote con una punta en la
     *    cuenta antes de reinsertar el plan, así que descartarlo no escribe nada que no se escribiera ya.
     *    Se informa en `pivotes_huerfanos` y no bloquea (ticket 171: San Cayetano 119 y 2471).
     *  - Cruce real (otra cuenta, otro titular, otra moneda, sin cuenta o un provisorio): la otra punta SÍ
     *    existe. Ignorarlo no es inocuo: aplicar() borraría el pivote de la OTRA cuenta y le dejaría estados
     *    mal. Se rechaza, ahora diciendo qué movimientos y qué cuenta, y NO se borra nada (el throw va antes
     *    del delete de aplicar()). Si hay cruce y huérfano juntos gana el cruce.
     * Se informa la primera fila no huérfana en orden (debe_id, haber_id) para que el mensaje sea estable.
     * Lo usa también la vía sin créditos de checkPagos() (verificar_vinculos), que no pasa por plan().
     *
     * @param  \App\Models\CreditAccount  $cuenta
     * @param  \Illuminate\Support\Collection  $ids  Ids de los movimientos no provisorios de la cuenta.
     * @return array<int,array{debe_id:int,haber_id:int,pagado:float}>
     * @throws \Illuminate\Validation\ValidationException
     */
    private static function vinculos_ajenos($cuenta, $ids)
    {
        $filas = DB::table('pagado_por')->where(function ($q) use ($ids) {
            $q->whereIn('debe_id', $ids)->whereNotIn('haber_id', $ids);
        })->orWhere(function ($q) use ($ids) {
            $q->whereIn('haber_id', $ids)->whereNotIn('debe_id', $ids);
        })->orderBy('debe_id')->orderBy('haber_id')->get(['debe_id', 'haber_id', 'pagado']);
        if ($filas->isEmpty()) {
            return [];
        }
        $propios = array_flip($ids->all());
        $otras = $filas->map(function ($fila) use ($propios) {
            return isset($propios[$fila->debe_id]) ? (int) $fila->haber_id : (int) $fila->debe_id;
        })->all();
        $existentes = DB::table('current_acounts')->whereIn('id', $otras)->get(['id', 'credit_account_id', 'is_provisorio'])->keyBy('id');
        $huerfanos = [];
        foreach ($filas as $fila) {
            $propia = isset($propios[$fila->debe_id]) ? (int) $fila->debe_id : (int) $fila->haber_id;
            $otra = isset($propios[$fila->debe_id]) ? (int) $fila->haber_id : (int) $fila->debe_id;
            if (!$existentes->has($otra)) {
                $huerfanos[] = ['debe_id' => (int) $fila->debe_id, 'haber_id' => (int) $fila->haber_id, 'pagado' => (float) $fila->pagado];
                continue;
            }
            $destino = $existentes->get($otra);
            $cierre = 'Pedile a soporte que revise esos vínculos; no cargues otro pago para cambiar los estados.';
            if (!is_null($destino->credit_account_id) && (int) $destino->credit_account_id === (int) $cuenta->id && (int) $destino->is_provisorio) {
                throw ValidationException::withMessages(['cuenta_corriente' => 'Esta cuenta tiene imputaciones vinculadas con un movimiento provisorio: el movimiento #'.$propia.' está imputado con el provisorio #'.$otra.'. '.$cierre]);
            }
            $de_cuenta = is_null($destino->credit_account_id) ? 'de un movimiento sin cuenta corriente' : 'de la cuenta corriente #'.$destino->credit_account_id;
            throw ValidationException::withMessages(['cuenta_corriente' => 'Esta cuenta tiene imputaciones vinculadas con otra cuenta: el movimiento #'.$propia.' está imputado con el #'.$otra.' '.$de_cuenta.'. '.$cierre]);
        }
        return $huerfanos;
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
        if (!empty($plan['pivotes_huerfanos'])) {
            // Rastro del descarte (sólo ids, sin importes de terceros): el borrado de abajo se lleva estos pivotes.
            Log::info('Créditos históricos: se descartan pivotes huérfanos de la cuenta '.$cuenta->id.': '.json_encode(array_map(function ($p) { return [$p['debe_id'], $p['haber_id']]; }, $plan['pivotes_huerfanos'])));
        }
        DB::table('pagado_por')->whereIn('debe_id', $ids)->orWhereIn('haber_id', $ids)->delete();
        foreach ($plan['estados'] as $estado) {
            DB::table('current_acounts')->where('id', $estado['id'])->update(['status' => $estado['status'], 'pagandose' => $estado['pagandose']]);
        }
        foreach (array_chunk($plan['imputaciones'], 500) as $lote) {
            DB::table('pagado_por')->insert($lote);
        }
        return $plan;
    }

    /**
     * Saldo inicial legado: fila importada en 2024 con detalle 'Saldo inicial', Debe NULL y Haber NEGATIVO
     * (el importador guardaba `haber = saldo` sin abs). La cadena de saldos la cuenta como +|haber| (deuda),
     * pero el motor normal nunca la imputó: no es débito (debe NULL) ni fuente (fondos negativos).
     * Se la trata igual acá: arrastre que suma al saldo y NO recibe ni da pagos, sin tocar su importe. El signo
     * original es dudoso (pudo ser saldo a favor), así que NO se la convierte en deuda imputable ni se corrige
     * el dato; si alguien la repara a Haber positivo deja de ser legada y pasa a ser una fuente normal.
     * Cualquier otra forma con Haber negativo (otro detalle, con venta, compra o destino) sigue rechazada.
     * Ver .mision/PLAN.md (ticket 171) y APRENDER_NO_PARCHEAR.md (saldo inicial con signo).
     *
     * @param CurrentAcount $movimiento
     * @return bool
     */
    private static function es_saldo_inicial_legado($movimiento)
    {
        return $movimiento->detalle === 'Saldo inicial'
            && is_null($movimiento->debe)
            && is_numeric($movimiento->haber) && (float) $movimiento->haber < 0
            && is_null($movimiento->sale_id) && is_null($movimiento->provider_order_id)
            && is_null($movimiento->to_pay_id) && is_null($movimiento->getRawOriginal('to_pay_ids'));
    }

    /**
     * Destinos dirigidos que el motor normal ignora y por eso `plan()` también: un id que no existe o que
     * pertenece a OTRA cuenta (CurrentAcountPagoHelper: si el destino no es de la misma credit_account_id
     * el pago sigue el orden FIFO; el alta del pago ignora los inexistentes). El pago entra en orden en su
     * propia cuenta, nunca se imputa deuda de otra cuenta ni moneda, y el to_pay_id guardado no se reescribe.
     * Un id de ESTA cuenta que no es débito (pago, crédito, saldo inicial, provisorio) NO se ignora: sigue
     * rechazado por el error de `plan()`. Cambia la regla de soporte-145 por decisión de Lucas (P4, ticket 171,
     * .mision/PLAN.md). Una sola consulta, y sólo si hay algún dirigido que no es débito de la cuenta.
     *
     * @param CreditAccount $cuenta
     * @param int[] $dirigidos ids dirigidos ya validados por destinos()
     * @param array $debitos débitos imputables de la cuenta, indexados por id
     * @return int[] ids ignorados, en el mismo orden en que se dirigieron
     */
    private static function destinos_fuera_de_la_cuenta($cuenta, $dirigidos, $debitos)
    {
        $faltantes = array_values(array_filter($dirigidos, function ($id) use ($debitos) {
            return !isset($debitos[$id]);
        }));
        if (empty($faltantes)) {
            return [];
        }
        $cuentas = CurrentAcount::whereIn('id', $faltantes)->pluck('credit_account_id', 'id')->all();
        return array_values(array_filter($faltantes, function ($id) use ($cuentas, $cuenta) {
            return !isset($cuentas[$id]) || (int) $cuentas[$id] !== (int) $cuenta->id;
        }));
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
        // Haber negativo que no es el saldo inicial legado: dato con el signo invertido (NC N°267 de la cuenta 5377).
        // Mensaje propio para que soporte lo distinga de las otras incompatibilidades; conserva el fragmento
        // "importes incompatibles" del texto histórico.
        if (is_numeric($movimiento->haber) && (float) $movimiento->haber < 0 && !self::es_saldo_inicial_legado($movimiento)) {
            self::error($movimiento, 'tiene importes incompatibles en Debe y Haber: el Haber es negativo ('.number_format((float) $movimiento->haber, 2, ',', '.').'), una nota de crédito o pago con el signo invertido. Revisá el signo del comprobante con soporte antes de recalcular');
        }
        if ((!is_null($movimiento->debe) && !is_numeric($movimiento->debe)) || (!is_null($movimiento->haber) && !is_numeric($movimiento->haber)) || ((float) $movimiento->haber < 0 && !self::es_saldo_inicial_legado($movimiento)) || (!is_null($movimiento->debe) && (float) $movimiento->haber != 0)) {
            self::error($movimiento, 'tiene importes incompatibles en Debe y Haber. Revisá el comprobante con soporte antes de recalcular');
        }
    }

    private static function error($movimiento, $motivo)
    {
        throw ValidationException::withMessages(['cuenta_corriente' => 'El movimiento #'.$movimiento->id.' '.$motivo.'. No se modificaron sus importes ni se registraron pagos.']);
    }
}
