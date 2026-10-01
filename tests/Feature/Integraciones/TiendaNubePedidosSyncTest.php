<?php

namespace Tests\Feature\Integraciones;

use App\Models\TiendaNubeOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Pedidos de Tienda Nube: sincronización de un pedido NUEVO desde el módulo Pedidos.
 *
 * El 1/10/2026 `TiendaNubeOrderService` usaba `$order['created_at']` (variable inexistente) al
 * crear el pedido, y el módulo entero devolvía 500 apenas Tienda Nube traía un pedido que todavía
 * no estaba en la base. Ningún test pasaba por ese camino: sin pedidos nuevos el código no llegaba
 * a esa línea.
 *
 * Todo con `Http::fake()`: nada sale a la red. Sin conector conectado el servicio usa las
 * credenciales de entorno (`TN_ACCESS_TOKEN` / `TN_USER_ID`), que se fijan acá.
 *
 * @group integraciones
 */
class TiendaNubePedidosSyncTest extends EmpresaTestCase
{
    /** Id de la tienda y del pedido en el Tienda Nube fakeado. */
    const STORE_ID = 4663772;
    const EXTERNAL_ID = 2001889077;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        putenv('TN_ACCESS_TOKEN=token-de-prueba');
        putenv('TN_USER_ID=' . self::STORE_ID);
        $_ENV['TN_ACCESS_TOKEN'] = 'token-de-prueba';
        $_ENV['TN_USER_ID'] = (string) self::STORE_ID;

        TiendaNubeOrder::where('external_id', self::EXTERNAL_ID)->delete();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        TiendaNubeOrder::where('external_id', self::EXTERNAL_ID)->delete();

        putenv('TN_ACCESS_TOKEN');
        putenv('TN_USER_ID');
        unset($_ENV['TN_ACCESS_TOKEN'], $_ENV['TN_USER_ID']);

        parent::tearDown();
    }

    /**
     * Pedido con la forma que devuelve `GET /{store}/orders`.
     *
     * @return array<string, mixed>
     */
    protected function pedido_de_tn()
    {
        return [
            'id'              => self::EXTERNAL_ID,
            'store_id'        => self::STORE_ID,
            'total'           => '12500.00',
            'payment_status'  => 'paid',
            'created_at'      => '2026-10-01T13:15:00+0000',
            'billing_address' => ['name' => 'Santiago Pasini'],
            'products'        => [],
        ];
    }

    /**
     * Un pedido nuevo de Tienda Nube se importa y el listado del módulo lo devuelve.
     *
     * @return void
     */
    public function test_un_pedido_nuevo_se_importa_y_el_listado_responde_200()
    {
        Http::fake([
            'api.tiendanube.com/*' => Http::response([$this->pedido_de_tn()], 200),
        ]);

        $respuesta = $this->getJson('/api/tienda-nube-order');

        $respuesta->assertStatus(200);

        $pedido = TiendaNubeOrder::where('external_id', self::EXTERNAL_ID)->first();

        $this->assertNotNull($pedido, 'El pedido nuevo de Tienda Nube no se importó.');
        $this->assertSame('Santiago Pasini', $pedido->customer_name);
        $this->assertSame('Pagado', $pedido->payment_status);
        // 13:15 UTC en Tienda Nube son las 10:15 de Argentina, que es lo que se guarda en la base.
        $this->assertSame(
            '2026-10-01 10:15:00',
            DB::table('tienda_nube_orders')->where('external_id', self::EXTERNAL_ID)->value('created_at'),
            'created_at tiene que salir de la fecha del pedido en Tienda Nube, pasada a hora local.'
        );

        $ids = collect($respuesta->json('models'))->pluck('external_id')->map(function ($id) {
            return (int) $id;
        });
        $this->assertTrue($ids->contains(self::EXTERNAL_ID), 'El listado no devuelve el pedido importado.');
    }

    /**
     * Volver a sincronizar no duplica el pedido.
     *
     * @return void
     */
    public function test_sincronizar_dos_veces_no_duplica_el_pedido()
    {
        Http::fake([
            'api.tiendanube.com/*' => Http::response([$this->pedido_de_tn()], 200),
        ]);

        $this->getJson('/api/tienda-nube-order')->assertStatus(200);
        $this->getJson('/api/tienda-nube-order')->assertStatus(200);

        $this->assertSame(1, TiendaNubeOrder::where('external_id', self::EXTERNAL_ID)->count());
    }
}
