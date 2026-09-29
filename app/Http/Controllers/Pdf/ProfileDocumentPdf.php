<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\PdfDocumentSource;
use App\Http\Controllers\Helpers\PdfDocumentSetupHelper;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
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
 */
class ProfileDocumentPdf extends fpdf
{
    /** Ancho útil de la hoja A4 (210) menos el margen de 5mm de cada lado. */
    const CONTENT_WIDTH = 200;

    /** Límite Y (mm) hasta donde puede llegar una fila de la tabla antes de saltar de hoja. */
    const ITEMS_BOTTOM_Y = 285;

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
     * Prepara el PDF SIN dibujar nada ni terminar el proceso.
     *
     * @param PdfDocumentSource $source  Comprobante (presupuesto o pedido) ya adaptado.
     * @param PdfColumnProfile  $profile Diseño de PDF con el que se imprime.
     */
    public function __construct(PdfDocumentSource $source, PdfColumnProfile $profile)
    {
        parent::__construct();
        $this->SetAutoPageBreak(false);

        $this->source = $source;
        $this->user = User::find($source->owner_id());
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
        $this->get_totals_rows();

        $this->AddPage();

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
     * Nada salió al navegador hasta acá: `render()` arma todo en memoria y recién `emit()` llama a
     * `Output()`. Por eso volver atrás a mitad de camino es seguro.
     *
     * @param PdfDocumentSource $source  Presupuesto o pedido adaptado.
     * @param PdfColumnProfile  $profile Diseño elegido.
     * @return ProfileDocumentPdf|null
     */
    public static function try_render(PdfDocumentSource $source, PdfColumnProfile $profile)
    {
        try {
            $pdf = new self($source, $profile);
            $pdf->render();

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
        $this->SetFont('Arial', '', 8);

        $row_height = $this->line_height;
        $values = [];
        $wrap_lines = [];
        $image_path = null;
        $image_box = null;

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
                    $this->count_discarded_image($image_path, 'no es una imagen que se pueda leer');
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

        /** Guarda contra un bucle de hojas vacías si una sola fila fuera más alta que la hoja. */
        if ($this->rows_on_page > 0 && $this->y + $row_height > self::ITEMS_BOTTOM_Y) {
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
        $this->Line($this->start_x, $this->y, 210 - $this->start_x, $this->y);
        $this->rows_on_page++;
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
