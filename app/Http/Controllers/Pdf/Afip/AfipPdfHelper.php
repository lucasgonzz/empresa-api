<?php

namespace App\Http\Controllers\Pdf\Afip;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Pdf\AfipQrPdf;
use Carbon\Carbon;

/**
 * Helper estático para header y footer fiscal AFIP/ARCA en PDFs de venta.
 * Reemplaza la combinación PdfHelper::header + TicketInfoHelper para perfiles fiscales.
 */
class AfipPdfHelper
{
    /**
     * Alícuotas IVA a mostrar siempre en el resumen fiscal (letras A y B).
     *
     * @var array<int, string>
     */
    protected static $iva_rate_labels = ['27', '21', '10.5', '5', '2.5', '0'];

    /**
     * Filas fijas de la tabla "Otros Tributos" (sin datos reales en el sistema).
     *
     * @var array<int, string>
     */
    protected static $otros_tributos_rows = [
        'Per./Ret. de Impuesto a las Ganancias',
        'Per./Ret. de IVA',
        'Per./Ret. de Ingresos Brutos',
        'Impuestos Internos',
        'Impuestos Municipales',
    ];

    /**
     * Dibuja el header fiscal completo estilo comprobante ARCA/AFIP.
     *
     * @param mixed $pdf Instancia FPDF (NewSalePdf).
     * @param mixed $afip_ticket Ticket AFIP con relaciones cargadas.
     * @param mixed $sale Venta asociada al comprobante.
     * @param mixed $user Usuario emisor del comprobante.
     * @return void
     */
    public static function header($pdf, $afip_ticket, $sale, $user): void
    {
        /**
         * Información fiscal del emisor vinculada al ticket.
         */
        $afip_information = $afip_ticket->afip_information;

        /**
         * Posición inicial del header fiscal.
         */
        $pdf->x = 5;
        $pdf->y = 5;

        /**
         * Banda superior "ORIGINAL" a ancho completo.
         */
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(200, 8, 'ORIGINAL', 1, 1, 'C');

        /**
         * Bloque central: logo, cuadrado de letra y tipo de comprobante.
         */
        $block_start_y = $pdf->y;
        self::print_header_logo_zone($pdf, $user, $block_start_y);
        self::print_header_letter_zone($pdf, $afip_ticket, $block_start_y);
        self::print_header_right_zone($pdf, $afip_ticket, $sale, $block_start_y, $pdf);

        /**
         * Avanza Y al máximo alcanzado por las tres columnas del bloque central.
         */
        $pdf->y = max($block_start_y + 40, $pdf->y);

        /**
         * Bloque de datos del emisor (razón social, domicilio, CUIT, etc.).
         */
        if ($afip_information) {
            self::print_emisor_block($pdf, $afip_information);
        }

        /**
         * Bloque de datos del receptor cuando la venta tiene cliente.
         */
        if (!is_null($sale->client)) {
            self::print_receptor_block($pdf, $sale);
            $pdf->y += 2;
        }
    }

    /**
     * Zona izquierda del header: logo cuadrado y nombre del negocio.
     *
     * @param mixed $pdf
     * @param mixed $user
     * @param float $start_y
     * @return void
     */
    protected static function print_header_logo_zone($pdf, $user, $start_y): void
    {
        /**
         * Logo forzado a cuadrado 35×35 mm; en local se usa imagen de prueba.
         */
        $logo_size = 35;
        $logo_url = $user->image_url;
        $logo_printed = false;

        if (config('app.APP_ENV') == 'local') {
            $pdf->Image(
                'https://img.freepik.com/vector-gratis/ilustracion-banner-sello-circulo_53876-28480.jpg',
                5,
                $start_y,
                $logo_size,
                $logo_size
            );
            $logo_printed = true;
        } elseif (!is_null($logo_url) && GeneralHelper::file_exists_2($logo_url)) {
            $pdf->Image($logo_url, 5, $start_y, $logo_size, $logo_size);
            $logo_printed = true;
        }

        /**
         * Nombre comercial debajo del logo (o al inicio si no hay imagen).
         */
        $name_y = $logo_printed ? ($start_y + $logo_size + 2) : $start_y;
        $pdf->y = $name_y;
        $pdf->x = 5;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(65, 5, (string) $user->company_name, 0, 1, 'L');
    }

    /**
     * Zona central del header: rectángulo con letra y código de comprobante.
     *
     * @param mixed $pdf
     * @param mixed $afip_ticket
     * @param float $start_y
     * @return void
     */
    protected static function print_header_letter_zone($pdf, $afip_ticket, $start_y): void
    {
        /**
         * Rectángulo 30×20 mm con la letra del comprobante centrada.
         */
        $rect_x = 75;
        $rect_y = $start_y;
        $rect_w = 30;
        $rect_h = 20;

        self::draw_box($pdf, $rect_x, $rect_y, $rect_w, $rect_h);

        $pdf->y = $rect_y + 2;
        $pdf->x = $rect_x;
        $pdf->SetFont('Arial', 'B', 28);
        $pdf->Cell($rect_w, $rect_h - 4, (string) $afip_ticket->cbte_letra, 0, 1, 'C');

        /**
         * Código numérico del tipo de comprobante con zero-pad a 2 dígitos.
         */
        $pdf->x = $rect_x;
        $pdf->SetFont('Arial', '', 8);
        $codigo = self::left_pad((string) $afip_ticket->cbte_tipo, 2);
        $pdf->Cell($rect_w, 5, 'COD. '.$codigo, 0, 1, 'C');
    }

    /**
     * Zona derecha del header: tipo, punto de venta, número y fecha.
     *
     * @param mixed $pdf
     * @param mixed $afip_ticket
     * @param mixed $sale
     * @param float $start_y
     * @param mixed $pdf_instance Para leer use_current_date del PDF.
     * @return void
     */
    protected static function print_header_right_zone($pdf, $afip_ticket, $sale, $start_y, $pdf_instance): void
    {
        /**
         * Fecha de emisión: actual del servidor o fecha del ticket según perfil.
         */
        $emission_date = (!empty($pdf_instance->use_current_date))
            ? now()
            : $afip_ticket->created_at;

        $pdf->y = $start_y;
        $pdf->x = 110;
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell(95, 8, self::get_tipo_comprobante_label($afip_ticket, $sale), 0, 1, 'L');

        $punto_venta = self::left_pad((string) $afip_ticket->punto_venta, 5);
        $cbte_numero = self::left_pad((string) $afip_ticket->cbte_numero, 8);

        $pdf->x = 110;
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(
            95,
            5,
            'Punto de Venta: '.$punto_venta.'   Comp. Nro: '.$cbte_numero,
            0,
            1,
            'L'
        );

        $pdf->x = 110;
        $fecha_texto = $emission_date instanceof \DateTimeInterface
            ? $emission_date->format('d/m/Y')
            : date('d/m/Y', strtotime((string) $emission_date));
        $pdf->Cell(95, 5, 'Fecha de Emisión: '.$fecha_texto, 0, 1, 'L');
    }

    /**
     * Bloque de dos columnas con datos fiscales del emisor.
     *
     * @param mixed $pdf
     * @param mixed $afip_information
     * @return void
     */
    protected static function print_emisor_block($pdf, $afip_information): void
    {
        $start_y = $pdf->y;
        $start_x = 5;
        $block_width = 200;

        /**
         * Columna izquierda: razón social, domicilio y condición IVA.
         */
        $pdf->y = $start_y + 2;
        $pdf->x = $start_x + 2;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(98, 4, 'Razón Social: '.(string) $afip_information->razon_social, 0, 1, 'L');

        if (!empty($afip_information->owner_name)) {
            $pdf->x = $start_x + 2;
            $pdf->SetFont('Arial', 'I', 9);
            $pdf->Cell(98, 4, (string) $afip_information->owner_name, 0, 1, 'L');
        }

        $pdf->x = $start_x + 2;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(98, 4, 'Domicilio Comercial: '.(string) $afip_information->domicilio, 0, 1, 'L');

        $iva_name = $afip_information->iva_condition ? $afip_information->iva_condition->name : '';
        $pdf->x = $start_x + 2;
        $pdf->Cell(98, 4, 'Condición frente al IVA: '.$iva_name, 0, 1, 'L');

        $left_end_y = $pdf->y;

        /**
         * Columna derecha: CUIT, ingresos brutos e inicio de actividades.
         */
        $pdf->y = $start_y + 2;
        $pdf->x = 112;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(93, 4, 'CUIT: '.(string) $afip_information->cuit, 0, 1, 'L');

        $pdf->x = 112;
        $pdf->Cell(93, 4, 'Ingresos Brutos: '.(string) $afip_information->ingresos_brutos, 0, 1, 'L');

        if (!is_null($afip_information->inicio_actividades)) {
            $pdf->x = 112;
            $inicio = $afip_information->inicio_actividades instanceof \DateTimeInterface
                ? $afip_information->inicio_actividades->format('d/m/Y')
                : Carbon::parse($afip_information->inicio_actividades)->format('d/m/Y');
            $pdf->Cell(93, 4, 'Fecha de Inicio de Actividades: '.$inicio, 0, 1, 'L');
        }

        $end_y = max($left_end_y, $pdf->y) + 2;

        self::draw_box($pdf, $start_x, $start_y, $block_width, $end_y - $start_y);
        $pdf->Line(105, $start_y, 105, $end_y);

        $pdf->y = $end_y;
    }

    /**
     * Bloque de dos columnas con datos del receptor (cliente).
     *
     * @param mixed $pdf
     * @param mixed $sale
     * @return void
     */
    protected static function print_receptor_block($pdf, $sale): void
    {
        $client = $sale->client;
        $start_y = $pdf->y;
        $start_x = 5;
        $block_width = 200;

        /**
         * Condición de venta según cuenta corriente.
         */
        $condicion_venta = $sale->current_acount ? 'Cuenta corriente' : 'Contado';

        /**
         * Columna izquierda: CUIT, IVA y condición de venta.
         */
        $pdf->y = $start_y + 2;
        $pdf->x = $start_x + 2;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(98, 4, 'CUIT: '.(string) $client->cuit, 0, 1, 'L');

        if (!is_null($client->iva_condition)) {
            $pdf->x = $start_x + 2;
            $pdf->Cell(98, 4, 'Condición frente al IVA: '.$client->iva_condition->name, 0, 1, 'L');
        }

        $pdf->x = $start_x + 2;
        $pdf->Cell(98, 4, 'Condición de venta: '.$condicion_venta, 0, 1, 'L');

        $left_end_y = $pdf->y;

        /**
         * Columna derecha: nombre y domicilio del cliente.
         */
        $pdf->y = $start_y + 2;
        $pdf->x = 112;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(93, 4, 'Apellido y Nombre / Razón Social: '.(string) $client->name, 0, 1, 'L');

        $pdf->x = 112;
        $pdf->Cell(93, 4, 'Domicilio Comercial: '.(string) $client->address, 0, 1, 'L');

        $end_y = max($left_end_y, $pdf->y) + 2;

        self::draw_box($pdf, $start_x, $start_y, $block_width, $end_y - $start_y);
        $pdf->Line(105, $start_y, 105, $end_y);

        $pdf->y = $end_y;
    }

    /**
     * Resuelve la etiqueta del tipo de comprobante AFIP.
     *
     * @param mixed $afip_ticket
     * @param mixed $sale
     * @return string
     */
    public static function get_tipo_comprobante_label($afip_ticket, $sale): string
    {
        /**
         * Prioriza el nombre persistido en la relación afip_tipo_comprobante.
         */
        if (
            $afip_ticket->afip_tipo_comprobante
            && !empty($afip_ticket->afip_tipo_comprobante->name)
        ) {
            return (string) $afip_ticket->afip_tipo_comprobante->name;
        }

        /**
         * Inferencia por código cbte_tipo cuando no hay relación cargada.
         */
        $cbte_tipo = (int) $afip_ticket->cbte_tipo;

        if (in_array($cbte_tipo, [1, 6, 11, 51], true)) {
            return 'FACTURA';
        }
        if (in_array($cbte_tipo, [3, 8, 13], true)) {
            return 'NOTA DE CRÉDITO';
        }
        if (in_array($cbte_tipo, [2, 7, 12], true)) {
            return 'NOTA DE DÉBITO';
        }
        if ($cbte_tipo === 19) {
            return 'FACTURA DE EXPORTACIÓN';
        }
        if (in_array($cbte_tipo, [201, 206, 211], true)) {
            return 'FACTURA CRÉDITO ELECTRÓNICA MIPYMES';
        }

        return 'COMPROBANTE';
    }

    /**
     * Dibuja el footer fiscal completo: importes, QR, CAE y leyenda legal.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @param mixed $sale Venta asociada.
     * @param mixed $user Usuario emisor.
     * @param mixed $afip_helper Helper AFIP para resolver importes.
     * @param bool $show_importes Si false, omite bloque de importes (perfil sin total en pie).
     * @return void
     */
    public static function footer($pdf, $afip_ticket, $sale, $user, $afip_helper, $show_importes = true): void
    {
        if ($show_importes) {
            self::print_footer_importes_block($pdf, $afip_ticket, $sale, $afip_helper);
        }

        self::print_footer_qr_and_cae_block($pdf, $afip_ticket);
        self::print_footer_legal_text($pdf);
    }

    /**
     * Bloque de importes: tabla Otros Tributos + resumen fiscal con alícuotas IVA.
     *
     * @param mixed $pdf
     * @param mixed $afip_ticket
     * @param mixed $sale
     * @param mixed $afip_helper
     * @return void
     */
    protected static function print_footer_importes_block($pdf, $afip_ticket, $sale, $afip_helper): void
    {
        $start_y = $pdf->y + 5;

        /**
         * Columna izquierda: tabla "Otros Tributos" con filas fijas en 0,00.
         */
        $left_x = 5;
        $left_w = 97;
        $row_h = 5;

        $pdf->y = $start_y;
        $pdf->x = $left_x;
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($left_w, $row_h, 'Otros Tributos', 'B', 1, 'L');

        $col_desc_w = 50;
        $col_det_w = 17;
        $col_alic_w = 10;
        $col_imp_w = 20;

        $pdf->SetFont('Arial', '', 8);
        foreach (self::$otros_tributos_rows as $row_label) {
            $pdf->x = $left_x;
            $pdf->Cell($col_desc_w, $row_h, $row_label, 0, 0, 'L');
            $pdf->Cell($col_det_w, $row_h, '', 0, 0, 'L');
            $pdf->Cell($col_alic_w, $row_h, '', 0, 0, 'R');
            $pdf->Cell($col_imp_w, $row_h, '0,00', 0, 1, 'R');
        }

        $pdf->x = $left_x;
        $pdf->Cell($col_desc_w + $col_det_w + $col_alic_w, $row_h, 'Importe Otros Tributos:', 0, 0, 'L');
        $pdf->Cell($col_imp_w, $row_h, '0,00', 0, 1, 'R');

        $left_end_y = $pdf->y;

        /**
         * Columna derecha: resumen fiscal con importes reales del comprobante.
         */
        $right_x = 107;
        $right_w = 98;
        $importes = AfipImportesResolver::resolve($afip_ticket, $afip_helper);
        $moneda_id = $sale->moneda_id;

        $pdf->y = $start_y;
        $pdf->x = $right_x;

        /**
         * Nombre de moneda desde relación o valor por defecto.
         */
        $moneda_nombre = 'Peso Argentino';
        if ($sale->moneda && !empty($sale->moneda->name)) {
            $moneda_nombre = (string) $sale->moneda->name;
        }

        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($right_w, $row_h, 'Moneda: '.$moneda_nombre, 0, 1, 'L');

        $cbte_letra = (string) $afip_ticket->cbte_letra;

        if ($cbte_letra === 'A' || $cbte_letra === 'B') {
            $pdf->x = $right_x;
            $pdf->Cell($right_w, $row_h, 'Importe Neto Gravado: '.Numbers::price($importes['gravado'], true, $moneda_id), 0, 1, 'L');

            foreach (self::$iva_rate_labels as $iva_rate) {
                $importe_iva = 0;
                if (isset($importes['ivas'][$iva_rate]['Importe'])) {
                    $importe_iva = (float) $importes['ivas'][$iva_rate]['Importe'];
                }
                $pdf->x = $right_x;
                $pdf->Cell($right_w, $row_h, 'IVA '.$iva_rate.'%: '.Numbers::price($importe_iva, true, $moneda_id), 0, 1, 'L');
            }
        }

        $pdf->x = $right_x;
        $pdf->Cell($right_w, $row_h, 'Importe Otros Tributos: 0,00', 0, 1, 'L');

        /**
         * Total: respeta moneda de venta en comprobantes tipo E.
         */
        $total = (float) $importes['total'];
        $formatted_total = Numbers::price($total, true, $moneda_id);
        if ($cbte_letra === 'E') {
            $formatted_total = Numbers::price($sale->total, true, $moneda_id);
            $total = (float) $sale->total;
        }

        $pdf->x = $right_x;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell($right_w, $row_h + 2, 'Importe Total: '.$formatted_total, 0, 1, 'L');

        $right_end_y = $pdf->y;

        /**
         * Conversión a pesos cuando la venta está en moneda extranjera (dólar).
         */
        if ((int) $sale->moneda_id === 2 && (float) $sale->valor_dolar > 0) {
            $total_pesos = $total * (float) $sale->valor_dolar;
            $conversion_text = 'El total de este comprobante expresado en moneda de curso legal - Pesos Argentinos - considerándose un tipo de cambio de '
                .$sale->valor_dolar.', asciende a $ '.Numbers::price($total_pesos, true);

            $pdf->x = 5;
            $pdf->SetFont('Arial', '', 8);
            $pdf->MultiCell(200, 4, $conversion_text, 0, 'C', false);
            $right_end_y = max($right_end_y, $pdf->y);
        }

        $pdf->y = max($left_end_y, $right_end_y) + 3;
    }

    /**
     * Bloque QR AFIP + datos de CAE y número de página.
     *
     * @param mixed $pdf
     * @param mixed $afip_ticket
     * @return void
     */
    protected static function print_footer_qr_and_cae_block($pdf, $afip_ticket): void
    {
        $qr_start_y = $pdf->y;

        /**
         * QR: misma lógica de entorno que TicketInfoHelper (omitido en local).
         */
        if (config('app.APP_ENV') != 'local') {
            $qr_pdf = new AfipQrPdf($pdf, $afip_ticket, false);
            $qr_pdf->printQR();
            $pdf->y = $qr_start_y;
        }

        /**
         * Datos de CAE y paginación a la derecha del QR.
         */
        $pdf->y = $qr_start_y + 7;
        $pdf->x = 110;
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(95, 5, 'Pág. '.$pdf->PageNo(), 0, 1, 'C');

        $pdf->x = 110;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(30, 5, 'CAE N°:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(65, 5, (string) $afip_ticket->cae, 0, 1, 'L');

        $pdf->x = 110;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell(55, 5, 'Fecha de Vto. de CAE:', 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(40, 5, self::cae_expired_at_label($afip_ticket), 0, 1, 'L');

        $pdf->y = max($pdf->y, $qr_start_y + 45);
    }

    /**
     * Leyenda legal obligatoria al pie del comprobante fiscal.
     *
     * @param mixed $pdf
     * @return void
     */
    protected static function print_footer_legal_text($pdf): void
    {
        $pdf->x = 5;
        $pdf->SetFont('Arial', '', 7);
        $pdf->MultiCell(
            200,
            4,
            'Esta Administración Federal no se responsabiliza por los datos ingresados en el detalle de la operación',
            0,
            'C',
            false
        );
    }

    /**
     * Formatea la fecha de vencimiento del CAE para impresión.
     *
     * @param mixed $afip_ticket
     * @return string
     */
    protected static function cae_expired_at_label($afip_ticket): string
    {
        $cae_expired_at = $afip_ticket->cae_expired_at;
        if ($cae_expired_at instanceof \DateTimeInterface) {
            return $cae_expired_at->format('d/m/Y');
        }

        /**
         * Compatibilidad con formato legacy almacenado como string.
         */
        return substr((string) $cae_expired_at, 0, 10);
    }

    /**
     * Dibuja un rectángulo con cuatro líneas.
     *
     * @param mixed $pdf
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @return void
     */
    protected static function draw_box($pdf, $x, $y, $w, $h): void
    {
        $pdf->Line($x, $y, $x + $w, $y);
        $pdf->Line($x + $w, $y, $x + $w, $y + $h);
        $pdf->Line($x + $w, $y + $h, $x, $y + $h);
        $pdf->Line($x, $y + $h, $x, $y);
    }

    /**
     * Completa con ceros a la izquierda para códigos AFIP.
     *
     * @param string $value
     * @param int $length
     * @return string
     */
    protected static function left_pad(string $value, int $length): string
    {
        return str_pad($value, $length, '0', STR_PAD_LEFT);
    }
}
