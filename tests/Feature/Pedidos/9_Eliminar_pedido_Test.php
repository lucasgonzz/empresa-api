<?php

namespace Tests\Feature\Pedidos;

use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\PermissionEmpresa;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\PedidosDePrueba;
use Tests\EmpresaTestCase;

/**
 * Misión eliminar-pedidos-tienda: criterios de aceptación CA1 a CA9 de la API.
 *
 * Regla de negocio que se fija acá (pedido literal de Lucas):
 *   - Eliminar un pedido SIN venta borra solamente el pedido.
 *   - Eliminar un pedido CON venta borra solamente el pedido: la venta (con su total, su cuenta
 *     corriente y su stock) queda intacta.
 *   - Si el pedido había reservado stock, la reserva se libera; si ya había descontado stock sin
 *     venta, se devuelve UNA sola vez.
 *
 * Todo se ejercita contra `DELETE api/order/{id}` real. Los armadores privados (reservado,
 * configuracion, activar_check_sales, payload_venta_reservada) son copia local de los de
 * `8_Reserva_stock_web_Test.php`, que no se edita.
 *
 * @group pedidos
 */
class Eliminar_pedido_Test extends EmpresaTestCase
{
    use PedidosDePrueba;

    /**
     * Arma el escenario base de reservas: artículo centinela con stock 10, pedido con 1 renglón
     * de $cantidad unidades a $100, reserva viva por esa cantidad y la tienda con reservas activas.
     *
     * @param  int  $cantidad  Unidades reservadas.
     * @return array  [Order $pedido, Article $articulo]
     */
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

    /**
     * Configuración de la tienda del dueño del fixture.
     *
     * @return \App\Models\OnlineConfiguration
     */
    private function configuracion()
    {
        return OnlineConfiguration::where('user_id', $this->user_id())->orderBy('id')->firstOrFail();
    }

    /**
     * Activa la extensión `check_sales` (control de depósito) en el dueño del fixture.
     *
     * @return void
     */
    private function activar_check_sales()
    {
        $extension = \App\Models\ExtencionEmpresa::firstOrCreate(['slug' => 'check_sales'], ['name' => 'Control de depósito']);
        $dueno = $this->app['auth']->user();
        $dueno->extencions()->syncWithoutDetaching([$extension->id]);
        $dueno->unsetRelation('extencions');
    }

    /**
     * Payload de edición de una venta reservada, como lo manda Vender.
     *
     * @param  \App\Models\Sale     $venta
     * @param  \App\Models\Article  $articulo
     * @param  int                  $cantidad
     * @param  bool                 $confirmar  true = confirma la venta (egreso).
     * @return array
     */
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

    /**
     * Cantidad de filas de reserva de un pedido.
     *
     * @param  int  $order_id
     * @return int
     */
    private function reservas_de($order_id)
    {
        return DB::table('order_stock_reservations')->where('order_id', $order_id)->count();
    }

    /**
     * CA1: pedido "Sin confirmar" sin reserva ni venta: solo se borra el pedido.
     *
     * @test
     */
    public function sin_venta_ni_reserva_solo_borra_el_pedido()
    {
        $this->sembrar_estados_de_pedido();
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $stock_previo = $this->stock_actual();

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
        $this->assertEquals($stock_previo, $this->stock_actual());
        $this->assertEquals(0, StockMovement::where('order_id', $pedido->id)->count());
        $this->assertEquals(0, Sale::where('order_id', $pedido->id)->count());
    }

    /**
     * CA2: pedido reservado "Sin confirmar": se libera la reserva y el stock queda en 10.
     *
     * @test
     */
    public function sin_venta_con_reserva_activa_libera_la_reserva()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->assertEquals(1, $this->reservas_de($pedido->id));

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertEquals(0, $this->reservas_de($pedido->id));
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(0, StockMovement::where('order_id', $pedido->id)->count());
        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
    }

    /**
     * CA3: confirmado sin venta (el stock ya bajó a 4): eliminar devuelve las 6 unidades, con sus
     * dos movimientos (-6 y +6), y un segundo DELETE da 404 sin devolver nada de nuevo.
     *
     * @test
     */
    public function sin_venta_con_egreso_devuelve_el_stock_una_sola_vez()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->configuracion()->update(['save_sale_after_finish_order' => false]);
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertFalse(Sale::where('order_id', $pedido->id)->exists());

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(2, StockMovement::where('order_id', $pedido->id)->count());
        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);

        // Segundo borrado: el pedido ya no existe y el stock no se devuelve dos veces.
        $this->deleteJson('api/order/'.$pedido->id)->assertStatus(404);
        $this->assertEquals(10, $articulo->fresh()->stock);
    }

    /**
     * CA4: pedido confirmado CON venta (stock 4, reserva consumida): eliminar borra solo el pedido;
     * la venta, su cuenta corriente, el saldo del cliente y el stock quedan exactamente igual.
     *
     * @test
     */
    public function con_venta_confirmada_borra_el_pedido_y_deja_la_venta_intacta()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertEquals(0, $this->reservas_de($pedido->id));

        // Foto previa de todo lo que la venta ya produjo.
        $movimientos_previos = StockMovement::count();
        $cuentas_previas = CurrentAcount::where('sale_id', $venta->id)->count();
        $total_previo = $venta->fresh()->total;
        $to_check_previo = (bool) $venta->fresh()->to_check;
        $saldo_previo = Client::find($this->cliente_cc()->id)->saldo;

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
        $venta_despues = Sale::find($venta->id);
        $this->assertNotNull($venta_despues, 'La venta no se elimina junto con el pedido.');
        $this->assertNull($venta_despues->deleted_at ?? null);
        $this->assertEquals($pedido->id, $venta_despues->order_id);
        $this->assertEquals($total_previo, $venta_despues->total);
        $this->assertEquals(600, (float) $venta_despues->total);
        $this->assertEquals($to_check_previo, (bool) $venta_despues->to_check);
        $this->assertEquals($cuentas_previas, CurrentAcount::where('sale_id', $venta->id)->count());
        $this->assertEquals($saldo_previo, Client::find($this->cliente_cc()->id)->saldo);
        $this->assertEquals(4, $articulo->fresh()->stock);
        $this->assertEquals($movimientos_previos, StockMovement::count());
        $this->assertEquals(0, $this->reservas_de($pedido->id));
    }

    /**
     * CA5: venta en revisión (check_sales) con reserva viva: eliminar libera la reserva y deja la
     * venta; al confirmar después la venta con 3 unidades, el stock queda en 7 (no 13).
     *
     * @test
     */
    public function con_venta_en_revision_libera_la_reserva_y_la_venta_descuenta_al_confirmar()
    {
        list($pedido, $articulo) = $this->reservado();
        $this->activar_check_sales();
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();
        $this->assertTrue((bool) $venta->to_check);
        $this->assertEquals(10, $articulo->fresh()->stock);
        $this->assertEquals(6, DB::table('order_stock_reservations')->where('order_id', $pedido->id)->sum('amount'));

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertEquals(0, $this->reservas_de($pedido->id));
        $this->assertEquals(10, $articulo->fresh()->stock);
        $venta_despues = Sale::find($venta->id);
        $this->assertNotNull($venta_despues);
        $this->assertTrue((bool) $venta_despues->to_check);
        $this->assertEquals($pedido->id, $venta_despues->order_id);

        // La venta sigue viva y, al confirmarla, descuenta el stock una sola vez: 10 - 3 = 7.
        $this->putJson('api/sale/'.$venta->id, $this->payload_venta_reservada($venta, $articulo, 3, true))->assertOk();
        $this->assertEquals(7, $articulo->fresh()->stock);
        $this->assertEquals(0, $this->reservas_de($pedido->id));
    }

    /**
     * CA6: pedido sin reserva con venta generada: eliminar deja la venta, el stock y la cuenta
     * corriente sin cambios.
     *
     * @test
     */
    public function pedido_sin_reserva_con_venta_deja_la_venta_intacta()
    {
        $this->sembrar_estados_de_pedido();
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $this->configuracion()->update(['save_sale_after_finish_order' => true]);
        $this->putJson('api/order/'.$pedido->id, $this->payload_de_estado('Confirmado'))->assertOk();
        $venta = Sale::where('order_id', $pedido->id)->firstOrFail();

        $stock_previo = $this->stock_actual();
        $cuentas_previas = CurrentAcount::where('sale_id', $venta->id)->count();
        $total_previo = $venta->fresh()->total;

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
        $venta_despues = Sale::find($venta->id);
        $this->assertNotNull($venta_despues, 'La venta no se elimina junto con el pedido.');
        $this->assertEquals($total_previo, $venta_despues->total);
        $this->assertEquals($pedido->id, $venta_despues->order_id);
        $this->assertEquals($stock_previo, $this->stock_actual());
        $this->assertEquals($cuentas_previas, CurrentAcount::where('sale_id', $venta->id)->count());
    }

    /**
     * CA7: comercio sin reservas y pedido "Cancelado" sin reserva ni venta: se borra sin mover stock.
     *
     * @test
     */
    public function comercio_sin_reservas_borra_el_pedido_sin_mover_stock()
    {
        $this->sembrar_estados_de_pedido();
        $this->configuracion()->update(['reservar_stock_pedidos_web' => false]);
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $pedido->update(['order_status_id' => $this->estado('Cancelado')->id]);
        $stock_previo = $this->stock_actual();

        $this->deleteJson('api/order/'.$pedido->id)->assertOk();

        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
        $this->assertEquals($stock_previo, $this->stock_actual());
    }

    /**
     * CA8: un pedido de otro dueño o inexistente da 404 y no se toca.
     *
     * @test
     */
    public function pedido_de_otro_comercio_o_inexistente_da_404()
    {
        $this->sembrar_estados_de_pedido();
        $otro_dueno = User::forceCreate([
            'name'         => 'Comercio ajeno eliminar pedido',
            'company_name' => 'Ferreteria ajena',
            'email'        => 'eliminar-pedido-ajeno-'.uniqid().'@test.local',
            'password'     => Hash::make('secret'),
        ]);
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $pedido->update(['user_id' => $otro_dueno->id]);

        $this->deleteJson('api/order/'.$pedido->id)
            ->assertStatus(404)
            ->assertJson(['message' => 'El pedido no existe o no es de tu cuenta.']);
        $this->assertDatabaseHas('orders', ['id' => $pedido->id]);

        $this->deleteJson('api/order/999999999')->assertStatus(404);
    }

    /**
     * CA9: un empleado sin el permiso `order.delete` recibe 403 y el pedido sigue; con el permiso
     * se elimina.
     *
     * @test
     */
    public function empleado_necesita_el_permiso_order_delete()
    {
        $this->sembrar_estados_de_pedido();
        $pedido = $this->crear_pedido($this->cliente_cc()->id);
        $dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        $empleado = User::create([
            'name'         => 'Empleado eliminar pedido',
            'company_name' => 'Ferreteria eliminar pedido',
            'email'        => 'eliminar-pedido-emp-'.uniqid().'@test.local',
            'password'     => Hash::make('x'),
            'owner_id'     => $dueno->id,
        ]);

        // Sin el permiso: 403 y el pedido sigue.
        Auth::forgetGuards();
        $this->actingAs($empleado->fresh(), 'web');
        $this->deleteJson('api/order/'.$pedido->id)
            ->assertStatus(403)
            ->assertJson(['message' => 'No tenés permiso para eliminar pedidos de la tienda.']);
        $this->assertDatabaseHas('orders', ['id' => $pedido->id]);

        // Con el permiso: 200 y el pedido se borra.
        $permiso = PermissionEmpresa::where('slug', 'order.delete')->first();
        if (is_null($permiso)) {
            $permiso = PermissionEmpresa::forceCreate(['slug' => 'order.delete', 'name' => 'Eliminar pedidos de la tienda', 'model_name' => 'Pedidos']);
        }
        $empleado->permissions()->sync([$permiso->id]);
        Auth::forgetGuards();
        $this->actingAs($empleado->fresh(), 'web');
        $this->deleteJson('api/order/'.$pedido->id)->assertOk();
        $this->assertDatabaseMissing('orders', ['id' => $pedido->id]);
    }
}
