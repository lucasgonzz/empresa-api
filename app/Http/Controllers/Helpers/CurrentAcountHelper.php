<?php

namespace App\Http\Controllers\Helpers;

use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\CurrentAcountController;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\SellerCommissionHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\currentAcount\CuentaCorrienteLock;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use App\Models\Article;
use App\Models\Check;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CreditCard;
use App\Models\CreditCardPaymentPlan;
use App\Models\CurrentAcount;
use App\Models\ErrorCurrentAcount;
use App\Models\NotaCreditoDescription;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CurrentAcountHelper {

    // Si este nuevo pago no es provisorio, significa que es el pago que cancela los demas pagos, 
    static function eliminar_pagos_provisorios($credit_account_id, $is_provisorio) {

    }

    /**
     * 🔴 Ya no es un recálculo parcial (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026).
     * Recalculaba solo desde el antepenúltimo movimiento (las últimas 3 filas por `created_at`), así
     * que un movimiento metido más atrás dejaba la cadena cortada. Con el índice de la cuenta y
     * checkSaldos() en una sola lectura, la cadena entera cuesta una consulta más las filas que
     * cambian: se recalcula entera. Queda el nombre por los llamadores que no son de esta misión
     * (LocalImportHelper).
     *
     * @param  int  $credit_account_id
     * @return void
     */
    static function checkCurrentAcountSaldo($credit_account_id) {

        Self::checkSaldos($credit_account_id);
    }

    static function updateSellerCommissionsStatus($pago) {
        foreach ($pago->pagando_las_comisiones as $seller_commission) {
            $seller_commission->status = 'inactive';
            $seller_commission->save();
        } 
    }

    static function getCreatedAt($request) {
        if ($request->current_date) {
            return Carbon::now();
        } else {
            return Carbon::parse($request->created_at.substr(Carbon::now(), 11));    
        }
    }

    static function saldoCheckeado($model_name, $model_id) {
        if ($model_name == 'client') {
            $client = Client::find($model_id);
            if (!$client->saldo_checkeado) {
                Self::checkSaldos($model_name, $model_id, null, true);
            }
        }
    }

    static function getNumReceipt($from_nota_credito = false) {
        $last_receipt = CurrentAcount::where('user_id', UserHelper::userId())
                                        ->orderBy('created_at', 'DESC');
        if ($from_nota_credito) {
            $last_receipt = $last_receipt->where('status', 'nota_credito');
        } else {
            $last_receipt = $last_receipt->where('status', 'pago_from_client');
        }
        $last_receipt = $last_receipt->first();
        return is_null($last_receipt) ? 1 : $last_receipt->num_receipt + 1;
    }


    static function updateModelSaldo($current_acount, $model_name, $model_id) {
        $_model_name = GeneralHelper::getModelName($model_name);
        $model = $_model_name::find($model_id);
        $model->saldo = Self::getSaldo($model_name, $model_id);
        $model->save();
        Log::info('se seteo saldo de '.$model->name.' a '.$model->saldo);
    } 


    static function update_credit_account_saldo($credit_account_id) {

        $credit_account = CreditAccount::find($credit_account_id);

        $credit_account->saldo = Self::getSaldo($credit_account_id);
        $credit_account->save();

        $moneda = 'pesos';
        if ($credit_account->moneda_id == 2) {
            $moneda = 'dolares';
        }

        $model = $credit_account->model;

        $model->{'saldo_'.$moneda} = $credit_account->saldo;
        $model->save();

        Log::info('se actualizo saldo del model '.$model->name.' a '.$credit_account->saldo);


        // Log::info('se seteo saldo de '.$model->name.' a '.$model->saldo);
    } 


    static function getSaldo($credit_account_id, $until_current_acount = null) {
        $query = CurrentAcount::query();
        
        Log::info('getSaldo credit_account_id ' . $credit_account_id);
        
        $query->where('is_provisorio', 0);

        /*
         * 🔴 Adentro de una transacción el saldo anterior se lee CON CANDADO (misión
         * cuenta-corriente-carrera-y-velocidad, 23/9/2026). En REPEATABLE READ un SELECT común ve la
         * foto de la base tomada en la primera lectura de la transacción, no lo último commiteado:
         * es exactamente lo que dejó a la venta 54160 de Fenix con el saldo de una venta que la otra
         * PC ya había cambiado. Una lectura FOR UPDATE ve siempre lo último commiteado.
         *
         * Fuera de una transacción cada sentencia ya ve lo último commiteado y el candado se soltaría
         * al terminar la sentencia: no aporta nada, así que no se pide.
         */
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        if (!is_null($until_current_acount)) {
            $query->where(function ($q) use ($credit_account_id, $until_current_acount) {
                $q->where('credit_account_id', $credit_account_id)
                  ->where(function ($q2) use ($until_current_acount) {
                      $q2->where('created_at', '<', $until_current_acount->created_at)
                         ->orWhere(function ($q3) use ($until_current_acount) {
                             $q3->where('created_at', $until_current_acount->created_at)
                                ->where('id', '<', $until_current_acount->id);
                         });
                  });
            });
        } else {
            $query->where('credit_account_id', $credit_account_id);
        }

        $last = $query->orderBy('created_at', 'desc')
                      ->orderBy('id', 'desc')
                      ->first();

        if (is_null($last)) {
            return 0;
        } else {
            Log::info('Se retorna el saldo de current_acount_id ' . $last->id.' con saldo de '.$last->saldo);
            return $last->saldo;
        }
    }

    // Esta usaba antes de credit_account 
    // static function getSaldo($model_name, $model_id, $until_current_acount = null) {
    //     $query = CurrentAcount::query();

    //     if ($model_name === 'client') {
    //         $query->where('client_id', $model_id);
    //     } else {
    //         $query->where('provider_id', $model_id);
    //     }

    //     $query->where('credit_account_id', $model_id);

    //     if (!is_null($until_current_acount)) {
    //         $query->where(function ($q) use ($until_current_acount) {
    //             $q->where('created_at', '<', $until_current_acount->created_at)
    //               ->orWhere(function ($q2) use ($until_current_acount) {
    //                   $q2->where('created_at', $until_current_acount->created_at)
    //                      ->where('id', '<', $until_current_acount->id);
    //               });
    //         });
    //     }

    //     $last = $query->orderBy('created_at', 'desc')->orderBy('id', 'desc')->first();

    //     if (is_null($last)) {
    //         return 0;
    //     } else {
    //         Log::info('Se retorna el saldo de current_acount_id ' . $last->id);
    //         return $last->saldo;
    //     }
    // }

    static function notaCredito($credit_account_id, $haber, $description, $model_name, $model_id, $sale_id = null, $items = null, $descriptions = null) {

        /*
         * Candado de la cuenta corriente (misión cuenta-corriente-carrera-y-velocidad, 23/9/2026).
         * Los llamadores que escriben desde un request ya lo tomaron al abrir su transacción
         * (Devoluciones, la NC de monto libre, la edición de venta): acá es gratis. Va igual en el
         * punto por el que pasan TODAS las notas de crédito, para que un camino nuevo nazca
         * cubierto. Sin dueño (devolución sin cliente) no bloquea nada.
         */
        if (!is_null($model_name) && !is_null($model_id)) {
            CuentaCorrienteLock::bloquear($model_name, $model_id);
        }

        $moneda_id = Self::get_moneda_id($credit_account_id, $sale_id);

        Log::info('moneda_id para nota_credito: '.$moneda_id);

        /**
         * Imputación dirigida de la NC a la venta que originó la devolución
         * (regla cerrada por Lucas el 4/7/2026 — refactor_empresa/cuentas_corrientes.md).
         *
         * Sin esto la NC entra a la cola general de CurrentAcountPagoHelper y salda la
         * deuda MAS VIEJA del cliente, no la venta que se devolvió. El motor de imputación
         * dirigida ya existe: CurrentAcountPagoHelper::setSinPagar() usa to_pay_id en la
         * primera iteración y sigue en FIFO con el sobrante, que es justo la cascada pedida
         * (el remanente de la NC cae en la siguiente venta/ND sin saldar).
         */
        $to_pay_id = null;

        if (!is_null($sale_id) && !is_null($credit_account_id)) {

            $debito_de_la_venta = CurrentAcount::where('sale_id', $sale_id)
                                                ->whereNull('haber')
                                                ->where('credit_account_id', $credit_account_id)
                                                ->whereIn('status', ['sin_pagar', 'pagandose'])
                                                ->first();

            if (!is_null($debito_de_la_venta)) {
                $to_pay_id = $debito_de_la_venta->id;
            }
        }

        $nota_credito = CurrentAcount::create([
            'description'       => $description,
            'haber'             => $haber,
            'status'            => 'nota_credito',
            'client_id'         => $model_name == 'client' ? $model_id : null,
            'provider_id'       => $model_name == 'provider' ? $model_id : null,
            'sale_id'           => $sale_id,
            'to_pay_id'         => $to_pay_id,
            'num_receipt'       => CurrentAcountHelper::getNumReceipt(true),
            'user_id'           => UserHelper::userId(),
            'employee_id'       => UserHelper::userId(false),
            'credit_account_id' => $credit_account_id,
            'moneda_id'         => $moneda_id,
        ]); 

        if (!is_null($model_name) && !is_null($model_id)) {
            $nota_credito->saldo = Self::getSaldo($credit_account_id, $nota_credito) - $haber;
        }

        $nota_credito->detalle = 'Nota Credito N°'.$nota_credito->num_receipt;
        $nota_credito->save();

        Self::attachNotaCreditoArticles($nota_credito, $items);
        Self::attachNotaCreditoServices($nota_credito, $items);
        Self::attachNotaCreditoDescriptions($nota_credito, $descriptions);

        if (!is_null($model_name) && !is_null($model_id)) {
            $pago_helper = new CurrentAcountPagoHelper($credit_account_id, $model_name, $model_id, $nota_credito);
            $pago_helper->init();

            /*
             * 🔴 La cadena ENTERA, no solo el saldo de la cuenta (misión
             * cuenta-corriente-carrera-y-velocidad, 23/9/2026). Antes se copiaba el saldo del último
             * movimiento a la cuenta y listo: si la NC no era el último movimiento (una venta con
             * fecha posterior), todo lo que venía después quedaba sin la NC. checkSaldos() también
             * deja la cuenta y el cliente con el saldo final, que es lo que hacía
             * update_credit_account_saldo(). Lo cubre para todos los llamadores: la NC de monto
             * libre, Devoluciones y la del panel de Vender.
             */
            Self::checkSaldos($credit_account_id);
        }

        /*
         * Puntos para clientes. Este método llama a CurrentAcountPagoHelper DERECHO y nunca
         * pasa por checkPagos(), así que el enganche de allá no lo ve: sin esto, una nota de
         * crédito dejaría los puntos de la venta devuelta tal como estaban.
         *
         * 🔴 Y una NC es justamente el caso que más duele: se imputa DIRIGIDA al débito de la
         * venta (el to_pay_id de más arriba), así que una venta devuelta entera llega a
         * `status = 'pagado'` SIN QUE EL CLIENTE HAYA PAGADO UN PESO. Los dos tapones viven en
         * PuntosBaseHelper (el returned_amount por renglón y el factor por NC de monto libre),
         * pero alguien tiene que volver a preguntar, y ese alguien es esta línea.
         *
         * `$nota_credito->user_id` ya está resuelto acá arriba: se pasa para que el comercio
         * sin la extensión salga sin leer ni la venta.
         */
        if (!is_null($sale_id)) {
            PuntosAcumulacionHelper::reconciliar_venta_por_id($sale_id, $nota_credito->user_id);
        }

        return $nota_credito;
    }

    static function get_moneda_id($credit_account_id, $sale_id) {

        Log::info('get_moneda_id');

        $moneda_id = null;

        if ($credit_account_id) {
            $credit_account = CreditAccount::find($credit_account_id);

            if ($credit_account) {
                $moneda_id = $credit_account->moneda_id;
            }
        }

        if (!$moneda_id && $sale_id) {
            $sale = Sale::find($sale_id);
            if ($sale) {
                $moneda_id = $sale->moneda_id;
            }
        }

        if (!$moneda_id) {
            Log::info('moneda_id null');
            $moneda_id = 1;
        }

        return $moneda_id;
    }

    static function attachNotaCreditoArticles($nota_credito, $items) {
        if (!is_null($items)) {
            $nota_credito->articles()->detach();
            foreach ($items as $item) {
                if (isset($item['is_article'])) {
                    if (
                        isset($item['unidades_devueltas'])
                        && (float)$item['unidades_devueltas'] > 0
                    ) {

                        $cost = $item['costo_real'];

                        if (
                            isset($item['pivot'])
                            && isset($item['pivot']['cost'])
                            && (float)$item['pivot']['cost'] > 0
                        ) {
                            $cost = (float)$item['pivot']['cost'];
                        }

                        /**
                         * Prioriza el IVA persistido en el pivot de la venta original.
                         * Si no existe (registros históricos), cae en el IVA actual del artículo.
                         * Esto garantiza que la NC use la misma alícuota de la factura,
                         * incluso si el artículo fue modificado entre la venta y la devolución.
                         */
                        $iva_percentage_nc = null;
                        if (isset($item['pivot']['iva_percentage']) && !is_null($item['pivot']['iva_percentage'])) {
                            $iva_percentage_nc = $item['pivot']['iva_percentage'];
                        } elseif (isset($item['iva']['percentage'])) {
                            $iva_percentage_nc = $item['iva']['percentage'];
                        }

                        $nota_credito->articles()->attach($item['id'], [
                                                            'amount'          => $item['unidades_devueltas'],
                                                            'price'           => $item['price_vender'],
                                                            'cost'            => $cost,
                                                            'discount'        => $item['discount'],
                                                            'iva_percentage'  => SaleHelper::normalize_iva_percentage_for_pivot($iva_percentage_nc),
                                                        ]);
                    }
                }
            }
        }
    }

    static function attachNotaCreditoServices($nota_credito, $items) {
        if (!is_null($items)) {
            $nota_credito->services()->detach();
            foreach ($items as $item) {
                if (isset($item['is_service'])) {

                    if (isset($item['unidades_devueltas'])) {

                        Log::info('attach service '.$item['name'].' con '.$item['unidades_devueltas']);
                        $nota_credito->services()->attach($item['id'], [
                                                            'amount'    => $item['unidades_devueltas'],
                                                            'price'     => $item['price_vender'],
                                                            'discount'  => $item['discount'],
                                                        ]);
                    }
                }
            }
        }
    }

    static function attachNotaCreditoDescriptions($nota_credito, $descriptions) {
        if (!is_null($descriptions)) {

            foreach ($descriptions as $description) {

                if (isset($description['price'])) {

                    NotaCreditoDescription::create([
                        'notes'     => $description['notes'],
                        'price'     => $description['price'],
                        'iva_id'    => $description['iva_id'],
                        'current_acount_id'    => $nota_credito->id,
                    ]);
                }
            }
        }
    }

    static function procesarPago($model_name, $model_id, $haber, $until_pago, $to_pay_id = null) {
        $detalle = '';
        if (!is_null($to_pay_id)) {
            $until_pago->to_pay_id = $to_pay_id;
            $until_pago->save();
            $haber = Self::saldarSpecificCurrentAcount($to_pay_id, $until_pago, $haber);
        } 
        $haber_restante = Self::saldarPagandose($model_name, $model_id, $haber, $until_pago);
        // $saldar_pagandose = Self::saldarPagandose($model_name, $model_id, $haber, $until_pago);
        // $haber_restante = $saldar_pagandose['haber'];
        // $detalle .= $saldar_pagandose['detalle'];    
        // $detalle .= Self::saldarCuentasSinPagar($model_name, $model_id, $haber_restante, $until_pago);
        Self::saldarCuentasSinPagar($model_name, $model_id, $haber_restante, $until_pago);
        // return $detalle;
    }

    static function saldarPagandose($model_name, $model_id, $haber, $until_pago) {
        // $detalle = '';
        $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        while (!is_null($sin_pagar) && !is_null($pagandose) && $sin_pagar->created_at->lt($pagandose->created_at) && $haber > 0) {
            $haber = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            // $res = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            // $detalle .= $res['detalle'];
            // $haber = $res['haber'];
            $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
            $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        }
        while (!is_null($pagandose) && $haber > 0) {
            $haber += $pagandose->pagandose;
            $pagandose->pagandose = 0;
            $pagandose->save();
            $haber = Self::saldarCurrentAcount($pagandose, $haber, $until_pago);
            // $res = Self::saldarCurrentAcount($pagandose, $haber, $until_pago);
            // $detalle .= $res['detalle'];
            // $haber = $res['haber'];
            $pagandose = Self::getFirstPagandose($model_name, $model_id, $until_pago);
        }
        return $haber;
    }

    static function saldarCuentasSinPagar($model_name, $model_id, $haber, $until_pago = null) {
        $detalle = '';
        $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        while (!is_null($sin_pagar) && $haber > 0) {
            $haber = Self::saldarCurrentAcount($sin_pagar, $haber, $until_pago);
            $sin_pagar = Self::getFirstSinPagar($model_name, $model_id, $until_pago);
        }
        return $detalle;
    }

    static function saldarSpecificCurrentAcount($to_pay_id, $pago, $haber) {
        $current_acount = CurrentAcount::find($to_pay_id);
        if (!is_null($current_acount)) {
            $haber =  Self::saldarCurrentAcount($current_acount, $haber, $pago);
            return $haber;
        }
    }

    static function saldarCurrentAcount($current_acount, $haber, $pago) {
        if ($haber >= $current_acount->debe) {
            $current_acount->status = 'pagado';
            $current_acount->save();
            Self::savePagadoPor($current_acount, $pago, $haber);
            // Grupo 268 · Prompt 02, bug F: faltaba el segundo argumento ($pago, ya disponible
            // como parametro de esta funcion) -> ArgumentCountError fatal si este camino corria
            // (checkCommissionStatus($current_acount, $pago) pide dos, sin default).
            SellerCommissionHelper::checkCommissionStatus($current_acount, $pago);
            $haber -= $current_acount->debe;
        } else { 
            $previus_pagandose = $current_acount->pagandose;
            if ($current_acount->status == 'pagandose') {
                $current_acount->pagandose += $haber;
            } else {
                $current_acount->status = 'pagandose';
                $current_acount->pagandose = $haber;
            }
            $current_acount->save();
            Self::savePagadoPor($current_acount, $pago, $haber - $current_acount->debe);
            $haber = 0;
        }
        return $haber;
    }

    static function savePagadoPor($current_acount, $pago, $haber) {
        $current_acount->pagado_por()->attach($pago->id, [
            'pagado'        => $haber,
            'total_pago'    => $pago->haber,
        ]);
    }

    static function getFirstSinPagar($model_name, $model_id, $until_pago) {
        $first = CurrentAcount::where('status', 'sin_pagar')
                                ->orderBy('created_at', 'ASC')
                                ->where('created_at', '<', $until_pago->created_at);
        if ($model_name == 'client') {
            $first = $first->where('client_id', $model_id);
        } else {
            $first = $first->where('provider_id', $model_id);
        }
        $first = $first->first();
        return $first;
    }

    static function getFirstPagandose($model_name, $model_id, $until_pago) {
        $pagandose = CurrentAcount::where('status', 'pagandose')
                                ->orderBy('created_at', 'ASC')
                                ->where('created_at', '<', $until_pago->created_at);
        if ($model_name == 'client') {
            $pagandose = $pagandose->where('client_id', $model_id);
        } else {
            $pagandose = $pagandose->where('provider_id', $model_id);
        }
        $pagandose = $pagandose->first();
        return $pagandose;
    }

    static function check_saldos_y_pagos($credit_account_id) {
        Self::checkSaldos($credit_account_id);
        Self::checkPagos($credit_account_id);
    }

    /**
     * Recalcula la cadena de saldos de una cuenta corriente: cada movimiento no provisorio queda con
     * el saldo del anterior más su debe (o menos su haber), en orden `created_at, id`, y la cuenta y
     * su dueño quedan con el saldo del último.
     *
     * 🔴 REESCRITO EN LA MISIÓN cuenta-corriente-carrera-y-velocidad (23/9/2026). Lo que cambió y por
     * qué:
     *   - UNA SOLA LECTURA, CON CANDADO. Antes se leía la lista con un SELECT común y después, por
     *     cada movimiento, el saldo del anterior con otro (`getSaldo()`): 192 movimientos en Fenix eran
     *     193 consultas y 35 segundos, y en REPEATABLE READ todas veían la foto de la transacción, no lo
     *     último commiteado. Eso es la carrera de la venta 54160. Ahora las filas se leen de una vez con
     *     `FOR UPDATE` —que siempre ve lo último commiteado— y el saldo se acumula en memoria.
     *   - EL MISMO ORDEN QUE getSaldo(). Antes el recálculo ordenaba solo por `created_at` y el saldo
     *     anterior se buscaba por `created_at, id`: con dos movimientos en el mismo segundo los dos
     *     órdenes podían no coincidir.
     *   - SOLO SE GUARDAN LAS FILAS CUYO SALDO CAMBIÓ. Antes se guardaban todas.
     *   - CORRE EN SU PROPIA TRANSACCIÓN (un savepoint, si ya hay una abierta) Y CON EL CANDADO DE LA
     *     CUENTA. Adentro de un request el candado ya lo tomó la entrada y acá es gratis; lo que cubre
     *     es a los comandos y jobs que recalculan cuentas en el fondo, que ahora esperan al request que
     *     está escribiendo esa misma cuenta en vez de pisarle la cadena con una lectura vieja.
     *   - Un movimiento sin debe ni haber queda con el saldo del anterior (antes no se tocaba y el
     *     siguiente arrancaba de lo que tuviera guardado).
     *
     * La firma y la semántica de `$from_current_acount` no cambiaron: con un movimiento de arranque se
     * recalculan los posteriores (`>` o, con `$mayor_o_igual`, `>=` por `created_at`) partiendo del
     * saldo del movimiento anterior al primero que se recalcula, leído con una sola consulta.
     *
     * @param  int  $credit_account_id
     * @param  \App\Models\CurrentAcount|null  $from_current_acount
     * @param  bool  $mayor_o_igual
     * @return null
     */
    static function checkSaldos($credit_account_id, $from_current_acount = null, $mayor_o_igual = false) {

        if (! app()->runningInConsole()) {
            Log::info('checkSaldos para credit_account id '.$credit_account_id);
        }

        DB::transaction(function () use ($credit_account_id, $from_current_acount, $mayor_o_igual) {

            Self::recalcular_cadena_de_saldos($credit_account_id, $from_current_acount, $mayor_o_igual);
        });

        return null;
    }

    /**
     * El cuerpo de checkSaldos(), ya adentro de una transacción. No llamar directo.
     *
     * @param  int  $credit_account_id
     * @param  \App\Models\CurrentAcount|null  $from_current_acount
     * @param  bool  $mayor_o_igual
     * @return void
     */
    static function recalcular_cadena_de_saldos($credit_account_id, $from_current_acount, $mayor_o_igual) {

        $credit_account = CreditAccount::find($credit_account_id);

        if (is_null($credit_account)) {
            // Hay llamadores viejos que todavía pasan ('client', $id): antes reventaban con un
            // "Trying to get property of non-object" y siguen reventando, pero diciendo por qué.
            throw new \RuntimeException('checkSaldos: no existe la credit_account '.var_export($credit_account_id, true).'.');
        }

        CuentaCorrienteLock::bloquear($credit_account->model_name, $credit_account->model_id);

        $current_acounts = CurrentAcount::where('credit_account_id', $credit_account->id)
                                        ->where('is_provisorio', 0);

        if (!is_null($from_current_acount)) {

            if ($mayor_o_igual) {
                $operador = '>=';
            } else {
                $operador = '>';
            }

            $current_acounts = $current_acounts->where('created_at', $operador, $from_current_acount->created_at);
        }

        $current_acounts = $current_acounts->orderBy('created_at', 'ASC')
                                            ->orderBy('id', 'ASC')
                                            ->lockForUpdate()
                                            ->get();

        if (! app()->runningInConsole()) {
            Log::info(count($current_acounts).' movimientos');
        }

        $saldo = 0;

        if (!is_null($from_current_acount) && count($current_acounts) >= 1) {
            // El saldo del movimiento anterior al primero que se recalcula (con candado: estamos
            // adentro de una transacción).
            $saldo = (float) Self::getSaldo($credit_account->id, $current_acounts[0]);
        }

        $guardados = 0;

        foreach ($current_acounts as $current_acount) {

            $saldo = Numbers::redondear($saldo + Self::aporte_al_saldo($current_acount));

            if (is_null($current_acount->saldo) || abs((float) $current_acount->saldo - $saldo) > 0.001) {

                $current_acount->saldo = $saldo;
                $current_acount->save();

                $guardados++;
            }
        }

        if (count($current_acounts) >= 1) {
            $credit_account->saldo = $saldo;
        } else if (!is_null($from_current_acount)) {
            // No había nada después del movimiento de arranque: el saldo de la cuenta es el del
            // último movimiento (antes quedaba en 0 por error).
            $credit_account->saldo = (float) Self::getSaldo($credit_account->id);
        } else {
            $credit_account->saldo = 0;
        }

        $credit_account->save();

        Self::set_model_saldo($credit_account);

        if (! app()->runningInConsole()) {
            Log::info('Seteando saldo de credit_account id '.$credit_account_id.' con '.$credit_account->saldo.' ('.$guardados.' movimientos con el saldo corregido)');
        }
    }

    /**
     * Lo que un movimiento le suma a la cadena de saldos: su debe, menos su haber, o nada. Mismo
     * criterio que usó siempre checkSaldos(): si tiene debe cuenta el debe, aunque también tenga
     * haber. Lo comparten el recálculo y la detección de cadenas cortadas, para que no puedan
     * dejar de estar de acuerdo.
     *
     * @param  object  $current_acount  Modelo o fila con `debe` y `haber`.
     * @return float
     */
    static function aporte_al_saldo($current_acount) {

        if (!is_null($current_acount->debe)) {
            return (float) $current_acount->debe;
        }

        if (!is_null($current_acount->haber)) {
            return -(float) $current_acount->haber;
        }

        return 0.0;
    }

    static function set_model_saldo($credit_account) {

        $model_name = GeneralHelper::getModelName($credit_account->model_name);

        $model = $model_name::find($credit_account->model_id);

        if ($model) {

            if (! app()->runningInConsole()) {
                Log::info('Se actualizo saldo de '.$model->name);

                if ($credit_account->moneda_id == 1) {
                    Log::info('saldo_pesos: '.$credit_account->saldo);
                } else if ($credit_account->moneda_id == 2) {
                    Log::info('saldo_dolares: '.$credit_account->saldo);
                }
            }

            if ($credit_account->moneda_id == 1) {
                
                $model->saldo_pesos = $credit_account->saldo;

            } else if ($credit_account->moneda_id == 2) {

                $model->saldo_dolares = $credit_account->saldo;
            }

            $model->save();
        }


    }


    /**
     * Re-imputa la cuenta entera: borra las imputaciones de todos los débitos, los deja sin pagar y
     * vuelve a correr cada pago contra ellos, en orden.
     *
     * Misión cuenta-corriente-carrera-y-velocidad (23/9/2026): la lógica no cambió. Lo que cambió es
     * que corre en su propia transacción (un savepoint si ya hay una abierta: antes, un corte a mitad
     * dejaba los débitos reseteados y sin imputar), con el candado de la cuenta, y que los débitos y
     * los pagos se leen CON CANDADO —ven lo último commiteado, no la foto de la transacción— y
     * desempatando por `id`, el mismo orden de la cadena de saldos.
     *
     * @param  int  $credit_account_id
     * @return void
     */
    static function checkPagos($credit_account_id) {

        DB::transaction(function () use ($credit_account_id) {

            Self::reimputar_pagos($credit_account_id);
        });
    }

    /**
     * El cuerpo de checkPagos(), ya adentro de una transacción. No llamar directo.
     *
     * @param  int  $credit_account_id
     * @return void
     */
    static function reimputar_pagos($credit_account_id) {

        if (! app()->runningInConsole()) {
            Log::info('checkPagos');
        }

        $credit_account = CreditAccount::find($credit_account_id);

        CuentaCorrienteLock::bloquear($credit_account->model_name, $credit_account->model_id);

        $debitos = CurrentAcount::orderBy('created_at', 'ASC')
                                ->orderBy('id', 'ASC')
                                ->where('credit_account_id', $credit_account_id)
                                ->whereNotNull('debe')
                                ->where($credit_account->model_name.'_id', $credit_account->model_id)
                                ->lockForUpdate()
                                ->get();

        $debito_ids = $debitos->pluck('id');

        // Eliminar todas las imputaciones existentes de estos débitos en un solo query
        // (reemplaza el detach() individual por cada débito)
        DB::table('pagado_por')->whereIn('debe_id', $debito_ids)->delete();

        // Resetear todos los débitos a sin_pagar en un solo UPDATE masivo
        // (reemplaza el save() individual por cada débito)
        CurrentAcount::whereIn('id', $debito_ids)->update([
            'pagandose' => 0,
            'status'    => 'sin_pagar',
        ]);

        $pagos = CurrentAcount::orderBy('created_at', 'ASC')
                                    ->orderBy('id', 'ASC')
                                    ->where('is_provisorio', 0)
                                    ->whereNotNull('haber')
                                    ->where('credit_account_id', $credit_account_id)
                                    ->where($credit_account->model_name.'_id', $credit_account->model_id)
                                    ->lockForUpdate()
                                    ->get();

        /*
         * Puntos para clientes: se SUSPENDE el enganche que cuelga del final de
         * CurrentAcountPagoHelper::init() mientras dura este loop, y se reconcilia una sola vez
         * al final de esta función (más abajo).
         *
         * El enganche de init() es el que cubre todos los caminos que dejan un débito en
         * 'pagado' sin pasar por acá —el cobro de todos los días, entre ellos—, pero esta
         * función llama a init() UNA VEZ POR PAGO de la cuenta: sin suspenderlo, una cuenta con
         * 30 pagos reconciliaría todas sus ventas 31 veces. No duplicaría un punto (el
         * reconciliador compara contra lo escrito y no escribe si no cambió nada), pero le
         * multiplicaría el costo en consultas a los dos jobs de fondo que barren todas las
         * cuentas de todos los clientes.
         *
         * El try/finally no es decorativo: si un pago tira una excepción, el enganche tiene que
         * volver a prenderse igual, o el módulo queda mudo para el resto del request.
         */
        PuntosAcumulacionHelper::suspender();

        try {

            foreach ($pagos as $pago) {
                // El detach de pagando_a es un no-op: ya se eliminaron todas las filas
                // de pagado_por en el batch delete anterior (por debe_id)
                // Se pasa $credit_account para evitar un CreditAccount::find por cada pago
                $pago_helper = new CurrentAcountPagoHelper($credit_account_id, $credit_account->model_name, $credit_account->model_id, $pago, $credit_account);
                $pago_helper->init();
            }

        } finally {

            PuntosAcumulacionHelper::reanudar();
        }

        // Grupo 268 · Prompt 02, bug D: los debitos ya quedaron con su estado definitivo (los dos
        // loops de arriba ya corrieron), asi que se revierten las comisiones que se habian dado
        // por liquidadas de una venta que dejo de estar saldada (ej. se borro el pago que la saldaba).
        SellerCommissionHelper::revertirComisionesNoSaldadas($credit_account_id);

        /*
         * Puntos para clientes (misión del 22/8/2026). Va acá, al final, y UNA SOLA VEZ para
         * toda la función, porque esta función RE-IMPUTA la cuenta entera: borra las
         * imputaciones, resetea todos los débitos a 'sin_pagar' y vuelve a correr el pago por
         * cada pago. O sea que la transición "el débito llegó a pagado" se dispara N veces por
         * UN solo hecho económico. Por eso el loop de arriba corre con el enganche de
         * CurrentAcountPagoHelper::init() suspendido y la reconciliación se hace acá, con los
         * débitos ya en su estado definitivo.
         *
         * 🔴 Y esta llamada NO reemplaza a la de init(): checkPagos() es UNO de los caminos por
         * los que un débito llega a 'pagado', no el único. El cobro de todos los días
         * (CurrentAcountController@pago con current_date = 1, el default de la SPA) no pasa por
         * acá — hasta el 22/8/2026 ése era justamente el bug: los puntos aparecían recién de
         * rebote, cuando alguna otra cosa disparaba checkPagos(). Las dos llamadas son la misma
         * regla vista desde los dos lados: init() cubre la familia, esta cubre el costo.
         *
         * PuntosAcumulacionHelper compara contra lo que ya está escrito y no escribe si no
         * cambió nada, así que correr esto en cada una de las once llamadas a checkPagos() no
         * duplica un punto. En un comercio sin la extensión son CERO consultas: el helper corta
         * con la respuesta memoizada de PuntosConfigHelper antes de mirar una sola fila.
         *
         * Se le pasa $credit_account (que ya está en memoria acá arriba) y no su id, para no
         * pagar un CreditAccount::find de más en los jobs que barren todas las cuentas.
         */
        PuntosAcumulacionHelper::reconciliar_cuenta_corriente($credit_account);

        // $model->pagos_checkeados = 1;
        // $model->save();

    }

    static function checkSaldoInicial($client_id) {
        $saldo_inicial = CurrentAcount::where('client_id', $client_id)
                                        ->where('detalle', 'Saldo inicial')
                                        ->first();
        if (!is_null($saldo_inicial)) {
            if ($saldo_inicial->haber) {
                $saldo_inicial->status = 'pago_from_client';
                $saldo_inicial->saldo = $saldo_inicial->haber;
            } else if ($saldo_inicial->debe) {
                $saldo_inicial->status = 'sin_pagar';
                $saldo_inicial->pagandose = null;
                $saldo_inicial->saldo = $saldo_inicial->debe;
            }
            $saldo_inicial->save();
        } 
        return $saldo_inicial;
    }

    static function isSaldoInicial($current_acount) {
        return $current_acount->detalle == 'Saldo inicial';
    }

    static function getDescription($sale, $total = null) {
        if (count($sale->discounts) >= 1) {
            if (!is_null($total)) {
                $description = '$'.Numbers::price($total);
            } else {
                $description = '$'.Numbers::price($sale->total);
            }
            foreach ($sale->discounts as $discount) {
                $description .= '(-'.$discount->pivot->percentage . '% '. substr($discount->name, 0, 3) .')';
            }
            foreach ($sale->surchages as $surchage) {
                $description .= '(+'.$surchage->pivot->percentage . '% '. substr($surchage->name, 0, 3) .')';
            }
            return $description;
        } else {
            return null;
        }
    }

    static function getCurrentAcountsSinceMonths($model_name, $model_id, $months_ago) {
        $months_ago = Carbon::now()->subMonths($months_ago);
        $current_acounts = CurrentAcount::whereDate('created_at', '>=', $months_ago)
                                        ->orderBy('created_at', 'ASC')
                                        ->with(['sale' => function($q) {
                                            return $q->withAll();
                                        }])
                                        ->with(['budget' => function($q) {
                                            return $q->withAll();
                                        }])
                                        ->with('payment_method')
                                        ->with('checks');
        if ($model_name == 'client') {
            $current_acounts = $current_acounts->where('client_id', $model_id);
        } else {
            $current_acounts = $current_acounts->where('provider_id', $model_id);
        }
        $current_acounts = $current_acounts->get();
        $current_acounts = Self::format($current_acounts);
        return $current_acounts;
    }

    static function format($current_acounts) {
        foreach ($current_acounts as $current_acount) {
            if (!is_null($current_acount->num_receipt)) {
                $current_acount->detalle = 'Recibo pago '.$current_acount->num_receipt;
                // $current_acount->detalle = 'ReciboPago'.Self::getFormatedNum($current_acount->num_receipt);
            }
            if (!is_null($current_acount->sale_id)) {
                $current_acount->detalle = 'Remito N°'.Self::getNum('sales', $current_acount->sale_id, 'num');
            }
            if (!is_null($current_acount->budget_id)) {
                $current_acount->detalle = 'Presupuesto N°'.Self::getNum('budgets', $current_acount->budget_id ,'num');
            }
            if (!is_null($current_acount->provider_order_id)) {
                // No lleva el numero de comprobante del proveedor (ver ProviderOrder::detalle_current_acount())
                // porque acá no está cargado el modelo completo, solo el num vía getNum() -- y esta
                // función hoy no tiene ningún llamador vivo (comentada en CurrentAcountController.php),
                // así que no vale la pena pagar una query extra por un dato que nadie ve todavía.
                $current_acount->detalle = 'Compra N°'.Self::getNum('provider_orders', $current_acount->provider_order_id ,'num');
            }
            if (!is_null($current_acount->order_production_id)) {
                $current_acount->detalle = 'Orden de produccion N°'.Self::getNum('order_productions', $current_acount->order_production_id ,'num');
            }
            if ($current_acount->status == 'nota_credito') {
                $current_acount->detalle = 'Nota credito';
            }
            if ($current_acount->detalle == 'Saldo inicial') {
                $current_acount->detalle = 'Saldo inicial';
            }
            if ($current_acount->detalle == 'Nota de debito') {
                $current_acount->detalle = 'Nota debito';
            }
            if (!is_null($current_acount->current_acount_payment_methods)) {
                foreach ($current_acount->current_acount_payment_methods as $payment_method) {
                    if (!is_null($payment_method->pivot->credit_card_id)) {
                        $credit_card = CreditCard::find($payment_method->pivot->credit_card_id);
                        $payment_method->credit_card = $credit_card;
                        if (!is_null($payment_method->pivot->credit_card_payment_plan_id)) {
                            $credit_card_payment_plan = CreditCardPaymentPlan::find($payment_method->pivot->credit_card_payment_plan_id);
                            $payment_method->credit_card_payment_plan = $credit_card_payment_plan;
                        }
                    }
                }
            }
        }
        return $current_acounts;
    }

    static function getNum($table, $id, $prop) {
        $model = DB::table($table)->where('id', $id)->first();
        if (!is_null($model)) {
            return $model->{$prop};
        }
        // return Self::getFormatedNum($model->{$prop});
    }

    static function getFormatedNum($num) {
        $letras_faltantes = 8 - strlen($num);
        $cbte_numero = '';
        for ($i=0; $i < $letras_faltantes; $i++) { 
            $cbte_numero .= '0'; 
        }
        $cbte_numero  .= $num;
        return $cbte_numero;
    }

}