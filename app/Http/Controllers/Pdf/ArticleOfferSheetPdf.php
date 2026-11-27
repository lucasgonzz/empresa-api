<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ArticleOfferCashPriceHelper;
use App\Http\Controllers\Helpers\CriterioDeOfertaPorCantidadHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePdf as ArticlePdfTemplate;
use Carbon\Carbon;
use Milon\Barcode\DNS1D;
use fpdf;
/*
 * require_once y NO require (misión soporte-t173-m44): con un require pelado, si en el mismo proceso
 * otro PDF ya cargó fpdf.php, esta clase tira "Constant FPDF_VERSION already defined". Es lo que
 * impedía instanciarla en un test; require_once no cambia nada en producción (un PDF por pedido).
 */
require_once(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF A4 vertical: dos artículos por página (mitad de hoja cada uno) según plantilla `ArticlePdf` del usuario.
 */
class ArticleOfferSheetPdf extends fpdf
{
    /** @var ArticlePdfTemplate Plantilla de título, textos y flags de visualización. */
    protected $template;

    /** @var \Illuminate\Support\Collection<int, \App\Models\Article> */
    protected $articles;

    /** @var DNS1D Generador de PNG para código de barras. */
    protected $barcode_generator;

    /** @var float Ancho útil del código de barras (mm). */
    protected $bar_code_img_width = 40;

    /** @var int Borde de celdas (0 sin borde). */
    protected $b = 0;

    /** @var float Mitad de página A4 vertical (mm), para la línea divisoria entre los dos artículos. */
    protected $page_half_y = 148.5;

    /** @var string|null ID de lista de precios (`?price_type_id=`) para imprimir precios del pivot. */
    protected $price_type_id;

    /** @var \App\Models\User|null Usuario autenticado (owner) para reglas de listas de precio. */
    protected $user;

    /**
     * @var float|null Porcentaje de descuento por pagar en efectivo o transferencia (el mismo que se
     *                 aplica al cobrar). Sólo se calcula si la plantilla tiene prendido
     *                 `mostrar_precio_efectivo`; null = no hay descuento y no se dibuja el recuadro.
     */
    protected $cash_discount_percentage = null;

    /**
     * @param ArticlePdfTemplate $template        Registro de diseño elegido.
     * @param string             $ids             IDs de artículos separados por guión, en el orden deseado.
     * @param bool               $output_and_exit true (default, el uso real del controlador): envía el PDF
     *                                            y corta el proceso. false: arma las páginas y vuelve, para
     *                                            que un test pueda leer el binario con `Output('S')`.
     */
    public function __construct(ArticlePdfTemplate $template, $ids, $output_and_exit = true)
    {
        set_time_limit(0);
        parent::__construct();
        $this->template = $template;
        $this->SetAutoPageBreak(false);
        $this->barcode_generator = new DNS1D();

        /*
         * Lista de precios opcional (misma convención que `ArticleTicketPdf`).
         * Query: ?price_type_id=ID
         */
        $this->price_type_id = request()->query('price_type_id');
        $this->user = UserHelper::getFullModel();

        $owner_id = UserHelper::userId();
        $this->load_articles_for_user($ids, $owner_id);

        // Porcentaje de efectivo/transferencia: una sola consulta por PDF, no una por artículo.
        if ($this->template->mostrar_precio_efectivo) {
            $this->cash_discount_percentage = ArticleOfferCashPriceHelper::cash_discount_percentage($owner_id);
        }

        $this->AddPage();
        $this->render_all_blocks();

        if (!$output_and_exit) {
            return;
        }

        $this->Output();
        exit;
    }

    /**
     * Carga artículos del usuario preservando el orden de `$ids`.
     *
     * @param string $ids
     * @param int    $user_id
     * @return void
     */
    protected function load_articles_for_user($ids, $user_id)
    {
        $id_list = [];
        foreach (explode('-', $ids) as $raw_id) {
            $raw_id = trim($raw_id);
            if ($raw_id === '') {
                continue;
            }
            $id_list[] = (int) $raw_id;
        }

        if (!count($id_list)) {
            $this->articles = collect([]);
            return;
        }

        $field_order = implode(',', $id_list);
        $query = Article::query()
            ->where('user_id', $user_id)
            ->whereIn('id', $id_list)
            ->with(['images', 'category', 'unidad_medida', 'iva']);

        if (!is_null($this->price_type_id) && $this->price_type_id !== '') {
            $query->with(['price_types']);
        }

        $this->articles = $query
            ->orderByRaw('FIELD(id, '.$field_order.')')
            ->get();
    }

    /**
     * Precio final a imprimir: pivot de `price_types` si hay `price_type_id`, si no `final_price` del artículo.
     *
     * @param \App\Models\Article $article
     * @return float
     */
    protected function get_article_final_price($article)
    {
        if (UserHelper::uses_listas_de_precio($this->user)) {
            if (!is_null($this->price_type_id) && $this->price_type_id !== '') {
                $price_type = $article->price_types
                    ->firstWhere('id', (int) $this->price_type_id);
                if (is_null($price_type)) {
                    $price_type = $article->price_types()
                        ->where('price_type_id', $this->price_type_id)
                        ->first();
                }
                if (!is_null($price_type) && isset($price_type->pivot->final_price)) {
                    return (float) $price_type->pivot->final_price;
                }
            }
        }

        return (float) $article->final_price;
    }

    /**
     * Precio anterior tachado/referencia: pivot de lista o columna general del artículo.
     *
     * @param \App\Models\Article $article
     * @return float|null
     */
    protected function get_article_previus_final_price($article)
    {
        if (
            UserHelper::uses_listas_de_precio($this->user)
            && !is_null($this->price_type_id)
            && $this->price_type_id !== ''
        ) {
            $price_type = $article->price_types
                ->firstWhere('id', (int) $this->price_type_id);
            if (is_null($price_type)) {
                $price_type = $article->price_types()
                    ->where('price_type_id', $this->price_type_id)
                    ->first();
            }
            if (!is_null($price_type) && isset($price_type->pivot->previus_final_price)) {
                return (float) $price_type->pivot->previus_final_price;
            }
        }

        if (is_null($article->previus_final_price)) {
            return null;
        }

        return (float) $article->previus_final_price;
    }

    /**
     * Dibuja cada bloque de media página.
     *
     * @return void
     */
    protected function render_all_blocks()
    {
        $index = 0;
        foreach ($this->articles as $article) {
            if ($index > 0 && $index % 2 === 0) {
                $this->AddPage();
            }
            $slot = $index % 2;
            $block_top = 6 + ($slot * 148);
            $this->print_half_block($article, $block_top, $slot);
            $index++;
        }
    }

    /**
     * Línea horizontal punteada de borde a borde en la mitad de la hoja, entre el artículo superior e inferior.
     * FPDF no trae trazos; se simula con segmentos cortos.
     *
     * @param float $x1        Inicio X (mm).
     * @param float $x2        Fin X (mm).
     * @param float $y         Ordenada fija (mm).
     * @param float $dash_len  Largo de cada trazo.
     * @param float $gap_len   Largo del espacio entre trazos.
     * @return void
     */
    protected function draw_horizontal_dashed_line($x1, $x2, $y, $dash_len, $gap_len)
    {
        $this->SetDrawColor(80, 80, 80);
        $this->SetLineWidth(0.2);
        $x = $x1;
        while ($x < $x2) {
            $x_end = min($x + $dash_len, $x2);
            $this->Line($x, $y, $x_end, $y);
            $x = $x_end + $gap_len;
        }
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.2);
    }

    /**
     * Contenido de una mitad de página para un artículo.
     *
     * @param \App\Models\Article $article
     * @param float               $block_top Origen Y superior del bloque (mm).
     * @param int                 $slot      0 mitad superior, 1 mitad inferior.
     * @return void
     */
    protected function print_half_block($article, $block_top, $slot)
    {
        $x0 = 10;
        $full_w = 190;
        $left_w = 92;
        // $right_x = $x0 + $left_w + 6;
        // $right_w = $full_w - $left_w - 6;
        $right_x = 80;
        $right_w = 125;

        // Formato "pagando en efectivo o transferencia" (opción de la plantilla): método aparte.
        if ($this->template->mostrar_precio_efectivo) {
            $this->print_half_block_cash_price($article, $block_top, $slot);
            return;
        }

        if ($slot === 1) {
            $this->draw_horizontal_dashed_line($x0, $x0 + $full_w, $this->page_half_y, 2.2, 1.8);
        }

        $y = $block_top + 7;

        $final_price = $this->get_article_final_price($article);

        $this->SetXY($x0, $y);
        $this->SetFont('Arial', 'BI', 35);
        $titulo = $this->template->titulo ?: '';
        $this->Cell($full_w, 12, $this->to_pdf_text($titulo), $this->b, 1, 'C');

        $this->SetX($x0);
        $this->SetFont('Arial', 'B', 25);
        $this->MultiCell($full_w, 13, $this->to_pdf_text($article->name), $this->b, 'C');
        $y = $this->GetY() + 2;

        $this->SetXY($x0, $y);
        $this->SetFont('Arial', 'B', 55);
        $this->Cell($left_w, 18, '$'.Numbers::price($final_price), $this->b, 1, 'L');

        $y_secondary_row = $this->GetY();

        $this->SetXY($x0, $y_secondary_row);
        $this->SetFont('Arial', '', 12);
        $line_net = 'Precio sin impuestos: ';

        $precio_sin_iva = $final_price;
        if (!is_null($article->iva)) {
            $precio_sin_iva = (float) $final_price / (1 + ((float) $article->iva->percentage / 100));
        }
        $line_net .= '$'.Numbers::price($precio_sin_iva);
        $this->Cell($left_w, 15, $line_net, $this->b, 1, 'L');




        $y_after_net = $this->GetY();

        $this->y -= 15;
        $ref_line = $this->precio_por_unidad_referencia($article, $final_price);
        if (!is_null($ref_line)) {
            $this->SetX($right_x);
            $this->SetFont('Arial', 'B', 15);
            $this->MultiCell($right_w, 10, $this->to_pdf_text($ref_line), $this->b, 'R');
        }

        $previus_final_price = $this->get_article_previus_final_price($article);
        if ($this->template->mostrar_precio_anterior && !is_null($previus_final_price) && $previus_final_price > 0) {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 23);
            $prev = 'Precio anterior: $'.Numbers::price($previus_final_price);
            $this->MultiCell($right_w, 10, $this->to_pdf_text($prev), $this->b, 'R');
        }

        $custom = trim((string) $this->template->texto_personalizado);
        if ($custom !== '') {
            $this->SetX($right_x);
            $this->SetFont('Arial', 'B', 20);
            $custom_upper = mb_strtoupper($custom, 'UTF-8');
            $this->MultiCell($right_w, 10, $this->to_pdf_text($custom_upper), $this->b, 'R');
        }

        $cat_name = $article->category ? $article->category->name : '';
        if ($cat_name !== '') {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 12);
            $this->MultiCell($right_w, 7, $this->to_pdf_text($cat_name), $this->b, 'R');
        }

        if ($this->template->motrar_fecha_impresion) {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 12);
            $fecha = Carbon::today()->format('d/m/Y');
            $this->MultiCell($right_w, 7, $fecha, $this->b, 'R');
        }

        $this->print_article_price_ranges($article, $right_w, $right_x);

        $this->SetXY($x0, $y_after_net);
        $this->print_first_image($article, $x0, $y_after_net, $left_w);

        $bar_y = $block_top + 132;
        $this->print_bar_code_centered($article, $x0, $full_w, $bar_y);

    }

    /**
     * Mitad de página con el formato "pagando en efectivo o transferencia" (ticket 173, La Martina).
     *
     * Orden: título y nombre centrados; el precio habitual grande a la izquierda y, a la derecha y a
     * la misma altura, un recuadro amarillo con borde rojo ("PAGANDO EN EFECTIVO / O TRANSFERENCIA")
     * con el precio con descuento en rojo; debajo del recuadro el texto personalizado y la fecha; la
     * imagen del artículo a la izquierda y el código de barras abajo.
     *
     * En este formato NO se imprimen "Precio sin impuestos" ni "Precio anterior" (aunque la plantilla
     * tenga `mostrar_precio_anterior`): son lo que confundía a los clientes del local. Se conservan
     * el precio por kilo/litro (dato de góndola) y las ofertas por cantidad.
     *
     * Si el dueño no tiene un descuento de efectivo/transferencia configurado
     * (`$cash_discount_percentage` null) no se dibuja el recuadro y sale sólo el precio habitual.
     *
     * @param \App\Models\Article $article
     * @param float               $block_top Origen Y superior del bloque (mm).
     * @param int                 $slot      0 mitad superior, 1 mitad inferior.
     * @return void
     */
    protected function print_half_block_cash_price($article, $block_top, $slot)
    {
        $x0 = 10;
        $full_w = 190;
        $left_w = 92;
        // Columna derecha: termina en x = 200, el mismo borde derecho que el recuadro.
        $right_x = 75;
        $right_w = 125;
        // Recuadro del precio con descuento.
        $box_x = 105;
        $box_w = 95;
        $box_h = 44;
        // Colores del formato: azul marino (textos), rojo (borde y precio), amarillo (fondo).
        $navy = [20, 40, 140];
        $red = [220, 0, 0];
        $yellow = [255, 230, 0];

        if ($slot === 1) {
            $this->draw_horizontal_dashed_line($x0, $x0 + $full_w, $this->page_half_y, 2.2, 1.8);
        }

        $final_price = $this->get_article_final_price($article);

        // Título y nombre, centrados y en azul.
        $this->SetTextColor($navy[0], $navy[1], $navy[2]);
        $this->SetXY($x0, $block_top + 7);
        $this->SetFont('Arial', 'BI', 35);
        $titulo = $this->template->titulo ?: '';
        $this->Cell($full_w, 12, $this->to_pdf_text($titulo), $this->b, 1, 'C');

        $this->SetX($x0);
        $this->SetFont('Arial', 'B', 25);
        $this->MultiCell($full_w, 13, $this->to_pdf_text($article->name), $this->b, 'C');
        $y_price = $this->GetY() + 2;

        // Precio habitual, grande, a la izquierda.
        $this->SetXY($x0, $y_price);
        $this->SetFont('Arial', 'B', 55);
        $this->Cell($left_w, 18, '$'.Numbers::price($final_price), $this->b, 0, 'L');

        if (!is_null($this->cash_discount_percentage)) {
            $cash_price = ArticleOfferCashPriceHelper::cash_price($final_price, $this->cash_discount_percentage);

            // Recuadro amarillo con borde rojo.
            $this->SetFillColor($yellow[0], $yellow[1], $yellow[2]);
            $this->SetDrawColor($red[0], $red[1], $red[2]);
            $this->SetLineWidth(1.2);
            $this->Rect($box_x, $y_price, $box_w, $box_h, 'DF');

            // Dos líneas fijas (no un MultiCell) para que el corte sea siempre el mismo.
            $this->SetTextColor($navy[0], $navy[1], $navy[2]);
            $this->print_fitted_cell($box_x, $y_price + 3, $box_w, 8, 'PAGANDO EN EFECTIVO', 'BI', 18, 12);
            $this->print_fitted_cell($box_x, $y_price + 11, $box_w, 8, 'O TRANSFERENCIA', 'BI', 18, 12);

            // Precio con descuento, grande y rojo; se achica si no entra en el recuadro.
            $this->SetTextColor($red[0], $red[1], $red[2]);
            $this->print_fitted_cell($box_x, $y_price + 21, $box_w, 20, '$'.Numbers::price($cash_price), 'B', 48, 24);

            $right_y = $y_price + $box_h + 4;
        } else {
            $right_y = $y_price + 20;
        }

        // Columna derecha, alineada a la derecha: texto personalizado, fecha, precio por unidad, categoría.
        $this->SetXY($right_x, $right_y);

        $custom = trim((string) $this->template->texto_personalizado);
        if ($custom !== '') {
            $this->SetTextColor($navy[0], $navy[1], $navy[2]);
            $this->SetX($right_x);
            $this->SetFont('Arial', 'B', 20);
            $this->MultiCell($right_w, 10, $this->to_pdf_text(mb_strtoupper($custom, 'UTF-8')), $this->b, 'R');
        }

        $this->SetTextColor(0, 0, 0);

        if ($this->template->motrar_fecha_impresion) {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 12);
            $this->MultiCell($right_w, 7, Carbon::today()->format('d/m/Y'), $this->b, 'R');
        }

        $ref_line = $this->precio_por_unidad_referencia($article, $final_price);
        if (!is_null($ref_line)) {
            $this->SetX($right_x);
            $this->SetFont('Arial', 'B', 15);
            $this->MultiCell($right_w, 10, $this->to_pdf_text($ref_line), $this->b, 'R');
        }

        $cat_name = $article->category ? $article->category->name : '';
        if ($cat_name !== '') {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 12);
            $this->MultiCell($right_w, 7, $this->to_pdf_text($cat_name), $this->b, 'R');
        }

        $this->SetTextColor($navy[0], $navy[1], $navy[2]);
        $this->print_article_price_ranges($article, $right_w, $right_x);
        $this->SetTextColor(0, 0, 0);

        // Imagen a la izquierda, debajo del precio habitual (60 x 60 mm).
        $this->print_first_image($article, $x0, $y_price + 20, $left_w);

        // Código de barras: misma posición que el formato de siempre.
        $this->print_bar_code_centered($article, $x0, $full_w, $block_top + 132);

        // Se devuelven los colores y el grosor de línea a los de siempre para no teñir el bloque siguiente.
        $this->SetTextColor(0, 0, 0);
        $this->SetDrawColor(0, 0, 0);
        $this->SetFillColor(255, 255, 255);
        $this->SetLineWidth(0.2);
    }

    /**
     * Celda centrada de una sola línea que baja el tamaño de la fuente de a 2 puntos hasta que el
     * texto entra en el ancho (menos 3 mm de aire a cada lado), sin pasar del mínimo.
     *
     * @param float  $x         Origen X (mm).
     * @param float  $y         Origen Y (mm).
     * @param float  $w         Ancho disponible (mm).
     * @param float  $h         Alto de la celda (mm).
     * @param string $text      Texto ya en UTF-8.
     * @param string $style     Estilo de FPDF ('B', 'BI', ...).
     * @param int    $size      Tamaño inicial en puntos.
     * @param int    $min_size  Tamaño mínimo en puntos.
     * @return void
     */
    protected function print_fitted_cell($x, $y, $w, $h, $text, $style, $size, $min_size)
    {
        $text = $this->to_pdf_text($text);
        $this->SetFont('Arial', $style, $size);
        while ($size > $min_size && $this->GetStringWidth($text) > ($w - 6)) {
            $size -= 2;
            $this->SetFont('Arial', $style, $size);
        }
        $this->SetXY($x, $y);
        $this->Cell($w, $h, $text, $this->b, 0, 'C');
    }

    /**
     * Las ofertas por cantidad del articulo, una linea por oferta.
     *
     * Desde la mision oferta-por-cantidad-porcentaje (24/9/2026) una oferta puede fijar un PRECIO
     * o descontar un PORCENTAJE, y cual de los dos manda lo decide
     * `CriterioDeOfertaPorCantidadHelper` — el mismo criterio que usan el ABM, el ERP al vender y
     * la tienda. Aca NO se vuelve a decidir: se pregunta.
     *
     * 🔴 Una oferta que no tiene ningun valor usable (MODO_NINGUNO) NO SE IMPRIME. Antes se
     * imprimia igual y salia "= $0 cu" en un cartel pensado para colgar en el local: un precio de
     * cero anunciado a la calle. El tramo sin valor usable no descuenta nada en ningun lado del
     * sistema, asi que tampoco tiene nada que anunciar.
     */
    function print_article_price_ranges($article, $right_w, $right_x) {

        if (count($article->article_price_ranges) >= 1) {

            foreach ($article->article_price_ranges as $price_range) {

                $modo = CriterioDeOfertaPorCantidadHelper::resolver($price_range->price, $price_range->porcentaje);

                if ($modo === CriterioDeOfertaPorCantidadHelper::MODO_NINGUNO) {
                    continue;
                }

                $text = 'Llevando ';
                if ($price_range->modo == 'Igual') {

                    $text .= Numbers::price($price_range->amount);
                } else {

                    $text .= Numbers::price($price_range->amount). ' o mas';

                }

                if ($modo === CriterioDeOfertaPorCantidadHelper::MODO_PORCENTAJE) {

                    $text .= ' = '.CriterioDeOfertaPorCantidadHelper::porcentaje_legible($price_range->porcentaje).'% de descuento';
                } else {

                    $text .= ' = $'.Numbers::price($price_range->price).' cu';
                }

                $this->SetFont('Arial', 'B', 20);
                $this->SetX($right_x);
                $this->MultiCell($right_w, 7, $text, $this->b, 'R');
            }
        }
    }

    /**
     * Texto compatible con FPDF (ISO-8859-1).
     *
     * @param string $value
     * @return string
     */
    protected function to_pdf_text($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
        }
        return $value;
    }

    /**
     * Línea "Medida: ..." con unidad si existe.
     *
     * @param \App\Models\Article $article
     * @return string
     */
    protected function format_medida_line($article)
    {
        $parts = ['Medida:'];
        if (!is_null($article->medida) && $article->medida !== '') {
            $parts[] = (string) round($article->medida, 2);
        } else {
            $parts[] = '-';
        }
        if ($article->unidad_medida) {
            $parts[] = $article->unidad_medida->name.'s';
        }
        return implode(' ', $parts);
    }

    /**
     * Calcula precio por kilo o litro cuando la unidad lo permite (regla de tres sobre `final_price` y `medida`).
     *
     * @param \App\Models\Article $article
     * @param float|null          $final_price Precio final ya resuelto (lista o artículo).
     * @return string|null
     */
    protected function precio_por_unidad_referencia($article, $final_price = null)
    {
        $final = (float) ($final_price ?? $this->get_article_final_price($article));
        $medida = (float) $article->medida;
        if ($medida <= 0 || is_null($article->unidad_medida_id) || !$article->unidad_medida) {
            return null;
        }

        $name = mb_strtolower(trim($article->unidad_medida->name), 'UTF-8');

        if (strpos($name, 'gramo') !== false && strpos($name, 'kilo') === false) {
            $per = $final / $medida * 1000;
            return '$'.Numbers::price($per).' el kilo';
        }
        if (strpos($name, 'kilo') !== false) {
            $per = $final / $medida;
            return '$'.Numbers::price($per).' el kilo';
        }
        if ($name === 'ml' || strpos($name, 'mililitro') !== false) {
            $per = $final / $medida * 1000;
            return '$'.Numbers::price($per).' el litro';
        }
        if (strpos($name, 'litro') !== false) {
            $per = $final / $medida;
            return '$'.Numbers::price($per).' el litro';
        }

        return null;
    }

    /**
     * Primera imagen del artículo bajo el bloque de precios (columna izquierda).
     *
     * @param \App\Models\Article $article
     * @param float               $x
     * @param float               $y
     * @param float               $max_w
     * @return void
     */
    protected function print_first_image($article, $x, $y, $max_w)
    {
        if (!count($article->images)) {
            return;
        }

        $path = $this->resolve_article_image_path($article);
        if (is_null($path) || $path === '') {
            return;
        }

        $img_h = 48;
        $this->Image($path, $x, $y, 60, 60);
        // $this->Image($path, $x, $y, $max_w, $img_h);
        $this->SetY($y + $img_h + 2);
    }

    /**
     * Resuelve una ruta LOCAL usable por FPDF para la primera imagen del artículo, o null.
     *
     * Delega en GeneralHelper::pdf_image_path(), que convierte la imagen a jpg cuando hace falta y
     * nunca devuelve una URL sin verificar: si la imagen no se puede leer (URL caída, archivo
     * corrupto), devuelve null y print_first_image() se salta la imagen de ese artículo. Antes
     * este método convertía a mano y, si esa conversión fallaba, devolvía la URL original de la
     * imagen sin convertir -- FPDF no sabe parsear ese formato, así que Image() tiraba una
     * excepción sin catch que abortaba el PDF ENTERO (los artículos con imagen sana incluidos).
     * Es lo que le pasaba a La Martina: "Unsupported image type" en producción, 19/9/2026.
     *
     * @param \App\Models\Article $article
     * @return string|null
     */
    protected function resolve_article_image_path($article)
    {
        if (config('app.APP_ENV') == 'local') {
            $img_url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
        } else {
            /* Nombre de columna fijo (dejo de ser configurable, ver Prompt 01/02 grupo 215) */
            $img_url = $article->images[0]->hosting_url;
        }

        return GeneralHelper::pdf_image_path($img_url);
    }

    /**
     * Código de barras centrado en el ancho del bloque (misma lógica que `ArticleTicketPdf::print_bar_code`).
     *
     * @param \App\Models\Article $article
     * @param float               $x0
     * @param float               $full_w
     * @param float               $y
     * @return void
     */
    protected function print_bar_code_centered($article, $x0, $full_w, $y)
    {
        if (!$article->bar_code) {
            return;
        }

        $img_height = 6;
        $barcode = $this->barcode_generator->getBarcodePNG($article->bar_code, 'C128');
        $img_data = base64_decode($barcode);
        $safe_code = str_replace(['/', '\\'], '_', $article->bar_code);
        $file = storage_path('app/temp_barcode_'.$safe_code.'_'.uniqid('', true).'.png');
        file_put_contents($file, $img_data);

        $img_x = $x0 + (($full_w - $this->bar_code_img_width) / 2);
        $this->Image($file, $img_x, $y, $this->bar_code_img_width, $img_height);
        @unlink($file);

        $this->SetXY($img_x, $y + $img_height);
        $this->SetFont('Arial', '', 8);
        $this->Cell($this->bar_code_img_width, 4, $article->bar_code, $this->b, 0, 'C');
    }
}
