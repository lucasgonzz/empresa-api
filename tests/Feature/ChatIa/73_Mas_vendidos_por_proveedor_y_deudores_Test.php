<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\VentasSinCobrarIaHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-altas-y-conversacion (16/10/2026) — D1: las dos preguntas del dueño que el
 * asistente leyó al revés por WhatsApp en demo3.
 *
 * 🔴 "¿QUÉ ES LO QUE MÁS VENDO DE FERRETERA DEL SUR?" no es una pregunta de compras: es el ranking
 * de lo que les vendí a mis clientes, acotado a los artículos cuyo proveedor (el de la ficha) es ese.
 * El proveedor se resuelve por nombre y, si no encaja uno solo, vuelve un error con las opciones: un
 * proveedor adivinado devuelve un ranking que parece bueno y es de otro.
 *
 * 🔴 "LOS QUE ME DEBEN MÁS DE 200 MIL HACE MÁS DE 20 DÍAS" = deuda TOTAL de 200 mil o más Y alguna
 * deuda de 20 días o más. El asistente filtraba "lo atrasado suma más de 200 mil" y contestaba que
 * no había ninguno; en demo3 eran 3. El fixture de abajo arma ese caso a mano, con el cliente que
 * separa las dos lecturas: poca deuda vieja y mucha reciente.
 */
class Mas_vendidos_por_proveedor_y_deudores_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->comercio = User::create([
            'name'     => 'Comercio D1',
            'email'    => 'd1-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio D1',
            'email'    => 'd1-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    // ------------------------------------------------------------------ lo que más vendo de un proveedor

    /**
     * El ranking con `proveedor` trae solo los artículos de ese proveedor, dice cuál se usó, y los
     * de otro proveedor (aunque se vendan más) no aparecen.
     *
     * @group chat-ia
     * @test
     */
    public function mas_vendidos_con_proveedor_trae_solo_los_articulos_de_ese_proveedor()
    {
        $del_sur = $this->proveedor('Ferretería del Sur');
        $norte = $this->proveedor('Bulonera Norte');

        $taladro = $this->articulo('Taladro D1', $del_sur);
        $mecha = $this->articulo('Mecha D1', $del_sur);
        $tornillo = $this->articulo('Tornillo D1', $norte);
        $suelto = $this->articulo('Cinta D1', null);

        // Lo del proveedor: mecha 5 unidades, taladro 2.
        $this->venta_con_renglon($mecha, 3, 100, 10);
        $this->venta_con_renglon($mecha, 2, 100, 40);
        $this->venta_con_renglon($taladro, 2, 5000, 15);

        // Lo que NO tiene que aparecer: otro proveedor y sin proveedor, los dos vendidos MÁS.
        $this->venta_con_renglon($tornillo, 50, 10, 5);
        $this->venta_con_renglon($suelto, 30, 10, 5);

        // Y uno de ese proveedor vendido fuera de la ventana (los últimos 2 meses).
        $this->venta_con_renglon($taladro, 9, 5000, 80);

        $desde = now()->subDays(60)->format('Y-m-d');
        $hasta = now()->format('Y-m-d');

        $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, $desde, $hasta, 0, 'Ferretería del Sur');

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals('Ferretería del Sur', $resultado['proveedor']);
        $this->assertEquals($del_sur->id, $resultado['proveedor_id']);
        $this->assertEquals(2, $resultado['articulos_con_ese_proveedor']);
        $this->assertStringContainsString('consultar_compras_a_un_proveedor', $resultado['criterio']);

        $nombres = array_column($resultado['articulos'], 'nombre');

        $this->assertEquals(['Mecha D1', 'Taladro D1'], $nombres);
        $this->assertEquals(5.0, $resultado['articulos'][0]['total_vendido']);
        $this->assertEquals(2.0, $resultado['articulos'][1]['total_vendido'], 'Las 9 de hace 80 días quedan afuera del período.');
        $this->assertEquals(10000.0, $resultado['articulos'][1]['total_en_pesos']);

        // Por palabras: "ferreteria sur" también lo encuentra.
        $por_palabras = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 90, null, null, 0, 'ferreteria sur');

        $this->assertArrayNotHasKey('error', $por_palabras, json_encode($por_palabras));
        $this->assertEquals($del_sur->id, $por_palabras['proveedor_id']);

        // Y la tool lo cablea: el handler pasa `proveedor` al helper.
        $this->assertEquals('string', $this->definicion('consultar_articulos_mas_vendidos')['input_schema']['properties']['proveedor']['type']);
        $handler = $this->handler('consultar_articulos_mas_vendidos');
        $por_tool = $handler(['dias' => 90, 'proveedor' => 'Bulonera Norte'], $this->comercio->id);

        $this->assertEquals('Bulonera Norte', $por_tool['proveedor']);
        $this->assertEquals(['Tornillo D1'], array_column($por_tool['articulos'], 'nombre'));
    }

    /**
     * 🔴 El caso real, con una letra de menos ("ferretera"): ningún LIKE lo encuentra, así que vuelve
     * un error con los PARECIDOS para que el modelo reintente con el nombre exacto. Y un nombre que
     * no se parece a nada devuelve la lista de proveedores. Nunca se adivina.
     *
     * @group chat-ia
     * @test
     */
    public function un_proveedor_que_no_encaja_devuelve_error_con_los_parecidos_o_la_lista()
    {
        $this->proveedor('Ferretería del Sur');
        $this->proveedor('Pinturas Oeste');

        // De otro comercio, con el nombre parecido: no puede aparecer como opción.
        $this->proveedor('Ferretería del Sur 2', $this->otro_comercio);

        $mal_escrito = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, 'ferretera del sur');

        $this->assertArrayHasKey('error', $mal_escrito);
        $this->assertArrayNotHasKey('articulos', $mal_escrito);
        $this->assertEquals('Ferretería del Sur', $mal_escrito['opciones'][0]);
        $this->assertNotContains('Pinturas Oeste', $mal_escrito['opciones']);
        $this->assertNotContains('Ferretería del Sur 2', $mal_escrito['opciones']);

        $inexistente = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, 'Maderera Kappa');

        $this->assertArrayHasKey('error', $inexistente);
        $this->assertStringContainsString('Los proveedores cargados son', $inexistente['error']);
        $this->assertEquals(['Ferretería del Sur', 'Pinturas Oeste'], $inexistente['opciones']);
    }

    /**
     * Si encaja más de uno, vuelven las opciones; el nombre exacto desempata.
     *
     * @group chat-ia
     * @test
     */
    public function un_proveedor_ambiguo_devuelve_las_opciones_y_el_nombre_exacto_desempata()
    {
        $corto = $this->proveedor('Distribuidora Centro');
        $this->proveedor('Distribuidora Centro Mayorista');

        $ambiguo = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, 'distribuidora');

        $this->assertArrayHasKey('error', $ambiguo);
        $this->assertEquals(['Distribuidora Centro', 'Distribuidora Centro Mayorista'], $ambiguo['opciones']);

        $exacto = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, 'Distribuidora Centro');

        $this->assertArrayNotHasKey('error', $exacto, json_encode($exacto));
        $this->assertEquals($corto->id, $exacto['proveedor_id']);
        $this->assertSame([], $exacto['articulos']);
        $this->assertArrayHasKey('nota', $exacto, 'Sin artículos asignados lo dice, en vez de una lista vacía muda.');
    }

    /**
     * Sin `proveedor` (o vacío) la salida es la de siempre: la lista pelada, con las mismas claves.
     *
     * @group chat-ia
     * @test
     */
    public function mas_vendidos_sin_proveedor_devuelve_la_lista_de_siempre()
    {
        $del_sur = $this->proveedor('Ferretería del Sur');

        $this->venta_con_renglon($this->articulo('Mecha D1', $del_sur), 3, 100, 2);
        $this->venta_con_renglon($this->articulo('Cinta D1', null), 1, 50, 2);

        foreach ([null, '', '   '] as $sin) {
            $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, $sin);

            $this->assertSame([0, 1], array_keys($resultado));
            $this->assertEquals(
                ['nombre', 'total_vendido', 'articulo_id', 'total_en_pesos', 'unidades_sin_precio', 'tiene_imagen'],
                array_keys($resultado[0])
            );
            $this->assertEquals('Mecha D1', $resultado[0]['nombre']);
        }

        $this->assertEquals(ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30), ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30, null, null, 0, null));
    }

    // ------------------------------------------------------------------ los que me deben más de X hace más de N días

    /**
     * 🔴 EL CASO DE DEMO3, ARMADO A MANO. "Los que me deben más de 200 mil hace más de 20 días":
     *
     *   - Gómez: 100 mil de hace 35 días + 428 mil de hace 5 → total 528 mil. ENTRA (y con la lectura
     *     vieja, "lo atrasado > 200 mil", quedaba afuera: es el cliente que separa las dos).
     *   - López: 503 mil de hace 25 días. ENTRA.
     *   - Sosa: 30 mil de hace 22 días + 250 mil de hace 2 → total 280 mil. ENTRA.
     *   - Pérez: 125 mil de hace 40 días, y nada más. NO entra: debe poco.
     *   - Ruiz: 900 mil de hace 3 días. NO entra: no tiene nada de más de 20 días.
     *
     * @group chat-ia
     * @test
     */
    public function los_que_me_deben_mas_de_x_hace_mas_de_n_dias_compara_contra_la_deuda_total()
    {
        $gomez = $this->cliente('Gomez D1');
        $lopez = $this->cliente('Lopez D1');
        $sosa = $this->cliente('Sosa D1');
        $perez = $this->cliente('Perez D1');
        $ruiz = $this->cliente('Ruiz D1');

        $this->venta_impaga($gomez, 100000, now()->subDays(35));
        $this->venta_impaga($gomez, 428000, now()->subDays(5));
        $this->venta_impaga($lopez, 503000, now()->subDays(25));
        $this->venta_impaga($sosa, 30000, now()->subDays(22));
        $this->venta_impaga($sosa, 250000, now()->subDays(2));
        $this->venta_impaga($perez, 125000, now()->subDays(40));
        $this->venta_impaga($ruiz, 900000, now()->subDays(3));

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 20, null, 200000);

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));

        $this->assertEquals(['Gomez D1', 'Lopez D1', 'Sosa D1'], array_column($resultado['clientes'], 'cliente'));
        $this->assertEquals(3, $resultado['clientes_con_deuda']);
        $this->assertEquals(3, $resultado['clientes_en_esta_lista']);
        $this->assertEquals(4, $resultado['clientes_con_deuda_sin_el_monto_minimo'], 'Pérez tiene deuda vieja pero chica; Ruiz no tiene deuda vieja.');
        $this->assertEquals(200000.0, $resultado['monto_minimo_pedido']);

        $por_nombre = [];
        foreach ($resultado['clientes'] as $cliente) {
            $por_nombre[$cliente['cliente']] = $cliente;
        }

        $this->assertEquals(528000.0, $por_nombre['Gomez D1']['deuda_total_en_pesos']);
        $this->assertEquals(100000.0, $por_nombre['Gomez D1']['deuda_atrasada_en_pesos']);
        $this->assertEquals(35, $por_nombre['Gomez D1']['dias_de_la_venta_mas_vieja']);

        $this->assertEquals(503000.0, $por_nombre['Lopez D1']['deuda_total_en_pesos']);
        $this->assertEquals(503000.0, $por_nombre['Lopez D1']['deuda_atrasada_en_pesos']);
        $this->assertEquals(25, $por_nombre['Lopez D1']['dias_de_la_venta_mas_vieja']);

        $this->assertEquals(280000.0, $por_nombre['Sosa D1']['deuda_total_en_pesos']);
        $this->assertEquals(30000.0, $por_nombre['Sosa D1']['deuda_atrasada_en_pesos']);
        $this->assertEquals(22, $por_nombre['Sosa D1']['dias_de_la_venta_mas_vieja']);

        // Las claves de siempre por cliente siguen siendo las del conjunto pedido (lo atrasado).
        $this->assertEquals(100000.0, $por_nombre['Gomez D1']['pendiente_en_pesos']);
        $this->assertEquals(1, $por_nombre['Gomez D1']['ventas_sin_cobrar']);

        // Los totales de arriba no cambian de significado: son lo de 20 días o más, de todos.
        $this->assertEquals(4, $resultado['ventas_sin_cobrar']);
        $this->assertEquals(758000.0, $resultado['total_pendiente_en_pesos']);
        $this->assertEquals(1311000.0, $resultado['deuda_total_en_pesos_de_estos_clientes']);
        $this->assertStringContainsString('deuda_total_en_pesos', $resultado['como_leer_cada_cliente']);

        // Sin monto mínimo, con dias: entran los cuatro con deuda vieja, por deuda total.
        $sin_minimo = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, 20);

        $this->assertEquals(['Gomez D1', 'Lopez D1', 'Sosa D1', 'Perez D1'], array_column($sin_minimo['clientes'], 'cliente'));
        $this->assertArrayNotHasKey('monto_minimo_pedido', $sin_minimo);

        // Y por la tool: el handler pasa monto_minimo.
        $this->assertEquals('number', $this->definicion('consultar_ventas_sin_cobrar')['input_schema']['properties']['monto_minimo']['type']);
        $handler = $this->handler('consultar_ventas_sin_cobrar');
        $por_tool = $handler(['dias' => 20, 'monto_minimo' => 200000], $this->comercio->id);

        $this->assertEquals(['Gomez D1', 'Lopez D1', 'Sosa D1'], array_column($por_tool['clientes'], 'cliente'));
    }

    /**
     * Sin `dias` el monto mínimo también va contra la deuda total, y Ruiz (todo reciente) entra.
     *
     * @group chat-ia
     * @test
     */
    public function sin_dias_el_monto_minimo_tambien_va_contra_la_deuda_total()
    {
        $ruiz = $this->cliente('Ruiz D1');
        $perez = $this->cliente('Perez D1');

        $this->venta_impaga($ruiz, 900000, now()->subDays(3));
        $this->venta_impaga($perez, 125000, now()->subDays(40));

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id, null, null, 200000);

        $this->assertEquals(['Ruiz D1'], array_column($resultado['clientes'], 'cliente'));
        $this->assertEquals(900000.0, $resultado['clientes'][0]['deuda_total_en_pesos']);
        $this->assertEquals(3, $resultado['clientes'][0]['dias_de_la_venta_mas_vieja']);
        $this->assertEquals(2, $resultado['clientes_con_deuda_sin_el_monto_minimo']);
    }

    /**
     * Sin los parámetros nuevos, la salida de siempre: mismas claves arriba, mismos números, mismo
     * orden, y cada cliente con sus claves de siempre (más las tres nuevas).
     *
     * @group chat-ia
     * @test
     */
    public function sin_parametros_nuevos_la_salida_de_siempre()
    {
        $gomez = $this->cliente('Gomez D1');
        $lopez = $this->cliente('Lopez D1');

        $this->venta_impaga($gomez, 1000, now()->subDays(40));
        $this->venta_impaga($gomez, 500, now()->subDays(1));
        $this->venta_impaga($lopez, 2000, now()->subDays(10));

        $resultado = VentasSinCobrarIaHelper::ventas_sin_cobrar($this->comercio->id);

        foreach (['criterio', 'alcance', 'dias_de_antiguedad_pedidos', 'ventas_sin_cobrar', 'total_pendiente_en_pesos', 'ventas_en_otra_moneda', 'ventas_sin_cliente', 'venta_mas_vieja', 'clientes_con_deuda', 'clientes_en_esta_lista', 'clientes'] as $clave) {
            $this->assertArrayHasKey($clave, $resultado);
        }

        $this->assertArrayNotHasKey('monto_minimo_pedido', $resultado);
        $this->assertEquals(3, $resultado['ventas_sin_cobrar']);
        $this->assertEquals(3500.0, $resultado['total_pendiente_en_pesos']);
        $this->assertEquals(2, $resultado['clientes_con_deuda']);
        $this->assertEquals(['Lopez D1', 'Gomez D1'], array_column($resultado['clientes'], 'cliente'));

        $lopez_fila = $resultado['clientes'][0];

        foreach (['cliente', 'cliente_id', 'ventas_sin_cobrar', 'pendiente_en_pesos', 'ventas_en_otra_moneda'] as $clave) {
            $this->assertArrayHasKey($clave, $lopez_fila);
        }

        $this->assertEquals(2000.0, $lopez_fila['pendiente_en_pesos']);

        // Sin dias, la deuda total y la del conjunto son la misma.
        $this->assertEquals(1500.0, $resultado['clientes'][1]['pendiente_en_pesos']);
        $this->assertEquals(1500.0, $resultado['clientes'][1]['deuda_total_en_pesos']);
        $this->assertEquals(40, $resultado['clientes'][1]['dias_de_la_venta_mas_vieja']);
    }

    // ------------------------------------------------------------------ fixture

    /**
     * @param  string  $nombre
     * @param  User|null  $dueno
     * @return Provider
     */
    protected function proveedor($nombre, $dueno = null)
    {
        return Provider::create(['name' => $nombre, 'user_id' => is_null($dueno) ? $this->comercio->id : $dueno->id]);
    }

    /**
     * @param  string  $nombre
     * @param  Provider|null  $proveedor
     * @return Article
     */
    protected function articulo($nombre, $proveedor)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $this->comercio->id,
            'provider_id' => is_null($proveedor) ? null : $proveedor->id,
        ]);
    }

    /**
     * Una venta en pesos con un renglón, con la fila de article_purchases escrita por
     * ArticlePurchaseHelper (molde: 33_), en la fecha de hace `$dias` días.
     */
    protected function venta_con_renglon($articulo, $cantidad, $precio, $dias)
    {
        $momento = now()->subDays($dias);

        $venta = Sale::create([
            'user_id'    => $articulo->user_id,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ]);

        $venta->articles()->attach($articulo->id, ['amount' => $cantidad, 'price' => $precio, 'cost' => 0]);

        (new ArticlePurchaseHelper())->set_article_purcase($venta->fresh());

        // El renglón toma la fecha de la venta: es la que filtra la ventana.
        \Illuminate\Support\Facades\DB::table('article_purchases')->where('sale_id', $venta->id)->update(['created_at' => $momento]);

        return $venta;
    }

    /**
     * @param  string  $nombre
     * @return Client
     */
    protected function cliente($nombre)
    {
        return Client::create(['name' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /**
     * Una venta a cuenta corriente impaga, como la arma 46_.
     */
    protected function venta_impaga($cliente, $debe, Carbon $momento)
    {
        $venta = Sale::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'total'      => $debe,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ]);

        CurrentAcount::create([
            'user_id'    => $this->comercio->id,
            'client_id'  => $cliente->id,
            'sale_id'    => $venta->id,
            'detalle'    => 'Venta impaga D1',
            'debe'       => $debe,
            'saldo'      => $debe,
            'status'     => 'sin_pagar',
            'created_at' => $momento,
        ]);

        return $venta;
    }

    /**
     * La definición de una tool de lectura, como la ve el modelo.
     *
     * @param  string  $nombre
     * @return array
     */
    protected function definicion($nombre)
    {
        foreach ((new AsistenteIaService())->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === $nombre) {
                return $herramienta;
            }
        }

        $this->fail('No esta la tool ' . $nombre);
    }

    /**
     * El handler de una tool de lectura, el mismo que corre el loop del asistente
     * (`handler_de_lectura()` es protected: se lo llama por reflexión, como en 43_ y 64_).
     *
     * @param  string  $nombre
     * @return callable
     */
    protected function handler($nombre)
    {
        $metodo = new \ReflectionMethod(AsistenteIaService::class, 'handler_de_lectura');
        $metodo->setAccessible(true);

        $handler = $metodo->invoke(new AsistenteIaService(), $nombre);

        $this->assertNotNull($handler, 'No esta la tool ' . $nombre);

        return $handler;
    }
}
