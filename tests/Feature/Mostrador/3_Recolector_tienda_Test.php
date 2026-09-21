<?php

namespace Tests\Feature\Mostrador;

use App\Models\Buyer;
use App\Models\CreditAccount;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Mostrador\RecolectorTienda;
use Illuminate\Support\Facades\DB;

/**
 * Misión modulo-ia-mostrador — P3: el recolector de "Tu tienda".
 *
 * Un comercio sin tienda (sin URL y sin pedidos) devuelve aplica: false. Con tienda,
 * sobre dos pedidos de ayer (uno sin confirmar), uno de hace cinco días y otro de hace
 * diez, dos compradores (uno asociado a un cliente del ERP) y un visitante anónimo con
 * sus eventos de tracking, cada número es el sembrado: vistas, tiempo en pantalla,
 * carrito abandonado (y el que sí convirtió, que no aparece), búsquedas sin
 * resultados, quién miró y no compró y el cliente del local que anda por la tienda.
 */
class Recolector_tienda_Test extends MostradorTestCase
{
    /** @var array Lo sembrado */
    protected $s = [];

    /**
     * Siembra la tienda: pedidos, compradores y eventos.
     *
     * @return void
     */
    protected function sembrar_la_tienda()
    {
        $this->comercio->online = 'https://ferreteria-mostrador.com.ar';
        $this->comercio->save();

        // La tienda escribe buyer_tracking_events solo con esta extensión.
        $this->dar_extension(null, 'tracking_buyers');

        // Los dos estados que usa el ERP: 1 = Sin confirmar (OrderController::indexUnconfirmed).
        $sin_confirmar = OrderStatus::find(1);

        if (!$sin_confirmar) {
            $sin_confirmar = OrderStatus::forceCreate(['id' => 1, 'name' => 'Sin confirmar']);
        }

        $confirmado = OrderStatus::where('name', 'Confirmado')->first();

        if (!$confirmado) {
            $confirmado = OrderStatus::forceCreate(['name' => 'Confirmado']);
        }

        $cancelado = OrderStatus::where('name', 'Cancelado')->first();

        if (!$cancelado) {
            $cancelado = OrderStatus::forceCreate(['name' => 'Cancelado']);
        }

        $martillo = $this->articulo('Martillo', ['stock' => 5, 'final_price' => 150]);
        $pinza    = $this->articulo('Pinza', ['stock' => 8, 'final_price' => 90]);
        $cuchara  = $this->articulo('Cuchara', ['stock' => 0, 'final_price' => 60]);

        // La deuda de Pérez vive en credit_accounts (la fuente de verdad); clients.saldo
        // es una columna muerta que el sistema ya no escribe.
        $perez = $this->cliente('Pérez');

        CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $perez->id,
            'saldo'      => 900,
            'moneda_id'  => 1,
            'user_id'    => $this->comercio->id,
        ]);

        $ana = Buyer::create([
            'name'                     => 'Ana',
            'surname'                  => 'García',
            'user_id'                  => $this->comercio->id,
            'comercio_city_client_id'  => $perez->id,
            'created_at'               => $this->ayer_a_las(8),
        ]);

        $beto = Buyer::create([
            'name'       => 'Beto',
            'user_id'    => $this->comercio->id,
            'created_at' => $this->ayer->copy()->subDays(10),
        ]);

        // Pedidos: dos ayer, uno hace cinco días, uno hace diez.
        $pedidos = [];

        foreach ([
            [$this->ayer_a_las(9), 3000, $sin_confirmar->id, $ana->id],
            [$this->ayer_a_las(18), 2000, $confirmado->id, $beto->id],
            [$this->ayer->copy()->subDays(5)->setTime(12, 0), 1000, $confirmado->id, $beto->id],
            [$this->ayer->copy()->subDays(10)->setTime(12, 0), 7000, $confirmado->id, $beto->id],
        ] as $datos) {
            $pedidos[] = Order::create([
                'user_id'         => $this->comercio->id,
                'buyer_id'        => $datos[3],
                'total'           => $datos[1],
                'status'          => $datos[2] === $sin_confirmar->id ? 'unconfirmed' : 'confirmed',
                'deliver'         => 0,
                'order_status_id' => $datos[2],
                'created_at'      => $datos[0],
            ]);
        }

        // Un pedido de ayer CANCELADO (por el estado del ERP y por el enum de la tienda):
        // no suma en ningún lado.
        Order::create([
            'user_id'         => $this->comercio->id,
            'buyer_id'        => $beto->id,
            'total'           => 99999,
            'status'          => 'canceled',
            'deliver'         => 0,
            'order_status_id' => $cancelado->id,
            'created_at'      => $this->ayer_a_las(20),
        ]);

        // El pedido de Beto de ayer a las 18 lleva la pinza en sus renglones (article_order).
        $pedido_beto = $pedidos[1];

        DB::table('article_order')->insert([
            'article_id' => $pinza->id,
            'order_id'   => $pedido_beto->id,
            'amount'     => 1,
            'price'      => 90,
            'created_at' => $this->ayer_a_las(18),
            'updated_at' => $this->ayer_a_las(18),
        ]);

        // Una compra de Pérez en el local hace tres días.
        $this->venta($this->ayer->copy()->subDays(3)->setTime(12, 0), [[$pinza, 1, 90]], ['client_id' => $perez->id]);

        // Ana: mira el martillo tres veces, lo agrega al carrito y no compra.
        $v_ana = 'visitante-ana';

        foreach ([10000, 20000, 30000] as $indice => $dwell) {
            $this->evento('product_view', $this->ayer_a_las(10 + $indice), [
                'buyer_id' => $ana->id, 'visitor_id' => $v_ana, 'article_id' => $martillo->id, 'dwell_ms' => $dwell,
            ]);
        }

        $this->evento('cart_add', $this->ayer_a_las(12), [
            'buyer_id' => $ana->id, 'visitor_id' => $v_ana, 'article_id' => $martillo->id, 'quantity' => 2,
        ]);

        // Beto: mira la pinza, la carretea y compra dos horas después. El checkout_complete
        // va COMO LO EMITE LA TIENDA (tienda-spa, mixins/cart.js): order_id y amount del
        // pedido, sin article_id; el artículo comprado se sabe por los renglones del pedido.
        $v_beto = 'visitante-beto';

        $this->evento('product_view', $this->ayer_a_las(14), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'article_id' => $pinza->id, 'dwell_ms' => 5000,
        ]);
        $this->evento('cart_add', $this->ayer_a_las(15), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'article_id' => $pinza->id, 'quantity' => 1, 'amount' => 90,
        ]);
        $this->evento('checkout_complete', $this->ayer_a_las(17), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'order_id' => $pedido_beto->id, 'amount' => 90,
        ]);

        // Un visitante anónimo: mira dos veces la cuchara (sin stock) y busca.
        $v_anon = 'visitante-anonimo';

        foreach ([9, 11] as $hora) {
            $this->evento('product_view', $this->ayer_a_las($hora), [
                'visitor_id' => $v_anon, 'article_id' => $cuchara->id, 'dwell_ms' => 4000,
            ]);
        }

        foreach ([9, 10] as $hora) {
            $this->evento('search', $this->ayer_a_las($hora), [
                'visitor_id' => $v_anon, 'search_term' => 'taladro', 'results_count' => 0,
            ]);
        }

        $this->evento('search', $this->ayer_a_las(11), [
            'visitor_id' => $v_anon, 'search_term' => 'martillo', 'results_count' => 4,
        ]);

        $this->s = compact('martillo', 'pinza', 'cuchara', 'perez', 'ana', 'beto', 'pedido_beto');
    }

    /**
     * @group mostrador
     * @test
     */
    public function sin_tienda_no_aplica()
    {
        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertFalse($h['aplica']);
        $this->assertSame($this->ayer->format('Y-m-d'), $h['fecha']);
        $this->assertNotEmpty($h['motivo']);
        $this->assertArrayNotHasKey('pedidos', $h);
    }

    /**
     * @group mostrador
     * @test
     */
    public function los_pedidos_de_ayer_los_pendientes_y_los_de_la_semana()
    {
        $this->sembrar_la_tienda();

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertTrue($h['aplica']);
        $this->assertSame('https://ferreteria-mostrador.com.ar', $h['url_tienda']);

        $p = $h['pedidos'];
        $this->assertSame(2, $p['ayer']['cantidad']);
        $this->assertEquals(5000.00, $p['ayer']['total']);
        $this->assertEqualsCanonicalizing([
            ['estado' => 'Sin confirmar', 'cantidad' => 1],
            ['estado' => 'Confirmado', 'cantidad' => 1],
        ], $p['ayer']['por_estado']);

        $this->assertSame(1, $p['pendientes_de_confirmar']['cantidad']);
        $this->assertEquals(3000.00, $p['pendientes_de_confirmar']['total']);
        // El pendiente es de ayer a las 9: al menos 15 horas hasta hoy a las 0.
        $this->assertGreaterThanOrEqual(15, $p['pendientes_de_confirmar']['mas_viejo_horas']);

        // Los dos de ayer + el de hace cinco días; el de hace diez queda afuera, y el
        // cancelado de ayer no cuenta en ningún bloque.
        $this->assertSame(['cantidad' => 3, 'total' => 6000.0], $p['ultimos_7_dias']);
        $this->assertTrue($h['tracking_activo']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function compradores_y_productos_del_dia()
    {
        $this->sembrar_la_tienda();

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertSame(['visitantes_ayer' => 3, 'activos_ayer' => 2, 'nuevos_ayer' => 1], $h['compradores']);

        $mas_vistos = $h['productos']['mas_vistos'];
        $this->assertSame([$this->s['martillo']->id, $this->s['cuchara']->id, $this->s['pinza']->id], array_column($mas_vistos, 'article_id'));
        $this->assertSame(3, $mas_vistos[0]['vistas']);
        $this->assertSame(20, $mas_vistos[0]['tiempo_promedio_seg']);
        $this->assertEquals(5.0, $mas_vistos[0]['stock']);
        $this->assertEquals(150.00, $mas_vistos[0]['precio']);
        $this->assertNull($mas_vistos[0]['imagen_url']);

        $this->assertSame([
            ['article_id' => $this->s['martillo']->id, 'nombre' => 'Martillo', 'veces' => 1, 'imagen_url' => null],
            ['article_id' => $this->s['pinza']->id, 'nombre' => 'Pinza', 'veces' => 1, 'imagen_url' => null],
        ], $h['productos']['mas_agregados_al_carrito']);

        $this->assertSame([
            ['article_id' => $this->s['cuchara']->id, 'nombre' => 'Cuchara', 'vistas' => 2, 'imagen_url' => null],
        ], $h['productos']['vistos_sin_stock']);
    }

    /**
     * stock = null es "no controla stock" y la tienda lo vende como disponible: un
     * artículo así, por más que lo miren, no está "visto sin stock".
     *
     * @group mostrador
     * @test
     */
    public function un_articulo_sin_control_de_stock_no_esta_visto_sin_stock()
    {
        $this->comercio->online = 'https://ferreteria-mostrador.com.ar';
        $this->comercio->save();
        $this->dar_extension(null, 'tracking_buyers');

        $balanza = $this->articulo('Balanza', ['stock' => null]);
        $cuchara = $this->articulo('Cuchara', ['stock' => 0]);

        foreach ([$balanza, $cuchara] as $articulo) {
            $this->evento('product_view', $this->ayer_a_las(10), ['visitor_id' => 'visitante-x', 'article_id' => $articulo->id]);
        }

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([$cuchara->id], array_column($h['productos']['vistos_sin_stock'], 'article_id'));
        $this->assertCount(2, $h['productos']['mas_vistos']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function el_carrito_abandonado_es_el_de_ana_y_no_el_de_beto_que_compro()
    {
        $this->sembrar_la_tienda();

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertCount(1, $h['carritos_abandonados']);

        $carrito = $h['carritos_abandonados'][0];
        $this->assertSame($this->s['ana']->id, $carrito['buyer_id']);
        $this->assertSame('Ana García', $carrito['nombre']);
        $this->assertSame($this->s['perez']->id, $carrito['client_id']);
        $this->assertSame([['nombre' => 'Martillo', 'cantidad' => 2]], $carrito['articulos']);
        // Sin importe en el evento: 2 × precio actual (150).
        $this->assertEquals(300.00, $carrito['monto_estimado']);
        $this->assertGreaterThanOrEqual(12, $carrito['hace_horas']);
        // La ventana de 24 horas del último agregado (ayer a las 12) está abierta solo si
        // todavía no son las 12 de hoy.
        $this->assertSame(now()->lt($this->ayer_a_las(12)->addHours(24)), $carrito['ventana_abierta']);
    }

    /**
     * Un carrito que se vació con cart_remove no está abandonado; un checkout_complete
     * anterior al agregado (el de un carrito previo) no lo cierra; y un comprador que
     * agregó anónimo y compró logueado (mismo visitor_id) tampoco aparece.
     *
     * @group mostrador
     * @test
     */
    public function un_carrito_vaciado_no_esta_abandonado_y_un_checkout_anterior_no_lo_cierra()
    {
        $this->comercio->online = 'https://ferreteria-mostrador.com.ar';
        $this->comercio->save();
        $this->dar_extension(null, 'tracking_buyers');

        $martillo = $this->articulo('Martillo', ['final_price' => 150]);
        $pinza    = $this->articulo('Pinza', ['final_price' => 90]);

        // Visitante 1: agrega tres martillos, quita dos, después quita la línea entera.
        $this->evento('cart_add', $this->ayer_a_las(10), ['visitor_id' => 'v-uno', 'article_id' => $martillo->id, 'quantity' => 3, 'amount' => 450]);
        $this->evento('cart_remove', $this->ayer_a_las(11), ['visitor_id' => 'v-uno', 'article_id' => $martillo->id, 'quantity' => 2]);
        $this->evento('cart_remove', $this->ayer_a_las(12), ['visitor_id' => 'v-uno', 'article_id' => $martillo->id]);

        // Visitante 2: cerró un carrito viejo a las 9 y DESPUÉS agregó una pinza: abandonada.
        $this->evento('checkout_complete', $this->ayer_a_las(9), ['visitor_id' => 'v-dos', 'order_id' => 1, 'amount' => 500]);
        $this->evento('cart_add', $this->ayer_a_las(10), ['visitor_id' => 'v-dos', 'article_id' => $pinza->id, 'quantity' => 1]);

        // Visitante 3: agrega anónimo, se loguea (buyer) y compra desde el mismo visitor_id.
        $carla = Buyer::create(['name' => 'Carla', 'user_id' => $this->comercio->id]);
        $this->evento('cart_add', $this->ayer_a_las(14), ['visitor_id' => 'v-tres', 'article_id' => $martillo->id, 'quantity' => 1]);
        $this->evento('checkout_complete', $this->ayer_a_las(15), ['visitor_id' => 'v-tres', 'buyer_id' => $carla->id, 'order_id' => 2, 'amount' => 150]);

        // Visitante 4: agrega dos pinzas y quita una: queda una, abandonada por $90.
        $this->evento('cart_add', $this->ayer_a_las(16), ['visitor_id' => 'v-cuatro', 'article_id' => $pinza->id, 'quantity' => 2, 'amount' => 180]);
        $this->evento('cart_remove', $this->ayer_a_las(17), ['visitor_id' => 'v-cuatro', 'article_id' => $pinza->id, 'quantity' => 1]);

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $carritos = $h['carritos_abandonados'];
        $this->assertCount(2, $carritos);

        // Anónimos los dos: por monto, 90 y 90 → por orden de llegada (v-dos primero).
        $this->assertNull($carritos[0]['buyer_id']);
        $this->assertSame([['nombre' => 'Pinza', 'cantidad' => 1]], $carritos[0]['articulos']);
        $this->assertEquals(90.00, $carritos[0]['monto_estimado']);
        $this->assertSame([['nombre' => 'Pinza', 'cantidad' => 1]], $carritos[1]['articulos']);
        $this->assertEquals(90.00, $carritos[1]['monto_estimado']);
        $this->assertArrayHasKey('ventana_abierta', $carritos[0]);
    }

    /**
     * Sin la extensión tracking_buyers la tienda no escribe eventos: los bloques que
     * salen del tracking viajan null (no ceros), y pedidos y clientes del local siguen.
     *
     * @group mostrador
     * @test
     */
    public function sin_la_extension_tracking_buyers_los_bloques_de_tracking_van_null()
    {
        $this->comercio->online = 'https://ferreteria-mostrador.com.ar';
        $this->comercio->save();

        $confirmado = OrderStatus::where('name', 'Confirmado')->first() ?: OrderStatus::forceCreate(['name' => 'Confirmado']);

        $perez = $this->cliente('Pérez');
        CreditAccount::create(['model_name' => 'client', 'model_id' => $perez->id, 'saldo' => 450, 'moneda_id' => 1, 'user_id' => $this->comercio->id]);

        $ana = Buyer::create(['name' => 'Ana', 'user_id' => $this->comercio->id, 'comercio_city_client_id' => $perez->id]);

        Order::create([
            'user_id' => $this->comercio->id, 'buyer_id' => $ana->id, 'total' => 3000, 'status' => 'confirmed',
            'deliver' => 0, 'order_status_id' => $confirmado->id, 'created_at' => $this->ayer_a_las(9),
        ]);

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertTrue($h['aplica']);
        $this->assertFalse($h['tracking_activo']);

        foreach (['compradores', 'productos', 'carritos_abandonados', 'busquedas', 'vieron_y_no_compraron'] as $clave) {
            $this->assertArrayHasKey($clave, $h);
            $this->assertNull($h[$clave], $clave . ' tiene que viajar null sin tracking');
        }

        $this->assertSame(1, $h['pedidos']['ayer']['cantidad']);
        $this->assertEquals(3000.00, $h['pedidos']['ayer']['total']);

        // El cliente del local que hizo un pedido esta semana aparece aunque no haya tracking.
        $this->assertSame([[
            'buyer_id'            => $ana->id,
            'client_id'           => $perez->id,
            'nombre'              => 'Pérez',
            'deuda'               => 450.0,
            'ultima_compra_local' => null,
        ]], $h['clientes_del_local_en_la_tienda']);
    }

    /**
     * @group mostrador
     * @test
     */
    public function busquedas_quien_miro_sin_comprar_y_clientes_del_local()
    {
        $this->sembrar_la_tienda();

        $h = (new RecolectorTienda())->recolectar($this->comercio, $this->ayer);

        $this->assertSame([
            ['termino' => 'taladro', 'veces' => 2, 'resultados_promedio' => 0.0],
            ['termino' => 'martillo', 'veces' => 1, 'resultados_promedio' => 4.0],
        ], $h['busquedas']['mas_buscadas']);

        $this->assertSame([['termino' => 'taladro', 'veces' => 2]], $h['busquedas']['sin_resultados']);

        // Ana miró el martillo y no lo compró; Beto compró la pinza (el pedido de su
        // checkout_complete la trae en los renglones) y no aparece.
        $this->assertSame([[
            'buyer_id'        => $this->s['ana']->id,
            'nombre'          => 'Ana García',
            'article_id'      => $this->s['martillo']->id,
            'nombre_articulo' => 'Martillo',
            'vistas'          => 3,
            'tiempo_seg'      => 60,
            'precio'          => 150.0,
            'imagen_url'      => null,
        ]], $h['vieron_y_no_compraron']);

        $this->assertSame([[
            'buyer_id'            => $this->s['ana']->id,
            'client_id'           => $this->s['perez']->id,
            'nombre'              => 'Pérez',
            'deuda'               => 900.0,
            'ultima_compra_local' => $this->ayer->copy()->subDays(3)->format('Y-m-d'),
        ]], $h['clientes_del_local_en_la_tienda']);
    }
}
