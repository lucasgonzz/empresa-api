<?php

namespace App\Http\Controllers\Pdf\ArticleTicket;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\EtiquetaMedidaController;
use App\Models\Article;
use Carbon\Carbon;
use Milon\Barcode\DNS1D;
use fpdf;

/*
 * `require_once` y no `require` pelado: con `require`, dos clases de PDF no pueden convivir en un
 * mismo proceso (la segunda vuelve a declarar FPDF). Pasa en la suite de tests, donde esta clase se
 * carga después de `ArticleTicketDesignPdf`.
 */
require_once(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

/**
 * PDF de etiquetas con medida y propiedades configurables (tamaño y negrita por campo).
 *
 * Una etiqueta = una hoja del tamaño de la etiqueta. 🔴 Una etiqueta NUNCA se parte en dos hojas
 * (misión etiquetas-individuales-sin-partir, 4/10/2026): el salto de página automático está
 * apagado y lo que se dibuja sale de `DisposicionDeEtiquetaIndividual`, que ya garantiza que todo
 * entra en la etiqueta (achicando la letra, el código de barras, recortando con "..." o, como
 * último recurso, dejando afuera los bloques de abajo). La vista previa del modal de la SPA usa el
 * mismo algoritmo portado a JS.
 *
 * Cómo se usa:
 *
 *     new ArticleBarCodeEtiquetasPdf($ids, $ancho, $alto, $propiedades, $codigo_alto, $interlineado);
 *         // manda el PDF y corta con exit (el controlador)
 *
 *     $pdf = new ArticleBarCodeEtiquetasPdf($ids, $ancho, $alto, $propiedades, $codigo_alto, $interlineado, false);
 *     $binario = $pdf->generar();   // Output('S'), para los tests
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
     * @param bool $enviar Con true (lo de siempre) dibuja, manda el PDF al navegador y corta con
     *                     `exit`. Con false solo prepara: el PDF se pide con `generar()`.
     */
    function __construct($ids, $ancho = null, $alto = null, $propiedades = null, $codigo_barras_alto = null, $interlineado = null, $enviar = true) {
        parent::__construct();

        /* Sin salto de página automático: la disposición ya entra en la etiqueta. */
        $this->SetAutoPageBreak(false);

        $this->user = UserHelper::user();
        $this->barcodeGenerator = new DNS1D();

        $this->etiqueta_width = (int) ($ancho ?: ($this->user->article_etiqueta_width ?: 100));
        $this->etiqueta_height = (int) ($alto ?: ($this->user->article_etiqueta_height ?: 50));
        $this->cant_article_x_etiqueta = (int) ($this->user->cant_article_x_etiqueta ?: 1);

        $this->propiedades = $this->normalize_propiedades($propiedades);

        $this->code_height = $this->resolve_code_height($codigo_barras_alto);
        $this->interlineado = $this->resolve_interlineado($interlineado);

        /* Los ids pedidos, tal cual llegan de la ruta: `generar()` los carga. */
        $this->ids = $ids;

        if ($enviar) {
            $this->setArticles($ids);
            $this->print();
            $this->Output();
            exit;
        }
    }

    /**
     * Dibuja las etiquetas y devuelve el binario del PDF (sin mandarlo ni cortar el proceso).
     *
     * @return string
     */
    function generar()
    {
        $this->setArticles($this->ids);
        $this->print();

        return $this->Output('S');
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
     * Dibuja la etiqueta de un artículo según su disposición: cada línea de texto en su `y`, con su
     * letra, centrada en el ancho de la etiqueta; el código de barras en su `(x, y)`.
     *
     * @param Article $article
     *
     * @return void
     */
    function print_info($article) {
        $disposicion = DisposicionDeEtiquetaIndividual::calcular(
            $this->etiqueta_width,
            $this->etiqueta_height,
            $this->propiedades,
            $this->code_height,
            $this->interlineado,
            $this->textos_de_articulo($article),
            (bool) $article->bar_code
        );

        foreach ($disposicion['bloques'] as $bloque) {
            if ($bloque['tipo'] === 'codigo') {
                $this->print_bar_code($article->bar_code, $bloque['x'], $bloque['y'], $bloque['ancho'], $bloque['alto']);
                continue;
            }

            $this->SetFont('Arial', $bloque['negrita'] ? 'B' : '', $bloque['tamano']);

            foreach ($bloque['lineas'] as $indice => $linea) {
                $this->x = 0;
                $this->y = $bloque['y'] + $indice * $bloque['alto_linea'];

                /* Cell hace su propio utf8_decode: se le pasa el texto en UTF-8. */
                $this->Cell($this->etiqueta_width, $bloque['alto_linea'], $linea, 0, 2, 'C');
            }
        }
    }

    /**
     * El texto de cada propiedad configurada (menos el código de barras, que es una imagen).
     *
     * @param Article $article
     *
     * @return array<string, string> `key => texto` en UTF-8
     */
    protected function textos_de_articulo($article)
    {
        $textos = [];

        foreach ($this->propiedades as $propiedad_config) {
            $key = $propiedad_config['key'];

            if ($key === 'codigo_barras') {
                continue;
            }

            $textos[$key] = $this->text_for_propiedad($article, $key);
        }

        return $textos;
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
     * Dibuja el código de barras (C128) en `(x, y)` con el tamaño que dio la disposición.
     *
     * El PNG se escribe en un archivo temporal del directorio actual (FPDF lo lee de ahí) y se
     * borra apenas se dibuja.
     *
     * @param string $code
     * @param float $x
     * @param float $y
     * @param float $ancho
     * @param float $alto
     *
     * @return void
     */
    function print_bar_code($code, $x, $y, $ancho, $alto) {
        /* Con ancho o alto en 0 FPDF calcula el tamaño de la imagen solo: en una etiqueta tan chica no se dibuja. */
        if ($ancho <= 0 || $alto <= 0) {
            return;
        }

        $barcode = $this->barcodeGenerator->getBarcodePNG($code, 'C128');
        $imgData = base64_decode($barcode);
        $file = 'temp_barcode'.str_replace('/', '_', $code).'.png';
        file_put_contents($file, $imgData);

        $this->Image($file, $x, $y, $ancho, $alto);
        unlink($file);
    }
}
