<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;

/**
 * El PDF por DISEÑO de un presupuesto de contado explica el descuento o recargo por método de
 * pago (misión presupuesto-contado-o-cuenta-corriente, 1/10/2026).
 *
 * 🔴 POR QUÉ EXISTE ESTE ARCHIVO. El "Total:" de los dos PDF de presupuesto sale de
 * `BudgetHelper::getTotal()`, que con un presupuesto de contado incluye el ajuste por método de
 * pago (`Σ surchage_amount − Σ discount_amount` de las filas guardadas). El PDF de siempre
 * (`Pdf/BudgetPdf.php`) ya imprime un renglón que lo nombra; el PDF por diseño
 * (`PdfDocument/BudgetPdfDocument.php`, de la misión del PDF personalizable) se copió de aquél
 * ANTES de que existiera el ajuste y llegó a `develop` sin el renglón: el cliente leía un Total
 * que no sumaba contra los renglones y nadie se lo explicaba. Estos tests lo fijan.
 *
 * Ningún test emite el PDF: `render()` + `Output('S')` con la compresión apagada, igual que
 * `tests/Feature/Pdf/10_Render_de_presupuesto_con_perfil_Test`.
 *
 * @group pdf-documentos
 */
class Pdf_por_diseno_de_presupuesto_de_contado_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf();
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    /**
     * Presupuesto de un solo renglón ($1.000) guardado de contado, con una fila de reparto que
     * trae el ajuste pedido.
     *
     * @param array $fila_extra     `discount_amount` y/o `surchage_amount` de la fila.
     * @param float $total          Total guardado (el neto, ya con el ajuste).
     * @param array $atributos      Atributos del presupuesto que pisan los de por defecto.
     * @return \App\Models\Budget
     */
    protected function presupuesto_de_contado(array $fila_extra, $total, array $atributos = [])
    {
        $articulo = $this->crear_articulo('Taladro percutor 13mm', ['bar_code' => '7791111111111']);

        $fila = array_merge([
            'current_acount_payment_method_id' => 3,
            'amount'                           => $total,
            'caja_id'                          => 0,
        ], $fila_extra);

        return $this->crear_presupuesto([
            ['article' => $articulo, 'amount' => 1, 'price' => 1000, 'bonus' => null],
        ], array_merge([
            'total'                      => $total,
            'omitir_en_cuenta_corriente' => 1,
            'selected_payment_methods'   => [$fila],
        ], $atributos));
    }

    /**
     * @param \App\Models\Budget $budget
     * @param string             $diseno
     * @return string
     */
    protected function pdf_de($budget, $diseno = 'Presupuesto')
    {
        return $this->renderizar(new BudgetPdfDocument($budget), $this->diseno('budget', $diseno));
    }

    /**
     * @test
     */
    public function un_descuento_por_metodo_de_pago_se_imprime_restando_y_con_el_total_neto()
    {
        $budget = $this->presupuesto_de_contado(['discount_amount' => 50], 950);

        $this->assertEquals(950.0, (float) BudgetHelper::getTotal($budget), 'getTotal() tiene que dar el neto: 1000 - 50.', 0.01);

        $pdf = $this->pdf_de($budget);

        $this->assertPdfContiene($this->en_el_pdf('(- $50 Descuento por método de pago)'), $pdf);
        /**
         * El Sub Total se imprime IGUAL que con un forzado: sin él, el renglón del ajuste quedaría
         * solo, sin decir nunca de cuánto se partía.
         */
        $this->assertPdfContiene('(Sub Total sin descuentos: $1.000)', $pdf);
        $this->assertPdfContiene('(Total: $'.Numbers::price(BudgetHelper::getTotal($budget)).')', $pdf);
        $this->assertPdfContiene('(Total: $950)', $pdf);
        $this->assertPdfNoContiene($this->en_el_pdf('Recargo por método de pago'), $pdf);
    }

    /**
     * @test
     */
    public function un_recargo_por_metodo_de_pago_se_imprime_sumando_y_con_el_total_neto()
    {
        $budget = $this->presupuesto_de_contado(['surchage_amount' => 122.4], 1122.4);

        $this->assertEquals(1122.4, (float) BudgetHelper::getTotal($budget), 'getTotal() tiene que dar el neto: 1000 + 122,40.', 0.01);

        $pdf = $this->pdf_de($budget);

        $this->assertPdfContiene($this->en_el_pdf('(+ $122,40 Recargo por método de pago)'), $pdf);
        $this->assertPdfContiene('(Sub Total sin descuentos: $1.000)', $pdf);
        $this->assertPdfContiene('(Total: $1.122,40)', $pdf);
        $this->assertPdfNoContiene($this->en_el_pdf('Descuento por método de pago'), $pdf);
    }

    /**
     * El reparto sin descuento ni recargo no inventa ningún renglón: el PDF queda como el de un
     * presupuesto común.
     *
     * @test
     */
    public function un_reparto_sin_ajuste_no_agrega_ningun_renglon()
    {
        $budget = $this->presupuesto_de_contado([], 1000);

        $pdf = $this->pdf_de($budget);

        $this->assertPdfNoContiene($this->en_el_pdf('por método de pago'), $pdf);
        $this->assertPdfNoContiene('Sub Total sin descuentos', $pdf);
        $this->assertPdfContiene('(Total: $1.000)', $pdf);
    }

    /**
     * Un presupuesto a cuenta corriente no tiene ajuste aunque la fila guardada traiga montos
     * (por ejemplo un reparto viejo que quedó en la columna): el ajuste solo cuenta si es de contado,
     * y es la misma regla que aplica `getTotal()`.
     *
     * @test
     */
    public function un_presupuesto_a_cuenta_corriente_no_imprime_el_ajuste()
    {
        $budget = $this->presupuesto_de_contado(['discount_amount' => 50], 1000, ['omitir_en_cuenta_corriente' => 0]);

        $this->assertEquals(1000.0, (float) BudgetHelper::getTotal($budget), 'A cuenta corriente no hay ajuste.', 0.01);

        $pdf = $this->pdf_de($budget);

        $this->assertPdfNoContiene($this->en_el_pdf('por método de pago'), $pdf);
        $this->assertPdfContiene('(Total: $1.000)', $pdf);
    }

    /**
     * El diseño "sin precios" no imprime ningún renglón de la caja de totales (un descuento suelto
     * sin importe no dice nada): el ajuste tampoco.
     *
     * @test
     */
    public function el_diseno_sin_precios_no_imprime_el_ajuste()
    {
        $budget = $this->presupuesto_de_contado(['discount_amount' => 50], 950);

        $pdf = $this->pdf_de($budget, 'Presupuesto sin precios');

        $this->assertPdfNoContiene($this->en_el_pdf('por método de pago'), $pdf);
        $this->assertPdfNoContiene('Total:', $pdf);
    }
}
