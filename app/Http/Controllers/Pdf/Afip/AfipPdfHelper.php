<?php

namespace App\Http\Controllers\Pdf\Afip;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Afip\AfipImportesResolver;
use App\Http\Controllers\Helpers\Numbers;
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
         * Bloque unificado emisor: dos recuadros laterales + letra centrada (modelo AFIP).
         */
        self::print_emisor_header_block($pdf, $afip_ticket, $sale, $user, $afip_information, $pdf);

        /**
         * Bloque de datos del receptor cuando la venta tiene cliente.
         */
        if (!is_null($sale->client)) {
            $pdf->y += 2;
            self::print_receptor_block($pdf, $sale);
            $pdf->y += 2;
        }
    }

    /**
     * Geometría del header fiscal: paneles laterales y cuadro de letra centrado.
     *
     * @return array<string, float|int>
     */
    protected static function get_header_layout(): array
    {
        /**
         * Márgenes y ancho útil del comprobante (coherente con el resto del PDF).
         */
        $page_left = 5;
        $page_width = 200;
        $page_right = $page_left + $page_width;

        /**
         * Cuadro central de letra del comprobante, centrado horizontalmente.
         * Dimensiones reducidas para minimizar espacio vertical en el header.
         */
        $letter_width = 25;
        $letter_height = 16;
        $letter_x = $page_left + ($page_width - $letter_width) / 2;

        /**
         * Eje vertical central del comprobante (mitad del ancho útil).
         */
        $center_x = $page_left + ($page_width / 2);

        /**
         * Paneles de contenido: a cada lado del cuadro de letra (sin solaparse con él).
         */
        $left_panel_x = $page_left;
        $left_panel_width = $letter_x - $page_left;
        $right_panel_x = $letter_x + $letter_width;
        $right_panel_width = $page_right - $right_panel_x;

        return [
            'page_left' => $page_left,
            'page_width' => $page_width,
            'page_right' => $page_right,
            'center_x' => $center_x,
            'letter_x' => $letter_x,
            'letter_width' => $letter_width,
            'letter_height' => $letter_height,
            'left_panel_x' => $left_panel_x,
            'left_panel_width' => $left_panel_width,
            'right_panel_x' => $right_panel_x,
            'right_panel_width' => $right_panel_width,
            'inner_pad' => 2,
            'logo_size' => 35,
        ];
    }

    /**
     * Imprime una línea con label en negrita y valor en peso normal.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param string $label Texto del label (incluir ":" al final).
     * @param string $value Valor a mostrar.
     * @param float $x Posición X inicial.
     * @param float $width Ancho total de la línea.
     * @param int $font_size Tamaño de fuente.
     * @param int $line_height Alto de línea.
     * @return void
     */
    protected static function print_label_value_line($pdf, $label, $value, $x, $width, $font_size = 9, $line_height = 4): void
    {
        $pdf->x = $x;
        $pdf->SetFont('Arial', 'B', $font_size);
        $label_width = $pdf->GetStringWidth($label);
        $pdf->Cell($label_width, $line_height, $label, 0, 0, 'L');

        $pdf->SetFont('Arial', '', $font_size);
        $value_width = $width - $label_width;
        if ($value_width > 0) {
            $pdf->Cell($value_width, $line_height, (string) $value, 0, 1, 'L');
        } else {
            $pdf->Ln($line_height);
        }
    }

    /**
     * Imprime label en negrita y valor con wrap correcto para textos extensos.
     *
     * Usa Write() con márgenes temporales para que cualquier salto de línea
     * del valor comience desde $x (borde izquierdo de la columna de texto),
     * no desde una posición indentada bajo el label.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param string $label Texto del label (incluir ":" al final).
     * @param string $value Valor a mostrar (puede ocupar varias líneas).
     * @param float $x Posición X inicial de la columna de texto.
     * @param float $width Ancho total disponible de la columna.
     * @param int $font_size Tamaño de fuente.
     * @param int $line_height Alto de línea.
     * @return void
     */
    protected static function print_label_value_multiline($pdf, $label, $value, $x, $width, $font_size = 9, $line_height = 4): void
    {
        /**
         * Establecer márgenes temporales para que Write() haga el wrap desde $x.
         * El margen derecho se calcula para que el texto no supere $x + $width.
         * El ancho total de página en A4 es 210mm; los márgenes del PDF son 5mm.
         */
        $page_total_width = $pdf->GetPageWidth();
        $pdf->SetLeftMargin($x);
        $pdf->SetRightMargin($page_total_width - $x - $width);
        $pdf->x = $x;

        $pdf->SetFont('Arial', 'B', $font_size);
        $pdf->Write($line_height, $label);

        $pdf->SetFont('Arial', '', $font_size);
        $pdf->Write($line_height, (string) $value);

        $pdf->Ln($line_height);

        /**
         * Restaurar márgenes al valor estándar del comprobante (5mm a cada lado).
         */
        $pdf->SetLeftMargin(5);
        $pdf->SetRightMargin(5);
    }

    /**
     * Bloque unificado del emisor: logo, datos fiscales, letra centrada y datos del comprobante.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @param mixed $sale Venta asociada.
     * @param mixed $user Usuario emisor.
     * @param mixed|null $afip_information Datos fiscales del emisor.
     * @param mixed $pdf_instance Para leer use_current_date del PDF.
     * @return void
     */
    protected static function print_emisor_header_block($pdf, $afip_ticket, $sale, $user, $afip_information, $pdf_instance): void
    {
        $layout = self::get_header_layout();
        $block_start_y = $pdf->y;
        $inner_pad = $layout['inner_pad'];
        $logo_size = $layout['logo_size'];

        $left_content_x = $layout['left_panel_x'] + $inner_pad;
        $left_content_width = $layout['left_panel_width'] - ($inner_pad * 2);
        $right_content_x = $layout['right_panel_x'] + $inner_pad;
        $right_content_width = $layout['right_panel_width'] - ($inner_pad * 2);

        /**
         * --- Panel izquierdo: logo + nombre comercial + datos fiscales del emisor ---
         * El logo se coloca pegado a los bordes del panel (sin inner_pad) para maximizar espacio.
         */
        $logo_x = $layout['left_panel_x'];
        $logo_y = $block_start_y;
        $logo_printed = self::print_logo_image($pdf, $user, $logo_x, $logo_y, $logo_size);

        /**
         * Columna de texto al costado del logo: desde el borde derecho del logo hasta
         * el borde derecho del panel, con 1mm de separación en cada extremo.
         */
        $text_gap = 1;
        $beside_logo_x = $logo_printed
            ? ($layout['left_panel_x'] + $logo_size + $text_gap)
            : ($layout['left_panel_x'] + $inner_pad);
        /**
         * Ancho hasta el borde derecho del panel izquierdo (sin margen adicional derecho).
         */
        $beside_logo_width = $logo_printed
            ? ($layout['left_panel_width'] - $logo_size - $text_gap)
            : $left_content_width;

        /**
         * Nombre del negocio a la derecha del logo, tipografía más grande.
         */
        $name_y = $block_start_y + $inner_pad;
        $company_name_font_size = 13;
        $company_name_line_height = 6;

        $pdf->y = $name_y;
        $pdf->x = $beside_logo_x;
        $pdf->SetFont('Arial', 'B', $company_name_font_size);
        $pdf->MultiCell($beside_logo_width, $company_name_line_height, (string) $user->company_name, 0, 'L');
        $name_end_y = $pdf->y;

        $logo_end_y = $logo_printed ? ($logo_y + $logo_size) : $name_y;

        /**
         * Razón social y domicilio al costado del logo, debajo de la altura del cuadro central.
         * Usan el mismo ancho calculado arriba, que ocupa todo el espacio disponible.
         */
        $fields_start_y = $block_start_y + $layout['letter_height'] + $inner_pad;
        $fields_end_y = $fields_start_y;

        if ($afip_information) {

            $pdf->y = $fields_start_y;

            self::print_label_value_multiline(
                $pdf,
                'Razón Social: ',
                (string) $afip_information->razon_social,
                $beside_logo_x,
                $beside_logo_width
            );

            if (!empty($afip_information->owner_name)) {
                $pdf->x = $beside_logo_x;
                $pdf->SetFont('Arial', 'I', 9);
                $pdf->MultiCell($beside_logo_width, 4, (string) $afip_information->owner_name, 0, 'L');
            }

            self::print_label_value_multiline(
                $pdf,
                'Domicilio Comercial: ',
                (string) $afip_information->domicilio_comercial,
                $beside_logo_x,
                $beside_logo_width
            );

            $fields_end_y = $pdf->y;
        }

        $left_end_y = max($logo_end_y, $name_end_y, $fields_end_y);

        /**
         * --- Cuadro central: letra y código del comprobante ---
         */
        self::print_header_letter_zone($pdf, $afip_ticket, $block_start_y, $layout);

        /**
         * --- Panel derecho: tipo de comprobante, PV, fecha y datos fiscales del emisor ---
         */
        $emission_date = (!empty($pdf_instance->use_current_date))
            ? now()
            : $afip_ticket->created_at;

        $pdf->y = $block_start_y + $inner_pad;
        $pdf->x = $right_content_x;
        $pdf->SetFont('Arial', 'B', 18);
        $pdf->Cell($right_content_width, 8, self::get_tipo_comprobante_label($afip_ticket, $sale), 0, 1, 'L');

        $punto_venta = self::left_pad((string) $afip_ticket->punto_venta, 5);
        $cbte_numero = self::left_pad((string) $afip_ticket->cbte_numero, 8);

        $pdf->x = $right_content_x;
        $pdf->SetFont('Arial', 'B', 10);
        $label_pv = 'Punto de Venta: ';
        $pdf->Cell($pdf->GetStringWidth($label_pv), 5, $label_pv, 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(22, 5, $punto_venta, 0, 1, 'L');

        $pdf->x = $right_content_x;
        $pdf->SetFont('Arial', 'B', 10);
        $label_nro = 'Comp. Nro: ';
        $pdf->Cell($pdf->GetStringWidth($label_nro), 5, $label_nro, 0, 0, 'L');
        $pdf->SetFont('Arial', '', 10);
        $remaining_width = $right_content_width - ($pdf->x - $right_content_x);
        $pdf->Cell($remaining_width, 5, $cbte_numero, 0, 1, 'L');

        $fecha_texto = $emission_date instanceof \DateTimeInterface
            ? $emission_date->format('d/m/Y')
            : date('d/m/Y', strtotime((string) $emission_date));
        self::print_label_value_line(
            $pdf,
            'Fecha de Emisión: ',
            $fecha_texto,
            $right_content_x,
            $right_content_width,
            10,
            5
        );

        if ($afip_information) {

            $iva_name = $afip_information->iva_condition ? $afip_information->iva_condition->name : '';
            self::print_label_value_line(
                $pdf,
                'Condición frente al IVA: ',
                $iva_name,
                $right_content_x,
                $right_content_width
            );

            self::print_label_value_line(
                $pdf,
                'CUIT: ',
                (string) $afip_information->cuit,
                $right_content_x,
                $right_content_width
            );

            self::print_label_value_line(
                $pdf,
                'Ingresos Brutos: ',
                (string) $afip_information->ingresos_brutos,
                $right_content_x,
                $right_content_width
            );

            if (!is_null($afip_information->inicio_actividades)) {
                $inicio = $afip_information->inicio_actividades instanceof \DateTimeInterface
                    ? $afip_information->inicio_actividades->format('d/m/Y')
                    : Carbon::parse($afip_information->inicio_actividades)->format('d/m/Y');
                self::print_label_value_line(
                    $pdf,
                    'Fecha de Inicio de Actividades: ',
                    $inicio,
                    $right_content_x,
                    $right_content_width
                );
            }
        }

        $right_end_y = $pdf->y;

        /**
         * Altura mínima del bloque: al menos la del cuadro de letra.
         */
        $min_block_height = $layout['letter_height'];
        $content_end_y = max($left_end_y, $right_end_y, $block_start_y + $min_block_height) + $inner_pad;

        /**
         * Bordes del bloque emisor: sin línea central en la franja superior (cuadro de letra).
         */
        self::draw_emisor_header_borders($pdf, $layout, $block_start_y, $content_end_y);

        $pdf->y = $content_end_y;
    }

    /**
     * Dibuja los bordes del header emisor estilo AFIP.
     * La línea central solo baja desde el borde inferior del cuadro de letra.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param array<string, float|int> $layout Geometría del header.
     * @param float $block_start_y Inicio Y del bloque.
     * @param float $content_end_y Fin Y del bloque.
     * @return void
     */
    protected static function draw_emisor_header_borders($pdf, $layout, $block_start_y, $content_end_y): void
    {
        $page_left = $layout['page_left'];
        $page_right = $layout['page_right'];
        $center_x = $layout['center_x'];
        $letter_x = $layout['letter_x'];
        $letter_width = $layout['letter_width'];
        $letter_height = $layout['letter_height'];
        $letter_right_x = $letter_x + $letter_width;
        $letter_bottom_y = $block_start_y + $letter_height;

        /**
         * Cuadro central de letra y código.
         */
        self::draw_box($pdf, $letter_x, $block_start_y, $letter_width, $letter_height);

        /**
         * Borde superior: segmentos izquierdo y derecho (interrumpidos por el cuadro de letra).
         */
        $pdf->Line($page_left, $block_start_y, $letter_x, $block_start_y);
        $pdf->Line($letter_right_x, $block_start_y, $page_right, $block_start_y);

        /**
         * Borde inferior a ancho completo.
         */
        $pdf->Line($page_left, $content_end_y, $page_right, $content_end_y);

        /**
         * Bordes laterales exteriores a altura completa.
         */
        $pdf->Line($page_left, $block_start_y, $page_left, $content_end_y);
        $pdf->Line($page_right, $block_start_y, $page_right, $content_end_y);

        /**
         * Divisor central: solo desde el centro del borde inferior del cuadro de letra.
         */
        $pdf->Line($center_x, $letter_bottom_y, $center_x, $content_end_y);
    }

    /**
     * Imprime el logo cuadrado del emisor en las coordenadas indicadas.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $user Usuario emisor.
     * @param float $x Posición X del logo.
     * @param float $y Posición Y del logo.
     * @param int $logo_size Tamaño del logo en mm.
     * @return bool True si se imprimió una imagen.
     */
    protected static function print_logo_image($pdf, $user, $x, $y, $logo_size): bool
    {
        $logo_url = $user->image_url;

        if (config('app.APP_ENV') == 'local') {
            $pdf->Image(
                'https://img.freepik.com/vector-gratis/ilustracion-banner-sello-circulo_53876-28480.jpg',
                $x,
                $y,
                $logo_size,
                $logo_size
            );

            return true;
        }

        if (!is_null($logo_url) && GeneralHelper::file_exists_2($logo_url)) {
            $pdf->Image($logo_url, $x, $y, $logo_size, $logo_size);

            return true;
        }

        return false;
    }

    /**
     * Zona central del header: letra y código de comprobante (sin borde; se dibuja al final).
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @param float $start_y Posición Y inicial del bloque.
     * @param array<string, float|int> $layout Geometría del header.
     * @return void
     */
    protected static function print_header_letter_zone($pdf, $afip_ticket, $start_y, $layout): void
    {
        $rect_x = $layout['letter_x'];
        $rect_w = $layout['letter_width'];
        $rect_h = $layout['letter_height'];

        /**
         * Letra grande sin padding superior, pegada a la línea de COD para máxima compacidad.
         * letter_cell_h + cod_cell_h = letter_height exacto.
         */
        $letter_cell_h = 11;
        $cod_cell_h = 5;

        $pdf->y = $start_y;
        $pdf->x = $rect_x;
        $pdf->SetFont('Arial', 'B', 22);
        $pdf->Cell($rect_w, $letter_cell_h, (string) $afip_ticket->cbte_letra, 0, 1, 'C');

        $pdf->x = $rect_x;
        $pdf->SetFont('Arial', '', 7);
        $codigo = self::left_pad((string) $afip_ticket->cbte_tipo, 2);
        $pdf->Cell($rect_w, $cod_cell_h, 'COD. '.$codigo, 0, 1, 'C');

        /**
         * Restablece Y al fondo del cuadro para no desalinear otros paneles.
         */
        $pdf->y = $start_y + $rect_h;
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
        $content_x = $start_x + 2;
        $left_content_width = 98;
        $right_content_x = 112;
        $right_content_width = 93;

        $pdf->y = $start_y + 2;

        self::print_label_value_line(
            $pdf,
            'CUIT: ',
            (string) $client->cuit,
            $content_x,
            $left_content_width
        );

        if (!is_null($client->iva_condition)) {
            self::print_label_value_line(
                $pdf,
                'Condición frente al IVA: ',
                $client->iva_condition->name,
                $content_x,
                $left_content_width
            );
        }

        self::print_label_value_line(
            $pdf,
            'Condición de venta: ',
            $condicion_venta,
            $content_x,
            $left_content_width
        );

        $left_end_y = $pdf->y;

        /**
         * Columna derecha: nombre y domicilio del cliente.
         */
        $pdf->y = $start_y + 2;

        self::print_label_value_line(
            $pdf,
            'Apellido y Nombre / Razón Social: ',
            (string) $client->name,
            $right_content_x,
            $right_content_width
        );

        self::print_label_value_line(
            $pdf,
            'Domicilio Comercial: ',
            (string) $client->address,
            $right_content_x,
            $right_content_width
        );

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

        self::print_footer_official_block($pdf, $afip_ticket);
    }

    /**
     * Bloque de importes: tabla Otros Tributos + resumen fiscal (modelo AFIP).
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @param mixed $sale Venta asociada.
     * @param mixed $afip_helper Helper AFIP.
     * @return void
     */
    protected static function print_footer_importes_block($pdf, $afip_ticket, $sale, $afip_helper): void
    {
        $start_y = $pdf->y + 5;
        $page_left = 5;
        $page_width = 200;
        $row_h = 5;

        /**
         * Columna izquierda: tabla "Otros Tributos" con encabezado y celdas bordeadas.
         */
        $left_x = $page_left;
        $left_w = 97;
        $col_desc_w = 55;
        $col_det_w = 15;
        $col_alic_w = 10;
        $col_imp_w = 17;

        /**
         * Título con borde inferior solamente, como en el modelo AFIP.
         */
        $pdf->y = $start_y;
        $pdf->x = $left_x;
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($left_w, $row_h, 'Otros Tributos', 'B', 1, 'L');

        /**
         * Encabezado de columnas con fondo gris y borde completo.
         */
        $pdf->x = $left_x;
        $pdf->SetFillColor(210, 210, 210);
        $pdf->SetFont('Arial', 'B', 7);
        $pdf->Cell($col_desc_w, $row_h, 'Descripción', 1, 0, 'C', true);
        $pdf->Cell($col_det_w, $row_h, 'Detalle', 1, 0, 'C', true);
        $pdf->Cell($col_alic_w, $row_h, 'Alic. %', 1, 0, 'C', true);
        $pdf->Cell($col_imp_w, $row_h, 'Importe', 1, 1, 'C', true);

        /**
         * Filas de datos con borde y texto normal (sin relleno).
         */
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetFillColor(255, 255, 255);
        foreach (self::$otros_tributos_rows as $row_label) {
            $pdf->x = $left_x;
            $pdf->Cell($col_desc_w, $row_h, $row_label, 1, 0, 'L');
            $pdf->Cell($col_det_w, $row_h, '', 1, 0, 'L');
            $pdf->Cell($col_alic_w, $row_h, '', 1, 0, 'R');
            $pdf->Cell($col_imp_w, $row_h, '0,00', 1, 1, 'R');
        }

        /**
         * Fila de subtotal con el código de moneda visible en la celda de descripción.
         */
        $moneda_code = (int) $sale->moneda_id === 2 ? ' USD' : '';
        $pdf->x = $left_x;
        $pdf->SetFont('Arial', '', 8);
        $pdf->Cell($col_desc_w + $col_det_w + $col_alic_w, $row_h, 'Importe Otros Tributos:'.$moneda_code, 1, 0, 'L');
        $pdf->Cell($col_imp_w, $row_h, '0,00', 1, 1, 'R');

        $left_end_y = $pdf->y;

        /**
         * Columna derecha: moneda y totales alineados a la derecha.
         */
        $right_x = 107;
        $right_w = 98;
        $importes = AfipImportesResolver::resolve($afip_ticket, $afip_helper);
        $moneda_id = $sale->moneda_id;
        $moneda_label = self::get_footer_moneda_label($sale);

        /**
         * Primera línea de la columna derecha: moneda como texto simple alineado a la derecha.
         */
        $pdf->y = $start_y;
        $pdf->x = $right_x;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($right_w, $row_h, 'Moneda: '.$moneda_label, 0, 1, 'R');

        $cbte_letra = (string) $afip_ticket->cbte_letra;

        if ($cbte_letra === 'A' || $cbte_letra === 'B') {
            self::print_footer_right_row(
                $pdf, $right_x, $right_w,
                'Importe Neto Gravado:',
                Numbers::price($importes['gravado'], true, $moneda_id),
                false, $row_h
            );

            foreach (self::$iva_rate_labels as $iva_rate) {
                $importe_iva = 0;
                if (isset($importes['ivas'][$iva_rate]['Importe'])) {
                    $importe_iva = (float) $importes['ivas'][$iva_rate]['Importe'];
                }
                self::print_footer_right_row(
                    $pdf, $right_x, $right_w,
                    'IVA '.$iva_rate.'%:',
                    Numbers::price($importe_iva, true, $moneda_id),
                    false, $row_h
                );
            }
        }

        self::print_footer_right_row($pdf, $right_x, $right_w, 'Importe Otros Tributos:', '0,00', false, $row_h);

        $total = (float) $importes['total'];
        $formatted_total = Numbers::price($total, true, $moneda_id);
        if ($cbte_letra === 'E') {
            $formatted_total = Numbers::price($sale->total, true, $moneda_id);
            $total = (float) $sale->total;
        }

        /**
         * Importe Total: label y valor ambos en negrita, alto de fila levemente mayor.
         */
        self::print_footer_right_row($pdf, $right_x, $right_w, 'Importe Total:', $formatted_total, true, $row_h + 1);

        $right_end_y = $pdf->y;
        $pdf->y = max($left_end_y, $right_end_y);

        /**
         * Caja de conversión a pesos para ventas en moneda extranjera.
         */
        if ((int) $sale->moneda_id === 2 && (float) $sale->valor_dolar > 0 && $cbte_letra === 'E') {
            $total_pesos = $total * (float) $sale->valor_dolar;
            self::print_footer_conversion_box($pdf, $page_left, $page_width, $sale->valor_dolar, $total_pesos);
        }

        $pdf->y += 3;
    }

    /**
     * Resuelve la etiqueta de moneda para el pie fiscal.
     *
     * @param mixed $sale Venta asociada.
     * @return string
     */
    protected static function get_footer_moneda_label($sale): string
    {
        if ((int) $sale->moneda_id === 2) {
            return 'USD - Dólar Estadounidense';
        }

        if ($sale->moneda && !empty($sale->moneda->name)) {
            return (string) $sale->moneda->name;
        }

        return 'Peso Argentino';
    }

    /**
     * Imprime una fila de la columna de totales con label en negrita (izquierda) y valor (derecha).
     *
     * @param mixed $pdf Instancia FPDF.
     * @param float $x Posición X inicial.
     * @param float $total_width Ancho total de la fila.
     * @param string $label Texto del label (sin valor numérico).
     * @param string $value Valor numérico formateado (con símbolo de moneda si corresponde).
     * @param bool $bold_value Si true, el valor también se imprime en negrita (para Importe Total).
     * @param int $line_h Alto de línea.
     * @return void
     */
    protected static function print_footer_right_row($pdf, $x, $total_width, $label, $value, $bold_value = false, $line_h = 5): void
    {
        /**
         * Ancho fijo para la columna de label; el resto para el valor numérico.
         */
        $label_col_w = 65;
        $value_col_w = $total_width - $label_col_w;

        $pdf->x = $x;
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($label_col_w, $line_h, $label, 0, 0, 'L');

        $pdf->SetFont('Arial', $bold_value ? 'B' : '', 9);
        $pdf->Cell($value_col_w, $line_h, $value, 0, 1, 'R');
    }

    /**
     * Dibuja la caja de conversión a pesos argentinos (comprobantes en moneda extranjera).
     *
     * @param mixed $pdf Instancia FPDF.
     * @param float $x Inicio X de la caja.
     * @param float $width Ancho de la caja.
     * @param float $tipo_cambio Cotización utilizada.
     * @param float $total_pesos Total convertido a ARS.
     * @return void
     */
    protected static function print_footer_conversion_box($pdf, $x, $width, $tipo_cambio, $total_pesos): void
    {
        $box_y = $pdf->y + 2;
        $box_h = 10;
        $inner_pad = 2;
        $text_width = 145;

        $conversion_text = 'El total de este comprobante expresado en Dólar Estadounidense - considerándose un tipo de cambio consignado de '
            .number_format((float) $tipo_cambio, 6, ',', '.')
            .' asciende a:';

        $pdf->y = $box_y + $inner_pad;
        $pdf->x = $x + $inner_pad;
        $pdf->SetFont('Arial', '', 8);
        $pdf->MultiCell($text_width, 4, $conversion_text, 0, 'L');

        $pdf->y = $box_y + 3;
        $pdf->x = $x + $text_width;
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->Cell($width - $text_width - $inner_pad, 5, '$ '.Numbers::price($total_pesos), 0, 0, 'R');

        self::draw_box($pdf, $x, $box_y, $width, $box_h);
        $pdf->y = $box_y + $box_h;
    }

    /**
     * Bloque oficial del pie: QR, logo AFIP, leyenda y datos de CAE (modelo AFIP).
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @return void
     */
    protected static function print_footer_official_block($pdf, $afip_ticket): void
    {
        $footer_start_y = $pdf->y + 4;
        $page_left = 5;
        $page_width = 200;
        $center_x = $page_left + ($page_width / 2);
        $qr_size = 45;
        $afip_logo_w = 40;
        $afip_logo_h = 18;
        $cae_block_x = 138;
        $cae_block_w = 62;

        /**
         * Paginación centrada sobre el bloque AFIP.
         */
        $pdf->y = $footer_start_y;
        $pdf->x = $center_x - 25;
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(50, 4, 'Pág. '.$pdf->PageNo(), 0, 0, 'C');

        /**
         * QR AFIP a la izquierda (omitido en entorno local).
         */
        $qr_bottom_y = $footer_start_y + $qr_size;
        if (config('app.APP_ENV') != 'local') {
            self::print_afip_qr_image($pdf, $afip_ticket, $page_left, $footer_start_y + 2, $qr_size);
        }

        /**
         * Logo AFIP y leyenda "Comprobante Autorizado" al centro.
         */
        $logo_path = public_path().'/afip/logo.jpg';
        $logo_x = $center_x - ($afip_logo_w / 2);
        $logo_y = $footer_start_y + 5;

        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, $logo_x, $logo_y, $afip_logo_w, $afip_logo_h);
        }

        $pdf->y = $logo_y + $afip_logo_h + 1;
        $pdf->x = $center_x - 50;
        $pdf->SetFont('Arial', 'BI', 9);
        $pdf->Cell(100, 4, 'Comprobante Autorizado', 0, 1, 'C');

        $pdf->x = $center_x - 50;
        $pdf->SetFont('Arial', '', 6);
        $pdf->MultiCell(
            100,
            3,
            'Esta Administración Federal no se responsabiliza por los datos ingresados en el detalle de la operación',
            0,
            'C'
        );

        $center_end_y = $pdf->y;

        /**
         * CAE y vencimiento alineados a la derecha.
         */
        $pdf->y = $footer_start_y + 8;
        $pdf->x = $cae_block_x;
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(24, 5, 'CAE N°:', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($cae_block_w - 24, 5, (string) $afip_ticket->cae, 0, 1, 'L');

        $pdf->x = $cae_block_x;
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell(42, 5, 'Fecha de Vto. de CAE:', 0, 0, 'R');
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell($cae_block_w - 42, 5, self::cae_expired_at_label($afip_ticket), 0, 1, 'L');

        $cae_end_y = $pdf->y;
        $pdf->y = max($qr_bottom_y, $center_end_y, $cae_end_y) + 2;
    }

    /**
     * Imprime el código QR AFIP en coordenadas fijas del pie fiscal.
     *
     * @param mixed $pdf Instancia FPDF.
     * @param mixed $afip_ticket Ticket AFIP.
     * @param float $x Posición X.
     * @param float $y Posición Y.
     * @param float $size Tamaño del QR en mm.
     * @return void
     */
    protected static function print_afip_qr_image($pdf, $afip_ticket, $x, $y, $size): void
    {
        $data = [
            'ver' => 1,
            'fecha' => date_format($afip_ticket->created_at, 'Y-m-d'),
            'cuit' => $afip_ticket->cuit_negocio,
            'ptoVta' => $afip_ticket->punto_venta,
            'tipoCmp' => $afip_ticket->cbte_tipo,
            'nroCmp' => $afip_ticket->cbte_numero,
            'importe' => $afip_ticket->importe_total,
            'moneda' => $afip_ticket->moneda_id,
            'ctz' => 1,
            'tipoDocRec' => AfipHelper::getDocType('Cuit'),
            'nroDocRec' => $afip_ticket->cuit_cliente,
            'codAut' => $afip_ticket->cae,
        ];

        $afip_link = 'https://www.afip.gob.ar/fe/qr/?'.base64_encode(json_encode($data));
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.$afip_link.'&format=jpeg#.jpg';

        if (GeneralHelper::file_exists_2($url)) {
            $pdf->Image($url, $x, $y, $size, $size);
        }
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
