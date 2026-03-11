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

        $sales_with_afip_errors = Sale::where('user_id', $this->userId())
                            ->with('address')
                            ->with('employee')
                            ->whereHas('afip_tickets', function($query) {
                                $query->whereNull('cae');
                            })
                            ->with('afip_tickets.afip_errors', 'afip_tickets.afip_observations')
                            ->orderBy('created_at', 'DESC');

        if (!$this->is_admin()) {
            $sales_with_afip_errors = $sales_with_afip_errors->where('employee_id', $this->userId(false));
        }

        $sales_with_afip_errors = $sales_with_afip_errors->get();

        $sales_with_afip_observations = Sale::where('user_id', $this->userId())
                            ->with('address')
                            ->whereHas('afip_tickets', function($query) {
                                $query->has('afip_errors')
                                        ->orHas('afip_observations');
                            })
                            ->with('afip_tickets.afip_errors', 'afip_tickets.afip_observations')
                            ->whereHas('afip_observations')
                            ->with('employee')
                            ->orderBy('created_at', 'ASC');

        if (!$this->is_admin()) {
            $sales_with_afip_observations = $sales_with_afip_observations->where('employee_id', $this->userId(false));
        }

        $sales_with_afip_observations = $sales_with_afip_observations->get();

        $errores_de_facturacion = [];

        // Log::info('sales_with_afip_errors:');
        // Log::info($sales_with_afip_errors);
        
        // Log::info('sales_with_afip_observations:');
        // Log::info($sales_with_afip_observations);

        foreach ($sales_with_afip_errors as $sale_afip_error) {

            foreach ($sale_afip_error->afip_tickets as $afip_ticket) {

                if (
                    is_null($afip_ticket->cae)
                    || $afip_ticket->cae == ''
                ) {

                    $errores_de_facturacion[] = $sale_afip_error;
                }
            }
            
        }

        foreach ($sales_with_afip_observations as $sale_afip_obs) {
            
            foreach ($sale_afip_error->afip_tickets as $afip_ticket) {

                if (
                    is_null($afip_ticket->cae)
                    || $afip_ticket->cae == ''
                ) {

                    if (!collect($errores_de_facturacion)->pluck('id')->contains($afip_ticket->id)) {
                        $errores_de_facturacion[] = $sale_afip_obs;
                    }
                }
            }
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

        return response()->json(['sale' => $this->fullModel('Sale', $afip_ticket->sale_id)], 200);
    }

    function destroy($id) {
        $model = AfipTicket::find($id);
        $sale_id = $model->sale_id;
        $model->delete();
        return response()->json(['sale' => $this->fullModel('Sale', $sale_id)], 200);
    }
}
