<?php

namespace Tests\Feature\Vender;

use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda correctivos 24/8 — ítem 3: el panel "Nota de crédito" de Vender
 * (PUT api/sale/{id} con save_nota_credito) llamaba a CurrentAcountHelper::notaCredito()
 * con la firma VIEJA: el total entraba donde va el id de la cuenta corriente y todo lo
 * demás corrido un lugar. Además llamaba a checkSaldos('client', $client_id), también
 * firma vieja (la actual recibe el id de la credit_account), que reventaba adentro con
 * CreditAccount::find('client')->id sobre null.
 *
 * El camino de referencia con la firma correcta es DevolucionesController::store().
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada;
 * cada test arma su propio cliente/venta para no depender del fixture más que en el
 * usuario 500 y el artículo centinela.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Nota_de_credito_desde_vender_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario del fixture de testing (TestingFerreteriaSeeder).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Escenario: cliente con cuenta corriente en pesos y una venta a cuenta corriente
     * con su débito sin_pagar, como la deja el flujo real de vender.
     *
     * @param string $nombre_cliente
     * @return array
     */
    protected function escenario_base($nombre_cliente)
    {
        /** Cliente propio del test (prefijo zz para no chocar con el fixture). */
        $client = Client::create([
            'name'    => $nombre_cliente,
            'user_id' => 500,
        ]);

        /** Cuenta corriente del cliente en pesos: es LA cuenta que la NC tiene que pegar. */
        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => 500,
        ]);

        /** Venta a cuenta corriente ya guardada (el panel NC vive en la EDICIÓN de una venta). */
        $venta = Sale::create([
            'user_id'             => 500,
            'client_id'           => $client->id,
            'moneda_id'           => 1,
            'total'               => 500,
            'terminada'           => 1,
            'save_current_acount' => 1,
        ]);

        /** Débito de la venta en la cuenta, como lo crea CurrentAcountFromSaleHelper. */
        $debito = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta->id,
            'debe'              => 500,
            'saldo'             => 500,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta->id,
            'credit_account_id' => $credit_account->id,
            'user_id'           => 500,
            'created_at'        => Carbon::now()->subDay(),
        ]);

        return [
            'client'         => $client,
            'credit_account' => $credit_account,
            'venta'          => $venta,
            'debito'         => $debito,
        ];
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id} (molde de tests/Feature/Puntos y
     * LimiteCredito: to_check/checked/confirmed/discounts_in_services/surchages_in_services
     * son NOT NULL y el update los asigna a secas). Los items van SIEMPRE: el update hace
     * detach + attach de lo que venga.
     *
     * @param array $e         Escenario devuelto por escenario_base().
     * @param \App\Models\Article $articulo
     * @param array $overrides
     * @return array
     */
    protected function payload_update($e, $articulo, $overrides = [])
    {
        return array_merge([
            'client_id'                  => $e['client']->id,
            'save_current_acount'        => 1,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => 500,
            'total'                      => 500,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => 500,
                    'amount'       => 1,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * El camino crítico del panel NC de Vender: el update con save_nota_credito crea la
     * nota de crédito con el MONTO correcto (returned_amount × price_vender) en la CUENTA
     * corriente correcta (la del cliente en la moneda de la venta), atada a la venta.
     *
     * Con la firma vieja este request directamente reventaba (500): el monto entraba como
     * id de cuenta y los returned_items como sale_id.
     *
     * @group vender
     * @test
     */
    public function el_panel_nota_de_credito_de_vender_crea_la_nc_con_monto_y_cuenta_correctos()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        /** Artículo del fixture, para que los renglones y el stock del flujo real funcionen. */
        $articulo = Article::where('user_id', 500)->where('name', 'Martillo acero')->first();
        if (is_null($articulo)) {
            $this->markTestSkipped('La base de testing no tiene el artículo centinela sembrado.');
        }

        $e = $this->escenario_base('zz Cliente NC Vender 2408');

        /*
         * Devolución parcial desde el panel de vender: 3 unidades a $100 → NC de $300.
         */
        $payload = $this->payload_update($e, $articulo, [
            'save_nota_credito'        => 1,
            'nota_credito_description' => 'NC desde vender (test tanda 2408)',
            'returned_items'           => [
                [
                    'id'              => $articulo->id,
                    'is_article'      => true,
                    'price_vender'    => 100,
                    'returned_amount' => 3,
                    'discount'        => null,
                ],
            ],
        ]);

        $response = $this->putJson('api/sale/'.$e['venta']->id, $payload);

        $response->assertStatus(200);

        /** La NC que dejó el update, atada a la venta. */
        $nota_credito = CurrentAcount::where('status', 'nota_credito')
                                    ->where('sale_id', $e['venta']->id)
                                    ->latest('id')
                                    ->first();

        $this->assertNotNull($nota_credito, 'El update con save_nota_credito no creó la nota de crédito.');

        // El monto: 3 × $100. Con la firma vieja acá quedaba la descripción (string) como haber.
        $this->assertEquals(300, (float) $nota_credito->haber);

        // La cuenta: la credit_account del cliente en la moneda de la venta. Con la firma
        // vieja el primer argumento era el TOTAL, así que este campo quedaba con un id basura.
        $this->assertEquals($e['credit_account']->id, $nota_credito->credit_account_id);

        // El cliente y la venta, en sus campos.
        $this->assertEquals($e['client']->id, $nota_credito->client_id);
        $this->assertEquals($e['venta']->id, $nota_credito->sale_id);

        /*
         * El saldo de la cuenta cierra: débito de la venta (500) − NC (300) = 200.
         * No se asierta sobre qué débito puntual quedó imputado porque el update recrea el
         * movimiento de la venta (updateCurrentAcountsAndCommissions); el neto es el contrato.
         */
        $this->assertEquals(200, (float) $e['credit_account']->fresh()->saldo);
    }

    /**
     * El update SIN save_nota_credito no crea ninguna NC (el flag es el gatillo del panel).
     *
     * @group vender
     * @test
     */
    public function el_update_sin_save_nota_credito_no_crea_ninguna_nc()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $articulo = Article::where('user_id', 500)->where('name', 'Martillo acero')->first();
        if (is_null($articulo)) {
            $this->markTestSkipped('La base de testing no tiene el artículo centinela sembrado.');
        }

        $e = $this->escenario_base('zz Cliente NC Vender 2408 B');

        $response = $this->putJson('api/sale/'.$e['venta']->id, $this->payload_update($e, $articulo));

        $response->assertStatus(200);

        $this->assertEquals(
            0,
            CurrentAcount::where('status', 'nota_credito')->where('sale_id', $e['venta']->id)->count(),
            'Un update común (sin save_nota_credito) no tiene que generar notas de crédito.'
        );
    }
}
