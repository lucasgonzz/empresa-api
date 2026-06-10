<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use App\Http\Controllers\Helpers\GeneralHelper as AppGeneralHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\PdfColumnProfile;
use App\Services\PdfColumnService;
use fpdf;

require __DIR__.'/../CommonLaravel/fpdf/fpdf.php';

/**
 * PDF tabular de artículos con diseño tipo catálogo moderno.
 *
 * Características visuales:
 *  - Barra de título gris con esquinas redondeadas y nombre del catálogo.
 *  - Encabezado de columnas con fondo azul oscuro y texto blanco.
 *  - Filas alternadas (azul muy claro / blanco) para facilitar la lectura.
 *  - Separadores sutiles entre filas.
 *  - Pie de página con barra de color, número de página y observaciones.
 *
 * Respeta las columnas y anchos configurados en PdfColumnProfile.
 */
class ArticleTablePdf extends fpdf
{
    // ── Paleta de colores ─────────────────────────────────────────────────────

    /** Fondo de la barra de título del catálogo y pie de página (gris slate suave). */
    const COLOR_TITLE_BAR   = [90, 98, 110];

    /** Borde exterior de bloques redondeados (gris claro). */
    const COLOR_TABLE_BORDER = [148, 163, 184];

    /** Radio de esquinas redondeadas en mm. */
    const BORDER_RADIUS     = 2.5;

    /** Fondo del encabezado de columnas de la tabla (azul intenso). */
    const COLOR_HEADER_BG   = [30, 58, 138];

    /** Texto del encabezado de columnas y del pie de página (blanco). */
    const COLOR_HEADER_TEXT = [255, 255, 255];

    /** Fondo de filas impares (azul muy claro). */
    const COLOR_ROW_ODD     = [239, 246, 255];

    /** Fondo de filas pares (blanco puro). */
    const COLOR_ROW_EVEN    = [255, 255, 255];

    /** Color de las líneas separadoras entre filas (gris azulado suave). */
    const COLOR_ROW_BORDER  = [203, 213, 225];

    /** Color del texto de datos de la tabla (azul casi negro). */
    const COLOR_TEXT_DARK   = [15, 23, 42];

    // ── Constructor ───────────────────────────────────────────────────────────

    /**
     * Inicializa el PDF, configura propiedades de diseño y genera el output.
     *
     * @param \App\Models\PdfColumnProfile              $pdf_column_profile  Plantilla de columnas activa.
     * @param \Illuminate\Support\Collection|array      $articles            Artículos a listar.
     */
    public function __construct(PdfColumnProfile $pdf_column_profile, $articles)
    {
        /** Perfil de columnas activo que determina qué campos se muestran y en qué orden. */
        $this->pdf_column_profile = $pdf_column_profile;

        /** Colección de artículos a imprimir. */
        $this->articles = $articles;

        /**
         * Lista de precios opcional (`?price_type_id=`) para columna precio final del pivot.
         * Misma convención que ArticleOfferSheetPdf y ArticleTicketPdf.
         */
        $this->price_type_id = request()->query('price_type_id');

        /** Usuario autenticado para reglas de listas de precio al resolver columnas. */
        $this->user = UserHelper::getFullModel();

        /** Texto de observaciones del pie de página (vacío si no se configuró). */
        $this->footer_text = $pdf_column_profile->footer_text ?: '';

        /**
         * Ruta local para FPDF de la imagen de cabecera del perfil (webp → jpg).
         * Se resuelve una sola vez para evitar conversión repetida en cada página.
         */
        $this->header_image_fpdf_path = AppGeneralHelper::pdf_image_path($pdf_column_profile->header_image_url);

        /** Margen horizontal del PDF en mm (por defecto 5 mm). */
        $this->margin_mm = (int) ($pdf_column_profile->margin_mm ?? 5);

        /**
         * Ancho de hoja del perfil (A4 portrait = 210 mm); fallback 210 si no está definido.
         */
        $this->paper_width_mm = (int) ($pdf_column_profile->paper_width_mm ?? 210);

        /** Posición X de inicio de la tabla (margen izquierdo). */
        $this->start_x = $this->margin_mm;

        /** Posición X final de la tabla (margen derecho). */
        $this->table_right_x = $this->paper_width_mm - $this->margin_mm;

        /**
         * Columnas visibles normalizadas al ancho útil de la tabla.
         * Debe calcularse después de start_x y table_right_x.
         */
        $this->profile_columns = $this->normalize_column_widths($this->get_profile_columns());

        /**
         * Índice de fila global para calcular el color alternado de fondo.
         * Se incrementa en cada fila impresa, incluso al cambiar de página.
         */
        $this->row_index = 0;

        /**
         * Tamaño de letra uniforme (pt) para todos los encabezados de columna (th).
         */
        $this->table_header_font_size = $this->resolve_table_header_font_size(
            $pdf_column_profile->table_header_font_size ?? null
        );

        parent::__construct();
        $this->SetAutoPageBreak(false);

        /** Parámetro de borde para celdas: 0 = sin borde (el diseño usa Rect para fondos). */
        $this->b = 0;

        /** Altura mínima de fila en mm (filas de una sola línea sin imagen). */
        $this->line_height = 4.5;

        /** Altura base del encabezado de columnas en mm. */
        $this->table_header_line_height = 7;

        /** Alias para total de páginas; FPDF lo reemplaza al hacer Output. */
        $this->AliasNbPages('{nb}');

        /** Posición Y superior del bloque de tabla en la página actual (para borde redondeado). */
        $this->page_table_top_y = null;

        /** Posición Y inferior del contenido de tabla en la página actual. */
        $this->page_table_bottom_y = null;

        $this->AddPage();
        $this->print_items();
        $this->Output();
        exit;
    }

    // ── Columnas del perfil ───────────────────────────────────────────────────

    /**
     * Devuelve las columnas visibles del perfil ordenadas por su pivot.order.
     *
     * Lee la relación pdf_column_options si no está cargada en memoria
     * y filtra las que tienen visible=false en el pivot.
     *
     * @return array  Array de maps con keys: label, value_resolver, order, width, wrap_content.
     */
    private function get_profile_columns()
    {
        if (! $this->pdf_column_profile->relationLoaded('pdf_column_options')) {
            $this->pdf_column_profile->load('pdf_column_options');
        }

        /** Acumula las columnas habilitadas antes de ordenarlas. */
        $rows = [];

        foreach ($this->pdf_column_profile->pdf_column_options as $option) {
            /** Consideramos visible=true por defecto si el pivot no trae el campo. */
            $visible = isset($option->pivot->visible) ? (bool) $option->pivot->visible : true;
            if (! $visible) {
                continue;
            }

            $rows[] = [
                'label'          => $option->label,
                'value_resolver' => $option->value_resolver,
                'order'          => isset($option->pivot->order) ? (int) $option->pivot->order : 0,
                'width'          => isset($option->pivot->width) ? (int) $option->pivot->width : (int) $option->default_width,
                'wrap_content'   => isset($option->pivot->wrap_content) ? (bool) $option->pivot->wrap_content : false,
                'font_size'      => isset($option->pivot->font_size) ? (int) $option->pivot->font_size : null,
                'text_align'     => isset($option->pivot->text_align) ? (string) $option->pivot->text_align : null,
            ];
        }

        usort($rows, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        return $rows;
    }

    /**
     * Ajusta los anchos de columna para que la suma coincida con el ancho útil de la tabla.
     * Escala proporcionalmente y asigna el redondeo restante a la última columna.
     *
     * @param  array  $columns  Columnas del perfil con width en mm.
     * @return array            Mismas columnas con width normalizado.
     */
    private function normalize_column_widths(array $columns)
    {
        if (! count($columns)) {
            return $columns;
        }

        /** Ancho total disponible para la tabla entre márgenes del perfil. */
        $usable_width = $this->table_right_x - $this->start_x;

        /** Suma de anchos configurados en el perfil antes de escalar. */
        $total_width = 0;
        foreach ($columns as $column) {
            $total_width += max(1, (int) $column['width']);
        }

        if ($total_width <= 0 || $total_width === $usable_width) {
            return $columns;
        }

        /** Factor de escala para ocupar todo el ancho útil sin dejar huecos laterales. */
        $scale_factor = $usable_width / $total_width;
        $normalized = [];
        $assigned_width = 0;
        $columns_count = count($columns);

        foreach ($columns as $index => $column) {
            if ($index === $columns_count - 1) {
                /** La última columna absorbe el redondeo para que la suma sea exacta. */
                $width = max(1, $usable_width - $assigned_width);
            } else {
                $width = max(1, (int) round($column['width'] * $scale_factor));
                $assigned_width += $width;
            }

            $normalized[] = array_merge($column, ['width' => $width]);
        }

        return $normalized;
    }

    /**
     * Convierte texto UTF-8 al encoding esperado por FPDF en celdas sin utf8_decode automático.
     *
     * @param  string  $text  Texto en UTF-8.
     * @return string         Texto listo para MultiCell / NbLines.
     */
    private function pdf_text($text)
    {
        return utf8_decode((string) $text);
    }

    /**
     * Devuelve la alineación horizontal de una celda según la configuración del perfil
     * o, si no hay valor guardado, según reglas automáticas legacy por tipo de dato.
     *
     * @param  array  $column  Definición de columna del perfil.
     * @return string          'L', 'C' o 'R' para FPDF Cell / MultiCell.
     */
    private function get_column_text_align(array $column)
    {
        /** Valor configurado en el pivot del perfil (left|center|right). */
        $configured_align = isset($column['text_align']) ? (string) $column['text_align'] : '';
        if (in_array($configured_align, ['left', 'center', 'right'], true)) {
            return $this->text_align_to_fpdf($configured_align);
        }

        /** Resolver de la columna; define el tipo de dato mostrado. */
        $value_resolver = $column['value_resolver'] ?? '';

        /** Precios, costos e identificadores numéricos se alinean a la derecha. */
        if (
            strpos($value_resolver, 'price') !== false
            || strpos($value_resolver, 'cost') !== false
            || $value_resolver === 'row_index'
            || $value_resolver === 'article_id'
            || $value_resolver === 'article_stock'
            || $value_resolver === 'article_iva_percentage'
        ) {
            return 'R';
        }

        /** Textos largos con wrap se alinean a la izquierda para lectura natural. */
        if (! empty($column['wrap_content'])) {
            return 'L';
        }

        return 'C';
    }

    /**
     * Convierte alineación persistida en el perfil al código esperado por FPDF.
     *
     * @param  string  $text_align  left|center|right
     * @return string               L|C|R
     */
    private function text_align_to_fpdf($text_align)
    {
        $map = [
            'left' => 'L',
            'center' => 'C',
            'right' => 'R',
        ];

        return $map[$text_align] ?? 'C';
    }

    /**
     * Resuelve el tamaño de letra del encabezado tabular del perfil (default 8 pt).
     *
     * @param  mixed  $configured_font_size
     * @return int
     */
    private function resolve_table_header_font_size($configured_font_size)
    {
        $font_size = (int) $configured_font_size;
        if ($font_size >= 4 && $font_size <= 24) {
            return $font_size;
        }

        return 8;
    }

    /**
     * Tamaño de fuente en puntos para una columna (default 8 pt).
     *
     * @param  array  $column
     * @return int
     */
    private function get_column_font_size(array $column)
    {
        $font_size = isset($column['font_size']) ? (int) $column['font_size'] : 0;
        if ($font_size >= 4 && $font_size <= 24) {
            return $font_size;
        }

        return 8;
    }

    /**
     * Altura de línea en mm acorde al tamaño de fuente de la columna.
     * Factor bajo para interlineado compacto en celdas con salto de línea.
     *
     * @param  int  $font_size  Tamaño de fuente en puntos.
     * @return float
     */
    private function get_column_line_height($font_size)
    {
        return max(3.5, round($font_size * 0.52, 1));
    }

    /**
     * Imprime texto en una celda con alineación horizontal y vertical centrada en la fila.
     *
     * @param  float   $x
     * @param  float   $start_y
     * @param  int     $width
     * @param  float   $row_height
     * @param  string  $text
     * @param  string  $text_align  L|C|R
     * @param  int     $font_size
     * @param  bool    $wrap_content
     * @return void
     */
    private function print_vertically_centered_text_cell($x, $start_y, $width, $row_height, $text, $text_align, $font_size, $wrap_content)
    {
        if ($width <= 0) {
            return;
        }

        /** Altura de cada línea según el tamaño de fuente activo. */
        $cell_line_height = $this->get_column_line_height($font_size);
        $this->SetFont('Arial', '', $font_size);

        if ($wrap_content) {
            $lines = $this->NbLines($width, $text);
            $content_height = max(1, $lines) * $cell_line_height;
            $cell_y = $start_y + ($row_height - $content_height) / 2;
            $this->SetXY($x, $cell_y);
            $this->MultiCell($width, $cell_line_height, $this->pdf_text($text), 0, $text_align, false);
            return;
        }

        $short_text = $this->truncate_text_to_width($text, $width);
        $cell_y = $start_y + ($row_height - $cell_line_height) / 2;
        $this->SetXY($x, $cell_y);
        $this->Cell($width, $cell_line_height, $short_text, 0, 0, $text_align, false);
    }

    // ── Cabecera de página ────────────────────────────────────────────────────

    /**
     * Cabecera de página: imagen opcional del perfil, barra de título del catálogo
     * y fila de encabezados de columna con fondo azul.
     *
     * Es invocada automáticamente por FPDF al inicio de cada página.
     */
    public function Header()
    {
        $this->x = $this->start_x;
        $this->y = 5;

        /** Reinicia el contorno tabular de la página; se recalcula al dibujar título y filas. */
        $this->page_table_top_y = null;
        $this->page_table_bottom_y = null;

        // Imagen de cabecera configurada en el perfil (opcional)
        if ($this->header_image_fpdf_path && file_exists($this->header_image_fpdf_path)) {
            try {
                $header_dims = $this->get_header_image_dimensions_mm();
                if ($header_dims) {
                    $this->Image(
                        $this->header_image_fpdf_path,
                        $this->start_x,
                        $this->y,
                        $header_dims['width'],
                        $header_dims['height']
                    );
                    $this->y += $header_dims['height'] + 3;
                }
            } catch (\Exception $e) {
                // Si FPDF no puede leer el archivo, se omite la imagen sin cortar el PDF.
            }
        }

        // Barra de título gris con esquinas redondeadas
        $this->print_catalog_title_bar();

        // Fila de encabezados de columna con fondo azul intenso
        if (count($this->profile_columns)) {
            $this->print_styled_table_header($this->profile_columns);
        }
    }

    /**
     * Dibuja la barra de título del catálogo con fondo oscuro, texto blanco
     * y la fecha actual alineada a la derecha.
     *
     * @return void
     */
    private function print_catalog_title_bar()
    {
        /** Altura de la barra de título en mm. */
        $bar_height   = 9;
        $usable_width = $this->table_right_x - $this->start_x;

        /** Posición Y donde comienza la barra de título (independiente del borde de la tabla). */
        $bar_start_y = $this->y;

        // Fondo gris con esquinas redondeadas y borde propio
        $this->SetFillColor(...self::COLOR_TITLE_BAR);
        $this->SetDrawColor(...self::COLOR_TABLE_BORDER);
        $this->SetLineWidth(0.25);
        $this->draw_rounded_rect(
            $this->start_x,
            $bar_start_y,
            $usable_width,
            $bar_height,
            self::BORDER_RADIUS,
            'DF',
            '1111'
        );

        $this->SetTextColor(...self::COLOR_HEADER_TEXT);

        // Texto principal del catálogo; Cell() del FPDF del proyecto ya aplica utf8_decode().
        $this->SetFont('Arial', 'B', 10);
        $this->y = $bar_start_y + 2;
        $this->x = $this->start_x + 3;
        $this->Cell($usable_width - 6, 5, 'CATÁLOGO DE ARTÍCULOS', 0, 0, 'L');

        // Fecha del día alineada a la derecha dentro de la barra
        $this->SetFont('Arial', '', 8);
        $this->y = $bar_start_y + 2;
        $this->x = $this->start_x;
        $this->Cell($usable_width - 3, 5, date('d/m/Y'), 0, 0, 'R');

        // Posicionar y debajo de la barra con espaciado antes del encabezado de columnas
        $this->y = $bar_start_y + $bar_height + 2;
        $this->x = $this->start_x;

        // Restaurar color de texto para el contenido de la tabla
        $this->SetTextColor(...self::COLOR_TEXT_DARK);
    }

    /**
     * Dibuja la fila de encabezados de columna con fondo azul oscuro y texto blanco.
     *
     * Reemplaza el uso de PdfHelper::tableHeader() para aplicar el estilo visual del catálogo.
     *
     * @param  array  $columns  Columnas visibles del perfil con label y width.
     * @return void
     */
    private function print_styled_table_header(array $columns)
    {
        /** Tamaño uniforme para todos los títulos de columna (th). */
        $header_font_size = $this->table_header_font_size;
        $header_line_height = $this->get_column_line_height($header_font_size);
        $h = max($this->table_header_line_height, $header_line_height + 1);

        /** Posición Y inicial de la fila de encabezados en mm. */
        $start_y = $this->y;
        $usable_width = $this->table_right_x - $this->start_x;

        /**
         * El contorno de la tabla arranca aquí (sin incluir la barra de título del catálogo).
         */
        $this->page_table_top_y = $start_y;
        $this->page_table_bottom_y = $start_y + $h;

        /** Fondo azul del encabezado con esquinas superiores redondeadas (coincide con el borde de la tabla). */
        $this->SetFillColor(...self::COLOR_HEADER_BG);
        $this->print_table_area_fill($this->start_x, $start_y, $usable_width, $h, '1100');

        $this->SetTextColor(...self::COLOR_HEADER_TEXT);
        $this->x = $this->start_x;
        $this->SetFont('Arial', 'B', $header_font_size);

        $cell_y = $start_y + ($h - $header_line_height) / 2;

        foreach ($columns as $column) {
            /** Ancho y alineación coherentes con las celdas de datos; tipografía única en todos los th. */
            $width = (int) $column['width'];
            $align = $this->get_column_text_align($column);
            $current_x = $this->x;

            $this->SetXY($current_x, $cell_y);
            $this->Cell($width, $header_line_height, $column['label'], 0, 0, $align, false);
            /** Cell() avanza x; fijamos la posición explícita para la siguiente columna. */
            $this->x = $current_x + $width;
        }

        // Avanzar y al final de la fila de encabezados
        $this->y = $start_y + $h;
        $this->x = $this->start_x;

        // Restaurar colores para las filas de datos
        $this->SetDrawColor(...self::COLOR_ROW_BORDER);
        $this->SetTextColor(...self::COLOR_TEXT_DARK);
        $this->SetLineWidth(0.2);
    }

    // ── Dimensiones de imagen de cabecera ─────────────────────────────────────

    /**
     * Calcula ancho y alto en mm de la imagen de cabecera respetando su proporción.
     * Escala hacia abajo solo si supera el ancho útil entre márgenes.
     *
     * @return array|null  Keys: width, height en mm. null si la imagen no está disponible.
     */
    private function get_header_image_dimensions_mm()
    {
        if (! $this->header_image_fpdf_path || ! file_exists($this->header_image_fpdf_path)) {
            return null;
        }

        /** Ancho máximo disponible para la imagen en mm (entre márgenes del perfil). */
        $max_width_mm = $this->table_right_x - $this->start_x;
        $dims = PdfHelper::coordenadas_y_ancho_de_imagen($this->header_image_fpdf_path, $max_width_mm);

        return [
            'width'  => $dims['width'],
            'height' => $dims['height'],
        ];
    }

    // ── Filas de artículos ────────────────────────────────────────────────────

    /**
     * Itera todos los artículos y delega a print_item_from_profile() para dibujar cada fila.
     *
     * @return void
     */
    private function print_items()
    {
        /** Artículos válidos para saber cuál es la última fila (fondo con esquinas inferiores redondeadas). */
        $printable_articles = [];
        foreach ($this->articles as $article) {
            if ($article) {
                $printable_articles[] = $article;
            }
        }

        /** Índice de secuencia visible (comienza en 1, puede usarse como columna "N°"). */
        $index = 1;
        $total_rows = count($printable_articles);

        foreach ($printable_articles as $article) {
            $is_last_row = ($index === $total_rows);
            $this->print_item_from_profile($index, $article, $is_last_row);
            $index++;
        }

        /** Borde inferior redondeado al terminar el listado en la última página. */
        $this->draw_page_table_outline(true);
    }

    /**
     * Dibuja una fila completa de artículo con las columnas del perfil y color alternado de fondo.
     *
     * Flujo:
     *  1. Verifica salto de página.
     *  2. Calcula la altura necesaria para la fila (considerando wrap e imágenes).
     *  3. Pinta el fondo de la fila con Rect antes de escribir las celdas.
     *  4. Dibuja cada celda según su tipo (texto, wrap, imagen).
     *  5. Dibuja la línea separadora inferior.
     *
     * @param  int                    $index        Número de secuencia del artículo.
     * @param  \App\Models\Article    $article      Artículo a imprimir.
     * @param  bool                   $is_last_row  true en la última fila del listado completo.
     * @return void
     */
    private function print_item_from_profile($index, $article, $is_last_row = false)
    {
        if ($this->y >= $this->get_items_page_break_limit_y()) {
            /** Cierra el bloque tabular de la página antes del salto (sin esquinas inferiores). */
            $this->draw_page_table_outline(false);
            $this->AddPage();
        }

        // Incrementar índice para alternar el color de fondo de la fila
        $this->row_index++;

        $this->SetFont('Arial', '', 8);
        $this->x = $this->start_x;

        /** Altura de la fila; puede crecer si hay imágenes o celdas con wrap. */
        $row_height = $this->line_height;

        /**
         * Resolver la ruta de imagen una sola vez para toda la fila.
         * Evita llamadas HTTP o conversiones repetidas por columna.
         */
        $article_image_fpdf_path = null;
        foreach ($this->profile_columns as $column) {
            if (PdfColumnService::is_article_image_column($column['value_resolver'])) {
                $article_image_fpdf_path = PdfColumnService::article_first_image_path($article);
                break;
            }
        }

        // ── Paso 1: calcular la altura máxima necesaria para la fila ──────────
        foreach ($this->profile_columns as $column) {
            $width = (int) $column['width'];

            if (PdfColumnService::is_article_image_column($column['value_resolver'])) {
                // La imagen ocupa ancho × ancho como cuadrado; la fila crece si es mayor
                if ($article_image_fpdf_path && file_exists($article_image_fpdf_path) && $width > 0) {
                    $image_row_height = max($row_height, $width);
                    if ($image_row_height > $row_height) {
                        $row_height = $image_row_height;
                    }
                }
                continue;
            }

            $value = (string) $this->get_profile_column_value($column, $index, $article);
            $font_size = $this->get_column_font_size($column);
            $cell_line_height = $this->get_column_line_height($font_size);

            /** Las celdas con wrap_content expanden la altura según líneas necesarias. */
            $wrap_content = ! empty($column['wrap_content']);
            if ($wrap_content && $width > 0) {
                $this->SetFont('Arial', '', $font_size);
                $lines = $this->NbLines($width, $value);
                $estimated = max(1, $lines) * $cell_line_height;
                if ($estimated > $row_height) {
                    $row_height = $estimated;
                }
            }
        }

        // ── Paso 2: pintar el fondo de la fila completa ───────────────────────
        /** Color de fondo: azul muy claro para filas impares, blanco para pares. */
        $row_fill_color = ($this->row_index % 2 !== 0)
            ? self::COLOR_ROW_ODD
            : self::COLOR_ROW_EVEN;

        $usable_width = $this->table_right_x - $this->start_x;
        $this->SetFillColor(...$row_fill_color);

        /**
         * Última fila del catálogo: esquinas inferiores redondeadas.
         * Filas intermedias: rectángulo simple.
         */
        $row_fill_corners = $is_last_row ? '0011' : '0000';
        $this->print_table_area_fill($this->start_x, $this->y, $usable_width, $row_height, $row_fill_corners);

        $this->SetTextColor(...self::COLOR_TEXT_DARK);
        $this->SetDrawColor(...self::COLOR_ROW_BORDER);
        $this->SetLineWidth(0.2);

        /** Posición de inicio de la fila (se usa para volver al inicio después de MultiCell). */
        $start_x = $this->start_x;
        $start_y = $this->y;

        // ── Paso 3: dibujar cada celda ────────────────────────────────────────
        foreach ($this->profile_columns as $column) {
            $width     = (int) $column['width'];
            $current_x = $this->x;
            $current_y = $this->y;

            if (PdfColumnService::is_article_image_column($column['value_resolver'])) {
                $this->print_article_first_image_cell($article_image_fpdf_path, $current_x, $start_y, $width, $row_height);
                // Restaurar posición después del dibujo de imagen
                $this->x = $current_x + $width;
                $this->y = $current_y;
                continue;
            }

            $text = (string) $this->get_profile_column_value($column, $index, $article);
            $wrap_content = ! empty($column['wrap_content']);
            $text_align = $this->get_column_text_align($column);
            $font_size = $this->get_column_font_size($column);

            $this->print_vertically_centered_text_cell(
                $current_x,
                $start_y,
                $width,
                $row_height,
                $text,
                $text_align,
                $font_size,
                $wrap_content
            );

            $this->x = $current_x + $width;
            $this->y = $current_y;
        }

        // ── Paso 4: línea separadora inferior (omitida en la última fila; cierra el borde redondeado) ──
        $this->x = $start_x;
        $this->y = $start_y + $row_height;
        if (! $is_last_row) {
            $this->Line($start_x, $this->y, $this->table_right_x, $this->y);
        }

        /** Actualiza el límite inferior del bloque tabular en la página actual. */
        $this->page_table_bottom_y = $this->y;
    }

    /**
     * Dibuja la primera imagen del artículo dentro de su celda.
     * Si no hay imagen disponible, la celda queda vacía con el fondo ya pintado.
     *
     * @param  string|null  $image_path  Ruta local ya resuelta para FPDF.
     * @param  float        $x           Posición X de la celda.
     * @param  float        $y           Posición Y de inicio de la fila.
     * @param  int          $width       Ancho de la celda en mm.
     * @param  float        $row_height  Altura total de la fila en mm.
     * @return void
     */
    private function print_article_first_image_cell($image_path, $x, $y, $width, $row_height)
    {
        if ($width <= 0) {
            return;
        }

        if ($image_path && file_exists($image_path)) {
            /** La imagen se dibuja cuadrada usando el menor valor entre ancho y alto de fila. */
            $image_height = min($width, $row_height);
            try {
                $this->Image($image_path, $x, $y, $width, $image_height);
            } catch (\Exception $e) {
                // Si FPDF no puede procesar la imagen, la celda queda en blanco.
            }
        }
        // Si no hay imagen: la celda ya tiene fondo pintado por Rect, no hace falta nada más.
    }

    // ── Valor de celda ────────────────────────────────────────────────────────

    /**
     * Resuelve el valor de una celda para un artículo dado mediante PdfColumnService.
     *
     * @param  array                  $column   Definición de la columna (value_resolver, etc.).
     * @param  int                    $index    Posición del artículo en la lista.
     * @param  \App\Models\Article    $article  Artículo fuente del valor.
     * @return mixed                  Valor escalar listo para imprimir.
     */
    private function get_profile_column_value($column, $index, $article)
    {
        return PdfColumnService::resolve_value($column['value_resolver'], [
            'article'        => $article,
            'index'          => $index,
            'numbers'        => Numbers::class,
            'general_helper' => \App\Http\Controllers\Helpers\GeneralHelper::class,
            'price_type_id'  => $this->price_type_id,
            'user'           => $this->user,
        ]);
    }

    // ── Límite de salto de página ─────────────────────────────────────────────

    /**
     * Devuelve el límite de Y a partir del cual se debe insertar una nueva página.
     * Deja margen para el pie de página según si hay footer_text o no.
     *
     * @return int  Posición Y máxima antes del salto en mm.
     */
    private function get_items_page_break_limit_y()
    {
        /** Reserva extra si hay texto de observaciones en el pie (la barra es más alta). */
        $footer_reserve = $this->footer_text ? 28 : 18;
        return 285 - $footer_reserve;
    }

    // ── Pie de página ─────────────────────────────────────────────────────────

    /**
     * Pie de página con barra de color oscuro, observaciones del perfil (si existen)
     * y número de página alineado a la derecha.
     *
     * Es invocada automáticamente por FPDF al cerrar cada página.
     */
    public function Footer()
    {
        /** La barra es más alta cuando hay texto de observaciones. */
        $bar_height   = $this->footer_text ? 14 : 8;
        $usable_width = $this->table_right_x - $this->start_x;

        /** Posición Y donde comienza la barra de pie (5 mm desde el borde inferior de A4 = 297 mm). */
        $bar_y = 297 - 5 - $bar_height;

        // Fondo gris del pie con esquinas redondeadas
        $this->SetFillColor(...self::COLOR_TITLE_BAR);
        $this->SetDrawColor(...self::COLOR_TABLE_BORDER);
        $this->SetLineWidth(0.25);
        $this->draw_rounded_rect(
            $this->start_x,
            $bar_y,
            $usable_width,
            $bar_height,
            self::BORDER_RADIUS,
            'DF',
            '1111'
        );

        $this->SetTextColor(...self::COLOR_HEADER_TEXT);
        $this->SetFont('Arial', '', 7);

        // Número de página "Pág. X / N" alineado a la derecha
        $page_label = 'Pág. ' . $this->PageNo() . ' / {nb}';
        $this->y = $bar_y + 2;
        $this->x = $this->start_x;
        $this->Cell($usable_width - 2, 4, $page_label, 0, 0, 'R');

        // Texto de observaciones alineado a la izquierda (solo si fue configurado en el perfil)
        if ($this->footer_text) {
            $this->y = $bar_y + 2;
            $this->x = $this->start_x + 2;
            $this->MultiCell($usable_width - 22, 4, $this->pdf_text($this->footer_text), 0, 'L', false);
        }

        // Restaurar color de texto para no afectar posibles callbacks internos de FPDF
        $this->SetTextColor(...self::COLOR_TEXT_DARK);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /**
     * Pinta el relleno de un bloque de la tabla respetando esquinas redondeadas del contorno.
     *
     * @param  float   $x        Origen X en mm.
     * @param  float   $y        Origen Y en mm.
     * @param  float   $w        Ancho en mm.
     * @param  float   $h        Alto en mm.
     * @param  string  $corners  Flags TL TR BR BL ('1' redondeada, '0' recta).
     * @return void
     */
    private function print_table_area_fill($x, $y, $w, $h, $corners = '0000')
    {
        if ($corners === '0000') {
            $this->Rect($x, $y, $w, $h, 'F');
            return;
        }

        $this->draw_rounded_rect($x, $y, $w, $h, self::BORDER_RADIUS, 'F', $corners);
    }

    /**
     * Dibuja el contorno exterior redondeado del bloque tabular de la página actual.
     *
     * @param  bool  $round_bottom  true en la última fila del PDF; false al cambiar de página.
     * @return void
     */
    private function draw_page_table_outline($round_bottom)
    {
        if ($this->page_table_top_y === null || $this->page_table_bottom_y === null) {
            return;
        }

        /** Altura del bloque desde el encabezado de columnas hasta la última fila impresa. */
        $outline_height = $this->page_table_bottom_y - $this->page_table_top_y;
        if ($outline_height <= 0) {
            return;
        }

        /** Esquinas inferiores redondeadas solo al cerrar el listado; en salto de página quedan rectas. */
        $corners = $round_bottom ? '1111' : '1100';
        $usable_width = $this->table_right_x - $this->start_x;

        $this->SetDrawColor(...self::COLOR_TABLE_BORDER);
        $this->SetLineWidth(0.35);
        $this->draw_rounded_rect(
            $this->start_x,
            $this->page_table_top_y,
            $usable_width,
            $outline_height,
            self::BORDER_RADIUS,
            'D',
            $corners
        );
    }

    /**
     * Dibuja un rectángulo con esquinas redondeadas opcionales (FPDF no lo trae nativo).
     *
     * @param  float   $x        Origen X en mm.
     * @param  float   $y        Origen Y en mm.
     * @param  float   $w        Ancho en mm.
     * @param  float   $h        Alto en mm.
     * @param  float   $r        Radio de curvatura en mm.
     * @param  string  $style    F (relleno), D (borde) o DF (ambos).
     * @param  string  $corners  Cuatro flags '1'/'0' para TL, TR, BR, BL.
     * @return void
     */
    private function draw_rounded_rect($x, $y, $w, $h, $r, $style = 'F', $corners = '1111')
    {
        /** Limita el radio para que no supere la mitad del ancho o alto. */
        $r = min($r, $w / 2, $h / 2);
        if ($r <= 0) {
            $this->Rect($x, $y, $w, $h, $style);
            return;
        }

        $k = $this->k;
        $hp = $this->h;

        if ($style === 'F') {
            $op = 'f';
        } elseif ($style === 'FD' || $style === 'DF') {
            $op = 'B';
        } else {
            $op = 'S';
        }

        /** Factor de aproximación de arco de Bézier para un cuarto de círculo. */
        $my_arc = 4 / 3 * (sqrt(2) - 1);

        $round_tl = isset($corners[0]) && $corners[0] === '1';
        $round_tr = isset($corners[1]) && $corners[1] === '1';
        $round_br = isset($corners[2]) && $corners[2] === '1';
        $round_bl = isset($corners[3]) && $corners[3] === '1';

        if ($round_tl) {
            $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        } else {
            $this->_out(sprintf('%.2F %.2F m', $x * $k, ($hp - $y) * $k));
        }

        if ($round_tr) {
            $this->_out(sprintf('%.2F %.2F l', ($x + $w - $r) * $k, ($hp - $y) * $k));
        } else {
            $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $y) * $k));
        }

        if ($round_tr) {
            $xc = $x + $w - $r;
            $yc = $y + $r;
            $this->draw_arc(
                $xc + $r * $my_arc, $yc - $r,
                $xc + $r, $yc - $r * $my_arc,
                $xc + $r, $yc
            );
        }

        if ($round_br) {
            $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - ($y + $h - $r)) * $k));
        } else {
            $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - ($y + $h)) * $k));
        }

        if ($round_br) {
            $xc = $x + $w - $r;
            $yc = $y + $h - $r;
            $this->draw_arc(
                $xc + $r, $yc + $r * $my_arc,
                $xc + $r * $my_arc, $yc + $r,
                $xc, $yc + $r
            );
        }

        if ($round_bl) {
            $this->_out(sprintf('%.2F %.2F l', ($x + $r) * $k, ($hp - ($y + $h)) * $k));
        } else {
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - ($y + $h)) * $k));
        }

        if ($round_bl) {
            $xc = $x + $r;
            $yc = $y + $h - $r;
            $this->draw_arc(
                $xc - $r * $my_arc, $yc + $r,
                $xc - $r, $yc + $r * $my_arc,
                $xc - $r, $yc
            );
        }

        if ($round_tl) {
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - ($y + $r)) * $k));
        } else {
            $this->_out(sprintf('%.2F %.2F l', $x * $k, ($hp - $y) * $k));
        }

        if ($round_tl) {
            $xc = $x + $r;
            $yc = $y + $r;
            $this->draw_arc(
                $xc - $r, $yc - $r * $my_arc,
                $xc - $r * $my_arc, $yc - $r,
                $xc, $yc - $r
            );
        }

        $this->_out($op);
    }

    /**
     * Trazo de curva de Bézier cúbica usada por draw_rounded_rect().
     *
     * @param  float  $x1
     * @param  float  $y1
     * @param  float  $x2
     * @param  float  $y2
     * @param  float  $x3
     * @param  float  $y3
     * @return void
     */
    private function draw_arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $this->_out(sprintf(
            '%.2F %.2F %.2F %.2F %.2F %.2F c',
            $x1 * $this->k,
            ($this->h - $y1) * $this->k,
            $x2 * $this->k,
            ($this->h - $y2) * $this->k,
            $x3 * $this->k,
            ($this->h - $y3) * $this->k
        ));
    }

    /**
     * Calcula cuántas líneas ocupará un texto dentro de un ancho de celda dado.
     * Usado para estimar la altura de filas con wrap_content activo.
     *
     * @param  int     $w    Ancho de la celda en mm.
     * @param  string  $txt  Texto a medir.
     * @return int           Cantidad de líneas necesarias (mínimo 1).
     */
    private function NbLines($w, $txt)
    {
        /** Referencia al mapa de anchos de caracteres de la fuente activa en FPDF. */
        $cw = &$this->CurrentFont['cw'];

        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }

        /** Ancho máximo utilizable en unidades internas de FPDF (milésimas de punto). */
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $this->pdf_text($txt));
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
     * Acorta un texto para que entre dentro del ancho de celda sin hacer wrap.
     * Agrega "…" al final si fue necesario truncar.
     *
     * @param  string  $text   Texto original.
     * @param  int     $width  Ancho de la celda en mm.
     * @return string          Texto truncado con elipsis o el original si cabe.
     */
    private function truncate_text_to_width($text, $width)
    {
        if ($width <= 0) {
            return '';
        }

        /** Texto ya convertido para medir igual que Cell() lo imprimirá. */
        $pdf_text = $this->pdf_text($text);

        if ($this->GetStringWidth($pdf_text) <= $width) {
            return $text;
        }

        /** Reducimos caracter a caracter hasta que el texto con elipsis entre en el ancho. */
        $truncated = $text;
        while ($this->GetStringWidth($this->pdf_text($truncated . '...')) > $width && strlen($truncated) > 0) {
            $truncated = substr($truncated, 0, -1);
        }

        return $truncated . '...';
    }
}
