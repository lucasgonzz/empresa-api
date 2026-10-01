<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfLayout\CamposDeVentaPdf;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\Afip\TicketInfoHelper;
use App\Http\Controllers\Pdf\Layout\MotorDeCajasPdf;
use App\Models\PdfColumnProfile;
use App\Models\User;
use App\Services\PdfColumnService;
use fpdf;

/*
	| 🔴 EL ORDEN EN QUE SE CARGA FPDF NO ES UN DETALLE.
	|
	| `NewSalePdf.php` (el remito y la factura de siempre, que esta misión no toca) carga fpdf.php con
	| un `require` pelado. Si FPDF ya está declarada cuando PHP lee ese archivo, el proceso muere con
	| 'Cannot declare class FPDF'. `SaleController::pdf()` pregunta por el diseño ANTES de decidir qué
	| PDF dibujar, así que este archivo se carga primero también cuando la venta sale con el PDF de
	| siempre (perfil sin diseño, o un diseño que falló y cae al de siempre). Si este archivo
	| declarara FPDF por su cuenta, el `new NewSalePdf(...)` de la línea siguiente rompería TODOS los
	| PDF de venta.
	|
	| Por eso, si FPDF todavía no existe en este proceso, se carga primero la clase NewSalePdf (solo
	| se declara, no se instancia: su constructor es el que dibuja) y es ELLA la que declara FPDF con
	| su require. Después, el require_once de acá ve el archivo ya incluido y no hace nada.
	| Si FPDF ya existe (un proceso de tests que armó otros PDF), no se toca nada.
*/
if (! class_exists('FPDF', false)) {
    class_exists(NewSalePdf::class);
}
require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de una venta (remito o factura de ARCA) dibujado con un DISEÑO DE PÁGINA: el perfil tiene
 * `page_layout` (misión diseno-pdf-configurable, 1/10/2026). Las cajas de arriba de la tabla y las
 * del pie las arma el usuario en el diseñador; el encabezado del emisor, la tabla y los bloques
 * fijos de ARCA son los de siempre.
 *
 * POR QUÉ ES UNA CLASE APARTE Y NO `NewSalePdf`. `NewSalePdf` imprime los remitos y las facturas de
 * todos los clientes; los perfiles sin diseño (`page_layout` NULL, todos hasta que alguien diseña
 * uno) tienen que seguir saliendo EXACTAMENTE igual. Esta clase no la toca: la plata la copia fiel
 * `TotalesDeVentaPdf`, los valores los resuelve `CamposDeVentaPdf` con los mismos formatos, y el
 * dibujo de las cajas es de `MotorDeCajasPdf`.
 *
 * Orden de la hoja (plan §4.2): encabezado del emisor (sin el bloque del cliente: lo ponen las
 * cajas) → zona superior (en todas las hojas, en Header()) → tabla → zona pie (en la última hoja; o
 * en cada hoja con "Mostrar pie de página en cada hoja", con los totales FINALES).
 *
 * CICLO DE VIDA (el de `ProfileDocumentPdf`): el constructor no dibuja ni termina el proceso,
 * `render()` arma las hojas y `emit()` es lo único que hace Output() + exit (solo lo llama el
 * controlador). Así se prueba con `render()` + `Output('S')`.
 */
class SaleLayoutPdf extends fpdf
{
    /** Separación entre el encabezado y la zona de arriba (mm). */
    const SEPARACION_DE_LA_ZONA_SUPERIOR = 2;

    /** Separación entre el último renglón y la zona pie (mm). */
    const SEPARACION_DEL_PIE = 3;

    /** Lo lee `AfipPdfHelper`: dónde arranca el comprobante (mm). */
    public $pdf_x0;

    /** Lo lee `AfipPdfHelper`: ancho útil del comprobante (mm). */
    public $pdf_ancho_util;

    /** Lo lee `AfipPdfHelper`: dónde arranca el encabezado (el margen de arriba). */
    public $pdf_y0;

    /** Lo lee `AfipPdfHelper`: imprime la fecha de hoy en vez de la del comprobante. */
    public $use_current_date;

    /** Lo lee `AfipPdfHelper`: lado del logo, en mm. */
    public $logo_size_mm;

    /** Borde de las celdas de la tabla (0 = sin borde), como en `NewSalePdf`. */
    public $b;

    /** @var \App\Models\Sale */
    private $sale;

    /** @var \App\Models\User|null Dueño de la venta: emisor del encabezado. */
    private $user;

    /** @var PdfColumnProfile */
    private $perfil;

    /** @var array Diseño normalizado, con los bloques fijos asegurados. */
    private $diseno;

    /** @var array<int, array> Columnas visibles de la tabla, ordenadas. */
    private $columnas;

    /** @var array header_layout efectivo (el del perfil o el default). */
    private $header_layout;

    /** @var bool El perfil es "Es factura de ARCA". */
    private $es_perfil_fiscal;

    /** @var \App\Models\AfipTicket|null */
    private $afip_ticket;

    /** @var TicketInfoHelper|null */
    private $ticket_info_helper;

    /** @var \App\Http\Controllers\Helpers\AfipHelper|null */
    private $afip_helper;

    /** @var bool Perfil fiscal CON el comprobante de ARCA resuelto (si no, sale como remito). */
    private $es_fiscal;

    /** @var bool "Mostrar pie de página en cada hoja". */
    private $pie_en_cada_hoja;

    /** @var CamposDeVentaPdf */
    private $fuente;

    /** @var MotorDeCajasPdf */
    private $motor;

    /** @var float Hasta dónde se puede dibujar (alto − margen − 7). */
    private $limite_inferior;

    /** @var float|null Alto que ocupa el pie (separación + zona), memoizado. */
    private $alto_del_pie;

    /** @var int Renglones dibujados en la hoja actual. */
    private $renglones_en_hoja;

    /** @var float Alto de una línea de la tabla. */
    private $line_height;

    /** @var bool render() ya armó las hojas. */
    private $rendered;

    /**
     * @var bool|null La zona de arriba va en todas las hojas (true) o solo en la primera (false).
     *                null hasta que el Header() de la primera hoja lo decide (decidir_la_hoja()).
     */
    private $zona_superior_en_cada_hoja;

    /** @var bool render() está dibujando la zona de arriba de la primera hoja: Header() pone solo el encabezado del emisor. */
    private $dibujando_la_zona_superior;

    /** @var bool La hoja actual tiene el encabezado de la tabla (el pie "en cada hoja" va solo en esas). */
    private $tabla_en_la_hoja;

    /** @var float Alto del renglón más alto de la tabla (lo mide render() antes de la primera hoja). */
    private $alto_maximo_de_renglon;

    /** @var float Dónde arrancan los renglones en una hoja nueva (lo calcula decidir_la_hoja()). */
    private $y_de_los_renglones;

    /**
     * Prepara el PDF SIN dibujar nada ni terminar el proceso.
     *
     * @param \App\Models\Sale  $sale
     * @param PdfColumnProfile  $perfil         Perfil de venta con `page_layout`.
     * @param mixed             $afip_ticket_id El comprobante pedido (factura), o null.
     */
    public function __construct($sale, PdfColumnProfile $perfil, $afip_ticket_id = null)
    {
        $this->sale = $sale;
        $this->perfil = $perfil;
        $this->user = User::find($sale->user_id);

        /**
         * Hoja y geometría (plan §4.1): página W × H vertical, margen M (0..20) en los cuatro
         * lados, ancho útil = imprimible − 2M centrado en la hoja. La cuenta es la del motor, la
         * misma que usan el presupuesto y el pedido.
         */
        $hoja = MotorDeCajasPdf::geometria_de_la_hoja($perfil);

        $this->pdf_x0 = $hoja['x0'];
        $this->pdf_ancho_util = $hoja['ancho_util'];
        $this->pdf_y0 = $hoja['y0'];
        $this->limite_inferior = $hoja['limite_inferior'];

        parent::__construct('P', 'mm', [$hoja['ancho_de_hoja'], $hoja['alto_de_hoja']]);
        $this->SetAutoPageBreak(false);
        $this->SetMargins($hoja['x0'], $hoja['margen'], $hoja['margen_derecho']);

        $this->b = 0;
        $this->line_height = 5;
        $this->renglones_en_hoja = 0;
        $this->rendered = false;
        $this->alto_del_pie = null;
        $this->zona_superior_en_cada_hoja = null;
        $this->dibujando_la_zona_superior = false;
        $this->tabla_en_la_hoja = false;
        $this->alto_maximo_de_renglon = 0;
        $this->y_de_los_renglones = 0;
        $this->columnas = $this->columnas_del_perfil($perfil);

        /** Lo que lee el encabezado, con las mismas reglas que `NewSalePdf`. */
        $this->use_current_date = (bool) $perfil->use_current_date;
        $this->logo_size_mm = 35;
        if (! is_null($perfil->logo_size_mm) && $perfil->logo_size_mm !== '' && (int) $perfil->logo_size_mm > 0) {
            $this->logo_size_mm = (int) $perfil->logo_size_mm;
        } elseif ($this->user && $this->user->pdf_image_size) {
            $this->logo_size_mm = (int) $this->user->pdf_image_size;
        }

        /**
         * Factura de ARCA: como en `NewSalePdf`, el comprobante se busca SOLO entre los de esta
         * venta. Un perfil fiscal sin comprobante resoluble sale como remito y sin los bloques
         * fijos (mismo criterio que el PDF de siempre).
         */
        $this->es_perfil_fiscal = (bool) $perfil->is_afip_ticket;
        $this->afip_ticket = null;
        $this->ticket_info_helper = null;
        $this->afip_helper = null;
        if ($this->es_perfil_fiscal) {
            $this->afip_ticket = TicketInfoHelper::resolve_afip_ticket_for_sale($sale, $afip_ticket_id);
            $this->ticket_info_helper = new TicketInfoHelper($this->afip_ticket, $sale, $this->user);
            $this->afip_helper = $this->ticket_info_helper->afip_helper();
        }
        $this->es_fiscal = $this->es_perfil_fiscal
            && ! is_null($this->ticket_info_helper)
            && $this->ticket_info_helper->has_afip_context();

        /** header_layout: el del perfil o el default; en un perfil fiscal, con los campos obligatorios. */
        $this->header_layout = ! empty($perfil->header_layout)
            ? $perfil->header_layout
            : PdfColumnProfile::default_header_layout($this->es_perfil_fiscal);
        if ($this->es_perfil_fiscal) {
            $this->header_layout = AfipPdfHelper::enforce_fiscal_required_fields($this->header_layout);
        }

        /**
         * El diseño pasa otra vez por normalizar() (defensivo: lo que está en la base pudo guardarse
         * con otra versión) y por asegurar_fijos() con el fiscal REAL: un perfil puede cambiar de
         * "Es factura de ARCA" desde el formulario sin pasar por el diseñador.
         */
        $diseno = DisenoDePaginaPdf::normalizar($perfil->page_layout);
        $this->diseno = DisenoDePaginaPdf::asegurar_fijos(is_null($diseno) ? DisenoDePaginaPdf::vacio() : $diseno, $this->es_fiscal);

        $this->pie_en_cada_hoja = (bool) $perfil->show_totals_on_each_page;

        /**
         * Con la factura de ARCA resuelta la plata sigue el camino FISCAL de `NewSalePdf`: renglones
         * siempre descriptivos (el modo "simple" del perfil es solo del remito) y el Sub Total con
         * la condición fiscal. Lo decide TotalesDeVentaPdf con $this->es_fiscal.
         */
        $this->fuente = new CamposDeVentaPdf(
            $sale,
            $this->user,
            $this->use_current_date,
            $perfil->discount_display_mode === 'simple' ? 'simple' : 'descriptivo',
            $this->es_fiscal
        );
        $this->motor = new MotorDeCajasPdf($this->fuente, $this->pdf_x0, $this->pdf_ancho_util, $this->bloques_fijos());
    }

    /**
     * El perfil con el que se imprime esta venta, SOLO si tiene diseño de página; null si la venta
     * sale con el PDF de siempre.
     *
     * Resuelve el perfil con LAS MISMAS reglas que `NewSalePdf` (mismo dueño, mismo "solo fiscales
     * con factura / solo remitos sin factura", mismo default de la tienda): si las reglas fueran
     * otras, el diseño de un perfil podría salir en una venta que el PDF de siempre imprimiría con
     * otro perfil.
     *
     * @param \App\Models\Sale $sale
     * @param mixed            $profile_id     `?pdf_column_profile_id=`
     * @param mixed            $afip_ticket_id `?afip_ticket_id=` (o el detectado para la tienda)
     * @param string|null      $origin         `?origin=` ('tienda' prioriza is_default_tienda)
     * @return PdfColumnProfile|null
     */
    public static function perfil_con_diseno($sale, $profile_id, $afip_ticket_id, $origin)
    {
        $perfil = PdfColumnService::get_profile_for_print(
            $sale->user_id,
            'sale',
            $profile_id,
            $afip_ticket_id ? true : false,
            $origin === 'tienda' ? 'is_default_tienda' : null
        );

        return DisenoDePaginaPdf::tiene_diseno($perfil) ? $perfil : null;
    }

    /**
     * Arma el PDF con el diseño y devuelve la instancia ya dibujada, o null si el diseño falló.
     *
     * El link de la venta lo abre también el CLIENTE FINAL (WhatsApp, la tienda). Si el diseño
     * tirara una excepción con un dato raro, vería un 500 donde antes veía un PDF: con null, el
     * controlador cae al PDF de siempre. La falla NO se traga: report() la deja en el log.
     * Nada salió al navegador hasta acá (render() y Output('S') arman todo en memoria).
     *
     * `new static`: un test puede pedirle el respaldo a una subclase que falla a propósito.
     *
     * @param \App\Models\Sale $sale
     * @param PdfColumnProfile $perfil
     * @param mixed            $afip_ticket_id
     * @return SaleLayoutPdf|null
     */
    public static function try_render($sale, PdfColumnProfile $perfil, $afip_ticket_id = null)
    {
        try {
            /**
             * Una hoja con menos ancho útil que el mínimo (papel de 20 mm con margen de 10: la API
             * lo acepta) no se dibuja con cajas: sale el PDF de siempre (ver ANCHO_UTIL_MINIMO).
             */
            if (MotorDeCajasPdf::geometria_de_la_hoja($perfil)['ancho_util'] < MotorDeCajasPdf::ANCHO_UTIL_MINIMO) {
                return null;
            }

            $pdf = new static($sale, $perfil, $afip_ticket_id);
            $pdf->render();

            /**
             * 🔴 El documento se CIERRA acá, adentro del try. El Footer() de la última hoja (el pie
             * "en cada hoja") y el cierre de FPDF corren en Close(), y sin esto Close() recién corría
             * en el Output() de emit(), afuera de este respaldo: una excepción ahí era un 500 para
             * el cliente final en vez del PDF de siempre. Output('S') cierra y deja los bytes
             * listos; emit() solo los manda.
             */
            $pdf->Output('S');

            return $pdf;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Arma todas las hojas. Se puede llamar una sola vez.
     *
     * @return void
     */
    public function render()
    {
        if ($this->rendered) {
            return;
        }
        $this->rendered = true;

        /** Precarga marca/categoría/subcategoría/proveedor para las columnas de relación (como NewSalePdf). */
        PdfColumnService::eager_load_sale_article_relations($this->sale);

        /**
         * Los renglones se piden ANTES de la primera hoja: renglones() les pone las marcas de
         * tipo (is_article, is_service...) que lee AfipItemCalculator para las columnas fiscales,
         * igual que NewSalePdf::items() antes de dibujar la tabla.
         */
        $renglones = $this->fuente->totales()->renglones();

        /** Lo usa decidir_la_hoja(), que corre en el Header() de la primera hoja. */
        $this->alto_maximo_de_renglon = $this->alto_del_renglon_mas_alto($renglones);

        $this->AddPage();

        if ($this->dibujando_la_zona_superior) {
            $this->dibujar_la_zona_superior_de_la_primera_hoja();
        }

        $index = 1;
        foreach ($renglones as $renglon) {
            $this->dibujar_renglon($index, $renglon[1]);
            $index++;
        }

        if (! $this->pie_en_cada_hoja) {
            $this->dibujar_pie_en_la_ultima_hoja();
        }
    }

    /**
     * Manda el PDF al navegador y termina el proceso. SOLO lo llama el controlador: un test que
     * llegue acá se lleva puesto a PHPUnit (usar `render()` + `Output('S')`).
     *
     * Viniendo de try_render() el documento ya está CERRADO (lo cerró su Output('S'), adentro del
     * respaldo): este Output() no dibuja nada más —el Close() de FPDF no hace nada con el
     * documento cerrado— y solo manda esos mismos bytes con los headers de FPDF de siempre
     * (Content-Type: application/pdf; Content-Disposition: inline; filename="doc.pdf";
     * Cache-Control: private, max-age=0, must-revalidate; Pragma: public).
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
     * ¿El pie se dibuja en cada hoja? Es el "Mostrar pie de página en cada hoja" del perfil, salvo
     * que render() haya tenido que dibujarlo solo en la última porque no dejaba lugar para los
     * renglones (ver el comentario en render()).
     *
     * @return bool
     */
    public function pie_en_cada_hoja()
    {
        return $this->pie_en_cada_hoja;
    }

    /**
     * Encabezado de cada hoja: el del emisor SIN el bloque del cliente, la zona de arriba del diseño
     * y el encabezado de la tabla. Lo dispara FPDF en cada AddPage().
     *
     * @return void
     */
    public function Header()
    {
        $this->renglones_en_hoja = 0;
        $this->tabla_en_la_hoja = false;

        if ($this->es_fiscal) {
            AfipPdfHelper::header($this, $this->afip_ticket, $this->sale, $this->user, $this->header_layout, ['sin_receptor' => true]);
        } else {
            AfipPdfHelper::header_comercial($this, $this->sale, $this->user, $this->header_layout, null, ['sin_receptor' => true]);
        }

        if (is_null($this->zona_superior_en_cada_hoja)) {
            $this->decidir_la_hoja($this->y);
        }

        /**
         * Hoja de la zona de arriba de la primera hoja (o una a la que la zona siguió porque es
         * más alta que la hoja): la zona la dibuja render() debajo de este encabezado, y el de la
         * tabla va recién donde la zona termina.
         */
        if ($this->dibujando_la_zona_superior) {
            return;
        }

        if ($this->zona_superior_en_cada_hoja) {
            /** Zona de arriba, 2 mm debajo del encabezado; si no dibuja nada, no deja el hueco. */
            $y = $this->y;
            $alto = $this->motor->dibujar_zona($this, $this->diseno['superior'], $y + self::SEPARACION_DE_LA_ZONA_SUPERIOR);
            if ($alto <= 0) {
                $this->y = $y;
            }
        }

        $this->encabezado_de_la_tabla();
    }

    /**
     * El encabezado gris de la tabla, en la x de la hoja.
     *
     * @return void
     */
    private function encabezado_de_la_tabla()
    {
        AfipPdfHelper::table_header($this, $this->campos_de_la_tabla(), $this->pdf_x0);
        $this->tabla_en_la_hoja = true;
    }

    /**
     * Decide, en el Header() de la primera hoja (ya con el encabezado del emisor dibujado, que es
     * el mismo en todas las hojas), qué se repite en cada hoja. Cambiar los modos acá es seguro:
     * nada debajo del encabezado se dibujó todavía y el Footer() de la hoja 1 recién corre en el
     * próximo AddPage() o en el Output().
     *
     * 🔴 1. "PIE EN CADA HOJA" QUE NO DEJA LUGAR PARA NINGÚN RENGLÓN: va solo en la última.
     * El pie "en cada hoja" se dibuja en Footer(), donde FPDF no deja agregar una hoja: su alto se
     * reserva restándolo del límite de los renglones (limite_de_renglones()). Si ni siquiera en
     * una hoja sin la zona de arriba entra el renglón MÁS ALTO de la tabla junto con el pie (con
     * uno "típico" no alcanza: un nombre que ocupa dos líneas lo mete igual y se sale), la guarda
     * de "al menos un renglón por hoja" mete uno igual y el pie se dibuja AFUERA del papel en cada
     * hoja (medido el 1/10/2026 en A5: hasta 217 mm en una hoja de 210, en las 33 hojas). Entonces
     * el pie va SOLO en la última hoja, el camino que salta de hoja antes del pie y lo parte entre
     * filas si no entra.
     *
     * 🔴 2. ZONA DE ARRIBA QUE NO DEJA LUGAR PARA UN RENGLÓN: va solo en la primera hoja.
     * La zona de arriba se dibujaba en el Header() de cada hoja sin medirla, y dibujar_renglon()
     * dibuja siempre el primer renglón de cada hoja: con una zona más alta que el lugar libre, cada
     * renglón salía en una hoja propia y por DEBAJO del papel (medido el 1/10/2026: A5, margen 5,
     * 8 renglones y una zona de dos cajas de 12 columnas con 33 campos daban 10 hojas, con todos
     * los renglones en y = 234,8 en una hoja de 210). Si encabezado + zona + encabezado de la
     * tabla no dejan lugar para el renglón más alto (y, con el pie en cada hoja, para el pie), la
     * zona va SOLO en la primera hoja y las siguientes llevan encabezado + encabezado de la tabla.
     * La dibuja render() (dibujar_la_zona_superior_de_la_primera_hoja()), no este Header(): si
     * tampoco entra entera en la primera hoja, sigue en la segunda, y eso pide AddPage(), que FPDF
     * no permite desde Header(). La zona cede antes que el pie: el pie en cada hoja es lo que el
     * usuario pidió con su tilde; repetir los datos del cliente en cada hoja es el default.
     *
     * @param float $y_del_encabezado Dónde terminó el encabezado del emisor.
     * @return void
     */
    private function decidir_la_hoja($y_del_encabezado)
    {
        $tabla_y_renglon = AfipPdfHelper::alto_de_table_header() + $this->alto_maximo_de_renglon;

        if ($this->pie_en_cada_hoja && $y_del_encabezado + $tabla_y_renglon + $this->alto_del_pie() > $this->limite_inferior) {
            $this->pie_en_cada_hoja = false;
        }

        $alto_de_la_zona = $this->motor->medir_zona($this, $this->diseno['superior']);
        $zona = $alto_de_la_zona > 0 ? self::SEPARACION_DE_LA_ZONA_SUPERIOR + $alto_de_la_zona : 0;
        $pie = $this->pie_en_cada_hoja ? $this->alto_del_pie() : 0;

        $this->zona_superior_en_cada_hoja = $zona <= 0
            || $y_del_encabezado + $zona + $tabla_y_renglon + $pie <= $this->limite_inferior;
        $this->dibujando_la_zona_superior = ! $this->zona_superior_en_cada_hoja;

        /** Dónde arrancan los renglones en una hoja nueva (lo usa el pie de la última hoja). */
        $this->y_de_los_renglones = $y_del_encabezado
            + ($this->zona_superior_en_cada_hoja ? $zona : 0)
            + AfipPdfHelper::alto_de_table_header();
    }

    /**
     * La zona de arriba SOLO en la primera hoja (ver decidir_la_hoja()): debajo del encabezado
     * del emisor y, si no entra entera, sigue en la hoja siguiente (entre filas, nunca partiendo
     * una caja). Donde termina va el encabezado de la tabla, si debajo entra el renglón más alto
     * (con el pie en cada hoja, también el pie); si no, los renglones arrancan en la hoja
     * siguiente, que ya trae su encabezado de la tabla.
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
            $this->limite_inferior
        );

        $this->dibujando_la_zona_superior = false;

        $pie = $this->pie_en_cada_hoja ? $this->alto_del_pie() : 0;
        if ($this->y + AfipPdfHelper::alto_de_table_header() + $this->alto_maximo_de_renglon + $pie > $this->limite_inferior) {
            $this->AddPage();

            return;
        }

        $this->encabezado_de_la_tabla();
    }

    /**
     * Con "Mostrar pie de página en cada hoja", el pie va debajo del último renglón de CADA hoja,
     * con los totales FINALES (el motor los pide a la fuente, que no depende de cuántos renglones
     * se llevan dibujados). El salto de hoja de los renglones ya le reservó el alto medido.
     *
     * @return void
     */
    public function Footer()
    {
        /**
         * Solo en las hojas con la tabla: una hoja que lleva nada más que la zona de arriba (una
         * zona más alta que la hoja, ver decidir_la_hoja()) no le reservó lugar al pie.
         */
        if ($this->pie_en_cada_hoja && $this->tabla_en_la_hoja) {
            $this->dibujar_pie();
        }
    }

    /**
     * Pie en la última hoja: si no entra debajo del último renglón, salta de hoja antes. Y si es
     * más alto que lo que queda libre incluso en una hoja nueva (hoja chica, diseño cargado), las
     * filas que no entran siguen en la hoja siguiente en vez de caerse del papel.
     *
     * @return void
     */
    private function dibujar_pie_en_la_ultima_hoja()
    {
        $alto = $this->alto_del_pie();

        if ($alto <= 0) {
            return;
        }

        /**
         * Salta de hoja si el pie no entra y una hoja nueva le da más lugar: si esta tiene renglones,
         * o si tiene la zona de arriba que las siguientes no llevan (ver decidir_la_hoja()). Una
         * hoja que solo repetiría lo que ya hay no ayuda: nunca una hoja con el encabezado y nada.
         */
        $hoja_nueva_con_mas_lugar = $this->renglones_en_hoja > 0 || $this->y > $this->y_de_los_renglones + 0.001;
        if ($hoja_nueva_con_mas_lugar && $this->y + $alto > $this->limite_inferior) {
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
            $this->limite_inferior
        );
    }

    /**
     * Dibuja la zona pie 3 mm debajo de donde quedó la hoja (el pie "en cada hoja", desde Footer():
     * ahí no se puede saltar de hoja, por eso su alto se reserva en el salto de los renglones).
     *
     * @return void
     */
    private function dibujar_pie()
    {
        if ($this->alto_del_pie() <= 0) {
            return;
        }

        $this->motor->dibujar_zona($this, $this->diseno['pie'], $this->y + self::SEPARACION_DEL_PIE);
    }

    /**
     * Alto del pie (separación + zona), medido UNA vez con la misma rutina que lo dibuja.
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
     * Hasta dónde puede llegar un renglón de la tabla: el límite de la hoja y, si el pie va en
     * cada hoja, menos el alto del pie.
     *
     * @return float
     */
    private function limite_de_renglones()
    {
        if (! $this->pie_en_cada_hoja) {
            return $this->limite_inferior;
        }

        return $this->limite_inferior - $this->alto_del_pie();
    }

    /**
     * Los bloques fijos de la factura de ARCA, como los dibuja el PDF de siempre: el receptor
     * (`AfipPdfHelper::receptor_fiscal()`) y el cuadro de importes + QR + CAE
     * (`AfipPdfHelper::footer()`, que se mide con `estimate_footer_height()`). Sin comprobante
     * resuelto no hay fijos (asegurar_fijos() ya los sacó del diseño).
     *
     * @return array<string, callable>
     */
    private function bloques_fijos()
    {
        if (! $this->es_fiscal) {
            return [];
        }

        return [
            /**
             * El bloque del cliente se le cambia el ancho (6 a 12 columnas): con menos de 12 el
             * motor le pasa la x y el ancho de su celda, y se mide y se dibuja en esa geometría
             * (en_la_celda()); con 12, $x y $ancho vienen en null y va a lo ancho, como siempre.
             */
            CatalogoDeCamposPdf::FIJO_AFIP_RECEPTOR => function ($pdf, $item, $dibujar, $x = null, $ancho = null, $alto = null) {
                return $this->en_la_celda($x, $ancho, function () use ($pdf, $dibujar, $alto) {
                    if (! $dibujar) {
                        return AfipPdfHelper::estimate_receptor_height($pdf, $this->sale);
                    }

                    $y = $pdf->y;
                    AfipPdfHelper::receptor_fiscal($pdf, $this->sale, $alto);

                    return $pdf->y - $y;
                });
            },
            CatalogoDeCamposPdf::FIJO_AFIP_PIE => function ($pdf, $item, $dibujar) {
                $importes = ! array_key_exists('importes', $item) || (bool) $item['importes'];

                if (! $dibujar) {
                    return AfipPdfHelper::estimate_footer_height($this->afip_ticket, $this->sale, $importes);
                }

                $y = $pdf->y;
                AfipPdfHelper::footer($pdf, $this->afip_ticket, $this->sale, $this->user, $this->afip_helper, $importes);

                return $pdf->y - $y;
            },
        ];
    }

    /**
     * Corre $hacer con la geometría de una celda de la grilla: AfipPdfHelper mide y dibuja con
     * `pdf_x0` y `pdf_ancho_util` (y con ellos repone los márgenes después de un Write()), así que
     * mientras dura se los apunta a la celda, y después se vuelven a la hoja, márgenes incluidos,
     * pase lo que pase. Con $x o $ancho null corre tal cual (a lo ancho de la hoja).
     *
     * @param float|null $x
     * @param float|null $ancho
     * @param callable   $hacer
     * @return mixed Lo que devuelve $hacer.
     */
    private function en_la_celda($x, $ancho, callable $hacer)
    {
        if (is_null($x) || is_null($ancho)) {
            return $hacer();
        }

        $x0_de_la_hoja = $this->pdf_x0;
        $ancho_de_la_hoja = $this->pdf_ancho_util;
        $this->pdf_x0 = $x;
        $this->pdf_ancho_util = $ancho;

        try {
            return $hacer();
        } finally {
            $this->pdf_x0 = $x0_de_la_hoja;
            $this->pdf_ancho_util = $ancho_de_la_hoja;
            $this->SetLeftMargin($x0_de_la_hoja);
            $this->SetRightMargin($this->GetPageWidth() - $x0_de_la_hoja - $ancho_de_la_hoja);
        }
    }

    /**
     * Los valores de las celdas de un renglón y su alto (el de la celda con wrap más alta, como
     * mínimo una línea). Es la MISMA cuenta para dibujar el renglón y para decidir si el pie
     * "en cada hoja" deja lugar para los renglones (render()).
     *
     * @param int    $index
     * @param object $item
     * @return array{valores: array<int, string>, alto: float}
     */
    private function medir_renglon($index, $item)
    {
        $this->SetFont('Arial', '', 8);

        $alto = $this->line_height;
        $valores = [];

        foreach ($this->columnas as $i => $columna) {
            $valores[$i] = (string) $this->valor_de_columna($columna, $index, $item);

            if (! empty($columna['wrap_content'])) {
                $alto = max($alto, max(1, $this->NbLines($columna['width'], $valores[$i])) * $this->line_height);
            }
        }

        return ['valores' => $valores, 'alto' => $alto];
    }

    /**
     * Alto del renglón más alto de la tabla (una línea si la venta no tiene renglones).
     *
     * @param array $renglones Los de TotalesDeVentaPdf::renglones().
     * @return float
     */
    private function alto_del_renglon_mas_alto($renglones)
    {
        $alto = $this->line_height;

        $index = 1;
        foreach ($renglones as $renglon) {
            $alto = max($alto, $this->medir_renglon($index, $renglon[1])['alto']);
            $index++;
        }

        return $alto;
    }

    /**
     * Un renglón de la tabla con las columnas del perfil. El alto se calcula ANTES de decidir el
     * salto de hoja (como `ProfileDocumentPdf`): decidir por la posición de arranque deja pasar
     * una fila alta que empieza apenas arriba del límite.
     *
     * @param int    $index
     * @param object $item
     * @return void
     */
    private function dibujar_renglon($index, $item)
    {
        $medida = $this->medir_renglon($index, $item);
        $valores = $medida['valores'];
        $alto = $medida['alto'];

        if ($this->renglones_en_hoja > 0 && $this->y + $alto > $this->limite_de_renglones()) {
            $this->AddPage();
            $this->SetFont('Arial', '', 8);
        }

        $y = $this->y;
        $x = $this->pdf_x0;

        foreach ($this->columnas as $i => $columna) {
            $this->x = $x;
            $this->y = $y;

            if (! empty($columna['wrap_content'])) {
                $this->MultiCell($columna['width'], $this->line_height, $valores[$i], $this->b, 'L', false);
            } else {
                $this->Cell($columna['width'], $alto, $this->truncate_text_to_width($valores[$i], $columna['width']), $this->b, 0, 'C');
            }

            $x += $columna['width'];
        }

        $this->x = $this->pdf_x0;
        $this->y = $y + $alto;
        $this->Line($this->pdf_x0, $this->y, $this->pdf_x0 + $this->pdf_ancho_util, $this->y);
        $this->renglones_en_hoja++;
    }

    /**
     * Valor de una celda, con el mismo contexto que le arma `NewSalePdf` al resolver.
     *
     * @param array  $columna
     * @param int    $index
     * @param object $item
     * @return mixed
     */
    private function valor_de_columna($columna, $index, $item)
    {
        return PdfColumnService::resolve_value($columna['value_resolver'], [
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
     * Columnas visibles del perfil, ordenadas por el `order` del pivot (como `NewSalePdf`). Una
     * columna sin ancho no se dibuja: con ancho 0 FPDF estira la celda hasta el margen derecho.
     *
     * @param PdfColumnProfile $perfil
     * @return array<int, array>
     */
    private function columnas_del_perfil(PdfColumnProfile $perfil)
    {
        if (! $perfil->relationLoaded('pdf_column_options')) {
            $perfil->load('pdf_column_options');
        }

        $columnas = [];
        foreach ($perfil->pdf_column_options as $option) {
            $visible = isset($option->pivot->visible) ? (bool) $option->pivot->visible : true;
            $ancho = isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width;

            if (! $visible || $ancho <= 0) {
                continue;
            }

            $columnas[] = [
                'label' => $option->label,
                'value_resolver' => $option->value_resolver,
                'order' => isset($option->pivot->order) ? (int) $option->pivot->order : 0,
                'width' => $ancho,
                'wrap_content' => isset($option->pivot->wrap_content) ? (bool) $option->pivot->wrap_content : false,
            ];
        }

        usort($columnas, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $columnas;
    }

    /**
     * Mapa etiqueta => ancho del encabezado gris de la tabla (como `NewSalePdf::getFields()`).
     *
     * @return array<string, int>
     */
    private function campos_de_la_tabla()
    {
        $campos = [];
        foreach ($this->columnas as $columna) {
            $campos[$columna['label']] = $columna['width'];
        }

        return $campos;
    }

    /**
     * Cantidad de líneas que ocupa un texto en un ancho con la fuente actual (mismo corte que
     * MultiCell() de FPDF). Copia de `ProfileDocumentPdf::NbLines()`.
     *
     * @param float  $w
     * @param string $txt
     * @return int
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
     * Acorta el texto al ancho de la celda con puntos suspensivos (copia de
     * `NewSalePdf::truncate_text_to_width()`).
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
}
