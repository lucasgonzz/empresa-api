<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\Article;
use App\Models\Provider;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

class Cotizacion_por_articulo_Test extends EmpresaTestCase
{
    use EscenarioDeMonedas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparar_escenario_2r('2026-11-02 12:00:00');
    }

    protected function tearDown(): void
    {
        \App\Http\Controllers\Helpers\sale\CotizacionPorArticuloHelper::limpiar_cache();
        $this->limpiar_escenario_2r();
        parent::tearDown();
    }

    private function foto($cotizacion, $moneda_costo = 1)
    {
        return ['cotizacion_aplicada' => $cotizacion, 'cotizacion_origen' => 'proveedor', 'moneda_base_precio' => 1, 'moneda_base_costo' => $moneda_costo];
    }

    public function test_guardado_real_con_costos_pesos_y_dolares_cotizaciones_distintas()
    {
        $pesos = $this->crear_articulo('Cotización pesos', ['cost' => 1000, 'cost_in_dollars' => 0]);
        $usd = $this->crear_articulo('Cotización dólares', ['cost' => 10, 'cost_in_dollars' => 1]);
        $venta = $this->guardar_venta($this->payload_venta(2, 1200, [
            $this->item($pesos, 2, 1.5, $this->foto(1000)),
            $this->item($usd, 1, 15, $this->foto(1500, 2)),
        ]));
        $a = $this->pivot_de($venta, $pesos);
        $b = $this->pivot_de($venta, $usd);
        $this->assertEquals(1, $a->cost);
        $this->assertEquals(10, $b->cost);
        $this->assertEquals(1000, $a->cotizacion_aplicada);
        $this->assertEquals(1500, $b->cotizacion_aplicada);
        $this->assertEquals(1, $a->moneda_base_costo);
        $this->assertEquals(2, $b->moneda_base_costo);
        $this->assertEquals(1, $a->ganancia);
        $this->assertEquals(5, $b->ganancia);
        $this->assertEquals(2, $venta->moneda_id);
    }

    public function test_rechaza_cotizacion_invalida_sin_dejar_venta_parcial()
    {
        $articulo = $this->crear_articulo('Cotización inválida', ['cost' => 1000, 'cost_in_dollars' => 0]);
        foreach ([0, -1, 0.0000001, 0.0000014, 'texto', null] as $cotizacion) {
            $antes = DB::table('sales')->count();
            $this->avanzar_reloj();
            $response = $this->postJson('api/sale', $this->payload_venta(2, 1200, [$this->item($articulo, 1, 1.5, $this->foto($cotizacion))]));
            $response->assertStatus(422);
            $this->assertEquals($antes, DB::table('sales')->count());
        }
    }

    public function test_endpoint_anuncia_capacidad_y_no_devuelve_articulos_ajenos()
    {
        $articulo = $this->crear_articulo('Cotización proveedor', ['cost' => 1000]);
        $provider = Provider::where('user_id', $this->dueno->id)->first();
        $provider->dolar = 1800;
        $provider->save();
        $articulo->provider_id = $provider->id;
        $articulo->save();
        $ajeno = Article::where('user_id', '!=', $this->dueno->id)->first();
        $ids = [$articulo->id];
        if ($ajeno) $ids[] = $ajeno->id;
        $response = $this->getJson('api/vender/cotizaciones-articulos?'.http_build_query(['ids' => $ids]));
        $response->assertOk()->assertJsonPath('supported', true)->assertJsonPath('articulos.'.$articulo->id.'.proveedor', 1800);
        if ($ajeno) $this->assertArrayNotHasKey($ajeno->id, $response->json('articulos'));
    }
    public function test_reabrir_y_guardar_dos_veces_no_recotiza_ni_reinterpreta_ficha_actual()
    {
        $articulo = $this->crear_articulo('Foto monetaria histórica', ['cost' => 10, 'cost_in_dollars' => 1], 20);
        $venta = $this->guardar_venta($this->payload_venta(2, 1200, [$this->item($articulo, 2, 15, $this->foto(1000, 2))], ['terminada' => 0]));
        $antes = $this->pivot_de($venta, $articulo);
        $movimientos_stock = DB::table('stock_movements')->where('sale_id', $venta->id)->orderBy('id')->get()->toJson();
        $this->assertNotSame('[]', $movimientos_stock);
        $ganancia = $venta->ganancia;
        $costo = $venta->total_cost;
        $articulo->cost_in_dollars = 0;
        $articulo->cost = 99999;
        $articulo->aplicar_iva = 0;
        $articulo->save();
        for ($i = 0; $i < 2; $i++) {
            $item = $this->item_de_edicion($articulo, $venta, 2);
            $payload = $this->payload_edicion_venta($venta, [$item], [
                'iva_aplicado' => $venta->iva_aplicado,
                'iva_en_articulos_sin_iva' => $venta->iva_en_articulos_sin_iva,
                'valor_dolar' => 1900,
            ]);
            $res = $this->putJson('api/sale/'.$venta->id, $payload);
            $this->assertEquals(200, $res->status(), $res->getContent());
            $despues = $this->pivot_de($venta, $articulo);
            foreach (['price', 'cost', 'ganancia', 'iva_percentage', 'price_sin_iva', 'cotizacion_aplicada', 'cotizacion_origen', 'moneda_base_costo'] as $campo) {
                $this->assertSame($antes->$campo, $despues->$campo, $campo);
            }
            $this->assertSame($movimientos_stock, DB::table('stock_movements')->where('sale_id', $venta->id)->orderBy('id')->get()->toJson());
            $this->assertSame($ganancia, $venta->fresh()->ganancia);
            $this->assertSame($costo, $venta->fresh()->total_cost);
        }
    }

    public function test_editar_cotizacion_costo_pesos_sin_cambiar_precio_manual_usd_respeta_costo_nuevo()
    {
        $articulo = $this->crear_articulo('Manual USD costo pesos', ['cost' => 1000, 'cost_in_dollars' => 0]);
        $datos = $this->foto(1000);
        $datos['moneda_base_precio'] = 2;
        $venta = $this->guardar_venta($this->payload_venta(2, 1200, [$this->item($articulo, 1, 20, $datos)], ['terminada' => 0]));
        $item = $this->item_de_edicion($articulo, $venta, 1);
        $item = array_merge($item, $datos, ['cotizacion_aplicada' => 1250, 'cotizacion_origen' => 'manual']);
        $item['pivot']['cost'] = 0.8;
        $res = $this->putJson('api/sale/'.$venta->id, $this->payload_edicion_venta($venta, [$item]));
        $this->assertEquals(200, $res->status(), $res->getContent());
        $pivot = $this->pivot_de($venta, $articulo);
        $this->assertEquals(20, $pivot->price);
        $this->assertEquals(0.8, $pivot->cost);
        $this->assertEquals(19.2, $pivot->ganancia);
        $this->assertEquals(1250, $pivot->cotizacion_aplicada);
    }

    public function test_presupuesto_duplicado_y_confirmado_conserva_fotos_por_renglon()
    {
        $articulo = $this->crear_articulo('Presupuesto foto monetaria', ['cost' => 10, 'cost_in_dollars' => 1]);
        $cliente = \App\Models\Client::create(['num' => 991234, 'name' => 'Cliente cotización presupuesto', 'user_id' => $this->dueno->id]);
        \App\Http\Controllers\Helpers\CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $this->dueno->id);
        $fila = array_merge($articulo->getAttributes(), $this->foto(1000, 2), ['amount' => 2, 'price' => 15, 'bonus' => null, 'location' => null]);
        $payload = [
            'client_id' => $cliente->id, 'start_at' => null, 'finish_at' => null, 'total' => 30,
            'budget_status_id' => 1, 'moneda_id' => 2, 'valor_dolar' => 1200, 'discount_stock' => 0,
            'iva_aplicado' => 1, 'surchages_in_services' => 1, 'discounts_in_services' => 1,
            'aplicar_recargos_directo_a_items' => 0, 'aplicar_descuentos_directo_a_items' => 0,
            'discounts' => [], 'surchages' => [], 'services' => [], 'promocion_vinotecas' => [], 'combos' => [],
            'articles' => [$fila],
        ];
        $res = $this->postJson('api/budget', $payload);
        $this->assertEquals(201, $res->status(), $res->getContent());
        $id = $res->json('model.id');
        $antes = DB::table('article_budget')->where('budget_id', $id)->first();
        $this->assertEquals(10, $antes->cost);
        $extension = \App\Models\ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();
        if (!$extension) $extension = \App\Models\ExtencionEmpresa::forceCreate(['slug' => 'duplicar_presupuestos', 'name' => 'Duplicar presupuestos']);
        $this->dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $res = $this->postJson('api/budget/'.$id.'/duplicate');
        $this->assertEquals(201, $res->status(), $res->getContent());
        $duplicado = $res->json('model.id');
        $foto = DB::table('article_budget')->where('budget_id', $duplicado)->first();
        foreach (['price', 'cost', 'cotizacion_aplicada', 'cotizacion_origen', 'moneda_base_precio', 'moneda_base_costo'] as $campo) $this->assertSame($antes->$campo, $foto->$campo);
        $articulo->cost = 9999;
        $articulo->cost_in_dollars = 0;
        $articulo->save();
        $res = $this->postJson('api/budget/'.$duplicado.'/confirmar');
        $this->assertEquals(200, $res->status(), $res->getContent());
        $venta = \App\Models\Sale::where('budget_id', $duplicado)->first();
        $this->assertNotNull($venta);
        $foto_venta = $this->pivot_de($venta, $articulo);
        foreach (['price', 'cost', 'cotizacion_aplicada', 'cotizacion_origen', 'moneda_base_precio', 'moneda_base_costo'] as $campo) $this->assertSame($antes->$campo, $foto_venta->$campo);
        $this->assertEquals(10, $foto_venta->ganancia);
        $this->assertEquals(2, $venta->moneda_id);
    }

    public function test_moneda_de_snapshot_invalida_rechaza_con_422()
    {
        $articulo = $this->crear_articulo('Moneda de origen inválida', ['cost' => 10]);
        foreach (['1foo', '1.5', 0, 3, true, ['1']] as $moneda) {
            $datos = $this->foto(1000);
            $datos['moneda_base_costo'] = $moneda;
            $this->avanzar_reloj();
            $this->postJson('api/sale', $this->payload_venta(2, 1200, [$this->item($articulo, 1, 1.5, $datos)]))->assertStatus(422);
        }
    }

    public function test_spa_anterior_no_intercambia_fotos_de_varios_precios_iguales()
    {
        $articulo = $this->crear_articulo('Dos fotos mismo precio', ['cost' => 1000, 'cost_in_dollars' => 0]);
        $venta = $this->guardar_venta($this->payload_venta(2, 1200, [
            $this->item($articulo, 1, 20, $this->foto(1000)),
            $this->item($articulo, 2, 20, $this->foto(1250)),
        ], ['terminada' => 0]));
        $guardadas = DB::table('article_sale')->where('sale_id', $venta->id)->orderBy('amount')->get();
        $items = [];
        foreach ($guardadas as $fila) {
            $items[] = $this->item($articulo, (float) $fila->amount, (float) $fila->price, ['pivot' => ['cost' => $fila->cost, 'price' => $fila->price, 'amount' => $fila->amount]]);
        }
        $res = $this->putJson('api/sale/'.$venta->id, $this->payload_edicion_venta($venta, array_reverse($items)));
        $this->assertEquals(200, $res->status(), $res->getContent());
        $nuevas = DB::table('article_sale')->where('sale_id', $venta->id)->orderBy('amount')->get();
        foreach ($guardadas as $i => $fila) {
            foreach (['amount', 'cost', 'cotizacion_aplicada', 'cotizacion_origen'] as $campo) $this->assertSame($fila->$campo, $nuevas[$i]->$campo);
        }
    }

    public function test_pesos_sin_conversion_no_obliga_a_cargar_dolar()
    {
        $articulo = $this->crear_articulo('Pesos sin cotización', ['cost' => 1000, 'cost_in_dollars' => 0]);
        $item = $this->item($articulo, 1, 1500, ['cotizacion_aplicada' => null, 'moneda_base_precio' => 1, 'moneda_base_costo' => 1]);
        $venta = $this->guardar_venta($this->payload_venta(1, null, [$item]));
        $fila = $this->pivot_de($venta, $articulo);
        $this->assertEquals(1000, $fila->cost);
        $this->assertEquals(1500, $fila->price);
        $this->assertNull($fila->cotizacion_aplicada);
        $this->assertNull($fila->cotizacion_origen);
    }

    public function test_varios_precios_persisten_cotizacion_y_costo_de_cada_subfila()
    {
        $articulo = $this->crear_articulo('Subfilas cotizaciones distintas', ['cost' => 1000, 'cost_in_dollars' => 0]);
        $datos = $this->foto(1000);
        $item = $this->item($articulo, 1, 20, $datos);
        $item['varios_precios'] = [
            array_merge($this->foto(1000), ['amount' => 1, 'price_vender' => 20]),
            array_merge($this->foto(1250), ['amount' => 2, 'price_vender' => 20, 'cotizacion_origen' => 'manual']),
        ];
        $venta = $this->guardar_venta($this->payload_venta(2, 1200, [$item], ['total' => 60, 'sub_total' => 60]));
        $filas = DB::table('article_sale')->where('sale_id', $venta->id)->orderBy('amount')->get();
        $this->assertCount(2, $filas);
        $this->assertEquals(1000, $filas[0]->cotizacion_aplicada);
        $this->assertEquals(1, $filas[0]->cost);
        $this->assertEquals(1250, $filas[1]->cotizacion_aplicada);
        $this->assertEquals(0.8, $filas[1]->cost);
        $this->assertEquals('manual', $filas[1]->cotizacion_origen);
    }



}
