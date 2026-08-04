<?php

namespace Tests\Feature\Sales;

use App\Models\Caja;
use App\Models\Client;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del grupo 317 (prompt 01): el backend rechaza actualizar una venta que ya
 * no se puede editar. Regla definida por Lucas el 3/8/2026, a raiz del bug del grupo 314:
 * los unicos dos casos editables son (a) una venta a cuenta corriente de un cliente sin
 * facturar, y (b) una venta de mostrador en un comercio SIN cajas configuradas, con un
 * unico metodo de pago y sin facturar. Antes de este prompt, SaleController::update() y
 * updatePrices() no validaban absolutamente nada.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada
 * de antes y un refresh la vaciaria, rompiendo el resto de las suites. Cada test crea su
 * propia venta con user_id 500 en vez de depender de fixtures o de estado ambiguo.
 */
class No_se_puede_editar_venta_Test extends TestCase
{
    use DatabaseTransactions;

    public $client_name = 'Lucas Gonzalez';

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Crea una venta minima con user_id 500. Por defecto queda en el estado editable
     * (sin facturar, sin cerrar, sin caja): cada test sobreescribe lo que necesita para
     * su escenario puntual.
     *
     * @param array $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                       => 500,
            'client_id'                     => null,
            'omitir_en_cuenta_corriente'    => 0,
            'save_current_acount'           => 0,
            'terminada'                     => 1,
            'is_cerrada'                    => 0,
            'caja_id'                       => null,
            'sub_total'                     => 100,
            'total'                         => 100,
        ], $overrides));
    }

    /**
     * Payload minimo y valido para PUT api/sale/{id} y PUT api/sale/update-prices/{id}.
     * items/discounts/surchages vacios a proposito: a estos tests no les importa el
     * contenido de la venta, solo si el endpoint deja pasar o rechaza la actualizacion.
     *
     * @param \App\Models\Sale $sale
     * @param array $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $overrides = [])
    {
        return array_merge([
            'id'                                => $sale->id,
            'client_id'                         => $sale->client_id,
            'address_id'                        => null,
            'save_current_acount'               => $sale->save_current_acount,
            'omitir_en_cuenta_corriente'         => $sale->omitir_en_cuenta_corriente,
            'to_check'                           => 0,
            'checked'                            => 0,
            'confirmed'                          => 0,
            'current_acount_payment_method_id'   => null,
            'discounts_in_services'              => 1,
            'surchages_in_services'              => 1,
            'employee_id'                        => null,
            'sub_total'                          => $sale->sub_total,
            'total'                              => $sale->total,
            'terminada'                          => 1,
            'seller_id'                          => null,
            'cantidad_cuotas'                    => null,
            'cuota_descuento'                    => 0,
            'cuota_recargo'                      => 0,
            'caja_id'                            => null,
            'afip_tipo_comprobante_id'           => null,
            'descuento'                          => null,
            'items'                              => [],
            'discounts'                          => [],
            'surchages'                          => [],
        ], $overrides);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_a_cuenta_corriente_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $client = Client::where('name', $this->client_name)->first();
        if (is_null($client)) {
            $this->markTestSkipped('La base de testing no tiene el cliente "'.$this->client_name.'" sembrado.');
        }

        // Cajas configuradas A PROPOSITO: el caso (a) no tiene que verse afectado por esto,
        // porque una venta a cuenta corriente queda con cero metodos de pago adjuntos.
        Caja::create(['num' => 900101, 'user_id' => 500]);

        $sale = $this->crear_venta([
            'client_id'                     => $client->id,
            'omitir_en_cuenta_corriente'    => 0,
            'save_current_acount'           => 1,
        ]);

        // Precondicion del caso (a): sin metodos de pago adjuntos.
        $this->assertEquals(0, $sale->current_acount_payment_methods()->count());

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale));

        $response->assertStatus(200);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_sin_cajas_y_un_metodo_de_pago_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Precondicion del caso (b): el comercio no tiene NINGUNA caja. No se asume el
        // estado de la base sembrada: si trae cajas para este user_id, se saltea.
        if (Caja::where('user_id', 500)->count() >= 1) {
            $this->markTestSkipped('La base de testing ya tiene cajas sembradas para el usuario 500.');
        }

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'zz Efectivo Prompt317']);

        $sale = $this->crear_venta();

        $sale->current_acount_payment_methods()->attach($metodo->id, ['amount' => 100]);

        // Frontera exacta de la condicion 5: UN solo metodo, no mas.
        $this->assertEquals(1, $sale->current_acount_payment_methods()->count());

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale, [
            'current_acount_payment_method_id' => $metodo->id,
        ]));

        $response->assertStatus(200);

        $this->assertEquals(1, $sale->fresh()->current_acount_payment_methods()->count());
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_con_dos_metodos_de_pago_no_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Sin cajas a proposito: asi se verifica que la condicion 5 vale por si sola,
        // sin ayuda de la condicion 4.
        if (Caja::where('user_id', 500)->count() >= 1) {
            $this->markTestSkipped('La base de testing ya tiene cajas sembradas para el usuario 500.');
        }

        $metodo_1 = CurrentAcountPaymentMethod::create(['name' => 'zz Efectivo Prompt317 A']);
        $metodo_2 = CurrentAcountPaymentMethod::create(['name' => 'zz Credito Prompt317 A']);

        $sale = $this->crear_venta();

        $sale->current_acount_payment_methods()->attach($metodo_1->id, ['amount' => 50]);
        $sale->current_acount_payment_methods()->attach($metodo_2->id, ['amount' => 50]);

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale));

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'La venta se cobro con mas de un metodo de pago. Para modificarla hay que eliminarla y volver a cargarla.']);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_cobrada_con_cajas_configuradas_no_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // Precondicion de la condicion 4: hace falta al menos una caja del mismo user_id.
        Caja::create(['num' => 900102, 'user_id' => 500]);

        $metodo = CurrentAcountPaymentMethod::create(['name' => 'zz Efectivo Prompt317 B']);

        $sale = $this->crear_venta();

        // UN solo metodo adjunto: si esto solo, sin cajas, alcanzaria (test anterior); con
        // cajas configuradas, un solo metodo ya alcanza para bloquear (condicion 4).
        $sale->current_acount_payment_methods()->attach($metodo->id, ['amount' => 100]);

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale));

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'El comercio tiene cajas configuradas, asi que una venta ya cobrada no se puede modificar. Para corregirla hay que eliminarla y volver a cargarla.']);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_con_caja_id_no_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $caja = Caja::create(['num' => 900103, 'user_id' => 500]);

        $sale = $this->crear_venta(['caja_id' => $caja->id]);

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale));

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'La venta ya movio una caja y no se puede modificar.']);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_cerrada_no_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $sale = $this->crear_venta(['is_cerrada' => 1]);

        $response = $this->put('api/sale/'.$sale->id, $this->payload_actualizar($sale));

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'La venta esta cerrada y no se puede modificar.']);
    }

    /**
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function update_prices_tambien_rechaza_la_venta_con_dos_metodos_de_pago()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        if (Caja::where('user_id', 500)->count() >= 1) {
            $this->markTestSkipped('La base de testing ya tiene cajas sembradas para el usuario 500.');
        }

        $metodo_1 = CurrentAcountPaymentMethod::create(['name' => 'zz Efectivo Prompt317 C']);
        $metodo_2 = CurrentAcountPaymentMethod::create(['name' => 'zz Credito Prompt317 C']);

        $sale = $this->crear_venta();

        $sale->current_acount_payment_methods()->attach($metodo_1->id, ['amount' => 50]);
        $sale->current_acount_payment_methods()->attach($metodo_2->id, ['amount' => 50]);

        $response = $this->put('api/sale/update-prices/'.$sale->id, ['items' => []]);

        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'La venta se cobro con mas de un metodo de pago. Para modificarla hay que eliminarla y volver a cargarla.']);
    }
}
