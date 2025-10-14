<?php

namespace App\Http\Controllers\Helpers;

use App\Models\Cheque;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Helpers\UserHelper;

class ChequeHelper {

    static function crear_cheque($pago, $payment_method) {
        
        Cheque::create([

            'numero'                    => $payment_method['numero'] ?? null,
            'banco'                     => $payment_method['banco'] ?? null,
            'amount'                    => $payment_method['amount'] ?? null,
            'fecha_emision'             => $payment_method['fecha_emision'] ?? null,
            'fecha_pago'                => $payment_method['fecha_pago'] ?? null,
            'es_echeq'                  => $payment_method['es_echeq'] ?? 0,

            // Tipo de cheque: recibido (de cliente) o emitido (a proveedor)
            'tipo'                      => !is_null($pago->client_id) ? 'recibido' : 'emitido',

            // Cliente que entregó el cheque (si tipo = recibido)
            'client_id'                 => $pago->client_id,

            // Proveedor al que se le emitió el cheque (si tipo = emitido)
            'provider_id'               => $pago->provider_id,
            'endosado_desde_client_id'               => isset($payment_method['endosado_desde_client_id']) ? $payment_method['endosado_desde_client_id'] : null,

            // Cuenta corriente relacionada
            'current_acount_id'         => $pago->id,

            // Usuario que registró el cheque
            'employee_id'               => UserHelper::userId(false),
            'user_id'                   => UserHelper::userId(),

            // Caja utilizada al momento de cobro (recibido) o egreso (emitido)
            'caja_id'                   => null,

            // Datos de endoso (solo si tipo = recibido)
            'endosado_a_provider_id'    => null,
            'fecha_endoso'              => null,

            // Estado actual manual (solo si fue cobrado o rechazado)
            'estado_manual'             => null,
        ]);
    }

}