<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Pdf\SaleLayoutPdf;
use App\Models\Article;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * El remito dibujado con un DISEÑO DE PÁGINA (`SaleLayoutPdf`, misión diseno-pdf-configurable,
 * 1/10/2026): cada dato que pidió Lucas aparece en su caja con el MISMO texto que el remito de
 * siempre, los títulos, los tamaños, el texto libre, la hoja elegida, el pie en cada hoja con los
 * totales finales, y el despacho (qué PDF sale con y sin diseño).
 *
 * Nada se emite: `render()` + `Output('S')` con la compresión apagada (emitir hace exit).
 *
 * @group pdf-diseno-de-pagina
 */
class Remito_con_diseno_de_pagina_Test extends EmpresaTestCase
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
     * @test
     */
    public function cada_dato_pedido_aparece_en_su_caja_con_el_texto_del_remito_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $pdf = $this->pdf_de_venta($venta, $this->perfil_de_venta([], $this->diseno_de_remito_completo()));

        $this->assertSame(0, strpos($pdf, '%PDF'));
        $this->assertDibujaTexto('Comprobante', $pdf, 'El encabezado del emisor es el de siempre.');
        $this->assertDibujaTexto('Taladro percutor 13mm', $pdf, 'La tabla de renglones va en el medio.');

        /** Cliente y cuenta corriente (los tres renglones que hoy salen fijos, ahora en su caja). */
        $this->assertRenglon('Cliente: ', 'Juan Perez Test', $pdf);
        $this->assertRenglon('CUIT/DNI: ', '20123456789', $pdf);
        $this->assertRenglon('Dirección: ', 'Av San Martin 1234', $pdf);
        $this->assertRenglon('Localidad: ', 'Rosario Test', $pdf);
        $this->assertRenglon('Saldo anterior: ', '$15.000', $pdf);
        $this->assertRenglon('Compra actual: ', '$'.Numbers::price($venta->total), $pdf);
        $this->assertRenglon('Compra actual: ', '$2.362,50', $pdf);
        $this->assertRenglon('Saldo: ', '$17.362,50', $pdf);

        /** Datos de la venta: vendedor, sucursal, lista, tipo, métodos de pago con monto y cajas. */
        $this->assertRenglon('Vendedor: ', 'Carla Gomez', $pdf);
        $this->assertRenglon('Sucursal: ', 'Belgrano 450, Rosario', $pdf);
        $this->assertRenglon('Lista de precios: ', 'Minorista Test', $pdf);
        $this->assertRenglon('Tipo de venta: ', 'Mostrador Test', $pdf);
        $this->assertRenglon('Métodos de pago: ', 'Efectivo: $2.000', $pdf);
        $this->assertDibujaTexto('Debito: $362,50', $pdf, 'Cada método de pago va en su renglón.');
        $this->assertRenglon('Cajas: ', 'Caja mostrador', $pdf);
        $this->assertDibujaTexto('Caja posnet', $pdf);

        /**
         * 🔴 La plata, tal cual la caja de totales del remito de siempre (NewSalePdf::print_totals_box()):
         * cada renglón entero, alineado a la derecha.
         */
        $this->assertDibujaTexto('Sub Total: $2.500', $pdf);
        $this->assertDibujaTexto('Menos $250 (10% Descuento efectivo) = $2.250', $pdf);
        $this->assertDibujaTexto('Se aplican descuentos a los servicios', $pdf);
        $this->assertDibujaTexto('Mas $112,50 (5.00% Recargo tarjeta) = $2.362,50', $pdf);
        $this->assertDibujaTexto('Total: '.Numbers::price($venta->total, true, $venta->moneda_id), $pdf);
        $this->assertDibujaTexto('Total: $2.362,50', $pdf);

        /** Comisiones, costos y ganancia (datos internos que Lucas pidió poder poner). */
        $this->assertRenglon('Comisiones: ', 'Carla Gomez 5.00%: $118,13', $pdf);
        $this->assertRenglon('Total menos comisiones: ', '$2.244,37', $pdf);
        $this->assertRenglon('Costos: ', '$1.500', $pdf);
        $this->assertRenglon('Ganancia: ', '$3.200', $pdf);

        /** Observaciones sin rótulo, el texto libre y los títulos de caja. */
        $this->assertDibujaTexto('Entregar por la tarde', $pdf);
        $this->assertNoDibujaTexto('Observaciones: ', $pdf, 'Con etiqueta vacía no hay rótulo.');
        $this->assertDibujaTexto('Gracias por su compra', $pdf);
        $this->assertDibujaTexto('OBSERVACIONES', $pdf);
        $this->assertDibujaTexto('Cliente', $pdf, 'El título de la caja del cliente.');
        $this->assertDibujaTexto('Datos de la venta', $pdf);

        /** Una sola hoja: la cuenta corriente sale una vez (el bloque del cliente de siempre no se duplica). */
        $this->assertSame(1, $this->cantidad_de_hojas($pdf));
        $this->assertSame(1, $this->veces_que_se_dibuja('Saldo anterior: ', $pdf));
        $this->assertNoDibujaTexto('Teléfono: ', $pdf, 'El encabezado no dibuja su propio bloque del cliente.');
    }

    /**
     * El título de caja va en Arial negrita 9; un campo con tamaño, negrita y cursiva propios se
     * dibuja así, el rótulo y el valor.
     *
     * @test
     */
    public function el_titulo_y_los_estilos_de_cada_campo()
    {
        $diseno = $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 12, [
                $this->campo_de_caja('cliente_nombre', ['tamano' => 14, 'negrita' => true, 'cursiva' => true]),
                $this->campo_de_caja('cliente_telefono', ['tamano' => 7]),
            ], 'Datos del cliente'),
        ], [
            $this->caja_de_diseno('caja_totales', 12, [$this->campo_de_caja('tot_total')], '', 'gris'),
        ]);

        $pdf = $this->pdf_de_venta($this->crear_venta_completa(), $this->perfil_de_venta([], $diseno));

        $this->assertSame(['fuente' => 'Helvetica-Bold', 'tamano' => 9.0], $this->estilo_del_texto('Datos del cliente', $pdf));
        $this->assertSame(['fuente' => 'Helvetica-BoldOblique', 'tamano' => 14.0], $this->estilo_del_texto('Cliente: ', $pdf));
        $this->assertSame(['fuente' => 'Helvetica-BoldOblique', 'tamano' => 14.0], $this->estilo_del_texto('Juan Perez Test', $pdf), 'Con negrita, el valor también va en negrita.');
        $this->assertSame(['fuente' => 'Helvetica-Bold', 'tamano' => 7.0], $this->estilo_del_texto('Teléfono: ', $pdf));
        $this->assertSame(['fuente' => 'Helvetica', 'tamano' => 7.0], $this->estilo_del_texto('1155555555', $pdf), 'Sin negrita, el valor va en letra normal.');
        $this->assertSame(['fuente' => 'Helvetica-Bold', 'tamano' => 12.0], $this->estilo_del_texto('Total: $2.362,50', $pdf), 'El Total con el estilo del catálogo: 12 en negrita, como siempre.');
    }

    /**
     * Una venta de contado: la caja de cuenta corriente no se dibuja (aunque tenga título) y la de
     * al lado no se corre.
     *
     * @test
     */
    public function en_una_venta_de_contado_la_caja_de_cuenta_corriente_no_se_dibuja()
    {
        $venta = $this->crear_venta_completa(['save_current_acount' => 0]);
        $diseno = $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cuenta', 6, [$this->campo_de_caja('cc_saldo_anterior'), $this->campo_de_caja('cc_saldo')], 'Cuenta corriente'),
            $this->caja_de_diseno('caja_cliente', 6, [$this->campo_de_caja('cliente_nombre')]),
        ], []);

        $pdf = $this->pdf_de_venta($venta, $this->perfil_de_venta([], $diseno));

        $this->assertNoDibujaTexto('Cuenta corriente', $pdf, 'El título de una caja sin valores no se imprime.');
        $this->assertNoDibujaTexto('Saldo anterior: ', $pdf);
        $this->assertRenglon('Cliente: ', 'Juan Perez Test', $pdf);
    }

    /**
     * Hoja A5 con 8 mm de margen: la página mide 148 × 210 mm y todo arranca en x = 8.
     *
     * @test
     */
    public function una_hoja_a5_con_su_margen()
    {
        $perfil = $this->perfil_de_venta([
            'paper_width_mm' => 148,
            'printable_width_mm' => 148,
            'paper_height_mm' => 210,
            'margin_mm' => 8,
        ], $this->diseno_de_remito_completo());

        $pdf = $this->pdf_de_venta($this->crear_venta_completa(), $perfil);

        $this->assertSame(1, preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $pdf, $mb));
        $this->assertEqualsWithDelta(148, (float) $mb[1] * 25.4 / 72, 0.01, 'Ancho de la hoja A5.');
        $this->assertEqualsWithDelta(210, (float) $mb[2] * 25.4 / 72, 0.01, 'Alto de la hoja A5.');

        /** El encabezado gris de la tabla arranca en el margen: la primera celda en x = 8 mm. */
        $k = 72 / 25.4;
        $this->assertSame(1, preg_match('~\n'.preg_quote(sprintf('%.2F', 8 * $k), '~').' [\d.]+ [\d.]+ -'.preg_quote(sprintf('%.2F', 7 * $k), '~').' re B~', $pdf), 'La tabla (celdas grises de 7 mm) arranca en x = margen.');

        /** Ningún texto se sale a la derecha de la hoja menos el margen (140 mm). */
        preg_match_all('~BT ([\d.]+) [\d.]+ Td~', $pdf, $x);
        foreach ($x[1] as $posicion) {
            $this->assertLessThan(140, (float) $posicion / $k);
            $this->assertGreaterThanOrEqual(8, (float) $posicion / $k);
        }
    }

    /**
     * 🔴 Con "Mostrar pie de página en cada hoja", los totales FINALES salen en cada hoja (no los
     * acumulados hasta ahí), y los renglones nunca se montan sobre el pie.
     *
     * @test
     */
    public function el_pie_en_cada_hoja_lleva_los_totales_finales_en_todas()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 60);
        $venta = Sale::find($venta->id);

        $perfil = $this->perfil_de_venta(['show_totals_on_each_page' => true], $this->diseno_de_remito_completo());
        $pdf = $this->pdf_de_venta($venta, $perfil);

        $hojas = $this->cantidad_de_hojas($pdf);
        $this->assertGreaterThanOrEqual(2, $hojas, '60 renglones más tienen que ocupar varias hojas.');
        $this->assertSame($hojas, $this->veces_que_se_dibuja('Total: $2.362,50', $pdf), 'El Total final sale en cada hoja.');
        $this->assertSame($hojas, $this->veces_que_se_dibuja('Sub Total: $2.500', $pdf));
        $this->assertSame($hojas, $this->veces_que_se_dibuja('Saldo anterior: ', $pdf), 'La zona de arriba sale en todas las hojas.');

        foreach ($this->hojas($pdf) as $numero => $contenido) {
            $this->assertStringContainsString('(Total: $2.362,50) Tj', $contenido, 'La hoja '.($numero + 1).' no tiene el Total.');
        }

        $this->assertNingunTextoBajoElLimite($pdf, 297 - 5 - 7 + 1);
    }

    /**
     * Sin "pie en cada hoja": el pie sale UNA vez, en la última hoja, y ningún renglón se pierde.
     *
     * @test
     */
    public function sin_pie_en_cada_hoja_el_pie_sale_una_vez_en_la_ultima()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 60);
        $venta = Sale::find($venta->id);

        $pdf = $this->pdf_de_venta($venta, $this->perfil_de_venta([], $this->diseno_de_remito_completo()));

        $this->assertGreaterThanOrEqual(2, $this->cantidad_de_hojas($pdf));
        $this->assertSame(1, $this->veces_que_se_dibuja('Total: $2.362,50', $pdf));
        $por_hoja = $this->hojas($pdf);
        $this->assertStringContainsString('(Total: $2.362,50) Tj', end($por_hoja), 'El pie va en la última hoja.');
        $this->assertDibujaTexto('Renglon extra 1', $pdf);
        $this->assertDibujaTexto('Renglon extra 60', $pdf);
        $this->assertNingunTextoBajoElLimite($pdf, 297 - 5 - 7 + 1);
    }

    /**
     * 🔴 El despacho: el perfil se resuelve con las MISMAS reglas que NewSalePdf, y solo un perfil
     * con diseño sale con cajas. Sin diseño, null: el PDF de siempre.
     *
     * @test
     */
    public function el_despacho_elige_el_pdf_de_siempre_sin_diseno_y_el_de_cajas_con_diseno()
    {
        $venta = $this->crear_venta_completa();

        $sin_diseno = $this->perfil_de_venta(['is_default' => true]);
        $con_diseno = $this->perfil_de_venta([], $this->diseno_de_remito_completo());
        $fiscal_con_diseno = $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_remito_completo());
        $tienda_con_diseno = $this->perfil_de_venta(['is_default_tienda' => true], $this->diseno_de_remito_completo());

        $this->assertNull(SaleLayoutPdf::perfil_con_diseno($venta, null, null, null), 'Sin id, el default (sin diseño): el PDF de siempre.');
        $this->assertNull(SaleLayoutPdf::perfil_con_diseno($venta, $sin_diseno->id, null, null));
        $this->assertSame($con_diseno->id, SaleLayoutPdf::perfil_con_diseno($venta, $con_diseno->id, null, null)->id);

        /** Como NewSalePdf: sin factura, un perfil fiscal no se usa (cae al default, sin diseño). */
        $this->assertNull(SaleLayoutPdf::perfil_con_diseno($venta, $fiscal_con_diseno->id, null, null));
        /** Con factura, solo perfiles fiscales. */
        $this->assertSame($fiscal_con_diseno->id, SaleLayoutPdf::perfil_con_diseno($venta, $fiscal_con_diseno->id, 123, null)->id);

        /** La tienda prioriza el "Predeterminado Tienda". */
        $this->assertSame($tienda_con_diseno->id, SaleLayoutPdf::perfil_con_diseno($venta, null, null, 'tienda')->id);

        /** Un perfil de otro dueño nunca. */
        $otro = $this->crear_dueno('Otro dueno '.uniqid());
        $ajeno = PdfColumnProfile::create([
            'user_id' => $otro->id,
            'model_name' => 'sale',
            'name' => 'Ajeno',
            'columns' => [],
            'page_layout' => $this->diseno_de_remito_completo(),
        ]);
        $this->assertNull(SaleLayoutPdf::perfil_con_diseno($venta, $ajeno->id, null, null));
    }

    /**
     * Si el diseño falla al dibujarse (un dato raro), try_render() devuelve null y el controlador
     * cae al PDF de siempre: el cliente final no se lleva un 500.
     *
     * @test
     */
    public function un_diseno_que_falla_devuelve_null_para_caer_al_pdf_de_siempre()
    {
        $perfil = $this->perfil_de_venta([], $this->diseno_de_remito_completo());

        /**
         * Una venta cuyo dueño no existe (en memoria: la base no deja guardarla): el encabezado no
         * tiene emisor y el dibujo tira.
         */
        $venta_rara = $this->crear_venta_completa();
        $venta_rara->user_id = 999999999;

        $this->assertNull(SaleLayoutPdf::try_render($venta_rara, $perfil, null));

        /** Y con una venta sana, el mismo perfil sí dibuja. */
        $this->assertInstanceOf(SaleLayoutPdf::class, SaleLayoutPdf::try_render($this->crear_venta_completa(), $perfil, null));
    }

    /**
     * Agrega renglones a la venta (sin tocar su total: es relleno para ocupar hojas).
     *
     * @param \App\Models\Sale $venta
     * @param int              $cantidad
     * @return void
     */
    private function agregar_renglones($venta, $cantidad)
    {
        for ($i = 1; $i <= $cantidad; $i++) {
            $articulo = Article::create(['name' => 'Renglon extra '.$i, 'user_id' => $this->dueno->id]);
            $venta->articles()->attach($articulo->id, ['amount' => 0, 'price' => 0]);
        }
    }

    /**
     * Ningún texto se dibuja por debajo del límite usable de la hoja (alto − margen − 7).
     *
     * @param string $pdf
     * @param float  $limite_mm
     * @return void
     */
    private function assertNingunTextoBajoElLimite($pdf, $limite_mm)
    {
        $k = 72 / 25.4;
        preg_match_all('~BT [\d.]+ ([\d.]+) Td~', $pdf, $y);

        foreach ($y[1] as $posicion) {
            $this->assertLessThan($limite_mm, 297 - (float) $posicion / $k, 'Un texto quedó debajo del límite de la hoja.');
        }
    }
}
