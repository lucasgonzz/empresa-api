<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\AfipWsController;

/** Borde único de red de MakeAfipTicket: los tests lo reemplazan sin construir clientes SOAP. */
class TransporteDeFacturaHelper
{
    public function emitir($ticket): void
    {
        (new AfipWsController($ticket))->init();
    }

    public function conciliar($ticket): void
    {
        $this->consultar_configurado($ticket, true);
    }

    public function consultar($ticket): void
    {
        // El carril manual conserva las consultas de backfill de IVA y ganancia, incluso con CAE.
        $this->consultar_configurado($ticket, !empty($ticket->firma_datos_fiscales));
    }

    private function consultar_configurado($ticket, bool $identidad_estricta): void
    {
        $exportacion = (int) $ticket->afip_tipo_comprobante->codigo === 19;
        $helper = $this->helper_para_consultar($ticket);
        if ($exportacion) {
            $helper->exigir_identidad_de_intento = $identidad_estricta;
        }
        // Un envío con respuesta incierta se CONSULTA. Nunca se vuelve a pedir otro CAE acá.
        $helper->consultar_comprobante();
    }

    protected function helper_para_consultar($ticket)
    {
        $exportacion = (int) $ticket->afip_tipo_comprobante->codigo === 19;
        $testing = !$ticket->afip_information->afip_ticket_production;
        (new AfipWSAAHelper($testing, $exportacion ? 'wsfex' : 'wsfe'))->checkWsaa();
        return $exportacion ? new AfipFexHelper($ticket) : new AfipWsfeHelper($ticket, $testing);
    }
}
