<?php

namespace App\Exports;

use App\Models\Sale;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SalesFullExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /** Ventas del rango exportado. */
    protected $sales;

    /** Índice columna Total ($) en headings(). */
    protected $total_peso_column_index = 2;

    /** Índice columna Total USD en headings(). */
    protected $total_usd_column_index = 3;

    /**
     * @param \Illuminate\Support\Collection $sales Ventas a exportar.
     */
    function __construct($sales)
    {
        $this->sales = $sales;
    }

    /**
     * Filas de ventas más fila final con suma de totales en pesos y dólares.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $rows = $this->sales->map(function ($sale) {
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

        $totals = $this->sum_totals_by_moneda();
        $total_row = array_fill(0, count($this->headings()), '');
        $total_row[0] = 'Total';
        $total_row[$this->total_peso_column_index] = $totals['pesos'];
        $total_row[$this->total_usd_column_index] = $totals['usd'];

        $rows->push($total_row);

        return $rows;
    }

    /**
     * Suma importes por moneda (misma regla que cada fila: peso en Total, USD en Total USD).
     *
     * @return array{pesos: float, usd: float}
     */
    protected function sum_totals_by_moneda()
    {
        $total_pesos = 0;
        $total_usd = 0;

        $this->sales->each(function ($sale) use (&$total_pesos, &$total_usd) {
            if ($sale->moneda_id == 1) {
                $total_pesos += (float) $sale->total;
            } elseif ($sale->moneda_id == 2) {
                $total_usd += (float) $sale->total;
            }
        });

        return [
            'pesos' => $total_pesos,
            'usd'   => $total_usd,
        ];
    }

    /**
     * Nombre de provincia del cliente de la venta.
     *
     * @param \App\Models\Sale $sale
     * @return string
     */
    function provincia($sale)
    {
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
