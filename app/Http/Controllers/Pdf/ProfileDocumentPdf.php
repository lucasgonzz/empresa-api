<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\PdfDocumentSource;
use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Helpers\PdfLayout\CamposDePedidoPdf;
use App\Http\Controllers\Helpers\PdfLayout\CamposDePresupuestoPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\Layout\MotorDeCajasPdf;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use fpdf;
use Illuminate\Support\Facades\Log;

/*
	| require_once y NO require: con un require pelado, el SEGUNDO PDF que se instancie en un mismo
	| proceso muere con 'Cannot declare class FPDF'. En produccion cada request arma un solo PDF, pero
	| una corrida de tests arma varios (ver el comentario de BudgetPdf.php).
*/
require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de un comprobante que NO es una venta (presupuesto, pedido online) dibujado con un diseño
 * de PDF (`PdfColumnProfile`): mismo editor de columnas, mismo encabezado configurable y mismo
 * pie que el remito.
 *
 * POR QUÉ ES UNA CLASE APARTE Y NO `NewSalePdf`. `NewSalePdf` imprime los remitos y las facturas
 * de todos los clientes y está atado a `Sale`. Hacerlo hablar con presupuestos es el riesgo más
 * alto de la misión y no aporta nada visible, así que esta clase REPLICA el aspecto del remito
 * (encabezado unificado, tabla con encabezado gris, caja gris de totales, recuadro de
 * observaciones) y reusa `AfipPdfHelper::header_comercial()` + `table_header()`. Del comprobante
 * solo lee lo que le da un `PdfDocumentSource`; la plata no se calcula acá.
 *
 * Replica UN solo camino del remito: "totales solo en la última hoja". `show_totals_on_each_page`,
 * las comisiones, los costos y `discount_display_mode` no existen para estos comprobantes.
 *
 * DIFERENCIA DE CICLO DE VIDA con el resto de los PDF de `Pdf/`, que dibujan y hacen
 * `Output(); exit;` en el constructor (por eso ningún test los instancia): acá el constructor NO
 * tiene efectos, `render()` arma las hojas y `emit()` es lo único que termina el proceso, y solo
 * lo llama el controlador. Así se puede probar con `render()` + `Output('S')`.
 *
 * DOS MODOS (misión diseno-pdf-configurable, 1/10/2026):
 * - El de siempre (perfil con `page_layout` NULL): todo lo de arriba, sin un solo cambio. Los tests
 *   10, 11, 12 y 13 de tests/Feature/Pdf lo cuidan sin tocar sus aserciones.
 * - Con diseño de página: la hoja y el margen del perfil, el encabezado del emisor SIN el bloque
 *   del cliente, las cajas de arriba de la tabla (en todas las hojas) y las del pie (en la última
 *   hoja, o en cada hoja con "Mostrar pie de página en cada hoja", como el remito con cajas),
 *   dibujadas por `MotorDeCajasPdf` con los valores de `CamposDePresupuestoPdf` /
 *   `CamposDePedidoPdf`. Los flags del pie (total, sub total, texto, observaciones del cliente)
 *   no se leen: lo que se imprime lo dicen las cajas.
 * - Un perfil SIN diseño pero con "Mostrar pie de página en cada hoja" prendido se dibuja con el
 *   diseño DERIVADO del perfil (`DisenoDerivadoPdf`), no con el modo de siempre: el de siempre
 *   no sabe repetir el pie, y el tilde no puede mentir. Con el tilde apagado, el de siempre.
 */
class ProfileDocumentPdf extends fpdf
{
    /** Ancho útil de la hoja A4 (210) menos el margen de 5mm de cada lado. */
    const CONTENT_WIDTH = 200;

    /** Límite Y (mm) hasta donde puede llegar una fila de la tabla antes de saltar de hoja. */
    const ITEMS_BOTTOM_Y = 285;

    /** Diseño de página: separación entre el encabezado y la zona de arriba (mm). */
    const SEPARACION_DE_LA_ZONA_SUPERIOR = 2;

    /** Diseño de página: separación entre el último renglón y la zona pie (mm). */
    const SEPARACION_DEL_PIE = 3;

    /** Comprobante que se dibuja (adaptador: presupuesto, pedido). */
    private $source;
    /** Dueño del comprobante: emisor del encabezado. */
    private $user;
    /** Columnas visibles del diseño, ya ordenadas: label, value_resolver, width, wrap_content. */
    private $profile_columns;
    /** Margen izquierdo de la tabla, en mm. */
    private $start_x;
    /** Alto de una línea de texto de la tabla, en mm. */
    private $line_height;
    /** Objeto que el encabezado lee como si fuera una venta (lo arma el adaptador en render()). */
    private $header_document;
    /** Diseño del encabezado por cuadrante (el del perfil, o el default por código). */
    private $header_layout;
    /** Imprime la caja de totales (con el total apagado es el diseño "sin precios"). */
    private $show_total_in_footer;
    /** Imprime la línea "Sub Total" cuando hay algo que la separe del total. */
    private $show_subtotal_in_footer;
    /** Texto libre debajo de los totales. */
    private $footer_text;
    /** Imprime las observaciones del cliente (`clients.description`) debajo del encabezado. */
    private $show_client_description;
    /** Renglones del comprobante, ya listos. */
    private $doc_items;
    /** Renglones de la caja de totales (memoizados: pesan una consulta cada vez que se piden). */
    private $totals_rows_cache;
    /** Filas dibujadas en la hoja actual: el salto de hoja solo aplica si ya hay al menos una. */
    private $rows_on_page;
    /** true cuando render() ya armó las hojas. */
    private $rendered;
    /** Cantidad de imágenes que no se pudieron dibujar. */
    private $discarded_images_count;
    /** Lo lee `AfipPdfHelper::header_comercial()`: imprime la fecha de hoy en vez de la del comprobante. */
    public $use_current_date;
    /** Lo lee `AfipPdfHelper::header_comercial()`: lado del logo, en mm. */
    public $logo_size_mm;
    /** Lo lee `PdfHelper::client_description()` (borde de las celdas: 0 = sin borde). */
    public $b;
    /**
     * Lo lee `AfipPdfHelper` con un diseño de página: dónde arranca el comprobante. null en el modo
     * de siempre (AfipPdfHelper dibuja en 5 / 200 como siempre).
     */
    public $pdf_x0;
    /** Lo lee `AfipPdfHelper` con un diseño de página: ancho útil del comprobante (null = el de siempre). */
    public $pdf_ancho_util;
    /** Lo lee `AfipPdfHelper` con un diseño de página: dónde arranca el encabezado (null = el de siempre). */
    public $pdf_y0;
    /** Diseño de página normalizado, o null: el PDF de siempre. */
    private $diseno;
    /** Motor que mide y dibuja las cajas del diseño (null sin diseño). */
    private $motor;
    /** Límite Y de los renglones de la tabla (285 en el de siempre; alto − margen − 7 con diseño). */
    private $limite_de_renglones;
    /** Hasta dónde llega la línea debajo de cada renglón (205 en el de siempre). */
    private $fin_de_renglon;
    /** Alto del pie del diseño (separación + zona), memoizado. */
    private $alto_del_pie;
    /**
     * Con diseño: la zona de arriba va en todas las hojas (true) o solo en la primera (false). null
     * hasta que el encabezado de la primera hoja lo decide (decidir_la_zona_superior()).
     */
    private $zona_superior_en_cada_hoja;
    /** Con diseño: render() está dibujando la zona de arriba de la primera hoja (el encabezado pone solo el del emisor). */
    private $dibujando_la_zona_superior;
    /** Con diseño: alto del renglón más alto (lo mide render() antes de la primera hoja). */
    private $alto_maximo_de_renglon;
    /** Con diseño: dónde arrancan los renglones en una hoja nueva (lo calcula decidir_la_zona_superior()). */
    private $y_de_los_renglones;
    /** Con diseño: lo que midió medir_item() de cada renglón antes de la primera hoja, por índice. */
    private $medidas_de_renglones;
    /**
     * Con diseño: "Mostrar pie de página en cada hoja" (el pie va en Footer() de cada hoja con los
     * totales finales), salvo que decidir_la_zona_superior() tenga que mandarlo a la última hoja.
     */
    private $pie_en_cada_hoja;
    /** Con diseño: la hoja actual tiene el encabezado de la tabla (el pie en cada hoja va solo en esas). */
    private $tabla_en_la_hoja;

    /**
     * Prepara el PDF SIN dibujar nada ni terminar el proceso.
     *
     * @param PdfDocumentSource $source  Comprobante (presupuesto o pedido) ya adaptado.
     * @param PdfColumnProfile  $profile Diseño de PDF con el que se imprime.
     */
    public function __construct(PdfDocumentSource $source, PdfColumnProfile $profile)
    {
        $dueno = User::find($source->owner_id());

        /**
         * El diseño con el que se dibuja: el del perfil, el derivado del perfil (sin diseño pero
         * con el pie en cada hoja), o ninguno (el modo de siempre). Con diseño de página la hoja es
         * la del perfil; sin diseño, la A4 de siempre (el mismo parent::__construct() sin
         * argumentos que antes).
         *
         * 🔴 Con el derivado, la hoja es la A4 de siempre y NO la que el perfil tenga guardada: esas
         * medidas no las eligió nadie para un diseño (un perfil de venta con los 297/277 del
         * formulario viejo, pasado a presupuesto, salía en una hoja de 297 × 297; uno con
         * 210/200/5, con 190 mm útiles) y el diseñador muestra ese perfil en la A4 de siempre.
         */
        $diseno_crudo = $this->diseno_con_el_que_se_dibuja($source, $profile, $dueno);
        $con_diseno = ! is_null($diseno_crudo);
        $hoja = null;
        if ($con_diseno) {
            $hoja = DisenoDePaginaPdf::tiene_diseno($profile)
                ? MotorDeCajasPdf::geometria_de_la_hoja($profile)
                : MotorDeCajasPdf::geometria_de_la_hoja_de_siempre();
        }

        if ($con_diseno) {
            parent::__construct('P', 'mm', [$hoja['ancho_de_hoja'], $hoja['alto_de_hoja']]);
        } else {
            parent::__construct();
        }
        $this->SetAutoPageBreak(false);

        $this->source = $source;
        $this->user = $dueno;
        $this->profile_columns = $this->get_profile_columns($profile);

        $this->start_x = 5;
        $this->b = 0;
        $this->line_height = 5;
        $this->header_document = null;
        $this->doc_items = [];
        $this->totals_rows_cache = null;
        $this->rows_on_page = 0;
        $this->rendered = false;
        $this->discarded_images_count = 0;

        /**
         * Layout del encabezado: el guardado en el perfil o, si no tiene uno propio, el default del
         * tipo de comprobante (el del remito y, para el presupuesto, además el Vendedor).
         */
        $this->header_layout = ! empty($profile->header_layout)
            ? $profile->header_layout
            : PdfDocumentSetupHelper::default_header_layout_for($source->model_name());

        /** Los flags que vienen null (perfil viejo) toman el mismo default que en el remito. */
        $this->show_total_in_footer = $this->flag($profile->show_total_in_footer, true);
        $this->show_subtotal_in_footer = $this->flag($profile->show_subtotal_in_footer, true);
        $this->show_client_description = $this->flag($profile->show_client_description, true);
        $this->use_current_date = $this->flag($profile->use_current_date, false);
        $this->footer_text = $profile->footer_text ? (string) $profile->footer_text : '';

        /** Tamaño del logo: perfil -> global del dueño (`pdf_image_size`) -> 35, igual que el remito. */
        $this->logo_size_mm = 35;
        if ((int) $profile->logo_size_mm > 0) {
            $this->logo_size_mm = (int) $profile->logo_size_mm;
        } elseif ($this->user && $this->user->pdf_image_size) {
            $this->logo_size_mm = (int) $this->user->pdf_image_size;
        }

        /** Modo de siempre: A4, 5 / 200, renglones hasta 285 y la línea hasta 205. */
        $this->pdf_x0 = null;
        $this->pdf_ancho_util = null;
        $this->pdf_y0 = null;
        $this->diseno = null;
        $this->motor = null;
        $this->alto_del_pie = null;
        $this->zona_superior_en_cada_hoja = null;
        $this->dibujando_la_zona_superior = false;
        $this->alto_maximo_de_renglon = 0;
        $this->y_de_los_renglones = 0;
        $this->medidas_de_renglones = [];
        $this->pie_en_cada_hoja = false;
        $this->tabla_en_la_hoja = false;
        $this->limite_de_renglones = self::ITEMS_BOTTOM_Y;
        $this->fin_de_renglon = 210 - $this->start_x;

        if ($con_diseno) {
            $this->preparar_diseno($source, $profile, $hoja, $diseno_crudo);
        }
    }

    /**
     * El diseño de página con el que se dibuja este comprobante, o null para el modo de siempre.
     *
     * - El del perfil, si tiene (`page_layout`).
     * - 🔴 Sin diseño pero con "Mostrar pie de página en cada hoja" prendido: el DERIVADO del
     *   perfil (`DisenoDerivadoPdf::para()`, el mismo que el diseñador muestra de arranque; acá
     *   solo se lee, no se guarda). El modo de siempre no sabe repetir el pie en cada hoja, y
     *   Lucas puso ese tilde en el editor de los tres modelos: si el presupuesto saliera con el
     *   modo de siempre, el tilde prendido mentiría. Con el tilde apagado, el modo de siempre,
     *   sin un byte de diferencia.
     *
     * Solo presupuesto y pedido online: otro comprobante sale siempre con el modo de siempre.
     *
     * @param PdfDocumentSource       $source
     * @param PdfColumnProfile        $profile
     * @param \App\Models\User|null $dueno
     * @return array|null
     */
    private function diseno_con_el_que_se_dibuja(PdfDocumentSource $source, PdfColumnProfile $profile, $dueno)
    {
        if (! ($source instanceof BudgetPdfDocument || $source instanceof OrderPdfDocument)) {
            return null;
        }

        if (DisenoDePaginaPdf::tiene_diseno($profile)) {
            return $profile->page_layout;
        }

        if ($this->flag($profile->show_totals_on_each_page, false)) {
            return DisenoDerivadoPdf::para($source->model_name(), $profile, false, $dueno);
        }

        return null;
    }

    /**
     * Modo con diseño de página: la geometría de la hoja (la leen AfipPdfHelper y la tabla), el
     * diseño normalizado y el motor de cajas con la fuente de campos del comprobante.
     *
     * Presupuesto y pedido nunca son factura de ARCA: asegurar_fijos(…, false) saca cualquier
     * bloque fijo que hubiera quedado en el JSON.
     *
     * @param PdfDocumentSource $source
     * @param PdfColumnProfile  $profile
     * @param array             $hoja         MotorDeCajasPdf::geometria_de_la_hoja($profile), o la de siempre con el derivado
     * @param array             $diseno_crudo El diseño del perfil o el derivado (diseno_con_el_que_se_dibuja()).
     * @return void
     */
    private function preparar_diseno(PdfDocumentSource $source, PdfColumnProfile $profile, $hoja, $diseno_crudo)
    {
        $this->pdf_x0 = $hoja['x0'];
        $this->pdf_ancho_util = $hoja['ancho_util'];
        $this->pdf_y0 = $hoja['y0'];
        $this->SetMargins($hoja['x0'], $hoja['margen'], $hoja['margen_derecho']);

        $this->start_x = $hoja['x0'];
        $this->limite_de_renglones = $hoja['limite_inferior'];
        $this->fin_de_renglon = $hoja['x0'] + $hoja['ancho_util'];

        $diseno = DisenoDePaginaPdf::normalizar($diseno_crudo);
        $this->diseno = DisenoDePaginaPdf::asegurar_fijos(is_null($diseno) ? DisenoDePaginaPdf::vacio() : $diseno, false);

        /** Como el remito con cajas: el pie en cada hoja, con los totales finales (ver Footer()). */
        $this->pie_en_cada_hoja = $this->flag($profile->show_totals_on_each_page, false);

        $fuente = $source instanceof BudgetPdfDocument
            ? new CamposDePresupuestoPdf($source, $this->use_current_date)
            : new CamposDePedidoPdf($source, $this->use_current_date);

        $this->motor = new MotorDeCajasPdf($fuente, $hoja['x0'], $hoja['ancho_util']);
    }

    /**
     * Arma todas las hojas: encabezado, tabla de renglones y, en la última, los totales, el texto
     * de pie y las observaciones. Se puede llamar una sola vez.
     *
     * El orden importa: primero los renglones y su precarga, después los totales. El total del
     * presupuesto recarga las relaciones del modelo (`BudgetHelper::getTotal()`), y una precarga
     * hecha DESPUÉS no llegaría a los renglones que ya se imprimieron.
     *
     * @return void
     */
    public function render()
    {
        if ($this->rendered) {
            return;
        }
        $this->rendered = true;

        $this->header_document = $this->source->header_document();
        $this->doc_items = $this->source->items();
        $this->source->preload_item_relations(
            $this->has_resolver(['document_item_image']),
            $this->has_resolver(['document_item_brand_name', 'document_item_category_name', 'document_item_sub_category_name', 'document_item_provider_name'])
        );

        /**
         * La plata se pide en el mismo punto en los dos modos (después de la precarga de los
         * renglones): con diseño, medir el pie le pide a la fuente sus valores una vez.
         */
        if (is_null($this->diseno)) {
            $this->get_totals_rows();
        } else {
            $this->alto_del_pie();
            $this->medir_los_renglones();
        }

        $this->AddPage();

        if ($this->dibujando_la_zona_superior) {
            $this->dibujar_la_zona_superior_de_la_primera_hoja();
        }

        $index = 1;
        foreach ($this->doc_items as $item) {
            $this->print_item($index, $item);
            $index++;
        }

        $this->print_closing_blocks();

        if ($this->discarded_images_count > 0) {
            Log::info('ProfileDocumentPdf: '.$this->discarded_images_count.' imagenes descartadas de '.count($this->doc_items).' renglones');
        }
    }

    /**
     * Manda el PDF al navegador y termina el proceso. SOLO lo llama el controlador: un test que
     * llegue acá se lleva puesto a PHPUnit (usar `render()` + `Output('S')`).
     *
     * Con diseño de página, viniendo de try_render() el documento ya está CERRADO (lo cerró su
     * Output('S'), adentro del respaldo): este Output() no dibuja nada más —el Close() de FPDF no
     * hace nada con el documento cerrado— y solo manda esos mismos bytes con los headers de FPDF
     * de siempre (Content-Type: application/pdf; Content-Disposition: inline; filename="doc.pdf";
     * Cache-Control: private, max-age=0, must-revalidate; Pragma: public). En el modo de siempre
     * lo cierra este Output(), como antes.
     *
     * @return void
     */
    public function emit()
    {
        $this->render();
        $this->Output();
        exit;
    }

    /**
     * Arma el PDF con el diseño y devuelve la instancia ya dibujada, o null si el diseño falló.
     *
     * Por qué existe: el link de WhatsApp de un presupuesto lo abre el CLIENTE FINAL del comercio y
     * desde esta misión lleva el diseño por defecto. Si el diseño nuevo tirara una excepción con un
     * dato raro, el cliente vería un 500 donde antes veía un PDF. El controlador, con null, cae al
     * PDF de siempre (`BudgetPdf` / `OrderPdf`).
     *
     * La falla NO se traga: `report()` la manda al log y al registro de errores, así que un diseño
     * que se rompe se entera igual; lo que cambia es que el cliente final no se lleva el 500.
     *
     * Nada salió al navegador hasta acá: `render()` y `Output('S')` arman todo en memoria y recién
     * `emit()` manda los bytes. Por eso volver atrás a mitad de camino es seguro.
     *
     * `new static`: un test puede pedirle el respaldo a una subclase que falla a propósito.
     *
     * @param PdfDocumentSource $source  Presupuesto o pedido adaptado.
     * @param PdfColumnProfile  $profile Diseño elegido.
     * @return ProfileDocumentPdf|null
     */
    public static function try_render(PdfDocumentSource $source, PdfColumnProfile $profile)
    {
        try {
            $pdf = new static($source, $profile);

            /**
             * Con diseño de página, una hoja con menos ancho útil que el mínimo (papel de 20 mm con
             * margen de 10: la API lo acepta) no se dibuja: sale el PDF de siempre, que es A4 (ver
             * MotorDeCajasPdf::ANCHO_UTIL_MINIMO).
             */
            if (! is_null($pdf->diseno) && $pdf->pdf_ancho_util < MotorDeCajasPdf::ANCHO_UTIL_MINIMO) {
                return null;
            }

            /** Un diseño sin ninguna columna visible saldría sin tabla de renglones: mejor el PDF de siempre. */
            if (empty($pdf->profile_columns)) {
                return null;
            }

            $pdf->render();

            /**
             * 🔴 Con diseño de página el documento se CIERRA acá, adentro del try: el Footer() de la
             * última hoja y el cierre de FPDF corren en Close(), que sin esto recién corría en el
             * Output() de emit(), afuera de este respaldo (un 500 en vez del PDF de siempre). emit()
             * solo manda los bytes.
             *
             * El modo de siempre (sin `page_layout`) queda como estaba: devuelve la instancia sin
             * cerrar. Su último Footer() es el vacío de FPDF, y quien recibe la instancia puede
             * elegir todavía cómo cerrarla (los tests de esa misión le apagan la compresión para
             * leer el texto).
             */
            if (! is_null($pdf->diseno)) {
                $pdf->Output('S');
            }

            return $pdf;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Columnas visibles del perfil, ordenadas por el `order` del pivot.
     *
     * @param PdfColumnProfile $profile
     * @return array<int, array<string, mixed>>
     */
    private function get_profile_columns(PdfColumnProfile $profile)
    {
        if (! $profile->relationLoaded('pdf_column_options')) {
            $profile->load('pdf_column_options');
        }

        $rows = [];
        foreach ($profile->pdf_column_options as $option) {
            $width = isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width;

            /** Oculta o sin ancho: no se dibuja (con ancho 0 FPDF estira la celda hasta el margen derecho). */
            if ((isset($option->pivot->visible) && ! $option->pivot->visible) || $width <= 0) {
                continue;
            }
            $rows[] = [
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'order' => isset($option->pivot->order) ? (int) $option->pivot->order : 0,
                'width' => $width,
                'wrap_content' => isset($option->pivot->wrap_content) ? (bool) $option->pivot->wrap_content : false,
            ];
        }

        usort($rows, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $rows;
    }

    /**
     * ¿El diseño tiene alguna de estas columnas? Sirve para precargar solo lo que se va a usar.
     *
     * @param array $resolvers Lista de value_resolver.
     * @return bool
     */
    private function has_resolver($resolvers)
    {
        foreach ($this->profile_columns as $column) {
            if (in_array($column['value_resolver'], $resolvers, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Encabezado de cada hoja: el mismo del remito (emisor + cliente) con el título del
     * comprobante a la derecha, las observaciones del cliente y el encabezado de la tabla.
     * Lo dispara FPDF en cada AddPage().
     *
     * @return void
     */
    public function Header()
    {
        $this->rows_on_page = 0;
        $this->tabla_en_la_hoja = false;

        if (! is_null($this->diseno)) {
            $this->header_con_diseno();

            return;
        }

        AfipPdfHelper::header_comercial(
            $this,
            $this->header_document,
            $this->user,
            $this->header_layout,
            null,
            ['right_title' => $this->source->title()]
        );

        $client = $this->header_document->client;

        if (
            $this->show_client_description
            && ! is_null($client)
            && trim((string) $client->description) !== ''
        ) {
            PdfHelper::client_description($this, $client, $this->y);
        }

        /** Mapa etiqueta => ancho, que es lo que dibuja el encabezado gris de la tabla. */
        $fields = [];
        foreach ($this->profile_columns as $column) {
            $fields[$column['label']] = $column['width'];
        }
        AfipPdfHelper::table_header($this, $fields);
    }

    /**
     * Encabezado con diseño de página: el del emisor SIN el bloque del cliente (lo ponen las cajas),
     * la zona de arriba del diseño (2 mm debajo, en todas las hojas) y el encabezado de la tabla
     * en la x de la hoja.
     *
     * @return void
     */
    private function header_con_diseno()
    {
        AfipPdfHelper::header_comercial(
            $this,
            $this->header_document,
            $this->user,
            $this->header_layout,
            null,
            ['right_title' => $this->source->title(), 'sin_receptor' => true]
        );

        if (is_null($this->zona_superior_en_cada_hoja)) {
            $this->decidir_la_zona_superior($this->y);
        }

        /**
         * Hoja de la zona de arriba de la primera hoja (o una a la que la zona siguió porque es más
         * alta que la hoja): la zona la dibuja render() debajo de este encabezado, y el de la tabla
         * va recién donde la zona termina.
         */
        if ($this->dibujando_la_zona_superior) {
            return;
        }

        if ($this->zona_superior_en_cada_hoja) {
            /** Si la zona no dibuja nada, no deja el hueco. */
            $y = $this->y;
            $alto = $this->motor->dibujar_zona($this, $this->diseno['superior'], $y + self::SEPARACION_DE_LA_ZONA_SUPERIOR);
            if ($alto <= 0) {
                $this->y = $y;
            }
        }

        $this->encabezado_de_la_tabla_con_diseno();
    }

    /**
     * El encabezado gris de la tabla, en la x de la hoja del diseño.
     *
     * @return void
     */
    private function encabezado_de_la_tabla_con_diseno()
    {
        $fields = [];
        foreach ($this->profile_columns as $column) {
            $fields[$column['label']] = $column['width'];
        }
        AfipPdfHelper::table_header($this, $fields, $this->pdf_x0);
        $this->tabla_en_la_hoja = true;
    }

    /**
     * Con diseño y "pie en cada hoja", el pie va debajo del último renglón de CADA hoja con la
     * tabla, con los totales FINALES (el motor se los pide a la fuente: no dependen de cuántos
     * renglones se llevan dibujados). FPDF no deja agregar una hoja desde acá: su alto ya se
     * reservó en el salto de los renglones (limite_para_renglones()). Una hoja que lleva solo la
     * zona de arriba (una zona más alta que la hoja) no le reservó lugar y no lo lleva. Sin
     * diseño no hace nada, como el Footer() vacío de FPDF de siempre.
     *
     * @return void
     */
    public function Footer()
    {
        if (is_null($this->diseno) || ! $this->pie_en_cada_hoja || ! $this->tabla_en_la_hoja) {
            return;
        }

        if ($this->alto_del_pie() <= 0) {
            return;
        }

        $this->motor->dibujar_zona($this, $this->diseno['pie'], $this->y + self::SEPARACION_DEL_PIE);
    }

    /**
     * Hasta dónde puede llegar un renglón de la tabla: el límite de siempre y, con diseño y el pie
     * en cada hoja, menos el alto del pie.
     *
     * @return float
     */
    private function limite_para_renglones()
    {
        if (is_null($this->diseno) || ! $this->pie_en_cada_hoja) {
            return $this->limite_de_renglones;
        }

        return $this->limite_de_renglones - $this->alto_del_pie();
    }

    /**
     * 🔴 ZONA DE ARRIBA QUE NO DEJA LUGAR PARA UN RENGLÓN: va solo en la primera hoja.
     *
     * La zona de arriba se dibujaba en el encabezado de cada hoja sin medirla, y print_item()
     * dibuja siempre el primer renglón de cada hoja: con una zona más alta que el lugar libre, cada
     * renglón salía en una hoja propia y por DEBAJO del papel (el mismo defecto que se midió el
     * 1/10/2026 en el remito: A5, margen 5, 8 renglones y una zona de dos cajas de 12 columnas
     * daban 10 hojas, con todos los renglones en y = 234,8 en una hoja de 210). Se decide en el
     * encabezado de la primera hoja, con el del emisor ya dibujado (es el mismo en todas): si
     * encabezado + zona + encabezado de la tabla no dejan lugar para el renglón más alto, la zona
     * va SOLO en la primera hoja y las siguientes llevan encabezado + encabezado de la tabla. La
     * dibuja render() (dibujar_la_zona_superior_de_la_primera_hoja()): si tampoco entra entera en
     * la primera hoja sigue en la segunda, y eso pide AddPage(), que FPDF no permite desde Header().
     *
     * @param float $y_del_encabezado Dónde terminó el encabezado del emisor.
     * @return void
     */
    private function decidir_la_zona_superior($y_del_encabezado)
    {
        $tabla_y_renglon = AfipPdfHelper::alto_de_table_header() + $this->alto_maximo_de_renglon;

        /**
         * 🔴 "Pie en cada hoja" que no deja lugar para ningún renglón ni siquiera sin la zona de
         * arriba: va solo en la última hoja (la misma decisión que SaleLayoutPdf::decidir_la_hoja():
         * el pie de cada hoja se dibuja en Footer(), que no puede saltar de hoja, y la guarda de "al
         * menos un renglón por hoja" lo mandaría afuera del papel).
         */
        if ($this->pie_en_cada_hoja && $y_del_encabezado + $tabla_y_renglon + $this->alto_del_pie() > $this->limite_de_renglones) {
            $this->pie_en_cada_hoja = false;
        }

        $alto_de_la_zona = $this->motor->medir_zona($this, $this->diseno['superior']);
        $zona = $alto_de_la_zona > 0 ? self::SEPARACION_DE_LA_ZONA_SUPERIOR + $alto_de_la_zona : 0;
        $pie = $this->pie_en_cada_hoja ? $this->alto_del_pie() : 0;

        /** La zona cede antes que el pie en cada hoja (el tilde que eligió el usuario). */
        $this->zona_superior_en_cada_hoja = $zona <= 0
            || $y_del_encabezado + $zona + $tabla_y_renglon + $pie <= $this->limite_de_renglones;
        $this->dibujando_la_zona_superior = ! $this->zona_superior_en_cada_hoja;

        /** Dónde arrancan los renglones en una hoja nueva (lo usa el pie de la última hoja). */
        $this->y_de_los_renglones = $y_del_encabezado
            + ($this->zona_superior_en_cada_hoja ? $zona : 0)
            + AfipPdfHelper::alto_de_table_header();
    }

    /**
     * La zona de arriba SOLO en la primera hoja (ver decidir_la_zona_superior()): debajo del
     * encabezado del emisor y, si no entra entera, sigue en la hoja siguiente (entre filas, nunca
     * partiendo una caja). Donde termina va el encabezado de la tabla, si debajo entra el renglón
     * más alto; si no, los renglones arrancan en la hoja siguiente, que ya trae el suyo.
     *
     * @return void
     */
    private function dibujar_la_zona_superior_de_la_primera_hoja()
    {
        $this->motor->dibujar_zona(
            $this,
            $this->diseno['superior'],
            $this->y + self::SEPARACION_DE_LA_ZONA_SUPERIOR,
            function () {
                $this->AddPage();

                return $this->y + self::SEPARACION_DE_LA_ZONA_SUPERIOR;
            },
            $this->limite_de_renglones
        );

        $this->dibujando_la_zona_superior = false;

        $pie = $this->pie_en_cada_hoja ? $this->alto_del_pie() : 0;
        if ($this->y + AfipPdfHelper::alto_de_table_header() + $this->alto_maximo_de_renglon + $pie > $this->limite_de_renglones) {
            $this->AddPage();

            return;
        }

        $this->encabezado_de_la_tabla_con_diseno();
    }

    /**
     * Con diseño: mide todos los renglones antes de la primera hoja (el más alto decide si la
     * zona de arriba va en cada hoja) y guarda cada medida para dibujarlo sin volver a resolver
     * sus valores ni a leer su imagen.
     *
     * @return void
     */
    private function medir_los_renglones()
    {
        $this->alto_maximo_de_renglon = $this->line_height;

        $index = 1;
        foreach ($this->doc_items as $item) {
            $this->medidas_de_renglones[$index] = $this->medir_item($index, $item);
            $this->alto_maximo_de_renglon = max($this->alto_maximo_de_renglon, $this->medidas_de_renglones[$index]['row_height']);
            $index++;
        }
    }

    /**
     * Alto del pie del diseño (separación + zona), medido UNA vez con la misma rutina que lo dibuja.
     *
     * @return float 0 si la zona no dibuja nada.
     */
    private function alto_del_pie()
    {
        if (is_null($this->alto_del_pie)) {
            $alto = $this->motor->medir_zona($this, $this->diseno['pie']);
            $this->alto_del_pie = $alto > 0 ? self::SEPARACION_DEL_PIE + $alto : 0;
        }

        return $this->alto_del_pie;
    }

    /**
     * Pie con diseño de página, en la última hoja: si no entra debajo del último renglón, salta de
     * hoja antes. Un pie que no dibuja nada no salta de hoja (mismo criterio que el de siempre:
     * nunca una hoja que solo repite el encabezado). Si el pie es más alto que lo que queda libre
     * incluso en una hoja nueva, las filas que no entran siguen en la siguiente.
     *
     * @return void
     */
    private function print_pie_con_diseno()
    {
        $alto = $this->alto_del_pie();

        if ($alto <= 0) {
            return;
        }

        /**
         * Salta de hoja si el pie no entra y una hoja nueva le da más lugar: si esta tiene renglones,
         * o si tiene la zona de arriba que las siguientes no llevan (ver decidir_la_zona_superior()).
         * Una hoja que solo repetiría lo que ya hay no ayuda: nunca una hoja con el encabezado y nada.
         */
        $hoja_nueva_con_mas_lugar = $this->rows_on_page > 0 || $this->y > $this->y_de_los_renglones + 0.001;
        if ($hoja_nueva_con_mas_lugar && $this->y + $alto > $this->limite_de_renglones) {
            $this->AddPage();
        }

        $this->motor->dibujar_zona(
            $this,
            $this->diseno['pie'],
            $this->y + self::SEPARACION_DEL_PIE,
            function () {
                $this->AddPage();

                return $this->y + self::SEPARACION_DEL_PIE;
            },
            $this->limite_de_renglones
        );
    }

    /**
     * Dibuja un renglón con las columnas del diseño.
     *
     * El alto de la fila se calcula ANTES de decidir el salto de hoja: es el mayor entre las celdas
     * con texto envuelto y la imagen. Decidir por la posición de arranque (como hace el remito)
     * deja pasar una fila alta que empieza apenas arriba del límite y se sale de la hoja.
     *
     * @param int    $index Posición del renglón, desde 1.
     * @param object $item  Renglón del comprobante (con `pivot`).
     * @return void
     */
    private function print_item($index, $item)
    {
        if (isset($this->medidas_de_renglones[$index])) {
            /** Con diseño, render() ya lo midió antes de la primera hoja (medir_los_renglones()). */
            $medida = $this->medidas_de_renglones[$index];
            unset($this->medidas_de_renglones[$index]);
            $this->SetFont('Arial', '', 8);
        } else {
            $medida = $this->medir_item($index, $item);
        }

        foreach ($medida['discarded_images'] as $discarded_path) {
            $this->count_discarded_image($discarded_path, 'no es una imagen que se pueda leer');
        }

        $row_height = $medida['row_height'];
        $values = $medida['values'];
        $wrap_lines = $medida['wrap_lines'];
        $image_path = $medida['image_path'];
        $image_box = $medida['image_box'];

        /** Guarda contra un bucle de hojas vacías si una sola fila fuera más alta que la hoja. */
        if ($this->rows_on_page > 0 && $this->y + $row_height > $this->limite_para_renglones()) {
            $this->AddPage();
            $this->SetFont('Arial', '', 8);
        }

        $start_y = $this->y;
        $this->x = $this->start_x;

        foreach ($this->profile_columns as $key => $column) {
            $width = (int) $column['width'];
            $cell_x = $this->x;

            if (PdfColumnService::is_document_image_column($column['value_resolver'])) {
                $this->print_image_cell($image_path, $image_box, $cell_x, $start_y, $width, $row_height);
            } elseif (! empty($column['wrap_content'])) {
                /** Con imagen la fila es más alta que el texto: se centra para que no quede pegado arriba. */
                $offset = is_null($image_box) ? 0 : max(0, ($row_height - $wrap_lines[$key] * $this->line_height) / 2);
                $this->y = $start_y + $offset;
                $this->MultiCell($width, $this->line_height, $values[$key], $this->b, 'L', false);
            } else {
                $this->Cell($width, $row_height, $this->truncate_text_to_width($values[$key], $width), $this->b, 0, 'C');
            }

            $this->x = $cell_x + $width;
            $this->y = $start_y;
        }

        $this->x = $this->start_x;
        $this->y = $start_y + $row_height;
        $this->Line($this->start_x, $this->y, $this->fin_de_renglon, $this->y);
        $this->rows_on_page++;
    }

    /**
     * Mide un renglón: sus valores, las líneas de las celdas con texto envuelto, la imagen y el alto
     * de la fila (el mayor entre las celdas envueltas y la imagen). Es lo que print_item() medía al
     * empezar, sin cambios; las imágenes que no se pueden leer las cuenta print_item() al dibujar
     * (así un renglón medido antes no se cuenta dos veces).
     *
     * @param int    $index Posición del renglón, desde 1.
     * @param object $item  Renglón del comprobante (con `pivot`).
     * @return array{row_height: float, values: array, wrap_lines: array, image_path: string|null, image_box: array|null, discarded_images: array}
     */
    private function medir_item($index, $item)
    {
        $this->SetFont('Arial', '', 8);

        $row_height = $this->line_height;
        $values = [];
        $wrap_lines = [];
        $image_path = null;
        $image_box = null;
        $discarded_images = [];

        foreach ($this->profile_columns as $key => $column) {
            $width = (int) $column['width'];

            if (PdfColumnService::is_document_image_column($column['value_resolver'])) {
                /**
                 * MISMA decisión para medir y para dibujar: caja de la imagen respetando su
                 * proporción, o null si no se puede leer. Si medir y dibujar decidieran por
                 * separado, la fila reservaría alto para una imagen que no se dibuja (o al revés).
                 */
                $image_path = PdfColumnService::document_first_image_path($item);
                $image_box = CatalogHeaderLayoutHelper::logo_box_dimensions_mm($image_path, max(1, $width - 2));
                if (! is_null($image_box)) {
                    $row_height = max($row_height, $image_box['height'] + 2);
                } elseif (! empty($image_path)) {
                    $discarded_images[] = $image_path;
                }
                continue;
            }

            $values[$key] = (string) PdfColumnService::resolve_value($column['value_resolver'], [
                'item' => $item,
                'index' => $index,
                'document' => $this->source,
                'numbers' => Numbers::class,
            ]);

            if (! empty($column['wrap_content'])) {
                $wrap_lines[$key] = max(1, $this->NbLines($width, $values[$key]));
                $row_height = max($row_height, $wrap_lines[$key] * $this->line_height);
            }
        }

        return [
            'row_height' => $row_height,
            'values' => $values,
            'wrap_lines' => $wrap_lines,
            'image_path' => $image_path,
            'image_box' => $image_box,
            'discarded_images' => $discarded_images,
        ];
    }

    /**
     * Dibuja la imagen del renglón centrada en su celda (x, y, ancho de la celda y alto de la fila).
     * Sin caja (no hay imagen o no se puede leer) la celda queda vacía. Un fallo de FPDF no aborta
     * el PDF: se cuenta y se sigue.
     *
     * @param string|null $path Ruta local resuelta.
     * @param array|null  $box  ['width', 'height'] en mm, o null.
     * @return void
     */
    private function print_image_cell($path, $box, $x, $y, $width, $row_height)
    {
        if (is_null($box)) {
            return;
        }

        try {
            $this->Image($path, $x + ($width - $box['width']) / 2, $y + ($row_height - $box['height']) / 2, $box['width'], $box['height']);
        } catch (\Exception $e) {
            $this->count_discarded_image($path, 'FPDF no pudo dibujarla: '.$e->getMessage());
        }
    }

    /**
     * Cuenta una imagen descartada (ruta y motivo). Loguea el detalle de la PRIMERA nomás: un
     * presupuesto de 300 renglones sin fotos no puede escribir 300 líneas por PDF.
     *
     * @return void
     */
    private function count_discarded_image($path, $reason)
    {
        $this->discarded_images_count++;

        if ($this->discarded_images_count === 1) {
            Log::info('ProfileDocumentPdf: imagen descartada '.$path.' ('.$reason.')');
        }
    }

    /** @return array<int, array{text: string, bold: bool}> Renglones de la caja de totales (memoizados). */
    private function get_totals_rows()
    {
        if (is_null($this->totals_rows_cache)) {
            $this->totals_rows_cache = $this->source->totals_rows([
                'show_total_in_footer' => $this->show_total_in_footer,
                'show_subtotal_in_footer' => $this->show_subtotal_in_footer,
            ]);
        }

        return $this->totals_rows_cache;
    }

    /**
     * Bloque final de la última hoja: caja de totales, texto de pie y observaciones. Si no entran
     * debajo del último renglón, salta de hoja ANTES de dibujarlos.
     *
     * @return void
     */
    private function print_closing_blocks()
    {
        if (! is_null($this->diseno)) {
            /** Con el pie en cada hoja, el de la última lo dibuja su Footer() al cerrar el documento. */
            if (! $this->pie_en_cada_hoja) {
                $this->print_pie_con_diseno();
            }

            return;
        }

        $with_total = count($this->get_totals_rows()) > 0;

        /**
         * Sin caja de totales, sin pie y sin observaciones no hay NADA que dibujar (el diseño
         * "sin precios" de un presupuesto sin observaciones): no se salta de hoja. Sin esta salida,
         * un último renglón entre y=275 y y=285 disparaba un AddPage() para no dibujar nada y el PDF
         * terminaba con una hoja con encabezado y tabla vacía.
         */
        if (
            ! $with_total
            && $this->estimate_footer_text_height() <= 0
            && $this->estimate_observations_height() <= 0
        ) {
            return;
        }

        if ($this->y >= $this->get_last_page_break_limit_y($with_total)) {
            $this->AddPage();
        }

        if ($with_total) {
            $this->print_totals_box();
        } elseif ($this->footer_text) {
            $this->y += 5;
        }

        $this->print_footer_text_block();
        $this->print_observations_block();
    }

    /**
     * Límite Y para decidir el salto de hoja antes del bloque final: 265 (275 sin totales) menos
     * lo que va a ocupar el bloque. Mismo criterio que el remito.
     *
     * @param bool $with_total Se va a dibujar la caja de totales.
     * @return float
     */
    private function get_last_page_break_limit_y($with_total)
    {
        $reserved = $this->estimate_footer_text_height()
            + $this->estimate_observations_height()
            + ($with_total ? $this->estimate_totals_box_height() : 0);

        return max(120, ($with_total ? 265 : 275) - $reserved);
    }

    /**
     * Alto de print_totals_box(): 6mm por renglón, 3mm de padding arriba y abajo, 2mm de separación
     * previa y 3mm posterior. Tiene que seguir a print_totals_box().
     *
     * @return float
     */
    private function estimate_totals_box_height()
    {
        return (count($this->get_totals_rows()) * 6) + (3 * 2) + 2 + 3;
    }

    /**
     * Caja gris con los renglones de totales (fondo 247 y borde 210, como la del remito). El
     * importe impreso es el que arma el comprobante, nunca un acumulado de esta clase.
     *
     * @return void
     */
    private function print_totals_box()
    {
        $rows = $this->get_totals_rows();
        $row_height = 6;
        $padding = 3;
        $box_height = (count($rows) * $row_height) + ($padding * 2);
        $box_x = $this->start_x;
        $box_y = $this->y + 2;

        $this->SetFillColor(247, 247, 247);
        $this->SetDrawColor(210, 210, 210);
        $this->Rect($box_x, $box_y, self::CONTENT_WIDTH, $box_height, 'DF');

        $this->y = $box_y + $padding;

        foreach ($rows as $row) {
            $this->x = $box_x + 4;
            $this->SetFont('Arial', $row['bold'] ? 'B' : '', $row['bold'] ? 12 : 9);
            $this->Cell(self::CONTENT_WIDTH - 8, $row_height, $row['text'], $this->b, 1, 'R');
        }

        /** Colores de vuelta a los de siempre para no afectar lo que se dibuje después. */
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->y = $box_y + $box_height + 3;
    }

    /** @return float Alto del texto de pie (0 si no hay). */
    private function estimate_footer_text_height()
    {
        if (! $this->footer_text) {
            return 0;
        }

        $this->SetFont('Arial', '', 9);

        return 1 + (max(1, $this->NbLines(self::CONTENT_WIDTH, $this->footer_text)) * 5);
    }

    /**
     * Texto libre del pie, debajo de los totales (MultiCell para que soporte varias líneas).
     *
     * @return void
     */
    private function print_footer_text_block()
    {
        if (! $this->footer_text) {
            return;
        }

        $this->y += 1;
        $this->x = $this->start_x;
        $this->SetFont('Arial', '', 9);
        $this->MultiCell(self::CONTENT_WIDTH, 5, $this->footer_text, $this->b, 'L', false);
    }

    /**
     * Alto del recuadro de observaciones: 3 de separación previa, 6 de título, 4.5 por línea,
     * 4 de padding inferior y 3 posteriores. Tiene que seguir a print_observations_block().
     *
     * @return float 0 si el comprobante no tiene observaciones.
     */
    private function estimate_observations_height()
    {
        $observations = $this->source->observations();

        if (is_null($observations)) {
            return 0;
        }

        $this->SetFont('Arial', '', 9);

        return 3 + 6 + (max(1, $this->NbLines(self::CONTENT_WIDTH - 8, $observations)) * 4.5) + 4 + 3;
    }

    /**
     * Recuadro de observaciones (o notas del pedido) con fondo gris y borde sutil, como el del
     * remito. El espacio ya lo reservó get_last_page_break_limit_y(): acá no se salta de hoja.
     *
     * @return void
     */
    private function print_observations_block()
    {
        $observations = $this->source->observations();

        if (is_null($observations)) {
            return;
        }

        $text_width = self::CONTENT_WIDTH - 8;
        $this->SetFont('Arial', '', 9);
        $body_height = max(1, $this->NbLines($text_width, $observations)) * 4.5;
        $box_height = 6 + $body_height + 4;
        $box_y = $this->y + 3;

        $this->SetFillColor(247, 247, 247);
        $this->SetDrawColor(210, 210, 210);
        $this->Rect($this->start_x, $box_y, self::CONTENT_WIDTH, $box_height, 'DF');

        $this->x = $this->start_x + 4;
        $this->y = $box_y + 3;
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(110, 110, 110);
        $this->Cell($text_width, 4, $this->source->observations_title(), 0, 1, 'L');

        $this->x = $this->start_x + 4;
        $this->y += 1;
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(40, 40, 40);
        $this->MultiCell($text_width, 4.5, $observations, 0, 'L', false);

        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);

        $this->y = $box_y + $box_height + 3;
    }

    /**
     * Cantidad de líneas que ocupa un texto en un ancho dado, con la fuente activa. Mismo corte
     * que MultiCell() de FPDF (en el último espacio que entra, "\n" fuerza línea). Mide el texto
     * UTF-8 tal cual, o sea que un acento cuenta doble: sobrestima, nunca subestima.
     *
     * @param float  $w   Ancho de la celda en mm.
     * @param string $txt Texto.
     * @return int
     */
    private function NbLines($w, $txt)
    {
        $cw = &$this->CurrentFont['cw'];
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
     * Acorta el texto al ancho de la celda con puntos suspensivos, para que una celda sin
     * wrap no se desborde sobre la vecina.
     *
     * @param string $text
     * @param float  $width
     * @return string
     */
    private function truncate_text_to_width($text, $width)
    {
        $available_width = max(1, $width - 2);
        $text = (string) $text;

        if ($this->GetStringWidth($text) <= $available_width) {
            return $text;
        }

        $ellipsis_width = $this->GetStringWidth('...');
        if ($ellipsis_width >= $available_width) {
            return '';
        }

        $short_text = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($chars)) {
            return $text;
        }

        foreach ($chars as $char) {
            if ($this->GetStringWidth($short_text.$char) + $ellipsis_width > $available_width) {
                break;
            }
            $short_text .= $char;
        }

        return $short_text.'...';
    }

    /**
     * Booleano que puede venir null (perfil de antes de que existiera la columna): null toma el default.
     *
     * @param mixed $value
     * @param bool  $default
     * @return bool
     */
    private function flag($value, $default)
    {
        return is_null($value) ? $default : (bool) $value;
    }
}
