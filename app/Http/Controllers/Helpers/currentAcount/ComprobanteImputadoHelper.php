<?php

namespace App\Http\Controllers\Helpers\currentAcount;

class ComprobanteImputadoHelper {

    /**
     * Devuelve el AfipTicket autorizado (con CAE) mas reciente de la venta, o null si no tiene
     * ninguno.
     *
     * Un afip_ticket sin CAE es un intento de facturacion que AFIP rechazo o que quedo a
     * medias; imprimirlo en el recibo como si fuera una factura le estaria dando al cliente
     * un numero de comprobante que no existe en AFIP.
     *
     * @param \App\Models\Sale|null $sale
     * @return \App\Models\AfipTicket|null
     */
    static function get_afip_ticket_autorizado($sale) {
        if (is_null($sale)) {
            return null;
        }

        return $sale->afip_tickets()
                        ->whereNotNull('cae')
                        ->where('cae', '!=', '')
                        ->with('afip_tipo_comprobante')
                        ->orderBy('id', 'DESC')
                        ->first();
    }

    /**
     * Arma el texto del comprobante, ej: 'Factura A 00003-00000123'.
     *
     * El padding 5-8 no es un numero magico: es la misma convencion del header fiscal de los
     * PDF de venta (AfipPdfHelper::build_emisor_field_values), para que un comprobante se vea
     * igual en el recibo y en la factura.
     *
     * @param \App\Models\AfipTicket $afip_ticket
     * @return string
     */
    static function get_texto_factura($afip_ticket) {
        $punto_venta = str_pad((string) $afip_ticket->punto_venta, 5, '0', STR_PAD_LEFT);
        $numero = str_pad((string) $afip_ticket->cbte_numero, 8, '0', STR_PAD_LEFT);

        if ($afip_ticket->afip_tipo_comprobante && $afip_ticket->afip_tipo_comprobante->name) {
            // El name de afip_tipo_comprobante YA incluye la letra ("Factura A"), asi que
            // NO se concatena cbte_letra aca: quedaria "Factura A A".
            $nombre = $afip_ticket->afip_tipo_comprobante->name;
        } else {
            // Fallback para tickets viejos sin afip_tipo_comprobante_id.
            $nombre = 'Factura';
            if (!empty($afip_ticket->cbte_letra)) {
                $nombre .= ' '.$afip_ticket->cbte_letra;
            }
        }

        return $nombre.' '.$punto_venta.'-'.$numero;
    }

    /**
     * Texto completo de un renglon de comprobante imputado (o del concepto del recibo): el
     * numero de venta y, si tiene un comprobante autorizado, la factura a continuacion.
     *
     * @param \App\Models\CurrentAcount|null $current_acount
     * @return string
     */
    static function get_detalle($current_acount) {
        if (is_null($current_acount)) {
            return '';
        }

        if (!$current_acount->sale) {
            return (string) $current_acount->detalle;
        }

        $detalle = 'Venta N° '.$current_acount->sale->num;

        $afip_ticket = self::get_afip_ticket_autorizado($current_acount->sale);
        if ($afip_ticket) {
            $detalle .= ' - '.self::get_texto_factura($afip_ticket);
        }

        return $detalle;
    }
}
