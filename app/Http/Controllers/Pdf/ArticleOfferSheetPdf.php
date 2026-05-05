<?php

namespace App\Http\Controllers\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\ArticlePdf as ArticlePdfTemplate;
use Carbon\Carbon;
use Milon\Barcode\DNS1D;
use fpdf;
require(__DIR__.'/../CommonLaravel/fpdf/fpdf.php');

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

    /**
     * @param ArticlePdfTemplate $template Registro de diseño elegido.
     * @param string             $ids      IDs de artículos separados por guión, en el orden deseado.
     */
    public function __construct(ArticlePdfTemplate $template, $ids)
    {
        set_time_limit(0);
        parent::__construct();
        $this->template = $template;
        $this->SetAutoPageBreak(false);
        $this->barcode_generator = new DNS1D();

        $owner_id = UserHelper::userId();
        $this->load_articles_for_user($ids, $owner_id);

        $this->AddPage();
        $this->render_all_blocks();
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
        $this->articles = Article::query()
            ->where('user_id', $user_id)
            ->whereIn('id', $id_list)
            ->with(['images', 'category', 'unidad_medida'])
            ->orderByRaw('FIELD(id, '.$field_order.')')
            ->get();
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
        $right_x = $x0 + $left_w + 6;
        $right_w = $full_w - $left_w - 6;

        if ($slot === 1) {
            $this->draw_horizontal_dashed_line($x0, $x0 + $full_w, $this->page_half_y, 2.2, 1.8);
        }

        $y = $block_top + 2;

        $this->SetXY($x0, $y);
        $this->SetFont('Arial', 'BI', 35);
        $titulo = $this->template->titulo ?: '';
        $this->Cell($full_w, 12, $this->to_pdf_text($titulo), $this->b, 1, 'C');

        $this->SetX($x0);
        $this->SetFont('Arial', 'B', 25);
        $this->MultiCell($full_w, 17, $this->to_pdf_text($article->name), $this->b, 'C');
        $y = $this->GetY() + 2;

        $this->SetXY($x0, $y);
        $this->SetFont('Arial', 'B', 70);
        $this->Cell($left_w, 20, '$'.Numbers::price($article->final_price), $this->b, 1, 'L');

        $y_secondary_row = $this->GetY();

        $this->SetXY($x0, $y_secondary_row);
        $this->SetFont('Arial', '', 12);
        $line_net = 'Precio sin impuestos: ';

        $precio_sin_iva = $article->final_price;
        if (!is_null($article->iva)) {
            $precio_sin_iva = (float)$article->final_price / (1+((float)$article->iva->percentage / 100));
            
        } 
        $line_net .= '$'.Numbers::price($precio_sin_iva);
        $this->Cell($left_w, 15, $line_net, $this->b, 1, 'L');



        // Derecha

        $y_after_net = $this->GetY();

        $this->SetXY($right_x, $y_secondary_row);
        $this->SetFont('Arial', '', 15);
        $medida_txt = $this->format_medida_line($article);
        $this->MultiCell($right_w, 7, $this->to_pdf_text($medida_txt), $this->b, 'R');

        $ref_line = $this->precio_por_unidad_referencia($article);
        if (!is_null($ref_line)) {
            $this->SetX($right_x);
            $this->SetFont('Arial', 'B', 15);
            $this->MultiCell($right_w, 7, $this->to_pdf_text($ref_line), $this->b, 'R');
        }

        if ($this->template->mostrar_precio_anterior && !is_null($article->previus_final_price) && $article->previus_final_price > 0) {
            $this->SetX($right_x);
            $this->SetFont('Arial', '', 15);
            $prev = 'Precio anterior: $'.Numbers::price($article->previus_final_price);
            $this->MultiCell($right_w, 7, $this->to_pdf_text($prev), $this->b, 'R');
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

        $this->SetXY($x0, $y_after_net);
        $this->print_first_image($article, $x0, $y_after_net, $left_w);

        $bar_y = $block_top + 118;
        $this->print_bar_code_centered($article, $x0, $full_w, $bar_y);

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
     * @return string|null
     */
    protected function precio_por_unidad_referencia($article)
    {
        $final = (float) $article->final_price;
        $medida = (float) $article->medida;
        if ($medida <= 0 || is_null($article->unidad_medida_id) || !$article->unidad_medida) {
            return null;
        }

        $name = mb_strtolower(trim($article->unidad_medida->name), 'UTF-8');

        if (str_contains($name, 'gramo') && !str_contains($name, 'kilo')) {
            $per = $final / $medida * 1000;
            return '$'.Numbers::price($per).' el kilo';
        }
        if (str_contains($name, 'kilo')) {
            $per = $final / $medida;
            return '$'.Numbers::price($per).' el kilo';
        }
        if ($name === 'ml' || str_contains($name, 'mililitro')) {
            $per = $final / $medida * 1000;
            return '$'.Numbers::price($per).' el litro';
        }
        if (str_contains($name, 'litro')) {
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
        $this->Image($path, $x, $y, 70, 70);
        // $this->Image($path, $x, $y, $max_w, $img_h);
        $this->SetY($y + $img_h + 2);
    }

    /**
     * Resuelve ruta o URL usable por FPDF para la primera imagen (webp → jpg en storage si hace falta).
     *
     * @param \App\Models\Article $article
     * @return string|null
     */
    protected function resolve_article_image_path($article)
    {
        if (config('app.APP_ENV') == 'local') {
            $img_url = 'https://api-colman-prueba.comerciocity.com/public/storage/171699179550596.webp';
        } else {
            $prop = env('IMAGE_URL_PROP_NAME', 'image_url');
            $img_url = $article->images[0]->{$prop};
        }

        if (is_null($img_url) || $img_url === '') {
            return null;
        }

        $array_name = basename(parse_url($img_url, PHP_URL_PATH) ?: $img_url);
        $base_name = explode('.', $array_name)[0];
        $extension = strtolower(pathinfo($array_name, PATHINFO_EXTENSION));
        $jpg_file_url = storage_path('app/public/'.$base_name.'.jpg');

        if ($extension === 'webp') {
            if (!file_exists($jpg_file_url)) {
                try {
                    $image = @imagecreatefromwebp($img_url);
                    if ($image !== false) {
                        imagejpeg($image, $jpg_file_url, 100);
                        imagedestroy($image);
                    } else {
                        return $img_url;
                    }
                } catch (\Exception $e) {
                    return $img_url;
                }
            }
            return $jpg_file_url;
        }

        return $img_url;
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
