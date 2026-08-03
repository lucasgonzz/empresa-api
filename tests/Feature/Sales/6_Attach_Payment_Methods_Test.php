<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\User;
use Tests\TestCase;

/**
 * Feature tests de PaymentMethodHelper::attach_payment_methods() (grupo 321, prompt 03).
 *
 * Hasta el 3/8/2026, un método de pago sin `current_acount_payment_method_id` hacía `return` en
 * vez de `continue` dentro del foreach: abortaba el loop ENTERO, así que la venta quedaba
 * cobrada pero sin ningún método de pago adjunto y, río abajo, sin ningún movimiento de caja.
 */
class Attach_Payment_Methods_Test extends TestCase
{
    public $amount = 1;
    public $price = 100;

    public $address_id = 2;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que 1_Crear_venta_Test).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Cualquier articulo real del usuario 500: esta suite no depende del nombre de un articulo
     * de fixture especifico (distinto entre ramas), solo necesita UN articulo valido para armar
     * el item de la venta.
     *
     * @return \App\Models\Article|null
     */
    protected function articulo_de_testing()
    {
        return Article::where('user_id', 500)->first();
    }

    /**
     * Esta clase no usa DatabaseTransactions (mismo estilo que 1_Crear_venta_Test.php y
     * compañía: persisten en la base real del slot), así que la caja que crea
     * venta_con_metodo_y_caja_genera_movimiento_de_caja() hay que borrarla a mano, o cada
     * corrida deja una más en la base compartida (EscenariosDePlata sigue esta misma
     * convención de limpieza para lo que crea).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $cajas = Caja::where('name', 'zz Caja test attach payment methods')->get();
        foreach ($cajas as $caja) {
            MovimientoCaja::where('caja_id', $caja->id)->delete();
            AperturaCaja::where('caja_id', $caja->id)->delete();
            $caja->delete();
        }

        parent::tearDown();
    }

    function get_sale_mostrador($selected_payment_methods)
    {
        $total = $this->price * $this->amount;

        return [
            // Venta mostrador: sin cliente, es la condición que hace que
            // SaleHelper::attachSelectedPaymentMethods() efectivamente adjunte los métodos.
            'client_id'                         => null,
            'address_id'                        => $this->address_id,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'         => 1,
            'to_check'                           => 0,
            'current_acount_payment_method_id'  => null,
            'discounts_in_services'             => 1,
            'surchages_in_services'              => 1,
            'employee_id'                        => null,
            'sub_total'                           => $total,
            'total'                               => $total,
            'terminada'                           => 1,
            'seller_id'                           => null,
            'cantidad_cuotas'                     => null,
            'cuota_descuento'                     => 0,
            'cuota_recargo'                        => 0,
            'caja_id'                              => null,
            'afip_tipo_comprobante_id'             => null,
            'descuento'                            => null,
            'discounts'                            => [],
            'surchages'                            => [],
            'selected_payment_methods'             => $selected_payment_methods,
        ];
    }

    function add_items($sale, $article_model)
    {
        $sale['items'] = [
            [
                'is_article'    => true,
                'id'            => $article_model->id,
                'price_vender'  => $this->price,
                'amount'        => $this->amount,
            ],
        ];
        return $sale;
    }

    /**
     * @group sales
     * @test
     */
    public function un_metodo_sin_id_no_descarta_los_siguientes()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $article = $this->articulo_de_testing();
        if (is_null($article)) {
            $this->markTestSkipped('La base de testing no tiene articulos para el usuario 500.');
        }

        $data = $this->add_items($this->get_sale_mostrador([
            // Sin current_acount_payment_method_id: hasta el 3/8/2026 esto abortaba el loop
            // entero. Ahora tiene que saltearse solo.
            [
                'amount' => 30,
            ],
            [
                'current_acount_payment_method_id' => 3,
                'amount'                            => 70,
            ],
        ]), $article);

        $response = $this->post('api/sale', $data);
        $response->assertStatus(201);

        $sale = Sale::orderBy('id', 'DESC')->first();
        $this->assertEquals(1, $sale->current_acount_payment_methods()->count());
        $this->assertEquals(3, $sale->current_acount_payment_methods()->first()->id);
    }

    /**
     * @group sales
     * @test
     */
    public function venta_con_metodo_y_caja_genera_movimiento_de_caja()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        // No asumir el estado de la base: crear y abrir una caja propia para este test.
        $caja = Caja::create([
            'num'       => 900001,
            'name'      => 'zz Caja test attach payment methods',
            'user_id'   => $user->owner_id ? $user->owner_id : $user->id,
            'saldo'     => 0,
        ]);
        (new CajaAperturaHelper($caja->id))->abrir_caja();

        $article = $this->articulo_de_testing();
        if (is_null($article)) {
            $this->markTestSkipped('La base de testing no tiene articulos para el usuario 500.');
        }

        // Total distinto al de los otros tests de esta clase: SaleController::venta_ya_cread()
        // debounea (no crea, devuelve 200) una venta con el mismo user/client/employee/total en
        // los últimos 5 segundos -- exactamente lo que pasaría si los tres tests de esta clase
        // corrieran con el mismo total, uno atrás del otro, en la misma corrida.
        $this->price = 250;

        $data = $this->add_items($this->get_sale_mostrador([
            [
                'current_acount_payment_method_id' => 3,
                'amount'                             => 250,
                'caja_id'                            => $caja->id,
            ],
        ]), $article);

        $response = $this->post('api/sale', $data);
        $response->assertStatus(201);

        $sale = Sale::orderBy('id', 'DESC')->first();
        $movimiento = MovimientoCaja::where('sale_id', $sale->id)->where('caja_id', $caja->id)->first();

        $this->assertNotNull($movimiento, 'Deberia haberse creado un movimiento de caja');
        $this->assertEquals(250, (float) $movimiento->ingreso);
    }

    /**
     * @group sales
     * @test
     */
    public function metodo_de_pago_inexistente_no_rompe()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $article = $this->articulo_de_testing();
        if (is_null($article)) {
            $this->markTestSkipped('La base de testing no tiene articulos para el usuario 500.');
        }

        // Total distinto al de los otros tests de esta clase (ver comentario en
        // venta_con_metodo_y_caja_genera_movimiento_de_caja sobre venta_ya_cread()).
        $this->price = 375;

        $data = $this->add_items($this->get_sale_mostrador([
            [
                // Id que no existe en current_acount_payment_methods.
                'current_acount_payment_method_id' => 999999,
                'amount'                             => 375,
            ],
        ]), $article);

        $response = $this->post('api/sale', $data);
        $response->assertStatus(201);
        $this->assertNotEquals(500, $response->getStatusCode());

        $sale = Sale::orderBy('id', 'DESC')->first();
        $this->assertEquals(0, $sale->current_acount_payment_methods()->count());
    }
}
