<?php

namespace Tests\Feature\Comisiones;

use App\Http\Controllers\Helpers\comisiones\ComisionesHelper;
use App\Http\Controllers\Helpers\sale\RestoreSaleFromPapeleraHelper;
use App\Models\Client;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Archivo 5 — borrar una venta de MOSTRADOR (sin cliente) borra también la comisión del vendedor.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE FIJA (misión afip-combos-y-comision-mostrador, 4/11/2026)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `DeleteSaleHelper::ejecutar_baja()` llamaba a `SaleHelper::deleteSellerCommissionsFromSale()`
 *  ADENTRO del `if ($model->client_id)` que protege la cuenta corriente del cliente. Una venta sin
 *  cliente con vendedor SÍ genera comisión (nace `active`, ver `comisiones\Helper::get_status()`),
 *  pero al borrarla nadie la sacaba: la comisión seguía sumando en el saldo del vendedor,
 *  apuntando a una venta que ya no existe.
 *
 *  La restauración desde la papelera tenía la misma condición espejada
 *  (`restaurar_comisiones_si_corresponde()` salía si no había cliente), así que una venta de
 *  mostrador restaurada tampoco recuperaba su comisión. Las dos puntas se arreglan juntas: si solo
 *  se arreglara la baja, borrar y restaurar dejaría al vendedor sin comisión.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group comisiones
 */
class Baja_de_venta_de_mostrador_borra_su_comision_Test extends EmpresaTestCase
{
    /** Delta para comparaciones de plata. */
    const DELTA = 0.01;

    /**
     * Vendedor del 10 % para el comercio del fixture, y motor de comisión GENÉRICO: el usuario del
     * fixture trae `comision_funcion = 'distri_creo'`, que es el motor personalizado de otro cliente.
     * Se pisa por query directa; lo revierte la transacción de `EmpresaTestCase`.
     *
     * @return \App\Models\Seller
     */
    protected function vendedor()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        DB::table('users')->where('id', $user->id)->update(['comision_funcion' => null]);

        return Seller::create([
            'num'                       => 910001,
            'name'                      => 'Vendedor de la suite de baja de ventas',
            'commission_after_pay_sale' => 0,
            'percentage_commission'     => 10,
            'commission_with_iva'       => 1,
            'user_id'                   => $user->id,
        ]);
    }

    /**
     * Venta de 1.000 con vendedor y su comisión (100) ya creada, como la deja el alta.
     *
     * @param  \App\Models\Seller  $seller
     * @param  \App\Models\Client|null  $client
     * @return \App\Models\Sale
     */
    protected function venta_con_comision($seller, $client = null)
    {
        $articulo = $this->articulo('Martillo acero');

        $this->assertNotNull($articulo, 'Falta el artículo "Martillo acero" del fixture.');

        $sale = Sale::create([
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
        ]);

        $sale->articles()->attach($articulo->id, ['amount' => 1, 'price' => 1000]);

        (new ComisionesHelper(Sale::find($sale->id)))->crear_comision();

        $this->assertEquals(
            1,
            SellerCommission::where('sale_id', $sale->id)->count(),
            'Precondición: la venta tenía que dejar su comisión.'
        );

        return Sale::find($sale->id);
    }

    /**
     * Suma de lo que el vendedor tiene a favor: la comisión activa de la venta suma en su saldo.
     *
     * @param  \App\Models\Seller  $seller
     * @return float
     */
    protected function saldo_del_vendedor($seller)
    {
        return (float) SellerCommission::where('seller_id', $seller->id)->where('status', 'active')->sum('debe');
    }

    /**
     * Test 1 — EL DEFECTO: la venta de mostrador borrada por el endpoint real no deja comisión
     * activa colgada.
     *
     * @group comisiones
     * @test
     */
    public function borrar_una_venta_sin_cliente_borra_su_comision()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);

        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA, 'Precondición: 10 % de 1.000.');

        $response = $this->deleteJson('api/sale/'.$sale->id);

        $response->assertStatus(200);

        $this->assertNull(Sale::find($sale->id), 'Precondición: la venta tiene que estar borrada.');
        $this->assertEquals(
            0,
            SellerCommission::where('sale_id', $sale->id)->count(),
            'la comisión de una venta borrada no puede quedar activa'
        );
        $this->assertEqualsWithDelta(0.00, $this->saldo_del_vendedor($seller), self::DELTA, 'el vendedor no cobra comisión de una venta que no existe');
    }

    /**
     * Test 2 — CONTROL: con cliente la comisión ya se borraba. Fija que el arreglo no rompe ese
     * camino.
     *
     * @group comisiones
     * @test
     */
    public function borrar_una_venta_con_cliente_sigue_borrando_su_comision()
    {
        $seller = $this->vendedor();
        $client = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CONTADO)->first();

        $this->assertNotNull($client, 'Falta el cliente del fixture.');

        $sale = $this->venta_con_comision($seller, $client);

        $response = $this->deleteJson('api/sale/'.$sale->id);

        $response->assertStatus(200);

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count());
        $this->assertEqualsWithDelta(0.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 3 — la otra punta: restaurar de la papelera una venta de mostrador le devuelve la
     * comisión (una sola vez, aunque se restaure dos veces).
     *
     * @group comisiones
     * @test
     */
    public function restaurar_una_venta_de_mostrador_le_devuelve_su_comision_una_sola_vez()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);

        $this->deleteJson('api/sale/'.$sale->id)->assertStatus(200);

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count(), 'Precondición: la baja sacó la comisión.');

        $borrada = Sale::withTrashed()->find($sale->id);
        $borrada->restore();

        RestoreSaleFromPapeleraHelper::restaurar_comisiones_si_corresponde(Sale::find($sale->id));
        RestoreSaleFromPapeleraHelper::restaurar_comisiones_si_corresponde(Sale::find($sale->id));

        $this->assertEquals(1, SellerCommission::where('sale_id', $sale->id)->count(), 'la comisión vuelve, y una sola');
        $this->assertEqualsWithDelta(100.00, $this->saldo_del_vendedor($seller), self::DELTA);
    }

    /**
     * Test 4 — una venta sin vendedor no tiene comisión que borrar ni que restaurar: la baja sigue
     * andando igual.
     *
     * @group comisiones
     * @test
     */
    public function borrar_una_venta_de_mostrador_sin_vendedor_no_rompe_nada()
    {
        $seller = $this->vendedor();
        $sale = $this->venta_con_comision($seller, null);

        // Se la deja sin vendedor y sin comisión, como una venta común de mostrador.
        SellerCommission::where('sale_id', $sale->id)->delete();
        DB::table('sales')->where('id', $sale->id)->update(['seller_id' => null]);

        $this->deleteJson('api/sale/'.$sale->id)->assertStatus(200);

        $this->assertNull(Sale::find($sale->id));

        $borrada = Sale::withTrashed()->find($sale->id);
        $borrada->restore();
        RestoreSaleFromPapeleraHelper::restaurar_comisiones_si_corresponde(Sale::find($sale->id));

        $this->assertEquals(0, SellerCommission::where('sale_id', $sale->id)->count(), 'sin vendedor no nace comisión');
    }
}
