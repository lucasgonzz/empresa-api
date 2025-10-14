<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AfipController extends Controller
{
    public function exportVentas($inicio, $fin) {

        $inicioCarbon = Carbon::parse($inicio)->startOfMonth();

        // Obtener último día del mes de fin
        $finCarbon = Carbon::parse($fin)->endOfMonth();

        $sales = Sale::where('user_id', $this->userId())
                        ->whereBetween('created_at', [$inicioCarbon, $finCarbon])
                        ->orderBy('created_at', 'ASC')
                        ->whereHas('afip_ticket')
                        ->get();


        $lines = [];

        foreach ($sales as $sale) {

            if ($sale->afip_ticket->cae) {

                $ticket = $sale->afip_ticket;
                $info = $sale->afip_information;

                // Fecha comprobante (AAAAMMDD)
                $fecha = Carbon::parse($ticket->created_at)->format('Ymd');

                // Tipo comprobante (3 dígitos)
                $tipoComprobante = str_pad($ticket->cbte_tipo, 3, '0', STR_PAD_LEFT);

                // Punto de sale (5 dígitos) + número comprobante (20 dígitos)
                $puntoVenta = str_pad($ticket->punto_venta, 5, '0', STR_PAD_LEFT);
                $numeroComprobante = str_pad($ticket->cbte_numero, 20, '0', STR_PAD_LEFT);

                // CUIT cliente (16 dígitos, completado con ceros a la izquierda)
                $cuitCliente = str_pad($ticket->cuit_cliente ?? '0', 20, '0', STR_PAD_LEFT);

                // Razón social cliente (30 caracteres en mayúsculas, completado con espacios)
                $razonSocial = str_pad(strtoupper(substr($info->razon_social ?? 'SIN NOMBRE', 0, 30)), 30, ' ');

                // Importe total (15 dígitos sin punto ni coma)
                $importeTotal = str_pad(number_format($ticket->importe_total * 100, 0, '', ''), 15, '0', STR_PAD_LEFT);

                // Moneda
                $moneda = 'PES';

                // Tipo de cambio fijo
                $tipoCambio = '00010000001 ';

                // Importe operaciones exentas (15 dígitos)
                $importeExento = str_pad('0', 15, '0', STR_PAD_LEFT);

                $line = $fecha
                    . $tipoComprobante
                    . $puntoVenta
                    . $numeroComprobante
                    . $cuitCliente
                    . $razonSocial
                    . $importeTotal
                    . $moneda
                    . $tipoCambio
                    . $importeExento;

                $lines[] = $line;
            }

        }

        $txt = implode("\r\n", $lines);
        $fileName = 'Archivo_Comprobantes_' . now()->format('Ymd_His') . '.txt';

        Storage::disk('local')->put($fileName, $txt);

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
                $alicuotaCodigo = str_pad($alicuota['Id'], 3, '0', STR_PAD_LEFT);

                $line = $tipoComprobante
                    . $puntoVenta
                    . $numeroComprobante
                    . $netoStr
                    . $ivaStr
                    . $alicuotaCodigo;

                $lines[] = $line;
            }
        }

        $fileName = 'ALICUOTAS_' . $inicio . '_a_' . $fin . '.txt';
        Storage::disk('local')->put($fileName, implode("\r\n", $lines));

        return response()->download(storage_path('app/' . $fileName));
    }
}
