<?php

namespace Tests\Feature\Pedidos;

use App\Models\Article;
use App\Models\CurrentAcount;
use App\Models\OnlineConfiguration;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

class Reserva_stock_web_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    private function reservado($cantidad = 6)
    {
        $this->sembrar_estados_de_pedido();
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $articulo = $this->renglones()[0]['article'];
        $articulo->update(['stock' => 10]);
        $articulo->addresses()->detach();
        $articulo->addresses()->attach($pedido->address_id, ['amount' => 10]);
        $pedido->articles()->detach();
        $pedido->articles()->attach($articulo->id, ['amount' => $cantidad, 'price' => 100]);
        $pedido->update(['reserva_stock_web' => true, 'total' => $cantidad * 100]);
        $this->configuracion()->update(['reservar_stock_pedidos_web' => true, 'save_sale_after_finish_order' => true]);
        DB::table('order_stock_reservations')->insert(['user_id' => $pedido->user_id, 'order_id' => $pedido->id,
            'article_id' => $articulo->id, 'address_id' => $pedido->address_id, 'amount' => $cantidad]);
        return [$pedido->fresh(), $articulo->fresh()];
    }

    private function configuracion()
    {
        return OnlineConfiguration::where('user_id', $this->user_id())->orderBy('id')->firstOrFail();
    }

    private function guardado_config($config, $activo)
    {
        return $this->putJson('api/online-configuration/'.$config->id,
            array_merge($config->fresh()->toArray(), ['reservar_stock_pedidos_web' => $activo]));
    }

    /** @test */
    public function confirmar_consume_una_reserva_y_una_sola_venta()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->assertEquals(10, $articulo->stock);
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());
        $url = 'api/order/'.$pedido->id;
        $this->putJson($url, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->putJson($url, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertEquals(1, Sale::where('order_id', $pedido->id)->count());
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertTrue($pedido->fresh()->reserva_stock_web_consumida);
    }

    /** @test */
    public function cancelar_repetido_libera_sin_inventar_unidades()
    {
        list($pedido, $articulo) = $this->reservado();
        $url = 'api/order/'.$pedido->id;
        $this->putJson($url, $this->payload_de_estado('Cancelado'))->assertOk();
        $this->putJson($url, $this->payload_de_estado('Cancelado'))->assertOk();
        $this->putJson($url, $this->payload_de_estado('Confirmado'))->assertStatus(422);
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());
    }

    /** @test */
    public function editar_cantidad_y_quitar_refleja_la_reserva_exacta()
    {
        list($pedido, $articulo) = $this->reservado();
        $url = 'api/order/'.$pedido->id;
        $this->putJson($url, ['articles' => [['id' => $articulo->id, 'pivot' => ['amount' => 3, 'price' => 100]]]])->assertOk();
        $this->assertEquals(3, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->putJson($url, ['articles' => []])->assertOk();
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function cantidad_excesiva_o_deposito_ajeno_revierte_todo()
    {
        list($pedido, $articulo) = $this->reservado();
        $url = 'api/order/'.$pedido->id;
        $this->putJson($url, ['articles' => [['id' => $articulo->id, 'pivot' => ['amount' => 11, 'price' => 100]]]])->assertStatus(422);
        $this->assertEquals(6, $pedido->fresh()->articles->first()->pivot->amount);
        $this->putJson($url, ['address_id' => 99999999])->assertStatus(422);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
    }

    /** @test */
    public function apagar_libera_pendientes_y_no_los_reserva_al_volver_a_prender()
    {
        list($pedido, $articulo) = $this->reservado();
        $config = $this->configuracion();
        $this->guardado_config($config, false)->assertOk();
        $this->assertTrue($pedido->fresh()->reserva_stock_web);
        $this->assertTrue($pedido->fresh()->reserva_stock_web_liberada);
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $config->update(['reserva_stock_tienda_url' => 'https://tienda-prueba.test', 'reserva_stock_tienda_protocolo' => 1]);
        Http::fake(['*' => Http::response(['contract' => 1, 'commerce_id' => $this->user_id()])]);
        $this->guardado_config($config, true)->assertOk();
        $this->putJson('api/order/'.$pedido->id, [])->assertOk();
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function sin_venta_descuenta_y_cancelar_despues_de_apagar_revierte_una_sola_vez()
    {
        list($pedido, $articulo) = $this->reservado();
        $config = $this->configuracion();
        $config->update(['save_sale_after_finish_order' => false]);
        $cuentas = CurrentAcount::count();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());
        $this->assertEquals($cuentas, CurrentAcount::count());
        $this->guardado_config($config, false)->assertOk();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))->assertOk();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))->assertOk();
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(2, StockMovement::where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function consumido_rechaza_cambiar_cantidades_desde_pedido()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->putJson('api/order/'.$pedido->id, ['articles' => [['id' => $articulo->id, 'pivot' => ['amount' => 2, 'price' => 100]]]])->assertStatus(422);
        $this->assertEquals(4, $articulo->fresh()->stock);
    }

    /** @test */
    public function opcion_nueva_arranca_apagada_y_no_acepta_capability_del_formulario()
    {
        $config = OnlineConfiguration::create(['user_id' => $this->user_id()]);
        $this->assertFalse($config->fresh()->reservar_stock_pedidos_web);
        $payload = array_merge($config->fresh()->toArray(), ['reservar_stock_pedidos_web' => true,
            'reserva_stock_tienda_url' => 'https://falsa.test', 'reserva_stock_tienda_protocolo' => 1]);
        $this->putJson('api/online-configuration/'.$config->id, $payload)->assertStatus(422);
        $this->assertFalse($config->fresh()->reservar_stock_pedidos_web);
    }

    /** @test */
    public function activar_exige_capability_viva_del_mismo_comercio()
    {
        $config = $this->configuracion();
        $config->update(['reservar_stock_pedidos_web' => false, 'reserva_stock_tienda_url' => 'https://tienda-prueba.test', 'reserva_stock_tienda_protocolo' => 1]);
        Http::fake(['*' => Http::response(['contract' => 1, 'commerce_id' => $this->user_id() + 1])]);
        $this->guardado_config($config, true)->assertStatus(422);
        $this->assertFalse($config->fresh()->reservar_stock_pedidos_web);
    }

    /** @test */
    public function activar_sin_verificacion_de_soporte_explica_que_hay_que_pedirla_a_comerciocity()
    {
        $config = $this->configuracion();
        $config->update(['reservar_stock_pedidos_web' => false, 'reserva_stock_tienda_url' => null, 'reserva_stock_tienda_protocolo' => 0]);
        $respuesta = $this->guardado_config($config, true);
        $respuesta->assertStatus(422);
        // El mensaje debe decirle al dueño a quién pedirle la verificación (no sólo "verificá").
        $this->assertStringContainsString('soporte de ComercioCity', $respuesta->json('errors.reservar_stock_pedidos_web.0'));
        $this->assertFalse($config->fresh()->reservar_stock_pedidos_web);
    }

    /** @test */
    public function un_empleado_no_puede_prender_ni_apagar_reservas()
    {
        list($pedido) = $this->reservado();
        $config = $this->configuracion();
        $empleado = User::create(['name' => 'Empleado reservas', 'email' => 'reservas-empleado@testing.local', 'password' => 'sin-acceso', 'owner_id' => $this->user_id()]);
        $this->actingAs($empleado, 'web');
        $this->guardado_config($config, false)->assertStatus(403);
        $this->assertTrue($config->fresh()->reservar_stock_pedidos_web);
    }

    /** @test */
    public function ignorar_stock_exige_apagar_reserva_y_libera_en_el_mismo_guardado()
    {
        list($pedido) = $this->reservado();
        $config = $this->configuracion();
        $payload = $config->fresh()->toArray();
        unset($payload['reservar_stock_pedidos_web']);
        $payload['ignorar_stock'] = true;
        $this->putJson('api/online-configuration/'.$config->id, $payload)->assertStatus(422);
        $this->assertTrue($config->fresh()->reservar_stock_pedidos_web);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $payload['reservar_stock_pedidos_web'] = false;
        $this->putJson('api/online-configuration/'.$config->id, $payload)->assertOk();
        $this->assertTrue($pedido->fresh()->reserva_stock_web);
        $this->assertTrue($pedido->fresh()->reserva_stock_web_liberada);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function stock_global_no_se_puede_reservar_dos_veces_eligiendo_otro_deposito()
    {
        list($primero, $articulo) = $this->reservado();
        $articulo->addresses()->detach();
        $otro_deposito = \App\Models\Address::create(['street' => 'Depósito reservas alternativo', 'user_id' => $this->user_id()]);
        $segundo = $this->crear_pedido($this->cliente_cc()->id);
        $segundo->articles()->detach();
        $segundo->articles()->attach($articulo->id, ['amount' => 6, 'price' => 100]);
        $segundo->update(['reserva_stock_web' => true]);
        $this->putJson('api/order/'.$segundo->id, ['address_id' => $otro_deposito->id])->assertStatus(422);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('article_id', $articulo->id)->sum('amount'));
    }

    private function activar_check_sales()
    {
        $extension = \App\Models\ExtencionEmpresa::firstOrCreate(['slug' => 'check_sales'], ['name' => 'Control de depósito']);
        $dueno = $this->app['auth']->user();
        $dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $dueno->unsetRelation('extencions');
    }

    private function payload_venta_reservada($venta, $articulo, $cantidad, $confirmar)
    {
        $venta = $venta->fresh();
        // Vender convierte la fecha al día elegido antes de enviar la edición; reproducimos ese contrato.
        return array_merge($venta->toArray(), ['created_at' => $venta->created_at->format('Y-m-d'), 'items' => [[
            'is_article' => true, 'id' => $articulo->id, 'amount' => $cantidad, 'price_vender' => 100,
            'checked_amount' => $confirmar ? $cantidad : null,
        ]], 'to_check' => $confirmar ? 0 : 1, 'checked' => 0, 'confirmed' => $confirmar ? 1 : 0,
            'total' => $cantidad * 100, 'sub_total' => $cantidad * 100, 'discounts' => [], 'surchages' => []]);
    }

    /** @test */
    public function check_sales_reserva_hasta_egreso_y_editar_la_venta_ajusta_las_unidades()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->activar_check_sales();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->assertTrue((bool) $venta->to_check);
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $this->assertFalse(CurrentAcount::where('sale_id', $venta->id)->exists());
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 3, false))->assertOk();
        $this->assertEquals(3, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 3, true))->assertOk();
        $this->assertEquals(7, $articulo->fresh()->stock);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertTrue($pedido->fresh()->reserva_stock_web_consumida);
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 3, true))->assertOk();
        $this->assertEquals(7, $articulo->fresh()->stock);
    }

    /** @test */
    public function borrar_venta_pendiente_no_reserva_de_nuevo_desde_el_pedido()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->activar_check_sales();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->deleteJson('api/sale/'.$venta->id)->assertSuccessful();
        $this->assertTrue($pedido->fresh()->reserva_stock_web);
        $this->assertTrue($pedido->fresh()->reserva_stock_web_liberada);
        $this->putJson('api/order/'.$pedido->id, [])->assertOk();
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertEquals(10, $articulo->fresh()->stock);
    }

    /** @test */
    public function pedido_confirmado_y_cancelado_se_puede_eliminar_con_y_sin_venta()
    {
        foreach ([true, false] as $genera_venta) {
            list($pedido, $articulo) = $this->reservado();
            $this->configuracion()->update(['save_sale_after_finish_order' => $genera_venta]);
            $url = 'api/order/'.$pedido->id;
            $this->putJson($url, $this->payload_de_estado('Confirmado'))->assertOk();
            $this->putJson($url, $this->payload_de_estado('Cancelado'))->assertOk();
            $this->deleteJson($url)->assertSuccessful();
            $this->assertEquals(10, $articulo->fresh()->stock);
            $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
        }
    }

    /** @test */
    public function variantes_y_consultar_se_reservan_por_renglon_sin_inventar_stock()
    {
        list($pedido, $articulo) = $this->reservado();
        $disponible = $articulo->article_variants()->create(['variant_description' => 'Disponible reservas', 'stock' => 10]);
        $consultada = $articulo->article_variants()->create(['variant_description' => 'Consultar reservas', 'stock' => 0]);
        $disponible->addresses()->attach($pedido->address_id, ['amount' => 10]);
        $consultada->addresses()->attach($pedido->address_id, ['amount' => 0]);
        $pedido->articles()->detach();
        $pedido->articles()->attach($articulo->id, ['amount' => 6, 'price' => 100, 'variant_id' => $disponible->id]);
        $pedido->articles()->attach($articulo->id, ['amount' => 12, 'price' => 100, 'variant_id' => $consultada->id, 'stock_a_consultar' => 1]);
        $this->putJson('api/order/'.$pedido->id, [])->assertOk();
        $filas = DB::table('order_stock_reservations')->where('order_id', $pedido->id)->get();
        $this->assertCount(1, $filas);
        $this->assertEquals($disponible->id, $filas[0]->article_variant_id);
        $this->assertEquals(6, $filas[0]->amount);
        $this->assertEquals(0, $consultada->fresh()->stock);
    }

    /** @test */
    public function articulo_suelto_y_combo_reservan_el_mismo_recurso_y_promocion_su_stock_propio()
    {
        list($pedido, $articulo) = $this->reservado(2);
        $combo = \App\Models\Combo::create(['num' => 9611, 'name' => 'Combo reserva', 'price' => 100, 'cost' => 10, 'user_id' => $this->user_id()]);
        $combo->articles()->attach($articulo->id, ['amount' => 2]);
        $pedido->combos()->attach($combo->id, ['amount' => 2, 'price' => 100]);
        $promo = \App\Models\PromocionVinoteca::create(['name' => 'Promo reserva', 'stock' => 4, 'user_id' => $this->user_id()]);
        $pedido->promocion_vinotecas()->attach($promo->id, ['amount' => 3, 'price' => 100]);
        $this->putJson('api/order/'.$pedido->id, [])->assertOk();
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->where('article_id', $articulo->id)->sum('amount'));
        $this->assertEquals(3, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->where('promocion_vinoteca_id', $promo->id)->sum('amount'));
        $this->assertEquals(4, $promo->fresh()->stock);
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertEquals(1, $promo->fresh()->stock);
    }

    /** @test */
    public function formulario_viejo_puede_guardar_pedido_consumido_sin_repetir_su_unica_variante()
    {
        list($pedido, $articulo) = $this->reservado();
        $variante = $articulo->article_variants()->create(['variant_description' => 'Variante formulario viejo', 'stock' => 10]);
        $variante->addresses()->attach($pedido->address_id, ['amount' => 10]);
        $pedido->articles()->detach();
        $pedido->articles()->attach($articulo->id, ['amount' => 6, 'price' => 100, 'variant_id' => $variante->id]);
        $url = 'api/order/'.$pedido->id;
        $this->putJson($url, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->assertEquals(4, $variante->fresh()->stock);
        $this->putJson($url, ['articles' => [['id' => $articulo->id, 'pivot' => ['amount' => 6, 'price' => 100]]]])->assertOk();
        $this->assertEquals($variante->id, $pedido->fresh()->articles->first()->pivot->variant_id);
        $this->assertEquals(4, $variante->fresh()->stock);
        $this->assertEquals(1, Sale::where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function apagar_durante_revision_no_olvida_el_primer_descuento_de_la_venta()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->activar_check_sales();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->guardado_config($this->configuracion(), false)->assertOk();
        $this->assertTrue($pedido->fresh()->reserva_stock_web);
        $this->assertTrue($pedido->fresh()->reserva_stock_web_liberada);
        $this->assertFalse($pedido->fresh()->reserva_stock_web_consumida);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 6, true))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertTrue($pedido->fresh()->reserva_stock_web_consumida);
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 6, true))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
    }

    /** @test */
    public function rechaza_cantidades_decimales_antes_de_que_el_pivote_las_redondee()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->putJson('api/order/'.$pedido->id, ['articles' => [[
            'id' => $articulo->id, 'pivot' => ['amount' => 1.2, 'price' => 100],
        ]]])->assertStatus(422);
        $this->assertEquals(6, $pedido->fresh()->articles->first()->pivot->amount);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->putJson('api/order/'.$pedido->id, ['articles' => [[
            'id' => $articulo->id, 'pivot' => ['amount' => 2147483648, 'price' => 100],
        ]]])->assertStatus(422);
        $this->assertEquals(6, $pedido->fresh()->articles->first()->pivot->amount);
    }

    /** @test */
    public function rechaza_pivotes_duplicados_sin_alterar_stock_ni_reserva()
    {
        list($pedido, $articulo) = $this->reservado();
        $articulo->addresses()->detach();
        $articulo->addresses()->attach($pedido->address_id, ['amount' => 4]);
        $articulo->addresses()->attach($pedido->address_id, ['amount' => 6]);
        $this->putJson('api/order/'.$pedido->id, [])->assertStatus(422);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertStatus(422);
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Cancelado'))->assertOk();
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertEquals(10, $articulo->fresh()->stock);
    }

    /** @test */
    public function rechaza_stock_de_variante_con_pivotes_duplicados()
    {
        list($pedido, $articulo) = $this->reservado();
        $variante = $articulo->article_variants()->create(['variant_description' => 'Variante duplicada', 'stock' => 10]);
        $variante->addresses()->attach($pedido->address_id, ['amount' => 4]);
        $variante->addresses()->attach($pedido->address_id, ['amount' => 6]);
        $pedido->articles()->detach();
        $pedido->articles()->attach($articulo->id, ['amount' => 6, 'price' => 100, 'variant_id' => $variante->id]);
        DB::table('order_stock_reservations')->where('order_id', $pedido->id)->delete();
        $this->putJson('api/order/'.$pedido->id, [])->assertStatus(422);
        $this->assertEquals(0, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->count());
        $this->assertEquals(10, $variante->fresh()->stock);
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());
    }
}
