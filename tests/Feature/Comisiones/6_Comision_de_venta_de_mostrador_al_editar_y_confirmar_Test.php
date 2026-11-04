<?php

namespace Tests\Feature\Comisiones;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Models\Category;
use App\Models\Client;
use App\Models\Commission;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Archivo 6 — la comisión de una venta de MOSTRADOR (sin cliente) se recalcula al EDITARLA y al
 * CONFIRMARLA.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE FIJA (misión comision-mostrador-edicion-y-confirmacion, 4/11/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `SaleController::update()` y `SaleController::updatePrices()` llamaban a
 *  `SaleHelper::updateCurrentAcountsAndCommissions()` SOLO si la venta tenía cliente (`if
 *  ($model->client_id ...)`), que es la condición que protege la cuenta corriente. La comisión del
 *  vendedor no depende de la cuenta corriente —una venta de mostrador con vendedor genera
 *  comisión `active`, ver `comisiones\Helper::get_status()`—, pero quedó colgada de esa misma
 *  condición:
 *
 *   - una venta de mostrador de $1.000 con vendedor al 10 % que se editaba a $2.000 seguía con
 *     comisión $100 (debía ser $200);
 *   - una venta de mostrador que nace en depósito (`to_check`) no genera comisión (el alta solo la
 *     crea con la venta fuera de revisión) y al confirmarla tampoco la generaba nadie: el vendedor
 *     no cobraba nunca esa venta.
 *
 *  La baja y la restauración ya estaban arregladas en el archivo 5 de esta carpeta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Comision_de_venta_de_mostrador_al_editar_y_confirmar_Test extends EmpresaTestCase
{
    /** Delta para comparaciones de plata. */
    const DELTA = 0.01;

    /**
     * Vendedor del 10 % para el comercio del fixture. El usuario del fixture trae
     * `comision_funcion = 'distri_creo'` (el motor de otro cliente): se lo pisa con el motor que
     * pide cada caso (null = genérico). Lo revierte la transacción de `EmpresaTestCase`.
     *
     * @param  string|null  $motor
     * @return \App\Models\Seller
     */
    protected function vendedor($motor = null)
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        DB::table('users')->where('id', $user->id)->update(['comision_funcion' => $motor]);

        return Seller::create([
            'num'                       => 910002,
            'name'                      => 'Vendedor de la suite de edicion de ventas',
            'commission_after_pay_sale' => 0,
            'percentage_commission'     => 10,
            'commission_with_iva'       => 1,
            'user_id'                   => $user->id,
        ]);
    }

    /**
     * Venta de $1.000 (un martillo), con vendedor.
     *
     * @param  \App\Models\Seller  $seller
     * @param  \App\Models\Client|null  $client
     * @param  array  $campos  Pisa los campos de la venta (to_check, checked...).
     * @return \App\Models\Sale
     */
    protected function venta($seller, $client = null, $campos = [])
    {
        $articulo = $this->articulo('Martillo acero');

        $this->assertNotNull($articulo, 'Falta el artículo "Martillo acero" del fixture.');

        $sale = Sale::create(array_merge([
            'user_id'                    => $seller->user_id,
            'client_id'                  => is_null($client) ? null : $client->id,
            'seller_id'                  => $seller->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => 1000,
            'total'                      => 1000,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
            'discount_stock'             => 0,
        ], $campos));

        $sale->articles()->attach($articulo->id, ['amount' => 1, 'price' => 1000, 'cost' => 400]);

        return Sale::find($sale->id);
    }

    /** Venta de $1.000 con su comisión ya creada, como la deja el alta. */
    protected function venta_con_comision($seller, $client = null)
    {
        $sale = $this->venta($seller, $client);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: la venta tenía que dejar su comisión.');

        return Sale::find($sale->id);
    }

    /**
     * Payload de `PUT api/sale/{id}` con el renglón al precio indicado (la SPA manda el total ya
     * calculado).
     *
     * @param  \App\Models\Sale  $sale
     * @param  float  $precio
     * @param  array  $overrides
     * @return array
     */
    protected function payload($sale, $precio, $overrides = [])
    {
        $sale = Sale::find($sale->id);
        $fila = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        return array_merge([
            'client_id'                  => $sale->client_id,
            'save_current_acount'        => $sale->save_current_acount,
            'omitir_en_cuenta_corriente' => $sale->omitir_en_cuenta_corriente,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'seller_id'                  => $sale->seller_id ? $sale->seller_id : null,
            'employee_id'                => null,
            'sub_total'                  => $precio,
            'total'                      => $precio,
            'moneda_id'                  => $sale->moneda_id,
            'items'                      => [[
                'is_article'   => true,
                'id'           => (int) $fila->article_id,
                'price_vender' => $precio,
                'amount'       => (float) $fila->amount,
                'pivot'        => [
                    'price'  => $precio,
                    'cost'   => (float) $fila->cost,
                    'amount' => (float) $fila->amount,
                ],
            ]],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /** Suma de lo que el vendedor tiene a favor (comisiones activas). */
    protected function saldo_del_vendedor($seller)
    {
        return (float) SellerCommission::where('seller_id', $seller->id)->where('status', 'active')->sum('debe');
    }

    /**
     * Test 1 — EL DEFECTO: editar una venta de mostrador de $1.000 a $2.000 deja la comisión en
     * $200 (antes quedaba en $100).
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_de_mostrador_recalcula_su_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);

        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEqualsWithDelta(2000.00, (float) Sale::find($sale->id)->total, self::DELTA, 'Precondición: la venta quedó en 2.000.');
        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'una sola comisión por venta');
        $this->assertEqualsWithDelta(200.00, (float) SellerCommission::where('sale_id', $sale->id)->value('debe'), self::DELTA, '10 % de 2.000');
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
        $this->assertEqualsWithDelta(200.00, (float) SellerCommission::where('sale_id', $sale->id)->value('saldo'), self::DELTA, 'el saldo acumulado también se recalcula');
    }

    /**
     * Test 2 — CONTROL: con cliente la comisión ya se recalculaba. El arreglo no rompe ese camino.
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_con_cliente_sigue_recalculando_su_comision()
    {
        $seller = $this->vendedor();
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();

        $this->assertNotNull($client, 'Falta el cliente del fixture.');

        $sale = $this->venta_con_comision($seller, $client);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 3 — guardar SIN cambios no recrea la comisión: conserva su fila (misma foto económica).
     *
     * @group comisiones
     * @test
     */
    public function guardar_una_venta_de_mostrador_sin_cambios_conserva_la_misma_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);
        $id_antes = SellerCommission::where('sale_id', $sale->id)->value('id');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEquals($id_antes, SellerCommission::where('sale_id', $sale->id)->value('id'), 'sin cambios económicos no se toca la comisión');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 4 — sacarle el vendedor a una venta de mostrador saca su comisión.
     *
     * @group comisiones
     * @test
     */
    public function sacarle_el_vendedor_a_una_venta_de_mostrador_borra_su_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1000, ['seller_id' => null]))->assertStatus(200);

        $this->assertNull(Sale::find($sale->id)->seller_id, 'Precondición: la venta quedó sin vendedor.');
        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(0.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 5 — EL DEFECTO DE LA CONFIRMACIÓN: una venta de mostrador que nació en depósito
     * (`to_check`, sin comisión) y ya fue chequeada, al confirmarla genera su comisión.
     *
     * @group comisiones
     * @test
     */
    public function confirmar_una_venta_de_mostrador_de_deposito_genera_su_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta($seller, null, ['to_check' => 1, 'checked' => 1]);

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: en depósito no hay comisión.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1000, ['confirmed' => 1]))->assertStatus(200);

        $sale = Sale::find($sale->id);

        $this->assertEquals(0, (int) $sale->to_check, 'Precondición: la venta salió de depósito.');
        $this->assertEquals(0, (int) $sale->checked, 'Precondición: la venta quedó confirmada.');
        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'al confirmarla nace su comisión');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 6 — confirmar con el total cambiado (las unidades chequeadas difieren de las pedidas):
     * la comisión nace sobre el total confirmado.
     *
     * @group comisiones
     * @test
     */
    public function confirmar_una_venta_de_mostrador_con_otro_total_comisiona_el_total_confirmado()
    {
        $seller = $this->vendedor();
        $sale = $this->venta($seller, null, ['to_check' => 1, 'checked' => 1]);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1500, ['confirmed' => 1]))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(150.00, (float) SellerCommission::where('sale_id', $sale->id)->value('debe'), self::DELTA);
    }

    /**
     * Test 7 — una venta de mostrador que SIGUE en depósito al guardarse no genera comisión (todavía
     * no es una venta).
     *
     * @group comisiones
     * @test
     */
    public function guardar_una_venta_de_mostrador_que_sigue_en_deposito_no_genera_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta($seller, null, ['to_check' => 1, 'checked' => 0]);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000, ['to_check' => 1]))->assertStatus(200);

        $this->assertEquals(1, (int) Sale::find($sale->id)->to_check, 'Precondición: sigue en depósito.');
        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count());
    }

    /**
     * Test 8 — "Actualizar precios" (`PUT api/sale/update-prices/{id}`) sobre una venta de mostrador
     * con un motor que comisiona por renglón (Ros Mar): la comisión sigue a los precios nuevos.
     *
     * @group comisiones
     * @test
     */
    public function actualizar_precios_de_una_venta_de_mostrador_recalcula_su_comision_por_renglon()
    {
        $seller = $this->vendedor('ros_mar');
        $sale = $this->venta($seller, null);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: Ros Mar comisiona la venta.');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $fila = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->putJson('api/sale/update-prices/'.$sale->id, [
            'items' => [[
                'is_article'   => true,
                'id'           => (int) $fila->article_id,
                'price_vender' => 2000,
            ]],
        ])->assertStatus(200);

        $this->assertEqualsWithDelta(2000.00, (float) DB::table('article_sale')->where('sale_id', $sale->id)->value('price'), self::DELTA, 'Precondición: el renglón quedó a 2.000.');
        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 9 — editar con motor Ros Mar (comisión por renglón, leída de los pivotes): la comisión
     * nueva sale de los precios nuevos y no de los que la venta tenía antes de guardar.
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_de_mostrador_con_motor_por_renglon_usa_los_precios_nuevos()
    {
        $seller = $this->vendedor('ros_mar');
        $sale = $this->venta($seller, null);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA, 'la comisión sale de los precios nuevos');
    }

    /**
     * Test 10 — motor Distri Creo: editar una venta de mostrador la recalcula (10 % por defecto).
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_de_mostrador_con_motor_distri_creo_recalcula_su_comision()
    {
        $seller = $this->vendedor('distri_creo');
        $sale = $this->venta($seller, null);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: Distri Creo comisiona la venta.');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 11 — motor Golonorte (porcentaje por categoría del vendedor): confirmar una venta de
     * mostrador de depósito genera su comisión y editarla la recalcula.
     *
     * @group comisiones
     * @test
     */
    public function confirmar_y_editar_una_venta_de_mostrador_con_motor_golonorte()
    {
        $seller = $this->vendedor('golonorte');
        $articulo = $this->articulo('Martillo acero');

        $categoria = Category::create(['num' => 910004, 'name' => 'Categoría de la suite de comisiones', 'user_id' => $seller->user_id]);

        DB::table('articles')->where('id', $articulo->id)->update(['category_id' => $categoria->id]);

        $seller->categories()->attach($categoria->id, ['percentage' => 10]);

        $sale = $this->venta($seller, null, ['to_check' => 1, 'checked' => 1]);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1000, ['confirmed' => 1]))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'al confirmarla nace su comisión');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 12 — motor Truvari (porcentaje fijo por vendedor): editar una venta de mostrador la
     * recalcula.
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_de_mostrador_con_motor_truvari_recalcula_su_comision()
    {
        $seller = $this->vendedor('truvari');

        Commission::create(['num' => 910003, 'user_id' => $seller->user_id, 'seller_id' => $seller->id, 'percentage' => 10]);

        $sale = $this->venta($seller, null);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(200.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 13 — motor Fenix: comisiona sobre el movimiento de la cuenta corriente, y una venta de
     * mostrador no tiene. Editarla no tiene que romper ni inventar una comisión.
     *
     * @group comisiones
     * @test
     */
    public function editar_una_venta_de_mostrador_con_motor_fenix_no_crea_comision_ni_revienta()
    {
        $seller = $this->vendedor('fenix');
        $sale = $this->venta($seller, null);

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 2000))->assertStatus(200);

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count());
    }

    /**
     * Test 14 — a la venta de mostrador se le AGREGA un vendedor al editarla: nace su comisión.
     *
     * @group comisiones
     * @test
     */
    public function agregarle_un_vendedor_a_una_venta_de_mostrador_genera_su_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta($seller, null);

        DB::table('sales')->where('id', $sale->id)->update(['seller_id' => null]);

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: sin vendedor no hay comisión.');

        $this->putJson('api/sale/'.$sale->id, $this->payload($sale, 1000, ['seller_id' => $seller->id]))->assertStatus(200);

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }
}
