<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\PdfLayout\DisenoDerivadoPdf;
use App\Http\Controllers\Pdf\Ticket\BloquesFiscalesDeTicket;
use App\Http\Controllers\Pdf\Ticket\TicketComanderaEscPos;
use App\Models\PdfColumnProfile;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;

/**
 * El motor del ticket de comandera diseñado (`TicketComanderaEscPos`, misión
 * diseno-ticket-comandera, 9/10/2026, §4 del plan): las reglas que el SPA replica en la vista
 * previa del diseñador, afirmadas renglón por renglón sobre la salida en texto y, donde importa,
 * sobre los bytes ESC/POS.
 *
 * Cada test arma su venta, su perfil y su diseño adentro de la transacción. Un perfil sin columnas
 * visibles no tiene tabla: así los tests de cajas afirman la salida ENTERA sin el ruido de los
 * renglones.
 *
 * @group pdf-ticket-comandera
 */
class Motor_escpos_del_ticket_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;
    use PerfilesDeTicketDeComandera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_dueno_para_pdf(false);
        $this->actingAs($this->dueno, 'web');
    }

    protected function tearDown(): void
    {
        $this->borrar_archivos_de_prueba();

        parent::tearDown();
    }

    /**
     * Un ticket de remito de 80 mm SIN columnas (sin tabla) con ese diseño.
     *
     * @param array $diseno
     * @param int   $ancho_mm
     * @return PdfColumnProfile
     */
    private function ticket_sin_tabla(array $diseno, $ancho_mm = 80)
    {
        return $this->perfil_de_ticket($this->dueno->id, false, ['page_layout' => $diseno], $ancho_mm, []);
    }

    /**
     * Las líneas de texto del ticket.
     *
     * @param \App\Models\Sale       $venta
     * @param PdfColumnProfile       $perfil
     * @param \App\Models\AfipTicket|null $factura
     * @param array|null             $diseno
     * @return array<int, string>
     */
    private function lineas($venta, $perfil, $factura = null, $diseno = null)
    {
        return (new TicketComanderaEscPos($venta, $perfil, $factura, $diseno))->lineas();
    }

    /**
     * Afirma que ninguna línea de texto pasa de N caracteres.
     *
     * @param array<int, string> $lineas
     * @param int                $caracteres
     * @return void
     */
    private function assertNingunaLineaPasaDe($lineas, $caracteres)
    {
        foreach ($lineas as $linea) {
            $this->assertLessThanOrEqual($caracteres, mb_strlen($linea, 'UTF-8'), 'La línea "'.$linea.'" se pasa del rollo.');
        }
    }

    /**
     * @test
     */
    public function dos_cajas_de_una_fila_salen_lado_a_lado_con_un_espacio_de_separacion()
    {
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([
            $this->caja_de_diseno('a', 6, [
                $this->campo_de_caja('venta_numero', ['etiqueta' => 'Num']),
                $this->campo_de_caja('cliente_telefono', ['etiqueta' => 'Tel']),
            ], '', 'borde'),
            $this->caja_de_diseno('b', 6, [
                $this->campo_de_caja('cliente_nombre', ['etiqueta' => '']),
            ], '', 'ninguno'),
        ], []));

        /**
         * N = 48: cada caja de 6 columnas mide 24; la primera deja 1 de separación (contenido 23) y
         * la segunda usa sus 24. La más corta (la de la derecha) se completa con espacios.
         */
        $this->assertSame([
            str_pad('Num: 1520', 23).' '.'Juan Perez Test',
            'Tel: 1155555555',
            str_repeat('-', 23),
        ], $this->lineas($venta, $perfil));
    }

    /**
     * @test
     */
    public function la_alineacion_va_con_espacios_dentro_de_la_caja()
    {
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([
            $this->caja_de_diseno('a', 12, [
                $this->campo_de_caja('cliente_nombre', ['etiqueta' => '', 'alineacion' => 'derecha']),
                $this->campo_de_caja('venta_numero', ['etiqueta' => 'N', 'alineacion' => 'centro']),
                $this->campo_de_caja('cliente_telefono', ['etiqueta' => 'Tel', 'alineacion' => 'izquierda']),
            ], '', 'ninguno'),
        ], []));

        $this->assertSame([
            str_repeat(' ', 33).'Juan Perez Test',
            str_repeat(' ', 20).'N: 1520',
            'Tel: 1155555555',
        ], $this->lineas($venta, $perfil));
    }

    /**
     * @test
     */
    public function grande_cuenta_doble_y_parte_a_la_mitad_de_caracteres_y_alto_doble_no()
    {
        $venta = $this->crear_venta_completa();
        $texto = 'Gracias por elegirnos siempre';

        $grande = $this->ticket_sin_tabla($this->diseno_de_pagina([], [
            $this->caja_de_diseno('a', 12, [
                $this->campo_de_caja('texto_libre', ['id' => 'gracias', 'texto' => $texto, 'tamano' => 18]),
            ], '', 'ninguno'),
        ]));

        /** 29 caracteres en grande no entran en 48 columnas (24 letras): se parte por palabras. */
        $this->assertSame(['Gracias por elegirnos', 'siempre'], $this->lineas($venta, $grande));

        $bytes = (new TicketComanderaEscPos($venta, $grande))->bytes();
        $this->assertStringContainsString(TicketComanderaEscPos::TAMANO_GRANDE.'Gracias por elegirnos', $bytes);
        /** Al terminar, la impresora vuelve al tamaño normal antes del corte. */
        $this->assertStringEndsWith(TicketComanderaEscPos::TAMANO_NORMAL.TicketComanderaEscPos::CORTE, $bytes);

        $alto = $this->ticket_sin_tabla($this->diseno_de_pagina([], [
            $this->caja_de_diseno('a', 12, [
                $this->campo_de_caja('texto_libre', ['id' => 'gracias', 'texto' => $texto, 'tamano' => 12]),
            ], '', 'ninguno'),
        ]));

        /** Alto doble no cambia el ancho: entra en un renglón. */
        $this->assertSame([$texto], $this->lineas($venta, $alto));
        $this->assertStringContainsString(TicketComanderaEscPos::TAMANO_ALTO.$texto, (new TicketComanderaEscPos($venta, $alto))->bytes());
    }

    /**
     * @test
     */
    public function una_fila_vacia_no_ocupa_lugar_y_una_caja_vacia_deja_sus_columnas_en_blanco()
    {
        /** Venta en pesos y sin incoterms: la cotización y los incoterms no tienen valor. */
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([
            $this->caja_de_diseno('vacia', 12, [
                $this->campo_de_caja('venta_cotizacion'),
                $this->campo_de_caja('venta_incoterms'),
            ], 'Datos de exportación', 'borde'),
            $this->caja_de_diseno('izquierda_vacia', 6, [$this->campo_de_caja('venta_cotizacion')], '', 'borde'),
            $this->caja_de_diseno('derecha', 6, [$this->campo_de_caja('venta_numero', ['etiqueta' => 'N'])], '', 'ninguno'),
        ], []));

        /** Ni el título ni la línea de la caja vacía; la de 6 vacía deja sus 23 + 1 en blanco. */
        $this->assertSame([str_repeat(' ', 24).'N: 1520'], $this->lineas($venta, $perfil));
    }

    /**
     * @test
     */
    public function el_titulo_va_en_negrita_primero_y_una_lista_va_un_renglon_por_elemento()
    {
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([
            $this->caja_de_diseno('pagos', 12, [$this->campo_de_caja('venta_metodos_de_pago')], 'PAGOS', 'gris'),
        ], []));

        $lineas = $this->lineas($venta, $perfil);

        $this->assertSame('PAGOS', $lineas[0]);
        $this->assertSame(0, strpos($lineas[1], 'Métodos de pago: Efectivo'), 'El rótulo va en el primer elemento.');
        $this->assertSame(0, strpos($lineas[2], 'Debito'), 'El segundo elemento va en su renglón, sin rótulo.');
        $this->assertSame(str_repeat('-', 48), $lineas[3], '"gris" en una comandera es "Con línea".');
        $this->assertCount(4, $lineas);

        $bytes = (new TicketComanderaEscPos($venta, $perfil))->bytes();
        $this->assertStringContainsString(TicketComanderaEscPos::NEGRITA_SI.'PAGOS', $bytes);
    }

    /**
     * @test
     */
    public function la_tabla_reparte_los_caracteres_a_80_mm_y_parte_el_nombre()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_ticket($this->dueno->id, false, ['page_layout' => $this->diseno_de_pagina([], [])], 80);

        $motor = new TicketComanderaEscPos($venta, $perfil);
        $columnas = $motor->columnas_de_la_tabla();

        /** 10/2/6/6 medias columnas sobre 48: 20/4/12/12 (lo que sobra iría a Nombre, que tiene salto). */
        $this->assertSame([20, 4, 12, 12], array_column($columnas, 'caracteres'));
        $this->assertSame([19, 3, 11, 12], array_column($columnas, 'contenido'), 'Cada columna menos la última deja un espacio.');

        $lineas = $motor->lineas();

        /** Encabezado: los numéricos a la derecha, cada rótulo cortado a su columna ("Cant" → "Can"). */
        $this->assertSame(str_pad('Nombre', 19).' '.'Can'.' '.str_pad('Precio', 11, ' ', STR_PAD_LEFT).' '.str_pad('Sub total', 12, ' ', STR_PAD_LEFT), $lineas[0]);
        $this->assertSame(str_repeat('-', 48), $lineas[1]);

        /** "Taladro percutor 13mm" (21) no entra en 19: con salto de línea, sigue abajo. */
        $this->assertSame(0, strpos($lineas[2], 'Taladro percutor '));
        $this->assertSame('13mm', $lineas[3]);
        $this->assertMatchesRegularExpression('/^Taladro percutor\s+2\s+\S+\s+\S+$/', $lineas[2], 'La cantidad, el precio y el subtotal van en el primer renglón del ítem, a la derecha.');
        $this->assertSame(str_repeat('-', 48), end($lineas), 'Línea de guiones al final de la tabla.');
        $this->assertNingunaLineaPasaDe($lineas, 48);
    }

    /**
     * @test
     */
    public function la_tabla_reparte_los_caracteres_a_55_mm()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_ticket($this->dueno->id, false, ['page_layout' => $this->diseno_de_pagina([], [])], 55);

        $motor = new TicketComanderaEscPos($venta, $perfil);
        $columnas = $motor->columnas_de_la_tabla();

        /**
         * N = 33: 10/2/6/6 medias dan 13/2/8/8 = 31; los 2 que sobran van a Nombre (la de salto de
         * línea): 15/2/8/8.
         */
        $this->assertSame(33, $motor->caracteres_por_renglon());
        $this->assertSame([15, 2, 8, 8], array_column($columnas, 'caracteres'));
        $this->assertSame([14, 1, 7, 8], array_column($columnas, 'contenido'));

        $lineas = $motor->lineas();
        $this->assertSame(str_pad('Nombre', 14).' C '.' Precio'.' '.'Sub tota', $lineas[0]);
        $this->assertSame(0, strpos($lineas[2], 'Taladro '));
        $this->assertSame(0, strpos($lineas[3], 'percutor 13mm'));
        $this->assertNingunaLineaPasaDe($lineas, 33);
    }

    /**
     * @test
     */
    public function un_numero_que_no_entra_en_su_columna_no_se_corta_y_un_texto_sin_salto_si()
    {
        $venta = $this->crear_venta_completa();

        /**
         * Cant en 1 media columna (2 caracteres, 1 de contenido: no es la última) y Nombre sin salto
         * de línea, con un nombre de 30 caracteres que no entra.
         */
        $perfil = $this->perfil_de_ticket($this->dueno->id, false, ['page_layout' => $this->diseno_de_pagina([], [])], 80, [
            'item_name' => [30, false],
            'item_amount' => [3, false],
            'item_price' => [20, false],
        ]);

        $taladro = $venta->articles()->first();
        $taladro->name = 'Taladro percutor 13mm con valija y mechas';
        $taladro->save();
        $venta->articles()->updateExistingPivot($taladro->id, ['amount' => 12]);
        $venta = $venta->fresh();

        $motor = new TicketComanderaEscPos($venta, $perfil);

        /** 9/1/6 medias: 18/2/12 = 32; sin columna con salto, los 16 que sobran van a la más ancha. */
        $this->assertSame([34, 2, 12], array_column($motor->columnas_de_la_tabla(), 'caracteres'));

        $lineas = $motor->lineas();

        /** Sin salto de línea el nombre se corta en su columna (33 de contenido). */
        $this->assertSame('Taladro percutor 13mm con valija ', mb_substr($lineas[2], 0, 33, 'UTF-8'));
        $this->assertSame(1, preg_match('/^.{33} 1 /u', $lineas[2]), 'La cantidad empieza en su columna con el "1" de "12"...');

        /** ...y el "2" sigue abajo, en la misma columna: un "12" nunca se imprime como "1". */
        $this->assertSame(str_repeat(' ', 34).'2', $lineas[3]);
    }

    /**
     * @test
     */
    public function los_bytes_arrancan_con_esc_t_2_van_en_cp850_y_terminan_con_el_corte()
    {
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([], [
            $this->caja_de_diseno('a', 12, [
                $this->campo_de_caja('texto_libre', ['id' => 'nota', 'texto' => 'Pagó la seña']),
            ], '', 'ninguno'),
        ]));

        $bytes = (new TicketComanderaEscPos($venta, $perfil))->bytes();

        $this->assertStringStartsWith("\x1B\x74\x02", $bytes, 'ESC t 2: tabla CP850.');
        $this->assertStringEndsWith("\n\n\n\n\x1D\x56\x00\n", $bytes, 'El corte de siempre.');
        $this->assertStringNotContainsString("\x1B\x40", $bytes, 'Sin ESC @ (el de siempre tampoco lo manda).');
        $this->assertStringContainsString("Pag\xA2 la se\xA4a", $bytes, 'ó → 0xA2 y ñ → 0xA4 en CP850.');
        $this->assertSame(['Pagó la seña'], $this->lineas($venta, $perfil), 'El texto sigue en UTF-8.');
    }

    /**
     * @test
     */
    public function un_caracter_de_control_en_un_dato_no_llega_como_comando()
    {
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([], [
            $this->caja_de_diseno('a', 12, [
                $this->campo_de_caja('texto_libre', ['id' => 'nota', 'texto' => "Hola\x1D\x56\x00chau"]),
            ], '', 'ninguno'),
        ]));

        $bytes = (new TicketComanderaEscPos($venta, $perfil))->bytes();

        $this->assertSame(1, substr_count($bytes, "\x1D\x56\x00"), 'El único GS V 0 es el corte del final.');
        $this->assertSame(['Hola V chau'], $this->lineas($venta, $perfil));
    }

    /**
     * @test
     */
    public function el_logo_sale_antes_de_los_renglones_de_su_fila()
    {
        $this->dueno->image_url = 'https://logo.test/logo.png';
        $this->dueno->save();
        $venta = $this->crear_venta_completa();

        $perfil = $this->ticket_sin_tabla($this->diseno_de_pagina([
            $this->caja_de_diseno('logo', 6, [$this->campo_de_caja('negocio_logo')], '', 'ninguno'),
            $this->caja_de_diseno('numero', 6, [$this->campo_de_caja('venta_numero', ['etiqueta' => 'N'])], '', 'ninguno'),
        ], []));

        /** Sin salir a internet: el raster de prueba. */
        $motor = new class($venta, $perfil) extends TicketComanderaEscPos {
            protected function raster_del_logo($url)
            {
                return $url === 'https://logo.test/logo.png' ? "\x1D\x76\x30\x00RASTER" : null;
            }
        };

        $this->assertSame(['[LOGO]', str_repeat(' ', 24).'N: 1520'], $motor->lineas());
        $this->assertStringStartsWith("\x1B\x74\x02\x1D\x76\x30\x00RASTER", $motor->bytes());

        /** Sin logo cargado no sale nada. */
        $this->dueno->image_url = null;
        $this->dueno->save();
        $this->assertSame([str_repeat(' ', 24).'N: 1520'], $this->lineas($venta->fresh(), $perfil));
    }

    /**
     * @test
     */
    public function el_derivado_de_un_remito_imprime_lo_del_ticket_de_siempre()
    {
        $venta = $this->crear_venta_completa();
        $perfil = $this->perfil_de_ticket($this->dueno->id, false);
        $derivado = DisenoDerivadoPdf::para('sale', $perfil, false, $this->dueno, true);

        $lineas = $this->lineas($venta, $perfil, null, $derivado);

        $this->assertSame($this->dueno->company_name, $lineas[0], 'Sin logo: primero el nombre del negocio.');
        $this->assertContains('Venta Número: 1520', $lineas);
        $this->assertContains('Cliente: Juan Perez Test', $lineas);
        $this->assertContains('Dirección: Av San Martin 1234', $lineas);
        $this->assertContains('TOTAL A PAGAR: $2.362,50', $lineas);
        $this->assertNotContains('[QR]', $lineas, 'Un remito no lleva el QR.');
        $this->assertNingunaLineaPasaDe($lineas, 48);
    }

    /**
     * @test
     */
    public function la_factura_b_lleva_el_emisor_el_receptor_el_iva_contenido_el_cae_y_el_qr()
    {
        $venta = $this->crear_venta_completa();
        $factura = $this->crear_factura($venta, 'B');
        $perfil = $this->perfil_de_ticket($this->dueno->id, true);
        $derivado = DisenoDerivadoPdf::para('sale', $perfil, true, $this->dueno, true);

        $motor = new TicketComanderaEscPos($venta, $perfil, $factura, $derivado);
        $lineas = $motor->lineas();

        $this->assertTrue($motor->es_fiscal());

        foreach ([
            'Razon Social PDF SA',
            'Calle Falsa 123',
            'CUIT: 30111111118',
            'IIBB: 30111111118',
            'Inicio de actividades: 01/01/2020',
            'FACTURA B',
            'Código: 006',
            'Comprobante N°: 00001-00000027',
            'Cliente: Juan Perez Test',
            'CUIT: 20123456789',
            'Condición IVA: Responsable inscripto',
            'Domicilio: Av San Martin 1234',
            'Régimen de Transparencia Fiscal al Consumidor',
            '(Ley 27.743)',
            'IVA contenido: $410,02',
            'CAE: 76123456789012',
            'Vto. CAE: 11/10/2026',
        ] as $esperada) {
            $this->assertContains($esperada, $lineas, 'Falta "'.$esperada.'" en la factura.');
        }

        /** El emisor va arriba del nombre del negocio, el receptor después del cliente y el QR al final. */
        $this->assertLessThan(array_search($this->dueno->company_name, $lineas), array_search('FACTURA B', $lineas));
        $this->assertGreaterThan(array_search('Cliente: Juan Perez Test', $lineas), array_search('CUIT: 20123456789', $lineas));
        $this->assertSame('[QR]', end($lineas));
        $this->assertNingunaLineaPasaDe($lineas, 48);

        /** El QR con el GS ( k del Ticket 2.0 de siempre y el link de ARCA. */
        $link = BloquesFiscalesDeTicket::link_del_qr($factura);
        $this->assertStringContainsString("\x1D\x28\x6B\x03\x00\x31\x43\x05", $motor->bytes(), 'Punto 5.');
        $this->assertStringContainsString("\x31\x50\x30".$link, $motor->bytes());
    }

    /**
     * @test
     */
    public function la_factura_a_discrimina_el_iva_y_sin_factura_sale_como_remito()
    {
        $venta = $this->crear_venta_completa();
        $factura = $this->crear_factura($venta, 'A');
        $perfil = $this->perfil_de_ticket($this->dueno->id, true);
        $derivado = DisenoDerivadoPdf::para('sale', $perfil, true, $this->dueno, true);

        $lineas = $this->lineas($venta, $perfil, $factura, $derivado);

        $this->assertContains('FACTURA A', $lineas);
        $this->assertContains('Neto gravado: $1.952,48', $lineas);
        $this->assertContains('IVA 21%: $410,02', $lineas);
        $this->assertNotContains('IVA contenido: $410,02', $lineas);

        /** Un perfil de factura sin una factura con CAE sale sin los fijos (como el PDF). */
        $sin_factura = $this->lineas($venta, $perfil, null, $derivado);
        $this->assertNotContains('[QR]', $sin_factura);
        $this->assertNotContains('FACTURA A', $sin_factura);
    }
}
