<?php

namespace App\Http\Controllers\Pdf\Layout;

use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDePaginaPdf;
use App\Http\Controllers\Helpers\PdfLayout\FuenteDeCamposPdf;

/**
 * Mide y dibuja una zona de un diseño de página ("superior" o "pie") con sus cajas, sus saltos de
 * fila y sus bloques fijos (misión diseno-pdf-configurable, 1/10/2026). Es el mismo motor para la
 * venta (`SaleLayoutPdf`), el presupuesto y el pedido online (`ProfileDocumentPdf`): no lee ningún
 * modelo, le pide cada valor a una `FuenteDeCamposPdf`.
 *
 * 🔴 UNA SOLA RUTINA MIDE Y DIBUJA (recorrer_zona(), con el flag $dibujar). En `NewSalePdf` el
 * alto que se reserva para el pie (estimate_*) y el que se dibuja (print_*) son funciones
 * gemelas que hay que mantener en sincro a mano, y cuando se separaron el texto se escribió encima
 * del borde. Acá las dos pasadas recorren las mismas filas, parten los renglones con la MISMA
 * función (renglones_de_campo()) y suman los mismos altos: no hay dos cuentas que se puedan
 * separar. Por eso el motor no usa Write()/MultiCell() de FPDF, que cortan el texto por su cuenta:
 * corta él mismo, con las métricas de la letra, y dibuja cada línea con Cell().
 *
 * Cómo se arma una zona (plan de la misión, §4.3–4.5):
 * - Grilla de 12 columnas, gutter G = 2 mm, unidad U = (ancho + G) / 12; una caja de N columnas
 *   mide N·U − G y arranca en x0 + columna·U. Si no entra en lo que queda de la fila, baja.
 *   `salto_de_fila` corta la fila. Un bloque fijo ocupa una fila entera.
 * - El alto de una fila es el de su caja más alta, y todas sus cajas se dibujan con ese alto.
 *   2 mm entre filas. Una caja sin ningún campo con valor no se dibuja (aunque tenga título) y sus
 *   columnas quedan vacías; una fila sin nada dibujado no ocupa alto.
 * - Una caja: 2 mm de relleno a los costados y 1,5 mm arriba y abajo; título opcional (Arial
 *   negrita 9, gris 90, alto de línea 4,5, 0,5 mm antes del primer campo); estilo borde (línea
 *   fina negra), gris (fondo 247 con borde 210) o ninguno.
 * - Un campo: alto de línea = tamaño × 0,5 mm. A la izquierda: rótulo en negrita y valor normal
 *   (el valor corta en varias líneas y las siguientes arrancan en el borde de la caja, el patrón
 *   de AfipPdfHelper::print_label_value_multiline()). Al centro o a la derecha: el renglón entero
 *   ("Rótulo: valor") con el estilo del campo. Una lista va un renglón por elemento, con el rótulo
 *   solo en el primero. Un campo sin valor no imprime nada, ni el rótulo.
 */
class MotorDeCajasPdf
{
    const COLUMNAS = 12;
    const GUTTER = 2;
    const SEPARACION_ENTRE_FILAS = 2;
    const RELLENO_COSTADOS = 2;
    const RELLENO_VERTICAL = 1.5;
    const TITULO_TAMANO = 9;
    const TITULO_ALTO_DE_LINEA = 4.5;
    const TITULO_SEPARACION = 0.5;
    /** Alto de línea de un campo por cada punto de su tamaño (9 pt → 4,5 mm). */
    const ALTO_DE_LINEA_POR_PUNTO = 0.5;
    /** Margen interno de Cell() de FPDF (cMargin): 1 mm con los márgenes por defecto. */
    const MARGEN_DE_CELDA = 1;
    /** Milímetros por punto tipográfico. */
    const MM_POR_PUNTO = 25.4 / 72;

    /**
     * Anchos de letra de las cuatro variantes de Helvetica (Arial en FPDF), por archivo de
     * métricas. Se leen una sola vez por proceso.
     *
     * @var array<string, array<string, int>>
     */
    private static $metricas = [];

    /** @var FuenteDeCamposPdf De dónde salen los valores. */
    private $fuente;

    /** @var array<string, array> Catálogo del modelo de la fuente, por key. */
    private $catalogo;

    /** @var float Dónde arranca la zona (x0 de la hoja). */
    private $x0;

    /** @var float Ancho útil de la hoja. */
    private $ancho;

    /**
     * Bloques fijos que sabe dibujar el comprobante: key => callable($pdf, array $item, bool $dibujar).
     * Con $dibujar false devuelve el alto que va a ocupar (0 si no dibuja nada); con true lo
     * dibuja en $pdf->y y deja $pdf->y debajo de lo dibujado.
     *
     * @var array<string, callable>
     */
    private $fijos;

    /** @var array<string, mixed> Valores ya pedidos a la fuente (el pie se mide y se dibuja varias veces). */
    private $valores;

    /**
     * @param FuenteDeCamposPdf     $fuente
     * @param float                 $x0    Dónde arranca la zona.
     * @param float                 $ancho Ancho útil de la hoja.
     * @param array<string, callable> $fijos Ver $fijos.
     */
    public function __construct(FuenteDeCamposPdf $fuente, $x0, $ancho, $fijos = [])
    {
        $this->fuente = $fuente;
        $this->x0 = (float) $x0;
        $this->ancho = (float) $ancho;
        $this->fijos = is_array($fijos) ? $fijos : [];
        $this->valores = [];

        $this->catalogo = [];
        foreach (CatalogoDeCamposPdf::campos($fuente->model_name()) as $campo) {
            $this->catalogo[$campo['key']] = $campo;
        }
    }

    /**
     * La hoja de un perfil con diseño de página (plan §4.1). Es la MISMA cuenta para la venta y
     * para el presupuesto y el pedido: por eso vive acá y no en cada PDF.
     *
     * - Página W × H vertical: W = paper_width_mm (210 si falta), H = paper_height_mm (297 si falta).
     * - Margen M = margin_mm acotado a 0..20 (5 si falta), el mismo para los cuatro lados.
     * - Ancho útil = imprimible − 2M, centrado en la hoja (el diseñador guarda imprimible = W).
     * - El encabezado arranca en y = M y se puede dibujar hasta H − M − 7 (285 en A4 con 5 mm,
     *   el límite de siempre).
     *
     * @param \App\Models\PdfColumnProfile $perfil
     * @return array{ancho_de_hoja: float, alto_de_hoja: float, margen: float, x0: float, ancho_util: float, y0: float, limite_inferior: float, margen_derecho: float}
     */
    public static function geometria_de_la_hoja($perfil)
    {
        $ancho_de_hoja = (float) $perfil->paper_width_mm > 0 ? (float) $perfil->paper_width_mm : 210;
        $alto_de_hoja = (float) $perfil->paper_height_mm > 0 ? (float) $perfil->paper_height_mm : DisenoDePaginaPdf::ALTO_DE_HOJA_POR_DEFECTO;

        /** Siempre vertical (FPDF también la daría vuelta). */
        if ($ancho_de_hoja > $alto_de_hoja) {
            $intercambio = $ancho_de_hoja;
            $ancho_de_hoja = $alto_de_hoja;
            $alto_de_hoja = $intercambio;
        }

        $margen = (is_null($perfil->margin_mm) || $perfil->margin_mm === '')
            ? 5
            : max(DisenoDePaginaPdf::MARGEN_MIN, min(DisenoDePaginaPdf::MARGEN_MAX, (int) $perfil->margin_mm));

        $imprimible = (float) $perfil->printable_width_mm > 0
            ? min((float) $perfil->printable_width_mm, $ancho_de_hoja)
            : $ancho_de_hoja;

        $x0 = ($ancho_de_hoja - $imprimible) / 2 + $margen;
        $ancho_util = $imprimible - 2 * $margen;

        return [
            'ancho_de_hoja' => $ancho_de_hoja,
            'alto_de_hoja' => $alto_de_hoja,
            'margen' => $margen,
            'x0' => $x0,
            'ancho_util' => $ancho_util,
            'y0' => $margen,
            'limite_inferior' => $alto_de_hoja - $margen - 7,
            'margen_derecho' => $ancho_de_hoja - $x0 - $ancho_util,
        ];
    }

    /**
     * Alto que va a ocupar una zona, sin dibujar nada.
     *
     * @param mixed $pdf   Instancia FPDF (solo la usan los bloques fijos para medirse).
     * @param array $items Ítems de la zona (page_layout normalizado).
     * @return float
     */
    public function medir_zona($pdf, $items)
    {
        $recorrido = $this->recorrer_zona($pdf, $items, 0, false, null, null);

        return $recorrido['alto'];
    }

    /**
     * Dibuja una zona arrancando en $y y deja $pdf->y debajo de lo dibujado.
     *
     * Con $saltar_de_hoja (y su $limite), una fila que ya no entra en lo que queda de la hoja sigue
     * en la siguiente: el callable salta de hoja y devuelve la y donde seguir. Es para el pie de la
     * última hoja cuando es más alto que lo que queda libre incluso en una hoja nueva (una hoja
     * chica con un diseño cargado): sin esto, las últimas filas se caían del papel. Nunca salta
     * antes de la primera fila (la decisión de en qué hoja arranca la zona es de quien la dibuja)
     * ni dentro de una fila. El pie "en cada hoja" no lo usa: se dibuja en Footer(), y ahí FPDF no
     * deja agregar una hoja.
     *
     * @param mixed         $pdf
     * @param array         $items
     * @param float         $y
     * @param callable|null $saltar_de_hoja function ($pdf): float
     * @param float|null    $limite         Hasta dónde se puede dibujar en la hoja.
     * @return float Alto dibujado (filas y separaciones; sin contar los saltos de hoja).
     */
    public function dibujar_zona($pdf, $items, $y, $saltar_de_hoja = null, $limite = null)
    {
        $recorrido = $this->recorrer_zona($pdf, $items, $y, true, $saltar_de_hoja, $limite);
        $pdf->y = $recorrido['y'];

        return $recorrido['alto'];
    }

    /**
     * Las filas de una zona con la posición de cada caja: lo que el motor va a dibujar, sin
     * dibujarlo (lo usan los tests para afirmar la grilla).
     *
     * @param array $items
     * @return array<int, array> Cada fila: ['tipo' => 'cajas', 'cajas' => [['caja', 'columna', 'x', 'ancho']]]
     *                           o ['tipo' => 'fijo', 'item' => array].
     */
    public function filas($items)
    {
        $filas = [];
        $cajas = [];
        $columna = 0;

        foreach ((array) $items as $item) {
            if (! is_array($item) || ! isset($item['tipo'])) {
                continue;
            }

            if ($item['tipo'] === DisenoDePaginaPdf::TIPO_SALTO_DE_FILA) {
                $this->cerrar_fila($filas, $cajas, $columna);
                continue;
            }

            if ($item['tipo'] === DisenoDePaginaPdf::TIPO_FIJO) {
                $this->cerrar_fila($filas, $cajas, $columna);
                $filas[] = ['tipo' => 'fijo', 'item' => $item];
                continue;
            }

            if ($item['tipo'] !== DisenoDePaginaPdf::TIPO_CAJA) {
                continue;
            }

            $cols = isset($item['cols']) ? max(1, min(self::COLUMNAS, (int) $item['cols'])) : self::COLUMNAS;

            /** Si no entra en lo que queda de la fila, baja a la siguiente. */
            if ($columna + $cols > self::COLUMNAS) {
                $this->cerrar_fila($filas, $cajas, $columna);
            }

            $unidad = ($this->ancho + self::GUTTER) / self::COLUMNAS;
            $cajas[] = [
                'caja' => $item,
                'columna' => $columna,
                'x' => $this->x0 + $columna * $unidad,
                'ancho' => $cols * $unidad - self::GUTTER,
            ];
            $columna += $cols;
        }

        $this->cerrar_fila($filas, $cajas, $columna);

        return $filas;
    }

    /**
     * Cierra la fila en curso (si tiene cajas) y arranca una nueva.
     *
     * @param array $filas
     * @param array $cajas
     * @param int   $columna
     * @return void
     */
    private function cerrar_fila(&$filas, &$cajas, &$columna)
    {
        if (count($cajas) > 0) {
            $filas[] = ['tipo' => 'cajas', 'cajas' => $cajas];
        }

        $cajas = [];
        $columna = 0;
    }

    /**
     * LA rutina: recorre las filas de la zona y, según $dibujar, solo suma los altos o además
     * dibuja. Medir y dibujar pasan por las mismas filas y los mismos altos.
     *
     * @param mixed         $pdf
     * @param array         $items
     * @param float         $y_inicio
     * @param bool          $dibujar
     * @param callable|null $saltar_de_hoja Ver dibujar_zona() (solo al dibujar).
     * @param float|null    $limite
     * @return array{alto: float, y: float} Alto total de la zona y dónde quedó la y.
     */
    private function recorrer_zona($pdf, $items, $y_inicio, $dibujar, $saltar_de_hoja, $limite)
    {
        $y = $y_inicio;
        $alto_total = 0;
        $primera = true;

        foreach ($this->filas($items) as $fila) {

            $alto = $fila['tipo'] === 'fijo'
                ? $this->procesar_fijo($pdf, $fila['item'], false)
                : $this->alto_de_fila($fila);

            /** Una fila sin nada que dibujar no ocupa alto (ni la separación). */
            if ($alto <= 0) {
                continue;
            }

            $separacion = $primera ? 0 : self::SEPARACION_ENTRE_FILAS;

            /** La fila no entra en lo que queda de la hoja: sigue en la siguiente (nunca la primera). */
            if ($dibujar && ! is_null($saltar_de_hoja) && ! $primera && $y + $separacion + $alto > $limite) {
                $y = (float) call_user_func($saltar_de_hoja, $pdf);
                $separacion = 0;
            }

            $primera = false;
            $y += $separacion;
            $alto_total += $separacion;

            if ($dibujar) {
                if ($fila['tipo'] === 'fijo') {
                    /** El bloque fijo se dibuja con su alto real (el medido es una estimación por arriba). */
                    $pdf->y = $y;
                    $alto = $this->procesar_fijo($pdf, $fila['item'], true);
                } else {
                    $this->dibujar_fila($pdf, $fila, $y, $alto);
                }
            }

            $y += $alto;
            $alto_total += $alto;
        }

        return ['alto' => $alto_total, 'y' => $y];
    }

    /**
     * Mide o dibuja un bloque fijo con el callable que dio el comprobante. Sin callable (un fijo
     * que este comprobante no sabe dibujar) no ocupa nada.
     *
     * @param mixed $pdf
     * @param array $item
     * @param bool  $dibujar
     * @return float
     */
    private function procesar_fijo($pdf, $item, $dibujar)
    {
        $key = isset($item['key']) ? $item['key'] : null;

        if (is_null($key) || ! isset($this->fijos[$key])) {
            return 0;
        }

        return (float) call_user_func($this->fijos[$key], $pdf, $item, $dibujar);
    }

    /**
     * Alto de una fila de cajas: el de su caja más alta (0 si ninguna tiene algo que dibujar).
     *
     * @param array $fila
     * @return float
     */
    private function alto_de_fila($fila)
    {
        $alto = 0;

        foreach ($fila['cajas'] as $posicion) {
            $alto = max($alto, $this->contenido_de_caja($posicion['caja'], $posicion['ancho'])['alto']);
        }

        return $alto;
    }

    /**
     * Dibuja las cajas de una fila, todas con el alto de la fila.
     *
     * @param mixed $pdf
     * @param array $fila
     * @param float $y
     * @param float $alto_de_fila
     * @return void
     */
    private function dibujar_fila($pdf, $fila, $y, $alto_de_fila)
    {
        foreach ($fila['cajas'] as $posicion) {
            $contenido = $this->contenido_de_caja($posicion['caja'], $posicion['ancho']);

            /** Una caja sin ningún campo con valor no se dibuja: ni el recuadro ni el título. */
            if ($contenido['alto'] <= 0) {
                continue;
            }

            $this->dibujar_recuadro($pdf, $posicion['caja'], $posicion['x'], $y, $posicion['ancho'], $alto_de_fila);

            $x = $posicion['x'] + self::RELLENO_COSTADOS;
            $ancho = $posicion['ancho'] - 2 * self::RELLENO_COSTADOS;
            $y_linea = $y + self::RELLENO_VERTICAL;

            foreach ($contenido['lineas'] as $linea) {
                $this->dibujar_linea($pdf, $linea, $x, $y_linea, $ancho);
                $y_linea += $linea['alto'];

                if (! empty($linea['separacion_despues'])) {
                    $y_linea += $linea['separacion_despues'];
                }
            }
        }

        /** Colores de vuelta a los de siempre para no afectar lo que se dibuje después. */
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->SetFillColor(255, 255, 255);
    }

    /**
     * El recuadro de la caja según su estilo.
     *
     * @param mixed $pdf
     * @param array $caja
     * @param float $x
     * @param float $y
     * @param float $ancho
     * @param float $alto
     * @return void
     */
    private function dibujar_recuadro($pdf, $caja, $x, $y, $ancho, $alto)
    {
        $estilo = isset($caja['estilo']) ? $caja['estilo'] : DisenoDePaginaPdf::ESTILO_DE_CAJA_POR_DEFECTO;

        if ($estilo === 'ninguno') {
            return;
        }

        $pdf->SetLineWidth(0.2);

        if ($estilo === 'gris') {
            /** El de la caja de totales y de observaciones del remito de siempre. */
            $pdf->SetFillColor(247, 247, 247);
            $pdf->SetDrawColor(210, 210, 210);
            $pdf->Rect($x, $y, $ancho, $alto, 'DF');
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFillColor(255, 255, 255);

            return;
        }

        /** borde: línea fina negra, como el recuadro del cliente (AfipPdfHelper::draw_box()). */
        $pdf->SetDrawColor(0, 0, 0);
        $pdf->Rect($x, $y, $ancho, $alto, 'D');
    }

    /**
     * Dibuja una línea ya partida: sus segmentos uno al lado del otro (rótulo y valor) o un solo
     * texto alineado al centro o a la derecha.
     *
     * @param mixed $pdf
     * @param array $linea ['alto', 'alineacion', 'color', 'segmentos' => [['texto', 'estilo', 'tamano', 'ancho']]]
     * @param float $x     Borde izquierdo del contenido de la caja.
     * @param float $y
     * @param float $ancho Ancho del contenido de la caja.
     * @return void
     */
    private function dibujar_linea($pdf, $linea, $x, $y, $ancho)
    {
        $pdf->SetTextColor($linea['color'][0], $linea['color'][1], $linea['color'][2]);
        $pdf->y = $y;

        if ($linea['alineacion'] !== 'izquierda') {
            $segmento = $linea['segmentos'][0];
            $pdf->SetFont('Arial', $segmento['estilo'], $segmento['tamano']);
            $pdf->x = $x;
            /**
             * Nunca ancho 0: para Cell() de FPDF 0 quiere decir "hasta el margen derecho de la
             * hoja", y en una caja de 1 columna de una hoja de 80 mm (4 mm de caja, 0 de contenido)
             * el texto centrado o a la derecha se iba 190 mm, a la otra punta de la hoja.
             */
            $pdf->Cell(max(0.01, $ancho), $linea['alto'], $segmento['texto'], 0, 0, $linea['alineacion'] === 'derecha' ? 'R' : 'C');

            return;
        }

        $cursor = $x;
        $ultimo = count($linea['segmentos']) - 1;

        foreach ($linea['segmentos'] as $i => $segmento) {
            $pdf->SetFont('Arial', $segmento['estilo'], $segmento['tamano']);
            $pdf->x = $cursor;

            /**
             * Cada segmento en una celda del ancho de su texto (como print_label_value_line(): el
             * valor queda pegado al rótulo); el último se lleva lo que queda de la caja.
             */
            $ancho_celda = $i === $ultimo ? max(0.01, $x + $ancho - $cursor) : $segmento['ancho'];
            $pdf->Cell($ancho_celda, $linea['alto'], $segmento['texto'], 0, 0, 'L');
            $cursor += $segmento['ancho'];
        }
    }

    /**
     * El contenido de una caja ya partido en líneas, y su alto total. Es lo que comparten la
     * medición y el dibujo: si no hay ninguna línea de campo, la caja no se dibuja (alto 0).
     *
     * @param array $caja
     * @param float $ancho_de_caja
     * @return array{alto: float, lineas: array}
     */
    private function contenido_de_caja($caja, $ancho_de_caja)
    {
        $ancho_util = $ancho_de_caja - 2 * self::RELLENO_COSTADOS - 2 * self::MARGEN_DE_CELDA;

        $lineas_de_campos = [];
        $alto_de_campos = 0;

        $campos = isset($caja['campos']) && is_array($caja['campos']) ? $caja['campos'] : [];

        foreach ($campos as $campo) {
            foreach ($this->renglones_de_campo($campo, $ancho_util) as $linea) {
                $lineas_de_campos[] = $linea;
                $alto_de_campos += $linea['alto'];
            }
        }

        if ($alto_de_campos <= 0) {
            return ['alto' => 0, 'lineas' => []];
        }

        $lineas = [];
        $alto = self::RELLENO_VERTICAL;

        $titulo = isset($caja['titulo']) ? trim((string) $caja['titulo']) : '';
        if ($titulo !== '') {
            $partes = self::partir($titulo, 'B', self::TITULO_TAMANO, $ancho_util, $ancho_util);
            $ultima = count($partes) - 1;

            foreach ($partes as $i => $parte) {
                $lineas[] = [
                    'alto' => self::TITULO_ALTO_DE_LINEA,
                    'alineacion' => 'izquierda',
                    'color' => [90, 90, 90],
                    'separacion_despues' => $i === $ultima ? self::TITULO_SEPARACION : 0,
                    'segmentos' => [self::segmento($parte, 'B', self::TITULO_TAMANO)],
                ];
                $alto += self::TITULO_ALTO_DE_LINEA;
            }

            $alto += self::TITULO_SEPARACION;
        }

        foreach ($lineas_de_campos as $linea) {
            $lineas[] = $linea;
        }

        $alto += $alto_de_campos + self::RELLENO_VERTICAL;

        return ['alto' => $alto, 'lineas' => $lineas];
    }

    /**
     * Las líneas que ocupa un campo dentro de una caja, ya partidas, o [] si el campo no tiene
     * valor (no se imprime ni el rótulo) o si su key no está en el catálogo (se saltea sin error).
     *
     * @param array $campo      El campo del diseño.
     * @param float $ancho_util Ancho disponible para el texto.
     * @return array<int, array>
     */
    private function renglones_de_campo($campo, $ancho_util)
    {
        $key = isset($campo['key']) ? $campo['key'] : null;

        if (! is_string($key) || ! isset($this->catalogo[$key])) {
            return [];
        }

        $valor = $this->valor($key, $campo);

        $elementos = is_array($valor) ? array_values($valor) : [$valor];
        $elementos = array_values(array_filter($elementos, function ($elemento) {
            return ! is_null($elemento) && trim((string) $elemento) !== '';
        }));

        if (count($elementos) === 0) {
            return [];
        }

        $estilo = $this->estilo_del_campo($key, $campo);
        $alto_de_linea = $estilo['tamano'] * self::ALTO_DE_LINEA_POR_PUNTO;
        $color = [0, 0, 0];

        /** Estilo del valor (y del renglón entero cuando no va a la izquierda). */
        $estilo_valor = ($estilo['negrita'] ? 'B' : '').($estilo['cursiva'] ? 'I' : '');
        /** El rótulo a la izquierda va siempre en negrita, como en el bloque del cliente de siempre. */
        $estilo_rotulo = 'B'.($estilo['cursiva'] ? 'I' : '');

        $rotulo = $this->rotulo($key, $campo);
        $lineas = [];

        foreach ($elementos as $i => $elemento) {
            $texto = (string) $elemento;
            $con_rotulo = $i === 0 && $rotulo !== '';

            if ($estilo['alineacion'] !== 'izquierda') {
                $completo = $con_rotulo ? $rotulo.$texto : $texto;

                foreach (self::partir($completo, $estilo_valor, $estilo['tamano'], $ancho_util, $ancho_util) as $parte) {
                    $lineas[] = [
                        'alto' => $alto_de_linea,
                        'alineacion' => $estilo['alineacion'],
                        'color' => $color,
                        'segmentos' => [self::segmento($parte, $estilo_valor, $estilo['tamano'])],
                    ];
                }

                continue;
            }

            if (! $con_rotulo) {
                foreach (self::partir($texto, $estilo_valor, $estilo['tamano'], $ancho_util, $ancho_util) as $parte) {
                    $lineas[] = [
                        'alto' => $alto_de_linea,
                        'alineacion' => 'izquierda',
                        'color' => $color,
                        'segmentos' => [self::segmento($parte, $estilo_valor, $estilo['tamano'])],
                    ];
                }

                continue;
            }

            /**
             * Rótulo + valor: la primera línea arranca con el rótulo y el valor sigue al lado; las
             * siguientes arrancan en el borde de la caja (print_label_value_multiline()).
             */
            $segmento_rotulo = self::segmento($rotulo, $estilo_rotulo, $estilo['tamano']);

            if ($segmento_rotulo['ancho'] > $ancho_util) {
                /**
                 * El rótulo no entra entero en la caja (una caja angosta, una etiqueta propia de 60
                 * letras, 24 pt). Si quedara como un solo segmento, se dibujaría pasando el borde
                 * derecho, encima de la caja de al lado: medido el 1/10/2026 con "Total menos
                 * comisiones: " en negrita 12 en una caja de 3 columnas (52 mm de rótulo contra
                 * 42,5 de ancho útil), que pisaba el "Costos: " de la caja vecina. Se parte en
                 * palabras como el valor (y una palabra que no entra sola, por letras): todas sus
                 * líneas menos la última van solas, y el valor sigue al lado de la última con el
                 * ancho que le quede (o en la línea de abajo, si al lado no entra).
                 */
                $lineas_del_rotulo = self::partir(rtrim($rotulo), $estilo_rotulo, $estilo['tamano'], $ancho_util, $ancho_util);
                $ultima_del_rotulo = array_pop($lineas_del_rotulo);

                foreach ($lineas_del_rotulo as $parte_del_rotulo) {
                    $lineas[] = [
                        'alto' => $alto_de_linea,
                        'alineacion' => 'izquierda',
                        'color' => $color,
                        'segmentos' => [self::segmento($parte_del_rotulo, $estilo_rotulo, $estilo['tamano'])],
                    ];
                }

                /** El espacio que separa el rótulo del valor no se ve: no cuenta para el borde. */
                $segmento_rotulo = self::segmento($ultima_del_rotulo.' ', $estilo_rotulo, $estilo['tamano']);
            }

            $partes = self::partir($texto, $estilo_valor, $estilo['tamano'], $ancho_util - $segmento_rotulo['ancho'], $ancho_util);

            foreach ($partes as $j => $parte) {
                $segmentos = [];

                if ($j === 0) {
                    $segmentos[] = $segmento_rotulo;
                }
                if ($parte !== '' || $j > 0) {
                    $segmentos[] = self::segmento($parte, $estilo_valor, $estilo['tamano']);
                }

                $lineas[] = [
                    'alto' => $alto_de_linea,
                    'alineacion' => 'izquierda',
                    'color' => $color,
                    'segmentos' => $segmentos,
                ];
            }
        }

        return $lineas;
    }

    /**
     * Valor de un campo, pedido UNA vez a la fuente (el pie se mide para reservar lugar y se
     * dibuja, a veces en cada hoja).
     *
     * @param string $key
     * @param array  $campo
     * @return mixed
     */
    private function valor($key, $campo)
    {
        $clave = $key.'|'.(isset($campo['id']) ? (string) $campo['id'] : '');

        if (! array_key_exists($clave, $this->valores)) {
            $this->valores[$clave] = $this->fuente->valor($key, $campo);
        }

        return $this->valores[$clave];
    }

    /**
     * El rótulo que se imprime delante del valor ("Cliente: "), o '' si va sin rótulo. null en el
     * diseño = la etiqueta del catálogo; '' = sin rótulo; otro texto = ese.
     *
     * @param string $key
     * @param array  $campo
     * @return string
     */
    private function rotulo($key, $campo)
    {
        $etiqueta = array_key_exists('etiqueta', $campo) && ! is_null($campo['etiqueta'])
            ? (string) $campo['etiqueta']
            : (string) $this->catalogo[$key]['etiqueta'];

        $etiqueta = trim($etiqueta);

        return $etiqueta === '' ? '' : $etiqueta.': ';
    }

    /**
     * Estilo efectivo de un campo: lo que el diseño define y, lo que viene en null, el del catálogo.
     *
     * @param string $key
     * @param array  $campo
     * @return array{tamano: float, negrita: bool, cursiva: bool, alineacion: string}
     */
    private function estilo_del_campo($key, $campo)
    {
        $defecto = $this->catalogo[$key]['estilo'];

        $tamano = isset($campo['tamano']) && is_numeric($campo['tamano']) ? (int) $campo['tamano'] : (int) $defecto['tamano'];
        $tamano = max(DisenoDePaginaPdf::TAMANO_MIN, min(DisenoDePaginaPdf::TAMANO_MAX, $tamano));

        $alineacion = isset($campo['alineacion']) && in_array($campo['alineacion'], DisenoDePaginaPdf::ALINEACIONES, true)
            ? $campo['alineacion']
            : $defecto['alineacion'];

        return [
            'tamano' => $tamano,
            'negrita' => isset($campo['negrita']) && ! is_null($campo['negrita']) ? (bool) $campo['negrita'] : (bool) $defecto['negrita'],
            'cursiva' => isset($campo['cursiva']) && ! is_null($campo['cursiva']) ? (bool) $campo['cursiva'] : (bool) $defecto['cursiva'],
            'alineacion' => $alineacion,
        ];
    }

    // ── Texto: medir y partir (lo mismo para medir y para dibujar) ──────────────────────────

    /**
     * Un segmento de línea con su ancho ya medido.
     *
     * @param string $texto
     * @param string $estilo '' | 'B' | 'I' | 'BI'
     * @param float  $tamano
     * @return array{texto: string, estilo: string, tamano: float, ancho: float}
     */
    private static function segmento($texto, $estilo, $tamano)
    {
        return [
            'texto' => (string) $texto,
            'estilo' => $estilo,
            'tamano' => $tamano,
            'ancho' => self::ancho_de_texto($texto, $estilo, $tamano),
        ];
    }

    /**
     * Parte un texto en líneas que entran en un ancho, cortando en los espacios (y por letras una
     * palabra que no entra sola). La primera línea puede tener otro ancho: el que queda al lado del
     * rótulo. Respeta los saltos de línea del texto.
     *
     * @param string $texto
     * @param string $estilo
     * @param float  $tamano
     * @param float  $ancho_primera
     * @param float  $ancho_resto
     * @return array<int, string>
     */
    public static function partir($texto, $estilo, $tamano, $ancho_primera, $ancho_resto)
    {
        $lineas = [];
        $ancho = $ancho_primera;

        foreach (explode("\n", str_replace("\r", '', (string) $texto)) as $parrafo) {
            $actual = '';

            foreach (explode(' ', $parrafo) as $palabra) {
                $candidata = $actual === '' ? $palabra : $actual.' '.$palabra;

                if (self::ancho_de_texto($candidata, $estilo, $tamano) <= $ancho) {
                    $actual = $candidata;
                    continue;
                }

                if ($actual !== '') {
                    /** La palabra no entra en lo que queda: la línea se cierra y la palabra baja. */
                    $lineas[] = $actual;
                    $ancho = $ancho_resto;
                    $actual = '';

                    if (self::ancho_de_texto($palabra, $estilo, $tamano) <= $ancho) {
                        $actual = $palabra;
                        continue;
                    }
                } elseif (count($lineas) === 0 && $ancho < $ancho_resto && self::ancho_de_texto($palabra, $estilo, $tamano) <= $ancho_resto) {
                    /** Al lado del rótulo no entra ni la primera palabra: el valor arranca en la línea de abajo. */
                    $lineas[] = '';
                    $ancho = $ancho_resto;
                    $actual = $palabra;
                    continue;
                }

                /** Una palabra que no entra sola en una línea se corta por letras. */
                $letras = preg_split('//u', $palabra, -1, PREG_SPLIT_NO_EMPTY);
                if (! is_array($letras)) {
                    /**
                     * Con UTF-8 inválido preg_split() devuelve false y el foreach no correría: la
                     * palabra se perdería sin aviso. Se corta por bytes (Cell() los imprime igual).
                     */
                    $letras = str_split($palabra);
                }

                $trozo = '';
                foreach ($letras as $letra) {
                    if (self::ancho_de_texto($trozo.$letra, $estilo, $tamano) > $ancho) {
                        if ($trozo !== '') {
                            $lineas[] = $trozo;
                            $ancho = $ancho_resto;
                            $trozo = '';
                        } elseif (count($lineas) === 0 && $ancho < $ancho_resto) {
                            /** Al lado del rótulo no entra ni una letra: la palabra arranca en la línea de abajo. */
                            $lineas[] = '';
                            $ancho = $ancho_resto;
                        }
                    }
                    /** La primera letra de un trozo va siempre, aunque no entre: si no, el corte no avanzaría. */
                    $trozo .= $letra;
                }
                $actual = $trozo;
            }

            $lineas[] = $actual;
            $ancho = $ancho_resto;
        }

        return $lineas;
    }

    /**
     * Ancho en mm de un texto en Arial (Helvetica) con un estilo y un tamaño, con las MISMAS
     * métricas que usa FPDF. Se mide el texto ya pasado a Latin-1 (utf8_decode), que es lo que
     * Cell() escribe en la hoja: así un acento mide lo que se ve.
     *
     * @param string $texto
     * @param string $estilo '' | 'B' | 'I' | 'BI'
     * @param float  $tamano Puntos.
     * @return float
     */
    public static function ancho_de_texto($texto, $estilo, $tamano)
    {
        $anchos = self::metricas($estilo);
        $latin1 = utf8_decode((string) $texto);
        $total = 0;
        $largo = strlen($latin1);

        for ($i = 0; $i < $largo; $i++) {
            $letra = $latin1[$i];
            $total += isset($anchos[$letra]) ? $anchos[$letra] : 0;
        }

        return $total * $tamano / 1000 * self::MM_POR_PUNTO;
    }

    /**
     * Anchos de letra de una variante de Helvetica, leídos de los mismos archivos de métricas que
     * carga FPDF (CommonLaravel/fpdf/font/helvetica*.php).
     *
     * @param string $estilo '' | 'B' | 'I' | 'BI'
     * @return array<string, int>
     */
    private static function metricas($estilo)
    {
        $archivo = 'helvetica'.strtolower($estilo).'.php';

        if (! isset(self::$metricas[$archivo])) {
            self::$metricas[$archivo] = self::leer_metricas(__DIR__.'/../../CommonLaravel/fpdf/font/'.$archivo);
        }

        return self::$metricas[$archivo];
    }

    /**
     * Lee el arreglo $cw de un archivo de métricas de FPDF.
     *
     * @param string $ruta
     * @return array<string, int>
     */
    private static function leer_metricas($ruta)
    {
        $cw = [];
        include $ruta;

        return $cw;
    }
}
