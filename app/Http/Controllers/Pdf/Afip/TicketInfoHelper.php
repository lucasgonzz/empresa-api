<?php

namespace App\Http\Controllers\Pdf\Afip;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Pdf\AfipQrPdf;
use App\Models\AfipTicket;
use App\Models\Sale;

class TicketInfoHelper
{
    /**
     * Ticket AFIP utilizado para cálculos y render fiscal.
     *
     * @var \App\Models\AfipTicket|null
     */
    protected $afip_ticket;

    /**
     * Helper oficial AFIP usado por resolvers de importes/IVA.
     *
     * @var \App\Http\Controllers\Helpers\AfipHelper|null
     */
    protected $afip_helper;

    /**
     * Usuario propietario del comprobante para datos comerciales.
     *
     * @var mixed
     */
    protected $user;
    protected $sale;

    /**
     * Construye helper fiscal compartido para PDFs de venta.
     *
     * @param \App\Models\AfipTicket|null $afip_ticket
     * @param mixed $user
     */
    public function __construct($afip_ticket, $sale, $user)
    {
        $this->afip_ticket = $afip_ticket;
        $this->sale = $sale;
        $this->user = $user;

        /**
         * El helper se instancia solo cuando hay comprobante AFIP válido.
         */
        $this->afip_helper = $afip_ticket ? new AfipHelper($afip_ticket, null, null, $user) : null;
    }

    /**
     * Resuelve ticket AFIP por id dentro de la venta.
     *
     * @param \App\Models\Sale $sale
     * @param mixed $afip_ticket_id
     * @return \App\Models\AfipTicket|null
     */
    public static function resolve_afip_ticket_for_sale(Sale $sale, $afip_ticket_id): ?AfipTicket
    {
        if (empty($afip_ticket_id)) {
            return null;
        }

        /**
         * Se restringe a tickets de la venta para evitar cruces inválidos.
         */
        return $sale->afip_tickets()
                    ->where('id', $afip_ticket_id)
                    ->withAll()
                    ->first();
    }

    /**
     * Expone helper AFIP para resolvers de columnas dinámicas.
     *
     * @return \App\Http\Controllers\Helpers\AfipHelper|null
     */
    public function afip_helper()
    {
        return $this->afip_helper;
    }

    /**
     * Indica si el helper tiene ticket y contexto fiscal activo.
     *
     * @return bool
     */
    public function has_afip_context(): bool
    {
        return !is_null($this->afip_ticket) && !is_null($this->afip_helper);
    }

    /**
     * Imprime bloque resumido de cabecera fiscal AFIP/ARCA.
     *
     * @param mixed $pdf Instancia FPDF.
     * @return void
     */
    // public function print_afip_header($pdf): void
    // {
    //     if (! $this->has_afip_context()) {
    //         return;
    //     }

    //     /**
    //      * Posición base para resumen fiscal.
    //      */
    //     $pdf->SetFont('Arial', 'B', 10);
    //     $pdf->x = 5;
    //     $pdf->Cell(200, 5, 'Comprobante AFIP', 0, 1, 'L');

    //     $pdf->SetFont('Arial', '', 9);
    //     $pdf->x = 5;
    //     $pdf->Cell(35, 5, 'Punto de venta:', 0, 0, 'L');
    //     $pdf->Cell(30, 5, $this->left_pad((string) $this->afip_ticket->punto_venta, 5), 0, 0, 'L');
    //     $pdf->Cell(20, 5, 'Comp. Nro:', 0, 0, 'L');
    //     $pdf->Cell(35, 5, $this->left_pad((string) $this->afip_ticket->cbte_numero, 8), 0, 1, 'L');
    // }

    function print_afip_header($pdf) {
        // Cuit
        // $pdf->SetY(53);
        $pdf->SetX(6);
        $start_y = $pdf->y;

        if (
            !is_null($this->sale->client)
        ) {

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(10, 5, 'CUIT:',0,0,'L');
            $pdf->SetFont('Arial', '', 8);
            
            $pdf->Cell(20, 5, $this->sale->client->cuit, 0, 1, 'C');
        }

        if ($pdf->afip_ticket->cbte_letra != 'E') {
            
            // Iva
            $pdf->SetX(6);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(37, 5, 'Condición frente al IVA:', 0, 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            
            if (
                !is_null($this->sale->client)
                && !is_null($this->sale->client->iva_condition)
            ) {
                $pdf->Cell(50, 5, $this->sale->client->iva_condition->name, 0, 1, 'L');
            } else {
                $pdf->Cell(50, 5, 'IVA consumidor final', 0, 1, 'L');
            }

            $pdf->SetX(6);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(32, 5, 'Condición de venta:', 0, 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(50, 5,  $this->getPaymentMethod(), 0, 1, 'L');
        } else {

            $pdf->SetX(6);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(32, 5, 'ID Impositivo:', 0, 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(50, 5,  $this->sale->client->cuit, 0, 1, 'L');

            // CUIT País: 55000000042 (BOLIVIA - Persona Jurídica) 
        }
        
        


        // Parte derecha
        if (!is_null($this->sale->client)) {
            $pdf->SetY($start_y);
            $pdf->SetX(80);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(47, 5, 'Apellido y Nombre / Razón Social:', 0, 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(60, 5, $this->sale->client->name, 0, 1, 'L');

            $pdf->SetX(97);
            $pdf->SetX(80);
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->Cell(30, 5, 'Domicilio Comercial:', 0, 0, 'L');
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(60, 5, $this->sale->client->address, 0, 1, 'L');
        }
        $this->printClientLines($pdf, $start_y);
    }

    function printClientLines($pdf, $start_y) {

        $finish_y = $pdf->y;
        $finish_y += 5;

        $pdf->SetLineWidth(.3);
        // Arriba
        $pdf->Line(5, $start_y, 205, $start_y);
        // Abajo
        $pdf->Line(5, $finish_y, 205, $finish_y);
        // Izquierda
        $pdf->Line(5, $start_y, 5, $finish_y);
        // Derecha
        $pdf->Line(205, $start_y, 205, $finish_y);
    }

    function getPaymentMethod() {
        if ($this->sale->current_acount) {
            return 'Cuenta corriente';
        }
        return 'Contado';
    }

    /**
     * Imprime cuadro de alícuotas IVA y total fiscal.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param \App\Models\Sale $sale
     * @return void
     */
    public function print_iva_and_totals($pdf, Sale $sale): void
    {
        if (! $this->has_afip_context()) {
            return;
        }

        /**
         * Importes fiscales preferentemente persistidos al autorizar.
         * Si no existen (tickets legacy), se mantiene fallback a recálculo.
         */
        $importes = $this->get_importes_for_pdf();

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->x = 125;
        $pdf->y += 5;

        if ($this->afip_ticket->cbte_letra == 'A' || $this->afip_ticket->cbte_letra == 'B') {
            $pdf->SetFont('Arial', '', 9);
            $pdf->Cell(40, 5, 'Importe Neto Gravado:', 1, 0, 'L');
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(40, 5, '$'.Numbers::price($importes['gravado']), 1, 1, 'L');

            foreach ($importes['ivas'] as $iva => $importe) {
                if (($importe['Importe'] ?? 0) <= 0) {
                    continue;
                }
                $pdf->x = 125;
                $pdf->SetFont('Arial', '', 9);
                $pdf->Cell(40, 5, 'IVA '.$iva.'%:', 1, 0, 'L');
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->Cell(40, 5, '$'.Numbers::price($importe['Importe']), 1, 1, 'L');
            }
        }

        $pdf->x = 125;
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(40, 7, 'Importe Total:', 1, 0, 'L');

        /**
         * En comprobantes E se respeta moneda de la venta.
         */
        $formatted_total = Numbers::price($importes['total'], true);
        if ($this->afip_ticket->cbte_letra == 'E') {
            $formatted_total = Numbers::price($sale->total, true, $sale->moneda_id);
        }
        $pdf->Cell(40, 7, $formatted_total, 1, 1, 'L');
    }

    /**
     * Obtiene importes para render fiscal del PDF.
     *
     * @return array
     */
    protected function get_importes_for_pdf(): array
    {
        /**
         * Si hay snapshot persistido en AFIP ticket, se usa como fuente principal.
         */
        if (!is_null($this->afip_ticket->imp_total_enviado)) {
            /**
             * Mapa de alícuotas con el mismo formato esperado por el renderer.
             */
            $ivas = [];
            /**
             * Detalle de IVA persistido (array por cast o JSON legacy como string).
             */
            $detalle = $this->afip_ticket->iva_detalle_enviado_json;
            if (is_string($detalle)) {
                $decoded = json_decode($detalle, true);
                if (is_array($decoded)) {
                    $detalle = $decoded;
                } else {
                    $detalle = [];
                }
            }
            if (!is_array($detalle)) {
                $detalle = [];
            }

            foreach ($detalle as $iva_row) {
                if (!isset($iva_row['Id'])) {
                    continue;
                }
                $iva_label = $this->iva_id_to_label((int) $iva_row['Id']);
                if ($iva_label === null) {
                    continue;
                }
                $ivas[$iva_label] = [
                    'BaseImp' => isset($iva_row['BaseImp']) ? (float) $iva_row['BaseImp'] : 0,
                    'Importe' => isset($iva_row['Importe']) ? (float) $iva_row['Importe'] : 0,
                    'Id' => (int) $iva_row['Id'],
                ];
            }

            return [
                'gravado' => (float) $this->afip_ticket->imp_neto_enviado,
                'neto_no_gravado' => (float) $this->afip_ticket->imp_tot_conc_enviado,
                'exento' => (float) $this->afip_ticket->imp_op_ex_enviado,
                'iva' => (float) $this->afip_ticket->imp_iva_enviado,
                'ivas' => $ivas,
                'total' => (float) $this->afip_ticket->imp_total_enviado,
            ];
        }

        return $this->afip_helper->getImportes();
    }

    /**
     * Convierte Id de alícuota AFIP al texto de porcentaje usado en renderer.
     *
     * @param int $iva_id
     * @return string|null
     */
    protected function iva_id_to_label(int $iva_id): ?string
    {
        /** @var array $map Mapeo AFIP Id -> etiqueta alícuota. */
        $map = [
            6 => '27',
            5 => '21',
            4 => '10.5',
            8 => '5',
            9 => '2.5',
            3 => '0',
        ];

        if (!isset($map[$iva_id])) {
            return null;
        }

        return $map[$iva_id];
    }

    /**
     * Imprime pie fiscal con CAE y vencimiento.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param int $page_number
     * @return void
     */
    public function print_fiscal_footer($pdf, int $page_number): void
    {
        if (! $this->has_afip_context()) {
            return;
        }

        $pdf->y += 12;
        $pdf->x = 55;
        $pdf->Cell(100, 5, 'Pag. '.$page_number, 0, 0, 'C');

        $pdf->y += 5;
        $pdf->x = 105;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 5, 'CAE N°:', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(50, 5, $this->afip_ticket->cae, 0, 0, 'L');

        $pdf->y += 5;
        $pdf->x = 105;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(50, 5, 'Fecha de Vto. de CAE:', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(50, 5, $this->cae_expired_at_label(), 0, 0, 'L');
    }

    /**
     * Imprime QR AFIP y bloque legal ARCA en pie.
     *
     * @param mixed $pdf Instancia FPDF.
     * @return void
     */
    public function print_qr_and_arca_footer($pdf): void
    {
        if (! $this->has_afip_context()) {
            return;
        }

        if (config('app.APP_ENV') != 'local') {
            $qr_pdf = new AfipQrPdf($pdf, $this->afip_ticket, false);
            $qr_pdf->printQr();
            $pdf->y -= 40;
        }

    }

    /**
     * Obtiene texto normalizado de vencimiento CAE.
     *
     * @return string
     */
    protected function cae_expired_at_label(): string
    {
        /**
         * Se trunca formato legacy para mantener compatibilidad visual.
         */
        $date = (string) $this->afip_ticket->cae_expired_at;
        return substr($date, 0, 11);
    }

    /**
     * Completa con ceros a la izquierda para códigos AFIP.
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    protected function left_pad(string $value, int $length): string
    {
        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }
}
