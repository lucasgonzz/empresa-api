<?php

namespace App\Exports;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SalesFullExport implements FromCollection, WithHeadings, ShouldAutoSize
{

    function __construct($sales) {
        $this->sales = $sales;
    }

    public function collection()
    {
        return $this->sales
            ->map(function ($sale) {
                return [
                    'fecha'               => $sale->created_at->format('Y-m-d H:i:s'),
                    'numero_venta'        => $sale->id,
                    'total'               => $sale->moneda_id == 1 ? $sale->total : '',
                    'total_usd'           => $sale->moneda_id == 2 ? $sale->total : '',
                    'total_facturado'     => $sale->total_facturado,
                    'moneda'              => optional($sale->moneda)->name ?? 'Peso',
                    'metodo_pago'         => $sale->current_acount_payment_methods->pluck('name')->implode(', '),
                    'cliente'             => optional($sale->client)->name ?? 'N/A',
                    'provincia'           => $this->provincia($sale),
                    'empleado'            => optional($sale->employee)->name ?? 'N/A',
                    'observaciones'       => $sale->observations ?? '',
                ];
            });
    }

    function provincia($sale) {
        if ($sale->client && $sale->client->provincia) {
            return $sale->client->provincia->name;
        }
        return 'S/A';
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'N°',
            'Total',
            'Total USD',
            'Total Facturado',
            'Moneda',
            'Método de Pago',
            'Cliente',
            'Provincia',
            'Empleado',
            'Observaciones'
        ];
    }
}
