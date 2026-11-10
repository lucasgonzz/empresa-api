<?php

namespace Tests\Feature\Pedidos;

use App\Models\OnlineConfiguration;
use App\Models\Sale;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

class Stock_a_consultar_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /** @test */
    public function la_opcion_arranca_apagada_y_un_formulario_anterior_respeta_la_eleccion()
    {
        $config = OnlineConfiguration::create(['user_id' => $this->user_id()]);
        $this->assertFalse($config->fresh()->permitir_pedidos_sin_stock);

        $url = 'api/online-configuration/'.$config->id;
        $payload = $config->fresh()->toArray();
        $payload['permitir_pedidos_sin_stock'] = true;
        $this->putJson($url, $payload)->assertOk();
        $this->assertTrue($config->fresh()->permitir_pedidos_sin_stock);

        unset($payload['permitir_pedidos_sin_stock']);
        $this->putJson($url, $payload)->assertOk();
        $this->assertTrue($config->fresh()->permitir_pedidos_sin_stock);

        $payload['permitir_pedidos_sin_stock'] = false;
        $this->putJson($url, $payload)->assertOk();
        $this->assertFalse($config->fresh()->permitir_pedidos_sin_stock);
    }

    /** @test */
    public function revisar_y_confirmar_conserva_la_marca_y_las_dos_variantes_del_articulo()
    {
        $this->sembrar_estados_de_pedido();
        $this->app['auth']->user()->online_configuration->update(['save_sale_after_finish_order' => true]);

        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $articulo = $this->renglones()[0]['article'];
        $consultada = $articulo->article_variants()->create(['variant_description' => 'Rojo', 'stock' => 0]);
        $disponible = $articulo->article_variants()->create(['variant_description' => 'Azul', 'stock' => 5]);
        $deposito = $this->deposito()->id;
        $consultada->addresses()->attach($deposito, ['amount' => 0]);
        $disponible->addresses()->attach($deposito, ['amount' => 5]);

        $pedido->articles()->detach();
        $pedido->articles()->attach($articulo->id, [
            'price' => 100,
            'amount' => 1,
            'variant_id' => $consultada->id,
            'stock_a_consultar' => true,
        ]);
        $pedido->articles()->attach($articulo->id, [
            'price' => 100,
            'amount' => 2,
            'variant_id' => $disponible->id,
        ]);

        $url = 'api/order/'.$pedido->id;
        $detalle = $this->getJson($url)->assertOk()->json('model');
        $renglones = collect($detalle['articles'])->keyBy('pivot.variant_id');
        $this->assertCount(2, $renglones);
        $this->assertSame('Rojo', $renglones[$consultada->id]['variant']);
        $this->assertEquals(1, $renglones[$consultada->id]['pivot']['stock_a_consultar']);
        $this->assertEquals(0, $renglones[$disponible->id]['pivot']['stock_a_consultar']);

        // Al revisar el pedido, el negocio repone la variante consultada y cambia las cantidades.
        // La marca histórica se conserva aunque el formulario mande la marca invertida.
        $consultada->update(['stock' => 3]);
        $consultada->addresses()->updateExistingPivot($deposito, ['amount' => 3]);
        $renglones = $renglones->map(function (array $renglon) {
            $renglon['pivot']['amount'] = 1;
            $renglon['pivot']['stock_a_consultar'] = !$renglon['pivot']['stock_a_consultar'];
            return $renglon;
        });
        $this->putJson($url, [
            'order_status_id' => $this->estado('Confirmado')->id,
            'address_id' => $deposito,
            'articles' => $renglones->values()->all(),
        ])->assertOk();

        $this->assertDatabaseHas('article_order', [
            'order_id' => $pedido->id,
            'article_id' => $articulo->id,
            'variant_id' => $consultada->id,
            'amount' => 1,
            'stock_a_consultar' => 1,
        ]);
        $this->assertDatabaseHas('article_order', [
            'order_id' => $pedido->id,
            'article_id' => $articulo->id,
            'variant_id' => $disponible->id,
            'amount' => 1,
            'stock_a_consultar' => 0,
        ]);

        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->assertEquals(200, $venta->total);
        $variantes_vendidas = $venta->articles->pluck('pivot.article_variant_id')->map(function ($id) {
            return (int) $id;
        });
        $this->assertEqualsCanonicalizing([$consultada->id, $disponible->id], $variantes_vendidas->all());
    }
}
