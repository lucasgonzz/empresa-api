<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Models\PdfColumnOption;
use App\Models\Sale;
use App\Services\PdfColumnService;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;

/**
 * La factura de ARCA dibujada con un DISEÑO DE PÁGINA (misión diseno-pdf-configurable,
 * 1/10/2026): los dos bloques fijos que pide ARCA (el receptor arriba; importes + QR + CAE abajo)
 * están siempre, aunque el diseño guardado no los traiga; el cuadro de importes se puede apagar,
 * el QR y el CAE no; y un perfil fiscal sin comprobante resoluble sale como remito, sin los fijos
 * (el mismo criterio que el PDF de siempre).
 *
 * El QR de ARCA le pega a api.qrserver.com: cada render corre con el https cortado
 * (`sin_red_https()`). El pie se dibuja igual, sin la imagen del QR (que no es lo que se mide).
 *
 * @group pdf-diseno-de-pagina
 */
class Factura_con_diseno_de_pagina_Test extends EmpresaTestCase
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
     * Un diseño de factura sin los bloques fijos: los tiene que agregar el dibujo.
     *
     * @param array $pie_extra
     * @return array
     */
    private function diseno_de_factura(array $pie_extra = [])
    {
        return $this->diseno_de_pagina([
            $this->caja_de_diseno('caja_venta', 12, [
                $this->campo_de_caja('venta_vendedor'),
                $this->campo_de_caja('venta_metodos_de_pago'),
            ], 'Venta'),
        ], array_merge([
            $this->caja_de_diseno('caja_totales', 12, [
                $this->campo_de_caja('tot_subtotal'),
                $this->campo_de_caja('tot_descuentos'),
            ], '', 'ninguno'),
        ], $pie_extra));
    }

    /**
     * @param \App\Models\Sale             $venta
     * @param \App\Models\PdfColumnProfile $perfil
     * @param mixed                        $ticket_id
     * @return string
     */
    private function pdf_de_factura($venta, $perfil, $ticket_id)
    {
        return $this->sin_red_https(function () use ($venta, $perfil, $ticket_id) {
            return $this->pdf_de_venta($venta, $perfil, $ticket_id);
        });
    }

    /**
     * @test
     */
    public function la_factura_con_cajas_lleva_el_receptor_los_importes_y_el_cae_aunque_el_diseno_no_los_traiga()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'B');
        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura());

        $pdf = $this->pdf_de_factura($venta, $perfil, $ticket->id);

        /** El encabezado fiscal de siempre: banda ORIGINAL, letra y número de comprobante. */
        $this->assertDibujaTexto('ORIGINAL', $pdf);
        $this->assertDibujaTexto('00000027', $pdf, 'El número de la factura.');

        /**
         * La letra del comprobante EN SU RECUADRO: la "B" grande (Inter negrita 24, la letra del
         * recuadro central) y, justo debajo, su código "COD. 06" (factura B = tipo 6). Una "B"
         * suelta en cualquier otra parte de la hoja no alcanza.
         */
        $textos = $this->textos_legibles($pdf);
        $codigo = array_search('COD. 06', $textos, true);
        $this->assertNotFalse($codigo, 'El código del comprobante en el recuadro de la letra.');
        $this->assertSame('B', $textos[$codigo - 1], 'La letra va justo arriba de su código.');
        $this->assertSame(['fuente' => 'Inter-Bold', 'tamano' => 24.0], $this->estilo_del_texto('B', $pdf), 'La letra del recuadro, no una B cualquiera.');

        /** El receptor fiscal (bloque fijo de arriba), con las reglas de siempre. */
        $this->assertRenglon('CUIT: ', '20123456789', $pdf);
        $this->assertRenglon('Condición de venta: ', 'Cuenta corriente', $pdf);
        $this->assertDibujaTexto('Apellido y Nombre / Razón Social: ', $pdf);

        /** Las cajas del diseño. */
        $this->assertRenglon('Vendedor: ', 'Carla Gomez', $pdf);
        $this->assertDibujaTexto('Sub Total: $2.500', $pdf);

        /** El pie de ARCA (bloque fijo de abajo): importes y CAE. */
        $this->assertDibujaTexto('Importe Total:', $pdf);
        $this->assertDibujaTexto('$2.362,50', $pdf);
        $this->assertDibujaTexto('CAE N°:', $pdf);
        $this->assertDibujaTexto('76123456789012', $pdf);
        $this->assertDibujaTexto('Comprobante Autorizado', $pdf);

        /** El receptor va en la zona de arriba: antes que la tabla. El CAE, después. */
        $textos = $this->textos_legibles($pdf);
        $this->assertLessThan(array_search('Nombre', $textos, true), array_search('Condición de venta: ', $textos, true));
        $this->assertGreaterThan(array_search('Nombre', $textos, true), array_search('CAE N°:', $textos, true));
    }

    /**
     * 🔴 La factura dice lo mismo que la de siempre aunque el perfil esté en modo "simple": el
     * camino fiscal de NewSalePdf (discounts() y surchages(), desde descuentos_y_recargos()) no lee
     * discount_display_mode y escribe siempre el renglón descriptivo. Con el diseño derivado del
     * perfil, la factura con cajas escribe esos mismos renglones. El remito sí respeta el modo.
     *
     * @test
     */
    public function la_factura_en_modo_simple_escribe_los_descuentos_como_la_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'B');

        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true, 'discount_display_mode' => 'simple']);
        $perfil->page_layout = DisenoDerivadoPdf::para('sale', $perfil, true, $this->dueno);

        $pdf = $this->pdf_de_factura($venta, $perfil, $ticket->id);

        $this->assertDibujaTexto('Menos $250 (10% Descuento efectivo) = $2.250', $pdf);
        /** El porcentaje del recargo sale como lo guarda el pivot ("5.00"), igual que en NewSalePdf::surchages(). */
        $this->assertDibujaTexto('Mas $112,50 (5.00% Recargo tarjeta) = $2.362,50', $pdf);
        $this->assertNoDibujaTexto('10% Descuento efectivo', $pdf, 'El renglón "simple" es del remito, no de la factura.');
        $this->assertNoDibujaTexto('5.00% Recargo tarjeta', $pdf);

        /** El control: el mismo perfil como remito respeta el modo "simple". */
        $remito = $this->perfil_de_venta(['discount_display_mode' => 'simple']);
        $remito->page_layout = DisenoDerivadoPdf::para('sale', $remito, false, $this->dueno);
        $pdf_remito = $this->pdf_de_venta($venta, $remito);

        $this->assertDibujaTexto('10% Descuento efectivo', $pdf_remito);
        $this->assertNoDibujaTexto('Menos $250 (10% Descuento efectivo) = $2.250', $pdf_remito);
    }

    /**
     * Y la condición del Sub Total en la factura es la del camino fiscal de siempre: total bruto
     * distinto del total (o canje de puntos), SIN mirar el ajuste del total forzado. Una venta
     * forzada justo a su total bruto ($2.500): la factura de siempre no imprime Sub Total ni
     * descuentos, y la de cajas tampoco. El remito sí (ahí el ajuste es lo que los separa del Total).
     *
     * @test
     */
    public function la_factura_decide_el_sub_total_sin_mirar_el_ajuste_forzado()
    {
        $venta = $this->crear_venta_completa(['total' => 2500, 'forzar_total_monto' => 137.5]);
        $ticket = $this->crear_factura($venta, 'B');

        $factura = $this->pdf_de_factura($venta, $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura()), $ticket->id);

        $this->assertNoDibujaTexto('Sub Total: $2.500', $factura);
        $this->assertNoDibujaTexto('Menos $250 (10% Descuento efectivo) = $2.250', $factura);
        $this->assertDibujaTexto('CAE N°:', $factura, 'La factura se dibujó entera.');

        /** El control: el mismo diseño como remito los imprime, porque el ajuste los separa del Total. */
        $remito = $this->pdf_de_venta($venta, $this->perfil_de_venta([], $this->diseno_de_factura()));

        $this->assertDibujaTexto('Sub Total: $2.500', $remito);
        $this->assertDibujaTexto('Menos $250 (10% Descuento efectivo) = $2.250', $remito);
    }

    /**
     * 🔴 El bloque del cliente de ARCA se le cambia el ancho: a 6 columnas, con una caja de 6 al
     * lado, los dos van en la MISMA fila y cada uno en su celda (nada se sale de su celda ni de la
     * hoja), su recuadro llega al alto de la fila, y el bloque fiscal dice lo mismo que a lo ancho:
     * CUIT, condición frente al IVA, condición de venta, nombre y domicilio, en el mismo orden.
     *
     * @test
     */
    public function el_bloque_del_cliente_de_arca_a_seis_columnas_comparte_la_fila_con_otra_caja()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'B');

        $diseno = function ($cols) {
            return $this->diseno_de_pagina([
                ['tipo' => 'fijo', 'key' => 'afip_receptor', 'cols' => $cols],
                $this->caja_de_diseno('caja_venta', 6, [
                    $this->campo_de_caja('venta_vendedor'),
                    $this->campo_de_caja('venta_metodos_de_pago'),
                    $this->campo_de_caja('venta_cajas'),
                ], 'Venta'),
            ], []);
        };

        $a_lo_ancho = $this->pdf_de_factura($venta, $this->perfil_de_venta(['is_afip_ticket' => true], $diseno(12)), $ticket->id);
        $angosto = $this->pdf_de_factura($venta, $this->perfil_de_venta(['is_afip_ticket' => true], $diseno(6)), $ticket->id);

        /** A4, margen 5: unidad (200 + 2) / 12; la celda 1 va de 5 a 104 y la 2 de 106 a 205. */
        $unidad = 202 / 12;
        $celda_1 = [5, 5 + 6 * $unidad - 2];
        $celda_2 = [5 + 6 * $unidad, 205];

        /** El recuadro del bloque: su divisoria vertical al medio de la celda dice de dónde a dónde va. */
        $recuadro = $this->recuadro_del_receptor($angosto, ($celda_1[0] + $celda_1[1]) / 2);
        $this->assertNotNull($recuadro, 'El bloque del cliente se dibuja en la celda 1, con su divisoria al medio de la celda.');

        /** La caja de al lado, en la misma fila: arranca en la celda 2 a la misma altura y con el mismo alto. */
        $caja = null;
        foreach ($this->rectangulos_de_la_primera_hoja($angosto) as $rect) {
            if (abs($rect['x'] - $celda_2[0]) < 0.05 && abs($rect['ancho'] - ($celda_2[1] - $celda_2[0])) < 0.05) {
                $caja = $rect;
            }
        }
        $this->assertNotNull($caja, 'La caja "Venta" va en la celda 2 (al lado del bloque, no abajo).');
        $this->assertEqualsWithDelta($recuadro[0], $caja['y'], 0.05, 'Los dos arrancan a la misma altura: la misma fila.');
        $this->assertEqualsWithDelta($recuadro[1] - $recuadro[0], $caja['alto'], 0.05, 'El recuadro del bloque llega al alto de la fila.');

        /** Nada se sale de su celda: lo de la fila que arranca en la celda 1 termina antes del borde. */
        foreach ($this->textos_con_posicion($angosto) as $texto) {
            if ($texto['y'] < $recuadro[0] || $texto['y'] > $recuadro[1]) {
                continue;
            }
            $celda = $texto['x'] < $celda_2[0] ? $celda_1 : $celda_2;
            $this->assertGreaterThanOrEqual($celda[0], $texto['x'], '"'.$texto['texto'].'" arranca antes de su celda.');
            $this->assertLessThanOrEqual($celda[1] + 0.05, $texto['fin'], '"'.$texto['texto'].'" pasa el borde de su celda.');
        }
        $this->assertNadaDebajoDe($angosto, 297 - 5);

        /** Y el bloque fiscal dice lo mismo, en el mismo orden, que a lo ancho. */
        $recuadro_ancho = $this->recuadro_del_receptor($a_lo_ancho, 105);
        $this->assertNotNull($recuadro_ancho);
        $this->assertSame(
            $this->texto_del_bloque($a_lo_ancho, $recuadro_ancho, [5, 205]),
            $this->texto_del_bloque($angosto, $recuadro, $celda_1)
        );
        $this->assertStringContainsString('CUIT: 20123456789', $this->texto_del_bloque($angosto, $recuadro, $celda_1));
        $this->assertStringContainsString('Condición frente al IVA: Responsable inscripto', $this->texto_del_bloque($angosto, $recuadro, $celda_1));
        $this->assertStringContainsString('Condición de venta: Cuenta corriente', $this->texto_del_bloque($angosto, $recuadro, $celda_1));
        $this->assertStringContainsString('Apellido y Nombre / Razón Social: Juan Perez Test', $this->texto_del_bloque($angosto, $recuadro, $celda_1));
        $this->assertStringContainsString('Domicilio Comercial: Av San Martin 1234', $this->texto_del_bloque($angosto, $recuadro, $celda_1));
    }

    /**
     * El recuadro del bloque del cliente de ARCA: [arriba, abajo] en mm, leído de su divisoria
     * vertical (la línea al medio del bloque) que abarca el renglón "Condición de venta: " (el
     * único rótulo que es solo del bloque del cliente: el encabezado del emisor también tiene
     * líneas al medio y un "CUIT: "). null si no hay una así.
     *
     * @param string $pdf
     * @param float  $x_de_la_divisoria
     * @return array{0: float, 1: float}|null
     */
    private function recuadro_del_receptor($pdf, $x_de_la_divisoria)
    {
        $y_del_renglon = null;
        foreach ($this->textos_con_posicion($pdf) as $texto) {
            if ($texto['texto'] === 'Condición de venta: ') {
                $y_del_renglon = $texto['y'];
            }
        }
        if (is_null($y_del_renglon)) {
            return null;
        }

        foreach ($this->lineas_de_la_primera_hoja($pdf) as $linea) {
            $arriba = min($linea[1], $linea[3]);
            $abajo = max($linea[1], $linea[3]);
            if (abs($linea[0] - $x_de_la_divisoria) < 0.05 && abs($linea[2] - $x_de_la_divisoria) < 0.05 && $arriba < $y_del_renglon && $abajo > $y_del_renglon) {
                return [$arriba, $abajo];
            }
        }

        return null;
    }

    /**
     * Lo que dice el bloque: sus textos (los que caen adentro del recuadro y de la celda), en el
     * orden en que se dibujan y con los espacios normalizados (un rótulo que se parte en dos líneas
     * dice lo mismo que entero).
     *
     * @param string $pdf
     * @param array  $recuadro [arriba, abajo]
     * @param array  $celda    [izquierda, derecha]
     * @return string
     */
    private function texto_del_bloque($pdf, $recuadro, $celda)
    {
        $partes = [];
        foreach ($this->textos_con_posicion($pdf) as $texto) {
            if ($texto['y'] >= $recuadro[0] && $texto['y'] <= $recuadro[1] && $texto['x'] >= $celda[0] && $texto['x'] < $celda[1]) {
                $partes[] = $texto['texto'];
            }
        }

        return trim(preg_replace('/\s+/', ' ', implode(' ', $partes)));
    }

    /**
     * El cuadro de importes se apaga (como "Mostrar total en el pie"); el QR y el CAE no.
     *
     * @test
     */
    public function sin_importes_no_sale_el_cuadro_pero_si_el_cae()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'A');
        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura([
            ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => false],
        ]));

        $pdf = $this->pdf_de_factura($venta, $perfil, $ticket->id);

        $this->assertNoDibujaTexto('Importe Total:', $pdf);
        $this->assertNoDibujaTexto('Otros Tributos', $pdf);
        $this->assertDibujaTexto('CAE N°:', $pdf);
        $this->assertDibujaTexto('76123456789012', $pdf);

        /** El control: con importes, la factura A desglosa el IVA. */
        $con = $this->pdf_de_factura($venta, $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura()), $ticket->id);
        $this->assertDibujaTexto('Importe Neto Gravado:', $con);
        $this->assertDibujaTexto('$1.952,48', $con);
    }

    /**
     * Un perfil fiscal sin comprobante de ARCA resoluble sale como remito y sin los bloques fijos
     * (mismo criterio que el PDF de siempre).
     *
     * @test
     */
    public function un_perfil_fiscal_sin_comprobante_sale_como_remito_sin_bloques_fijos()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura([
            ['tipo' => 'fijo', 'key' => 'afip_pie', 'importes' => true],
        ]));

        $pdf = $this->pdf_de_factura($venta, $perfil, null);

        $this->assertNoDibujaTexto('ORIGINAL', $pdf);
        $this->assertNoDibujaTexto('CAE N°:', $pdf);
        $this->assertNoDibujaTexto('Condición de venta: ', $pdf, 'Sin comprobante no hay receptor fiscal.');
        $this->assertDibujaTexto('Comprobante', $pdf, 'Encabezado de remito.');
        $this->assertDibujaTexto('Sub Total: $2.500', $pdf, 'Las cajas se dibujan igual.');

        /** Un comprobante de OTRA venta tampoco sirve (se busca solo entre los de esta venta). */
        $otra = $this->crear_venta_completa();
        $ajeno = $this->crear_factura($otra, 'B');
        $this->assertNoDibujaTexto('CAE N°:', $this->pdf_de_factura($venta, $perfil, $ajeno->id));
    }

    /**
     * Con "pie en cada hoja", el bloque de ARCA sale en cada hoja (como el PDF de siempre) y los
     * renglones le dejan lugar.
     *
     * @test
     */
    public function con_pie_en_cada_hoja_el_bloque_de_arca_sale_en_cada_hoja()
    {
        $venta = $this->crear_venta_completa();
        for ($i = 1; $i <= 45; $i++) {
            $articulo = $this->crear_articulo('Renglon fiscal '.$i);
            $venta->articles()->attach($articulo->id, ['amount' => 0, 'price' => 0]);
        }
        $ticket = $this->crear_factura($venta, 'B');
        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true, 'show_totals_on_each_page' => true], $this->diseno_de_factura());

        $pdf = $this->pdf_de_factura(Sale::find($venta->id), $perfil, $ticket->id);

        $hojas = $this->cantidad_de_hojas($pdf);
        $this->assertGreaterThanOrEqual(2, $hojas);
        $this->assertSame($hojas, $this->veces_que_se_dibuja('CAE N°:', $pdf), 'El CAE sale en cada hoja.');
        $this->assertSame($hojas, $this->veces_que_se_dibuja('ORIGINAL', $pdf));
        $this->assertDibujaTexto('Renglon fiscal 45', $pdf);

        /** Nada (textos, recuadros, líneas, imágenes) por debajo del límite de la hoja (297 − 5 − 7). */
        $this->assertNadaDebajoDe($pdf, 297 - 5 - 7);
    }

    /**
     * 🔴 Las columnas fiscales de la tabla (precio sin IVA, importe de IVA) llevan los descuentos y
     * recargos de la venta, como en el PDF de siempre. `AfipItemCalculator` los aplica solo a los
     * renglones marcados como artículo o servicio (`is_article` / `is_service`, las marcas que pone
     * `NewSalePdf::get_sale_items()`): sin esas marcas la factura con cajas salía con los valores
     * sin descuentos.
     *
     * @test
     */
    public function las_columnas_fiscales_llevan_los_descuentos_de_la_venta_como_siempre()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'A');
        $perfil = $this->perfil_de_venta(['is_afip_ticket' => true], $this->diseno_de_factura());

        $columna = PdfColumnOption::where('model_name', 'sale')->where('value_resolver', 'item_price_without_iva')->first();
        $perfil->pdf_column_options()->attach($columna->id, ['visible' => true, 'order' => 10, 'width' => 20, 'wrap_content' => false]);
        $perfil = $perfil->fresh();

        $pdf = $this->pdf_de_factura($venta, $perfil, $ticket->id);

        /** Lo que imprime el PDF de siempre: el renglón marcado como artículo, con el contexto de NewSalePdf. */
        $venta_fresca = Sale::find($venta->id);
        $ticket_de_la_venta = \App\Http\Controllers\Pdf\Afip\TicketInfoHelper::resolve_afip_ticket_for_sale($venta_fresca, $ticket->id);
        $afip_helper = (new \App\Http\Controllers\Pdf\Afip\TicketInfoHelper($ticket_de_la_venta, $venta_fresca, $this->dueno))->afip_helper();
        $taladro = $venta_fresca->articles->first();
        $contexto = [
            'item' => $taladro,
            'index' => 1,
            'sale' => $venta_fresca,
            'afip_ticket' => $ticket_de_la_venta,
            'afip_helper' => $afip_helper,
            'numbers' => \App\Http\Controllers\Helpers\Numbers::class,
            'general_helper' => \App\Http\Controllers\Helpers\GeneralHelper::class,
        ];

        $sin_marca = (string) PdfColumnService::resolve_value('item_price_without_iva', $contexto);
        $taladro->is_article = true;
        $como_siempre = (string) PdfColumnService::resolve_value('item_price_without_iva', $contexto);

        $this->assertNotSame($sin_marca, $como_siempre, 'El control: con la marca de artículo el valor cambia (lleva los descuentos de la venta).');
        $this->assertDibujaTexto($como_siempre, $pdf, 'La columna fiscal no tiene el valor del PDF de siempre.');
        $this->assertNoDibujaTexto($sin_marca, $pdf);
    }

    /**
     * La factura en una hoja Carta con 10 mm de margen: la banda ORIGINAL y el receptor ocupan el
     * ancho útil de esa hoja (216 − 20 = 196 mm) arrancando en x = 10.
     *
     * @test
     */
    public function la_factura_respeta_la_hoja_y_el_margen()
    {
        $venta = $this->crear_venta_completa();
        $ticket = $this->crear_factura($venta, 'B');
        $perfil = $this->perfil_de_venta([
            'is_afip_ticket' => true,
            'paper_width_mm' => 216,
            'printable_width_mm' => 216,
            'paper_height_mm' => 279,
            'margin_mm' => 10,
        ], $this->diseno_de_factura());

        $pdf = $this->pdf_de_factura($venta, $perfil, $ticket->id);

        $k = 72 / 25.4;
        $this->assertSame(1, preg_match('~/MediaBox \[0 0 ([\d.]+) ([\d.]+)\]~', $pdf, $mb));
        $this->assertEqualsWithDelta(216, (float) $mb[1] / $k, 0.01);
        $this->assertEqualsWithDelta(279, (float) $mb[2] / $k, 0.01);

        /** La banda ORIGINAL: una celda con borde de 196 mm que arranca en x = 10 (`x y ancho -alto re S`). */
        $banda = sprintf('%.2F', 10 * $k).' [\d.]+ '.sprintf('%.2F', 196 * $k).' -[\d.]+ re S';
        $this->assertSame(1, preg_match('~'.$banda.'~', $pdf), 'La banda ORIGINAL no ocupa el ancho útil de la hoja.');
    }
}
