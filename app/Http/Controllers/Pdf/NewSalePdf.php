<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\Afip\TicketInfoHelper;
use App\Models\Address;
use App\Services\PdfColumnService;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class NewSalePdf extends fpdf
{
    /**
     * Constructor principal del PDF nuevo con perfil de columnas.
     */
    public function __construct($sale, $pdf_column_profile_id, $afip_ticket_id)
    {

        $user = UserHelper::user();
        $this->pdf_column_profile = PdfColumnService::get_profile_for_print($user->id, 'sale', $pdf_column_profile_id);
        $this->profile_columns = $this->get_profile_columns();

        parent::__construct();
        $this->SetAutoPageBreak(false);
        $this->start_x = 5;
        $this->b = 0;
        $this->line_height = 5;
        $this->table_header_line_height = 7;
        $this->sale = $sale;
        $this->user = $user;
        $this->total_sale = 0;
        /**
         * Flag para decidir si este perfil imprime comprobante fiscal AFIP.
         */
        $this->is_afip_ticket = $this->pdf_column_profile ? (bool) $this->pdf_column_profile->is_afip_ticket : false;
        /**
         * Flag para controlar impresión de totales por cada página.
         */
        $this->show_totals_on_each_page = $this->pdf_column_profile ? (bool) $this->pdf_column_profile->show_totals_on_each_page : false;

        /**
         * Comprobante AFIP solicitado para impresión fiscal.
         */
        $this->afip_ticket = null;
        /**
         * Helper compartido de datos AFIP/ARCA.
         */
        $this->ticket_info_helper = null;
        /**
         * Helper AFIP consumido por resolvers de columnas (iva/subtotales).
         */
        $this->afip_helper = null;
        // dd($this->is_afip_ticket);
        if ($this->is_afip_ticket) {
            $this->afip_ticket = TicketInfoHelper::resolve_afip_ticket_for_sale($this->sale, $afip_ticket_id);
            $this->ticket_info_helper = new TicketInfoHelper($this->afip_ticket, $this->sale, $this->user);
            $this->afip_helper = $this->ticket_info_helper->afip_helper();
        }

        $this->AddPage();
        $this->items();
        $this->observations();
        $this->print_totals_only_on_last_page_when_needed();

        // if ($save_doc_as) {
        //     $this->Output('F', storage_path().'/app/public/oscar-pdf/'.$save_doc_as, true);
        // } else {
            $this->Output();
            exit;
        // }
    }

    /**
     * Obtiene columnas visibles desde relación belongs_to_many + pivot.
     */
    private function get_profile_columns()
    {
        if (! $this->pdf_column_profile) {
            return [];
        }
        if (! $this->pdf_column_profile->relationLoaded('pdf_column_options')) {
            $this->pdf_column_profile->load('pdf_column_options');
        }

        $rows = [];
        foreach ($this->pdf_column_profile->pdf_column_options as $option) {
            $visible = isset($option->pivot->visible) ? (bool) $option->pivot->visible : true;
            if (! $visible) {
                continue;
            }
            $rows[] = [
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'order' => isset($option->pivot->order) ? (int) $option->pivot->order : 0,
                'width' => isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width,
                'wrap_content' => isset($option->pivot->wrap_content) ? (bool) $option->pivot->wrap_content : false,
            ];
        }

        usort($rows, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $rows;
    }

    /**
     * Header del PDF nuevo con columnas dinámicas.
     */
    public function Header()
    {
        $data = [
            'num' => $this->sale->num,
            'date' => $this->sale->created_at,
            'title' => null,
            // 'fields' => $this->getFields(),
            'address' => $this->get_address(),
            'user' => $this->user,
            'table_margen' => 2,
        ];

        if (!$this->is_afip_ticket) {
            $data['model_info']     = $this->sale->client;
            $data['model_props']    = $this->getModelProps();
            $data['fields']         = $this->getFields();
        }
        
        PdfHelper::header($this, $data);
        /**
         * Si el perfil es fiscal, se agrega cabecera AFIP debajo del header comercial.
         */
        if ($this->is_afip_ticket && $this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {
            $this->ticket_info_helper->print_afip_header($this);
            $this->y += 5;
            PdfHelper::tableHeader($this, $this->getFields());
        }

    }

    /**
     * Footer simplificado con total general.
     */
    public function Footer()
    {
        if (!$this->is_afip_ticket && $this->should_print_totals_in_footer()) {
            $this->x = $this->start_x;
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(200, 10, 'Total: '.Numbers::price($this->sale->total, true, $this->sale->moneda_id), $this->b, 1, 'R');
        }

        /**
         * En perfiles fiscales se imprime bloque AFIP/ARCA en el pie.
         */
        if ($this->is_afip_ticket && $this->ticket_info_helper && $this->ticket_info_helper->has_afip_context() && $this->should_print_totals_in_footer()) {
            $this->ticket_info_helper->print_iva_and_totals($this, $this->sale);
            $this->ticket_info_helper->print_qr_and_arca_footer($this);
            $this->ticket_info_helper->print_fiscal_footer($this, $this->PageNo());
        }
    }

    /**
     * Mapa de anchos para dibujar header de tabla.
     */
    private function getFields()
    {
        $fields = [];
        foreach ($this->profile_columns as $column) {
            $fields[$column['label']] = $column['width'];
        }
        return $fields;
    }

    /**
     * Itera todos los ítems de la venta y renderiza con columnas dinámicas.
     */
    private function items()
    {
        $index = 1;
        foreach ($this->get_sale_items() as $item) {
            $this->printItemFromProfile($index, $item);
            $index++;
        }
    }

    /**
     * Colección de ítems de la venta en orden estándar.
     */
    private function get_sale_items()
    {
        $items = [];
        foreach ($this->sale->articles as $item) {
            $items[] = $item;
        }
        foreach ($this->sale->combos as $item) {
            $items[] = $item;
        }
        foreach ($this->sale->promocion_vinotecas as $item) {
            $items[] = $item;
        }
        foreach ($this->sale->services as $item) {
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Dibuja una fila usando definición de pivot (ancho/wrap/orden).
     */
    private function printItemFromProfile($index, $item)
    {
        /**
         * El salto de página usa un límite dinámico:
         * - si hay footer de totales en cada hoja, se reserva más espacio;
         * - si el total va solo al final, se aprovecha ese espacio para más ítems.
         */
        if ($this->y >= $this->get_items_page_break_limit_y()) {
            $this->AddPage();
        }

        $this->SetFont('Arial', '', 8);
        $this->x = $this->start_x;
        $row_height = $this->line_height;

        foreach ($this->profile_columns as $column) {
            $value = (string) $this->get_profile_column_value($column, $index, $item);
            $width = (int) $column['width'];
            $wrap_content = !empty($column['wrap_content']);
            if ($wrap_content && $width > 0) {
                $lines = $this->NbLines($width, $value);
                $estimated = max(1, $lines) * $this->line_height;
                if ($estimated > $row_height) {
                    $row_height = $estimated;
                }
            }
        }

        $start_x = $this->x;
        $start_y = $this->y;
        foreach ($this->profile_columns as $column) {
            $width = (int) $column['width'];
            $text = (string) $this->get_profile_column_value($column, $index, $item);
            $wrap_content = !empty($column['wrap_content']);
            $current_x = $this->x;
            $current_y = $this->y;

            if ($wrap_content) {
                $this->MultiCell($width, $this->line_height, $text, $this->b, 'L', false);
                $this->x = $current_x + $width;
                $this->y = $current_y;
            } else {
                /**
                 * Si no hay wrap, acortamos el texto al ancho disponible para evitar overflow.
                 */
                $short_text = $this->truncate_text_to_width($text, $width);
                $this->Cell($width, $row_height, $short_text, $this->b, 0, 'C');
            }
        }

        $this->x = $start_x;
        $this->y = $start_y + $row_height;
        $this->Line($start_x, $this->y, 210 - $start_x, $this->y);
    }

    /**
     * Devuelve el límite Y para salto de página durante impresión de ítems.
     *
     * @return int
     */
    private function get_items_page_break_limit_y(): int
    {
        /**
         * Cuando el footer se imprime en cada página, se deja mayor reserva
         * para el bloque de totales/fiscal.
         */
        if ($this->show_totals_on_each_page) {
            return 230;
        }

        /**
         * Si el total se imprime solo al final, se puede usar más área vertical
         * en páginas intermedias evitando bloques vacíos.
         */
        return 285;
    }

    /**
     * Resuelve cada valor de columna con PdfColumnService.
     */
    private function get_profile_column_value($column, $index, $item)
    {
        return PdfColumnService::resolve_value($column['value_resolver'], [
            'item' => $item,
            'index' => $index,
            'sale' => $this->sale,
            'afip_helper' => $this->afip_helper,
            'numbers' => Numbers::class,
            'general_helper' => GeneralHelper::class,
        ]);
    }

    /**
     * Observaciones de la venta.
     */
    private function observations()
    {
        if (!is_null($this->sale->observations)) {
            $this->SetFont('Arial', '', 14);
            $this->y += 5;
            $this->x = 5;
            $this->Cell(200, 7, 'Observaciones', $this->b, 1, 'L');
            $this->y += 2;
            $this->SetFont('Arial', '', 10);
            $this->x = 5;
            $this->MultiCell(200, 5, $this->sale->observations, $this->b, 'L', false);
            $this->y += 2;
        }
    }

    /**
     * Propiedades del cliente para header.
     */
    private function getModelProps()
    {
        return [
            ['text' => 'Cliente', 'key' => 'name'],
            ['text' => 'Telefono', 'key' => 'phone'],
            ['text' => 'Localidad', 'key' => 'location.name'],
            ['text' => 'Direccion', 'key' => 'address'],
            ['text' => 'Cuit', 'key' => 'cuit'],
        ];
    }

    /**
     * Direcciones para el header.
     */
    private function get_address()
    {
        $address = $this->sale->address;
        $addresses = [];
        if (!is_null($this->user->address_company)) {
            $addresses[] = $this->user->address_company;
        }
        if ($this->user->all_addresses_in_sale_pdf) {
            $addresses_models = Address::where('user_id', $this->user->id)->get();
            foreach ($addresses_models as $address_model) {
                $addresses[] = "{$address_model->street} {$address_model->street_number}, {$address_model->city}, {$address_model->province}";
            }
        } else if (!is_null($address)) {
            $addresses[] = "{$address->street} {$address->street_number}, {$address->city}, {$address->province}";
        }
        return $addresses;
    }

    /**
     * Utilidad para estimar líneas en MultiCell.
     */
    private function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', (string) $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
            }
            $l += $cw[$c] ?? 0;
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j) {
                        $i++;
                    }
                } else {
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else {
                $i++;
            }
        }
        return $nl;
    }

    /**
     * Acorta texto al ancho de celda usando puntos suspensivos si es necesario.
     */
    private function truncate_text_to_width($text, $width)
    {
        /**
         * Reserva mínima de padding interno de FPDF para no cortar al límite exacto.
         */
        $available_width = max(1, $width - 2);
        $text = (string) $text;

        if ($this->GetStringWidth($text) <= $available_width) {
            return $text;
        }

        $ellipsis = '...';
        $ellipsis_width = $this->GetStringWidth($ellipsis);
        if ($ellipsis_width >= $available_width) {
            return '';
        }

        $short_text = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return $text;
        }

        foreach ($chars as $char) {
            $candidate = $short_text.$char;
            if ($this->GetStringWidth($candidate) + $ellipsis_width > $available_width) {
                break;
            }
            $short_text = $candidate;
        }

        return $short_text.$ellipsis;
    }

    /**
     * Define si el total general va en footer de todas las hojas.
     *
     * @return bool
     */
    private function should_print_totals_in_footer(): bool
    {
        /**
         * En footer automático solo se imprime cuando el perfil
         * exige mostrar totales en cada página.
         */
        return (bool) $this->show_totals_on_each_page;
    }

    /**
     * En multipágina y flag desactivado, escribe total solo al cierre en última hoja.
     *
     * @return void
     */
    private function print_totals_only_on_last_page_when_needed(): void
    {
        /**
         * Si corresponde imprimir en cada página, el Footer ya se encarga.
         */
        if ($this->show_totals_on_each_page) {
            return;
        }

        /**
         * Reserva espacio y genera salto de página si no entra el bloque final.
         */
        if ($this->y >= 265) {
            $this->AddPage();
        }

        /**
         * Con flag desactivado, el bloque de totales se imprime
         * únicamente al final del documento (última página).
         */
        if (! $this->is_afip_ticket) {
            $this->y += 5;
            $this->x = $this->start_x;
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(200, 10, 'Total: '.Numbers::price($this->sale->total, true, $this->sale->moneda_id), $this->b, 1, 'R');
            return;
        }

        /**
         * En perfil fiscal se reutiliza el mismo bloque AFIP/ARCA
         * pero solo en la última página cuando el flag está desactivado.
         */
        if ($this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {
            $this->ticket_info_helper->print_iva_and_totals($this, $this->sale);
            $this->ticket_info_helper->print_qr_and_arca_footer($this);
            $this->ticket_info_helper->print_fiscal_footer($this, $this->PageNo());
        }
    }
}

