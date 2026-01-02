<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\Log;

class AfipWsHelper {

    static function getTipoLetra($cbte_tipo) {

        Log::info('getTipoLetra: '.$cbte_tipo);

        if ($cbte_tipo == 1 || $cbte_tipo == 201) {
            return 'A';
        }
        if ($cbte_tipo == 6 || $cbte_tipo == 206) {
            return 'B';
        }
        if ($cbte_tipo == 11 || $cbte_tipo == 211) {
            return 'C';
        }
        if ($cbte_tipo == 51) {
            return 'M';
        }
        if ($cbte_tipo == 19 || $cbte_tipo == 21) {
            return 'E';
        }
    }


    static function update_sale_total_facturado($afip_ticket, $importe) {

        $total_facturado = $importe;

        if ($afip_ticket->sale->total_facturado) {

            // Log::info('Sumando total_facturado de sale de '.$afip_ticket->sale->total_facturado);
            $total_facturado += (float)$afip_ticket->sale->total_facturado;
        }
        
        $afip_ticket->sale->total_facturado = $total_facturado;
        $afip_ticket->sale->save();
    }

}
