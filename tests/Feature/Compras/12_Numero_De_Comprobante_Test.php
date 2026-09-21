<?php

namespace Tests\Feature\Compras;

use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Grupo `comprobante-proveedor-compras` (21/9/2026) — el numero que el PROVEEDOR le puso a su
 * propio remito/factura de una compra (`provider_orders.numero_comprobante`, ya se podia guardar
 * desde el backend pero no habia forma de cargarlo desde la interfaz) tiene que aparecer, entre
 * parentesis, junto al numero de la compra en el `detalle` del movimiento de cuenta corriente del
 * proveedor (`ProviderOrder::detalle_current_acount()`, usado por
 * `NewProviderOrderHelper::crear_current_acount()/actualizar_current_acount()` y por
 * `ProviderOrderHelper::createCurrentAcount()`).
 *
 * Las tres compras de esta suite van con `update_prices = 0`, `update_stock = 0` y
 * `total_with_iva = 0` (mismo criterio que `Factura_De_Compra_Total_Y_Percepciones_Test`): lo que
 * se prueba es el TEXTO del movimiento de cuenta corriente, no el motor de costeo, asi que no hace
 * falta snapshot/restore de ningun articulo del fixture compartido y el total da un numero limpio
 * y predecible (cost x amount, sin IVA ni descuentos) que sirve de guard. Proveedor Rosario
 * (`PROVIDER_OTRO`, sin bonificaciones de catalogo) por el mismo motivo de simplicidad que usa
 * `7_Banderas_Apagadas_Test`, y `update_provider => 0` en el pivot del articulo para no pisarle el
 * proveedor al articulo del fixture compartido (ver `NewProviderOrderHelper::update_article_provider()`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group compras
 */
class Numero_De_Comprobante_Test extends ComprasTestCase
{
    /** Tolerancia de las comparaciones de plata (las columnas son decimal(x,2)). */
    const DELTA = 0.01;

    /**
     * Ids de las compras creadas por el test en curso, para borrarlas (con su movimiento de cuenta
     * corriente) en el `tearDown()`. Mismo criterio de limpieza que
     * `Factura_De_Compra_Total_Y_Percepciones_Test`: el fixture es compartido y `DatabaseTransactions`
     * no revierte nada de verdad en MyISAM (ver el docblock de `ComprasTestCase`).
     *
     * @var array<int,int>
     */
    protected $compras_creadas = [];

    /**
     * Borra las compras (y sus movimientos de cuenta corriente) que crearon los tests de esta clase.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        foreach ($this->compras_creadas as $compra_id) {

            // Defensivo: el test 4 cuelga un ProviderOrderAfipTicket de la compra a mano (sin pasar
            // por el modo de facturacion). No tiene FK con cascada, asi que si no se borra acá
            // explícitamente queda huérfano para siempre en el fixture compartido.
            ProviderOrderAfipTicket::where('provider_order_id', $compra_id)->delete();

            CurrentAcount::where('provider_order_id', $compra_id)->delete();

            ProviderOrder::where('id', $compra_id)->delete();
        }

        $this->compras_creadas = [];

        parent::tearDown();
    }

    /**
     * Arma y crea, por el endpoint real, una compra de Rosario con un solo articulo (100 x 5 = 500,
     * sin descuentos ni IVA), y la registra para la limpieza del `tearDown()`.
     *
     * @param  array<string,mixed> $overrides Overrides del payload (ver `ComprasTestCase::payload_compra`).
     * @return array{payload: array<string,mixed>, order: \App\Models\ProviderOrder}
     */
    protected function crear_compra_del_escenario($overrides = [])
    {
        $rosario = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $defaults = [
            'provider_id'      => $rosario->id,
            'update_prices'    => 0,
            'update_stock'     => 0,
            'total_with_iva'   => 0,
            // 'sin factura' y no el 'automatico' de payload_compra(): lo que se prueba acá es el
            // TEXTO del movimiento de cuenta corriente, no el circuito de facturación. En
            // 'automatico' el artículo del fixture (con iva_id) dispara ModoFacturacionHelper y
            // deja un ProviderOrderAfipTicket + su desglose de alícuotas colgando, que este
            // tearDown() no limpia (los tres no tienen FK con cascada). Con 'sin factura' no se
            // genera ningún ticket, así que no hay nada que limpiar de más.
            'modo_facturacion' => 'sin factura',
            'articles'         => [
                $this->item('Marco para cama', 100, 5, ['update_provider' => 0]),
            ],
        ];

        $payload = $this->payload_compra(array_merge($defaults, $overrides));

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $order_id = $response->json('model.id');

        $this->compras_creadas[] = $order_id;

        return [
            'payload' => $payload,
            'order'   => ProviderOrder::find($order_id),
        ];
    }

    /**
     * Test 1 — compra creada CON `numero_comprobante`: el detalle del movimiento de cuenta
     * corriente lo lleva entre parentesis, despues del numero de la compra.
     *
     * @group compras
     * @test
     */
    public function compra_con_numero_de_comprobante_lo_agrega_entre_parentesis_al_detalle()
    {
        $this->set_condicion_iva('RRII');

        $escenario = $this->crear_compra_del_escenario([
            'numero_comprobante' => 'FC-A-00012345',
        ]);

        $order = $escenario['order'];

        $current_acount = CurrentAcount::where('provider_order_id', $order->id)->first();

        $this->assertNotNull(
            $current_acount,
            'generate_current_acount viene en 1 por default: la compra tiene que dejar su movimiento de cuenta corriente'
        );

        $this->assertEqualsWithDelta(
            500,
            (float) $current_acount->debe,
            self::DELTA,
            'guard: la compra tiene que haberse procesado (100 x 5, sin descuentos ni IVA)'
        );

        $this->assertEquals(
            'Compra N°'.$order->num.' (FC-A-00012345)',
            $current_acount->detalle,
            'con numero_comprobante cargado, el detalle tiene que llevarlo entre parentesis junto al numero de la compra'
        );
    }

    /**
     * Test 2 — compra creada SIN `numero_comprobante` (no se manda: `payload_compra()` ya lo trae
     * en `null` por default): el detalle queda sin parentesis.
     *
     * @group compras
     * @test
     */
    public function compra_sin_numero_de_comprobante_deja_el_detalle_sin_parentesis()
    {
        $this->set_condicion_iva('RRII');

        $escenario = $this->crear_compra_del_escenario();

        $order = $escenario['order'];

        $this->assertNull(
            $order->numero_comprobante,
            'guard: payload_compra() ya trae numero_comprobante en null por default, no hace falta mandarlo'
        );

        $current_acount = CurrentAcount::where('provider_order_id', $order->id)->first();

        $this->assertNotNull($current_acount);

        $this->assertEqualsWithDelta(
            500,
            (float) $current_acount->debe,
            self::DELTA,
            'guard: la compra tiene que haberse procesado (100 x 5, sin descuentos ni IVA)'
        );

        $this->assertEquals(
            'Compra N°'.$order->num,
            $current_acount->detalle,
            'sin numero_comprobante, el detalle no tiene que llevar parentesis'
        );
    }

    /**
     * Test 3 — una compra creada sin comprobante y despues editada agregandoselo actualiza el
     * `detalle` del movimiento de cuenta corriente YA EXISTENTE (mismo id, no uno nuevo).
     *
     * @group compras
     * @test
     */
    public function editar_una_compra_para_agregarle_el_comprobante_actualiza_el_detalle_del_movimiento_existente()
    {
        $this->set_condicion_iva('RRII');

        $escenario = $this->crear_compra_del_escenario();

        $order   = $escenario['order'];
        $payload = $escenario['payload'];

        $current_acount_antes = CurrentAcount::where('provider_order_id', $order->id)->first();

        $this->assertNotNull($current_acount_antes);

        $this->assertEquals(
            'Compra N°'.$order->num,
            $current_acount_antes->detalle,
            'punto de partida: sin comprobante, el detalle no lleva parentesis'
        );

        // Mismo payload de la creacion (mismo moneda_id, asi que set_current_acount() actualiza el
        // movimiento existente en vez de borrarlo y crear uno nuevo por cambio de moneda -- ver
        // NewProviderOrderHelper::check_cambio_moneda()), solo con el comprobante agregado.
        $payload['numero_comprobante'] = 'REM-0099';

        $response = $this->putJson('api/provider-order/'.$order->id, $payload);

        $response->assertStatus(200);

        $current_acounts_despues = CurrentAcount::where('provider_order_id', $order->id)->get();

        $this->assertCount(
            1,
            $current_acounts_despues,
            'editar la compra no puede duplicar el movimiento de cuenta corriente'
        );

        $current_acount_despues = $current_acounts_despues->first();

        $this->assertEquals(
            $current_acount_antes->id,
            $current_acount_despues->id,
            'el movimiento actualizado tiene que ser EL MISMO registro (mismo id), no uno nuevo'
        );

        $this->assertEquals(
            'Compra N°'.$order->num.' (REM-0099)',
            $current_acount_despues->detalle,
            'al editar la compra agregandole el comprobante, el detalle del movimiento existente se actualiza'
        );
    }

    /**
     * Test 4 — el endpoint que arma el listado de la cuenta corriente manda `provider_order` con
     * sus facturas ARCA, para que el SPA pueda pintar el badge verde (`List.vue::facturas_arca_de()`).
     *
     * Nace de un hallazgo de la verificación de interfaz (21/9/2026): hay DOS controllers con
     * nombres casi idénticos — `CurrentAcountController` (que sí tiene un método `index()`, pero su
     * ruta está COMENTADA en `routes/api.php:603`) y `CreditAccountController` (cuya ruta SÍ está
     * activa, `routes/api.php:604`, con una firma de parámetros distinta:
     * `{credit_account_id}/{cantidad_movimientos}`, no `{model_name}/{model_id}/{months_ago}`). El
     * primer intento de este cambio agregó el eager-load de `provider_order.provider_order_afip_tickets`
     * al controller equivocado (código muerto): el dato quedaba bien en la base y el backend lo leía
     * bien por Tinker contra el modelo directo, pero el endpoint real que consume el SPA nunca lo
     * mandaba — silencioso, sin error, el badge simplemente no tenía con qué pintarse. Este test
     * pega al endpoint real (mismo que usa `common/current-acounts/Index.vue`) para que un cambio
     * futuro que rompa este eager-load (en cualquiera de los dos controllers) lo agarre PHPUnit y no
     * dependa de que alguien lo note mirando la pantalla.
     *
     * @group compras
     * @test
     */
    public function el_endpoint_de_la_cuenta_corriente_manda_las_facturas_arca_de_la_compra()
    {
        $this->set_condicion_iva('RRII');

        $escenario = $this->crear_compra_del_escenario();

        $order = $escenario['order'];

        // El ticket se cuelga directo por Eloquent, sin pasar por el modo de facturacion: lo único
        // que este test verifica es que el endpoint DE LISTADO lo entregue anidado, no cómo se creó.
        $ticket = ProviderOrderAfipTicket::create([
            'provider_order_id' => $order->id,
            'code'               => '0001-00004567',
            'issued_at'          => now(),
            'total'              => 1210,
            'total_iva'          => 210,
        ]);

        $credit_account = CreditAccount::where('model_name', 'provider')
                                        ->where('model_id', $order->provider_id)
                                        ->where('moneda_id', $order->moneda_id)
                                        ->first();

        $this->assertNotNull(
            $credit_account,
            'guard: la compra tiene que haber generado (o reusado) la credit_account del proveedor en esa moneda'
        );

        $response = $this->getJson('api/current-acount/'.$credit_account->id.'/50');

        $response->assertStatus(200);

        $movimiento = collect($response->json('models'))->firstWhere('provider_order_id', $order->id);

        $this->assertNotNull(
            $movimiento,
            'el movimiento de esta compra tiene que estar en la respuesta del endpoint de listado'
        );

        $this->assertArrayHasKey(
            'provider_order',
            $movimiento,
            'el endpoint tiene que mandar la compra anidada (eager-load), no solo el provider_order_id suelto'
        );

        $this->assertNotNull($movimiento['provider_order']);

        $codigos = collect($movimiento['provider_order']['provider_order_afip_tickets'])->pluck('code');

        $this->assertTrue(
            $codigos->contains('0001-00004567'),
            'el ticket ARCA cargado en la compra tiene que venir en provider_order.provider_order_afip_tickets'
        );
    }
}
