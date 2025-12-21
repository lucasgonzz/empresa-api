<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Models\AfipTicket;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AfipController extends Controller
{
    
    public function exportVentas($inicio, $fin)
    {
        $inicioCarbon = Carbon::parse($inicio)->startOfMonth();
        $finCarbon = Carbon::parse($fin)->endOfMonth();

        // $afip_tickets = Sale::with(['afip_ticket', 'afip_information'])
        //     ->where('user_id', $this->userId())
        //     ->whereBetween('created_at', [$inicioCarbon, $finCarbon])
        //     ->orderBy('created_at', 'ASC')
        //     ->get();

        // $afip_tickets = AfipTicket::whereHas('sale', function($q) {
        //                                 $q->where('user_id', $this->userId());
        //                             })
        //                             ->whereBetween('created_at', [$inicioCarbon, $finCarbon])
        //                             ->orderBy('created_at', 'ASC')
        //                             ->get();
        
        $afip_tickets = AfipTicket::whereBetween('created_at', [$inicioCarbon, $finCarbon])
                                    ->orderBy('created_at', 'ASC')
                                    ->get();

        $lines = [];

        foreach ($afip_tickets as $afip_ticket) {

            $ticket = $afip_ticket;
            if (!$ticket || !$ticket->cae) continue;

            $sale = $afip_ticket->sale;
            if (is_null($sale)) {
                $sale = Sale::find($afip_ticket->sale_nota_credito_id);
            }

            if (is_null($sale)) {
                dd('No hay sale para afip_ticket '.$afip_ticket->id);
            }



            $fecha = Carbon::parse($ticket->created_at)->format('Ymd');
            $tipo_comprobante = str_pad($ticket->cbte_tipo, 3, '0', STR_PAD_LEFT);
            $punto_venta = str_pad($ticket->punto_venta, 5, '0', STR_PAD_LEFT);
            $nro_cbte = str_pad($ticket->cbte_numero, 20, '0', STR_PAD_LEFT);

            $nro_cbte_hasta = $nro_cbte; // mismo que el anterior si es un solo comprobante


            $res = AfipSolicitarCaeHelper::get_doc_client($sale);
            $codigo_doc = str_pad($res['doc_type'], 2, '0', STR_PAD_LEFT); // 96 = DNI

            $nro_doc = $res['doc_client'];
            if ($nro_doc == 'NR') {
                $nro_doc = 00;
            }
            $nro_doc = str_pad($nro_doc, 20, '0', STR_PAD_LEFT);

            $client_name = "SINNOMBRE";
            if ($sale->client) {
                $client_name = $sale->client->name;
            }
            $comprador = str_pad(substr($client_name, 0, 30), 30, ' ');

            $importe_total = str_pad(number_format($ticket->importe_total, 2, '', ''), 15, '0', STR_PAD_LEFT);

            $conceptos_no_gravados = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_no_categorizados = str_pad('0', 15, '0', STR_PAD_LEFT);
            $importe_exento = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_nacional = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_iibb = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_municipal = str_pad('0', 15, '0', STR_PAD_LEFT);
            $impuestos_internos = str_pad('0', 15, '0', STR_PAD_LEFT);

            $moneda = 'PES';
            $tipo_cambio = str_pad('0001000000', 10, '0', STR_PAD_LEFT); // 1.000000

            $cantidad_iva = $this->get_cantidad_iva($sale);

            $codigo_operacion = '0'; // operación general
            $otros_tributos = str_pad('0', 15, '0', STR_PAD_LEFT);
            $fecha_vencimiento = $fecha; // mismo que la fecha del comprobante

            $line = $fecha
                . $tipo_comprobante
                . $punto_venta
                . $nro_cbte
                . $nro_cbte_hasta
                . $codigo_doc
                . $nro_doc
                . $comprador
                . $importe_total
                . $conceptos_no_gravados
                . $percepcion_no_categorizados
                . $importe_exento
                . $percepcion_nacional
                . $percepcion_iibb
                . $percepcion_municipal
                . $impuestos_internos
                . $moneda
                . $tipo_cambio
                . $cantidad_iva
                . $codigo_operacion
                . $otros_tributos
                . $fecha_vencimiento;

            $lines[] = $line;
        }

        $fileName = 'Comprobantes_' . $inicio . '_a_' . $fin . '.txt';
        Storage::disk('local')->put($fileName, implode("\r\n", $lines));

        return response()->download(storage_path("app/{$fileName}"));
    }

    public function exportAlicuotasTxt($inicio, $fin)
    {
        $inicioCarbon = Carbon::parse($inicio)->startOfMonth();
        $finCarbon = Carbon::parse($fin)->endOfMonth();


        $afip_tickets = AfipTicket::whereBetween('created_at', [$inicioCarbon, $finCarbon])
                                    ->orderBy('created_at', 'ASC')
                                    ->get();

        $lines = [];

        foreach ($afip_tickets as $afip_ticket) {
            $ticket = $afip_ticket;

            if (!$ticket->cae) continue; // seguridad

            $sale = $afip_ticket->sale;
            if (is_null($sale)) {
                $sale = Sale::find($afip_ticket->sale_nota_credito_id);
            }

            if (is_null($sale)) {
                dd('No hay sale para afip_ticket '.$afip_ticket->id);
            }

            // Calculamos importes de IVA discriminados
            $afip_helper = new AfipHelper($sale);
            $importes = $afip_helper->getImportes();
            $ivas = $importes['ivas'];

            // Datos base
            $tipoComprobante = str_pad($ticket->cbte_tipo, 3, '0', STR_PAD_LEFT);
            $puntoVenta = str_pad($ticket->punto_venta, 5, '0', STR_PAD_LEFT);
            $numeroComprobante = str_pad($ticket->cbte_numero, 20, '0', STR_PAD_LEFT);

            // Generamos línea por cada alícuota con importe > 0
            foreach ($ivas as $alicuota) {
                $baseImp = $alicuota['BaseImp'];
                $ivaImp = $alicuota['Importe'];

                if ($baseImp == 0 && $ivaImp == 0) continue;

                $netoStr = str_pad(number_format($baseImp, 2, '', ''), 15, '0', STR_PAD_LEFT);
                $importe_iva = str_pad(number_format($ivaImp, 2, '', ''), 15, '0', STR_PAD_LEFT);
                
                $alicuotaCodigo = str_pad($alicuota['Id'], 4, '0', STR_PAD_LEFT);

                $line = $tipoComprobante
                    . $puntoVenta
                    . $numeroComprobante
                    . $netoStr
                    . $alicuotaCodigo
                    . $importe_iva;

                $lines[] = $line;
            }
        }

        $fileName = 'Alicuotas_' . $inicio . '_a_' . $fin . '.txt';
        Storage::disk('local')->put($fileName, implode("\r\n", $lines));

        return response()->download(storage_path('app/' . $fileName));
    }

    function get_cantidad_iva($sale) {

        $afip_helper = new AfipHelper($sale);
        $importes = $afip_helper->getImportes();

        $cantidad_iva = 0;
        
        foreach ($importes['ivas'] as $alicuota) {
                $baseImp = $alicuota['BaseImp'];
                $ivaImp = $alicuota['Importe'];

                if ($baseImp == 0 && $ivaImp == 0) continue;

                $cantidad_iva++;
        }

        return $cantidad_iva;
    }
}
