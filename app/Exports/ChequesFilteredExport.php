<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Exportación Excel síncrona de cheques por IDs (mismas filas visibles en reportes).
 *
 * Incluye columnas persistidas del modelo y relaciones legibles; al final agrega
 * una fila con el total de montos.
 */
class ChequesFilteredExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /** Colección de cheques ya filtrados y cargados con relaciones. */
    protected $cheques;

    /** Índice de columna "Monto" (base 0) para ubicar el total al final. */
    protected $amount_column_index = 8;

    /**
     * @param \Illuminate\Support\Collection $cheques Registros a exportar.
     */
    public function __construct($cheques)
    {
        $this->cheques = $cheques;
    }

    /**
     * Arma filas de datos y agrega al final la fila de total de montos.
     *
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $rows = $this->cheques->map(function ($cheque) {
            return $this->map_cheque_row($cheque);
        });

        $total_amount = $this->cheques->sum(function ($cheque) {
            return (float) $cheque->amount;
        });

        $total_row = array_fill(0, count($this->headings()), '');
        $total_row[0] = 'Total';
        $total_row[$this->amount_column_index] = $total_amount;

        $rows->push($total_row);

        return $rows;
    }

    /**
     * Encabezados del libro (español, alineados al listado de reportes).
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'ID',
            'Número',
            'Tipo',
            'Cliente',
            'Proveedor',
            'Endozado desde cliente',
            'Endozado al proveedor',
            'Banco',
            'Monto',
            'Notas',
            'Fecha emisión',
            'Fecha pago',
            'Fecha endoso',
            'Estado manual',
            'Cobrado en',
            'Cobrado por',
            'Rechazado en',
            'Rechazado por',
            'Observaciones rechazo',
            'Echeq',
            'Cuenta corriente ID',
            'Caja ID',
            'Empleado ID',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * Convierte un cheque en una fila ordenada según headings().
     *
     * @param \App\Models\Cheque $cheque
     * @return array<int, mixed>
     */
    protected function map_cheque_row($cheque)
    {
        return [
            $cheque->id,
            $cheque->numero,
            $cheque->tipo,
            optional($cheque->client)->name ?? '',
            optional($cheque->provider)->name ?? '',
            optional($cheque->endosado_desde_client)->name ?? '',
            optional($cheque->endosado_a_provider)->name ?? '',
            $cheque->banco,
            $cheque->amount,
            $cheque->notes,
            $this->format_date($cheque->fecha_emision),
            $this->format_date($cheque->fecha_pago),
            $this->format_datetime($cheque->fecha_endoso),
            $cheque->estado_manual,
            $this->format_datetime($cheque->cobrado_en),
            optional($cheque->cobrado_por)->name ?? '',
            $this->format_datetime($cheque->rechazado_en),
            optional($cheque->rechazado_por)->name ?? '',
            $cheque->rechazado_observaciones,
            $cheque->es_echeq ? 'Sí' : 'No',
            $cheque->current_acount_id,
            $cheque->caja_id,
            $cheque->employee_id,
            $this->format_datetime($cheque->created_at),
            $this->format_datetime($cheque->updated_at),
        ];
    }

    /**
     * Formatea fechas tipo date para Excel.
     *
     * @param mixed $value
     * @return string
     */
    protected function format_date($value)
    {
        if (is_null($value) || $value === '') {
            return '';
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }

    /**
     * Formatea timestamps para Excel.
     *
     * @param mixed $value
     * @return string
     */
    protected function format_datetime($value)
    {
        if (is_null($value) || $value === '') {
            return '';
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }
}
