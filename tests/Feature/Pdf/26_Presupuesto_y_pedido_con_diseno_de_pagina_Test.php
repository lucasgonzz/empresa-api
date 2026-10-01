<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfDocument\BudgetPdfDocument;
use App\Http\Controllers\Helpers\PdfDocument\OrderPdfDocument;
use App\Http\Controllers\Helpers\PdfLayout\CamposDePedidoPdf;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Http\Controllers\Pdf\Layout\MotorDeCajasPdf;
use App\Http\Controllers\Pdf\ProfileDocumentPdf;
use App\Models\Article;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * Un ProfileDocumentPdf que falla al cerrar el documento: tira el Footer() que FPDF corre en
 * Close(), y solo ese. Sirve para probar que try_render() cierra adentro de su respaldo.
 */
class ProfileDocumentPdfQueFallaAlCerrar extends ProfileDocumentPdf
{
    /** @var bool FPDF está cerrando el documento. */
    private $cerrando = false;

    public function Close()
    {
        $this->cerrando = true;
        parent::Close();
    }

    public function Footer()
    {
        if ($this->cerrando) {
            throw new \RuntimeException('El cierre del documento falló.');
        }

        parent::Footer();
    }
}

/**
 * El presupuesto y el pedido online dibujados con un DISEÑO DE PÁGINA (`ProfileDocumentPdf` en
 * modo con cajas, misión diseno-pdf-configurable, 1/10/2026): el encabezado del emisor de
 * siempre sin su bloque del cliente, las cajas de arriba (en todas las hojas), la tabla, y las
 * cajas del pie (en la última hoja) con la MISMA plata que el pie de siempre.
 *
 * El modo de siempre (perfil sin `page_layout`) lo cuidan los tests 10, 11, 12 y 13, que no se
 * tocaron; acá hay además un control de que un perfil sin diseño sale como siempre.
 *
 * @group pdf-diseno-de-pagina
 */
class Presupuesto_y_pedido_con_diseno_de_pagina_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;

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
     * El diseño de presupuesto: cliente y datos del presupuesto arriba; totales, observaciones y un
     * texto libre abajo.
     *
     * @param bool $con_totales
     * @return array
     */
    private function diseno_de_presupuesto($con_totales = true)
    {
        $pie = [];
        if ($con_totales) {
            $pie[] = $this->caja_de_diseno('caja_totales', 12, [
                $this->campo_de_caja('tot_subtotal'),
                $this->campo_de_caja('tot_descuentos'),
                $this->campo_de_caja('tot_recargos'),
                $this->campo_de_caja('tot_ajuste_del_total'),
                $this->campo_de_caja('tot_total'),
            ], '', 'gris');
        }
        $pie[] = $this->caja_de_diseno('caja_observaciones', 12, [
            $this->campo_de_caja('presupuesto_observaciones', ['etiqueta' => '']),
        ], 'OBSERVACIONES', 'gris');
        $pie[] = $this->caja_de_diseno('caja_texto', 12, [
            $this->campo_de_caja('texto_libre', ['id' => 'texto_validez', 'texto' => 'Presupuesto valido por 10 dias']),
        ], '', 'ninguno');

        return $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 6, [
                $this->campo_de_caja('cliente_nombre'),
                $this->campo_de_caja('cliente_cuit'),
            ], 'Cliente'),
            $this->caja_de_diseno('caja_presupuesto', 6, [
                $this->campo_de_caja('presupuesto_vendedor'),
                $this->campo_de_caja('presupuesto_estado'),
            ]),
        ], $pie);
    }

    /**
     * @test
     */
    public function el_presupuesto_con_cajas_dice_lo_mismo_que_el_de_siempre()
    {
        $budget = $this->crear_presupuesto_completo();
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $this->assertDibujaTexto('Presupuesto', $pdf, 'El título del comprobante en el encabezado de siempre.');
        $this->assertDibujaTexto('Taladro percutor 13mm', $pdf, 'La tabla de siempre.');

        $this->assertRenglon('Cliente: ', 'Cliente Presupuesto Test', $pdf);
        $this->assertRenglon('CUIT: ', '20222222229', $pdf);
        $this->assertRenglon('Vendedor: ', 'Vendedora Presupuesto', $pdf);
        $this->assertRenglon('Estado: ', 'Sin confirmar', $pdf);
        $this->assertSame(1, $this->veces_que_se_dibuja('Vendedor: ', $pdf), 'El encabezado no dibuja su propio bloque del cliente.');

        /** 🔴 La plata: los mismos renglones que el pie de siempre (test 10). */
        $this->assertDibujaTexto('Sub Total sin descuentos: $2.300', $pdf);
        $this->assertDibujaTexto('- 10% Descuento por volumen', $pdf);
        $this->assertDibujaTexto('+ 5% Recargo financiero', $pdf);
        $this->assertDibujaTexto('Total: $2.173,50', $pdf);

        $this->assertDibujaTexto('OBSERVACIONES', $pdf);
        $this->assertDibujaTexto('Entrega en 48 horas habiles', $pdf);
        $this->assertDibujaTexto('Presupuesto valido por 10 dias', $pdf);

        /** Las observaciones del cliente solo salen si el diseño tiene el campo (acá no lo tiene). */
        $this->assertNoDibujaTexto('Paga a 30 dias', $pdf);
        $this->assertNoDibujaTexto('Observaciones: Paga a 30 dias', $pdf);
    }

    /**
     * El forzado del presupuesto en una caja: el ajuste con su signo y el Total forzado.
     *
     * @test
     */
    public function el_ajuste_del_total_forzado_en_la_caja_de_totales()
    {
        $budget = $this->crear_presupuesto_completo(['total' => 2000, 'forzar_total_monto' => -173.5]);
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $this->assertDibujaTexto('- $173,50 Ajuste del total', $pdf);
        $this->assertDibujaTexto('Total: $2.000', $pdf);
    }

    /**
     * 🔴 Un presupuesto de CONTADO con descuento o recargo por método de pago, dibujado con el
     * diseño DERIVADO de su perfil (el equivalente al de siempre que arma el diseñador): cada
     * renglón del pie de siempre (`totals_rows()`, el mismo texto que imprime `BudgetPdf`) sale
     * entero en la caja de totales, incluido el del método de pago, y en el mismo orden.
     *
     * @test
     */
    public function el_derivado_de_un_presupuesto_de_contado_dice_lo_mismo_que_el_pie_de_siempre()
    {
        $casos = [
            ['ajuste' => ['discount_amount' => 50], 'total' => 950, 'renglon' => '- $50 Descuento por método de pago'],
            ['ajuste' => ['surchage_amount' => 122.4], 'total' => 1122.4, 'renglon' => '+ $122,40 Recargo por método de pago'],
        ];

        foreach ($casos as $caso) {
            $articulo = $this->crear_articulo('Taladro percutor 13mm');
            $budget = $this->crear_presupuesto(
                [['article' => $articulo, 'amount' => 1, 'price' => 1000, 'bonus' => null]],
                [
                    'total' => $caso['total'],
                    'omitir_en_cuenta_corriente' => 1,
                    'selected_payment_methods' => [array_merge(['current_acount_payment_method_id' => 3, 'amount' => $caso['total'], 'caja_id' => 0], $caso['ajuste'])],
                ]
            );

            $perfil = $this->diseno('budget', 'Presupuesto');
            $perfil->page_layout = DisenoDerivadoPdf::para('budget', $perfil, false, $this->dueno);
            $this->assertContains('tot_ajuste_metodo_de_pago', $this->keys_del_pie($perfil->page_layout), 'El derivado del presupuesto tiene el campo del método de pago.');

            $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

            /** El pie de siempre, con los flags del perfil (null = el default de siempre: encendido). */
            $de_siempre = array_column((new BudgetPdfDocument($budget))->totals_rows([
                'show_total_in_footer' => is_null($perfil->show_total_in_footer) ? true : (bool) $perfil->show_total_in_footer,
                'show_subtotal_in_footer' => is_null($perfil->show_subtotal_in_footer) ? true : (bool) $perfil->show_subtotal_in_footer,
            ]), 'text');

            $this->assertContains($caso['renglon'], $de_siempre, 'El control: el pie de siempre nombra el ajuste.');

            $textos = $this->textos_legibles($pdf);
            $anterior = -1;
            foreach ($de_siempre as $renglon) {
                $posicion = array_search($renglon, $textos, true);
                $this->assertNotFalse($posicion, 'El diseño derivado no dibujó el renglón del pie de siempre: '.$renglon);
                $this->assertGreaterThan($anterior, $posicion, 'El renglón "'.$renglon.'" salió fuera del orden del pie de siempre.');
                $anterior = $posicion;
            }
        }
    }

    /**
     * Las keys de los campos del pie de un diseño.
     *
     * @param array $diseno
     * @return array<int, string>
     */
    private function keys_del_pie($diseno)
    {
        $keys = [];
        foreach ($diseno['pie'] as $item) {
            foreach (isset($item['campos']) ? $item['campos'] : [] as $campo) {
                $keys[] = $campo['key'];
            }
        }

        return $keys;
    }

    /**
     * 🔴 "Sin precios": un diseño sin caja de totales y con el diseño sin columnas de plata no
     * imprime ningún importe.
     *
     * @test
     */
    public function un_diseno_sin_totales_no_imprime_ningun_importe()
    {
        $budget = $this->crear_presupuesto_completo();
        $perfil = $this->diseno('budget', 'Presupuesto sin precios');
        $perfil->page_layout = $this->diseno_de_presupuesto(false);

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $this->assertPdfSinImportes($pdf, 'Un diseño sin caja de totales imprimió un importe.');
        $this->assertNoDibujaTexto('Total: $2.173,50', $pdf);
        $this->assertDibujaTexto('Entrega en 48 horas habiles', $pdf);
    }

    /**
     * @test
     */
    public function el_pedido_con_cajas_respeta_la_regla_del_subtotal()
    {
        $diseno = $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_comprador', 6, [
                $this->campo_de_caja('comprador_nombre'),
                $this->campo_de_caja('comprador_cuit'),
            ], 'Comprador'),
            $this->caja_de_diseno('caja_pedido', 6, [
                $this->campo_de_caja('pedido_modalidad_de_entrega'),
                $this->campo_de_caja('pedido_envio_elegido'),
            ]),
        ], [
            $this->caja_de_diseno('caja_totales', 12, [
                $this->campo_de_caja('tot_subtotal'),
                $this->campo_de_caja('tot_envio'),
                $this->campo_de_caja('tot_cupon'),
                $this->campo_de_caja('tot_ajuste_medio_de_pago'),
                $this->campo_de_caja('tot_total'),
            ], '', 'gris'),
            $this->caja_de_diseno('caja_notas', 12, [$this->campo_de_caja('pedido_notas', ['etiqueta' => ''])], 'NOTAS DEL PEDIDO', 'gris'),
        ]);

        $perfil = $this->diseno('order', 'Pedido online');
        $perfil->page_layout = $diseno;

        $con_extras = $this->pdf_de_documento(new OrderPdfDocument($this->crear_pedido_completo()), $perfil);

        $this->assertDibujaTexto('Pedido online', $con_extras);
        $this->assertRenglon('Cliente: ', 'Marta Gomez', $con_extras);
        $this->assertRenglon('CUIT: ', '20333333336', $con_extras);
        $this->assertRenglon('Entrega: ', 'Envío a domicilio', $con_extras);
        $this->assertRenglon('Envío: ', 'Andreani · Estándar', $con_extras);

        /** D6: con extras, "Subtotal" y los extras como los dice el pedido de siempre; ningún "Total". */
        $this->assertDibujaTexto('Subtotal: $350', $con_extras);
        $this->assertDibujaTexto('Envío: $500', $con_extras);
        $this->assertDibujaTexto('Cupón: PROMO10', $con_extras);
        $this->assertDibujaTexto('Medio de pago: +10%', $con_extras);
        $this->assertNoDibujaTexto('Total: $350', $con_extras);
        $this->assertDibujaTexto('NOTAS DEL PEDIDO', $con_extras);
        $this->assertDibujaTexto('Tocar timbre dos veces', $con_extras);

        $sin_extras = $this->pdf_de_documento(new OrderPdfDocument($this->crear_pedido_completo([], false)), $perfil);
        $this->assertDibujaTexto('Total: $350', $sin_extras);
        $this->assertNoDibujaTexto('Subtotal: $350', $sin_extras);
    }

    /**
     * La hoja del diseño: Carta con 8 mm de margen (216 − 16 = 200 mm útiles, lo que suman las
     * columnas del diseño sembrado).
     *
     * @test
     */
    public function el_presupuesto_respeta_la_hoja_y_el_margen()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();
        $perfil->paper_width_mm = 216;
        $perfil->printable_width_mm = 216;
        $perfil->paper_height_mm = 279;
        $perfil->margin_mm = 8;

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil);

        $k = 72 / 25.4;
        $this->assertSame(1, preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $pdf, $mb));
        $this->assertEqualsWithDelta(216, (float) $mb[1] / $k, 0.01);
        $this->assertEqualsWithDelta(279, (float) $mb[2] / $k, 0.01);
        $this->assertSame(1, preg_match('~\n'.preg_quote(sprintf('%.2F', 8 * $k), '~').' [\d.]+ [\d.]+ -'.preg_quote(sprintf('%.2F', 7 * $k), '~').' re B~', $pdf), 'La tabla arranca en x = margen.');
    }

    /**
     * Muchos renglones: la zona de arriba se repite en cada hoja y el pie sale una vez, en la última.
     *
     * @test
     */
    public function muchos_renglones_repiten_la_zona_de_arriba_y_el_pie_va_en_la_ultima_hoja()
    {
        $budget = $this->crear_presupuesto_completo();
        for ($i = 1; $i <= 70; $i++) {
            $articulo = Article::create(['name' => 'Renglon de relleno '.$i, 'user_id' => $this->dueno->id]);
            $budget->articles()->attach($articulo->id, ['amount' => 0, 'price' => 0]);
        }
        $budget = $budget->fresh();

        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $hojas = $this->cantidad_de_hojas($pdf);
        $this->assertGreaterThanOrEqual(2, $hojas);
        $this->assertSame($hojas, $this->veces_que_se_dibuja('Cliente: ', $pdf), 'La zona de arriba sale en todas las hojas.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Total: $2.173,50', $pdf), 'El pie sale una sola vez.');
        $por_hoja = $this->hojas($pdf);
        $this->assertStringContainsString('(Total: $2.173,50) Tj', end($por_hoja));
        $this->assertDibujaTexto('Renglon de relleno 70', $pdf);
    }

    /**
     * 🔴 Una zona de arriba más alta que el lugar libre de la hoja. El mismo defecto que se midió el
     * 1/10/2026 en el remito (A5, margen 5, 8 renglones y una zona de dos cajas de 12 columnas: 10
     * hojas con todos los renglones en y = 234,8 en una hoja de 210), con el mismo patrón acá: la
     * zona se dibujaba en el encabezado de cada hoja sin medirla y print_item() dibuja siempre el
     * primer renglón de cada hoja. Con todos los campos de arriba del presupuesto en letra de 20 pt
     * la zona no entra en una A5: va SOLO en la primera hoja (y sigue en la segunda), los renglones
     * arrancan donde entra el primero, y nada queda por debajo de H − M en ninguna hoja.
     *
     * @test
     */
    public function una_zona_de_arriba_mas_alta_que_la_hoja_va_solo_en_la_primera_y_nada_sale_del_papel()
    {
        $budget = $this->crear_presupuesto_completo();
        for ($i = 1; $i <= 6; $i++) {
            $articulo = Article::create(['name' => 'Renglon de relleno '.$i, 'user_id' => $this->dueno->id]);
            $budget->articles()->attach($articulo->id, ['amount' => 0, 'price' => 0]);
        }
        $budget = $budget->fresh();

        $del_cliente = [];
        $del_presupuesto = [];
        foreach (CatalogoDeCamposPdf::campos('budget') as $definicion) {
            if ($definicion['zona_sugerida'] !== 'superior') {
                continue;
            }

            $campo = $this->campo_de_caja($definicion['key'], ['tamano' => 20]);
            if ($definicion['categoria'] === 'cliente') {
                $del_cliente[] = $campo;
            } else {
                $del_presupuesto[] = $campo;
            }
        }

        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 12, $del_cliente, 'Datos del cliente'),
            $this->caja_de_diseno('caja_presupuesto', 12, $del_presupuesto, 'Datos del presupuesto'),
        ], $this->diseno_de_presupuesto()['pie']);
        $perfil->paper_width_mm = 148;
        $perfil->printable_width_mm = 148;
        $perfil->paper_height_mm = 210;
        $perfil->margin_mm = 5;

        /** Las columnas del diseño sembrado suman 200 mm: se angostan (en memoria) a los 138 de la A5. */
        $perfil->load('pdf_column_options');
        foreach ($perfil->pdf_column_options as $opcion) {
            $opcion->pivot->width = (int) floor((int) $opcion->pivot->width * 138 / 200);
        }

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $this->assertNadaDebajoDe($pdf, 210 - 5);

        /** La zona sale UNA vez, cada renglón también, y el pie una vez. */
        $this->assertSame(1, $this->veces_que_se_dibuja('Datos del cliente', $pdf));
        $this->assertSame(1, $this->veces_que_se_dibuja('Datos del presupuesto', $pdf));
        for ($i = 1; $i <= 6; $i++) {
            $this->assertSame(1, $this->veces_que_se_dibuja('Renglon de relleno '.$i, $pdf), 'Renglon de relleno '.$i);
        }
        $this->assertSame(1, $this->veces_que_se_dibuja('Total: $2.173,50', $pdf));

        /** Los renglones van en una hoja con el encabezado de la tabla (sus celdas grises de 7 mm). */
        foreach ($this->hojas($pdf) as $numero => $hoja) {
            if (strpos($hoja, '(Renglon de relleno 1) Tj') !== false) {
                $this->assertSame(1, preg_match('~ -19\.84 re B~', $hoja), 'La hoja '.($numero + 1).' tiene renglones sin el encabezado de la tabla.');
            }
        }

        $this->assertLessThanOrEqual(4, $this->cantidad_de_hojas($pdf));
    }

    /**
     * 🔴 El respaldo cubre también el CIERRE del documento: el Footer() de la última hoja y el
     * cierre de FPDF corren en Close(), que recién pasaba en el Output() de emit(), afuera del try
     * de try_render(). Ahora try_render() cierra adentro: si el cierre falla, devuelve null y el
     * controlador cae al PDF de siempre.
     *
     * @test
     */
    public function si_falla_el_cierre_del_documento_try_render_devuelve_null()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();

        /** El control: sin la falla, try_render() devuelve el documento ya cerrado y entero. */
        $sano = ProfileDocumentPdf::try_render(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil);
        $this->assertInstanceOf(ProfileDocumentPdf::class, $sano);
        $this->assertStringEndsWith("%%EOF\n", $sano->Output('S'));

        $this->assertNull(ProfileDocumentPdfQueFallaAlCerrar::try_render(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil));
    }

    /**
     * Una hoja degenerada (papel de 20 mm con margen de 10: la API la acepta) no se dibuja con
     * cajas: try_render() devuelve null debajo de 60 mm de ancho útil y el controlador cae al PDF
     * de siempre, que es A4. El control: el mismo diseño en A4 sí se dibuja.
     *
     * @test
     */
    public function una_hoja_degenerada_cae_al_pdf_de_siempre()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();
        $perfil->paper_width_mm = 20;
        $perfil->printable_width_mm = 20;
        $perfil->margin_mm = 10;

        $this->assertNull(ProfileDocumentPdf::try_render(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil));

        $perfil->paper_width_mm = 210;
        $perfil->printable_width_mm = 210;
        $perfil->margin_mm = 5;

        $this->assertInstanceOf(ProfileDocumentPdf::class, ProfileDocumentPdf::try_render(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil));
    }

    /**
     * Un presupuesto largo con 70 renglones de relleno.
     *
     * @return \App\Models\Budget
     */
    private function presupuesto_largo()
    {
        $budget = $this->crear_presupuesto_completo();
        for ($i = 1; $i <= 70; $i++) {
            $articulo = Article::create(['name' => 'Renglon de relleno '.$i, 'user_id' => $this->dueno->id]);
            $budget->articles()->attach($articulo->id, ['amount' => 0, 'price' => 0]);
        }

        return $budget->fresh();
    }

    /**
     * Afirma que $total sale en CADA hoja con la tabla (y solo en esas), que hay más de una, y que
     * nada se dibuja por debajo de la hoja menos el margen.
     *
     * @param string                       $pdf
     * @param string                       $total
     * @param \App\Models\PdfColumnProfile $perfil
     * @return void
     */
    private function assertTotalEnCadaHoja($pdf, $total, $perfil)
    {
        $hojas = $this->hojas($pdf);
        $this->assertGreaterThanOrEqual(2, count($hojas), 'El comprobante largo ocupa varias hojas.');

        foreach ($hojas as $numero => $hoja) {
            $this->assertStringContainsString('('.$total.') Tj', $hoja, 'La hoja '.($numero + 1).' no tiene el Total.');
        }

        $hoja = MotorDeCajasPdf::geometria_de_la_hoja($perfil);
        $this->assertNadaDebajoDe($pdf, $hoja['alto_de_hoja'] - $hoja['margen']);
    }

    /**
     * 🔴 "Mostrar pie de página en cada hoja" en un presupuesto DISEÑADO: como el remito con cajas,
     * el pie va en cada hoja con los totales finales (no los acumulados), reservándole su alto.
     *
     * @test
     */
    public function un_presupuesto_disenado_con_pie_en_cada_hoja_lleva_el_total_en_todas()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $this->diseno_de_presupuesto();
        $perfil->show_totals_on_each_page = true;

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($this->presupuesto_largo()), $perfil);

        $this->assertTotalEnCadaHoja($pdf, 'Total: $2.173,50', $perfil);
        $this->assertSame($this->cantidad_de_hojas($pdf), $this->veces_que_se_dibuja('Presupuesto valido por 10 dias', $pdf), 'Todo el pie, en cada hoja.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Renglon de relleno 70', $pdf));
    }

    /**
     * 🔴 El mismo tilde en un presupuesto que todavía NO se diseñó: el modo de siempre no sabe
     * repetir el pie, así que se dibuja con el diseño DERIVADO del perfil (el que el diseñador
     * muestra de arranque), y el Total sale en cada hoja. El tilde no miente.
     *
     * @test
     */
    public function un_presupuesto_sin_disenar_con_pie_en_cada_hoja_usa_el_derivado_y_lleva_el_total_en_todas()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $this->assertNull($perfil->page_layout, 'El perfil sembrado no tiene diseño.');
        $perfil->show_totals_on_each_page = true;

        $documento = new BudgetPdfDocument($this->presupuesto_largo());
        $pdf = new ProfileDocumentPdf($documento, $perfil);
        $this->assertNotNull($pdf->pdf_x0, 'Se dibuja con diseño (el derivado), no con el modo de siempre.');

        $pdf->SetCompression(false);
        $pdf->render();
        $binario = $pdf->Output('S');

        $this->assertTotalEnCadaHoja($binario, 'Total: $2.173,50', $perfil);
        $this->assertSame(1, $this->veces_que_se_dibuja('Renglon de relleno 70', $binario));
    }

    /**
     * Y con el tilde apagado, el presupuesto sin diseño sale con el modo de siempre: sin la
     * geometría del diseño y con el Total UNA vez, en la última hoja.
     *
     * @test
     */
    public function un_presupuesto_sin_disenar_sin_pie_en_cada_hoja_sale_como_siempre()
    {
        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->show_totals_on_each_page = false;

        $documento = new BudgetPdfDocument($this->presupuesto_largo());
        $pdf = new ProfileDocumentPdf($documento, $perfil);
        $this->assertNull($pdf->pdf_x0, 'El modo de siempre (sin la geometría del diseño).');

        $pdf->SetCompression(false);
        $pdf->render();
        $binario = $pdf->Output('S');

        $this->assertGreaterThanOrEqual(2, $this->cantidad_de_hojas($binario));
        $this->assertSame(1, $this->veces_que_se_dibuja('Total: $2.173,50', $binario), 'El Total una sola vez, como siempre.');
        $por_hoja = $this->hojas($binario);
        $this->assertStringContainsString('(Total: $2.173,50) Tj', end($por_hoja));
    }

    /**
     * El pedido online, igual: sin diseñar y con el tilde, el derivado y el Total en cada hoja. Sin
     * envío, cupón ni recargo (con ellos el pedido no imprime Total: lo calcula la tienda).
     *
     * @test
     */
    public function un_pedido_sin_disenar_con_pie_en_cada_hoja_lleva_el_total_en_todas()
    {
        $pedido = $this->crear_pedido_completo([], false);
        for ($i = 1; $i <= 70; $i++) {
            $articulo = Article::create(['name' => 'Renglon de pedido '.$i, 'user_id' => $this->dueno->id]);
            $pedido->articles()->attach($articulo->id, ['price' => 0, 'amount' => 0]);
        }
        $documento = new OrderPdfDocument($pedido->fresh());

        $perfil = $this->diseno('order', 'Pedido online');
        $perfil->show_totals_on_each_page = true;

        $pdf = new ProfileDocumentPdf($documento, $perfil);
        $this->assertNotNull($pdf->pdf_x0, 'Se dibuja con el derivado.');
        $pdf->SetCompression(false);
        $pdf->render();
        $binario = $pdf->Output('S');

        $total = (new CamposDePedidoPdf(new OrderPdfDocument($pedido->fresh()), false))->valor('tot_total', $this->campo_de_caja('tot_total'));
        $this->assertSame('$350', $total, '2 x $100 + 3 x $50; los 70 de relleno van en $0.');
        $this->assertTotalEnCadaHoja($binario, 'Total: '.$total, $perfil);
    }

    /**
     * Un perfil SIN diseño sale como siempre (control: el bloque del cliente del encabezado, la caja
     * gris de totales y el recuadro de observaciones de siempre).
     *
     * @test
     */
    public function un_perfil_sin_diseno_sale_como_siempre()
    {
        $budget = $this->crear_presupuesto_completo();
        $perfil = $this->diseno('budget', 'Presupuesto');
        $this->assertNull($perfil->page_layout, 'El diseño sembrado no tiene page_layout.');

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($budget), $perfil);

        $this->assertRenglon('Cliente: ', 'Cliente Presupuesto Test', $pdf);
        $this->assertDibujaTexto('Total: $2.173,50', $pdf);
        $this->assertDibujaTexto('OBSERVACIONES', $pdf);
        $this->assertNoDibujaTexto('Presupuesto valido por 10 dias', $pdf);

        /** La caja gris de totales de siempre: 200 mm de ancho arrancando en x = 5. */
        $this->assertSame(1, preg_match('~14\.17 [\d.]+ 566\.93 -[\d.]+ re B~', $pdf));
    }

    /**
     * Un presupuesto nunca es factura: si el diseño trae bloques fijos de ARCA, se ignoran.
     *
     * @test
     */
    public function un_diseno_de_presupuesto_ignora_los_bloques_fijos()
    {
        $diseno = $this->diseno_de_presupuesto();
        array_unshift($diseno['superior'], ['tipo' => 'fijo', 'key' => 'afip_receptor']);
        $diseno['pie'][] = ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => true];

        $perfil = $this->diseno('budget', 'Presupuesto');
        $perfil->page_layout = $diseno;

        $pdf = $this->pdf_de_documento(new BudgetPdfDocument($this->crear_presupuesto_completo()), $perfil);

        $this->assertNoDibujaTexto('CAE N°:', $pdf);
        $this->assertNoDibujaTexto('Condición de venta: ', $pdf);
        $this->assertDibujaTexto('Total: $2.173,50', $pdf);
    }
}
