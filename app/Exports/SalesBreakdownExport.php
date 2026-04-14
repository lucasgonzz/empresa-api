<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesBreakdownExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    protected $sales;

    public function __construct($sales)
    {
        $this->sales = $sales;
    }

    public function collection()
    {
        return $this->sales->flatMap(function ($sale) {
            return $sale->articles->map(function ($article) use ($sale) {
                return [
                    'numero_venta'   => $sale->id,
                    'fecha_venta'    => optional($sale->created_at)->format('Y-m-d H:i:s'),
                    'nombre_articulo'=> $article->name,
                    'precio'         => $article->pivot->price ?? '',
                    'costo'          => $article->pivot->cost ?? '',
                    'cantidad'       => $article->pivot->amount ?? '',
                    'cliente'        => optional($sale->client)->name ?? 'N/A',
                    'empleado'       => optional($sale->employee)->name ?? 'N/A',
                ];
            });
        });
    }

    public function headings(): array
    {
        return [
            'Numero venta',
            'Fecha venta',
            'Nombre articulo',
            'Precio',
            'Costo',
            'Cantidad',
            'Cliente',
            'Empleado',
        ];
    }
}
