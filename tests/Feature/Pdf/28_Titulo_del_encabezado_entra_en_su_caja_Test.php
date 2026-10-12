<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\CommonLaravel\Helpers\PdfHelper;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../app/Http/Controllers/CommonLaravel/fpdf/fpdf.php';

/**
 * Un FPDF pelado con lo único que PdfHelper::title() le pide al comprobante ($b, el borde de las
 * celdas) y una ventana a lo que FPDF guarda protected: la letra puesta y lo dibujado en la hoja.
 */
class FpdfDelTituloDePrueba extends \FPDF
{
    public $b = 0;

    public function letra()
    {
        return $this->FontSizePt;
    }

    public function hoja()
    {
        return $this->pages[$this->page];
    }
}

/**
 * El título del cuadradito central del encabezado (PdfHelper::title(), misión acopio-pdf-entregadas,
 * 9/10/2026) siempre entra en su caja de 30 x 15 mm, y los que ya entraban no cambian ni un byte.
 *
 * Antes, con la letra por defecto (30 pt, renglón de 15 mm), el MultiCell del fpdf.php del proyecto
 * cortaba por carácter cualquier palabra que no entrara en los 28 mm útiles y bajaba 15 mm por
 * renglón: "Articulos entregados" se partía letra por letra hacia abajo, encima del cliente, de los
 * artículos y de la firma. Lo mismo en otros cinco PDF.
 *
 * Extiende PHPUnit\Framework\TestCase directamente, como 23_Motor_de_cajas_Test: title() no lee
 * ningún modelo y dibuja sobre un FPDF pelado. Las cantidades de los PDF de acopio se prueban en un
 * PROCESO APARTE: SaleDeliveredArticlesPdf y AcopioArticleDeliveryPdf hacen `require` (sin _once) de
 * fpdf.php, y cargarlos en el proceso de PHPUnit, que ya lo cargó, es un fatal "Cannot declare class
 * FPDF" que mata la corrida entera (ver 12_Elegir_diseno_al_imprimir_Test).
 *
 * @group pdf-titulo-del-encabezado
 */
class Titulo_del_encabezado_entra_en_su_caja_Test extends TestCase
{
    /** Puntos por milímetro (la escala de FPDF en mm). */
    const K = 72 / 25.4;

    /** La caja del título: y de arriba y de abajo (mm). */
    const CAJA_ARRIBA = 5;
    const CAJA_ABAJO = 20;

    /** Tolerancia para comparar milímetros que FPDF escribe con dos decimales. */
    const EPSILON = 0.000001;

    /**
     * Los seis títulos que se rompían, tal como los pasa cada PDF (sin title_font_size: 30 / 15), con
     * la letra a la que tienen que quedar: la mayor que entra (verificada aparte en
     * la_letra_es_la_mayor_que_entra contra el MultiCell de verdad).
     *
     * @return array
     */
    public function titulos_que_no_entraban()
    {
        return [
            'SaleDeliveredArticlesPdf' => ['Articulos entregados', 14],
            'AcopioArticleDeliveryPdf' => ['Entrega', 21],
            'OrderProductionPdf' => ['Orden de produccion', 14],
            'PagoPdf' => ['Recibo de Pago', 15],
            'SalePdf con precios netos' => ['Venta Pre netos', 15],
            '__base' => ['Presupuesto', 13],
        ];
    }

    /**
     * Los títulos que ya entraban, con lo que les pasa cada comprobante.
     *
     * @return array
     */
    public function titulos_que_ya_entraban()
    {
        return [
            'letra X' => [['title' => 'X']],
            'letra A' => [['title' => 'A']],
            'letra B' => [['title' => 'B']],
            'letra C' => [['title' => 'C']],
            'NC' => [['title' => 'NC']],
            'BudgetPdf' => [['title' => 'Presupuesto', 'title_font_size' => 12]],
            'CurrentAcountPdf' => [['title' => 'Cuenta corriente', 'title_font_size' => 12, 'title_height' => 5]],
            'NotaCreditoPdf' => [['title' => 'Nota de Credito', 'title_font_size' => 12, 'title_height' => 5]],
            'OrderPdf' => [['title' => 'Pedido Online', 'title_font_size' => 10]],
            'ProviderOrderPdf' => [['title' => 'Compra', 'title_font_size' => 10]],
            'DepositMovementPdf' => [['title' => 'Mov. Depositos', 'title_font_size' => 10]],
            'ClientsPdf' => [['title' => 'Clientes', 'title_font_size' => 12]],
            'SalePdf sin precios netos (null)' => [['title' => null]],
            'vacio' => [['title' => '']],
        ];
    }

    /**
     * @test
     * @dataProvider titulos_que_no_entraban
     */
    public function el_titulo_que_no_entraba_queda_adentro_de_la_caja_sin_cortar_palabras($titulo, $letra_esperada)
    {
        $pdf = $this->pdf_nuevo();
        PdfHelper::title($pdf, ['title' => $titulo]);

        $renglones = $this->textos($pdf->hoja());

        $this->assertLessThan(30, $pdf->letra(), 'El título no se achicó.');
        $this->assertGreaterThanOrEqual(PdfHelper::TITULO_LETRA_MINIMA, $pdf->letra());
        $this->assertEquals($letra_esperada, $pdf->letra(), 'No quedó con la letra mayor que entra.');

        /** Terminó adentro de la caja. */
        $this->assertLessThanOrEqual(self::CAJA_ABAJO + self::EPSILON, $pdf->y, 'El título se sale por abajo de la caja.');

        /** Ninguna palabra cortada: cada renglón son palabras enteras y juntos dan el título. */
        $palabras = explode(' ', $titulo);
        foreach ($renglones as $renglon) {
            foreach (explode(' ', $renglon['texto']) as $pedazo) {
                $this->assertContains($pedazo, $palabras, 'Una palabra quedó cortada: "'.$pedazo.'".');
            }
        }
        $this->assertSame($titulo, implode(' ', array_column($renglones, 'texto')));

        /** Centrado en vertical: el mismo aire arriba que abajo. */
        $alto_del_bloque = count($renglones) * $pdf->letra() * 0.5;
        $arriba = ($pdf->y - $alto_del_bloque) - self::CAJA_ARRIBA;
        $abajo = self::CAJA_ABAJO - $pdf->y;
        $this->assertGreaterThanOrEqual(-self::EPSILON, $arriba, 'El título arranca arriba de la caja.');
        $this->assertEqualsWithDelta($arriba, $abajo, self::EPSILON, 'El título no quedó centrado en la caja.');

        /** Y ningún texto se dibujó afuera de la caja. */
        foreach ($renglones as $renglon) {
            $this->assertGreaterThan(self::CAJA_ARRIBA, $renglon['y']);
            $this->assertLessThan(self::CAJA_ABAJO, $renglon['y']);
            $this->assertGreaterThanOrEqual(90, $renglon['x']);
        }
    }

    /**
     * Una letra más grande ya no entra. Se mide con el title() viejo (el MultiCell de siempre, sin
     * ninguna medición nuestra) con esa letra y su renglón proporcional: o corta una palabra o se
     * sale de la caja.
     *
     * @test
     * @dataProvider titulos_que_no_entraban
     */
    public function la_letra_es_la_mayor_que_entra($titulo, $letra_esperada)
    {
        $pdf = $this->pdf_nuevo();
        PdfHelper::title($pdf, ['title' => $titulo]);
        $letra = $pdf->letra();

        $mas_grande = $this->pdf_nuevo();
        self::title_viejo($mas_grande, ['title' => $titulo, 'title_font_size' => $letra + 1, 'title_height' => ($letra + 1) * 0.5]);

        $palabras = explode(' ', $titulo);
        $corta_una_palabra = false;
        foreach ($this->textos($mas_grande->hoja()) as $renglon) {
            foreach (explode(' ', $renglon['texto']) as $pedazo) {
                if (!in_array($pedazo, $palabras, true)) {
                    $corta_una_palabra = true;
                }
            }
        }
        $se_sale = $mas_grande->y > self::CAJA_ABAJO + self::EPSILON;

        $this->assertTrue($corta_una_palabra || $se_sale, 'Con '.($letra + 1).' pt "'.$titulo.'" también entraba.');
    }

    /**
     * La caja se sigue dibujando igual (30 x 15 desde x=90, y=5), entre o no el título.
     *
     * @test
     * @dataProvider titulos_que_no_entraban
     */
    public function la_caja_se_dibuja_igual_que_antes($titulo)
    {
        $nuevo = $this->pdf_nuevo();
        PdfHelper::title($nuevo, ['title' => $titulo]);

        $viejo = $this->pdf_nuevo();
        self::title_viejo($viejo, ['title' => $titulo]);

        $this->assertSame($this->lineas($viejo->hoja()), $this->lineas($nuevo->hoja()));
        $this->assertCount(4, $this->lineas($nuevo->hoja()));
    }

    /**
     * Lo que ya entraba sale EXACTAMENTE igual que con el title() de antes: la misma hoja byte por
     * byte, la misma letra puesta y la misma posición final.
     *
     * @test
     * @dataProvider titulos_que_ya_entraban
     */
    public function lo_que_ya_entraba_sale_identico_al_title_viejo(array $data)
    {
        $nuevo = $this->pdf_nuevo();
        PdfHelper::title($nuevo, $data);

        $viejo = $this->pdf_nuevo();
        self::title_viejo($viejo, $data);

        $this->assertSame($viejo->hoja(), $nuevo->hoja());
        $this->assertSame($viejo->letra(), $nuevo->letra());
        $this->assertSame($viejo->x, $nuevo->x);
        $this->assertSame($viejo->y, $nuevo->y);
    }

    /**
     * SaleDeliveredArticlesPdf ("Imprimir unidades entregadas") imprime la cantidad con
     * Numbers::price(): la columna es decimal(25,2) y antes salía "10.00 unidades".
     *
     * printArticle() se llama sobre una subclase que no pasa por el constructor (que hace Output() y
     * exit) ni por el Header() (que lee el usuario de la base).
     *
     * @test
     */
    public function la_cantidad_entregada_sale_formateada()
    {
        $codigo = <<<'PHP'
<?php
require __AUTOLOAD__;

class EntregadasSinEncabezado extends \App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf
{
    public function __construct()
    {
        \FPDF::__construct();
        $this->b = 0;
    }

    public function Header()
    {
    }
}

$pdf = new EntregadasSinEncabezado();
$pdf->SetCompression(false);
$pdf->AddPage();
$pdf->SetFont('Arial', 'B', 10);
foreach (['10.00', '2.50', '1000.00'] as $cantidad) {
    $pdf->x = 5;
    $pdf->printArticle((object) ['name' => 'Cemento', 'pivot' => (object) ['delivered_amount' => $cantidad]]);
}
echo 'PDF_INICIO'.base64_encode($pdf->Output('S')).'PDF_FIN';
PHP;

        $textos = array_column($this->textos($this->pdf_de_otro_proceso($codigo)), 'texto');

        $this->assertSame([
            'Se entregaron 10 unidades de Cemento',
            'Se entregaron 2,50 unidades de Cemento',
            'Se entregaron 1.000 unidades de Cemento',
        ], $textos);
    }

    /**
     * AcopioArticleDeliveryPdf (el comprobante de cada entrega del acopio) imprime sus tres
     * cantidades con Numbers::price(): la de la entrega, las vendidas y las entregadas.
     *
     * @test
     */
    public function las_cantidades_de_la_entrega_de_acopio_salen_formateadas()
    {
        $codigo = <<<'PHP'
<?php
require __AUTOLOAD__;

class EntregaSinEncabezado extends \App\Http\Controllers\Pdf\AcopioArticleDeliveryPdf
{
    public function __construct($model)
    {
        \FPDF::__construct();
        $this->b = 0;
        $this->line_height = 7;
        $this->model = $model;
    }

    public function Header()
    {
    }
}

/** Los artículos de la venta: lo único que printArticle() les pide es find($id). */
$de_la_venta = new class {
    public $items = [];

    public function find($id)
    {
        return $this->items[$id];
    }
};
$de_la_venta->items[1] = (object) ['pivot' => (object) ['amount' => '1000.00', 'delivered_amount' => '2.50']];

$pdf = new EntregaSinEncabezado((object) ['sale' => (object) ['articles' => $de_la_venta]]);
$pdf->SetCompression(false);
$pdf->AddPage();
$pdf->SetFont('Arial', '', 10);
$pdf->printArticle((object) ['id' => 1, 'name' => 'Cemento', 'pivot' => (object) ['amount' => '10.00']]);
echo 'PDF_INICIO'.base64_encode($pdf->Output('S')).'PDF_FIN';
PHP;

        $textos = array_column($this->textos($this->pdf_de_otro_proceso($codigo)), 'texto');

        $this->assertSame(['Cemento', '10', '1.000', '2,50'], $textos);
    }

    /**
     * El encabezado de "Imprimir unidades entregadas" lleva el número de la venta ("N° 345"). Antes
     * leía $sale->num_sale, que no existe (la columna es num): Eloquent devolvía null y el "N°" no
     * salía nunca.
     *
     * Corre el Header() de verdad, que llama a PdfHelper::header(). Lo único que se reemplaza es
     * UserHelper (lo declara el proceso aparte antes de que lo cargue el autoload): getFullModel()
     * busca el usuario en la base y este test no usa base. La venta es un objeto que, como un modelo
     * de Eloquent, devuelve null para cualquier atributo que no tenga.
     *
     * @test
     */
    public function el_encabezado_de_las_unidades_entregadas_lleva_el_numero_de_la_venta()
    {
        $codigo = <<<'PHP'
<?php
namespace App\Http\Controllers\Helpers {
    /** El usuario sin base: lo único de UserHelper que usa el encabezado. */
    class UserHelper
    {
        public static function getFullModel()
        {
            return (object) ['pdf_image_size' => 30];
        }
    }
}

namespace {
    require __AUTOLOAD__;

    class EntregadasConEncabezado extends \App\Http\Controllers\Pdf\SaleDeliveredArticlesPdf
    {
        public function __construct($sale)
        {
            \FPDF::__construct();
            $this->b = 0;
            $this->sale = $sale;
        }
    }

    $venta = new class {
        public $num = 345;
        public $created_at;
        public $user;

        public function __construct()
        {
            $this->created_at = new \DateTime('2026-10-09');
            $this->user = (object) ['image_url' => null, 'afip_information' => null, 'online' => null, 'phone' => null, 'email' => 'ventas@ejemplo.com'];
        }

        public function __get($atributo)
        {
            return null;
        }
    };

    $pdf = new EntregadasConEncabezado($venta);
    $pdf->SetCompression(false);
    $pdf->AddPage();
    echo 'PDF_INICIO'.base64_encode($pdf->Output('S')).'PDF_FIN';
}
PHP;

        $textos = array_column($this->textos($this->pdf_de_otro_proceso($codigo)), 'texto');

        /** El encabezado se dibujó entero: el número de página sale en la misma numeroFecha(). */
        $this->assertContains('Pag 1', $textos, 'El encabezado no se dibujó.');

        /** "N° 345" tal como queda en la hoja: Cell() lo pasa a Latin-1 (° = 0xB0). */
        $this->assertContains("N\xB0 345", $textos, 'El encabezado no imprime el número de la venta.');
    }

    /**
     * Copia LITERAL de PdfHelper::title() antes de la misión acopio-pdf-entregadas (develop al
     * 9/10/2026). Es la vara contra la que se mide que lo que ya entraba no cambió.
     */
    private static function title_viejo($instance, $data)
    {
        $font_size = 30;
        if (isset($data['title_font_size'])) {
            $font_size = $data['title_font_size'];
        }

        $height = 15;
        if (isset($data['title_height'])) {
            $height = $data['title_height'];
        }

        $instance->SetFont('Arial', 'B', $font_size);

        $start_y = 5;
        $start_x = 90;
        $width = 30;
        $instance->y = $start_y;
        $instance->x = $start_x;
        $instance->MultiCell(
            $width,
            $height,
            $data['title'],
            $instance->b,
            'C',
            false
        );
        $finish_x = $start_x + $width;
        $finish_y = $start_y + 15;
        $instance->Line($start_x, $start_y, $finish_x, $start_y);
        $instance->Line($finish_x, $start_y, $finish_x, $finish_y);
        $instance->Line($finish_x, $finish_y, $start_x, $finish_y);
        $instance->Line($start_x, $finish_y, $start_x, $start_y);
    }

    /**
     * Un FPDF A4 con una hoja abierta y la compresión apagada.
     *
     * @return FpdfDelTituloDePrueba
     */
    private function pdf_nuevo()
    {
        $pdf = new FpdfDelTituloDePrueba('P', 'mm', 'A4');
        $pdf->SetCompression(false);
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();

        return $pdf;
    }

    /**
     * Textos dibujados, en orden, con su x y la y de su línea de base (mm desde arriba), sin el
     * escape de FPDF.
     *
     * @param string $pdf
     * @return array<int, array{x: float, y: float, texto: string}>
     */
    private function textos($pdf)
    {
        preg_match_all('~BT ([\d.]+) ([\d.]+) Td \(((?:[^()\\\\]|\\\\.)*)\) Tj ET~s', $pdf, $m, PREG_SET_ORDER);

        $textos = [];
        foreach ($m as $t) {
            $textos[] = [
                'x' => (float) $t[1] / self::K,
                'y' => 297 - ((float) $t[2] / self::K),
                'texto' => preg_replace('~\\\\(.)~s', '$1', $t[3]),
            ];
        }

        return $textos;
    }

    /**
     * Las líneas sueltas dibujadas (Line()), tal como quedaron en la hoja.
     *
     * @param string $pdf
     * @return array<int, string>
     */
    private function lineas($pdf)
    {
        preg_match_all('~^[\d.]+ [\d.]+ m [\d.]+ [\d.]+ l S$~m', $pdf, $m);

        return $m[0];
    }

    /**
     * Corre $codigo en otro PHP (el mismo binario que PHPUnit) y devuelve el PDF que imprimió.
     *
     * @param string $codigo
     * @return string
     */
    private function pdf_de_otro_proceso($codigo)
    {
        $autoload = realpath(__DIR__.'/../../../vendor/autoload.php');
        $codigo = str_replace('__AUTOLOAD__', var_export($autoload, true), $codigo);

        $archivo = tempnam(sys_get_temp_dir(), 'pdftitulo');
        file_put_contents($archivo, $codigo);

        $salida = (string) shell_exec('"'.PHP_BINARY.'" "'.$archivo.'" 2>&1');
        @unlink($archivo);

        $this->assertMatchesRegularExpression('~PDF_INICIO(.*)PDF_FIN~s', $salida, 'El proceso aparte no llegó a imprimir el PDF: '.$salida);
        preg_match('~PDF_INICIO(.*)PDF_FIN~s', $salida, $m);

        return base64_decode($m[1]);
    }
}
