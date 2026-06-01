<?php

namespace App\Http\Controllers\Pdf\ArticleTicket;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\EtiquetaMedidaController;
use App\Models\Article;
use Carbon\Carbon;
use Milon\Barcode\DNS1D;
use fpdf;

require(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de etiquetas con medida y propiedades configurables (tamaño y negrita por campo).
 */
class ArticleBarCodeEtiquetasPdf extends fpdf {

    /** Interlineado por defecto entre bloques (mm). */
    const DEFAULT_INTERLINEADO = 1;

    /**
     * @param string $ids IDs de artículos separados por guión
     * @param int|null $ancho Ancho de etiqueta en mm
     * @param int|null $alto Alto de etiqueta en mm
     * @param array|null $propiedades Lista de claves o configs [{key, font_size, negrita}]
     * @param int|null $codigo_barras_alto Alto de la imagen del código de barras (mm)
     * @param int|null $interlineado Espacio vertical entre bloques (mm)
     */
    function __construct($ids, $ancho = null, $alto = null, $propiedades = null, $codigo_barras_alto = null, $interlineado = null) {
        parent::__construct();
        $this->SetAutoPageBreak(true, 1);
        $this->b = 0;

        $this->user = UserHelper::user();
        $this->barcodeGenerator = new DNS1D();

        $this->etiqueta_width = (int) ($ancho ?: ($this->user->article_etiqueta_width ?: 100));
        $this->etiqueta_height = (int) ($alto ?: ($this->user->article_etiqueta_height ?: 50));
        $this->cant_article_x_etiqueta = (int) ($this->user->cant_article_x_etiqueta ?: 1);

        $this->propiedades = $this->normalize_propiedades($propiedades);

        $this->code_height = $this->resolve_code_height($codigo_barras_alto);
        $this->interlineado = $this->resolve_interlineado($interlineado);
        $this->code_width = min($this->etiqueta_width - 4, 75);

        $this->setArticles($ids);
        $this->print();
        $this->Output();
        exit;
    }

    /**
     * Alto por defecto del código de barras según el alto de la etiqueta.
     *
     * @param int $etiqueta_height
     *
     * @return int
     */
    public static function default_code_height_for_etiqueta_height($etiqueta_height)
    {
        $etiqueta_height = (int) $etiqueta_height;

        return min(14, max(8, (int) floor($etiqueta_height * 0.22)));
    }

    /**
     * @param int|null $codigo_barras_alto
     *
     * @return int
     */
    protected function resolve_code_height($codigo_barras_alto)
    {
        if ($codigo_barras_alto !== null && $codigo_barras_alto !== '') {
            return min(50, max(4, (int) $codigo_barras_alto));
        }

        return self::default_code_height_for_etiqueta_height($this->etiqueta_height);
    }

    /**
     * @param int|null $interlineado
     *
     * @return int
     */
    protected function resolve_interlineado($interlineado)
    {
        if ($interlineado !== null && $interlineado !== '') {
            return min(30, max(0, (int) $interlineado));
        }

        return self::DEFAULT_INTERLINEADO;
    }

    /**
     * @return void
     */
    protected function spacing_after_block()
    {
        $this->y += $this->interlineado;
    }

    /**
     * Normaliza propiedades: acepta claves sueltas o configs con font_size y negrita.
     *
     * @param array|null $propiedades
     *
     * @return array<int, array{key: string, font_size: int, negrita: bool}>
     */
    protected function normalize_propiedades($propiedades)
    {
        $validas = EtiquetaMedidaController::PROPIEDADES_ETIQUETA_VALIDAS;

        if (!is_array($propiedades) || !count($propiedades)) {
            return $this->default_propiedades_config();
        }

        $raw_items = [];
        foreach ($propiedades as $item) {
            if (is_string($item)) {
                $key = trim($item);
                if ($key !== '') {
                    $raw_items[] = ['key' => $key];
                }
                continue;
            }
            if (is_array($item) && !empty($item['key'])) {
                $raw_items[] = $item;
            }
        }

        if (!count($raw_items)) {
            return $this->default_propiedades_config();
        }

        $line_count = count($raw_items);
        $result = [];
        $keys_used = [];

        foreach ($raw_items as $item) {
            $key = trim((string) $item['key']);

            if ($key === '' || !in_array($key, $validas, true) || in_array($key, $keys_used, true)) {
                continue;
            }

            $keys_used[] = $key;
            $font_size = isset($item['font_size']) ? (int) $item['font_size'] : null;
            $negrita = !empty($item['negrita']);

            $result[] = [
                'key' => $key,
                'font_size' => $this->resolve_font_size_for_propiedad($key, $font_size, $line_count),
                'negrita' => $negrita,
            ];
        }

        if (!count($result)) {
            return $this->default_propiedades_config();
        }

        return $result;
    }

    /**
     * Config por defecto: nombre + código de barras.
     *
     * @return array<int, array{key: string, font_size: int, negrita: bool}>
     */
    protected function default_propiedades_config()
    {
        return [
            [
                'key' => 'nombre',
                'font_size' => $this->default_font_size_for_propiedad('nombre', 2),
                'negrita' => false,
            ],
            [
                'key' => 'codigo_barras',
                'font_size' => $this->default_font_size_for_propiedad('codigo_barras', 2),
                'negrita' => false,
            ],
        ];
    }

    /**
     * Tamaño de fuente por defecto según cantidad de campos activos.
     *
     * @param string $key
     * @param int $line_count
     *
     * @return int
     */
    protected function default_font_size_for_propiedad($key, $line_count)
    {
        $base = $this->font_size_for_lines($line_count);

        if ($key === 'precio') {
            return min(24, $base + 1);
        }

        return $base;
    }

    /**
     * Valida y acota el font_size enviado desde el front.
     *
     * @param string $key
     * @param int|null $font_size
     * @param int $line_count
     *
     * @return int
     */
    protected function resolve_font_size_for_propiedad($key, $font_size, $line_count)
    {
        if ($font_size === null || $font_size === '') {
            return $this->default_font_size_for_propiedad($key, $line_count);
        }

        return min(24, max(6, (int) $font_size));
    }

    /**
     * @param string $ids
     *
     * @return void
     */
    function setArticles($ids) {
        $this->articles = [];
        foreach (explode('-', $ids) as $id) {
            if ($id === '' || $id === null) {
                continue;
            }
            $article = Article::with(['category', 'brand'])->find($id);
            if ($article) {
                $this->articles[] = $article;
            }
        }
    }

    /**
     * @return void
     */
    function print() {
        $prints_disponibles = $this->cant_article_x_etiqueta;
        $this->AddPage('L', [$this->etiqueta_width, $this->etiqueta_height]);
        $this->y = 0;

        foreach ($this->articles as $article) {
            if ($prints_disponibles == 0) {
                $this->AddPage('L', [$this->etiqueta_width, $this->etiqueta_height]);
                $prints_disponibles = $this->cant_article_x_etiqueta;
                $this->y = 0;
            }

            $prints_disponibles--;
            $this->x = 0;
            $this->print_info($article);
        }
    }

    /**
     * @param Article $article
     *
     * @return void
     */
    function print_info($article) {
        $content_height = $this->estimate_content_height_for_article($article);
        $start_y = ($this->etiqueta_height - $content_height) / 2;

        if ($start_y < 0) {
            $start_y = 0;
        }

        $this->y = $start_y;

        foreach ($this->propiedades as $propiedad_config) {
            $this->render_propiedad($article, $propiedad_config);
        }
    }

    /**
     * @param Article $article
     *
     * @return float
     */
    protected function estimate_content_height_for_article($article)
    {
        $total = 0;

        foreach ($this->propiedades as $propiedad_config) {
            $block_height = $this->estimate_block_height($article, $propiedad_config);

            if ($block_height <= 0) {
                continue;
            }

            $total += $block_height + $this->interlineado;
        }

        if ($total > 0) {
            $total -= $this->interlineado;
        }

        return max(0, $total);
    }

    /**
     * @param Article $article
     * @param array{key: string, font_size: int, negrita: bool} $propiedad_config
     *
     * @return float
     */
    protected function estimate_block_height($article, $propiedad_config)
    {
        $key = $propiedad_config['key'];

        if ($key === 'codigo_barras') {
            if (!$article->bar_code) {
                return 0;
            }

            return $this->code_height;
        }

        $text = $this->text_for_propiedad($article, $key);

        return $this->estimate_text_height(
            $text,
            $propiedad_config['font_size'],
            $propiedad_config['negrita']
        );
    }

    /**
     * @param Article $article
     * @param string $propiedad
     *
     * @return string
     */
    protected function text_for_propiedad($article, $propiedad)
    {
        switch ($propiedad) {
            case 'nombre':
                return (string) $article->name;
            case 'codigo_proveedor':
                return (string) ($article->provider_code ?: '');
            case 'sku':
                return (string) ($article->sku ?: '');
            case 'precio':
                return $article->final_price !== null ? '$'.Numbers::price($article->final_price) : '';
            case 'categoria':
                return $article->category ? (string) $article->category->name : '';
            case 'marca':
                return $article->brand ? (string) $article->brand->name : '';
            case 'fecha_actual':
                return Carbon::now()->format('d/m/Y');
            case 'nombre_negocio':
                return (string) ($this->user->company_name ?: '');
            default:
                return '';
        }
    }

    /**
     * @param string $text
     * @param int $font_size
     * @param bool $negrita
     *
     * @return float
     */
    protected function estimate_text_height($text, $font_size, $negrita = false)
    {
        if ($text === '' || $text === null) {
            return 0;
        }

        $font_style = $negrita ? 'B' : '';
        $this->SetFont('Arial', $font_style, $font_size);
        $line_height = max(4, (int) floor($font_size * 0.55));
        $effective_width = $this->etiqueta_width - 2;

        if ($effective_width <= 0) {
            $effective_width = $this->etiqueta_width;
        }

        $text_width = $this->GetStringWidth($text);
        $lines = 1;

        if ($text_width > $effective_width) {
            $lines = (int) ceil($text_width / $effective_width);
        }

        return $lines * $line_height;
    }

    /**
     * @param int $line_count
     *
     * @return int
     */
    protected function font_size_for_lines($line_count)
    {
        if ($line_count <= 2) {
            return 11;
        }
        if ($line_count <= 4) {
            return 9;
        }
        if ($line_count <= 6) {
            return 8;
        }

        return 7;
    }

    /**
     * @param Article $article
     * @param array{key: string, font_size: int, negrita: bool} $propiedad_config
     *
     * @return void
     */
    protected function render_propiedad($article, $propiedad_config)
    {
        $key = $propiedad_config['key'];
        $font_size = $propiedad_config['font_size'];
        $negrita = $propiedad_config['negrita'];

        if ($key === 'codigo_barras') {
            if ($article->bar_code) {
                $this->print_bar_code($article->bar_code);
                $this->spacing_after_block();
            }

            return;
        }

        $text = $this->text_for_propiedad($article, $key);

        if ($this->print_text_line($text, $font_size, $negrita)) {
            $this->spacing_after_block();
        }
    }

    /**
     * @param string $text
     * @param int $font_size
     * @param bool $negrita
     *
     * @return bool
     */
    protected function print_text_line($text, $font_size, $negrita = false)
    {
        if ($text === '' || $text === null) {
            return false;
        }

        $font_style = $negrita ? 'B' : '';
        $this->SetFont('Arial', $font_style, $font_size);
        $this->x = 0;
        $line_height = max(4, (int) floor($font_size * 0.55));

        $this->MultiCell(
            $this->etiqueta_width,
            $line_height,
            $text,
            $this->b,
            'C',
            false
        );

        return true;
    }

    /**
     * @param string $code
     *
     * @return void
     */
    function print_bar_code($code) {
        $this->x = 0;
        $barcode = $this->barcodeGenerator->getBarcodePNG($code, 'C128');
        $imgData = base64_decode($barcode);
        $file = 'temp_barcode'.str_replace('/', '_', $code).'.png';
        file_put_contents($file, $imgData);

        $img_width = min($this->code_width, $this->etiqueta_width - 6);
        $start_x = ($this->etiqueta_width / 2) - ($img_width / 2);

        $this->Image($file, $start_x, $this->y, $img_width, $this->code_height);
        unlink($file);

        $this->y += $this->code_height;
    }
}
