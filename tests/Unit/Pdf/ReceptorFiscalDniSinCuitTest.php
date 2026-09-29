<?php

namespace Tests\Unit\Pdf;

use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Espia minimo de FPDF: guarda el texto de cada celda que dibuja el bloque del receptor.
 */
class EspiaDeReceptorFiscal
{
    /** @var float */
    public $x = 0;

    /** @var float */
    public $y = 0;

    /** @var array<int, string> */
    public $renglones = [];

    public function Cell($w = 0, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false)
    {
        $this->renglones[] = (string) $txt;
    }

    public function SetFont($family, $style = '', $size = 0)
    {
    }

    public function GetStringWidth($s)
    {
        return 20;
    }

    public function Ln($h = null)
    {
    }

    public function Line($x1, $y1, $x2, $y2)
    {
    }

    public function MultiCell($w, $h, $txt, $border = 0, $align = 'J', $fill = false)
    {
        $this->renglones[] = (string) $txt;
    }

    public function Write($h, $txt, $link = '')
    {
        $this->renglones[] = (string) $txt;
    }

    public function SetLeftMargin($margin)
    {
    }

    public function SetRightMargin($margin)
    {
    }

    public function GetPageWidth()
    {
        return 210;
    }
}

/**
 * En el cuadrante del cliente de la factura fiscal, si el cliente no tiene CUIT se muestra su DNI
 * (pedido de Lucas, 29/9/2026, para ferretotal). Con CUIT, nada cambia.
 *
 * Extiende PHPUnit\Framework\TestCase directamente: no necesita la aplicacion ni base de datos.
 *
 * @group pdf-estructura
 */
class ReceptorFiscalDniSinCuitTest extends TestCase
{
    /**
     * Corre print_receptor_block con un cliente armado a mano y devuelve el texto dibujado.
     *
     * @param  string|null $cuit
     * @param  string|null $dni
     * @return array<int, string>
     */
    protected function renglones($cuit, $dni)
    {
        $cliente = new \stdClass();
        $cliente->cuit = $cuit;
        $cliente->dni = $dni;
        $cliente->iva_condition = null;
        $cliente->name = 'Cliente de prueba';
        $cliente->address = 'Calle 123';

        $venta = new \stdClass();
        $venta->client = $cliente;
        $venta->current_acount = null;

        $espia = new EspiaDeReceptorFiscal();

        $metodo = new ReflectionMethod(AfipPdfHelper::class, 'print_receptor_block');
        $metodo->setAccessible(true);
        $metodo->invoke(null, $espia, $venta);

        return $espia->renglones;
    }

    public function test_con_cuit_muestra_el_cuit_y_no_el_dni()
    {
        $r = $this->renglones('20111111112', '11222333');

        $this->assertContains('CUIT: ', $r);
        $this->assertContains('20111111112', $r);
        $this->assertNotContains('DNI: ', $r);
        $this->assertNotContains('11222333', $r);
    }

    public function test_sin_cuit_muestra_el_dni()
    {
        foreach ([null, '', '   '] as $cuit) {
            $r = $this->renglones($cuit, '11222333');

            $this->assertContains('DNI: ', $r);
            $this->assertContains('11222333', $r);
            $this->assertNotContains('CUIT: ', $r);
        }
    }

    public function test_sin_cuit_ni_dni_deja_la_linea_de_cuit_vacia_como_antes()
    {
        $r = $this->renglones(null, null);

        $this->assertContains('CUIT: ', $r);
        $this->assertNotContains('DNI: ', $r);
    }
}
