<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\ConsultasSistemaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AdjuntosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ReporteContableIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeVentasIaHelper;
use App\Http\Controllers\Helpers\sale\ArticlePurchaseHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\Mostrador\RecolectorDia;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloques A4 a A7: el resumen de ventas contra Rendimiento, los más
 * vendidos con monto, el reporte contable y el registro de las tools nuevas.
 *
 * 🔴 LA ASERCIÓN DEL ARCHIVO: "¿cuánto vendí?" contesta EL MISMO NÚMERO que el reporte de
 * Rendimiento. Se fija comparando contra RecolectorDia::recolectar() del mismo día, que es el que
 * el informe del mostrador y Rendimiento ya usan: si los dos leyeran conjuntos distintos, el dueño
 * tendría dos totales y ninguna forma de saber cuál es el bueno.
 */
class Resumen_de_ventas_y_mas_vendidos_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var User */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        EsquemaDeDatosIaHelper::olvidar();

        $this->comercio = User::create([
            'name'     => 'Comercio omnisciente A4',
            'email'    => 'omnisciente-a4-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio omnisciente A4',
            'email'    => 'omnisciente-a4-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 El total coincide con RecolectorDia::recolectar() para el mismo día: cantidad, total,
     * ticket promedio, a cuenta corriente, devoluciones y los grupos por método de pago.
     *
     * @group chat-ia
     * @test
     */
    public function el_total_coincide_con_recolectar_del_mismo_dia()
    {
        $dia = now()->subDays(3)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $martillo = Article::create(['name' => 'Martillo A4', 'user_id' => $this->comercio->id]);
        $pinza = Article::create(['name' => 'Pinza A4', 'user_id' => $this->comercio->id]);
        $cliente = Client::create(['name' => 'Cliente A4', 'user_id' => $this->comercio->id]);

        // Tres ventas de mostrador en pesos, terminadas.
        $this->venta($this->comercio, $dia->copy()->addHours(9), 1000, [$martillo->id => [2, 500]]);
        $this->venta($this->comercio, $dia->copy()->addHours(11), 300, [$pinza->id => [3, 100]]);
        $this->venta($this->comercio, $dia->copy()->addHours(15), 500, [$martillo->id => [1, 500]], ['current_acount_payment_method_id' => 3]);

        // Una a cuenta corriente: con cliente y con su movimiento de cuenta corriente creado.
        $a_cuenta = $this->venta($this->comercio, $dia->copy()->addHours(17), 800, [$martillo->id => [1, 800]], ['client_id' => $cliente->id]);
        CurrentAcount::create(['user_id' => $this->comercio->id, 'client_id' => $cliente->id, 'sale_id' => $a_cuenta->id, 'detalle' => 'Venta A4', 'debe' => 800, 'saldo' => 800, 'status' => 'sin_pagar', 'created_at' => $dia->copy()->addHours(17)]);

        // Una devolución (nota de crédito) en pesos ese día.
        CurrentAcount::create(['user_id' => $this->comercio->id, 'client_id' => $cliente->id, 'detalle' => 'NC A4', 'haber' => 80, 'saldo' => 720, 'status' => 'nota_credito', 'created_at' => $dia->copy()->addHours(18)]);

        // Lo que NO entra: en dólares, no terminada, borrada, consolidación, de otro día, de otro dueño.
        $this->venta($this->comercio, $dia->copy()->addHours(10), 9999, [$martillo->id => [9, 1111]], ['moneda_id' => 2]);
        $this->venta($this->comercio, $dia->copy()->addHours(10), 5555, [$martillo->id => [5, 1111]], ['terminada' => 0]);
        $this->venta($this->comercio, $dia->copy()->addHours(10), 4444, [$martillo->id => [4, 1111]])->delete();
        $this->venta($this->comercio, $dia->copy()->addHours(10), 3333, [$martillo->id => [3, 1111]], ['is_consolidacion_facturacion' => 1]);
        $this->venta($this->comercio, $dia->copy()->addDays(1), 2222, [$martillo->id => [2, 1111]]);
        $this->venta($this->otro_comercio, $dia->copy()->addHours(10), 7777, [$martillo->id => [7, 1111]]);

        $rendimiento = (new RecolectorDia())->recolectar($this->comercio, Carbon::parse($fecha))['ventas'];
        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha);

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertEquals(4, $resumen['cantidad_de_ventas']);
        $this->assertEquals(2600.0, $resumen['total']);
        $this->assertEquals(7.0, $resumen['unidades'], '2 + 3 + 1 + 1 unidades de las cuatro ventas que entran.');
        $this->assertEquals(800.0, $resumen['a_cuenta_corriente']);
        $this->assertEquals(80.0, $resumen['devoluciones']);
        $this->assertEquals(['cantidad' => 1, 'moneda' => 'dolares'], $resumen['ventas_en_otra_moneda']);

        // 🔴 Los mismos números que Rendimiento, uno por uno.
        $this->assertEquals($rendimiento['cantidad'], $resumen['cantidad_de_ventas']);
        $this->assertEquals($rendimiento['total'], $resumen['total']);
        $this->assertEquals($rendimiento['ticket_promedio'], $resumen['ticket_promedio']);
        $this->assertEquals($rendimiento['a_cuenta_corriente'], $resumen['a_cuenta_corriente']);
        $this->assertEquals($rendimiento['devoluciones'], $resumen['devoluciones']);

        // Y los grupos por método de pago replican por_metodo_pago: Efectivo (las tres de
        // mostrador, dos sin método → default Efectivo, una con el 3) y Cuenta corriente.
        $por_metodo = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'metodo_de_pago');

        $mios = [];
        foreach ($por_metodo['grupos'] as $grupo) {
            $mios[$grupo['etiqueta']] = $grupo['total'];
        }

        $de_rendimiento = [];
        foreach ($rendimiento['por_metodo_pago'] as $grupo) {
            $de_rendimiento[$grupo['metodo']] = $grupo['total'];
        }

        $this->assertEquals($de_rendimiento, $mios);
        $this->assertEquals(800.0, $mios['Cuenta corriente']);

        // Y por vendedor: todo al dueño, con el nombre del dueño, como por_vendedor.
        $por_vendedor = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'vendedor');

        $this->assertEquals(1, $por_vendedor['grupos_encontrados']);
        $this->assertEquals($rendimiento['por_vendedor'][0]['empleado'], $por_vendedor['grupos'][0]['etiqueta']);
        $this->assertEquals($rendimiento['por_vendedor'][0]['total'], $por_vendedor['grupos'][0]['total']);
        $this->assertEquals(4, $por_vendedor['grupos'][0]['cantidad']);

        // En dólares: la que quedó afuera, con el aviso.
        $en_dolares = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, null, 'dolares');

        $this->assertEquals(1, $en_dolares['cantidad_de_ventas']);
        $this->assertEquals(9999.0, $en_dolares['total']);
        $this->assertNull($en_dolares['devoluciones']);
        $this->assertArrayHasKey('aviso', $en_dolares);
    }

    /**
     * Agrupar por vendedor (empleados de la cuenta, sin empleado el dueño) y por artículo (unidades).
     *
     * @group chat-ia
     * @test
     */
    public function agrupa_por_vendedor_y_por_articulo()
    {
        $dia = now()->subDays(2)->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $empleada = User::create(['name' => 'Empleada A4', 'email' => 'empleada-a4-' . uniqid() . '@test.local', 'password' => Hash::make('x'), 'owner_id' => $this->comercio->id]);

        $martillo = Article::create(['name' => 'Martillo A4 grupos', 'user_id' => $this->comercio->id]);
        $pinza = Article::create(['name' => 'Pinza A4 grupos', 'user_id' => $this->comercio->id]);

        $this->venta($this->comercio, $dia->copy()->addHours(9), 1000, [$martillo->id => [2, 500]], ['employee_id' => $empleada->id]);
        $this->venta($this->comercio, $dia->copy()->addHours(10), 250, [$martillo->id => [1, 250]], ['employee_id' => $empleada->id]);
        $this->venta($this->comercio, $dia->copy()->addHours(11), 300, [$pinza->id => [3, 100]]);

        $por_vendedor = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'vendedor');

        $this->assertEquals('vendedor', $por_vendedor['agrupado_por']);
        $this->assertEquals(2, $por_vendedor['grupos_encontrados']);
        $this->assertEquals(['etiqueta' => 'Empleada A4', 'cantidad' => 2, 'total' => 1250.0], $por_vendedor['grupos'][0]);
        $this->assertEquals(['etiqueta' => 'Comercio omnisciente A4', 'cantidad' => 1, 'total' => 300.0], $por_vendedor['grupos'][1]);

        $por_articulo = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'articulo');

        $this->assertEquals(2, $por_articulo['grupos_encontrados']);
        $this->assertEquals(['etiqueta' => 'Martillo A4 grupos', 'cantidad' => 3.0, 'total' => 1250.0], $por_articulo['grupos'][0]);
        $this->assertEquals(['etiqueta' => 'Pinza A4 grupos', 'cantidad' => 3.0, 'total' => 300.0], $por_articulo['grupos'][1]);
        $this->assertStringContainsString('unidades', $por_articulo['nota']);

        // Por día, la etiqueta lleva el día de la semana; por semana y por mes también responden.
        $por_dia = ResumenDeVentasIaHelper::resumen($this->comercio->id, $dia->copy()->subDays(1)->format('Y-m-d'), $fecha, 'dia');

        $this->assertEquals(1, $por_dia['grupos_encontrados']);
        $this->assertStringStartsWith($fecha . ' (', $por_dia['grupos'][0]['etiqueta']);
        $this->assertEquals(1550.0, $por_dia['grupos'][0]['total']);

        $this->assertEquals(1, ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'mes')['grupos_encontrados']);
        $this->assertEquals(1, ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'semana')['grupos_encontrados']);
        $this->assertEquals('Sin sucursal', ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'sucursal')['grupos'][0]['etiqueta']);
        $this->assertEquals('Sin cliente (mostrador)', ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'cliente')['grupos'][0]['etiqueta']);
    }

    /**
     * Un rango de más de 400 días corta, igual que una fecha que no se entiende, una agrupación
     * inventada o una moneda inventada. Lo mismo en el reporte contable.
     *
     * @group chat-ia
     * @test
     */
    public function un_rango_mayor_a_400_dias_da_error()
    {
        $hoy = now()->format('Y-m-d');

        $largo = ResumenDeVentasIaHelper::resumen($this->comercio->id, now()->subDays(401)->format('Y-m-d'), $hoy);
        $this->assertArrayHasKey('error', $largo);
        $this->assertStringContainsString('400', $largo['error']);

        $justo = ResumenDeVentasIaHelper::resumen($this->comercio->id, now()->subDays(399)->format('Y-m-d'), $hoy);
        $this->assertArrayNotHasKey('error', $justo, '400 días inclusive entran.');

        $this->assertArrayHasKey('error', ResumenDeVentasIaHelper::resumen($this->comercio->id, 'ayer', $hoy));
        $this->assertArrayHasKey('error', ResumenDeVentasIaHelper::resumen($this->comercio->id, $hoy, now()->subDay()->format('Y-m-d')));
        $this->assertArrayHasKey('error', ResumenDeVentasIaHelper::resumen($this->comercio->id, $hoy, $hoy, 'marca'));
        $this->assertArrayHasKey('error', ResumenDeVentasIaHelper::resumen($this->comercio->id, $hoy, $hoy, null, 'euros'));

        $reporte_largo = ReporteContableIaHelper::reporte($this->comercio->id, 'estado_resultados', now()->subDays(401)->format('Y-m-d'), $hoy);
        $this->assertArrayHasKey('error', $reporte_largo);
        $this->assertArrayHasKey('error', ReporteContableIaHelper::reporte($this->comercio->id, 'balance', $hoy, $hoy));

        // Y los tres reportes contestan con la forma prometida sobre un comercio nuevo.
        foreach (ReporteContableIaHelper::REPORTES as $reporte) {
            $respuesta = ReporteContableIaHelper::reporte($this->comercio->id, $reporte, now()->subDays(30)->format('Y-m-d'), $hoy);

            $this->assertEquals($reporte, $respuesta['reporte']);
            $this->assertArrayHasKey('que_es', $respuesta);
            $this->assertArrayHasKey('datos', $respuesta);
            $this->assertArrayHasKey('podado', $respuesta);
        }
    }

    /**
     * Los más vendidos: `total_en_pesos` suma solo los renglones con precio en pesos,
     * `unidades_sin_precio` cuenta los que no lo tienen (una venta sin moneda no llena `price`),
     * `desde`/`hasta` mandan sobre `dias`, y `tiene_imagen` sale de `images` por el morph map.
     *
     * @group chat-ia
     * @test
     */
    public function mas_vendidos_trae_total_en_pesos_unidades_sin_precio_y_foto()
    {
        $fernet = Article::create(['name' => 'Fernet A4', 'user_id' => $this->comercio->id]);
        $gancia = Article::create(['name' => 'Gancia A4', 'user_id' => $this->comercio->id]);

        // 🔴 La fila de article_purchases la escribe ArticlePurchaseHelper (ver 16_): así `price` se
        // llena solo cuando la venta tiene moneda_id 1, que es lo que se está probando.
        $this->venta_con_purchase($fernet, 3, 1000, 5, 1);
        $this->venta_con_purchase($fernet, 4, 1000, 10, 1);
        $this->venta_con_purchase($fernet, 2, 1000, 20, null);   // venta vieja sin moneda: sin precio
        $this->venta_con_purchase($gancia, 1, 700, 2, 1);

        $fernet->images()->create(['hosting_url' => 'https://ejemplo.test/fernet.jpg']);

        $resultado = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 30);

        $this->assertEquals('Fernet A4', $resultado[0]['nombre']);
        $this->assertEquals(9.0, $resultado[0]['total_vendido'], 'Las unidades son exactas: 3 + 4 + 2.');
        $this->assertEquals($fernet->id, $resultado[0]['articulo_id']);
        $this->assertEquals(7000.0, $resultado[0]['total_en_pesos'], 'Solo las 7 unidades con precio: 3000 + 4000.');
        $this->assertEquals(2.0, $resultado[0]['unidades_sin_precio'], 'Las 2 de la venta sin moneda no valen cero pesos.');
        $this->assertTrue($resultado[0]['tiene_imagen']);

        $this->assertEquals('Gancia A4', $resultado[1]['nombre']);
        $this->assertEquals(700.0, $resultado[1]['total_en_pesos']);
        $this->assertEquals(0.0, $resultado[1]['unidades_sin_precio']);
        $this->assertFalse($resultado[1]['tiene_imagen']);

        // desde/hasta mandan sobre dias: solo la venta de hace 2 días.
        $rango = ConsultasSistemaIaHelper::mas_vendidos($this->comercio->id, 7, now()->subDays(3)->format('Y-m-d'), now()->format('Y-m-d'));

        $this->assertCount(1, $rango);
        $this->assertEquals('Gancia A4', $rango[0]['nombre']);

        // Y el stock también dice si hay foto.
        $stock = ConsultasSistemaIaHelper::stock_de_articulos($this->comercio->id, 'A4');
        $con_foto = [];

        foreach ($stock as $fila) {
            $con_foto[$fila['nombre']] = $fila['tiene_imagen'];
        }

        $this->assertTrue($con_foto['Fernet A4']);
        $this->assertFalse($con_foto['Gancia A4']);
    }

    /**
     * mostrar_imagenes_de_articulos está en el registro con la forma del contrato (§3), al final,
     * y lo que devuelve la tool queda en adjuntos() del servicio.
     *
     * @group chat-ia
     * @test
     */
    public function mostrar_imagenes_de_articulos_esta_en_el_registro_con_la_forma_del_contrato()
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $service = new AsistenteIaService();

        $definiciones = [];

        foreach ($service->herramientas_de_lectura() as $herramienta) {
            $definiciones[$herramienta['name']] = $herramienta;
        }

        $this->assertArrayHasKey('mostrar_imagenes_de_articulos', $definiciones);

        $schema = $definiciones['mostrar_imagenes_de_articulos']['input_schema'];

        $this->assertEquals('array', $schema['properties']['articulo_ids']['type']);
        $this->assertEquals('integer', $schema['properties']['articulo_ids']['items']['type']);
        $this->assertEquals(1, $schema['properties']['articulo_ids']['minItems']);
        $this->assertEquals(AdjuntosIaHelper::MAX_ADJUNTOS, $schema['properties']['articulo_ids']['maxItems']);
        $this->assertEquals(['articulo_ids'], $schema['required']);

        /*
         * Las cuatro de esta misión van juntas y en este orden, y nada de lo anterior se movió: el
         * orden del registro es el prefijo que cachea con_cache_control().
         *
         * 🔴 El `-5` de abajo NO es un ajuste para que el test pase: la misión asistente-ventas-y-fotos
         * (21/9/2026) sumó `consultar_ventas_sin_cobrar` DESPUÉS de estas cuatro, que es la única
         * forma correcta de agregar una tool. Lo que este test cuida —que estas cuatro no se
         * reordenen ni se separen— se sigue cuidando igual; lo que cambia es cuántas hay atrás.
         * Cada tool nueva mueve este offset en uno, y eso es lo que tiene que pasar.
         */
        $nombres = $service->nombres_de_lectura();

        $this->assertEquals(
            ['resumir_datos', 'consultar_resumen_de_ventas', 'consultar_reporte_contable', 'mostrar_imagenes_de_articulos'],
            array_slice($nombres, -5, 4)
        );

        $this->assertEquals('consultar_ventas_sin_cobrar', $nombres[count($nombres) - 1]);

        // Y el handler deja la imagen en adjuntos(), sin cruzarla contra ningún texto.
        $con_foto = Article::create(['name' => 'Precintos A4', 'user_id' => $this->comercio->id]);
        $con_foto->images()->create(['hosting_url' => 'https://ejemplo.test/precintos.jpg']);
        $sin_foto = Article::create(['name' => 'Tarugo A4', 'user_id' => $this->comercio->id]);
        $ajeno = Article::create(['name' => 'Ajeno A4', 'user_id' => $this->otro_comercio->id]);

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $service->execute_tool_calls([
            ['type' => 'tool_use', 'id' => 'toolu_foto_01', 'name' => 'mostrar_imagenes_de_articulos', 'input' => ['articulo_ids' => [$con_foto->id, $sin_foto->id, $ajeno->id]]],
        ], $conversation);

        $this->assertArrayNotHasKey('is_error', $resultados[0], $resultados[0]['content']);

        $datos = json_decode($resultados[0]['content'], true);

        $this->assertArrayHasKey('adjuntos_de_la_respuesta', $datos);

        $adjuntos = $service->adjuntos();

        $this->assertCount(1, $adjuntos);
        $this->assertEquals('imagen', $adjuntos[0]['tipo']);
        $this->assertEquals($con_foto->id, $adjuntos[0]['articulo_id']);
        $this->assertStringStartsWith('http', $adjuntos[0]['url']);

        // El artículo sin foto se informa, y el de otro dueño no aparece en ninguna lista.
        $this->assertContains($sin_foto->id, array_column($datos['sin_imagen'], 'articulo_id'));
        $this->assertStringNotContainsString('Ajeno A4', $resultados[0]['content']);
    }

    /**
     * El prompt dice cuándo va cada tool y el bloque de carga enumera lo que queda afuera de verdad.
     *
     * @group chat-ia
     * @test
     */
    public function el_prompt_dice_cuando_va_cada_tool()
    {
        $service = new AsistenteIaService();
        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $solo_lectura = $service->build_system_prompt($conversation, $this->comercio, false);

        $this->assertStringContainsString('resumir_datos', $solo_lectura);
        $this->assertStringContainsString('consultar_resumen_de_ventas', $solo_lectura);
        $this->assertStringContainsString('mostrar_imagenes_de_articulos', $solo_lectura);
        // La regla de las fotos se endureció el 21/9/2026 (commit 99c547ec): además de no escribir
        // la URL, ahora tiene prohibido afirmar que un artículo no tiene foto sin haber llamado.
        $this->assertStringContainsString('no escribís la URL', $solo_lectura);
        $this->assertStringContainsString('NUNCA digas que un', $solo_lectura);
        $this->assertStringContainsString('que_puedo_consultar cubre', $solo_lectura);

        $con_carga = $service->build_system_prompt($conversation, $this->comercio, true);

        $this->assertStringContainsString('Lo que queda afuera de verdad', $con_carga);
        $this->assertStringNotContainsString('Nada más: no anulás', $con_carga, 'La enumeración cerrada se reemplazó.');
    }

    /**
     * Una venta terminada del día, con sus renglones en article_sale (el pivot de Sale::articles).
     *
     * @param  User    $dueno
     * @param  Carbon  $momento
     * @param  float   $total
     * @param  array   $renglones  article_id => [cantidad, precio]
     * @param  array   $extra
     * @return Sale
     */
    protected function venta(User $dueno, Carbon $momento, $total, array $renglones, array $extra = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $dueno->id,
            'total'      => $total,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));

        foreach ($renglones as $article_id => $datos) {
            $venta->articles()->attach($article_id, ['amount' => $datos[0], 'price' => $datos[1], 'cost' => 0]);
        }

        return $venta;
    }

    /**
     * Una venta con un renglón y su fila de article_purchases escrita por el sistema (ver 16_).
     *
     * @param  Article   $articulo
     * @param  float     $cantidad
     * @param  float     $precio
     * @param  int       $dias
     * @param  int|null  $moneda_id
     * @return Sale
     */
    protected function venta_con_purchase($articulo, $cantidad, $precio, $dias, $moneda_id)
    {
        $venta = Sale::create([
            'user_id'    => $articulo->user_id,
            'moneda_id'  => $moneda_id,
            'created_at' => now()->subDays($dias),
        ]);

        $venta->articles()->attach($articulo->id, ['amount' => $cantidad, 'price' => $precio, 'cost' => 0]);

        (new ArticlePurchaseHelper())->set_article_purcase($venta->fresh());

        return $venta;
    }
}
