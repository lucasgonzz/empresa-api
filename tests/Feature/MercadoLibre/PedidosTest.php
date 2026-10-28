<?php

namespace Tests\Feature\MercadoLibre;

use App\Models\Article;
use App\Models\MeliOrder;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SyncToMeliArticle;
use App\Notifications\GlobalNotification;
use App\Services\MercadoLibre\OrderDownloaderService;
use App\Services\MercadoLibre\VentaDelPedidoMeli;
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
        MeliOrder::where('meli_order_id', 'like', '30000000%')->delete();

        // Punto de partida propio: ningún otro artículo del dueño con las publicaciones del test
        // (la base del slot se usa para probar a mano y podría tener uno).
        Article::where('user_id', $this->owner->id)->whereIn('me_li_id', ['MLA111', 'MLA999'])->update(['me_li_id' => null]);

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

    /**
     * Arreglo 1 (revisión, 27/10/2026): venta automática y después el botón "Crear venta" de la
     * pantalla vieja → una sola venta y el stock bajó una sola vez.
     */
    public function test_crear_venta_a_mano_despues_de_la_automatica_no_duplica()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 2, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();
        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count());

        $respuesta = $this->postJson('/api/meli-order/create-sale/'.$pedido->id);

        $respuesta->assertStatus(200);
        $this->assertSame((int) $pedido->id, (int) $respuesta->json('model.id'));
        // Contrato con la SPA (Orders.vue): avisa "Este pedido ya tenía venta".
        $respuesta->assertJsonPath('ya_tenia_venta', true);
        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count(), 'El botón creó una segunda venta.');
        $this->assertEquals(8, (float) Article::find($this->articulo->id)->stock, 'El stock bajó dos veces.');
    }

    /**
     * Arreglo 1: la pantalla vieja pasa el pedido a Pendiente y otra vez a Confirmado después de la
     * venta automática → sigue habiendo una sola venta.
     */
    public function test_confirmar_por_put_despues_de_la_automatica_no_duplica()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 2, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();

        $this->putJson('/api/meli-order/'.$pedido->id, ['notes' => null, 'address_id' => null, 'meli_order_status_id' => 1])->assertStatus(200);
        $this->putJson('/api/meli-order/'.$pedido->id, ['notes' => null, 'address_id' => null, 'meli_order_status_id' => 2])
            ->assertStatus(200)
            ->assertJsonPath('ya_tenia_venta', true);

        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count(), 'Confirmar por PUT creó una segunda venta.');
        $this->assertEquals(8, (float) Article::find($this->articulo->id)->stock, 'El stock bajó dos veces.');
    }

    /**
     * Arreglo 1: sin venta automática, confirmar por PUT y además apretar "Crear venta" → una venta.
     */
    public function test_confirmar_y_crear_venta_a_mano_dan_una_sola_venta()
    {
        config(['services.mercadolibre.crear_venta_automatica' => false]);
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 2, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();
        $this->assertSame(0, Sale::where('meli_order_id', $pedido->id)->count());

        $this->putJson('/api/meli-order/'.$pedido->id, ['notes' => null, 'address_id' => null, 'meli_order_status_id' => 2])
            ->assertStatus(200)
            ->assertJsonPath('ya_tenia_venta', false);
        $this->postJson('/api/meli-order/create-sale/'.$pedido->id)
            ->assertStatus(200)
            ->assertJsonPath('ya_tenia_venta', true);

        $this->assertSame(1, Sale::where('meli_order_id', $pedido->id)->count());
        $this->assertSame(2, (int) $pedido->fresh()->meli_order_status_id);
        $this->assertEquals(8, (float) Article::find($this->articulo->id)->stock);
    }

    /**
     * Revisión de merge (27/10/2026): con `CACHE_DRIVER=array` (clientes viejos) el `Cache::lock` no
     * cubre dos procesos. La venta toma la FILA del pedido con `lockForUpdate` adentro de la
     * transacción y recién ahí re-chequea que no tenga venta. En un solo proceso no se pueden cruzar
     * dos transacciones, así que se verifica el orden de las consultas: `select ... for update`
     * sobre `meli_orders`, después el re-chequeo de la venta, después el alta de la venta.
     */
    public function test_la_venta_toma_la_fila_del_pedido_con_for_update_antes_de_rechequear()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]])]);

        $consultas = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$consultas) {
            $consultas[] = strtolower($query->sql);
        });

        $this->sincronizar();

        $bloqueo = null;
        $rechequeo = null;
        $alta = null;
        foreach ($consultas as $i => $sql) {
            if (is_null($bloqueo) && strpos($sql, 'from `meli_orders`') !== false && strpos($sql, 'for update') !== false) {
                $bloqueo = $i;
            }
            if (!is_null($bloqueo) && is_null($rechequeo) && $i > $bloqueo && strpos($sql, 'from `sales`') !== false && strpos($sql, 'meli_order_id') !== false) {
                $rechequeo = $i;
            }
            if (is_null($alta) && strpos($sql, 'insert into `sales`') !== false) {
                $alta = $i;
            }
        }

        $this->assertNotNull($bloqueo, 'La venta no tomó la fila del pedido con lockForUpdate.');
        $this->assertNotNull($rechequeo, 'No se re-chequeó la venta después de tomar la fila.');
        $this->assertNotNull($alta);
        $this->assertLessThan($alta, $rechequeo, 'El re-chequeo tiene que ir antes del alta de la venta.');
    }

    /**
     * Arreglo 4 (revisión, 27/10/2026): el mismo ítem en dos renglones del pedido (2 + 3 unidades)
     * se suma: la venta lleva 5, el stock baja 5 y el total coincide.
     */
    public function test_el_mismo_item_en_dos_renglones_suma_las_cantidades()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [
            ['MLA111', 2, 15000],
            ['MLA111', 3, 14000],
        ])]);

        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001001')->first();

        $renglon = $pedido->articles()->where('articles.id', $this->articulo->id)->first();
        $this->assertEquals(5, (float) $renglon->pivot->amount);
        // Precio unitario ponderado: (2 x 15000 + 3 x 14000) / 5 = 14400.
        $this->assertEquals(14400, (float) $renglon->pivot->price);

        $venta = Sale::where('meli_order_id', $pedido->id)->first();
        $this->assertNotNull($venta);
        $this->assertEquals(72000, (float) $venta->total);
        $this->assertEquals(5, (float) $venta->articles()->where('articles.id', $this->articulo->id)->first()->pivot->amount);
        $this->assertEquals(5, (float) Article::find($this->articulo->id)->stock, 'El stock tiene que bajar 5 (2 + 3).');
    }

    /**
     * Arreglo 8: si la consulta llega al tope de pedidos por corrida y ML todavía tiene más, se
     * avisa al dueño UNA vez (y se vuelve a avisar recién después de una consulta completa).
     */
    public function test_si_la_consulta_queda_cortada_por_el_tope_avisa_una_vez()
    {
        config(['services.mercadolibre.max_pedidos_por_consulta' => 50]);
        $this->conector_ml();

        $pedidos = [];
        for ($i = 0; $i < 50; $i++) {
            $pedidos[] = $this->pedido_ml((string) (3000000000 + $i), 'paid', []);
        }
        $this->fake_ml([]);
        $this->rutas_ml['GET orders/search'] = [200, ['results' => $pedidos, 'paging' => ['total' => 120, 'offset' => 0, 'limit' => 50]]];

        $this->sincronizar();

        $this->assertCount(1, $this->pedidos_ml('GET', 'orders/search'), 'Con el tope en 50 se pide una sola página.');
        Notification::assertSentTo($this->owner, GlobalNotification::class, function ($notificacion) {
            return strpos($notificacion->message_text, 'más de 50 pedidos de Mercado Libre') !== false;
        });

        Notification::fake();
        $this->sincronizar();
        Notification::assertNothingSent();

        // Una consulta completa rearma el aviso.
        $this->rutas_ml['GET orders/search'] = [200, ['results' => [], 'paging' => ['total' => 0, 'offset' => 0, 'limit' => 50]]];
        $this->sincronizar();
        $this->rutas_ml['GET orders/search'] = [200, ['results' => $pedidos, 'paging' => ['total' => 120, 'offset' => 0, 'limit' => 50]]];
        $this->sincronizar();
        Notification::assertSentTo($this->owner, GlobalNotification::class);
    }

    /**
     * Arreglo 8: las fechas de ML (con su propio huso, p. ej. -04:00) se guardan en la hora de la
     * app (Argentina, -03:00).
     */
    public function test_las_fechas_de_ml_se_guardan_en_la_hora_de_la_app()
    {
        config(['services.mercadolibre.crear_venta_automatica' => false]);
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'cancelled', [['MLA111', 1, 15000]], [
            'date_created'  => '2026-10-27T10:00:00.000-04:00',
            'date_closed'   => '2026-10-27T10:05:00.000-04:00',
            'cancel_detail' => ['group' => 'buyer', 'code' => 'BC', 'description' => 'Cancelado', 'requested_by' => 'buyer', 'date' => '2026-10-27T12:00:00.000-04:00'],
        ])]);

        $this->sincronizar();

        $fila = \Illuminate\Support\Facades\DB::table('meli_orders')->where('meli_order_id', '2000001001')->where('user_id', $this->owner->id)->first();
        $this->assertSame('2026-10-27 11:00:00', $fila->meli_created_at);
        $this->assertSame('2026-10-27 11:05:00', $fila->meli_closed_at);

        $cancelacion = \Illuminate\Support\Facades\DB::table('meli_order_cancel_details')->where('meli_order_id', $fila->id)->first();
        $this->assertSame('2026-10-27 13:00:00', $cancelacion->date);
    }

    /**
     * Arreglo 8: si el pedido ya existe con el mismo `meli_order_id` (lo trajo otro proceso, o es
     * de otro comercio de la base compartida), la violación del único no se le notifica al dueño
     * con el SQL ni pisa la fila ajena.
     */
    public function test_un_pedido_que_ya_trajo_otro_proceso_no_notifica_el_sql()
    {
        config(['services.mercadolibre.crear_venta_automatica' => false]);
        $otro = \App\Models\User::create([
            'name'     => 'Otro comercio pedidos ML',
            'email'    => 'ml-pedidos-'.uniqid().'@test.local',
            'password' => \Illuminate\Support\Facades\Hash::make('secret'),
        ]);
        $ajeno = MeliOrder::create(['meli_order_id' => '2000001001', 'user_id' => $otro->id, 'status' => 'paid', 'meli_order_status_id' => 1]);

        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]])]);

        $this->sincronizar();

        Notification::assertNothingSent();
        $this->assertSame((int) $otro->id, (int) $ajeno->fresh()->user_id);
    }

    /**
     * Arreglo 8: `guardar_extra_meli` relee `extra_config` de la base y mezcla solo sus claves:
     * no pisa lo que otro proceso escribió mientras tanto.
     */
    public function test_guardar_el_poll_no_pisa_lo_que_otro_proceso_escribio_en_extra_config()
    {
        $connector = $this->conector_ml();
        $this->ml_devuelve_pedidos([]);

        $service = new OrderDownloaderService($this->owner->id);

        // Otro proceso (el callback, la cuenta) escribe mientras tanto.
        $fresco = $connector->fresh();
        $extra = $fresco->extra_config;
        $extra['meli']['nickname'] = 'NICK_NUEVO';
        $extra['otra_cosa'] = 'se queda';
        $fresco->extra_config = $extra;
        $fresco->save();

        $service->sincronizar_pedidos_recientes();

        $final = $connector->fresh()->extra_config;
        $this->assertSame('NICK_NUEVO', $final['meli']['nickname']);
        $this->assertSame('se queda', $final['otra_cosa']);
        $this->assertNotEmpty($final['meli']['ultimo_poll_pedidos']);
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

    /**
     * Misión mercado-libre-pruebas (27/10/2026): "Crear venta" sobre un pedido cancelado en Mercado
     * Libre creaba la venta igual y bajaba el stock de algo que no se vendió. Ahora 422 con un
     * mensaje legible (la SPA lo muestra tal cual) y no se toca nada.
     */
    public function test_crear_venta_a_mano_de_un_pedido_cancelado_se_rechaza_sin_tocar_el_stock()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001002', 'cancelled', [['MLA111', 2, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001002')->first();
        $this->assertSame(0, Sale::where('meli_order_id', $pedido->id)->count(), 'Un pedido cancelado no genera venta automática.');

        $respuesta = $this->postJson('/api/meli-order/create-sale/'.$pedido->id);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('message', 'Este pedido está cancelado en Mercado Libre: no se le crea venta.');
        $this->assertSame(0, Sale::where('meli_order_id', $pedido->id)->count(), 'El botón creó la venta de un pedido cancelado.');
        $this->assertEquals(10, (float) Article::find($this->articulo->id)->stock, 'El stock bajó por un pedido cancelado.');
    }

    /**
     * Mismo rechazo para "cancelándose" (la cancelación está en curso) e "inválido", con el motivo
     * de cada uno en el mensaje.
     */
    public function test_crear_venta_a_mano_se_rechaza_tambien_cancelandose_o_invalido()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([
            $this->pedido_ml('2000001002', 'pending_cancel', [['MLA111', 1, 15000]]),
            $this->pedido_ml('2000001003', 'invalid', [['MLA111', 1, 15000]]),
        ]);
        $this->sincronizar();

        foreach (['2000001002' => 'se está cancelando', '2000001003' => 'figura como inválido'] as $numero => $motivo) {
            $pedido = $this->pedidos_locales($numero)->first();
            $this->assertNotNull($pedido, 'La consulta tenía que traer el pedido '.$numero.'.');

            $respuesta = $this->postJson('/api/meli-order/create-sale/'.$pedido->id);

            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('message', 'Este pedido '.$motivo.' en Mercado Libre: no se le crea venta.');
            $this->assertSame(0, Sale::where('meli_order_id', $pedido->id)->count());
        }

        $this->assertEquals(10, (float) Article::find($this->articulo->id)->stock, 'El stock bajó por pedidos que no se vendieron.');
    }

    /**
     * El servicio de la venta (el único camino que la crea) vuelve a mirar el estado con la fila
     * tomada: un llamador que no lo chequeó (o que lo chequeó antes de que la consulta periódica lo
     * cancelara) no consigue venta de un pedido cancelado.
     */
    public function test_el_servicio_no_crea_la_venta_de_un_pedido_cancelado_aunque_el_llamador_no_lo_mire()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001002', 'cancelled', [['MLA111', 2, 15000]])]);
        $this->sincronizar();

        $pedido = $this->pedidos_locales('2000001002')->first();

        $creada = VentaDelPedidoMeli::generar_si_falta($pedido, new Controller(), $this->owner);

        $this->assertFalse($creada);
        $this->assertSame(0, Sale::where('meli_order_id', $pedido->id)->count());
        $this->assertEquals(10, (float) Article::find($this->articulo->id)->stock);
    }

    /**
     * La lupa de "Estado" de Pedidos busca por lo que la tabla MUESTRA ("Pagado", "Cancelado"), no
     * por el valor de Mercado Libre (`paid`, `cancelled`): MeliOrder::columnas_mostradas_para_filtros().
     */
    public function test_el_filtro_de_estado_de_pedidos_busca_por_lo_que_se_ve()
    {
        $this->conector_ml();
        $this->ml_devuelve_pedidos([
            $this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]]),
            $this->pedido_ml('2000001002', 'cancelled', [['MLA111', 1, 15000]]),
        ]);
        $this->sincronizar();

        $pagado = (int) $this->pedidos_locales('2000001001')->first()->id;
        $cancelado = (int) $this->pedidos_locales('2000001002')->first()->id;

        $con_pagado = $this->buscar_ids('meli_order', [['key' => 'status', 'type' => 'text', 'que_contenga' => 'pagado']]);
        $this->assertContains($pagado, $con_pagado);
        $this->assertNotContains($cancelado, $con_pagado);

        $con_cancel = $this->buscar_ids('meli_order', [['key' => 'status', 'type' => 'text', 'que_contenga' => 'cancel']]);
        $this->assertContains($cancelado, $con_cancel);
        $this->assertNotContains($pagado, $con_cancel);
    }

    /**
     * Lo mismo en Publicaciones (salientes): "Con error" y "Con aviso" (una exitosa con un aviso en
     * el detalle) se encuentran por lo que se ve. SyncToMeliArticle::columnas_mostradas_para_filtros().
     */
    public function test_el_filtro_de_estado_de_publicaciones_busca_por_lo_que_se_ve()
    {
        SyncToMeliArticle::where('user_id', $this->owner->id)->delete();
        $base = ['article_id' => $this->articulo->id, 'user_id' => $this->owner->id];
        $con_error = (int) SyncToMeliArticle::create($base + ['status' => 'error', 'error_message' => 'Mercado Libre rechazó la publicación: falta la marca.'])->id;
        $con_aviso = (int) SyncToMeliArticle::create($base + ['status' => 'exitosa', 'error_message' => 'Aviso: el precio no se pudo actualizar.'])->id;
        $actualizada = (int) SyncToMeliArticle::create($base + ['status' => 'exitosa'])->id;

        $this->assertSame([$con_error], $this->buscar_ids('sync_to_meli_article', [['key' => 'status', 'type' => 'text', 'que_contenga' => 'con error']]));
        $this->assertSame([$con_aviso], $this->buscar_ids('sync_to_meli_article', [['key' => 'status', 'type' => 'text', 'que_contenga' => 'con aviso']]));
        $this->assertSame([$actualizada], $this->buscar_ids('sync_to_meli_article', [['key' => 'status', 'type' => 'text', 'igual_que' => 'Actualizada']]));
    }

    /**
     * Ids que devuelve `POST global-search/{modelo}` con los filtros de columna que manda la lupa.
     *
     * @param string $modelo
     * @param array $filtros
     * @return array<int, int>
     */
    protected function buscar_ids($modelo, array $filtros)
    {
        $respuesta = $this->postJson('api/global-search/'.$modelo.'?page=1', [
            'query_value'    => '',
            'props'          => [],
            'relation_props' => [],
            'extra_filters'  => [],
            'filters'        => $filtros,
            'conector'       => 'or',
            'per_page'       => 200,
        ]);

        $this->assertSame(200, $respuesta->getStatusCode(), 'global-search/'.$modelo.' dio '.$respuesta->getStatusCode().': '.$respuesta->getContent());

        $ids = [];
        foreach ($respuesta->json('models.data') as $encontrado) {
            $ids[] = (int) $encontrado['id'];
        }

        return $ids;
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

    /**
     * Arreglo 2 de la revisión de merge: los pedidos pagos que quedan Pendientes por ser
     * anteriores a la conexión se le avisan al dueño UNA vez, con cuántos son.
     */
    public function test_los_pedidos_pagos_anteriores_a_la_conexion_se_avisan_una_vez()
    {
        $this->conector_ml([], [
            'nickname' => 'VENDEDOR_PRUEBA', 'user_product_seller' => false, 'multiorigen' => false, 'site_id' => 'MLA',
            'conectado_en' => Carbon::now()->toIso8601String(),
        ]);
        $viejo = [
            'date_created' => Carbon::now()->subDays(2)->toIso8601String(),
            'date_closed'  => Carbon::now()->subDays(2)->toIso8601String(),
        ];
        $this->ml_devuelve_pedidos([
            $this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]], $viejo),
            $this->pedido_ml('2000001002', 'paid', [['MLA111', 2, 15000]], $viejo),
        ]);

        $this->sincronizar();

        Notification::assertSentTo($this->owner, GlobalNotification::class, function ($notificacion) {
            return strpos($notificacion->message_text, '2 pedidos de Mercado Libre anteriores a la conexión quedaron Pendientes') !== false;
        });

        // La consulta siguiente los vuelve a traer (ML los actualizó): no se avisan de nuevo.
        Notification::fake();
        $this->sincronizar();
        Notification::assertNothingSent();
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

    /**
     * Arreglo 10: el listado usa el MISMO gate que el Kernel y que encolar (la extensión). Con el
     * USA_MERCADO_LIBRE viejo prendido pero sin la extensión, no consulta a ML.
     */
    public function test_el_listado_sin_la_extension_no_consulta_aunque_este_el_env()
    {
        $this->forzar_env_usa_mercado_libre('true');
        $this->extension_usa_mercado_libre(false);
        $this->conector_ml();
        $this->ml_devuelve_pedidos([$this->pedido_ml('2000001001', 'paid', [['MLA111', 1, 15000]])]);

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
