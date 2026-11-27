<?php

namespace Tests\Feature\CartelPrecioEfectivo;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CA4 a CA8 — el PDF de ofertas con el formato "pagando en efectivo o transferencia"
 * (misión soporte-t173-m44, ticket 173 de La Martina).
 *
 * Los textos se buscan en el binario sin comprimir (FPDF los deja tal cual; los acentos salen en
 * ISO-8859-1, por eso no se buscan textos con tilde).
 *
 * @group cartel_precio_efectivo
 */
class Pdf_cartel_precio_efectivo_Test extends CartelPrecioEfectivoTestCase
{
    /** @test */
    public function test_caso_del_ticket_muestra_recuadro_y_oculta_sin_impuestos_y_anterior()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);
        $articulo_id = $this->crear_articulo($dueno);
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));

        $pdf = $this->pdf_plano($plantilla, array($articulo_id), $dueno);

        $this->assertStringContainsString('SUPER OFERTA', $pdf);
        $this->assertStringContainsString('zz Yerba Mate Playadito 1 kg', $pdf);
        $this->assertStringContainsString('$2.499', $pdf);
        $this->assertStringContainsString('PAGANDO EN EFECTIVO', $pdf);
        $this->assertStringContainsString('O TRANSFERENCIA', $pdf);
        $this->assertStringContainsString('$2.374', $pdf);
        $this->assertStringContainsString('OFERTA HASTA AGOTAR STOCK', $pdf);
        $this->assertStringContainsString(Carbon::today()->format('d/m/Y'), $pdf);
        $this->assertStringContainsString('7790086438158', $pdf);

        $this->assertStringNotContainsString('Precio sin impuestos', $pdf);
        $this->assertStringNotContainsString('Precio anterior', $pdf);
        $this->assertStringNotContainsString('$2.399', $pdf);
    }

    /** @test */
    public function test_flag_prendido_sin_descuento_no_imprime_recuadro()
    {
        $dueno = $this->crear_dueno();
        /* Sólo Débito: no es efectivo ni transferencia. */
        $this->crear_regla($dueno, $this->crear_metodo('Débito'), 15);
        $articulo_id = $this->crear_articulo($dueno);
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));

        $pdf = $this->pdf_plano($plantilla, array($articulo_id), $dueno);

        $this->assertStringContainsString('$2.499', $pdf);
        $this->assertStringNotContainsString('PAGANDO EN EFECTIVO', $pdf);
        $this->assertStringNotContainsString('Precio sin impuestos', $pdf);
        $this->assertStringNotContainsString('Precio anterior', $pdf);
    }

    /** @test */
    public function test_flag_prendido_sin_ninguna_regla_no_imprime_recuadro()
    {
        $dueno = $this->crear_dueno();
        $articulo_id = $this->crear_articulo($dueno);
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));

        $pdf = $this->pdf_plano($plantilla, array($articulo_id), $dueno);

        $this->assertStringContainsString('$2.499', $pdf);
        $this->assertStringNotContainsString('PAGANDO EN EFECTIVO', $pdf);
        $this->assertStringNotContainsString('Precio sin impuestos', $pdf);
        $this->assertStringNotContainsString('Precio anterior', $pdf);
    }

    /** @test */
    public function test_usa_el_precio_de_la_lista_elegida()
    {
        $dueno = $this->crear_dueno(true);
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);
        $articulo_id = $this->crear_articulo($dueno);
        $lista = $this->crear_lista($dueno);
        DB::table('article_price_type')->insert(array(
            'article_id'    => $articulo_id,
            'price_type_id' => $lista->id,
            'final_price'   => 3000,
        ));
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));

        request()->query->set('price_type_id', (string) $lista->id);
        $pdf = $this->pdf_plano($plantilla, array($articulo_id), $dueno);

        $this->assertStringContainsString('$3.000', $pdf);
        $this->assertStringContainsString('$2.850', $pdf);
        $this->assertStringNotContainsString('$2.499', $pdf);
    }

    /** @test */
    public function test_flag_apagado_deja_el_cartel_de_siempre()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);
        $articulo_id = $this->crear_articulo($dueno);
        $plantilla = $this->crear_plantilla($dueno);

        $pdf = $this->pdf_plano($plantilla, array($articulo_id), $dueno);

        $this->assertStringContainsString('Precio sin impuestos: $2.065,29', $pdf);
        $this->assertStringContainsString('Precio anterior: $2.399', $pdf);
        $this->assertStringNotContainsString('PAGANDO EN EFECTIVO', $pdf);
        $this->assertStringNotContainsString('$2.374', $pdf);
    }

    /** @test */
    public function test_dos_articulos_en_una_hoja()
    {
        $dueno = $this->crear_dueno();
        $this->crear_regla($dueno, $this->crear_metodo('Efectivo'), 5);
        $primero = $this->crear_articulo($dueno);
        $segundo = $this->crear_articulo($dueno, array('name' => 'zz Fideos 500 g', 'final_price' => 1000.00, 'bar_code' => '7790086438165'));
        $plantilla = $this->crear_plantilla($dueno, array('mostrar_precio_efectivo' => 1));

        $pdf = $this->pdf_plano($plantilla, array($primero, $segundo), $dueno);

        $this->assertSame(1, $this->paginas($pdf));
        $this->assertStringContainsString('$2.374', $pdf);
        $this->assertStringContainsString('$950', $pdf);
    }
}
