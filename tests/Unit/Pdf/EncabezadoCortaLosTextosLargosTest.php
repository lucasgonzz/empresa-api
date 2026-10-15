<?php

namespace Tests\Unit\Pdf;

use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__.'/../../../app/Http/Controllers/CommonLaravel/fpdf/fpdf.php';

/**
 * FPDF real que además anota dónde termina cada texto que dibuja (Cell y Write), para poder medir
 * si algo se pasa del ancho de su cuadrante.
 */
class FpdfQueMideElBordeDerecho extends \FPDF
{
    /** @var float El x más a la derecha al que llegó un texto. */
    public $borde_derecho = 0;

    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        if ((string) $txt !== '') {
            $this->borde_derecho = max($this->borde_derecho, $this->x + $this->cMargin + $this->GetStringWidth($txt));
        }

        parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);
    }

    /** @return float */
    public function margen_izquierdo()
    {
        return $this->lMargin;
    }
}

/**
 * Encabezado del PDF: un campo largo corta línea en vez de meterse en el cuadrante de al lado
 * (pedido de Lucas, 15/10/2026, con el comprobante X 10186 de Masquito Reparaciones, donde el mail
 * "administracion@masquitoreparaciones.com.ar" se pisaba con el cuadrante de la derecha). Y el
 * bloque crece lo que haga falta.
 *
 * Con FPDF real y sin base de datos.
 *
 * @group pdf-estructura
 */
class EncabezadoCortaLosTextosLargosTest extends TestCase
{
    /** Ancho de la columna del emisor al lado de un logo de 35 mm en la A4 de siempre (aprox.). */
    const ANCHO = 55;

    const X = 42;

    /**
     * @return FpdfQueMideElBordeDerecho
     */
    protected function pdf()
    {
        $pdf = new FpdfQueMideElBordeDerecho('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(5, 5, 5);
        $pdf->AddPage();
        $pdf->y = 20;

        return $pdf;
    }

    /**
     * Corre print_emisor_field_list() y devuelve [pdf, y final].
     *
     * @param array<int, string>    $claves
     * @param array<string, string> $valores
     * @return array
     */
    protected function lista($claves, $valores)
    {
        $pdf = $this->pdf();

        $metodo = new ReflectionMethod(AfipPdfHelper::class, 'print_emisor_field_list');
        $metodo->setAccessible(true);
        $y = $metodo->invoke(null, $pdf, $claves, $valores, self::X, self::ANCHO);

        return [$pdf, $y];
    }

    public function test_un_mail_corto_va_en_un_renglon_como_siempre()
    {
        list($pdf, $y) = $this->lista(['email'], ['email' => 'ventas@ab.com']);

        $this->assertEqualsWithDelta(25, $y, 0.001, 'un renglón de 5 mm');
        $this->assertLessThanOrEqual(self::X + self::ANCHO, $pdf->borde_derecho);
    }

    public function test_el_mail_de_masquito_corta_linea_y_no_se_pasa_del_cuadrante()
    {
        list($pdf, $y) = $this->lista(['email'], ['email' => 'administracion@masquitoreparaciones.com.ar']);

        $this->assertGreaterThan(25, $y, 'el mail ocupa más de un renglón');
        $this->assertLessThanOrEqual(self::X + self::ANCHO + 0.01, $pdf->borde_derecho, 'ningún texto pasa el borde del cuadrante');
        $this->assertEqualsWithDelta(5, $pdf->margen_izquierdo(), 0.001, 'los márgenes vuelven a los de siempre');
    }

    public function test_cualquier_campo_largo_corta_y_los_que_siguen_se_corren()
    {
        $valores = [
            'telefono' => '+54 9 343 522 5062 / +54 9 343 400 0000 / fijo 0343 4221100',
            'cuit' => '20292700599',
        ];

        list($pdf, $y) = $this->lista(['telefono', 'cuit'], $valores);

        /** Teléfono en al menos dos renglones + CUIT en uno: más de 15 mm. */
        $this->assertGreaterThanOrEqual(35, $y);
        $this->assertLessThanOrEqual(self::X + self::ANCHO + 0.01, $pdf->borde_derecho);
    }

    public function test_el_numero_de_comprobante_largo_tambien_corta()
    {
        list($pdf, $y) = $this->lista(['numero_comprobante'], ['numero_comprobante' => str_repeat('1234567890', 8)]);

        $this->assertGreaterThan(25, $y);
        $this->assertLessThanOrEqual(self::X + self::ANCHO + 0.01, $pdf->borde_derecho);
    }

    public function test_el_bloque_del_cliente_del_remito_crece_con_un_texto_largo()
    {
        $alto = function ($direccion) {
            $cliente = new \stdClass();
            $cliente->name = 'CHIAPINO, ARIEL ALEJANDRO';
            $cliente->phone = '';
            $cliente->location = null;
            $cliente->address = $direccion;
            $cliente->cuit = '';
            $cliente->iva_condition = null;

            $venta = new \stdClass();
            $venta->client = $cliente;
            $venta->seller = null;
            $venta->employee = null;

            $pdf = $this->pdf();
            $metodo = new ReflectionMethod(AfipPdfHelper::class, 'print_receptor_block_comercial');
            $metodo->setAccessible(true);
            $metodo->invoke(null, $pdf, $venta, ['receptor' => ['izquierda' => ['cliente_nombre', 'cliente_direccion']]], null);

            return [$pdf->y - 20, $pdf->borde_derecho];
        };

        list($alto_corto) = $alto('JUAN B. JUSTO 779');
        list($alto_largo, $borde) = $alto('JUAN B. JUSTO 779, ENTRE CALLES CORRIENTES Y SAN MARTÍN, BARRIO CENTRO, PARANÁ, ENTRE RÍOS');

        $this->assertGreaterThan($alto_corto, $alto_largo, 'el recuadro del cliente crece');
        /** Cuadrante izquierdo: de x = 7 a 105 (el medio de la A4 de siempre). */
        $this->assertLessThanOrEqual(105 + 0.01, $borde);
    }

    /**
     * @return array<int, string>
     */
    protected function partir($texto, $ancho_primera, $ancho_resto)
    {
        $pdf = $this->pdf();
        $pdf->SetFont('Arial', '', 9);

        $metodo = new ReflectionMethod(AfipPdfHelper::class, 'partir_texto');
        $metodo->setAccessible(true);

        return $metodo->invoke(null, $pdf, $texto, $ancho_primera, $ancho_resto);
    }

    public function test_el_mail_corta_despues_de_la_arroba()
    {
        $this->assertSame(
            ['administracion@', 'masquitoreparaciones.com.ar'],
            $this->partir('administracion@masquitoreparaciones.com.ar', 40, 57)
        );
    }

    public function test_no_parte_una_fecha_ni_un_importe()
    {
        $this->assertSame(['', 'Fecha', '01/01/2020'], $this->partir('Fecha 01/01/2020', 5, 18));
        $this->assertSame(['Saldo', '$163.420,40'], $this->partir('Saldo $163.420,40', 12, 20));
    }

    public function test_un_texto_que_no_es_utf8_valido_se_corta_sin_romper_el_pdf()
    {
        $renglones = $this->partir(str_repeat("España ", 4), 10, 20);

        $this->assertGreaterThan(1, count($renglones));
        $this->assertSame(str_repeat("España", 4), str_replace(' ', '', implode('', $renglones)));
    }
}
