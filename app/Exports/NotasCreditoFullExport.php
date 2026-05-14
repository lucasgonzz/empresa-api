<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Exportación Excel de notas de crédito (current_acount con status nota_credito).
 *
 * Cada fila resume una nota con totales, cliente, venta asociada y texto de ítems/descripciones.
 */
class NotasCreditoFullExport implements FromCollection, WithHeadings, ShouldAutoSize
{
    /** Colección de modelos CurrentAcount (notas de crédito) ya cargados con relaciones necesarias. */
    protected $notas_credito;

    /**
     * @param \Illuminate\Support\Collection $notas_credito Registros a volcar al libro.
     */
    public function __construct($notas_credito)
    {
        $this->notas_credito = $notas_credito;
    }

    /**
     * Construye las filas del Excel a partir de cada nota de crédito.
     *
     * @return \Illuminate\Support\Collection Filas como arrays ordenados según headings().
     */
    public function collection()
    {
        return $this->notas_credito->map(function ($nota_credito) {
            /** Ticket AFIP de la nota de crédito (comprobante electrónico), si fue emitido. */
            $afip_ticket = $nota_credito->afip_ticket;

            return [
                'fecha' => $nota_credito->created_at->format('Y-m-d H:i:s'),
                'num_receipt' => $nota_credito->num_receipt,
                'sale_num' => optional($nota_credito->sale)->num ?? $nota_credito->sale_id,
                'invoice_number' => $this->format_afip_invoice_number($afip_ticket),
                'cae' => optional($afip_ticket)->cae ?? '',
                'cliente' => optional($nota_credito->client)->name ?? 'N/A',
                'moneda' => optional(optional($nota_credito->credit_account)->moneda)->name ?? $this->moneda_por_id($nota_credito->moneda_id),
                'total' => $nota_credito->haber,
                'detalle' => $nota_credito->detalle ?? '',
                'descripciones' => $this->format_descripciones($nota_credito),
                'articulos' => $this->format_articles($nota_credito),
                'servicios' => $this->format_services($nota_credito),
            ];
        });
    }

    /**
     * Encabezados de columnas visibles en la primera fila del archivo.
     *
     * @return array<int, string>
     */
    public function headings(): array
    {
        return [
            'Fecha',
            'N° nota',
            'N° venta',
            'N° factura',
            'CAE',
            'Cliente',
            'Moneda',
            'Total',
            'Detalle',
            'Descripciones',
            'Artículos',
            'Servicios',
        ];
    }

    /**
     * Arma el número de comprobante fiscal legible (letra, punto de venta y número de AFIP).
     *
     * @param \App\Models\AfipTicket|null $afip_ticket Ticket vinculado a la nota de crédito.
     * @return string Cadena vacía si no hay ticket o faltan datos mínimos; si no, formato alineado al PDF (PV 5 + nro 8).
     */
    protected function format_afip_invoice_number($afip_ticket)
    {
        if (is_null($afip_ticket)) {
            return '';
        }

        /** Valores crudos antes del padding; si ambos faltan no se inventa un comprobante. */
        $punto_venta_raw = trim((string) ($afip_ticket->punto_venta ?? ''));
        $cbte_numero_raw = trim((string) ($afip_ticket->cbte_numero ?? ''));

        if ($punto_venta_raw === '' && $cbte_numero_raw === '') {
            return '';
        }

        /** Punto de venta rellenado a 5 dígitos, coherente con comprobantes impresos. */
        $punto_venta = $this->left_pad_string($punto_venta_raw, 5);
        /** Número de comprobante rellenado a 8 dígitos. */
        $cbte_numero = $this->left_pad_string($cbte_numero_raw, 8);

        /** Letra del comprobante (A/B/C/M) cuando está persistida. */
        $letra = trim((string) ($afip_ticket->cbte_letra ?? ''));
        $nro = $punto_venta.'-'.$cbte_numero;

        if ($letra !== '') {
            return $letra.' '.$nro;
        }

        return $nro;
    }

    /**
     * Rellena por la izquierda con ceros hasta la longitud pedida (solo dígitos útiles para AFIP).
     *
     * @param string $value Valor crudo proveniente de base de datos.
     * @param int $length Longitud objetivo del string resultante.
     * @return string Valor acotado o vacío si no hay contenido.
     */
    protected function left_pad_string($value, $length)
    {
        $value = trim($value);

        if ($value === '') {
            return str_repeat('0', $length);
        }

        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }

    /**
     * Texto de respaldo cuando no hay relación moneda cargada.
     *
     * @param int|string|null $moneda_id Identificador de moneda persistido o derivado.
     * @return string Etiqueta legible.
     */
    protected function moneda_por_id($moneda_id)
    {
        if ($moneda_id == 1) {
            return 'Peso';
        }
        if ($moneda_id == 2) {
            return 'Dólar';
        }

        return 'S/A';
    }

    /**
     * Concatena las líneas de descripción libre de la nota.
     *
     * @param \App\Models\CurrentAcount $nota_credito Nota con relación nota_credito_descriptions cargada.
     * @return string Texto unido por separadores.
     */
    protected function format_descripciones($nota_credito)
    {
        return $nota_credito->nota_credito_descriptions
            ->pluck('notes')
            ->filter()
            ->implode(' | ');
    }

    /**
     * Resume artículos devueltos en la nota (nombre y cantidad desde pivot).
     *
     * @param \App\Models\CurrentAcount $nota_credito Nota con relación articles cargada.
     * @return string Lista separada por punto y coma.
     */
    protected function format_articles($nota_credito)
    {
        return $nota_credito->articles->map(function ($article) {
            $nombre = $article->name ?? '';
            $cantidad = $article->pivot->amount ?? 0;

            return $nombre.' x'.$cantidad;
        })->implode('; ');
    }

    /**
     * Resume servicios incluidos en la nota.
     *
     * @param \App\Models\CurrentAcount $nota_credito Nota con relación services cargada.
     * @return string Lista separada por punto y coma.
     */
    protected function format_services($nota_credito)
    {
        return $nota_credito->services->map(function ($service) {
            $nombre = $service->name ?? '';
            $cantidad = $service->pivot->amount ?? 0;

            return $nombre.' x'.$cantidad;
        })->implode('; ');
    }
}
