<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Models\AfipError;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Doble de AfipNotaCreditoHelper que NO sale a la red: hace lo mismo que `init()` en cuanto a
 * escribir (crea el comprobante de la NC y lo completa) pero con un CAE de mentira, o deja el
 * rechazo anotado en `afip_errors` si el test pide que ARCA no autorice.
 */
class EmisorDeNotaDeCreditoSinRed extends AfipNotaCreditoHelper
{
    /** @var bool true = ARCA autoriza; false = rechaza y deja un AfipError. */
    public static $autoriza = true;

    /** @var int Cuántas veces se llamó a init() en el test (para medir el doble clic). */
    public static $emisiones = 0;

    function init()
    {
        self::$emisiones++;

        $this->create_afip_ticket();

        if (!self::$autoriza) {
            AfipError::create([
                'message'        => 'Rechazo de prueba',
                'code'           => '10015',
                'sale_id'        => $this->sale->id,
                'afip_ticket_id' => $this->created_afip_ticket->id,
            ]);
            return;
        }

        $this->update_afip_ticket([
            'cuit_negocio'       => '20000000000',
            'cbte_nro'           => 900 + self::$emisiones,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 3,
            'importe_total'      => (float) $this->nota_credito->haber,
            'moneda_id'          => 'PES',
            'resultado'          => 'A',
            'concepto'           => 1,
            'cuit_cliente'       => '20111111112',
            'cae'                => '7123456789'.str_pad((string) self::$emisiones, 4, '0', STR_PAD_LEFT),
            'cae_expired_at'     => '2030-01-01',
            'importe_iva'        => 0,
            'afip_fecha_emision' => '2026-10-07',
        ]);
    }
}

/**
 * Facturar ante ARCA una nota de crédito que se guardó SIN facturar (`POST nota-credito/{id}/facturar`).
 *
 * Por qué existe: CF (7/10/2026). La nota de crédito N° 128 de la venta 1488 se guardó sin pasar por
 * ARCA y no había forma de facturarla después; al rehacer la devolución, el tope de unidades la
 * rechazaba ("1 vendidas y 1 ya devueltas"). Ahora la nota se factura desde Comprobantes sobre la
 * factura de su venta, y el rechazo del tope explica ese camino.
 *
 * 🔴 No toca la red: el emisor real (`AfipNotaCreditoHelper::init()`) habla con el webservice de ARCA,
 * así que se lo reemplaza en el contenedor por `EmisorDeNotaDeCreditoSinRed`. Lo que se mide es lo
 * propio de esta funcionalidad: las reglas de cuándo se puede facturar, qué factura se elige, qué
 * queda en la base y que un doble clic no emita dos veces.
 *
 * @group devoluciones
 */
class Facturar_nota_de_credito_existente_Test extends EmpresaTestCase
{
    /**
     * Ids de las filas de `afip_tickets` y `afip_information` que siembra el test, para borrarlas en
     * el tearDown (AfipTicket usa SoftDeletes y el fixture no debe quedar con comprobantes ajenos).
     *
     * @var array
     */
    protected $sembrado = ['afip_information' => []];

    protected function setUp(): void
    {
        parent::setUp();

        EmisorDeNotaDeCreditoSinRed::$autoriza = true;
        EmisorDeNotaDeCreditoSinRed::$emisiones = 0;

        $this->app->bind(AfipNotaCreditoHelper::class, function ($app, $parametros) {
            return new EmisorDeNotaDeCreditoSinRed($parametros['afip_ticket'], $parametros['nota_credito']);
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

    /**
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Configuración fiscal propia del test (homologación), para que el constructor del emisor se
     * pueda armar. Se borra en el tearDown.
     *
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_de_prueba()
    {
        $iva = IvaCondition::where('name', 'Responsable inscripto')->first();

        if (is_null($iva)) {
            $this->fail('No existe la condición de IVA "Responsable inscripto" en la base de testing: es un problema del fixture, no algo para saltear.');
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
     * Una venta de $1000 con cliente, su factura A autorizada (N° 33, CAE) y una nota de crédito
     * por el total guardada sin ningún comprobante: el caso de CF.
     *
     * @param  float  $total_de_la_nota
     * @return array
     */
    protected function escenario_de_cf($total_de_la_nota = 1000)
    {
        $usuario = $this->usuario_de_testing();
        $info = $this->afip_information_de_prueba();

        $cliente = Client::create(['name' => 'Cliente NC existente', 'user_id' => $usuario->id]);

        $venta = Sale::create([
            'user_id'   => $usuario->id,
            'client_id' => $cliente->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);

        $factura = AfipTicket::create([
            'sale_id'             => $venta->id,
            'afip_information_id' => $info->id,
            'cbte_tipo'           => '1',
            'cbte_letra'          => 'A',
            'cbte_numero'         => '33',
            'punto_venta'         => 1,
            'resultado'           => 'A',
            'importe_total'       => 1000,
            'cuit_negocio'        => '20000000000',
            'cae'                 => '86395456418990',
        ]);

        $nota = CurrentAcount::create([
            'detalle'     => 'Nota Credito N°128',
            'description' => 'Devolución de test',
            'haber'       => $total_de_la_nota,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'client_id'   => $cliente->id,
            'user_id'     => $usuario->id,
            'moneda_id'   => 1,
        ]);

        return ['venta' => $venta, 'factura' => $factura, 'nota' => $nota, 'cliente' => $cliente, 'info' => $info];
    }

    /**
     * @group devoluciones
     * @test
     */
    public function factura_la_nota_guardada_sin_facturar_sobre_la_unica_factura_de_la_venta()
    {
        $e = $this->escenario_de_cf();

        $response = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);

        $response->assertStatus(200);
        $response->assertJson(['facturada' => true]);

        $ticket = AfipTicket::where('nota_credito_id', $e['nota']->id)->first();

        $this->assertNotNull($ticket, 'La nota tenía que quedar con su comprobante.');
        $this->assertNotEmpty($ticket->cae, 'El comprobante de la nota tiene que traer el CAE.');
        $this->assertEquals($e['factura']->id, (int) $ticket->sale_afip_ticket_id, 'La nota se emite sobre la factura de su venta.');
        $this->assertNull($ticket->sale_id, 'El comprobante de una NC nace sin sale_id (así no cuenta como factura de venta).');
        $this->assertEquals($e['venta']->id, (int) $ticket->sale_nota_credito_id);

        $this->assertEquals(
            (int) $ticket->id,
            (int) $response->json('model.afip_ticket.id'),
            'La respuesta trae la nota con su comprobante, para que la pantalla la actualice sin recargar.'
        );
    }

    /**
     * @group devoluciones
     * @test
     */
    public function un_doble_clic_no_emite_dos_notas()
    {
        $e = $this->escenario_de_cf();

        $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', [])->assertStatus(200);

        $segundo = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);

        $segundo->assertStatus(422);
        $this->assertStringContainsString('ya está facturada', $segundo->json('message'));
        $this->assertEquals(1, EmisorDeNotaDeCreditoSinRed::$emisiones, 'ARCA tiene que recibir UNA sola nota de crédito.');
        $this->assertEquals(1, AfipTicket::where('nota_credito_id', $e['nota']->id)->count());
    }

    /**
     * @group devoluciones
     * @test
     */
    public function una_nota_pendiente_de_confirmacion_en_arca_no_se_vuelve_a_emitir()
    {
        $e = $this->escenario_de_cf();

        AfipTicket::create([
            'nota_credito_id'     => $e['nota']->id,
            'afip_information_id' => $e['info']->id,
            'cbte_numero'         => '77',
            'cbte_tipo'           => '3',
        ]);

        $response = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);

        $response->assertStatus(422);
        $this->assertStringContainsString('Consultar', $response->json('message'));
        $this->assertEquals(0, EmisorDeNotaDeCreditoSinRed::$emisiones);
    }

    /**
     * Un rechazo de ARCA deja la nota sin CAE (pero con el error a la vista) y se puede reintentar:
     * el segundo intento reutiliza el mismo comprobante, no apila otro.
     *
     * @group devoluciones
     * @test
     */
    public function si_arca_rechaza_la_nota_queda_sin_cae_y_se_puede_reintentar_sobre_el_mismo_comprobante()
    {
        $e = $this->escenario_de_cf();

        EmisorDeNotaDeCreditoSinRed::$autoriza = false;

        $primero = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);

        $primero->assertStatus(200);
        $primero->assertJson(['facturada' => false]);
        $this->assertCount(1, $primero->json('model.afip_ticket.afip_errors'), 'El motivo del rechazo viaja en la respuesta.');

        $ticket_fallido = AfipTicket::where('nota_credito_id', $e['nota']->id)->first();

        EmisorDeNotaDeCreditoSinRed::$autoriza = true;

        $segundo = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);

        $segundo->assertStatus(200);
        $segundo->assertJson(['facturada' => true]);

        $this->assertEquals(1, AfipTicket::where('nota_credito_id', $e['nota']->id)->count(), 'No se apila un segundo comprobante para la misma nota.');
        $this->assertEquals($ticket_fallido->id, AfipTicket::where('nota_credito_id', $e['nota']->id)->first()->id);
        $this->assertNotEmpty(AfipTicket::find($ticket_fallido->id)->cae);
    }

    /**
     * @group devoluciones
     * @test
     */
    public function con_dos_facturas_hay_que_elegir_y_la_elegida_tiene_que_ser_de_la_venta()
    {
        $e = $this->escenario_de_cf();

        $segunda = AfipTicket::create([
            'sale_id'             => $e['venta']->id,
            'afip_information_id' => $e['info']->id,
            'cbte_tipo'           => '1',
            'cbte_numero'         => '34',
            'resultado'           => 'A',
            'importe_total'       => 1000,
            'cae'                 => '86395456418991',
        ]);

        $sin_elegir = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);
        $sin_elegir->assertStatus(422);
        $this->assertStringContainsString('elegí sobre cuál', $sin_elegir->json('message'));

        $ajena = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', ['afip_ticket_id' => 999999999]);
        $ajena->assertStatus(422);
        $this->assertStringContainsString('no corresponde a la venta', $ajena->json('message'));

        $elegida = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', ['afip_ticket_id' => $segunda->id]);
        $elegida->assertStatus(200);

        $this->assertEquals($segunda->id, (int) AfipTicket::where('nota_credito_id', $e['nota']->id)->first()->sale_afip_ticket_id);
        $this->assertEquals(0, EmisorDeNotaDeCreditoSinRed::$emisiones - 1, 'Los dos rechazos de arriba no llegaron a ARCA: solo emitió la elegida.');
    }

    /**
     * @group devoluciones
     * @test
     */
    public function no_se_factura_una_nota_que_supera_el_total_de_la_factura_ni_una_venta_sin_factura()
    {
        $e = $this->escenario_de_cf(1500);

        $excedida = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);
        $excedida->assertStatus(422);
        $this->assertStringContainsString('supera el total de la factura', $excedida->json('message'));

        $e['factura']->update(['cae' => null]);

        $sin_factura = $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', []);
        $sin_factura->assertStatus(422);
        $this->assertStringContainsString('no tiene una factura autorizada', $sin_factura->json('message'));

        $this->assertEquals(0, EmisorDeNotaDeCreditoSinRed::$emisiones);
    }

    /**
     * Lo ya acreditado por otras notas sobre la misma factura cuenta para el tope: dos notas de
     * $600 no entran en una factura de $1000.
     *
     * @group devoluciones
     * @test
     */
    public function lo_ya_acreditado_sobre_la_factura_cuenta_para_el_tope()
    {
        $e = $this->escenario_de_cf(600);

        $otra_nota = CurrentAcount::create([
            'detalle'   => 'Nota Credito N°129',
            'haber'     => 600,
            'status'    => 'nota_credito',
            'sale_id'   => $e['venta']->id,
            'client_id' => $e['cliente']->id,
            'user_id'   => $this->usuario_de_testing()->id,
            'moneda_id' => 1,
        ]);

        $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', [])->assertStatus(200);

        $segunda = $this->post('api/nota-credito/'.$otra_nota->id.'/facturar', []);

        $segunda->assertStatus(422);
        $this->assertStringContainsString('supera el total de la factura', $segunda->json('message'));
    }

    /**
     * @group devoluciones
     * @test
     */
    public function no_factura_notas_ajenas_ni_sin_venta_ni_inexistentes()
    {
        $e = $this->escenario_de_cf();

        $ajena = CurrentAcount::create([
            'detalle'   => 'Nota Credito ajena',
            'haber'     => 100,
            'status'    => 'nota_credito',
            'sale_id'   => $e['venta']->id,
            'client_id' => $e['cliente']->id,
            'user_id'   => 999999,
            'moneda_id' => 1,
        ]);

        $this->post('api/nota-credito/'.$ajena->id.'/facturar', [])->assertStatus(422);

        $sin_venta = CurrentAcount::create([
            'detalle'   => 'Nota Credito libre',
            'haber'     => 100,
            'status'    => 'nota_credito',
            'client_id' => $e['cliente']->id,
            'user_id'   => $this->usuario_de_testing()->id,
            'moneda_id' => 1,
        ]);

        $respuesta = $this->post('api/nota-credito/'.$sin_venta->id.'/facturar', []);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('no está atada a una venta', $respuesta->json('message'));

        $this->post('api/nota-credito/987654321/facturar', [])->assertStatus(422);

        $this->assertEquals(0, EmisorDeNotaDeCreditoSinRed::$emisiones);
    }

    /**
     * El listado trae las facturas de la venta de cada nota: la pantalla las ofrece al facturar.
     *
     * @group devoluciones
     * @test
     */
    public function el_listado_de_notas_trae_las_facturas_de_su_venta()
    {
        $e = $this->escenario_de_cf();

        $response = $this->get('api/nota-credito');

        $response->assertStatus(200);

        $nota = collect($response->json('models'))->firstWhere('id', $e['nota']->id);

        $this->assertNotNull($nota, 'La nota sembrada tiene que estar en el listado.');
        $this->assertEquals($e['factura']->id, $nota['sale']['afip_tickets'][0]['id']);
    }

    /**
     * El rechazo por "ya devueltas" de una venta facturada cuya nota quedó sin facturar explica la
     * salida; sin esa situación, el mensaje es el de siempre.
     *
     * @group devoluciones
     * @test
     */
    public function el_rechazo_por_ya_devueltas_explica_que_la_nota_quedo_sin_facturar()
    {
        $e = $this->escenario_de_cf();

        $item = \App\Models\Article::create([
            'name'    => 'Artículo NC existente',
            'user_id' => $this->usuario_de_testing()->id,
        ]);

        $e['venta']->articles()->attach($item->id, ['amount' => 1, 'price' => 1000]);

        $concepto = \App\Models\ConceptoStockMovement::where('name', 'Nota de credito')->first();

        if (is_null($concepto)) {
            $this->fail('No existe el concepto de stock "Nota de credito" en la base de testing.');
        }

        \App\Models\StockMovement::create([
            'article_id'                 => $item->id,
            'sale_id'                    => $e['venta']->id,
            'amount'                     => 1,
            'concepto_stock_movement_id' => $concepto->id,
            'nota_credito_id'            => $e['nota']->id,
        ]);

        $payload = [
            'sale_id'                   => $e['venta']->id,
            'client_id'                 => $e['cliente']->id,
            'generar_current_acount'    => true,
            'total_devolucion'          => 1000,
            'items'                     => [['is_article' => true, 'id' => $item->id, 'unidades_devueltas' => 1, 'name' => 'Artículo NC existente']],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => true,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => $e['factura']->id,
        ];

        $sin_facturar = $this->post('api/devoluciones/', $payload);

        $sin_facturar->assertStatus(422);
        $this->assertStringContainsString('1 ya devueltas', $sin_facturar->json('message'));
        $this->assertStringContainsString('Facturar con ARCA', $sin_facturar->json('message'));

        // Con la nota ya facturada, el aviso no corresponde: queda el mensaje de siempre.
        $this->post('api/nota-credito/'.$e['nota']->id.'/facturar', [])->assertStatus(200);

        $facturada = $this->post('api/devoluciones/', $payload);

        $facturada->assertStatus(422);
        $this->assertStringContainsString('1 ya devueltas', $facturada->json('message'));
        $this->assertStringNotContainsString('Facturar con ARCA', $facturada->json('message'));
    }
}
