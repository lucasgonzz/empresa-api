<?php

namespace Tests\Feature\Mostrador;

use App\Models\Buyer;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Services\Mostrador\RecolectorTienda;

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

        // Los dos estados que usa el ERP: 1 = Sin confirmar (OrderController::indexUnconfirmed).
        $sin_confirmar = OrderStatus::find(1);

        if (!$sin_confirmar) {
            $sin_confirmar = OrderStatus::forceCreate(['id' => 1, 'name' => 'Sin confirmar']);
        }

        $confirmado = OrderStatus::where('name', 'Confirmado')->first();

        if (!$confirmado) {
            $confirmado = OrderStatus::forceCreate(['name' => 'Confirmado']);
        }

        $martillo = $this->articulo('Martillo', ['stock' => 5, 'final_price' => 150]);
        $pinza    = $this->articulo('Pinza', ['stock' => 8, 'final_price' => 90]);
        $cuchara  = $this->articulo('Cuchara', ['stock' => 0, 'final_price' => 60]);

        $perez = $this->cliente('Pérez');
        $perez->saldo = 900;
        $perez->save();

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
        foreach ([
            [$this->ayer_a_las(9), 3000, $sin_confirmar->id, $ana->id],
            [$this->ayer_a_las(18), 2000, $confirmado->id, $beto->id],
            [$this->ayer->copy()->subDays(5)->setTime(12, 0), 1000, $confirmado->id, $beto->id],
            [$this->ayer->copy()->subDays(10)->setTime(12, 0), 7000, $confirmado->id, $beto->id],
        ] as $datos) {
            Order::create([
                'user_id'         => $this->comercio->id,
                'buyer_id'        => $datos[3],
                'total'           => $datos[1],
                'status'          => $datos[2] === $sin_confirmar->id ? 'unconfirmed' : 'confirmed',
                'deliver'         => 0,
                'order_status_id' => $datos[2],
                'created_at'      => $datos[0],
            ]);
        }

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

        // Beto: mira la pinza, la carretea y compra dos horas después.
        $v_beto = 'visitante-beto';

        $this->evento('product_view', $this->ayer_a_las(14), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'article_id' => $pinza->id, 'dwell_ms' => 5000,
        ]);
        $this->evento('cart_add', $this->ayer_a_las(15), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'article_id' => $pinza->id, 'quantity' => 1,
        ]);
        $this->evento('checkout_complete', $this->ayer_a_las(17), [
            'buyer_id' => $beto->id, 'visitor_id' => $v_beto, 'article_id' => $pinza->id, 'quantity' => 1, 'amount' => 90,
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

        $this->s = compact('martillo', 'pinza', 'cuchara', 'perez', 'ana', 'beto');
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

        // Los dos de ayer + el de hace cinco días; el de hace diez queda afuera.
        $this->assertSame(['cantidad' => 3, 'total' => 6000.0], $p['ultimos_7_dias']);
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
            ['article_id' => $this->s['martillo']->id, 'nombre' => 'Martillo', 'veces' => 1],
            ['article_id' => $this->s['pinza']->id, 'nombre' => 'Pinza', 'veces' => 1],
        ], $h['productos']['mas_agregados_al_carrito']);

        $this->assertSame([
            ['article_id' => $this->s['cuchara']->id, 'nombre' => 'Cuchara', 'vistas' => 2],
        ], $h['productos']['vistos_sin_stock']);
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

        // Ana miró el martillo y no lo compró; Beto compró la pinza y no aparece.
        $this->assertSame([[
            'buyer_id'        => $this->s['ana']->id,
            'nombre'          => 'Ana García',
            'article_id'      => $this->s['martillo']->id,
            'nombre_articulo' => 'Martillo',
            'vistas'          => 3,
            'tiempo_seg'      => 60,
            'precio'          => 150.0,
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
