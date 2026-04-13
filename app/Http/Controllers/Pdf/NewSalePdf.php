<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
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

        $this->total_articles = 0;
        $this->total_promocion_vinotecas = 0;
        $this->total_combos = 0;
        $this->total_services = 0;

        /**
         * Flag para decidir si este perfil imprime comprobante fiscal AFIP.
         */
        $this->is_afip_ticket = $this->pdf_column_profile ? (bool) $this->pdf_column_profile->is_afip_ticket : false;
        /**
         * Flag para controlar impresión de totales por cada página.
         */
        $this->show_totals_on_each_page = $this->pdf_column_profile ? (bool) $this->pdf_column_profile->show_totals_on_each_page : false;
        /**
         * Flag para mostrar tabla de comisiones en bloque de footer.
         */
        $this->show_comissions = $this->pdf_column_profile
            ? $this->normalize_boolean($this->pdf_column_profile->show_comissions, false)
            : false;
        /**
         * Flag para mostrar total costos en bloque de footer.
         */
        $this->show_total_costs = $this->pdf_column_profile
            ? $this->normalize_boolean($this->pdf_column_profile->show_total_costs, false)
            : false;
        /**
         * Flag para controlar visibilidad del total general en el pie del PDF.
         * Default true para mantener comportamiento en perfiles legacy.
         */
        $this->show_total_in_footer = $this->pdf_column_profile
            ? $this->normalize_boolean($this->pdf_column_profile->show_total_in_footer, true)
            : true;
        /**
         * Texto libre del pie de página; se renderiza con MultiCell debajo de los totales.
         * Sigue la misma regla de visibilidad que show_totals_on_each_page.
         */
        $this->footer_text = $this->pdf_column_profile ? ($this->pdf_column_profile->footer_text ?: '') : '';
        /**
         * Cuando es true, el header imprime la fecha actual del servidor
         * en lugar de la fecha en que se creó el comprobante.
         * Default false para mantener comportamiento legacy.
         */
        $this->use_current_date = $this->pdf_column_profile
            ? (bool) $this->pdf_column_profile->use_current_date
            : false;

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
            'title' => null,
            // 'fields' => $this->getFields(),
            'address' => $this->get_address(),
            'user' => $this->user,
            'table_margen' => 2,
        ];

        /**
         * Fecha a imprimir en el header: fecha actual si use_current_date está activo,
         * o la fecha del comprobante (Sale o AfipTicket) en caso contrario.
         */
        $print_date = $this->use_current_date ? now() : null;

        if (!$this->is_afip_ticket) {
            $data['model_info']     = $this->sale->client;
            $data['model_props']    = $this->getModelProps();
            $data['fields']         = $this->getFields();
            $data['titulo']         = $this->user->company_name;
            $data['date']           = $print_date ?? $this->sale->created_at;
        } else if ($this->afip_ticket) {
            $data['title']          = $this->afip_ticket->cbte_letra;
            $data['num']            = $this->getPuntoVenta() .' - '. $this->getNumCbte();
            $data['titulo']         = $this->afip_ticket->afip_information->razon_social;
            $data['date']           = $print_date ?? $this->afip_ticket->created_at;
            $data['afip_ticket']    = $this->afip_ticket;
        }

        
        if (
            UserHelper::hasExtencion('vendedor_en_sale_pdf', $this->user)
            && $this->sale->employee
        ) {
            
            $data['extra_info'] = [
                'Vendedor'  => $this->sale->employee->name
            ];
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

    


    function getPuntoVenta() {
        $letras_faltantes = 5 - strlen($this->afip_ticket->punto_venta);
        $punto_venta = '';
        for ($i=0; $i < $letras_faltantes; $i++) { 
            $punto_venta .= '0'; 
        }
        $punto_venta  .= $this->afip_ticket->punto_venta;
        return $punto_venta;
    }

    function getNumCbte() {
        $letras_faltantes = 8 - strlen($this->afip_ticket->cbte_numero);
        $cbte_numero = '';
        for ($i=0; $i < $letras_faltantes; $i++) { 
            $cbte_numero .= '0'; 
        }
        $cbte_numero  .= $this->afip_ticket->cbte_numero;
        return $cbte_numero;
    }

    /**
     * Footer con total general y pie de página opcional.
     * Cuando hay footer_text, reposiciona Y para que ambos bloques quepan en la página.
     */
    public function Footer()
    {
        if (!$this->is_afip_ticket && $this->should_print_totals_in_footer()) {

            $this->y += 5;

            $this->x = $this->start_x;
            /**
             * Total general visible/oculto según configuración del perfil.
             */
            if ($this->should_print_total_in_footer()) {

                $this->SetFont('Arial', 'B', 12);
                $this->Cell(200, 10, 'Total: '.Numbers::price($this->sale->total, true, $this->sale->moneda_id), $this->b, 1, 'R');
            }
            $this->print_optional_footer_extras();
            $this->print_footer_text_block();
        }

        /**
         * En perfiles fiscales se imprime bloque AFIP/ARCA en el pie,
         * seguido del texto de pie de página si está configurado.
         */
        if ($this->is_afip_ticket && $this->ticket_info_helper && $this->ticket_info_helper->has_afip_context() && $this->should_print_totals_in_footer()) {
            /**
             * En perfiles fiscales, el bloque IVA/totales se considera "total" del footer.
             */
            if ($this->should_print_total_in_footer()) {
                $this->ticket_info_helper->print_iva_and_totals($this, $this->sale);
            }
            $this->print_optional_footer_extras();
            $this->print_footer_text_block();
            $this->ticket_info_helper->print_qr_and_arca_footer($this);
            $this->ticket_info_helper->print_fiscal_footer($this, $this->PageNo());
        }
    }

    function descuentos_y_recargos() {

        $this->total_bruto = $this->total_articles + $this->total_combos + $this->total_promocion_vinotecas + $this->total_services;
        $this->discounts();
        $this->surchages();

    }



    function discounts() {
        if (count($this->sale->discounts) >= 1) {

            $this->y += 5;

            $this->SetFont('Arial', 'B', 9);


            foreach ($this->sale->discounts as $discount) {
                
                $total_descuento = 0;
                
                $this->x = $this->start_x;
                
                $monto_descuento = $this->total_articles * floatval($discount->pivot->percentage) / 100;
                $this->total_articles -= $monto_descuento;
                $total_descuento += $monto_descuento;

                $monto_descuento = $this->total_combos * floatval($discount->pivot->percentage) / 100;
                $this->total_combos -= $monto_descuento;
                $total_descuento += $monto_descuento;

                $monto_descuento = $this->total_promocion_vinotecas * floatval($discount->pivot->percentage) / 100;
                $this->total_promocion_vinotecas -= $monto_descuento;
                $total_descuento += $monto_descuento;

                if ($this->sale->discounts_in_services) {
                    
                    $monto_descuento = $this->total_services * floatval($discount->pivot->percentage) / 100;
                    $this->total_services -= $monto_descuento;
                    $total_descuento += $monto_descuento;
                }

                $this->total_bruto -= $total_descuento;

                $text = 'Menos '.Numbers::price($total_descuento, true, $this->sale->moneda_id).' ('.$discount->pivot->percentage.'% '.$discount->name.') = '.Numbers::price($this->total_bruto, true);

                $this->Cell(
                    200, 
                    7, 
                    $text, 
                    $this->b, 
                    1, 
                    'R'
                );
            }
            if (count($this->sale->services) > 0) {
                $this->x = $this->start_x;
                if ($this->sale->discounts_in_services) {
                    $text = 'Se aplican descuentos a los servicios';
                } else {
                    $text = 'No se aplican descuentos a los servicios';
                }
                $this->Cell(
                    200, 
                    7, 
                    $text, 
                    $this->b, 
                    1, 
                    'R'
                );
            } 
        }
    }

    function surchages() {
        if (
            count($this->sale->surchages) >= 1
            && !$this->sale->aplicar_recargos_directo_a_items
        ) {
            $this->SetFont('Arial', '', 9);
            // $total_articles = $this->total_articles;
            // $total_services = $this->total_services;
            // $total_combos = $this->total_combos;
            // dd($total_combos);


            foreach ($this->sale->surchages as $surchage) {
                $this->x = $this->start_x;
                
                $total_recargo = 0;


                $monto_recargo = $this->total_articles * floatval($surchage->pivot->percentage) / 100;
                $this->total_articles += $monto_recargo;
                $total_recargo += $monto_recargo;


                $monto_recargo = $this->total_combos * floatval($surchage->pivot->percentage) / 100;
                $this->total_combos += $monto_recargo;
                $total_recargo += $monto_recargo;

                $monto_recargo = $this->total_promocion_vinotecas * floatval($surchage->pivot->percentage) / 100;
                $this->total_promocion_vinotecas += $monto_recargo;
                $total_recargo += $monto_recargo;

                // dd($this->sale->discounts_in_services);
                if ($this->sale->surchages_in_services) {
                    
                    $monto_recargo = $this->total_services * floatval($surchage->pivot->percentage) / 100;
                    $this->total_services += $monto_recargo;
                    $total_recargo += $monto_recargo;
                // dd($total_recargo);
                }

                // $total_with_discounts = $this->total_articles + $this->total_services + $this->total_combos + $this->total_promocion_vinotecas;

                $this->total_bruto += $total_recargo;

                $text = 'Mas '.Numbers::price($total_recargo, true, $this->sale->moneda_id).' ('.$surchage->pivot->percentage.'% '.$surchage->name.') = '.Numbers::price($this->total_bruto, true);

                $this->Cell(
                    200, 
                    7, 
                    $text, 
                    $this->b, 
                    1, 
                    'R'
                );

            }
            if (count($this->sale->services) > 0) {
                $this->x = $this->start_x;
                if ($this->sale->surchages_in_services) {
                    $text = 'Se aplican recargos a los servicios';
                } else {
                    $text = 'No se aplican recargos a los servicios';
                }
                $this->Cell(
                    200, 
                    7, 
                    $text, 
                    $this->b, 
                    1, 
                    'R'
                );
            } 
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
            $item->is_article = true;
            $items[] = $item;
        }
        foreach ($this->sale->combos as $item) {
            $item->is_combo = true;
            $items[] = $item;
        }
        foreach ($this->sale->promocion_vinotecas as $item) {
            $item->is_promocion_vinotecas = true;
            $items[] = $item;
        }
        foreach ($this->sale->services as $item) {
            $item->is_service = true;
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

        $this->sumar_totales($item);

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

    function sumar_totales($item) {

        if ($item->is_article) {

            $this->total_articles += $this->sub_total($item);

        } else if ($item->is_combo) {

            $this->total_combos += $this->sub_total($item);

        } else if ($item->is_promocion_vinotecas) {

            $this->total_promocion_vinotecas += $this->sub_total($item);

        } else if ($item->is_service) {
            
            $this->total_services += $this->sub_total($item);

        }
    }

    function sub_total($item) {

        $amount = $item->pivot->amount;
        
        $total = $item->pivot->price * $amount;
        if (!is_null($item->pivot->discount)) {
            $total -= $total * ($item->pivot->discount / 100);
        }
        return $total;
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

            $base_limit_y = $this->afip_ticket ? 210 : 260;
            /**
             * Reserva extra cuando el perfil agrega comisiones/costos y/o texto libre
             * en cada pie para evitar superposición con filas de ítems.
             */
            $extra_reserved_height = $this->estimate_optional_footer_extras_height()
                + $this->estimate_footer_text_height();

            return max(120, $base_limit_y - $extra_reserved_height);
        }

        /**
         * Si el total se imprime solo al final, se puede usar más área vertical
         * en páginas intermedias evitando bloques vacíos.
         */
        return 285;

    }

    /**
     * Estima altura dinámica del bloque de texto libre del footer.
     *
     * @return int
     */
    private function estimate_footer_text_height(): int
    {
        if (! $this->footer_text) {
            return 0;
        }

        /**
         * Replica spacing de print_footer_text_block():
         * +1 en no fiscal y 5 por cada línea de MultiCell.
         */
        $spacing = $this->afip_ticket ? 0 : 1;
        $lines = $this->NbLines(200, (string) $this->footer_text);

        return $spacing + (max(1, $lines) * 5);
    }

    /**
     * Estima altura adicional consumida por comisiones/costos en el footer.
     *
     * @return int
     */
    private function estimate_optional_footer_extras_height(): int
    {
        $height = 0;

        if ($this->show_comissions && count($this->sale->seller_commissions) > 0) {
            /**
             * Tabla de comisiones:
             * - header "Comisiones" = 5
             * - cada comisión = 5
             * - separación final = 2
             */
            $height += 5 + (count($this->sale->seller_commissions) * 5) + 2;
        }

        if ($this->show_total_costs) {
            $height += 7;
        }

        return $height;
    }

    /**
     * Límite Y para decidir salto en última página según altura estimada del pie final.
     *
     * @param bool $with_total_block true si se imprimirá total/IVA en el pie final.
     * @return int
     */
    private function get_last_page_footer_break_limit_y(bool $with_total_block): int
    {
        $base_limit_y = $with_total_block ? 265 : 275;
        $extra_reserved_height = $this->estimate_optional_footer_extras_height()
            + $this->estimate_footer_text_height();

        return max(120, $base_limit_y - $extra_reserved_height);
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
     * Imprime el texto del pie de página debajo de los totales si está configurado.
     * Usa MultiCell para soporte de texto largo en múltiples líneas.
     * No imprime nada si footer_text está vacío.
     *
     * @return void
     */
    private function print_footer_text_block(): void
    {
        if (!$this->footer_text) {
            return;
        }
        /**
         * Pequeña separación visual respecto al bloque de totales.
         */
        if ($this->afip_ticket) {

            // $this->y -= 20;
        } else {
            $this->y += 1;
        }
        $this->x = $this->start_x;
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(200, 5, $this->footer_text, $this->b, 'L', false);
    }

    /**
     * Imprime bloque de comisiones de la venta en el footer cuando está habilitado.
     *
     * @return void
     */
    private function print_commissions_block(): void
    {
        if (! $this->show_comissions || count($this->sale->seller_commissions) < 1) {
            return;
        }

        $this->x = $this->start_x;
        $this->SetFont('Arial', '', 9);
        $this->Cell(65, 5, 'Comisiones: ', 1, 1, 'L');
        foreach ($this->sale->seller_commissions as $commission) {
            $this->x = $this->start_x;
            $this->Cell(40, 5, $commission->seller->name.' '.$commission->percentage.'%', 1, 0, 'L');
            $this->Cell(25, 5, '$'.Numbers::price($commission->debe), 1, 1, 'L');
        }
        $this->y += 2;
    }

    /**
     * Imprime total de costos en el footer cuando está habilitado.
     *
     * @return void
     */
    private function print_total_costs_block(): void
    {
        if (! $this->show_total_costs) {
            return;
        }

        $this->x = $this->start_x;
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(
            100,
            7,
            'Costos: $'.Numbers::price(SaleHelper::getTotalCostSale($this->sale)),
            $this->b,
            1,
            'L'
        );
    }

    /**
     * Agrupa bloques opcionales del footer para reutilizar en todos los escenarios.
     *
     * @return void
     */
    private function print_optional_footer_extras(): void
    {
        $this->print_commissions_block();
        $this->print_total_costs_block();
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
     * Define si el bloque de total general debe imprimirse en el pie del PDF.
     * Aplica tanto a perfiles comerciales (Total) como a perfiles fiscales (IVA/totales).
     *
     * @return bool
     */
    private function should_print_total_in_footer(): bool
    {
        return (bool) $this->show_total_in_footer;
    }

    /**
     * Normaliza booleanos que pueden venir como 0/"0"/false desde DB.
     * Evita el problema de `(bool) "0"` en PHP.
     *
     * @param mixed $value
     * @param bool $default
     * @return bool
     */
    private function normalize_boolean($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === '') {
            return false;
        }
        return (bool) $value;
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
         * Si el perfil desactivó el total en el pie, no se imprime ni siquiera en la última hoja.
         * Sin embargo, si hay texto de pie configurado, se imprime igualmente al final para no perderlo.
         */
        if (! $this->should_print_total_in_footer()) {
            if (! $this->footer_text) {
                return;
            }
            /**
             * Reserva espacio y genera salto de página si no entra el bloque final.
             */
            if ($this->y >= $this->get_last_page_footer_break_limit_y(false)) {
                $this->AddPage();
            }
            /**
             * En perfil comercial, solo se imprime el texto libre.
             */
            if (! $this->is_afip_ticket) {
                $this->y += 5;
                $this->print_optional_footer_extras();
                $this->print_footer_text_block();
                return;
            }
            /**
             * En perfil fiscal, se mantiene QR/pie fiscal aunque se oculten totales.
             */
            if ($this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {
                $this->print_optional_footer_extras();
                $this->print_footer_text_block();
                $this->ticket_info_helper->print_qr_and_arca_footer($this);
                $this->ticket_info_helper->print_fiscal_footer($this, $this->PageNo());
            }
            return;
        }

        /**
         * Reserva espacio y genera salto de página si no entra el bloque final.
         */
        if ($this->y >= $this->get_last_page_footer_break_limit_y(true)) {
            $this->AddPage();
        }

        /**
         * Con flag desactivado, el bloque de totales se imprime
         * únicamente al final del documento (última página).
         */
        if (!$this->is_afip_ticket) {

            $this->descuentos_y_recargos();

            $this->y += 5;
            $this->x = $this->start_x;
            $this->SetFont('Arial', 'B', 12);
            $this->Cell(200, 10, 'Total: '.Numbers::price($this->sale->total, true, $this->sale->moneda_id), $this->b, 1, 'R');
            $this->print_optional_footer_extras();
            /**
             * Texto de pie de página debajo del total en la última hoja.
             */
            $this->print_footer_text_block();
            return;
        }

        /**
         * En perfil fiscal se reutiliza el mismo bloque AFIP/ARCA
         * pero solo en la última página cuando el flag está desactivado.
         * El texto de pie de página va debajo del bloque fiscal.
         */
        if ($this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {

            $this->descuentos_y_recargos();
            
            $this->ticket_info_helper->print_iva_and_totals($this, $this->sale);
            $this->print_optional_footer_extras();
            $this->print_footer_text_block();
            $this->ticket_info_helper->print_qr_and_arca_footer($this);
            $this->ticket_info_helper->print_fiscal_footer($this, $this->PageNo());
        }
    }
}

