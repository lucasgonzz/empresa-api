<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SalesBreakdownExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /** Ventas del rango (una fila por artículo en el detalle). */
    protected $sales;

    /** Índice columna Cantidad. */
    protected $cantidad_column_index = 5;

    /** Índice columna Unidad de medida. */
    protected $unidad_medida_column_index = 6;

    /** Índice columna Total ($) al final del desglose. */
    protected $total_peso_column_index = 9;

    /** Índice columna Total USD al final del desglose. */
    protected $total_usd_column_index = 10;

    /**
     * @param \Illuminate\Support\Collection $sales Ventas con artículos cargados.
     */
    public function __construct($sales)
    {
        $this->sales = $sales;
    }

    /**
     * Filas por artículo (total línea = precio × cantidad), fila final con suma de ventas en $,
     * USD y cantidad, y debajo un resumen de cantidad total vendida por cada unidad de medida.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $this->sales->loadMissing('articles.unidad_medida');

        $cantidad_total = 0;
        $cantidad_por_unidad = [];

        $rows = $this->sales->flatMap(function ($sale) use (&$cantidad_total, &$cantidad_por_unidad) {
            return $sale->articles->map(function ($article) use ($sale, &$cantidad_total, &$cantidad_por_unidad) {
                $line_totals = $this->article_line_totals_for_columns($sale, $article);
                $cantidad = (float) ($article->pivot->amount ?? 0);
                $unidad_medida = optional($article->unidad_medida)->name ?? 'N/A';

                $cantidad_total += $cantidad;
                $cantidad_por_unidad[$unidad_medida] = ($cantidad_por_unidad[$unidad_medida] ?? 0) + $cantidad;

                return [
                    'numero_venta'    => $sale->id,
                    'fecha_venta'     => optional($sale->created_at)->format('Y-m-d H:i:s'),
                    'nombre_articulo' => $article->name,
                    'precio'          => $article->pivot->price ?? '',
                    'costo'           => $article->pivot->cost ?? '',
                    'cantidad'        => $article->pivot->amount ?? '',
                    'unidad_medida'   => $unidad_medida,
                    'cliente'         => optional($sale->client)->name ?? 'N/A',
                    'empleado'        => optional($sale->employee)->name ?? 'N/A',
                    'total'           => $line_totals['total'],
                    'total_usd'       => $line_totals['total_usd'],
                ];
            });
        });

        $totals = $this->sum_totals_by_moneda();
        $total_row = array_fill(0, count($this->headings()), '');
        $total_row[0] = 'Total';
        $total_row[$this->cantidad_column_index] = $cantidad_total;
        $total_row[$this->total_peso_column_index] = $totals['pesos'];
        $total_row[$this->total_usd_column_index] = $totals['usd'];

        $rows->push($total_row);

        foreach ($cantidad_por_unidad as $unidad_medida => $cantidad) {
            $resumen_row = array_fill(0, count($this->headings()), '');
            $resumen_row[0] = 'Total '.$unidad_medida;
            $resumen_row[$this->cantidad_column_index] = $cantidad;
            $resumen_row[$this->unidad_medida_column_index] = $unidad_medida;

            $rows->push($resumen_row);
        }

        return $rows;
    }

    /**
     * Total de la línea: precio unitario × cantidad del pivot del artículo.
     *
     * @param \App\Models\Sale $sale
     * @param \App\Models\Article $article
     * @return float
     */
    protected function article_line_total($sale, $article)
    {
        $price = (float) ($article->pivot->price ?? 0);
        $amount = (float) ($article->pivot->amount ?? 0);

        return $price * $amount;
    }

    /**
     * Ubica el total de línea en Total o Total USD según la moneda de la venta.
     *
     * @param \App\Models\Sale $sale
     * @param \App\Models\Article $article
     * @return array{total: float|string, total_usd: float|string}
     */
    protected function article_line_totals_for_columns($sale, $article)
    {
        $line_total = $this->article_line_total($sale, $article);

        if ($sale->moneda_id == 2) {
            return [
                'total'     => '',
                'total_usd' => $line_total,
            ];
        }

        return [
            'total'     => $line_total,
            'total_usd' => '',
        ];
    }

    /**
     * Suma totales de ventas por moneda (una vez por venta, no por línea de artículo).
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
     * Encabezados del Excel desglosado (incluye columnas de totales al final).
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Numero venta',
            'Fecha venta',
            'Nombre articulo',
            'Precio',
            'Costo',
            'Cantidad',
            'Unidad de medida',
            'Cliente',
            'Empleado',
            'Total',
            'Total USD',
        ];
    }
}
