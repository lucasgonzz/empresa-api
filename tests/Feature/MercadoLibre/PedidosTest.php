<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\Article;
use App\Models\MeliOrder;
use App\Models\Sale;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\OrderDownloaderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;

/**
 * Pedidos de Mercado Libre por consulta periódica (misión mercado-libre-integracion, 27/10/2026):
 * el `MeliOrder` con `user_id`, la venta automática de un pedido pago, dos corridas = una venta,
 * renglón sin vincular, cancelación con venta, pedidos anteriores a la conexión, el comando y el
 * listado que no consulta si el último poll fue hace menos de un minuto.
 *
 * @group mercado-libre
 */
class PedidosTest extends MercadoLibreTestCase
{
    /** @var Article Artículo del dueño publicado como MLA111. */
    protected $articulo;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        MeliOrder::whereIn('meli_order_id', ['2000001001', '2000001002', '2000001003'])->delete();

        $this->articulo = $this->articulo_ml(null, ['me_li_id' => 'MLA111', 'stock' => 10]);
    }

    /**
     * Fakea `orders/search` con esos pedidos.
     *
     * @param array<int, array<string, mixed>> $pedidos
     * @return void
     */
    protected function ml_devuelve_pedidos(array $pedidos)
    {
        $this->fake_ml([
            'GET orders/search' => [200, [
                'results' => $pedidos,
                'paging'  => ['total' => count($pedidos), 'offset' => 0, 'limit' => 50],
            ]],
        ]);
        $this->rutas_ml['GET orders/search'] = [200, [
            'results' => $pedidos,
            'paging'  => ['total' => count($pedidos), 'offset' => 0, 'limit' => 50],
        ]];
    }

    /**
     * Corre la consulta periódica como lo hace el comando.
     *
     * @return array<string, int>
     */
    protected function sincronizar()
    {
        return (new OrderDownloaderService($this->owner->id))->sincronizar_pedidos_recientes();
    }

    public function test_crea_el_pedido_con_el_comercio_pack_y_envio_y_queda_pendiente()
    {
        config(['services.mercadolibre.crear_venta_automatica' => false]);
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 2, 15000]])]);

        $this->sincronizar();

        $pedidos = $this->pedidos_locales('2000001001');
        $this->assertCount(1, $pedidos);

        $pedido = $pedidos->first();
        $this->assertSame((int) $this->owner->id, (int) $pedido->user_id);
        $this->assertSame(1, (int) $pedido->meli_order_status_id);
        $this->assertSame('2000009999001', $pedido->pack_id);
        $this->assertSame('4400012345', $pedido->shipping_id);
        $this->assertSame('paid', $pedido->status);
        $this->assertSame([$this->articulo->id], $pedido->articles()->pluck('articles.id')->map(function ($id) {
            return (int) $id;
        })->all());
        $this->assertFalse(Sale::where('meli_order_id', $pedido->id)->exists(), 'Con la venta automática apagada no se genera venta.');
    }

    public function test_un_pedido_pago_con_todo_vinculado_genera_la_venta_y_baja_el_stock()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 2, 15000]])]);

        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();
        $this->assertSame(2, (int) $pedido->fresh()->meli_order_status_id, 'El pedido con venta queda Confirmado.');

        $ventas = Sale::where('meli_order_id', $pedido->id)->get();
        $this->assertCount(1, $ventas);
        $this->assertEquals(30000, (float) $ventas->first()->total);

        $this->assertEquals(8, (float) Article::find($this->articulo->id)->stock, 'La venta tiene que bajar el stock.');
    }

    public function test_dos_corridas_generan_una_sola_venta()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]])]);

        $this->sincronizar();
        $this->sincronizar();

        $pedidos = $this->pedidos_locales('2000001001');
        $this->assertCount(1, $pedidos, 'El pedido no se puede duplicar.');
        $this->assertSame(1, Sale::where('meli_order_id', $pedidos->first()->id)->count());
    }

    public function test_un_renglon_sin_vincular_no_genera_venta_y_avisa()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001002', 'paid', [
            ['MLA111', 1, 15000],
            ['MLA999', 1, 5000, 'Destornillador que no está en el sistema'],
        ])]);

        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001002')->first();
        $this->assertSame(1, (int) $pedido->meli_order_status_id);
        $this->assertFalse(Sale::where('meli_order_id', $pedido->id)->exists());

        Notification::assertSentTo($this->owner, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'Entró un pedido de Mercado Libre con un artículo que no está vinculado';
        });
    }

    public function test_un_pedido_cancelado_que_ya_tenia_venta_avisa_y_no_la_anula()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001003', 'paid', [['MLA111', 1, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001003')->first();
        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count());

        Notification::fake();

        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001003', 'cancelled', [['MLA111', 1, 15000]])]);
        $this->sincronizar();

        $this->assertSame('cancelled', $pedido->fresh()->status);
        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count(), 'La venta no se anula sola.');
        Notification::assertSentTo($this->owner, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'Se canceló en Mercado Libre un pedido que ya tenía venta';
        });

        // Una tercera corrida con el pedido igual de cancelado no vuelve a avisar.
        Notification::fake();
        $this->sincronizar();
        Notification::assertNothingSent();
    }

    public function test_un_pedido_anterior_a_la_conexion_no_genera_venta()
    {
        $this->conector_ml([], [
            'nickname' => 'VENDEDOR_PRUEBA', 'user_product_seller' => false, 'multiorigen' => false, 'site_id' => 'MLA',
            'conectado_en' => Carbon::now()->toIso8601String(),
        ]);
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]], [
            'date_created' => Carbon::now()->subDays(2)->toIso8601String(),
            'date_closed'  => Carbon::now()->subDays(2)->toIso8601String(),
        ])]);

        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();
        $this->assertNotNull($pedido, 'El pedido igual se importa.');
        $this->assertFalse(Sale::where('meli_order_id', $pedido->id)->exists(), 'Un pedido de antes de conectar ya lo atendió el comercio por fuera.');
    }

    public function test_el_comando_consulta_por_fecha_de_actualizacion_sin_filtrar_por_pagado_y_guarda_el_poll()
    {
        $connector = $this->conector_ml();
        $this->ml_devuelve_pedidos([]);

        Artisan::call('meli_sync_orders');

        $consultas = $this->pedidos_ml('GET', 'orders/search');
        $this->assertCount(1, $consultas);

        $url = urldecode($consultas[0]->url());
        $this->assertStringContainsString('seller='.self::SELLER_ID, $url);
        $this->assertStringContainsString('order.date_last_updated.from=', $url);
        $this->assertStringContainsString('sort=date_desc', $url);
        $this->assertStringNotContainsString('order.status', $url, 'Sin filtrar por paid: tienen que llegar las cancelaciones.');

        $meli = $connector->fresh()->extra_config['meli'];
        $this->assertNotEmpty($meli['ultimo_poll_pedidos']);
    }

    public function test_el_listado_no_consulta_a_ml_si_el_ultimo_poll_fue_hace_menos_de_un_minuto()
    {
        $this->conector_ml([], [
            'nickname' => 'VENDEDOR_PRUEBA', 'user_product_seller' => false, 'multiorigen' => false, 'site_id' => 'MLA',
            'conectado_en' => Carbon::now()->subDays(3)->toIso8601String(),
            'ultimo_poll_pedidos' => Carbon::now()->subSeconds(20)->toIso8601String(),
        ]);
        $this->ml_devuelve_pedidos([]);

        $this->getJson('/api/meli-order/from-date')->assertStatus(200);

        $this->assertCount(0, $this->pedidos_ml('GET', 'orders/search'));
    }

    public function test_el_listado_consulta_a_ml_si_el_ultimo_poll_es_viejo()
    {
        $this->conector_ml([], [
            'nickname' => 'VENDEDOR_PRUEBA', 'user_product_seller' => false, 'multiorigen' => false, 'site_id' => 'MLA',
            'conectado_en' => Carbon::now()->subDays(3)->toIso8601String(),
            'ultimo_poll_pedidos' => Carbon::now()->subMinutes(5)->toIso8601String(),
        ]);
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]])]);

        $respuesta = $this->getJson('/api/meli-order/from-date');

        $respuesta->assertStatus(200);
        $this->assertCount(1, $this->pedidos_ml('GET', 'orders/search'));
        $this->assertCount(1, $this->pedidos_locales('2000001001'));
    }
}
