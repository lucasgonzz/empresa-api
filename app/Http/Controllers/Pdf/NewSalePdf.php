<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\Afip\TicketInfoHelper;
use App\Models\Address;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

class NewSalePdf extends fpdf
{
    /**
     * Constructor principal del PDF nuevo con perfil de columnas.
     */
    public function __construct($sale, $pdf_column_profile_id, $afip_ticket_id, $origin = null)
    {

        $user = User::find($sale->user_id);
        /**
         * Con afip_ticket_id solo perfiles fiscales; sin factura solo remito/venta.
         */
        $only_afip_ticket_profile = $afip_ticket_id ? true : false;
        /**
         * Cuando el request viene de la tienda, se prioriza el perfil marcado como default de tienda.
         */
        $prefer_default_column = ($origin === 'tienda') ? 'is_default_tienda' : null;
        $this->pdf_column_profile = PdfColumnService::get_profile_for_print(
            $user->id,
            'sale',
            $pdf_column_profile_id,
            $only_afip_ticket_profile,
            $prefer_default_column
        );
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
         * Flag para controlar visibilidad de la línea "Sub Total" en el pie del PDF.
         * Solo tiene efecto cuando hay descuentos/recargos (total_bruto != total).
         * Default true para mantener el comportamiento de perfiles legacy.
         */
        $this->show_subtotal_in_footer = $this->pdf_column_profile
            ? $this->normalize_boolean($this->pdf_column_profile->show_subtotal_in_footer, true)
            : true;
        /**
         * Modo de listado de descuentos/recargos en el pie: 'descriptivo' (default) o 'simple'.
         * 'descriptivo' imprime monto + (%/nombre) + nuevo total acumulado (comportamiento clásico).
         * 'simple' imprime solo "% Nombre", sin montos ni totales parciales.
         */
        $this->discount_display_mode = ($this->pdf_column_profile && $this->pdf_column_profile->discount_display_mode === 'simple')
            ? 'simple'
            : 'descriptivo';
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
         * Tamaño del logo en mm. Prioridad: perfil (logo_size_mm) -> global del dueño (pdf_image_size) -> 35.
         * 35 reproduce el valor histórico del header fiscal cuando no hay nada configurado.
         */
        $this->logo_size_mm = 35;
        if ($this->pdf_column_profile
            && $this->pdf_column_profile->logo_size_mm !== null
            && $this->pdf_column_profile->logo_size_mm !== ''
            && (int) $this->pdf_column_profile->logo_size_mm > 0
        ) {
            $this->logo_size_mm = (int) $this->pdf_column_profile->logo_size_mm;
        } elseif ($this->user && $this->user->pdf_image_size) {
            $this->logo_size_mm = (int) $this->user->pdf_image_size;
        }

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
        /**
         * Las observaciones ahora pertenecen al pie del documento: se imprimen
         * desde Footer() (en cada página) o desde print_totals_only_on_last_page_when_needed()
         * (solo en la última), según el flag show_totals_on_each_page del perfil.
         */
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
        /**
         * header_layout efectivo: el guardado en el perfil (prompt 437) o, si el perfil
         * no tiene uno propio, el default por código (mismo orden que dejó el grupo 100).
         * En perfiles fiscales se fuerza además la presencia de los campos obligatorios
         * (razon_social, domicilio_comercial, condicion_iva, punto_venta, cuit,
         * ingresos_brutos, inicio_actividades, numero_comprobante, fecha_emision),
         * aunque el layout guardado no los tenga.
         */
        $header_layout = ($this->pdf_column_profile && !empty($this->pdf_column_profile->header_layout))
            ? $this->pdf_column_profile->header_layout
            : PdfColumnProfile::default_header_layout($this->is_afip_ticket);

        if ($this->is_afip_ticket) {
            $header_layout = AfipPdfHelper::enforce_fiscal_required_fields($header_layout);
        }

        /**
         * Perfil fiscal: header AFIP/ARCA dedicado sin pasar por PdfHelper comercial.
         */
        if ($this->is_afip_ticket && $this->afip_ticket) {
            AfipPdfHelper::header($this, $this->afip_ticket, $this->sale, $this->user, $header_layout);
            AfipPdfHelper::table_header($this, $this->getFields());
            return;
        }

        /**
         * Perfil no fiscal (remito): header unificado con el mismo diseño que la
         * factura fiscal (recuadro de letra dinámico, título del negocio 18pt,
         * campos del emisor configurables por header_layout a la izquierda/derecha),
         * sin banda "ORIGINAL" ni datos exclusivos de AFIP. El cuadrante izquierdo
         * del bloque del cliente (receptor) también es configurable en este camino.
         */
        AfipPdfHelper::header_comercial($this, $this->sale, $this->user, $header_layout);

        /**
         * Vendedor: se preserva debajo del header unificado cuando la extensión
         * 'vendedor_en_sale_pdf' está activa y la venta tiene empleado asociado.
         * El remito ya mostraba esta línea antes de unificar el header con AFIP.
         */
        if (
            UserHelper::hasExtencion('vendedor_en_sale_pdf', $this->user)
            && $this->sale->employee
        ) {
            PdfHelper::extra_info($this, [
                'extra_info' => [
                    'Vendedor' => $this->sale->employee->name,
                ],
            ]);
        }

        /**
         * Descripción del cliente ("Observaciones"): se dibuja debajo del header
         * unificado. Se pasa $this->y como $start_y para que la caja arranque donde
         * terminó el header (client_description ahora cierra en $start_y + 20,
         * o donde termine el texto si es más largo, en vez de un 52 hardcodeado).
         */
        if (
            !is_null($this->sale->client)
            && !is_null($this->sale->client->description)
        ) {
            PdfHelper::client_description($this, $this->sale->client, $this->y);
        }

        /**
         * Cuenta corriente: Saldo anterior / Compra actual / Saldo, cuando la venta
         * tiene cliente, guarda cuenta corriente y el perfil muestra el total en el pie.
         */
        if (
            $this->show_total_in_footer
            && !is_null($this->sale->client)
            && $this->sale->save_current_acount
            && !is_null($this->sale->current_acount)
        ) {
            PdfHelper::currentAcountInfo(
                $this,
                $this->sale->current_acount,
                $this->sale->client_id,
                $this->sale->total,
                $this->y,
                $this->user
            );
        }

        /**
         * Header de columnas de la tabla de ítems, igual al que usa el path fiscal,
         * para que la tabla quede consistente entre ambos tipos de comprobante.
         */
        AfipPdfHelper::table_header($this, $this->getFields());
    }

    function get_titulo() {
        $titulo = $this->afip_ticket->afip_information->razon_social;
        if ($this->afip_ticket->afip_information->owner_name && $this->afip_ticket->afip_information->owner_name != '') {
            $titulo .= ' - '.$this->afip_ticket->afip_information->owner_name;
        } 
        return $titulo;
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
            AfipPdfHelper::footer(
                $this,
                $this->afip_ticket,
                $this->sale,
                $this->user,
                $this->afip_helper,
                $this->should_print_total_in_footer()
            );
            $this->print_optional_footer_extras();
            $this->print_footer_text_block();
        }

        /**
         * Las observaciones pertenecen al pie del documento y se repiten en cada
         * página cuando el perfil pide mostrar el pie en todas las hojas.
         * No depende de show_total_in_footer ni de footer_text: es un bloque propio.
         * El espacio ya fue reservado en get_items_page_break_limit_y(), por lo que
         * acá no se dispara ningún salto de página (no es seguro llamar AddPage()
         * desde dentro de Footer(), FPDF lo invoca recursivamente).
         */
        if ($this->should_print_totals_in_footer()) {
            $this->print_observations_block();
        }
    }

    function descuentos_y_recargos() {

        $this->total_bruto = $this->total_articles + $this->total_combos + $this->total_promocion_vinotecas + $this->total_services;

        if ($this->total_bruto != $this->sale->total) {

            /**
             * La línea "Sub Total" solo se imprime si el perfil lo pide (show_subtotal_in_footer).
             * Los descuentos y recargos se siguen imprimiendo siempre, sin importar el flag.
             */
            if ($this->show_subtotal_in_footer) {

                $this->y += 2;

                $text = 'Sub Total: '.Numbers::price($this->total_bruto, true, $this->sale->moneda_id);

                $this->SetFont('Arial', 'B', 12);
                $this->Cell(
                    200,
                    7,
                    $text,
                    $this->b,
                    1,
                    'R'
                );
            }

            $this->discounts();
            $this->surchages();
        }


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
     * Cuenta cuántos renglones va a producir build_discount_rows() sin mutar totales.
     * Se usa únicamente para estimar la altura de la caja de totales antes de dibujarla
     * (estimate_totals_box_height()), evitando así llamar a build_discount_rows() dos veces
     * (lo que aplicaría los descuentos por duplicado sobre total_articles/total_combos/etc.).
     *
     * @return int
     */
    private function count_discount_rows(): int
    {
        if (count($this->sale->discounts) < 1) {
            return 0;
        }

        $count = count($this->sale->discounts);

        if (count($this->sale->services) > 0) {
            $count++;
        }

        return $count;
    }

    /**
     * Cuenta cuántos renglones va a producir build_surchage_rows() sin mutar totales.
     * Mismo motivo que count_discount_rows(): solo para estimar altura sin efectos secundarios.
     *
     * @return int
     */
    private function count_surchage_rows(): int
    {
        if (count($this->sale->surchages) < 1 || $this->sale->aplicar_recargos_directo_a_items) {
            return 0;
        }

        $count = count($this->sale->surchages);

        if (count($this->sale->services) > 0) {
            $count++;
        }

        return $count;
    }

    /**
     * Arma los renglones de texto del bloque de descuentos para la caja de totales del
     * path no fiscal de última página, mutando los totales corrientes (total_articles,
     * total_combos, total_promocion_vinotecas, total_services y total_bruto) exactamente
     * igual que discounts(), pero sin imprimir nada: cada renglón se acumula en un array
     * para poder medir la altura de la caja antes de dibujarla (Rect) y luego escribir
     * el texto encima. El formato de cada renglón depende de discount_display_mode:
     * 'descriptivo' (monto + %/nombre + nuevo total) o 'simple' (solo %/nombre).
     *
     * @return array<int, string>
     */
    private function build_discount_rows(): array
    {
        $rows = [];

        if (count($this->sale->discounts) >= 1) {

            foreach ($this->sale->discounts as $discount) {

                $total_descuento = 0;

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

                if ($this->discount_display_mode === 'simple') {
                    /**
                     * Modo simple: solo porcentaje + nombre del descuento, sin monto ni total parcial.
                     */
                    $rows[] = $discount->pivot->percentage.'% '.$discount->name;
                } else {
                    /**
                     * Modo descriptivo (default): mismo texto histórico.
                     */
                    $rows[] = 'Menos '.Numbers::price($total_descuento, true, $this->sale->moneda_id).' ('.$discount->pivot->percentage.'% '.$discount->name.') = '.Numbers::price($this->total_bruto, true);
                }
            }

            if (count($this->sale->services) > 0) {
                $rows[] = $this->sale->discounts_in_services
                    ? 'Se aplican descuentos a los servicios'
                    : 'No se aplican descuentos a los servicios';
            }
        }

        return $rows;
    }

    /**
     * Arma los renglones de texto del bloque de recargos para la caja de totales del
     * path no fiscal de última página. Misma lógica y motivo que build_discount_rows():
     * muta los totales corrientes igual que surchages() pero devuelve un array de
     * renglones en vez de imprimir con Cell().
     *
     * @return array<int, string>
     */
    private function build_surchage_rows(): array
    {
        $rows = [];

        if (
            count($this->sale->surchages) >= 1
            && !$this->sale->aplicar_recargos_directo_a_items
        ) {

            foreach ($this->sale->surchages as $surchage) {

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

                if ($this->sale->surchages_in_services) {

                    $monto_recargo = $this->total_services * floatval($surchage->pivot->percentage) / 100;
                    $this->total_services += $monto_recargo;
                    $total_recargo += $monto_recargo;
                }

                $this->total_bruto += $total_recargo;

                if ($this->discount_display_mode === 'simple') {
                    /**
                     * Modo simple: solo porcentaje + nombre del recargo, sin monto ni total parcial.
                     */
                    $rows[] = $surchage->pivot->percentage.'% '.$surchage->name;
                } else {
                    /**
                     * Modo descriptivo (default): mismo texto histórico.
                     */
                    $rows[] = 'Mas '.Numbers::price($total_recargo, true, $this->sale->moneda_id).' ('.$surchage->pivot->percentage.'% '.$surchage->name.') = '.Numbers::price($this->total_bruto, true);
                }
            }

            if (count($this->sale->services) > 0) {
                $rows[] = $this->sale->surchages_in_services
                    ? 'Se aplican recargos a los servicios'
                    : 'No se aplican recargos a los servicios';
            }
        }

        return $rows;
    }

    /**
     * Estima la altura total que va a ocupar print_totals_box() (path no fiscal de
     * última página): cantidad de renglones (Sub Total + descuentos/recargos + Total)
     * por el alto de fila uniforme, más el padding superior/inferior de la caja y la
     * separación previa/posterior. No muta totales: usa count_discount_rows()/
     * count_surchage_rows() en vez de build_discount_rows()/build_surchage_rows() para
     * no aplicar descuentos/recargos por duplicado. Debe mantenerse en sincro con
     * print_totals_box().
     *
     * @return float
     */
    private function estimate_totals_box_height(): float
    {
        /**
         * total_bruto de referencia: mismo cálculo que print_totals_box(), pero sin
         * mutar $this->total_bruto acá (solo se usa para decidir si hay descuentos/recargos).
         */
        $total_bruto = $this->total_articles + $this->total_combos + $this->total_promocion_vinotecas + $this->total_services;
        $has_discounts_or_surchages = $total_bruto != $this->sale->total;

        /**
         * El renglón de "Total" siempre está presente.
         */
        $rows_count = 1;

        if ($has_discounts_or_surchages) {
            if ($this->show_subtotal_in_footer) {
                $rows_count++;
            }
            $rows_count += $this->count_discount_rows();
            $rows_count += $this->count_surchage_rows();
        }

        $row_height = 6;
        $padding = 3;

        /**
         * +2 de separación previa a la caja (box_y = y + 2) y +3 de separación posterior,
         * igual que se aplican en print_totals_box().
         */
        return ($rows_count * $row_height) + ($padding * 2) + 2 + 3;
    }

    /**
     * Dibuja el bloque final de totales (Sub Total + descuentos/recargos + Total) del
     * path no fiscal de última página dentro de una caja con fondo gris y borde sutil,
     * con el mismo lenguaje visual que print_observations_block() (SetFillColor(247,247,247)
     * + SetDrawColor(210,210,210) + Rect('DF')) y alto de fila uniforme para todos los
     * renglones. Reemplaza, en ese path específico, a descuentos_y_recargos() + el Cell
     * suelto de "Total" que había antes.
     *
     * Importante: el path fiscal (AFIP/ARCA) y el de "totales en cada página" (Footer())
     * NO pasan por este método: siguen usando descuentos_y_recargos()/discounts()/
     * surchages() sin cambios, para no alterar su comportamiento.
     *
     * El "Total" impreso siempre es $this->sale->total (fuente de verdad), nunca un
     * acumulado calculado a partir de descuentos/recargos.
     *
     * @return void
     */
    private function print_totals_box(): void
    {
        /**
         * total_bruto: suma de los totales corrientes antes de aplicar descuentos/recargos.
         * Se recalcula acá porque este método reemplaza a descuentos_y_recargos() en este path.
         */
        $this->total_bruto = $this->total_articles + $this->total_combos + $this->total_promocion_vinotecas + $this->total_services;

        $has_discounts_or_surchages = $this->total_bruto != $this->sale->total;

        /**
         * Renglones a imprimir dentro de la caja, en orden: Sub Total (si corresponde),
         * descuentos, recargos y Total. Cada renglón indica si va en negrita.
         */
        $rows = [];

        if ($has_discounts_or_surchages) {

            if ($this->show_subtotal_in_footer) {
                $rows[] = [
                    'text' => 'Sub Total: '.Numbers::price($this->total_bruto, true, $this->sale->moneda_id),
                    'bold' => true,
                ];
            }

            foreach ($this->build_discount_rows() as $discount_row) {
                $rows[] = ['text' => $discount_row, 'bold' => false];
            }

            foreach ($this->build_surchage_rows() as $surchage_row) {
                $rows[] = ['text' => $surchage_row, 'bold' => false];
            }
        }

        /**
         * El Total siempre se imprime, tenga o no descuentos/recargos, y siempre a partir
         * de $this->sale->total (fuente de verdad), no de un acumulado propio.
         */
        $rows[] = [
            'text' => 'Total: '.Numbers::price($this->sale->total, true, $this->sale->moneda_id),
            'bold' => true,
        ];

        /**
         * Medición previa: alto de fila uniforme (6mm) + padding superior/inferior (3mm c/u),
         * mismo criterio visual que print_observations_block().
         */
        $row_height = 6;
        $padding = 3;
        $box_width = 200;
        $box_height = (count($rows) * $row_height) + ($padding * 2);
        $box_x = $this->start_x;
        $box_y = $this->y + 2;
        $text_width = $box_width - 8;

        /**
         * Fondo gris claro + borde sutil, igual que el bloque de observaciones.
         */
        $this->SetFillColor(247, 247, 247);
        $this->SetDrawColor(210, 210, 210);
        $this->Rect($box_x, $box_y, $box_width, $box_height, 'DF');

        $this->y = $box_y + $padding;

        foreach ($rows as $row) {
            $this->x = $box_x + 4;
            $this->SetFont('Arial', $row['bold'] ? 'B' : '', $row['bold'] ? 12 : 9);
            $this->Cell($text_width, $row_height, $row['text'], $this->b, 1, 'R');
        }

        /**
         * Restaurar colores por defecto para no afectar los bloques que se impriman después.
         */
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->y = $box_y + $box_height + 3;
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

            /**
             * Para comprobantes fiscales, la altura reservada para el pie ya no
             * es un número fijo (antes 210): se calcula según la letra del
             * comprobante y la moneda (AfipPdfHelper::estimate_footer_height),
             * porque el pie AFIP varía bastante entre letra A/B (con desglose
             * de IVA), C (sin desglose) y E/exportación.
             */
            $fiscal_footer_reserved = $this->is_afip_ticket
                ? AfipPdfHelper::estimate_footer_height(
                    $this->afip_ticket,
                    $this->sale,
                    $this->should_print_total_in_footer()
                )
                : 0;

            $base_limit_y = $this->is_afip_ticket ? (285 - $fiscal_footer_reserved) : 260;

            /**
             * Reserva extra cuando el perfil agrega comisiones/costos y/o texto libre
             * en cada pie para evitar superposición con filas de ítems.
             */
            $extra_reserved_height = $this->estimate_optional_footer_extras_height()
                + $this->estimate_footer_text_height()
                + $this->estimate_observations_height();

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
        if ($this->is_afip_ticket) {
            /**
             * El pie fiscal (AfipPdfHelper::footer) tiene altura variable según
             * letra de comprobante y moneda (ver AfipPdfHelper::estimate_footer_height).
             * Se resta esa altura real de 285 (mismo límite inferior usable de
             * página que usa get_items_page_break_limit_y en el path sin "cada
             * página") para decidir si hace falta un salto de página antes de
             * imprimir el pie fiscal completo en la última hoja.
             */
            $fiscal_footer_height = AfipPdfHelper::estimate_footer_height(
                $this->afip_ticket,
                $this->sale,
                $with_total_block
            );

            $extra_reserved_height = $this->estimate_optional_footer_extras_height()
                + $this->estimate_footer_text_height()
                + $this->estimate_observations_height();

            return max(120, 285 - $fiscal_footer_height - $extra_reserved_height);
        }

        $base_limit_y = $with_total_block ? 265 : 275;

        /**
         * Reserva adicional para la caja de totales (Sub Total + descuentos/recargos + Total)
         * del path no fiscal de última página: la caja con borde/fondo (print_totals_box())
         * puede ser más alta que el bloque suelto que había antes, así que se reserva la
         * altura real estimada en vez de confiar solo en el offset fijo de $base_limit_y.
         * Solo aplica cuando efectivamente se va a dibujar esa caja ($with_total_block true
         * y perfil no fiscal); el path fiscal sigue con el mismo cálculo de siempre.
         */
        $totals_box_extra = ($with_total_block && !$this->is_afip_ticket)
            ? $this->estimate_totals_box_height()
            : 0;

        $extra_reserved_height = $this->estimate_optional_footer_extras_height()
            + $this->estimate_footer_text_height()
            + $this->estimate_observations_height()
            + $totals_box_extra;

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
            'afip_ticket' => $this->afip_ticket,
            'afip_helper' => $this->afip_helper,
            'numbers' => Numbers::class,
            'general_helper' => GeneralHelper::class,
        ]);
    }

    /**
     * Indica si la venta tiene observaciones cargadas.
     *
     * @return bool
     */
    private function has_observations(): bool
    {
        return !is_null($this->sale->observations) && trim((string) $this->sale->observations) !== '';
    }

    /**
     * Estima la altura total que va a ocupar el bloque de observaciones
     * (separación previa + título + líneas reales del texto + paddings del recuadro
     * + separación posterior). Debe mantenerse en sincro con print_observations_block().
     * Devuelve 0 si la venta no tiene observaciones.
     *
     * @return float
     */
    private function estimate_observations_height(): float
    {
        if (!$this->has_observations()) {
            return 0;
        }

        $this->SetFont('Arial', '', 9);
        $lines = $this->NbLines(200 - 8, (string) $this->sale->observations);

        /**
         * 3 (separación previa) + 6 (título) + líneas*4.5 (cuerpo) + 4 (padding inferior del recuadro) + 3 (separación posterior).
         */
        return 3 + 6 + (max(1, $lines) * 4.5) + 4 + 3;
    }

    /**
     * Imprime el bloque de observaciones con un recuadro (fondo + borde) para que
     * se distinga visualmente del resto del comprobante. El espacio ya fue reservado
     * por quien llama a este método (ver estimate_observations_height()), por lo que
     * acá no se evalúa ni se dispara ningún salto de página.
     *
     * @return void
     */
    private function print_observations_block(): void
    {
        if (!$this->has_observations()) {
            return;
        }

        $box_x = $this->start_x;
        $box_width = 200;
        $text_width = $box_width - 8;

        $this->SetFont('Arial', '', 9);
        $lines = $this->NbLines($text_width, (string) $this->sale->observations);
        $body_height = max(1, $lines) * 4.5;
        $box_height = 6 + $body_height + 4;

        $box_y = $this->y + 3;

        /**
         * Fondo gris claro + borde sutil para que el bloque se note como una sección aparte.
         */
        $this->SetFillColor(247, 247, 247);
        $this->SetDrawColor(210, 210, 210);
        $this->Rect($box_x, $box_y, $box_width, $box_height, 'DF');

        $this->x = $box_x + 4;
        $this->y = $box_y + 3;
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(110, 110, 110);
        $this->Cell($text_width, 4, 'OBSERVACIONES', 0, 1, 'L');

        $this->x = $box_x + 4;
        $this->y += 1;
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(40, 40, 40);
        $this->MultiCell($text_width, 4.5, $this->sale->observations, 0, 'L', false);

        /**
         * Restaurar colores por defecto para no afectar los bloques que se impriman después.
         */
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->y = $box_y + $box_height + 3;
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

        $total = (float)$this->sale->total;

        $this->x = $this->start_x;
        $this->SetFont('Arial', '', 9);
        $this->Cell(65, 5, 'Comisiones: ', 1, 1, 'L');
        foreach ($this->sale->seller_commissions as $commission) {
            $this->x = $this->start_x;
            $this->Cell(40, 5, $commission->seller->name.' '.$commission->percentage.'%', 1, 0, 'L');
            $this->Cell(25, 5, '$'.Numbers::price($commission->debe), 1, 1, 'L');
            $total -= (float)$commission->debe;
        }
        $this->y += 2;

        $this->x = $this->start_x;
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(25, 5, 'Total menos comisiones: '.Numbers::price($total, true), 0, 1, 'L');

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
         * Las observaciones son independientes de este flag: se evalúan e imprimen siempre
         * que la venta las tenga cargadas, sin importar si el total o el texto libre están ocultos.
         */
        if (! $this->should_print_total_in_footer()) {
            /**
             * Reserva espacio (texto de pie + observaciones) y genera salto de página si no entra.
             */
            if ($this->y >= $this->get_last_page_footer_break_limit_y(false)) {
                $this->AddPage();
            }

            if ($this->footer_text) {
                /**
                 * En perfil comercial, solo se imprime el texto libre.
                 */
                if (! $this->is_afip_ticket) {
                    $this->y += 5;
                    $this->print_optional_footer_extras();
                    $this->print_footer_text_block();
                } elseif ($this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {
                    /**
                     * En perfil fiscal, se mantiene QR/pie fiscal aunque se oculten totales.
                     */
                    AfipPdfHelper::footer(
                        $this,
                        $this->afip_ticket,
                        $this->sale,
                        $this->user,
                        $this->afip_helper,
                        false
                    );
                    $this->print_optional_footer_extras();
                    $this->print_footer_text_block();
                }
            }

            $this->print_observations_block();
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

            /**
             * Bloque de totales del remito no fiscal: caja gris con Sub Total (si
             * corresponde) + descuentos/recargos (según discount_display_mode) + Total.
             * Reemplaza a descuentos_y_recargos() + el Cell suelto de "Total" que había antes.
             */
            $this->print_totals_box();

            $this->print_optional_footer_extras();
            /**
             * Texto de pie de página debajo del total en la última hoja.
             */
            $this->print_footer_text_block();
            $this->print_observations_block();
            return;
        }

        /**
         * En perfil fiscal se reutiliza el mismo bloque AFIP/ARCA
         * pero solo en la última página cuando el flag está desactivado.
         * El texto de pie de página va debajo del bloque fiscal.
         */
        if ($this->ticket_info_helper && $this->ticket_info_helper->has_afip_context()) {

            $this->descuentos_y_recargos();

            AfipPdfHelper::footer(
                $this,
                $this->afip_ticket,
                $this->sale,
                $this->user,
                $this->afip_helper,
                true
            );
            $this->print_optional_footer_extras();
            $this->print_footer_text_block();
        }

        $this->print_observations_block();
    }
}

