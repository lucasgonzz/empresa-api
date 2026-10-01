<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\Numbers;
use App\Http\Controllers\Helpers\PdfLayout\CatalogoDeCamposPdf;
use App\Http\Controllers\Pdf\Afip\AfipPdfHelper;
use App\Http\Controllers\Pdf\SaleLayoutPdf;
use App\Models\Article;
use App\Models\PdfColumnProfile;
use App\Models\Sale;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * Un SaleLayoutPdf cuyo pie de la ÚLTIMA hoja falla: tira el Footer() que FPDF corre al cerrar el
 * documento (Close()), y solo ese. Sirve para probar que try_render() cierra el documento adentro
 * de su respaldo.
 */
class SaleLayoutPdfQueFallaAlCerrar extends SaleLayoutPdf
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
            throw new \RuntimeException('El pie de la última hoja falló.');
        }

        parent::Footer();
    }
}

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
        preg_match_all('~BT (-?[\d.]+) -?[\d.]+ Td~', $pdf, $x);
        foreach ($x[1] as $posicion) {
            $this->assertLessThan(140, (float) $posicion / $k);
            $this->assertGreaterThanOrEqual(8, (float) $posicion / $k);
        }

        /** Y nada por debajo del límite usable de la hoja (210 − 8 − 7). */
        $this->assertNadaDebajoDe($pdf, 210 - 8 - 7);
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

        $this->assertNadaDebajoDe($pdf, 297 - 5 - 7);
    }

    /**
     * 🔴 A5 con "pie en cada hoja" y un pie alto: el encabezado y el pie no dejan lugar para ningún
     * renglón, ni siquiera sacando la zona de arriba de las hojas que siguen (el 1/10/2026 el pie se
     * dibujaba en cada hoja POR DEBAJO del papel: hasta 217 mm en una hoja de 210, en las 33 hojas,
     * con un renglón por hoja). El PDF se dibuja como si el pie fuera solo en la última hoja: sale
     * UNA vez, al final, y nada queda por debajo del límite de la hoja. El control: el mismo diseño
     * en A4 sí deja lugar y conserva el pie en cada hoja.
     *
     * El pie es el del remito completo con cuatro cajas de texto legal delante (152 mm en A5): con
     * el pie del remito completo solo (78 mm), sacar la zona de arriba de las hojas que siguen ya
     * le deja lugar, y ese caso es el de una_zona_de_arriba_que_no_deja_lugar_para_el_pie_cede_y_va_solo_en_la_primera_hoja().
     *
     * @test
     */
    public function un_pie_en_cada_hoja_que_no_deja_lugar_para_renglones_sale_solo_en_la_ultima_hoja()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 30);

        $perfil_a5 = $this->perfil_de_venta([
            'paper_width_mm' => 148,
            'printable_width_mm' => 148,
            'paper_height_mm' => 210,
            'margin_mm' => 8,
            'show_totals_on_each_page' => true,
        ], $this->remito_completo_con_un_pie_mas_alto());

        $pdf_a5 = new SaleLayoutPdf(Sale::find($venta->id), $perfil_a5, null);
        $pdf_a5->SetCompression(false);
        $pdf_a5->render();
        $a5 = $pdf_a5->Output('S');

        $this->assertFalse($pdf_a5->pie_en_cada_hoja(), 'En A5 este pie no deja lugar para ningún renglón: tiene que ir solo en la última hoja.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Total: $2.362,50', $a5), 'El pie sale UNA sola vez.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Gracias por su compra', $a5));
        $this->assertSame($this->cantidad_de_hojas($a5), $this->veces_que_se_dibuja('Saldo anterior: ', $a5), 'La zona de arriba sigue en todas las hojas.');

        /** El pie va al final: después del último renglón, y termina en la última hoja. */
        $textos = $this->textos_legibles($a5);
        $this->assertGreaterThan(array_search('Renglon extra 30', $textos, true), array_search('Total: $2.362,50', $textos, true));
        $por_hoja = $this->hojas($a5);
        $this->assertStringContainsString('(Gracias por su compra) Tj', end($por_hoja), 'El pie termina en la última hoja.');

        /** Nada por debajo del límite usable de la hoja (210 − 8 − 7), y menos del papel. */
        $this->assertNadaDebajoDe($a5, 210 - 8 - 7);

        /** Varios renglones por hoja (con el pie en cada hoja salía uno por hoja: 33 hojas). */
        $this->assertLessThan(10, $this->cantidad_de_hojas($a5));

        /** El control: el mismo diseño en A4 deja lugar y conserva el pie en cada hoja. */
        $perfil_a4 = $this->perfil_de_venta(['show_totals_on_each_page' => true], $this->remito_completo_con_un_pie_mas_alto());
        $pdf_a4 = new SaleLayoutPdf(Sale::find($venta->id), $perfil_a4, null);
        $pdf_a4->SetCompression(false);
        $pdf_a4->render();
        $a4 = $pdf_a4->Output('S');

        $this->assertTrue($pdf_a4->pie_en_cada_hoja());
        $this->assertGreaterThanOrEqual(2, $this->cantidad_de_hojas($a4));
        $this->assertSame($this->cantidad_de_hojas($a4), $this->veces_que_se_dibuja('Total: $2.362,50', $a4));
        $this->assertNadaDebajoDe($a4, 297 - 5 - 7);
    }

    /**
     * 🔴 El caso anterior con el pie del remito completo solo (A5, margen 8, "pie en cada hoja"):
     * encabezado + zona de arriba + pie no dejan lugar para un renglón, pero sin la zona sí. La
     * zona cede: sale UNA vez, en la primera hoja, y el pie sigue en cada hoja con renglones, como
     * pidió el tilde. Ninguna hoja lleva el pie sin la tabla (una hoja con solo la zona no le
     * reservó lugar), y nada queda por debajo del límite de la hoja.
     *
     * @test
     */
    public function una_zona_de_arriba_que_no_deja_lugar_para_el_pie_cede_y_va_solo_en_la_primera_hoja()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 30);

        $layout = new SaleLayoutPdf(Sale::find($venta->id), $this->perfil_de_venta([
            'paper_width_mm' => 148,
            'printable_width_mm' => 148,
            'paper_height_mm' => 210,
            'margin_mm' => 8,
            'show_totals_on_each_page' => true,
        ], $this->diseno_de_remito_completo()), null);
        $layout->SetCompression(false);
        $layout->render();
        $pdf = $layout->Output('S');

        $this->assertTrue($layout->pie_en_cada_hoja(), 'Sin la zona de arriba entran el renglón más alto y el pie: el pie sigue en cada hoja.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Saldo anterior: ', $pdf), 'La zona de arriba sale una sola vez.');
        $this->assertSame(1, $this->veces_que_se_dibuja('Renglon extra 30', $pdf));

        $hojas_con_tabla = 0;
        foreach ($this->hojas($pdf) as $numero => $hoja) {
            $con_tabla = $this->tiene_encabezado_de_tabla($hoja);
            $this->assertSame($con_tabla, strpos($hoja, '(Total: $2.362,50) Tj') !== false, 'El pie va en cada hoja con la tabla y en ninguna otra (hoja '.($numero + 1).').');
            $hojas_con_tabla += $con_tabla ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual(2, $hojas_con_tabla);

        $this->assertNadaDebajoDe($pdf, 210 - 8 - 7);
    }

    /**
     * 🔴 Una zona de arriba más alta que el lugar libre de la hoja (medido el 1/10/2026: A5,
     * margen 5, 8 renglones y una zona de dos cajas de 12 columnas con 33 campos daban 10 hojas,
     * con todos los renglones en y = 234,8 en una hoja de 210: la zona se dibujaba en cada hoja
     * sin medirla y el primer renglón de cada hoja iba siempre). La zona va SOLO en la primera
     * hoja y, como no entra entera, sigue en la segunda; los renglones arrancan donde entra el
     * primero, con el encabezado de la tabla; y nada queda por debajo de H − M en ninguna hoja.
     *
     * @test
     */
    public function una_zona_de_arriba_mas_alta_que_la_hoja_va_solo_en_la_primera_y_nada_sale_del_papel()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 5);

        $pdf = $this->pdf_de_venta(Sale::find($venta->id), $this->perfil_de_venta([
            'paper_width_mm' => 148,
            'printable_width_mm' => 148,
            'paper_height_mm' => 210,
            'margin_mm' => 5,
        ], $this->diseno_con_una_zona_de_arriba_enorme()));

        $this->assertNadaDebajoDe($pdf, 210 - 5);

        /** La zona sale UNA vez y cada uno de los 8 renglones también. */
        $this->assertSame(1, $this->veces_que_se_dibuja('Datos del cliente', $pdf));
        $this->assertSame(1, $this->veces_que_se_dibuja('Datos de la venta', $pdf));
        $renglones = ['Taladro percutor 13mm', 'Mecha widia 8mm', 'Instalacion', 'Renglon extra 1', 'Renglon extra 2', 'Renglon extra 3', 'Renglon extra 4', 'Renglon extra 5'];
        foreach ($renglones as $renglon) {
            $this->assertSame(1, $this->veces_que_se_dibuja($renglon, $pdf), $renglon);
        }

        /** Los renglones van debajo del encabezado de la tabla, en una hoja que lo tiene. */
        foreach ($this->hojas($pdf) as $numero => $hoja) {
            if (strpos($hoja, '(Renglon extra 1) Tj') !== false || strpos($hoja, '(Taladro percutor 13mm) Tj') !== false) {
                $this->assertTrue($this->tiene_encabezado_de_tabla($hoja), 'La hoja '.($numero + 1).' tiene renglones sin el encabezado de la tabla.');
            }
        }

        /** El pie, una vez. Y 3 hojas, no 10. */
        $this->assertSame(1, $this->veces_que_se_dibuja('Gracias por su compra', $pdf));
        $this->assertLessThanOrEqual(3, $this->cantidad_de_hojas($pdf));
    }

    /**
     * La misma zona enorme con "pie en cada hoja": el pie va en cada hoja de la tabla y en ninguna
     * de las que solo llevan la zona, y nada sale del papel.
     *
     * @test
     */
    public function con_pie_en_cada_hoja_y_una_zona_enorme_el_pie_va_solo_en_las_hojas_de_la_tabla()
    {
        $venta = $this->crear_venta_completa();
        $this->agregar_renglones($venta, 40);

        $layout = new SaleLayoutPdf(Sale::find($venta->id), $this->perfil_de_venta([
            'paper_width_mm' => 148,
            'printable_width_mm' => 148,
            'paper_height_mm' => 210,
            'margin_mm' => 5,
            'show_totals_on_each_page' => true,
        ], $this->diseno_con_una_zona_de_arriba_enorme()), null);
        $layout->SetCompression(false);
        $layout->render();
        $pdf = $layout->Output('S');

        $this->assertTrue($layout->pie_en_cada_hoja());
        $this->assertNadaDebajoDe($pdf, 210 - 5);
        $this->assertSame(1, $this->veces_que_se_dibuja('Datos del cliente', $pdf));
        $this->assertSame(1, $this->veces_que_se_dibuja('Renglon extra 40', $pdf));

        $hojas_con_tabla = 0;
        foreach ($this->hojas($pdf) as $numero => $hoja) {
            $con_tabla = $this->tiene_encabezado_de_tabla($hoja);
            $this->assertSame($con_tabla, strpos($hoja, '(Total: $2.362,50) Tj') !== false, 'El pie va en cada hoja con la tabla y en ninguna otra (hoja '.($numero + 1).').');
            $hojas_con_tabla += $con_tabla ? 1 : 0;
        }
        $this->assertGreaterThanOrEqual(2, $hojas_con_tabla);
        $this->assertLessThan($this->cantidad_de_hojas($pdf), $hojas_con_tabla, 'Las hojas de la zona sola no llevan la tabla ni el pie.');
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
        $this->assertNadaDebajoDe($pdf, 297 - 5 - 7);
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
     * 🔴 El respaldo cubre también el CIERRE del documento. Con "pie en cada hoja", el pie de la
     * última hoja lo dibuja el Footer() que FPDF corre en Close(), y el cierre recién pasaba en el
     * Output() de emit(), afuera del try de try_render(): si ese pie fallaba, el cliente final se
     * llevaba un 500 en vez del PDF de siempre. Ahora try_render() cierra adentro y devuelve null.
     *
     * @test
     */
    public function si_falla_el_pie_de_la_ultima_hoja_try_render_devuelve_null()
    {
        $perfil = $this->perfil_de_venta(['show_totals_on_each_page' => true], $this->diseno_de_remito_completo());
        $venta = $this->crear_venta_completa();

        /** El control: sin la falla, try_render() devuelve el documento ya cerrado y entero. */
        $sano = SaleLayoutPdf::try_render(Sale::find($venta->id), $perfil, null);
        $this->assertInstanceOf(SaleLayoutPdf::class, $sano);
        $this->assertStringEndsWith("%%EOF\n", $sano->Output('S'));

        $this->assertNull(SaleLayoutPdfQueFallaAlCerrar::try_render(Sale::find($venta->id), $perfil, null));
    }

    /**
     * 🔴 Una hoja degenerada (papel de 20 mm con margen de 10: la API la acepta) no cuelga el
     * pedido: con ancho útil de 4 mm o menos, el alto del receptor de la factura no se terminaba de
     * calcular nunca (estimate_lines() le restaba a la palabra un ancho ≤ 0). try_render() devuelve
     * null debajo de 60 mm de ancho útil (sale el PDF de siempre, que es A4 y no usa la hoja del
     * perfil), y la cuenta del receptor termina igual aunque alguien la llame con esa hoja.
     *
     * @test
     */
    public function una_hoja_degenerada_no_se_cuelga_y_cae_al_pdf_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $diseno = $this->diseno_de_remito_completo();

        $degenerada = $this->perfil_de_venta(['paper_width_mm' => 20, 'printable_width_mm' => 20, 'margin_mm' => 10], $diseno);
        $this->assertNull(SaleLayoutPdf::try_render(Sale::find($venta->id), $degenerada, null), 'Ancho útil 0: sale el PDF de siempre.');

        /** El borde: 59 mm de ancho útil cae al de siempre; 60 mm se dibuja con cajas. */
        $angosta = $this->perfil_de_venta(['paper_width_mm' => 69, 'printable_width_mm' => 69, 'margin_mm' => 5], $diseno);
        $this->assertNull(SaleLayoutPdf::try_render(Sale::find($venta->id), $angosta, null));
        $justa = $this->perfil_de_venta(['paper_width_mm' => 70, 'printable_width_mm' => 70, 'margin_mm' => 5], $diseno);
        $this->assertInstanceOf(SaleLayoutPdf::class, SaleLayoutPdf::try_render(Sale::find($venta->id), $justa, null));

        /** La cuenta del alto del receptor termina con la hoja degenerada (antes no volvía nunca). */
        $pdf = new SaleLayoutPdf(Sale::find($venta->id), $degenerada, null);
        $this->assertGreaterThan(0, AfipPdfHelper::estimate_receptor_height($pdf, Sale::find($venta->id)));
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
     * ¿La hoja tiene el encabezado de la tabla? Sus celdas grises son las únicas de 7 mm de alto
     * (FPDF las escribe en puntos: -19.84).
     *
     * @param string $hoja
     * @return bool
     */
    private function tiene_encabezado_de_tabla($hoja)
    {
        return preg_match('~ -19\.84 re B~', $hoja) === 1;
    }

    /**
     * Una zona de arriba más alta que el lugar libre de una A5: dos cajas de 12 columnas con TODOS
     * los campos de arriba del catálogo de venta (cliente y cuenta corriente en una, los datos de
     * la venta en la otra), y el pie del remito completo.
     *
     * @return array
     */
    private function diseno_con_una_zona_de_arriba_enorme()
    {
        $del_cliente = [];
        $de_la_venta = [];
        foreach (CatalogoDeCamposPdf::campos('sale') as $definicion) {
            if ($definicion['zona_sugerida'] !== 'superior') {
                continue;
            }

            if ($definicion['categoria'] === 'venta') {
                $de_la_venta[] = $this->campo_de_caja($definicion['key']);
            } else {
                $del_cliente[] = $this->campo_de_caja($definicion['key']);
            }
        }

        $completo = $this->diseno_de_remito_completo();

        return $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_cliente', 12, $del_cliente, 'Datos del cliente'),
            $this->caja_de_diseno('caja_venta', 12, $de_la_venta, 'Datos de la venta'),
        ], $completo['pie']);
    }

    /**
     * El remito completo con cuatro cajas de texto legal delante del pie: un pie que en A5 no deja
     * lugar para un renglón ni sin la zona de arriba (152 mm), y en A4 sí (125 mm).
     *
     * @return array
     */
    private function remito_completo_con_un_pie_mas_alto()
    {
        $texto = 'Este comprobante no es valido como factura. Los precios incluyen IVA. Las devoluciones se aceptan dentro de los 30 dias con el ticket y el embalaje original, sin uso y en perfecto estado. La garantia la da el fabricante.';

        $legales = [];
        for ($i = 1; $i <= 4; $i++) {
            $legales[] = $this->caja_de_diseno('caja_legal_'.$i, 12, [
                $this->campo_de_caja('texto_libre', ['id' => 'texto_legal_'.$i, 'texto' => $texto]),
            ], '', 'ninguno');
        }

        $diseno = $this->diseno_de_remito_completo();
        $diseno['pie'] = array_merge($legales, $diseno['pie']);

        return $diseno;
    }
}
