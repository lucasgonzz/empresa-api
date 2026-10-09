<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\sale\SaleTicketComanderaHelper;
use App\Models\Sale;
use Tests\Concerns\DocumentosParaPdf;
use Tests\EmpresaTestCase;
use Tests\Feature\Pdf\Concerns\ComprobantesConDisenoDePagina;
use Tests\Feature\Pdf\Concerns\PerfilesDeTicketDeComandera;

/**
 * `GET api/sale/{sale_id}/ticket-comandera` (misión diseno-ticket-comandera, 9/10/2026, contrato
 * §3.6 del plan), contra el endpoint real: la resolución del perfil (pedido o por defecto, por
 * clase), `disenado:false` con un perfil sin diseño (el SPA imprime el Ticket 2.0 de siempre), la
 * vista en texto con el derivado, los bytes en base64, el 422 con un perfil que no es un ticket y
 * el 404 con una venta ajena.
 *
 * @group pdf-ticket-comandera
 */
class Endpoint_ticket_comandera_Test extends EmpresaTestCase
{
    use DocumentosParaPdf;
    use ComprobantesConDisenoDePagina;
    use PerfilesDeTicketDeComandera;

    /**
     * Las claves exactas de la respuesta 200 (contrato §3.6). Cambio de especificación del 9/10/2026
     * (pedido de la sesión madre): suma `fallo_el_diseno`, siempre presente, true solo si el motor
     * tiró una excepción.
     */
    const CLAVES = ['disenado', 'perfil_id', 'es_factura', 'ancho_mm', 'caracteres_por_renglon', 'payload_base64', 'lineas', 'fallo_el_diseno'];

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
     * GET al endpoint.
     *
     * @param \App\Models\Sale $venta
     * @param array            $query
     * @return \Illuminate\Testing\TestResponse
     */
    private function pedir($venta, array $query = [])
    {
        return $this->getJson('api/sale/'.$venta->id.'/ticket-comandera'.(count($query) ? '?'.http_build_query($query) : ''));
    }

    /**
     * Un diseño mínimo de ticket: el número de la venta.
     *
     * @return array
     */
    private function diseno_minimo()
    {
        return $this->diseno_de_pagina([
            $this->caja_de_diseno('numero', 12, [$this->campo_de_caja('venta_numero', ['etiqueta' => 'Venta'])], '', 'ninguno'),
        ], []);
    }

    /**
     * @test
     */
    public function sin_tickets_responde_sin_diseno_y_sin_perfil()
    {
        $venta = $this->crear_venta_completa();

        $json = $this->pedir($venta)->assertStatus(200)->json();

        $this->assertSame(self::CLAVES, array_keys($json));
        $this->assertFalse($json['disenado']);
        $this->assertNull($json['perfil_id']);
        $this->assertNull($json['es_factura'], 'Sin perfil, el SPA aplica la regla de siempre.');
        $this->assertNull($json['payload_base64']);
        $this->assertNull($json['lineas']);
        $this->assertFalse($json['fallo_el_diseno']);
    }

    /**
     * @test
     */
    public function un_perfil_sin_diseno_responde_disenado_false_y_con_texto_el_derivado()
    {
        $venta = $this->crear_venta_completa();
        $remito = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true]);

        $json = $this->pedir($venta)->assertStatus(200)->json();

        $this->assertSame(self::CLAVES, array_keys($json));
        $this->assertFalse($json['disenado']);
        $this->assertSame($remito->id, $json['perfil_id']);
        $this->assertFalse($json['es_factura']);
        $this->assertSame(80, $json['ancho_mm']);
        $this->assertSame(48, $json['caracteres_por_renglon']);
        $this->assertNull($json['payload_base64'], 'Sin diseño imprime el Ticket 2.0 de siempre: no hay bytes.');
        $this->assertNull($json['lineas']);

        /** La vista previa del diseñador: el derivado, con disenado en false. */
        $texto = $this->pedir($venta, ['formato' => 'texto'])->assertStatus(200)->json();
        $this->assertFalse($texto['disenado']);
        $this->assertNull($texto['payload_base64']);
        $this->assertContains('Venta Número: 1520', $texto['lineas']);
        $this->assertContains('TOTAL A PAGAR: $2.362,50', $texto['lineas']);
    }

    /**
     * @test
     */
    public function un_perfil_disenado_responde_los_bytes_y_con_texto_tambien_las_lineas()
    {
        $venta = $this->crear_venta_completa();
        $remito = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true, 'page_layout' => $this->diseno_minimo()], 55);

        $json = $this->pedir($venta, ['pdf_column_profile_id' => $remito->id])->assertStatus(200)->json();

        $this->assertTrue($json['disenado']);
        $this->assertSame(55, $json['ancho_mm']);
        $this->assertSame(33, $json['caracteres_por_renglon']);
        $this->assertNull($json['lineas']);

        $bytes = base64_decode($json['payload_base64'], true);
        $this->assertNotFalse($bytes);
        $this->assertStringStartsWith("\x1B\x74\x02", $bytes);
        $this->assertStringContainsString('Venta: 1520', $bytes);
        $this->assertStringEndsWith("\n\n\n\n\x1D\x56\x00\n", $bytes);

        $texto = $this->pedir($venta, ['pdf_column_profile_id' => $remito->id, 'formato' => 'texto'])->assertStatus(200)->json();
        $this->assertTrue($texto['disenado']);
        $this->assertSame($json['payload_base64'], $texto['payload_base64']);
        $this->assertSame('Venta: 1520', $texto['lineas'][0]);
    }

    /**
     * @test
     */
    public function sin_perfil_pedido_elige_el_ticket_por_defecto_de_la_clase()
    {
        $venta = $this->crear_venta_completa();

        $remito_viejo = $this->perfil_de_ticket($this->dueno->id, false);
        $remito_por_defecto = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true]);
        $factura_primera = $this->perfil_de_ticket($this->dueno->id, true);
        $this->perfil_de_ticket($this->dueno->id, true);

        /** Sin comprobante con CAE: remito, el "por defecto" aunque haya uno de menor id. */
        $this->assertSame($remito_por_defecto->id, $this->pedir($venta)->json('perfil_id'));
        $this->assertNotSame($remito_viejo->id, $remito_por_defecto->id);

        /** Con afip_ticket_id: factura; sin ninguno por defecto, el de menor id. */
        $json = $this->pedir($venta, ['afip_ticket_id' => 999999999])->json();
        $this->assertSame($factura_primera->id, $json['perfil_id']);
        $this->assertTrue($json['es_factura']);

        /** Con una factura con CAE en la venta, también factura sin pedirla. */
        $this->crear_factura($venta, 'B');
        $this->assertSame($factura_primera->id, $this->pedir($venta)->json('perfil_id'));
    }

    /**
     * @test
     */
    public function la_factura_disenada_lleva_el_qr_con_la_factura_pedida()
    {
        $venta = $this->crear_venta_completa();
        $factura = $this->crear_factura($venta, 'B');
        $this->perfil_de_ticket($this->dueno->id, true, ['is_default' => true, 'page_layout' => $this->diseno_minimo()]);

        $json = $this->pedir($venta, ['afip_ticket_id' => $factura->id, 'formato' => 'texto'])->assertStatus(200)->json();

        $this->assertTrue($json['disenado']);
        $this->assertTrue($json['es_factura']);
        $this->assertContains('FACTURA B', $json['lineas'], 'El fijo del emisor lo pone asegurar_fijos() aunque el diseño no lo traiga.');
        $this->assertContains('CAE: 76123456789012', $json['lineas']);
        $this->assertSame('[QR]', end($json['lineas']));
    }

    /**
     * @test
     */
    public function si_el_motor_falla_sale_el_de_siempre_avisando()
    {
        $venta = $this->crear_venta_completa();
        $remito = $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true, 'page_layout' => $this->diseno_minimo()]);

        /** Con el motor de verdad, el diseño sale y no hay falla. */
        $bien = $this->pedir($venta)->assertStatus(200)->json();
        $this->assertTrue($bien['disenado']);
        $this->assertFalse($bien['fallo_el_diseno']);

        /** Un motor que revienta (un dato raro): disenado:false, sin bytes, y la marca para avisar. */
        $helper_que_falla = get_class(new class extends SaleTicketComanderaHelper {
            protected static function motor($sale, $perfil, $factura, $diseno)
            {
                throw new \RuntimeException('Diseño roto de prueba');
            }
        });

        $resultado = $helper_que_falla::responder($venta, $this->dueno->id, $remito->id, null, null);

        $this->assertSame(200, $resultado['status']);
        $this->assertSame(self::CLAVES, array_keys($resultado['body']));
        $this->assertFalse($resultado['body']['disenado']);
        $this->assertNull($resultado['body']['payload_base64']);
        $this->assertTrue($resultado['body']['fallo_el_diseno']);
        $this->assertSame($remito->id, $resultado['body']['perfil_id']);
    }

    /**
     * @test
     */
    public function un_perfil_que_no_es_ticket_de_venta_del_dueno_da_422()
    {
        $venta = $this->crear_venta_completa();
        $hoja = $this->perfil_de_hoja($this->dueno->id, false);
        $otro = $this->crear_dueno('Otro dueno ticket');
        $ajeno = $this->perfil_de_ticket($otro->id, false);

        foreach ([$hoja->id, $ajeno->id, 999999999, 'abc'] as $perfil_id) {
            $this->pedir($venta, ['pdf_column_profile_id' => $perfil_id])
                ->assertStatus(422)
                ->assertJsonStructure(['message']);
        }
    }

    /**
     * @test
     */
    public function una_venta_de_otro_dueno_da_404()
    {
        $otro = $this->crear_dueno('Otro dueno ticket');
        $ajena = Sale::create(['num' => 1, 'user_id' => $otro->id, 'total' => 100]);

        $this->perfil_de_ticket($this->dueno->id, false, ['is_default' => true, 'page_layout' => $this->diseno_minimo()]);

        $this->pedir($ajena)->assertStatus(404);
        $this->getJson('api/sale/999999999/ticket-comandera')->assertStatus(404);
    }
}
