<?php

namespace App\Http\Controllers\Pdf\ArticleTicket;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ArticleTicketDesignHelper;
use App\Http\Controllers\Helpers\GeneralHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use App\Models\PriceType;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Milon\Barcode\DNS1D;

/*
 * `require_once` y no `require` pelado: con `require`, dos clases de PDF no pueden convivir en un
 * mismo proceso (la segunda vuelve a declarar FPDF). Ver el bloque de SaleTicketPdf.
 */
require_once(__DIR__.'/../../CommonLaravel/fpdf/fpdf.php');

/**
 * Etiquetas de góndola dibujadas según un diseño del negocio (misión disenos-etiquetas-gondola,
 * 29/9/2026). La pide `ArticleController@ticketsPdf` cuando llega `?article_ticket_design_id=` de
 * un diseño del dueño; sin ese parámetro sigue saliendo `ArticleTicketPdf`, que NO se tocó.
 *
 * Con el diseño de siempre (`ArticleTicketDesignHelper::diseno_actual()`) reproduce la etiqueta
 * de `ArticleTicketPdf`: mismos márgenes, mismo ancho y alto, mismas fuentes y los mismos campos en
 * las mismas coordenadas.
 *
 * Cómo se usa (sin `exit`, para poder testearla):
 *
 *     $pdf = new ArticleTicketDesignPdf($diseno, '12-15-18', $owner_id);
 *     $binario = $pdf->generar();   // Output('S')
 *
 * -------------------------------------------------------------------------------------------
 *  Decisiones que no se ven a simple vista
 * -------------------------------------------------------------------------------------------
 *
 * - TEXTO: el `Cell()` del fpdf del proyecto hace `utf8_decode()` de todo lo que recibe (o sea,
 *   lo pasa a latin1). Acá cada texto se convierte primero a CP1252 —la codificación real de las
 *   fuentes core de FPDF— y se le pasa a `Cell()` "envuelto" (`latin1 -> utf8`), que el
 *   `utf8_decode()` desenvuelve byte por byte. Así se imprimen igual que antes la ñ y los acentos,
 *   y además el "…" del recorte, el € y las comillas tipográficas, que en latin1 no existen y
 *   `utf8_decode()` volvía "?". Las medidas (`GetStringWidth`) se toman sobre esos mismos bytes.
 * - NADA SE DESBORDA: cada campo se dibuja adentro de un recorte (`re W n`) del tamaño de su
 *   recuadro. Un texto de 120 pt en un campo de 5 mm se corta, no pisa al de al lado ni a la
 *   etiqueta de abajo. Como el recorte guarda y restaura el estado gráfico (`q`/`Q`), y la fuente
 *   es parte de ese estado, la fuente se vuelve a emitir adentro de cada recorte.
 * - ERRORES: un campo que falla (una imagen ilegible, un código de barras que C128 no puede
 *   codificar) se deja en blanco y se loguea; nunca rompe el PDF entero.
 * - SALTO DE PÁGINA: por cuenta propia (auto page break apagado), según las filas que entran con
 *   el alto del diseño (`filas_por_hoja()`), no con el `y >= 280` de `ArticleTicketPdf`.
 *
 * PHP 7.4 estricto: ninguna sintaxis de PHP 8 en ningún camino de este archivo.
 */
class ArticleTicketDesignPdf extends \fpdf
{
    /** Puntos tipográficos a mm. */
    const PT_A_MM = 0.3528;

    /** Interlineado de un campo con saltos de línea: `tamano * 0.3528 * 1.15` mm. */
    const INTERLINEADO = 1.15;

    /** "…" en CP1252. */
    const ELIPSIS = "\x85";

    /** @var array El diseño ya normalizado. */
    protected $diseno;

    /** @var int */
    protected $owner_id;

    /** @var int[] Los ids pedidos, en orden (con repetidos, si los hay). */
    protected $ids;

    /** @var \Illuminate\Support\Collection Los artículos del dueño, por id. */
    protected $articulos;

    /** @var array `[price_type_id => nombre]` de las listas del dueño que usa el diseño. */
    protected $listas = array();

    /**
     * @var bool Si el dueño trabaja con listas de precios. Sin listas, `precio_lista` imprime el
     *           `final_price` del artículo (igual que `ArticleTicketPdf::get_price()`).
     */
    protected $usa_listas = false;

    /** @var float Ancho real de la etiqueta en la hoja (sin redondear). */
    protected $ancho_etiqueta;

    /** @var float */
    protected $alto_etiqueta;

    /** @var int */
    protected $columnas;

    /** @var int */
    protected $filas_por_hoja;

    /** @var \Milon\Barcode\DNS1D|null */
    protected $generador_de_codigos = null;

    /** @var string[] Archivos temporales que hay que borrar al terminar. */
    protected $temporales = array();

    /**
     * @var array `[codigo => archivo png]`: un código de barras que se repite (el mismo artículo
     *            pedido varias veces) se genera una sola vez y FPDF reusa la imagen.
     */
    protected $codigos_generados = array();

    /** @var string Fecha de impresión, `d/m/y`, una sola para todo el PDF. */
    protected $fecha;

    /**
     * @param  array   $diseno    El JSON del diseño (se vuelve a normalizar contra el dueño).
     * @param  string  $ids       Ids de artículos separados por `-` (el formato de la ruta).
     * @param  int     $owner_id  El dueño: solo se imprimen SUS artículos y SUS listas.
     */
    function __construct($diseno, $ids, $owner_id)
    {
        parent::__construct('P', 'mm', 'A4');

        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);

        $this->owner_id = $owner_id;

        list($valido, $normalizado) = ArticleTicketDesignHelper::normalizar_diseno($diseno, $owner_id);

        $this->diseno = $valido ? $normalizado : ArticleTicketDesignHelper::diseno_actual(null);

        $this->columnas = $this->diseno['columnas'];
        $this->ancho_etiqueta = ArticleTicketDesignHelper::ancho_etiqueta($this->columnas);
        $this->alto_etiqueta = (float) $this->diseno['alto_mm'];
        $this->filas_por_hoja = ArticleTicketDesignHelper::filas_por_hoja($this->alto_etiqueta);

        $this->ids = $this->parsear_ids($ids);

        $this->fecha = Carbon::now()->format('d/m/y');
    }

    /**
     * Dibuja las etiquetas y devuelve el binario del PDF.
     *
     * @return string
     */
    function generar()
    {
        try {
            $this->cargar_articulos();
            $this->cargar_listas();

            $por_hoja = $this->columnas * $this->filas_por_hoja;

            $indice = 0;

            foreach ($this->ids as $id) {

                if (!isset($this->articulos[$id])) {
                    continue;
                }

                $en_la_hoja = $indice % $por_hoja;

                if ($en_la_hoja == 0) {
                    $this->AddPage();
                }

                $columna = $en_la_hoja % $this->columnas;
                $fila = (int) floor($en_la_hoja / $this->columnas);

                $x = ArticleTicketDesignHelper::MARGEN + ($columna * $this->ancho_etiqueta);
                $y = ArticleTicketDesignHelper::MARGEN + ($fila * $this->alto_etiqueta);

                $this->dibujar_etiqueta($this->articulos[$id], $x, $y);

                $indice++;
            }

            if ($this->page == 0) {
                $this->AddPage();
            }

            return $this->Output('S');

        } finally {
            $this->borrar_temporales();
        }
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Carga en lote
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Ids numéricos del parámetro de la ruta, en orden. Lo que no es un número se descarta.
     *
     * @param  string  $ids
     * @return int[]
     */
    protected function parsear_ids($ids)
    {
        $resultado = array();

        foreach (explode('-', (string) $ids) as $id) {
            $id = trim($id);

            if ($id !== '' && ctype_digit($id) && (int) $id > 0) {
                $resultado[] = (int) $id;
            }
        }

        return $resultado;
    }

    /**
     * Los artículos del dueño en UNA consulta, con solo las relaciones que usa el diseño.
     *
     * @return void
     */
    protected function cargar_articulos()
    {
        $tipos = $this->tipos_del_diseno();

        $relaciones = array();

        $por_tipo = array(
            'categoria'     => 'category',
            'sub_categoria' => 'sub_category',
            'marca'         => 'brand',
            'proveedor'     => 'provider',
            'unidad_medida' => 'unidad_medida',
            'imagen'        => 'images',
            'precio_lista'  => 'price_types',
            'descripcion'   => 'descriptions',
        );

        foreach ($por_tipo as $tipo => $relacion) {
            if (isset($tipos[$tipo])) {
                $relaciones[] = $relacion;
            }
        }

        if (count($this->ids) == 0) {
            $this->articulos = collect();
            return;
        }

        $this->articulos = Article::where('user_id', $this->owner_id)
                                    ->whereIn('id', array_values(array_unique($this->ids)))
                                    ->with($relaciones)
                                    ->get()
                                    ->keyBy('id');
    }

    /**
     * Nombre de cada lista que usa el diseño, solo si es del dueño. Una lista que ya no existe no
     * imprime nada.
     *
     * @return void
     */
    protected function cargar_listas()
    {
        $ids = array();

        foreach ($this->diseno['elementos'] as $elemento) {
            if ($elemento['tipo'] === 'precio_lista') {
                $ids[] = $elemento['price_type_id'];
            }
        }

        if (count($ids) == 0) {
            return;
        }

        $dueno = User::find($this->owner_id);

        $this->usa_listas = !is_null($dueno) && UserHelper::uses_listas_de_precio($dueno);

        $listas = PriceType::where('user_id', $this->owner_id)
                            ->whereIn('id', array_values(array_unique($ids)))
                            ->get();

        foreach ($listas as $lista) {
            $this->listas[(int) $lista->id] = (string) $lista->name;
        }
    }

    /**
     * @return array `[tipo => true]`
     */
    protected function tipos_del_diseno()
    {
        $tipos = array();

        foreach ($this->diseno['elementos'] as $elemento) {
            $tipos[$elemento['tipo']] = true;
        }

        return $tipos;
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Dibujo
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Una etiqueta: sus campos y, si el diseño lo pide, el marco.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float                $x
     * @param  float                $y
     * @return void
     */
    protected function dibujar_etiqueta($articulo, $x, $y)
    {
        foreach ($this->diseno['elementos'] as $elemento) {
            $this->dibujar_elemento($articulo, $elemento, $x + $elemento['x'], $y + $elemento['y']);
        }

        if ($this->diseno['marco']) {
            $this->Rect($x, $y, $this->ancho_etiqueta, $this->alto_etiqueta);
        }
    }

    /**
     * Un campo, recortado a su recuadro. Si falla queda en blanco (y se loguea).
     *
     * @param  \App\Models\Article  $articulo
     * @param  array                $elemento
     * @param  float                $x  Esquina del campo en la hoja.
     * @param  float                $y
     * @return void
     */
    protected function dibujar_elemento($articulo, array $elemento, $x, $y)
    {
        $w = (float) $elemento['w'];
        $h = (float) $elemento['h'];

        $this->_out(sprintf(
            'q %.2F %.2F %.2F %.2F re W n',
            $x * $this->k,
            ($this->h - $y) * $this->k,
            $w * $this->k,
            -$h * $this->k
        ));

        try {
            if ($elemento['tipo'] === 'codigo_barras_imagen') {
                $this->dibujar_codigo_de_barras($articulo, $x, $y, $w, $h);
            } else if ($elemento['tipo'] === 'imagen') {
                $this->dibujar_imagen($articulo, $x, $y, $w, $h);
            } else {
                $texto = $this->texto_del_elemento($articulo, $elemento);

                if ($texto !== '') {
                    $this->dibujar_texto($texto, $elemento, $x, $y, $w, $h);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('ArticleTicketDesignPdf: no se pudo dibujar el campo '.$elemento['tipo']
                .' del artículo '.$articulo->id.': '.$e->getMessage());
        }

        $this->_out('Q');
    }

    /**
     * El texto (UTF-8) que imprime un campo de texto para un artículo. '' si no hay nada.
     *
     * @param  \App\Models\Article  $articulo
     * @param  array                $elemento
     * @return string
     */
    protected function texto_del_elemento($articulo, array $elemento)
    {
        switch ($elemento['tipo']) {

            case 'nombre':
                return (string) $articulo->name;

            case 'precio_final':
                $precio = $this->con_signo($articulo->final_price);
                return $elemento['rotulo'] ? 'Precio: '.$precio : $precio;

            case 'precio_lista':
                $lista_id = $elemento['price_type_id'];

                if (!isset($this->listas[$lista_id])) {
                    return '';
                }

                $precio = $this->con_signo($this->precio_de_lista($articulo, $lista_id));
                return $elemento['rotulo'] ? $this->listas[$lista_id].': '.$precio : $precio;

            case 'codigo_barras_texto':
                return (string) $articulo->bar_code;

            case 'codigo_proveedor':
                return (string) $articulo->provider_code;

            case 'codigo_interno':
                /*
                 * El SKU: el campo que la ficha del artículo describe como "Código interno de tu
                 * negocio". `articles.num` ya no se completa (quedó comentado en
                 * ArticleController@store) y el "N°" del listado es el id de la tabla.
                 */
                return (string) $articulo->sku;

            case 'categoria':
                return $this->nombre_de($articulo->category);

            case 'sub_categoria':
                return $this->nombre_de($articulo->sub_category);

            case 'marca':
                return $this->nombre_de($articulo->brand);

            case 'proveedor':
                return $this->nombre_de($articulo->provider);

            case 'descripcion':
                return $this->descripcion($articulo);

            case 'unidad_medida':
                return $this->nombre_de($articulo->unidad_medida);

            case 'stock':
                return $this->stock($articulo->stock);

            case 'fecha_impresion':
                return $this->fecha;

            case 'texto_fijo':
                return isset($elemento['texto']) ? (string) $elemento['texto'] : '';
        }

        return '';
    }

    /**
     * El precio de un `precio_lista`, con el mismo criterio que `ArticleTicketPdf::get_price()`:
     *
     *   - El dueño no trabaja con listas -> el `final_price` del artículo.
     *   - Trabaja con listas -> el precio final de la lista (pivot `article_price_type.final_price`)
     *     y, si el artículo no tiene pivot para esa lista, su `final_price`.
     *
     * @param  \App\Models\Article  $articulo
     * @param  int                  $lista_id
     * @return mixed
     */
    protected function precio_de_lista($articulo, $lista_id)
    {
        if (!$this->usa_listas) {
            return $articulo->final_price;
        }

        foreach ($articulo->price_types as $lista) {
            if ((int) $lista->id === (int) $lista_id
                && isset($lista->pivot)
                && !is_null($lista->pivot->final_price)) {
                return $lista->pivot->final_price;
            }
        }

        return $articulo->final_price;
    }

    /**
     * `$` + `Numbers::price()`, pegado como en `ArticleTicketPdf` ("$1.234").
     *
     * @param  mixed  $precio
     * @return string
     */
    protected function con_signo($precio)
    {
        if (is_null($precio) || $precio === '') {
            $precio = 0;
        }

        return '$'.Numbers::price($precio);
    }

    /**
     * @param  mixed  $modelo
     * @return string
     */
    protected function nombre_de($modelo)
    {
        if (is_null($modelo) || is_null($modelo->name)) {
            return '';
        }

        return (string) $modelo->name;
    }

    /**
     * La descripción del artículo en texto plano: la columna `descripcion` y, si está vacía, la
     * primera de sus `descriptions`.
     *
     * @param  \App\Models\Article  $articulo
     * @return string
     */
    protected function descripcion($articulo)
    {
        $texto = (string) $articulo->descripcion;

        if (trim(strip_tags($texto)) === '' && count($articulo->descriptions) > 0) {
            $texto = (string) $articulo->descriptions->first()->content;
        }

        $texto = preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $texto);
        $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES, 'UTF-8');

        return trim($texto);
    }

    /**
     * El stock, entero si es entero ("12"), con dos decimales si no ("12,50").
     *
     * @param  mixed  $stock
     * @return string
     */
    protected function stock($stock)
    {
        if (is_null($stock) || $stock === '') {
            return '';
        }

        $stock = (float) $stock;

        if (floor($stock) == $stock) {
            return number_format($stock, 0, '', '.');
        }

        return number_format($stock, 2, ',', '.');
    }

    /**
     * Un texto en su recuadro.
     *
     *   - Sin saltos de línea: un solo renglón, centrado verticalmente, recortado con "…" al ancho.
     *   - Con saltos de línea: renglones de `tamano * 0.3528 * 1.15` mm desde arriba, cortados al
     *     alto del campo (el último que entra termina en "…" si sobraba texto).
     *
     * @param  string  $texto  UTF-8.
     * @param  array   $elemento
     * @param  float   $x
     * @param  float   $y
     * @param  float   $w
     * @param  float   $h
     * @return void
     */
    protected function dibujar_texto($texto, array $elemento, $x, $y, $w, $h)
    {
        $this->fuente($elemento);

        $ancho_util = $w - (2 * $this->cMargin);

        if (!$elemento['saltos_de_linea']) {
            $renglon = $this->a_cp1252($this->en_un_renglon($texto));
            $renglon = $this->recortar($renglon, $ancho_util, false);

            $this->SetXY($x, $y);
            $this->celda($w, $h, $renglon, $elemento['alineacion']);

            return;
        }

        $alto_renglon = $elemento['tamano'] * self::PT_A_MM * self::INTERLINEADO;

        $maximo = max(1, (int) floor(($h / $alto_renglon) + 0.0001));

        $renglones = $this->partir_en_renglones($this->a_cp1252($texto), $ancho_util);

        if (count($renglones) > $maximo) {
            $renglones = array_slice($renglones, 0, $maximo);
            $renglones[$maximo - 1] = $this->recortar($renglones[$maximo - 1], $ancho_util, true);
        }

        foreach ($renglones as $i => $renglon) {
            $this->SetXY($x, $y + ($i * $alto_renglon));
            $this->celda($w, $alto_renglon, $renglon, $elemento['alineacion']);
        }
    }

    /**
     * Fija la fuente del campo y se asegura de que quede emitida en la página: estamos adentro de
     * un `q`, y el `Q` del campo anterior pudo haber restaurado otra. El `SetFont()` del fpdf del
     * proyecto la emite siempre, pero el de FPDF original no la repite si no cambió; por eso se
     * mira si el contenido de la página creció y, si no, se emite a mano.
     *
     * @param  array  $elemento
     * @return void
     */
    protected function fuente(array $elemento)
    {
        $largo_antes = strlen($this->pages[$this->page]);

        $this->SetFont('Arial', $elemento['negrita'] ? 'B' : '', $elemento['tamano']);

        if (strlen($this->pages[$this->page]) === $largo_antes) {
            $this->_out(sprintf('BT /F%d %.2F Tf ET', $this->CurrentFont['i'], $this->FontSizePt));
        }
    }

    /**
     * `Cell()` sin borde, con el texto en CP1252 envuelto para que el `utf8_decode()` del fpdf del
     * proyecto lo deje byte por byte como está (ver el PHPDoc de la clase).
     *
     * @param  float   $w
     * @param  float   $h
     * @param  string  $texto_cp1252
     * @param  string  $alineacion
     * @return void
     */
    protected function celda($w, $h, $texto_cp1252, $alineacion)
    {
        $this->Cell($w, $h, mb_convert_encoding($texto_cp1252, 'UTF-8', 'ISO-8859-1'), 0, 0, $alineacion);
    }

    /**
     * UTF-8 -> CP1252 (lo que no existe en CP1252 se translitera o, en el peor caso, sale "?").
     *
     * @param  string  $texto
     * @return string
     */
    protected function a_cp1252($texto)
    {
        $texto = (string) $texto;

        $convertido = @iconv('UTF-8', 'CP1252//TRANSLIT', $texto);

        if ($convertido === false) {
            $convertido = @iconv('UTF-8', 'CP1252//IGNORE', $texto);
        }

        if ($convertido === false) {
            $convertido = utf8_decode($texto);
        }

        return $convertido;
    }

    /**
     * Todo el texto en un renglón: saltos de línea y espacios repetidos pasan a un espacio.
     *
     * @param  string  $texto  UTF-8.
     * @return string
     */
    protected function en_un_renglon($texto)
    {
        $una_linea = preg_replace('/\s+/u', ' ', (string) $texto);

        if (is_null($una_linea)) {
            $una_linea = str_replace(array("\r", "\n", "\t"), ' ', (string) $texto);
        }

        return trim($una_linea);
    }

    /**
     * Recorta un renglón (CP1252) para que entre en el ancho, terminando en "…" si hubo que
     * cortar. Con `$forzar_elipsis` termina en "…" aunque ya entrara (el último renglón visible de
     * un texto que sigue).
     *
     * @param  string  $renglon
     * @param  float   $ancho
     * @param  bool    $forzar_elipsis
     * @return string
     */
    protected function recortar($renglon, $ancho, $forzar_elipsis)
    {
        if (!$forzar_elipsis && $this->GetStringWidth($renglon) <= $ancho) {
            return $renglon;
        }

        $renglon = rtrim($renglon);

        while ($renglon !== '' && $this->GetStringWidth($renglon.self::ELIPSIS) > $ancho) {
            $renglon = rtrim(substr($renglon, 0, -1));
        }

        if ($this->GetStringWidth($renglon.self::ELIPSIS) > $ancho) {
            return '';
        }

        return $renglon.self::ELIPSIS;
    }

    /**
     * Parte un texto (CP1252) en renglones que entran en el ancho: por palabras, respetando los
     * saltos de línea del texto, y cortando por letra la palabra que sola no entra.
     *
     * @param  string  $texto
     * @param  float   $ancho
     * @return string[]
     */
    protected function partir_en_renglones($texto, $ancho)
    {
        $renglones = array();

        $parrafos = explode("\n", str_replace(array("\r\n", "\r"), "\n", trim($texto)));

        foreach ($parrafos as $parrafo) {

            $palabras = preg_split('/[ \t]+/', trim($parrafo));

            $actual = '';

            foreach ($palabras as $palabra) {

                if ($palabra === '') {
                    continue;
                }

                $candidato = $actual === '' ? $palabra : $actual.' '.$palabra;

                if ($this->GetStringWidth($candidato) <= $ancho) {
                    $actual = $candidato;
                    continue;
                }

                if ($actual !== '') {
                    $renglones[] = $actual;
                    $actual = '';
                }

                /* La palabra sola no entra: se corta por letra. */
                while ($this->GetStringWidth($palabra) > $ancho && strlen($palabra) > 1) {
                    $corte = strlen($palabra) - 1;

                    while ($corte > 1 && $this->GetStringWidth(substr($palabra, 0, $corte)) > $ancho) {
                        $corte--;
                    }

                    $renglones[] = substr($palabra, 0, $corte);
                    $palabra = substr($palabra, $corte);
                }

                $actual = $palabra;
            }

            $renglones[] = $actual;
        }

        /* Sin renglones vacíos al final (un texto que terminaba en salto de línea). */
        while (count($renglones) > 0 && trim($renglones[count($renglones) - 1]) === '') {
            array_pop($renglones);
        }

        return $renglones;
    }

    /**
     * El código de barras C128 como imagen, estirado al recuadro (como en `ArticleTicketPdf`).
     * Nada si el artículo no tiene código.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float                $x
     * @param  float                $y
     * @param  float                $w
     * @param  float                $h
     * @return void
     */
    protected function dibujar_codigo_de_barras($articulo, $x, $y, $w, $h)
    {
        $codigo = trim((string) $articulo->bar_code);

        if ($codigo === '') {
            return;
        }

        if (isset($this->codigos_generados[$codigo])) {
            $this->Image($this->codigos_generados[$codigo], $x, $y, $w, $h, 'PNG');
            return;
        }

        if (is_null($this->generador_de_codigos)) {
            $this->generador_de_codigos = new DNS1D();
        }

        $png = $this->generador_de_codigos->getBarcodePNG($codigo, 'C128');

        if (!is_string($png) || $png === '') {
            return;
        }

        $bytes = base64_decode($png);

        if ($bytes === false || $bytes === '') {
            return;
        }

        $archivo = $this->temporal('png');

        file_put_contents($archivo, $bytes);

        $this->Image($archivo, $x, $y, $w, $h, 'PNG');

        $this->codigos_generados[$codigo] = $archivo;
    }

    /**
     * La primera imagen del artículo, CONTENIDA en el recuadro (sin deformar, centrada).
     *
     * La ruta la resuelve `GeneralHelper::pdf_image_path()`, el mismo camino que usan los otros
     * PDF con imágenes: lee del disco cuando la URL apunta al `storage` de la propia API, pasa a
     * JPEG lo que FPDF no sabe dibujar (webp, png con alfa, gif) con GD, y devuelve null si la
     * imagen no se puede leer. Null -> el campo queda en blanco y el PDF sale igual.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float                $x
     * @param  float                $y
     * @param  float                $w
     * @param  float                $h
     * @return void
     */
    protected function dibujar_imagen($articulo, $x, $y, $w, $h)
    {
        if (count($articulo->images) == 0) {
            return;
        }

        $url = $articulo->images->first()->hosting_url;

        $ruta = GeneralHelper::pdf_image_path($url);

        if (is_null($ruta) || !is_file($ruta)) {
            return;
        }

        $medidas = @getimagesize($ruta);

        if ($medidas === false || $medidas[0] <= 0 || $medidas[1] <= 0) {
            return;
        }

        $escala = min($w / $medidas[0], $h / $medidas[1]);

        $ancho = $medidas[0] * $escala;
        $alto = $medidas[1] * $escala;

        $tipo = $medidas[2] === IMAGETYPE_PNG ? 'PNG' : 'JPG';

        $this->Image($ruta, $x + (($w - $ancho) / 2), $y + (($h - $alto) / 2), $ancho, $alto, $tipo);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  Temporales
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Un archivo temporal nuevo en `sys_get_temp_dir()` (nombre único: FPDF cachea las imágenes
     * por nombre de archivo, así que dos códigos distintos no pueden compartirlo).
     *
     * @param  string  $extension
     * @return string
     */
    protected function temporal($extension)
    {
        $archivo = rtrim(sys_get_temp_dir(), '/\\').DIRECTORY_SEPARATOR
            .'etiqueta_'.str_replace('.', '', uniqid('', true)).'_'.count($this->temporales).'.'.$extension;

        $this->temporales[] = $archivo;

        return $archivo;
    }

    /**
     * @return void
     */
    protected function borrar_temporales()
    {
        foreach ($this->temporales as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        $this->temporales = array();
    }
}
