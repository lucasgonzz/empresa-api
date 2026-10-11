<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\Afip\AfipSolicitarCaeHelper;
use App\Http\Controllers\Helpers\Afip\CondicionIvaReceptorHelper;
use App\Http\Controllers\Helpers\AfipHelper;
use App\Http\Controllers\Helpers\Devoluciones\NotasExistentesDeLaVentaHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Article;
use App\Models\Client;
use App\Models\ConceptoStockMovement;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Doble de AfipNotaCreditoHelper que NO sale a la red: crea el comprobante de la nota y lo completa
 * con un CAE de mentira. Es propio de este archivo (no se comparte con el test 8) para que cada
 * archivo corra solo.
 */
class EmisorSinRedParaNotasSinCliente extends AfipNotaCreditoHelper
{
    function init()
    {
        $this->create_afip_ticket();

        $this->update_afip_ticket([
            'cuit_negocio'       => '20000000000',
            'cbte_nro'           => 501,
            'cbte_letra'         => 'B',
            'cbte_tipo'          => 8,
            'importe_total'      => (float) $this->nota_credito->haber,
            'moneda_id'          => 'PES',
            'resultado'          => 'A',
            'concepto'           => 1,
            'cuit_cliente'       => '0',
            'cae'                => '71234567890501',
            'cae_expired_at'     => '2030-01-01',
            'importe_iva'        => 0,
            'afip_fecha_emision' => '2026-10-08',
        ]);
    }
}

/**
 * Misión nc-aviso-existente-y-sin-cliente (8/10/2026):
 *
 * 1. Aviso de nota de crédito existente: `POST devoluciones` con `verificar_notas_existentes`
 *    responde 409 con las notas cuando la venta ya devolvió esas unidades (mirando los renglones de
 *    las notas, no el libro de stock: sirve aunque no se devuelva stock). Es opt-in: sin el campo,
 *    el camino es el de siempre.
 * 2. Una nota de crédito se puede hacer y facturar ante ARCA con o sin cliente en la venta.
 *
 * @group devoluciones
 */
class Aviso_de_nota_existente_y_notas_sin_cliente_Test extends EmpresaTestCase
{
    /** @var array Ids de AfipInformation sembrados, para borrarlos (con sus comprobantes) al terminar. */
    protected $sembrado = ['afip_information' => []];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(AfipNotaCreditoHelper::class, function ($app, $parametros) {
            return new EmisorSinRedParaNotasSinCliente($parametros['afip_ticket'], $parametros['nota_credito']);
        });
    }

    protected function tearDown(): void
    {
        if (count($this->sembrado['afip_information'])) {
            AfipTicket::withTrashed()->whereIn('afip_information_id', $this->sembrado['afip_information'])->forceDelete();
            AfipInformation::whereIn('id', $this->sembrado['afip_information'])->delete();
        }

        parent::tearDown();
    }

    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    protected function afip_information_de_prueba()
    {
        $iva = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva)) {
            $this->fail('No existe la condición de IVA "Responsable inscripto" en la base de testing: es un problema del fixture.');
        }

        $info = AfipInformation::create([
            'user_id'                => $this->usuario_de_testing()->id,
            'iva_condition_id'       => $iva->id,
            'razon_social'           => 'Comercio de test',
            'cuit'                   => '20000000000',
            'punto_venta'            => 1,
            'afip_ticket_production' => 0,
        ]);

        $this->sembrado['afip_information'][] = $info->id;

        return $info;
    }

    /**
     * Una venta de $1000 (con o sin cliente) con $unidades_vendidas de un artículo.
     *
     * @param  bool   $con_cliente
     * @param  float  $unidades_vendidas
     * @return array
     */
    protected function venta($con_cliente = true, $unidades_vendidas = 1)
    {
        $usuario = $this->usuario_de_testing();

        $cliente = $con_cliente ? Client::create(['name' => 'Cliente aviso NC', 'user_id' => $usuario->id]) : null;

        $articulo = Article::create(['name' => 'Artículo aviso NC', 'user_id' => $usuario->id]);

        $venta = Sale::create([
            'user_id'   => $usuario->id,
            'client_id' => $cliente ? $cliente->id : null,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);

        $venta->articles()->attach($articulo->id, ['amount' => $unidades_vendidas, 'price' => 1000 / $unidades_vendidas]);

        return ['venta' => $venta, 'cliente' => $cliente, 'articulo' => $articulo];
    }

    /** Una nota de crédito ya guardada de la venta, con $unidades del artículo. */
    protected function nota_existente($e, $unidades = 1, $haber = 1000)
    {
        $nota = CurrentAcount::create([
            'detalle'   => 'Nota Credito N°'.rand(1000, 9999),
            'haber'     => $haber,
            'status'    => 'nota_credito',
            'sale_id'   => $e['venta']->id,
            'client_id' => $e['cliente'] ? $e['cliente']->id : null,
            'user_id'   => $this->usuario_de_testing()->id,
            'moneda_id' => 1,
        ]);

        $nota->articles()->attach($e['articulo']->id, ['amount' => $unidades, 'price' => 1000, 'cost' => 0, 'discount' => 0]);

        return $nota;
    }

    /** La factura autorizada de la venta (la que habilita facturar la nota). */
    protected function factura_de($e, $importe = 1000)
    {
        return AfipTicket::create([
            'sale_id'             => $e['venta']->id,
            'afip_information_id' => $this->afip_information_de_prueba()->id,
            'cbte_tipo'           => '6',
            'cbte_letra'          => 'B',
            'cbte_numero'         => '33',
            'punto_venta'         => 1,
            'resultado'           => 'A',
            'importe_total'       => $importe,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '86395456418990',
        ]);
    }

    /** Cuerpo de `POST devoluciones` por $unidades del artículo, solo de plata (sin stock). */
    protected function payload($e, $unidades = 1, $extra = [])
    {
        return array_merge([
            'sale_id'                   => $e['venta']->id,
            'client_id'                 => $e['cliente'] ? $e['cliente']->id : null,
            'generar_current_acount'    => false,
            'total_devolucion'          => 1000 * $unidades,
            'observaciones'             => 'test',
            'items'                     => [[
                'is_article'         => true,
                'id'                 => $e['articulo']->id,
                'name'               => 'Artículo aviso NC',
                'unidades_devueltas' => $unidades,
                'price_vender'       => 1000,
                'costo_real'         => 0,
                'discount'           => 0,
            ]],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ], $extra);
    }

    /**
     * Sin `verificar_notas_existentes` el camino es el de siempre (una SPA vieja contra esta API no
     * cambia): la segunda devolución de solo plata se crea.
     *
     * @group devoluciones
     * @test
     */
    public function sin_el_campo_de_la_pantalla_nueva_no_hay_aviso()
    {
        $e = $this->venta();
        $this->nota_existente($e);

        $this->post('api/devoluciones/', $this->payload($e))->assertStatus(201);

        $this->assertEquals(2, CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->count());
    }

    /**
     * @group devoluciones
     * @test
     */
    public function con_el_campo_avisa_de_la_nota_existente_y_no_crea_nada()
    {
        $e = $this->venta();
        $factura = $this->factura_de($e);
        $existente = $this->nota_existente($e);

        $response = $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]));

        $response->assertStatus(409);
        $response->assertJson(['nota_existente' => true]);

        $nota = $response->json('notas.0');

        $this->assertEquals($existente->id, $nota['id']);
        $this->assertFalse($nota['facturada']);
        $this->assertFalse($nota['pendiente_en_arca']);
        $this->assertTrue($nota['puede_facturarse'], 'La venta está facturada y la nota está sin facturar: se ofrece facturarla.');
        $this->assertEquals($factura->id, $nota['facturas'][0]['id']);
        $this->assertEquals(1, $nota['unidades'][0]['unidades']);

        $this->assertEquals(1, CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->count(), 'El aviso no crea ninguna nota.');
    }

    /**
     * Una devolución parcial legítima (vendidas 10, devueltas 3, se piden 5) no choca.
     *
     * @group devoluciones
     * @test
     */
    public function una_devolucion_parcial_que_entra_no_avisa()
    {
        $e = $this->venta(true, 10);
        $this->nota_existente($e, 3, 300);

        $this->post('api/devoluciones/', $this->payload($e, 5, ['verificar_notas_existentes' => true]))->assertStatus(201);
    }

    /**
     * Pero si lo ya devuelto más lo pedido pasa lo vendido, avisa aunque la plata sea parcial.
     *
     * @group devoluciones
     * @test
     */
    public function avisa_cuando_lo_devuelto_mas_lo_pedido_pasa_lo_vendido()
    {
        $e = $this->venta(true, 10);
        $this->nota_existente($e, 8, 800);

        $this->post('api/devoluciones/', $this->payload($e, 5, ['verificar_notas_existentes' => true]))->assertStatus(409);
    }

    /**
     * "Crear otra de todos modos": con `confirmar_duplicada` se salta el aviso y la devolución de
     * solo plata se crea.
     *
     * @group devoluciones
     * @test
     */
    public function confirmar_la_duplicada_deja_crear_la_otra()
    {
        $e = $this->venta();
        $this->nota_existente($e);

        $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true, 'confirmar_duplicada' => true]))->assertStatus(201);

        $this->assertEquals(2, CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->count());
    }

    /**
     * El bloqueo de stock sigue en pie aunque se confirme la duplicada: esa mercadería ya volvió.
     *
     * @group devoluciones
     * @test
     */
    public function confirmar_la_duplicada_no_salta_el_bloqueo_de_stock()
    {
        $e = $this->venta();
        $existente = $this->nota_existente($e);

        $concepto = ConceptoStockMovement::where('name', 'Nota de credito')->first();

        if (is_null($concepto)) {
            $this->fail('No existe el concepto de stock "Nota de credito" en la base de testing.');
        }

        StockMovement::create([
            'article_id'                 => $e['articulo']->id,
            'sale_id'                    => $e['venta']->id,
            'amount'                     => 1,
            'concepto_stock_movement_id' => $concepto->id,
            'nota_credito_id'            => $existente->id,
        ]);

        $response = $this->post('api/devoluciones/', $this->payload($e, 1, [
            'verificar_notas_existentes' => true,
            'confirmar_duplicada'        => true,
            'regresar_stock'             => true,
        ]));

        $response->assertStatus(422);
        $response->assertJson(['devolucion_excedida' => true]);
    }

    /**
     * Una nota ya facturada se muestra como tal y no se ofrece facturarla de nuevo.
     *
     * @group devoluciones
     * @test
     */
    public function una_nota_ya_facturada_no_ofrece_facturarse_de_nuevo()
    {
        $e = $this->venta();
        $factura = $this->factura_de($e);
        $existente = $this->nota_existente($e);

        AfipTicket::create([
            'nota_credito_id'     => $existente->id,
            'sale_afip_ticket_id' => $factura->id,
            'afip_information_id' => $factura->afip_information_id,
            'cbte_tipo'           => '8',
            'cbte_numero'         => '9',
            'importe_total'       => 1000,
            'cae'                 => '71234567890123',
        ]);

        $nota = $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]))->json('notas.0');

        $this->assertTrue($nota['facturada']);
        $this->assertFalse($nota['puede_facturarse']);
        $this->assertEmpty($nota['facturas']);
    }

    /**
     * Sin factura autorizada en la venta (o con la factura ya agotada por otras notas) no se ofrece.
     *
     * @group devoluciones
     * @test
     */
    public function sin_factura_en_la_venta_no_ofrece_facturar()
    {
        $e = $this->venta();
        $this->nota_existente($e);

        $nota = $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]))->json('notas.0');

        $this->assertFalse($nota['puede_facturarse']);
        $this->assertEmpty($nota['facturas']);
    }

    /**
     * Una venta SIN cliente: la devolución se crea (con y sin aviso), sin cuenta corriente, y la
     * nota queda sin cliente.
     *
     * @group devoluciones
     * @test
     */
    public function se_crea_la_nota_de_credito_de_una_venta_sin_cliente()
    {
        $e = $this->venta(false);

        $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]))->assertStatus(201);

        $nota = CurrentAcount::where('sale_id', $e['venta']->id)->where('status', 'nota_credito')->first();

        $this->assertNotNull($nota, 'La nota de una venta sin cliente tiene que crearse.');
        $this->assertNull($nota->client_id);
        $this->assertEquals(1000, (float) $nota->haber);
        $this->assertEquals(1, $nota->articles()->count(), 'La nota guarda sus renglones aunque no tenga cliente.');
    }

    /**
     * El aviso también funciona en una venta sin cliente.
     *
     * @group devoluciones
     * @test
     */
    public function el_aviso_funciona_en_una_venta_sin_cliente()
    {
        $e = $this->venta(false);
        $this->factura_de($e);
        $this->nota_existente($e);

        $response = $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]));

        $response->assertStatus(409);
        $this->assertTrue($response->json('notas.0.puede_facturarse'));
    }

    /**
     * Una nota de una venta sin cliente se factura ante ARCA (consumidor final).
     *
     * @group devoluciones
     * @test
     */
    public function se_factura_la_nota_de_una_venta_sin_cliente()
    {
        $e = $this->venta(false);
        $factura = $this->factura_de($e);
        $nota = $this->nota_existente($e);

        $response = $this->post('api/nota-credito/'.$nota->id.'/facturar', []);

        $response->assertStatus(200);
        $response->assertJson(['facturada' => true]);

        $ticket = AfipTicket::where('nota_credito_id', $nota->id)->first();

        $this->assertNotNull($ticket);
        $this->assertEquals($factura->id, (int) $ticket->sale_afip_ticket_id);
    }

    /**
     * La nota de exportación necesita cliente (país de destino): sin él, 422 con el motivo.
     *
     * @group devoluciones
     * @test
     */
    public function la_nota_de_exportacion_sin_cliente_se_rechaza_con_el_motivo()
    {
        $e = $this->venta(false);
        $factura = $this->factura_de($e);
        $factura->update(['cbte_tipo' => '19']);
        $nota = $this->nota_existente($e);

        $response = $this->post('api/nota-credito/'.$nota->id.'/facturar', []);

        $response->assertStatus(422);
        $this->assertStringContainsString('exportación', $response->json('message'));
        $this->assertEquals(0, AfipTicket::where('nota_credito_id', $nota->id)->count());
    }

    /**
     * Una nota de PROVEEDOR no se factura por esta vía.
     *
     * @group devoluciones
     * @test
     */
    public function una_nota_de_proveedor_no_se_factura()
    {
        $e = $this->venta();
        $this->factura_de($e);

        $nota = CurrentAcount::create([
            'detalle'     => 'Nota Credito proveedor',
            'haber'       => 100,
            'status'      => 'nota_credito',
            'sale_id'     => $e['venta']->id,
            'provider_id' => 1,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
        ]);

        $this->post('api/nota-credito/'.$nota->id.'/facturar', [])->assertStatus(422);
    }

    /**
     * El aviso mira solo ventas del dueño: con el id de una venta ajena no devuelve sus notas.
     *
     * @group devoluciones
     * @test
     */
    public function el_aviso_no_muestra_las_notas_de_una_venta_ajena()
    {
        $e = $this->venta();
        $this->nota_existente($e);

        $items = $this->payload($e)['items'];

        $this->assertCount(1, NotasExistentesDeLaVentaHelper::buscar($e['venta']->id, $items), 'Control: con la venta propia sí avisa.');

        $otro_comercio = User::create([
            'name'     => 'Otro comercio NC',
            'email'    => 'nc-otro-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $e['venta']->update(['user_id' => $otro_comercio->id]);

        $this->assertSame([], NotasExistentesDeLaVentaHelper::buscar($e['venta']->id, $items));
    }

    /**
     * Con dos notas existentes se listan las dos, cada una con su estado: la ya facturada no se
     * ofrece de nuevo y la otra no se puede facturar porque la factura quedó agotada por la primera.
     *
     * @group devoluciones
     * @test
     */
    public function con_dos_notas_se_listan_las_dos_y_la_factura_agotada_no_se_ofrece()
    {
        $e = $this->venta();
        $factura = $this->factura_de($e);

        $facturada = $this->nota_existente($e);

        AfipTicket::create([
            'nota_credito_id'     => $facturada->id,
            'sale_afip_ticket_id' => $factura->id,
            'afip_information_id' => $factura->afip_information_id,
            'cbte_tipo'           => '8',
            'cbte_numero'         => '9',
            'importe_total'       => 1000,
            'cae'                 => '71234567890123',
        ]);

        $sin_facturar = $this->nota_existente($e);

        $notas = $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]))->json('notas');

        $this->assertCount(2, $notas);

        $por_id = [];
        foreach ($notas as $nota) {
            $por_id[$nota['id']] = $nota;
        }

        $this->assertTrue($por_id[$facturada->id]['facturada']);
        $this->assertFalse($por_id[$sin_facturar->id]['facturada']);
        $this->assertFalse($por_id[$sin_facturar->id]['puede_facturarse'], 'La factura ya está agotada por la primera nota: no cabe otra.');
        $this->assertEmpty($por_id[$sin_facturar->id]['facturas']);
    }

    /**
     * "Crear otra de todos modos" sobre una venta cuya nota ya está facturada: se permite (el
     * usuario lo eligió con el aviso a la vista).
     *
     * @group devoluciones
     * @test
     */
    public function confirmar_la_duplicada_tambien_deja_crear_si_la_existente_ya_esta_facturada()
    {
        $e = $this->venta();
        $factura = $this->factura_de($e);
        $existente = $this->nota_existente($e);

        AfipTicket::create([
            'nota_credito_id'     => $existente->id,
            'sale_afip_ticket_id' => $factura->id,
            'afip_information_id' => $factura->afip_information_id,
            'cbte_tipo'           => '8',
            'cbte_numero'         => '9',
            'importe_total'       => 1000,
            'cae'                 => '71234567890123',
        ]);

        $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true]))->assertStatus(409);

        $this->post('api/devoluciones/', $this->payload($e, 1, ['verificar_notas_existentes' => true, 'confirmar_duplicada' => true]))->assertStatus(201);
    }

    /**
     * Una factura de exportación con un cliente SIN país de destino tampoco se puede notar: 422 con
     * el motivo, no un 500 del emisor.
     *
     * @group devoluciones
     * @test
     */
    public function la_nota_de_exportacion_con_cliente_sin_pais_se_rechaza_con_el_motivo()
    {
        $e = $this->venta(true);
        $factura = $this->factura_de($e);
        $factura->update(['cbte_tipo' => '19']);
        $nota = $this->nota_existente($e);

        $response = $this->post('api/nota-credito/'.$nota->id.'/facturar', []);

        $response->assertStatus(422);
        $this->assertStringContainsString('país de destino', $response->json('message'));
    }

    /**
     * Lo que el emisor REAL arma antes de hablar con ARCA funciona con una venta sin cliente: el
     * comprobante de la nota, el documento del receptor (consumidor final), la condición de IVA del
     * receptor y los importes. Lo único que queda afuera es la llamada de red (`init()`), que no se
     * puede hacer en un test; el doble de los otros tests reemplaza `init()` entero y no ejercita
     * nada de esto.
     *
     * @group devoluciones
     * @test
     */
    public function las_piezas_reales_de_la_emision_funcionan_sin_cliente()
    {
        $e = $this->venta(false);
        $factura = $this->factura_de($e);
        $nota = $this->nota_existente($e);

        $emisor = new AfipNotaCreditoHelper($factura, $nota);
        $emisor->create_afip_ticket();

        $comprobante = AfipTicket::where('nota_credito_id', $nota->id)->first();

        $this->assertNotNull($comprobante, 'El comprobante de la nota se crea aunque la venta no tenga cliente.');
        $this->assertSame('', $comprobante->iva_cliente);

        $documento = AfipSolicitarCaeHelper::get_doc_client($e['venta']);
        $this->assertSame('NR', $documento['doc_client']);
        $this->assertEquals(99, $documento['doc_type']);

        $this->assertEquals(5, CondicionIvaReceptorHelper::get_iva_receptor($e['venta'], 8), 'Sin cliente el receptor es consumidor final (5).');

        $calculador = new AfipHelper($factura, $nota->articles, $nota->services, null, null, $nota->nota_credito_descriptions, $nota);
        $importes = $calculador->getImportes();

        $this->assertGreaterThan(0, (float) $importes['total'], 'Los importes de la nota se calculan sin cliente.');
    }
}
