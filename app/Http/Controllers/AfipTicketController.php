<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipFexHelper;
use App\Http\Controllers\Helpers\Afip\AfipWSAAHelper;
use App\Http\Controllers\Helpers\Afip\AfipWsfeHelper;
use App\Models\AfipTicket;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AfipTicketController extends Controller
{

    function get_importes($sale_id) {
        $sale = Sale::find($sale_id);

        $afip_ticket = $sale->afip_tickets[0];

        $afip_helper = new AfipHelper($afip_ticket);

        $importes = $afip_helper->getImportes();

        $data = [
            'ver'           => 1,
            'fecha'         => date_format($afip_ticket->created_at, 'Y-m-d'),
            'cuit'          => $afip_ticket->cuit_negocio,
            'ptoVta'        => $afip_ticket->punto_venta,
            'tipoCmp'       => $afip_ticket->cbte_tipo,
            'nroCmp'        => $afip_ticket->cbte_numero,
            'importe'       => $afip_ticket->importe_total,
            'moneda'        => $afip_ticket->moneda_id,
            'ctz'           => 1,
            'tipoDocRec'    => AfipHelper::getDocType('Cuit'),
            'nroDocRec'     => $afip_ticket->cuit_cliente,
            'codAut'        => $afip_ticket->cae,
        ];
        $afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));

        return response()->json(['importes' => $importes, 'afip_qr_link' => $afip_link]);
    }

    function problemas_al_facturar() {

        /** Obtiene el usuario autenticado para filtrar ventas propias. */
        $user_id = $this->userId();

        /** Acumula ventas con problemas de facturación sin duplicados. */
        $errores_de_facturacion = [];

        /** Guarda los IDs de ventas ya agregadas para evitar duplicados. */
        $sale_ids_agregados = [];
        
        $afip_tickets_con_errores = AfipTicket::where(function($q) {
                                                $q->whereNull('cae')
                                                    ->orWhere('cae', '');
                                            })
                                            ->whereHas('sale', function($q2) use($user_id) {
                                                $q2->where('user_id', $user_id)
                                                   /**
                                                    * Excluye ventas individuales que ya fueron incluidas en una consolidación
                                                    * de facturación: su comprobante se emite a través de la venta consolidada.
                                                    * Las propias ventas contenedoras (is_consolidacion_facturacion=1) sí
                                                    * deben aparecer aquí si su ticket AFIP tiene problemas.
                                                    */
                                                   ->whereNull('consolidacion_facturacion_id');
                                            })
                                            ->with('sale.afip_tickets.afip_observations', 'sale.afip_tickets.afip_errors')
                                            ->orderBy('created_at', 'DESC')
                                            ->get();

        foreach ($afip_tickets_con_errores as $afip_ticket) {

            /** Si la venta ya fue agregada, evita volver a incluirla. */
            if (in_array($afip_ticket->sale_id, $sale_ids_agregados)) {
                continue;
            }

            $errores_de_facturacion[] = $afip_ticket->sale;
            $sale_ids_agregados[] = $afip_ticket->sale_id;
        }

        return response()->json(['models' => $errores_de_facturacion], 200);
    }       

    function consultar_comprobante($afip_ticket_id) {

        $afip_ticket = AfipTicket::find($afip_ticket_id);

        if ($afip_ticket) {

            $afip_tipo_comprobante = $afip_ticket->afip_tipo_comprobante;

            $testing = !$afip_ticket->afip_information->afip_ticket_production;

            // Comprobantes de exportación (si tipo es 19, 20, etc.)
            if (
                $afip_tipo_comprobante->codigo == 19
            ) {

                Log::info('Exportacion');
                $afip_wsaa = new AfipWSAAHelper($testing, 'wsfex');
                $afip_wsaa->checkWsaa();


                $helper = new AfipFexHelper($afip_ticket, $testing);
                
            } else {
                
                Log::info('NO Exportacion');
                $afip_wsaa = new AfipWSAAHelper($testing, 'wsfe');
                $afip_wsaa->checkWsaa();

                $helper = new AfipWsfeHelper($afip_ticket, $testing);
            }

            $helper->consultar_comprobante();
        }

        $afip_ticket = AfipTicket::find($afip_ticket_id);

        return response()->json([
            'sale' => $this->fullModel('Sale', $afip_ticket->sale_id),
            'afip_ticket'   => $afip_ticket,
        ], 200);
    }

    function destroy($id) {
        $model = AfipTicket::find($id);
        $sale_id = $model->sale_id;
        $model->delete();
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 200);
    }
}
