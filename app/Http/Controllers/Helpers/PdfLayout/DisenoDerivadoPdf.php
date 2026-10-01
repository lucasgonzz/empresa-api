<?php

namespace App\Http\Controllers\Helpers\PdfLayout;

use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\AfipTicket;
use App\Models\Budget;
use App\Models\Order;
use App\Models\PdfColumnProfile;
use App\Models\Sale;

/**
 * El diseño con cajas EQUIVALENTE a lo que un diseño de PDF imprime hoy con sus flags, y el
 * comprobante con el que el diseñador ofrece "Ver un PDF de prueba" (misión
 * diseno-pdf-configurable, 1/10/2026, §5 y §2.3 del plan). También las reglas del diseñador que
 * la API vuelve a aplicar al guardar: qué perfil es fiscal (es_fiscal()) y en qué hoja no entra
 * una factura de ARCA (factura_en_hoja_chica()).
 *
 * POR QUÉ EXISTE. Un perfil con `page_layout` NULL imprime "el PDF de siempre" (NewSalePdf /
 * ProfileDocumentPdf, gobernados por los flags show_*). Cuando el dueño lo abre en el diseñador
 * no puede encontrarse con una hoja vacía ni con un diseño inventado: tiene que ver, en cajas, lo
 * que hoy le sale impreso, para retocar desde ahí. Este helper arma ese diseño a partir de los
 * flags y NO lo guarda: recién cuando el dueño guarda en el diseñador el perfil pasa a dibujarse
 * con cajas (decisión 1 del plan). El endpoint `page-layout-catalog` lo devuelve como
 * `diseno_derivado`.
 *
 * Cada regla de abajo nombra el lugar del dibujante de siempre que imita. Si alguien cambia qué
 * imprime un flag en NewSalePdf / ProfileDocumentPdf, este derivado tiene que acompañar: si no,
 * el diseñador le muestra al dueño algo distinto de lo que le sale impreso. Donde el dibujante de
 * siempre tiene una regla que parece casual (los extras del pie colgados del texto de pie, el pie
 * fiscal en cada hoja sin los descuentos), se copia igual: lo que importa es que el derivado diga
 * lo que hoy sale.
 *
 * 🔴 Los ids de las cajas son FIJOS (no se generan): los tests los nombran y el SPA puede
 * reconocer las cajas del derivado. Los estilos de los campos van en null (= los del catálogo)
 * salvo donde el plan pide otro rótulo o tamaño.
 */
class DisenoDerivadoPdf
{
    /** Ids fijos de las cajas del derivado. */
    const CAJA_CLIENTE = 'caja_cliente';
    const CAJA_CUENTA_CORRIENTE = 'caja_cuenta_corriente';
    const CAJA_VENDEDOR = 'caja_vendedor';
    const CAJA_OBSERVACIONES_CLIENTE = 'caja_observaciones_cliente';
    const CAJA_TOTALES = 'caja_totales';
    const CAJA_COMISIONES = 'caja_comisiones';
    const CAJA_COSTOS = 'caja_costos';
    const CAJA_TEXTO_DE_PIE = 'caja_texto_de_pie';
    const CAJA_OBSERVACIONES = 'caja_observaciones';

    /** Id del texto libre que ocupa el lugar del "Pie de página" (footer_text) del perfil. */
    const ID_TEXTO_DE_PIE = 'texto_de_pie';

    /**
     * Alto mínimo de la hoja (mm) para una factura de ARCA diseñada con cajas: el cuadro de ARCA
     * del pie (importes, QR y CAE, unos 100 mm) no entra completo debajo del encabezado y de la
     * zona de arriba en una A5 (210 mm); en Carta (279), A4 (297) y Oficio (356) sí.
     */
    const ALTO_MINIMO_DE_HOJA_PARA_FACTURA = 250;

    /** Por qué no (el texto que ya muestra el diseñador, más dónde se cambia la hoja). */
    const MENSAJE_FACTURA_EN_HOJA_CHICA = 'En A5 no entra completo el cuadro de ARCA (importes, QR y CAE): para facturas usá A4, Carta u Oficio. Cambiá la hoja en Diseñar PDF.';

    /**
     * Keys del cuadrante izquierdo del cliente del remito (`header_layout.receptor.izquierda`, las
     * que conoce AfipPdfHelper::$receptor_field_labels) => key del catálogo de la VENTA. Los
     * rótulos coinciden ("Cliente:", "Teléfono:", "Vendedor:", "Empleado:"...), así que el campo
     * va con la etiqueta del catálogo. Una key que no está acá el remito de siempre la saltea, y el
     * derivado también.
     */
    const RECEPTOR_A_CAMPO_DE_VENTA = [
        'cliente_nombre' => 'cliente_nombre',
        'cliente_telefono' => 'cliente_telefono',
        'cliente_localidad' => 'cliente_localidad',
        'cliente_direccion' => 'cliente_direccion',
        'cliente_cuit' => 'cliente_cuit',
        'cliente_condicion_iva' => 'cliente_condicion_iva',
        'vendedor' => 'venta_vendedor',
        'empleado' => 'venta_empleado',
    ];

    /**
     * Lo mismo para el PRESUPUESTO. BudgetPdfDocument::header_document() pone al empleado que lo
     * cargó como `seller` Y como `employee`, así que `vendedor` y `empleado` imprimen a la misma
     * persona: los dos van a `presupuesto_vendedor`, una sola vez.
     */
    const RECEPTOR_A_CAMPO_DE_PRESUPUESTO = [
        'cliente_nombre' => 'cliente_nombre',
        'cliente_telefono' => 'cliente_telefono',
        'cliente_localidad' => 'cliente_localidad',
        'cliente_direccion' => 'cliente_direccion',
        'cliente_cuit' => 'cliente_cuit',
        'cliente_condicion_iva' => 'cliente_condicion_iva',
        'vendedor' => 'presupuesto_vendedor',
        'empleado' => 'presupuesto_vendedor',
    ];

    /**
     * Rótulos del presupuesto que no son el del catálogo: `presupuesto_vendedor` se rotula
     * "Vendedor", pero si el encabezado lo pedía como `empleado` el PDF de siempre decía
     * "Empleado: <nombre>". Se conserva ese rótulo para que el renglón diga lo mismo.
     */
    const ETIQUETAS_DEL_RECEPTOR_DE_PRESUPUESTO = [
        'empleado' => 'Empleado',
    ];

    /**
     * Lo mismo para el PEDIDO ONLINE. OrderPdfDocument::header_client() arma al comprador con la
     * forma de un cliente (nombre, teléfono, dirección, CUIT, localidad) y deja en null la
     * condición de IVA, el vendedor y el empleado: el PDF de siempre no los imprime nunca, así que
     * se descartan.
     */
    const RECEPTOR_A_CAMPO_DE_PEDIDO = [
        'cliente_nombre' => 'comprador_nombre',
        'cliente_telefono' => 'comprador_telefono',
        'cliente_localidad' => 'comprador_localidad',
        'cliente_direccion' => 'comprador_direccion',
        'cliente_cuit' => 'comprador_cuit',
    ];

    /**
     * El diseño derivado de un perfil, ya pasado por DisenoDePaginaPdf::normalizar() y
     * asegurar_fijos().
     *
     * @param string                            $model_name 'sale' | 'budget' | 'order'.
     * @param \App\Models\PdfColumnProfile|null $profile    null = un perfil nuevo (los defaults de hoy).
     * @param bool                              $es_fiscal  se imprime como factura de ARCA (solo cuenta en 'sale').
     * @param \App\Models\User|null             $owner      dueño del perfil: decide el vendedor de la cuenta
     *                                                      corriente y la extensión vendedor_en_sale_pdf.
     * @return array|null null si el modelo no se diseña con cajas (el catálogo de artículos).
     */
    public static function para($model_name, $profile, $es_fiscal, $owner)
    {
        if (! CatalogoDeCamposPdf::soporta($model_name)) {
            return null;
        }

        $es_fiscal = self::es_fiscal($model_name, $es_fiscal);

        if ($model_name === 'sale') {
            $zonas = $es_fiscal ? self::venta_fiscal($profile) : self::venta_remito($profile, $owner);
        } elseif ($model_name === 'budget') {
            $zonas = self::presupuesto($profile);
        } else {
            $zonas = self::pedido($profile);
        }

        $diseno = DisenoDePaginaPdf::normalizar([
            'version' => DisenoDePaginaPdf::VERSION,
            'superior' => $zonas['superior'],
            'pie' => $zonas['pie'],
        ]);

        return DisenoDePaginaPdf::asegurar_fijos($diseno, $es_fiscal);
    }

    /**
     * ¿El diseño se arma como factura de ARCA? Solo una venta puede serlo: un presupuesto o un
     * pedido con is_afip_ticket prendido (el formulario no lo ofrece, pero la columna es de todos
     * los modelos) se diseña sin los bloques fijos.
     *
     * Es la regla que usan el catálogo del diseñador y el guardado (asegurar_fijos()): tiene que
     * ser una sola.
     *
     * @param string $model_name
     * @param mixed  $is_afip_ticket
     * @return bool
     */
    public static function es_fiscal($model_name, $is_afip_ticket)
    {
        return $model_name === 'sale' && (bool) $is_afip_ticket;
    }

    /**
     * ¿El perfil queda como una factura de ARCA diseñada con cajas en una hoja donde no entra el
     * cuadro de ARCA (más baja que ALTO_MINIMO_DE_HOJA_PARA_FACTURA)?
     *
     * Es el lado API de la regla que el diseñador ya aplica (no deja elegir A5 en una factura):
     * igual se llega tildando "Es factura de ARCA" en el formulario de un diseño armado en A5, o
     * mandándolo por API. Sin diseño no aplica: el PDF de siempre ignora la hoja.
     *
     * @param string     $model_name
     * @param mixed      $is_afip_ticket  el que queda guardado.
     * @param array|null $page_layout     el que queda guardado, ya normalizado.
     * @param mixed      $paper_height_mm el que queda guardado; null = A4.
     * @return bool
     */
    public static function factura_en_hoja_chica($model_name, $is_afip_ticket, $page_layout, $paper_height_mm)
    {
        if (! self::es_fiscal($model_name, $is_afip_ticket)) {
            return false;
        }

        /** La regla de DisenoDePaginaPdf::tiene_diseno(), sobre el arreglo que queda guardado. */
        if (! is_array($page_layout) || (! isset($page_layout['superior']) && ! isset($page_layout['pie']))) {
            return false;
        }

        $alto = ($paper_height_mm === null || $paper_height_mm === '')
            ? DisenoDePaginaPdf::ALTO_DE_HOJA_POR_DEFECTO
            : (int) $paper_height_mm;

        return $alto < self::ALTO_MINIMO_DE_HOJA_PARA_FACTURA;
    }

    /**
     * El comprobante con el que el diseñador ofrece "Ver un PDF de prueba": el último (id más
     * alto) del dueño, o null si no tiene ninguno.
     *
     * - Venta no fiscal: la última venta real. Las contenedoras de una consolidación de
     *   facturación no son ventas (Sale::soloVentasReales()): su remito no le muestra nada útil.
     * - Venta fiscal: la última venta con una factura que tenga CAE, y esa factura. El PDF fiscal
     *   necesita el afip_ticket_id y, sin CAE, no hay QR ni comprobante que mostrar. Las notas de
     *   crédito no cuentan: su ticket cuelga de sale_nota_credito_id, no de sale_id.
     * - Presupuesto / pedido online: el último.
     *
     * Se va del lado de afip_tickets (índice afip_tickets_sale_id_idx, recorrido de atrás para
     * adelante) y no de sales: un comercio que factura poco tiene miles de remitos encima de su
     * última factura, y recorrerlos uno por uno para preguntar si tienen ticket es lo lento.
     *
     * @param string $model_name 'sale' | 'budget' | 'order'.
     * @param bool   $es_fiscal
     * @param int    $owner_id
     * @return array{id:int, afip_ticket_id:int|null}|null
     */
    public static function comprobante_de_prueba($model_name, $es_fiscal, $owner_id)
    {
        if (self::es_fiscal($model_name, $es_fiscal)) {
            $ticket = AfipTicket::query()
                ->whereNotNull('cae')
                ->where('cae', '!=', '')
                ->whereHas('sale', function ($query) use ($owner_id) {
                    $query->where('sales.user_id', $owner_id);
                })
                ->orderBy('sale_id', 'desc')
                ->orderBy('id', 'desc')
                ->first(['id', 'sale_id']);

            return is_null($ticket)
                ? null
                : ['id' => (int) $ticket->sale_id, 'afip_ticket_id' => (int) $ticket->id];
        }

        if ($model_name === 'sale') {
            $id = Sale::where('user_id', $owner_id)->soloVentasReales()->orderBy('id', 'desc')->value('id');
        } elseif ($model_name === 'budget') {
            $id = Budget::where('user_id', $owner_id)->orderBy('id', 'desc')->value('id');
        } elseif ($model_name === 'order') {
            $id = Order::where('user_id', $owner_id)->orderBy('id', 'desc')->value('id');
        } else {
            return null;
        }

        return is_null($id) ? null : ['id' => (int) $id, 'afip_ticket_id' => null];
    }

    // ── Los cuatro derivados ──────────────────────────────────────────────────────────────────

    /**
     * Venta, remito (no fiscal): NewSalePdf::Header() por el camino de header_comercial() y el pie
     * de print_totals_only_on_last_page_when_needed() / Footer().
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @param \App\Models\User|null             $owner
     * @return array{superior: array, pie: array}
     */
    private static function venta_remito($profile, $owner)
    {
        $flags = self::flags($profile);

        $superior = [];

        /**
         * Cuadrante izquierdo del bloque del cliente (AfipPdfHelper::print_receptor_block_comercial()):
         * los campos de header_layout.receptor.izquierda en su orden, o el default por código.
         */
        $campos_del_cliente = self::campos_del_receptor(
            self::receptor_izquierda($profile, PdfColumnProfile::default_header_layout(false)),
            self::RECEPTOR_A_CAMPO_DE_VENTA,
            []
        );
        $superior[] = self::caja(self::CAJA_CLIENTE, 6, 'borde', $campos_del_cliente);

        /**
         * Cuadrante derecho: la cuenta corriente. NewSalePdf::Header() solo la arma con
         * show_total_in_footer prendido. El "Vendedor" de ese cuadrante es el de
         * PdfHelper::buildCurrentAcountData() (el empleado que cargó la venta, si no el dueño), y
         * solo sale si el dueño tiene mostrar_vendedor_en_venta_pdf.
         */
        if ($flags['show_total_in_footer']) {
            $campos_de_cuenta_corriente = [
                self::campo('cc_saldo_anterior'),
                self::campo('cc_compra_actual'),
                self::campo('cc_saldo'),
            ];

            if (! is_null($owner) && (bool) $owner->mostrar_vendedor_en_venta_pdf) {
                $campos_de_cuenta_corriente[] = self::campo('venta_atendido_por', 'Vendedor');
            }

            $superior[] = self::caja(self::CAJA_CUENTA_CORRIENTE, 6, 'borde', $campos_de_cuenta_corriente);
        }

        /**
         * La línea "Vendedor: <empleado>" que NewSalePdf::Header() imprime debajo del encabezado con
         * la extensión vendedor_en_sale_pdf. Si el empleado ya está en la caja del cliente no se
         * repite: el diseño admite cada campo una sola vez (normalizar() descartaría el segundo y
         * esta caja quedaría vacía).
         *
         * El chequeo de $owner null NO es decorativo: UserHelper::hasExtencion() con null cae al
         * usuario de la sesión, que puede no ser el dueño del perfil.
         */
        if (
            ! is_null($owner)
            && UserHelper::hasExtencion('vendedor_en_sale_pdf', $owner)
            && ! self::tiene_campo($campos_del_cliente, 'venta_empleado')
        ) {
            $superior[] = self::caja(self::CAJA_VENDEDOR, 12, 'ninguno', [self::campo('venta_empleado', 'Vendedor')]);
        }

        /** PdfHelper::client_description(): "Observaciones: ..." en negrita 10. */
        if ($flags['show_client_description']) {
            $superior[] = self::caja_de_observaciones_del_cliente();
        }

        $pie = [];

        /**
         * NewSalePdf::print_totals_box(): la caja gris de Sub Total, descuentos, recargos, canje,
         * ajuste del total, Total y puntos. La misma caja sale en cada hoja con
         * show_totals_on_each_page (render_totals_box_snapshot()).
         */
        if ($flags['show_total_in_footer']) {
            $campos_de_totales = [];

            if ($flags['show_subtotal_in_footer']) {
                $campos_de_totales[] = self::campo('tot_subtotal');
            }

            $campos_de_totales[] = self::campo('tot_descuentos');
            $campos_de_totales[] = self::campo('tot_recargos');
            $campos_de_totales[] = self::campo('tot_canje_de_puntos');
            $campos_de_totales[] = self::campo('tot_ajuste_del_total');
            $campos_de_totales[] = self::campo('tot_total');

            /**
             * Los puntos van DERECHOS en la caja del remito: print_totals_box() los imprime con la
             * letra normal. El catálogo los trae en cursiva porque así salen en la factura
             * (renglones_de_puntos()), así que acá se pisa ese estilo para que el primer guardado
             * se vea igual que el PDF de siempre.
             */
            $puntos = self::campo('tot_puntos');
            $puntos['cursiva'] = false;
            $campos_de_totales[] = $puntos;

            $pie[] = self::caja(self::CAJA_TOTALES, 12, 'gris', $campos_de_totales);
        }

        $pie = array_merge($pie, self::extras_del_pie_de_venta($flags));

        $pie[] = self::caja_de_observaciones('venta_observaciones', 'OBSERVACIONES');

        return ['superior' => $superior, 'pie' => $pie];
    }

    /**
     * Venta, factura de ARCA: NewSalePdf::Header() por AfipPdfHelper::header() (receptor fijo) y
     * el pie fiscal (observaciones arriba de todo, después los renglones de descuentos y el bloque
     * de ARCA).
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @return array{superior: array, pie: array}
     */
    private static function venta_fiscal($profile)
    {
        $flags = self::flags($profile);

        $superior = [self::fijo(CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR, null)];

        /** Las observaciones del pie fiscal van ARRIBA de todo el bloque de ARCA. */
        $pie = [self::caja_de_observaciones('venta_observaciones', 'OBSERVACIONES')];

        /**
         * NewSalePdf::descuentos_y_recargos(): Sub Total, descuentos, recargos, canje y puntos,
         * sueltos (sin caja) arriba del bloque de ARCA. No llevan Total ni ajuste: el total lo
         * pone el cuadro de importes.
         *
         * 🔴 Con show_totals_on_each_page el pie fiscal de Footer() NO llama a
         * descuentos_y_recargos(): imprime solo el bloque de ARCA. Por eso, en ese caso, el
         * derivado no lleva esta caja.
         */
        if ($flags['show_total_in_footer'] && ! $flags['show_totals_on_each_page']) {
            $campos_de_totales = [];

            if ($flags['show_subtotal_in_footer']) {
                $campos_de_totales[] = self::campo('tot_subtotal');
            }

            /**
             * Los descuentos de la factura salen en NEGRITA: NewSalePdf::discounts() los escribe con
             * SetFont('Arial', 'B', 9) (los recargos, surchages(), con la letra normal). El catálogo
             * los trae sin negrita porque así salen en la caja del remito.
             */
            $campos_de_totales[] = self::campo('tot_descuentos', null, null, true);
            $campos_de_totales[] = self::campo('tot_recargos');
            $campos_de_totales[] = self::campo('tot_canje_de_puntos');
            $campos_de_totales[] = self::campo('tot_puntos');

            $pie[] = self::caja(self::CAJA_TOTALES, 12, 'ninguno', $campos_de_totales);
        }

        /** AfipPdfHelper::footer($pdf, ..., $show_importes): el cuadro de importes sigue a show_total_in_footer. */
        $pie[] = self::fijo(CatalogoDeCamposPdf::FIJO_AFIP_PIE, $flags['show_total_in_footer']);

        $pie = array_merge($pie, self::extras_del_pie_de_venta($flags));

        return ['superior' => $superior, 'pie' => $pie];
    }

    /**
     * Presupuesto: ProfileDocumentPdf::Header() (encabezado del remito con el vendedor) y
     * print_closing_blocks() (totales, texto de pie y observaciones).
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @return array{superior: array, pie: array}
     */
    private static function presupuesto($profile)
    {
        $flags = self::flags($profile);

        $superior = [
            self::caja(self::CAJA_CLIENTE, 6, 'borde', self::campos_del_receptor(
                self::receptor_izquierda($profile, PdfDocumentSetupHelper::default_header_layout_for('budget')),
                self::RECEPTOR_A_CAMPO_DE_PRESUPUESTO,
                self::ETIQUETAS_DEL_RECEPTOR_DE_PRESUPUESTO
            )),
        ];

        if ($flags['show_client_description']) {
            $superior[] = self::caja_de_observaciones_del_cliente();
        }

        $pie = [];

        /** BudgetPdfDocument::totals_rows(): con el total apagado ("sin precios") no sale ningún renglón. */
        if ($flags['show_total_in_footer']) {
            $campos_de_totales = [];

            if ($flags['show_subtotal_in_footer']) {
                $campos_de_totales[] = self::campo('tot_subtotal');
            }

            $campos_de_totales[] = self::campo('tot_descuentos');
            $campos_de_totales[] = self::campo('tot_recargos');
            /**
             * El descuento o recargo por método de pago de un presupuesto de contado va donde lo
             * imprime totals_rows(): después de los recargos y antes del ajuste del total.
             */
            $campos_de_totales[] = self::campo('tot_ajuste_metodo_de_pago');
            $campos_de_totales[] = self::campo('tot_ajuste_del_total');
            $campos_de_totales[] = self::campo('tot_total');

            $pie[] = self::caja(self::CAJA_TOTALES, 12, 'gris', $campos_de_totales);
        }

        if ($flags['footer_text'] !== '') {
            $pie[] = self::caja_de_texto_de_pie($flags['footer_text']);
        }

        $pie[] = self::caja_de_observaciones('presupuesto_observaciones', 'OBSERVACIONES');

        return ['superior' => $superior, 'pie' => $pie];
    }

    /**
     * Pedido online: ProfileDocumentPdf con OrderPdfDocument. Sin observaciones del cliente (el
     * comprador no tiene: header_client() las deja en null) y con las notas del pedido al final.
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @return array{superior: array, pie: array}
     */
    private static function pedido($profile)
    {
        $flags = self::flags($profile);

        $superior = [
            self::caja(self::CAJA_CLIENTE, 6, 'borde', self::campos_del_receptor(
                self::receptor_izquierda($profile, PdfDocumentSetupHelper::default_header_layout_for('order')),
                self::RECEPTOR_A_CAMPO_DE_PEDIDO,
                []
            )),
        ];

        $pie = [];

        /**
         * OrderPdfDocument::totals_rows(): show_subtotal_in_footer no aplica al pedido (la línea de
         * importe es la única que existe; se rotula "Subtotal" o "Total" según tenga extras), por
         * eso tot_subtotal va siempre y la fuente decide cuál de los dos imprime.
         */
        if ($flags['show_total_in_footer']) {
            $pie[] = self::caja(self::CAJA_TOTALES, 12, 'gris', [
                self::campo('tot_subtotal'),
                self::campo('tot_envio'),
                self::campo('tot_cupon'),
                self::campo('tot_ajuste_medio_de_pago'),
                self::campo('tot_total'),
            ]);
        }

        if ($flags['footer_text'] !== '') {
            $pie[] = self::caja_de_texto_de_pie($flags['footer_text']);
        }

        $pie[] = self::caja_de_observaciones('pedido_notas', 'NOTAS DEL PEDIDO');

        return ['superior' => $superior, 'pie' => $pie];
    }

    // ── Piezas compartidas ────────────────────────────────────────────────────────────────────

    /**
     * Comisiones, costos y texto de pie de la venta (remito y factura), en ese orden:
     * NewSalePdf::print_optional_footer_extras() + print_footer_text_block().
     *
     * Los extras (comisiones y costos) el dibujante de siempre los imprime con el pie en cada
     * hoja, con el total en el pie, o —con el total apagado— SOLO si hay texto de pie
     * (print_totals_only_on_last_page_when_needed() los cuelga de esa rama). Se copia esa
     * condición tal cual: un remito con "Mostrar comisiones" pero sin total ni texto de pie hoy
     * no imprime las comisiones, y el derivado no puede mostrarlas.
     *
     * @param array $flags
     * @return array
     */
    private static function extras_del_pie_de_venta($flags)
    {
        $tiene_texto_de_pie = $flags['footer_text'] !== '';

        $imprime_extras = $flags['show_totals_on_each_page']
            || $flags['show_total_in_footer']
            || $tiene_texto_de_pie;

        $extras = [];

        if ($imprime_extras && $flags['show_comissions']) {
            $extras[] = self::caja(self::CAJA_COMISIONES, 12, 'ninguno', [
                self::campo('tot_comisiones'),
                self::campo('tot_total_menos_comisiones'),
            ]);
        }

        if ($imprime_extras && $flags['show_total_costs']) {
            $extras[] = self::caja(self::CAJA_COSTOS, 12, 'ninguno', [self::campo('tot_costos')]);
        }

        if ($tiene_texto_de_pie) {
            $extras[] = self::caja_de_texto_de_pie($flags['footer_text']);
        }

        return $extras;
    }

    /**
     * Los flags del perfil con el MISMO default que el dibujante de siempre cuando vienen null
     * (perfil anterior a la columna) o cuando no hay perfil (uno nuevo): son los de
     * NewSalePdf::normalize_boolean() y ProfileDocumentPdf::flag().
     *
     * El texto de pie en blanco cuenta como "sin texto": imprimirlo daría un renglón vacío.
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @return array<string, mixed>
     */
    private static function flags($profile)
    {
        $texto_de_pie = is_null($profile) ? '' : (string) $profile->footer_text;

        return [
            'show_total_in_footer' => self::flag($profile, 'show_total_in_footer', true),
            'show_subtotal_in_footer' => self::flag($profile, 'show_subtotal_in_footer', true),
            'show_client_description' => self::flag($profile, 'show_client_description', true),
            'show_comissions' => self::flag($profile, 'show_comissions', false),
            'show_total_costs' => self::flag($profile, 'show_total_costs', false),
            'show_totals_on_each_page' => self::flag($profile, 'show_totals_on_each_page', false),
            'footer_text' => trim($texto_de_pie) !== '' ? $texto_de_pie : '',
        ];
    }

    /**
     * Un flag booleano del perfil; null (o sin perfil) toma el default.
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @param string                            $atributo
     * @param bool                              $por_defecto
     * @return bool
     */
    private static function flag($profile, $atributo, $por_defecto)
    {
        if (is_null($profile) || is_null($profile->{$atributo})) {
            return $por_defecto;
        }

        return (bool) $profile->{$atributo};
    }

    /**
     * header_layout.receptor.izquierda efectivo, con la regla del dibujante de siempre
     * (NewSalePdf::Header() y el constructor de ProfileDocumentPdf): el header_layout del perfil
     * si tiene uno —aunque no traiga receptor: entonces el cuadrante sale vacío, igual que hoy— o
     * el default por código si no.
     *
     * @param \App\Models\PdfColumnProfile|null $profile
     * @param array                             $por_defecto header_layout por defecto del modelo.
     * @return array<int, mixed>
     */
    private static function receptor_izquierda($profile, $por_defecto)
    {
        $layout = (! is_null($profile) && ! empty($profile->header_layout))
            ? $profile->header_layout
            : $por_defecto;

        if (! is_array($layout) || ! isset($layout['receptor']['izquierda']) || ! is_array($layout['receptor']['izquierda'])) {
            return [];
        }

        return array_values($layout['receptor']['izquierda']);
    }

    /**
     * Los campos de la caja del cliente a partir de las keys del receptor, en su orden. Una key que
     * el dibujante de siempre no conoce se descarta (AfipPdfHelper la saltea), y dos keys que van
     * al mismo campo del catálogo (el vendedor y el empleado del presupuesto) quedan en uno: el
     * primero, con su rótulo.
     *
     * @param array $keys      header_layout.receptor.izquierda.
     * @param array $mapa      key del receptor => key del catálogo.
     * @param array $etiquetas key del receptor => rótulo, cuando no es el del catálogo.
     * @return array
     */
    private static function campos_del_receptor($keys, $mapa, $etiquetas)
    {
        $campos = [];
        $usados = [];

        foreach ($keys as $key) {
            if (! is_string($key) || ! isset($mapa[$key]) || in_array($mapa[$key], $usados, true)) {
                continue;
            }

            $usados[] = $mapa[$key];
            $campos[] = self::campo($mapa[$key], isset($etiquetas[$key]) ? $etiquetas[$key] : null);
        }

        return $campos;
    }

    /**
     * ¿La lista de campos ya tiene esa key?
     *
     * @param array  $campos
     * @param string $key
     * @return bool
     */
    private static function tiene_campo($campos, $key)
    {
        foreach ($campos as $campo) {
            if ($campo['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Caja de las observaciones del cliente (clients.description): PdfHelper::client_description()
     * las imprime "Observaciones: ..." en negrita 10, por eso el campo lleva ese estilo y no el del
     * catálogo.
     *
     * @return array
     */
    private static function caja_de_observaciones_del_cliente()
    {
        return self::caja(self::CAJA_OBSERVACIONES_CLIENTE, 12, 'borde', [
            self::campo('cliente_observaciones', null, 10, true),
        ]);
    }

    /**
     * Recuadro gris de observaciones del comprobante (NewSalePdf::print_observations_block() /
     * ProfileDocumentPdf::print_observations_block()): el título en mayúsculas y el texto sin
     * rótulo.
     *
     * @param string $key    'venta_observaciones' | 'presupuesto_observaciones' | 'pedido_notas'.
     * @param string $titulo 'OBSERVACIONES' | 'NOTAS DEL PEDIDO'.
     * @return array
     */
    private static function caja_de_observaciones($key, $titulo)
    {
        return self::caja(self::CAJA_OBSERVACIONES, 12, 'gris', [self::campo($key, '')], $titulo);
    }

    /**
     * El "Pie de página" de texto del perfil, como texto libre.
     *
     * @param string $texto
     * @return array
     */
    private static function caja_de_texto_de_pie($texto)
    {
        $campo = self::campo(CatalogoDeCamposPdf::KEY_TEXTO_LIBRE);
        $campo['id'] = self::ID_TEXTO_DE_PIE;
        $campo['texto'] = $texto;

        return self::caja(self::CAJA_TEXTO_DE_PIE, 12, 'ninguno', [$campo]);
    }

    /**
     * Una caja.
     *
     * @param string $id
     * @param int    $cols   1..12
     * @param string $estilo 'borde' | 'gris' | 'ninguno'
     * @param array  $campos
     * @param string $titulo
     * @return array
     */
    private static function caja($id, $cols, $estilo, $campos, $titulo = '')
    {
        return [
            'tipo' => DisenoDePaginaPdf::TIPO_CAJA,
            'id' => $id,
            'cols' => $cols,
            'titulo' => $titulo,
            'estilo' => $estilo,
            'campos' => $campos,
        ];
    }

    /**
     * Un campo con el estilo del catálogo (null), salvo lo que se indique.
     *
     * @param string      $key
     * @param string|null $etiqueta null = la del catálogo; '' = sin rótulo.
     * @param int|null    $tamano
     * @param bool|null   $negrita
     * @return array
     */
    private static function campo($key, $etiqueta = null, $tamano = null, $negrita = null)
    {
        return [
            'key' => $key,
            'etiqueta' => $etiqueta,
            'tamano' => $tamano,
            'negrita' => $negrita,
            'cursiva' => null,
            'alineacion' => null,
        ];
    }

    /**
     * Un bloque fijo de la factura de ARCA.
     *
     * @param string    $key
     * @param bool|null $importes solo para afip_pie: muestra el cuadro de importes.
     * @return array
     */
    private static function fijo($key, $importes)
    {
        $fijo = [
            'tipo' => DisenoDePaginaPdf::TIPO_FIJO,
            'key' => $key,
        ];

        if ($key === CatalogoDeCamposPdf::FIJO_AFIP_PIE) {
            $fijo['importes'] = (bool) $importes;
        }

        /**
         * El receptor de la factura de siempre (AfipPdfHelper::print_receptor_block()) va a lo ancho
         * de la hoja: 12 columnas. normalizar() pondría lo mismo si faltara, pero el derivado lo
         * dice explícito porque es lo que imprime hoy, no un default.
         */
        if ($key === CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR) {
            $fijo['cols'] = 12;
        }

        return $fijo;
    }
}
