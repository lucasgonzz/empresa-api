<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
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

        $sales = Sale::with(['afip_ticket', 'afip_information'])
            ->where('user_id', $this->userId())
            ->whereBetween('created_at', [$inicioCarbon, $finCarbon])
            ->orderBy('created_at', 'ASC')
            ->get();

        $lines = [];

        foreach ($sales as $sale) {
            $ticket = $sale->afip_ticket;
            $info = $sale->afip_information;

            if (!$ticket || !$ticket->cae) continue;

            $fecha = Carbon::parse($ticket->created_at)->format('Ymd');
            $tipo_comprobante = str_pad($ticket->cbte_tipo, 3, '0', STR_PAD_LEFT);
            $punto_venta = str_pad($ticket->punto_venta, 5, '0', STR_PAD_LEFT);
            $nro_cbte = str_pad($ticket->cbte_numero, 20, '0', STR_PAD_LEFT);

            $nro_cbte_hasta = $nro_cbte; // mismo que el anterior si es un solo comprobante

            $codigo_doc = str_pad($ticket->doc_tipo ?? '96', 2, '0', STR_PAD_LEFT); // 96 = DNI
            $nro_doc = str_pad($ticket->cuit_cliente ?? '0', 20, '0', STR_PAD_LEFT);

            $razon_social = strtoupper(str_replace(' ', '', $info->razon_social ?? 'SINNOMBRE'));
            $razon_social = str_pad(substr($razon_social, 0, 30), 30, ' ');

            $importe_total = str_pad(number_format($ticket->importe_total * 100, 0, '', ''), 15, '0', STR_PAD_LEFT);

            $conceptos_no_gravados = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_no_categorizados = str_pad('0', 15, '0', STR_PAD_LEFT);
            $importe_exento = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_nacional = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_iibb = str_pad('0', 15, '0', STR_PAD_LEFT);
            $percepcion_municipal = str_pad('0', 15, '0', STR_PAD_LEFT);
            $impuestos_internos = str_pad('0', 15, '0', STR_PAD_LEFT);

            $moneda = 'PES';
            $tipo_cambio = str_pad('0001000000', 10, '0', STR_PAD_LEFT); // 1.000000

            $cantidad_iva = '1'; // asumimos una alícuota por comprobante
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
                . $razon_social
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

        $fileName = 'Archivo_Comprobantes_' . now()->format('Ymd_His') . '.txt';
        Storage::disk('local')->put($fileName, implode("\r\n", $lines));

        return response()->download(storage_path("app/{$fileName}"));
    }

    public function exportAlicuotasTxt($inicio, $fin)
    {
        $inicioCarbon = Carbon::parse($inicio)->startOfMonth();
        $finCarbon = Carbon::parse($fin)->endOfMonth();

        $ventas = Sale::with('afip_ticket')
            ->whereBetween('created_at', [$inicioCarbon, $finCarbon])
            ->get();

        $lines = [];

        foreach ($ventas as $venta) {
            $ticket = $venta->afip_ticket;
            if (!$ticket) continue; // seguridad

            // Calculamos importes de IVA discriminados
            $afip_helper = new AfipHelper($venta);
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

                $netoStr = str_pad(number_format($baseImp * 100, 0, '', ''), 15, '0', STR_PAD_LEFT);
                $ivaStr = str_pad(number_format($ivaImp * 100, 0, '', ''), 15, '0', STR_PAD_LEFT);
                $codigo_impuesto = '5'; // Campo faltante: 1 caracter
                $alicuotaCodigo = str_pad($alicuota['Id'], 3, '0', STR_PAD_LEFT);

                $line = $tipoComprobante
                    . $puntoVenta
                    . $numeroComprobante
                    . $netoStr
                    . $ivaStr
                    . $codigo_impuesto
                    . $alicuotaCodigo;

                $lines[] = $line;
            }
        }

        $fileName = 'ALICUOTAS_' . $inicio . '_a_' . $fin . '.txt';
        Storage::disk('local')->put($fileName, implode("\r\n", $lines));

        return response()->download(storage_path('app/' . $fileName));
    }
}
