<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Models\Article;
use App\Models\ProviderOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * CA11 (R1 del triple QA) — la compra espejo se PROCESA por el flujo real.
 *
 * Una venta de Fénix a Galván crea una ProviderOrder espejo (`SaleProviderOrderHelper`). El esquema
 * la crea con `update_prices` = 1 (default de la migración; producción midió NULL). Cuando Galván
 * la abre y la guarda (`PUT api/provider-order/{id}` → `NewProviderOrderHelper::update_cost()`), el
 * costo del renglón (el precio de Fénix, 4828) se escribe en `articles.cost` de la copia: en una
 * copia convertida (cost neto) eso la infla a 4828 y el final a 6426,07.
 *
 * Nada se emula por DB: venta real, orden real, PUT real del dueño de la copia.
 *
 * @group vinculacion-iva-copia
 */
class Compra_espejo_procesada_Test extends VinculacionDeInventarioTestCase
{
    /**
     * Venta real de Fénix a Galván por la fuente; devuelve la compra espejo que nace.
     *
     * @param  \App\Models\Article $fuente
     * @return \App\Models\ProviderOrder
     */
    private function vender_a_galvan($fuente)
    {
        $total = 4828;

        $this->postJson('api/sale', [
            'client_id'                  => $this->cliente_de_fenix->id,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 1,
            'to_check'                   => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
            'terminada'                  => 1,
            'cuota_descuento'            => 0,
            'cuota_recargo'              => 0,
            'discounts'                  => [],
            'surchages'                  => [],
            'items'                      => [[
                'is_article'   => true,
                'id'           => $fuente->id,
                'price_vender' => $total,
                'amount'       => 1,
                'costo_real'   => $total,
            ]],
        ])->assertStatus(201);

        return ProviderOrder::where('user_id', $this->galvan->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * Procesa la compra como Galván, igual que la pantalla: PUT con los renglones que ya tiene la
     * compra y la bandera `update_prices` que tiene guardada.
     *
     * @param  \App\Models\ProviderOrder $orden
     * @param  float|null $costo_renglon Costo del renglón distinto del de la compra (null = el que tiene).
     * @param  int|null   $provider_id   Proveedor del PUT distinto del de la compra (null = el que tiene).
     * @return void
     */
    private function procesar_como_galvan($orden, $costo_renglon = null, $provider_id = null)
    {
        $orden = ProviderOrder::find($orden->id);

        $renglones = [];

        foreach (DB::table('article_provider_order')->where('provider_order_id', $orden->id)->get() as $fila) {
            $articulo = Article::withTrashed()->find($fila->article_id);

            $renglones[] = [
                'id'            => $articulo->id,
                'bar_code'      => $articulo->bar_code,
                'provider_code' => $articulo->provider_code,
                'pivot'         => [
                    'cost'            => is_null($costo_renglon) ? $fila->cost : $costo_renglon,
                    'amount'          => $fila->amount,
                    'received'        => $fila->amount,
                    'price'           => null,
                    'discount'        => null,
                    'iva_id'          => $articulo->iva_id,
                    'cost_in_dollars' => 0,
                    'update_provider' => 0,
                ],
            ];
        }

                $this->cambiar_de_sesion($this->galvan);

        $r = $this->putJson('api/provider-order/' . $orden->id, [
            'provider_id'                            => is_null($provider_id) ? $orden->provider_id : $provider_id,
            'modo_facturacion'                       => 'manual',
            'update_prices'                          => $orden->update_prices,
            'update_stock'                           => 0,
            'total_with_iva'                         => 1,
            'precios_incluyen_iva'                   => 0,
            'moneda_id'                              => 2,
            'generate_current_acount'                => 0,
            'total_from_provider_order_afip_tickets' => 0,
            'provider_order_status_id'               => 1,
            'articles'                               => $renglones,
        ]);
        $r->assertStatus(200);

        $this->cambiar_de_sesion($this->fenix);
    }

    /**
     * Pasa la sesión del test a otro dueño: borra `owner`/`auth_user` de la sesión (UserHelper::user() los
     * prefiere a Auth::user()) y el usuario cacheado por los guards.
     *
     * @param  \App\Models\User $dueno
     * @return void
     */
    private function cambiar_de_sesion($dueno)
    {
        session()->forget(['auth_user', 'owner']);
        // `auth:sanctum` guarda el usuario de la primera request en el guard: sin esto la PUT de Galván
        // sigue entrando como Fénix (la venta ya corrió como él) y contesta 404 por tenencia.
        $this->app['auth']->forgetGuards();

        $this->actingAs(User::find($dueno->id), 'web');
    }

    /**
     * Copia convertida (marca 1) y compra espejo recién nacida, con el valor de `update_prices` dado.
     *
     * @param  mixed $update_prices Valor guardado en la compra (null = el dato de producción).
     * @return array [$copia, $orden]
     */
    private function espejo_con_copia_convertida($update_prices)
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);

        $orden = $this->vender_a_galvan($fuente);

        DB::table('provider_orders')->where('id', $orden->id)->update(['update_prices' => $update_prices]);

        $this->assertEquals(1, $this->valores($copia->id)['marca'], 'precondición: la copia es neta');
        $this->assertEquals(
            $copia->id,
            DB::table('article_provider_order')->where('provider_order_id', $orden->id)->value('article_id'),
            'precondición: el renglón de la compra espejo es la copia'
        );

        return [$copia, ProviderOrder::find($orden->id)];
    }

    /**
     * @param  int    $id
     * @param  string $donde
     * @return void
     */
    private function assert_copia_intacta($id, $donde)
    {
        $v = $this->valores($id);

        $this->assertEqualsWithDelta(self::NETO, $v['cost'], 0.0000005, $donde . ': cost');
        $this->assertEqualsWithDelta(self::BRUTO, $v['costo_real'], 0.0000005, $donde . ': costo_real');
        $this->assertEqualsWithDelta(self::FINAL, $v['final'], 0.0001, $donde . ': final');
        $this->assertEquals(1, $v['marca'], $donde . ': marca');
    }

    /**
     * CA11-R1a — update_prices = 1 (el default del esquema): procesar la compra espejo NO infla la copia.
     *
     * @test
     */
    public function ca11_r1a_procesar_la_compra_espejo_con_update_prices_1_no_infla_la_copia()
    {
        list($copia, $orden) = $this->espejo_con_copia_convertida(1);

        $cambios = DB::table('price_changes')->where('article_id', $copia->id)->count();

        $this->procesar_como_galvan($orden);

        $this->assert_copia_intacta($copia->id, 'update_prices=1');
        $this->assertEquals($cambios, DB::table('price_changes')->where('article_id', $copia->id)->count(), 'update_prices=1: delta 0 de price_changes');
    }

    /**
     * CA11-R1b — update_prices = 0: la compra no toca costos (la columna es NOT NULL en este esquema; el NULL de producción se trata como 0 en `if ($this->provider_order->update_prices)`).
     *
     * @test
     */
    public function ca11_r1b_procesar_la_compra_espejo_con_update_prices_0_no_toca_la_copia()
    {
        list($copia, $orden) = $this->espejo_con_copia_convertida(0);

        $cambios = DB::table('price_changes')->where('article_id', $copia->id)->count();

        $this->procesar_como_galvan($orden);

        $this->assert_copia_intacta($copia->id, 'update_prices=0');
        $this->assertEquals($cambios, DB::table('price_changes')->where('article_id', $copia->id)->count(), 'update_prices=0: delta 0 de price_changes');
    }

    /**
     * CA11-R1c — marca 0 (copia sin convertir): la compra espejo se procesa por el PUT real EXACTAMENTE
     * como hoy. El costo del renglón es DISTINTO del cost previo (4000 contra 4828), así que si la
     * guarda de update_cost() ignorara la marca y bloqueara también las copias sin convertir, `cost`
     * y `final` no se moverían y este test se pone rojo. (La versión anterior usaba un renglón igual al
     * cost previo: no escribía nada y no probaba la escritura.)
     *
     * @test
     */
    public function ca11_r1c_con_marca_0_la_compra_espejo_procesa_como_hoy()
    {
        $this->escenario([], false);
        $fuente = $this->crear_fuente();
        $copia = $this->copia_expuesta($fuente);
        $orden = $this->vender_a_galvan($fuente);

        $this->assertEquals(0, $this->valores($copia->id)['marca'], 'precondición: marca 0');
        $this->assertEquals(1, ProviderOrder::find($orden->id)->update_prices, 'precondición: default del esquema');

        $antes = $this->valores($copia->id);
        $this->assertEqualsWithDelta(self::BRUTO, $antes['cost'], 0.0000005, 'precondición: cost previo');

        $this->procesar_como_galvan($orden, 4000);

        $v = $this->valores($copia->id);
        $this->assertEquals(0, $v['marca'], 'la compra no pone la marca');
        $this->assertEqualsWithDelta(4000, $v['cost'], 0.0000005, 'el costo del renglón pasa a articles.cost, como hoy');
        $this->assertNotEquals($antes['cost'], $v['cost'], 'el cost cambió');
        $this->assertNotEquals($antes['final'], $v['final'], 'el final se recalculó con la cadena de siempre');
    }

    /**
     * CA11-R1d — copia con marca 1 comprada a un proveedor que NO es el vinculado (sin
     * `comercio_city_user_id`, u otro dueño): la compra escribe costo y final como hoy. Un mutante que
     * ignorara el proveedor y bloqueara toda copia con marca 1 deja la copia intacta y se detecta acá.
     *
     * @test
     * @dataProvider proveedores_no_vinculados
     */
    public function ca11_r1d_con_marca_1_y_otro_proveedor_la_compra_procesa_como_hoy($con_otro_dueno)
    {
        $this->escenario();
        $fuente = $this->crear_fuente();
        $copia = $this->crear_copia($fuente);
        $orden = $this->vender_a_galvan($fuente);

        $datos = ['name' => 'zz Otro proveedor ' . uniqid(), 'percentage_gain' => 10];

        if ($con_otro_dueno) {
            $datos['comercio_city_user_id'] = $this->crear_dueno()->id;
        }

        $otro = $this->crear_proveedor($this->galvan, $datos);

        $this->assertEquals(1, $this->valores($copia->id)['marca'], 'precondición: marca 1');

        $antes = $this->valores($copia->id);

        $this->procesar_como_galvan($orden, 4000, $otro->id);

        $v = $this->valores($copia->id);
        $this->assertEqualsWithDelta(4000, $v['cost'], 0.0000005, 'otro proveedor: el costo del renglón se escribe como hoy');
        $this->assertNotEquals($antes['cost'], $v['cost'], 'otro proveedor: el cost cambió');
        $this->assertNotEquals($antes['final'], $v['final'], 'otro proveedor: el final se recalculó');
    }

    /**
     * @return array
     */
    public function proveedores_no_vinculados()
    {
        return [
            'sin comercio_city_user_id' => [false],
            'otro dueño'                => [true],
        ];
    }
}
