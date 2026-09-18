<?php

namespace App\Http\Controllers\Helpers\Afip;

use Illuminate\Support\Facades\Log;

class AfipWsHelper {

    /**
     * Codigos de ARCA de los comprobantes de EXPORTACION: 19 (Factura E) y 21 (Nota de Credito E).
     *
     * 🔴 Una exportacion NO TIENE IVA. No es "IVA sin medir" ni un dato que falte: el webservice de
     * exportacion (WSFEX) directamente no tiene campo de IVA, y el comprobante no discrimina
     * ninguno. Esta lista existe para que esa regla se escriba UNA sola vez y la compartan los
     * cuatro lugares que la preguntan: `getTipoLetra()` aca abajo, `AfipItemCalculator::exportacion()`,
     * `AfipFexHelper::update_afip_ticket()` (que escribe el 0 al emitir) y
     * `IvaDeVentaHelper::subquery_por_venta()` (que lo lee al medir la ganancia).
     *
     * El 20 (Nota de Debito E) queda deliberadamente afuera: el sistema no lo emite, y la lista
     * espeja lo que ya decidian los dos call sites que existian antes de esta mision.
     */
    const CBTE_TIPOS_EXPORTACION = [19, 21];

    /**
     * Codigos de comprobante de exportacion, para armar un `whereIn` o un `IN (...)` de SQL.
     *
     * @return array<int,int>
     */
    static function codigos_de_exportacion() {
        return self::CBTE_TIPOS_EXPORTACION;
    }

    /**
     * ¿Este codigo de comprobante es de exportacion?
     *
     * @param  mixed $cbte_tipo Codigo de ARCA (puede venir como string de la base).
     * @return bool
     */
    static function es_de_exportacion($cbte_tipo) {

        if (is_null($cbte_tipo) || !is_numeric($cbte_tipo)) {
            return false;
        }

        return in_array((int) $cbte_tipo, self::CBTE_TIPOS_EXPORTACION, true);
    }

    static function getTipoLetra($cbte_tipo) {

        Log::info('getTipoLetra: '.$cbte_tipo);

        if ($cbte_tipo == 1 || $cbte_tipo == 3 || $cbte_tipo == 201) {
            return 'A';
        }
        if ($cbte_tipo == 6 || $cbte_tipo == 8 || $cbte_tipo == 206) {
            return 'B';
        }
        if ($cbte_tipo == 11 || $cbte_tipo == 13 || $cbte_tipo == 211) {
            return 'C';
        }
        if ($cbte_tipo == 51) {
            return 'M';
        }
        if (self::es_de_exportacion($cbte_tipo)) {
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
