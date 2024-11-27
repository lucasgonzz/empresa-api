<?php

namespace App\Exports;

use App\Models\AperturaCaja;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AperturaCajaExport implements WithMultipleSheets
{
    protected $aperturaCajaId;

    public function __construct($aperturaCajaId)
    {
        $this->aperturaCajaId = $aperturaCajaId;
    }

    public function sheets(): array
    {
        $apertura = AperturaCaja::with(['usuario_apertura', 'usuario_cierre', 'movimientos_caja'])->findOrFail($this->aperturaCajaId);

        return [
            new AperturaCajaHeaderSheet($apertura),
            new AperturaCajaMovimientosSheet($apertura->movimientos_caja),
        ];
    }
}

class AperturaCajaHeaderSheet implements FromCollection, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    protected $apertura;

    public function __construct($apertura)
    {
        $this->apertura = $apertura;
    }

    public function collection()
    {
        return collect([$this->apertura]);
    }

    public function map($apertura): array
    {
        return [
            ['Fecha de Apertura', $apertura->created_at->format('d/m/y H:i')],
            ['Saldo Apertura', $apertura->saldo_apertura],
            ['Saldo Cierre', $apertura->saldo_cierre],
            ['Fecha de Cierre', !is_null($apertura->cerrada_at) ? $apertura->cerrada_at->format('d/m/y H:i') : ''],
            ['Empleado Apertura', $apertura->usuario_apertura->name ?? 'S/A'],
            ['Empleado Cierre', $apertura->usuario_cierre->name ?? 'S/A'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]], // Aplica negrita al encabezado
        ];
    }

    public function title(): string
    {
        return 'General';
    }

    // Método para registrar los eventos, incluyendo el ajuste de los anchos de columna
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $sheet->getColumnDimension('A')->setWidth(80);
                $sheet->getColumnDimension('B')->setWidth(50);
            },
        ];
    }
}

class AperturaCajaMovimientosSheet implements FromCollection, WithHeadings, WithMapping, WithStyles, WithTitle, ShouldAutoSize
{
    protected $movimientos_caja;

    public function __construct($movimientos_caja)
    {
        $this->movimientos_caja = $movimientos_caja;
    }

    public function collection()
    {
        return $this->movimientos_caja;
    }

    public function headings(): array
    {
        return [
            'Concepto Movimiento',
            'Hora',
            'Ingreso',
            'Egreso',
            'Saldo',
            'Notas',
        ];
    }

    public function map($movimiento): array
    {
        return [
            $this->get_concepto($movimiento),
            $movimiento->created_at->format('H:i'),
            !is_null($movimiento->ingreso) ? $movimiento->ingreso : '',
            !is_null($movimiento->egreso) ? $movimiento->egreso : '',
            $movimiento->saldo,
            $movimiento->notas,
        ];
    }

    function get_concepto($movimiento) {
        
        $sale = $movimiento->sale;

        if (!is_null($sale)) {
            return 'Venta N°'.$sale->num;
        }

        if (!is_null($movimiento->concepto_movimiento_caja)) {
            return $movimiento->concepto_movimiento_caja->name;
        }
        return '';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]], // Aplica negrita al encabezado
        ];
    }

    public function title(): string
    {
        return 'Movimientos';
    }

    // Método para registrar los eventos, incluyendo el ajuste de los anchos de columna
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $sheet->getColumnDimension('A')->setWidth(50); 
                $sheet->getColumnDimension('B')->setWidth(20); 
                $sheet->getColumnDimension('C')->setWidth(50); 
                $sheet->getColumnDimension('D')->setWidth(50); 
                $sheet->getColumnDimension('E')->setWidth(50); 
                $sheet->getColumnDimension('F')->setWidth(200); 
            },
        ];
    }
}
