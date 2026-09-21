<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ResumenDeDatosIaHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\Provider;
use App\Models\ProviderOrder;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión asistente-omnisciente — bloque A3: resumir_datos, la agregación genérica.
 *
 * 🔴 EL CASO QUE ORIGINÓ LA MISIÓN va primero: "¿qué producto le compro más a EL MAYORISTA DEL
 * NORTE?". Hasta hoy `consultar_compras_a_un_proveedor` devolvía cabeceras y los renglones de
 * compra no eran consultables; el asistente no tenía cómo agrupar y contestaba a mano sobre una
 * página. Acá la pregunta es una sola llamada: entidad `renglon_de_compra`, filtro por el nombre
 * del proveedor, agrupar por artículo, sumar unidades y costo.
 *
 * Y lo que la agregación no puede hacer mal: mezclar monedas sin avisar, sumar un campo de texto,
 * o recortar los grupos sin decir cuántos hay.
 */
class Resumen_de_datos_Test extends TestCase
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
            'name'     => 'Comercio omnisciente A3',
            'email'    => 'omnisciente-a3-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio omnisciente A3',
            'email'    => 'omnisciente-a3-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * 🔴 LA PREGUNTA DEL PROVEEDOR.
     *
     * @group chat-ia
     * @test
     */
    public function que_producto_le_compro_mas_a_un_proveedor_sale_en_una_llamada()
    {
        $mayorista = Provider::create(['name' => 'EL MAYORISTA DEL NORTE', 'user_id' => $this->comercio->id]);
        $otro = Provider::create(['name' => 'Distribuidora Sur', 'user_id' => $this->comercio->id]);

        $tornillos = Article::create(['name' => 'Tornillos A3', 'user_id' => $this->comercio->id]);
        $tuercas = Article::create(['name' => 'Tuercas A3', 'user_id' => $this->comercio->id]);
        $pintura = Article::create(['name' => 'Pintura A3', 'user_id' => $this->comercio->id]);

        // Tres compras al mayorista: tornillos 100 + 250 = 350 unidades, tuercas 80.
        $compra_1 = $this->compra($mayorista, 30);
        $this->renglon($compra_1, $tornillos, 100, 10);
        $this->renglon($compra_1, $tuercas, 80, 5);

        $compra_2 = $this->compra($mayorista, 10);
        $this->renglon($compra_2, $tornillos, 250, 12);

        // Una compra al otro proveedor, con muchas más unidades: no puede colarse.
        $compra_3 = $this->compra($otro, 5);
        $this->renglon($compra_3, $pintura, 900, 3);

        // Y una compra de OTRO comercio a un proveedor con el mismo nombre.
        $ajeno = Provider::create(['name' => 'EL MAYORISTA DEL NORTE', 'user_id' => $this->otro_comercio->id]);
        $articulo_ajeno = Article::create(['name' => 'Cosa ajena A3', 'user_id' => $this->otro_comercio->id]);
        $compra_ajena = $this->compra($ajeno, 1);
        $this->renglon($compra_ajena, $articulo_ajeno, 5000, 1);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'contiene', 'valor' => 'mayorista del norte']],
            [['campo' => 'article_id']],
            [['funcion' => 'suma', 'campo' => 'amount'], ['funcion' => 'suma', 'campo' => 'cost'], ['funcion' => 'conteo']]
        );

        $this->assertArrayNotHasKey('error', $resultado, json_encode($resultado));
        $this->assertEquals(['suma_amount', 'suma_cost', 'conteo'], $resultado['metricas']);
        $this->assertEquals(2, $resultado['grupos_encontrados']);
        $this->assertEquals('suma_amount DESC', $resultado['orden']);

        $primero = $resultado['grupos'][0];

        $this->assertEquals($tornillos->id, $primero['grupo']);
        $this->assertEquals('Tornillos A3', $primero['grupo_etiqueta']);
        $this->assertEquals(350.0, $primero['suma_amount']);
        $this->assertEquals(22.0, $primero['suma_cost'], 'La suma del costo unitario de los renglones: 10 + 12.');
        $this->assertEquals(2, $primero['conteo']);

        $this->assertEquals('Tuercas A3', $resultado['grupos'][1]['grupo_etiqueta']);
        $this->assertEquals(80.0, $resultado['grupos'][1]['suma_amount']);

        // El total general es sobre el conjunto filtrado entero: 430 unidades en 3 renglones.
        $this->assertEquals(['suma_amount' => 430.0, 'suma_cost' => 27.0, 'conteo' => 3], $resultado['total_general']);

        // Y el otro comercio, con su mayorista homónimo, ve solo lo suyo.
        $del_otro = ResumenDeDatosIaHelper::resumir(
            $this->otro_comercio->id,
            'renglon_de_compra',
            [['campo' => 'provider_id', 'operador' => 'contiene', 'valor' => 'mayorista']],
            [['campo' => 'article_id']],
            [['funcion' => 'suma', 'campo' => 'amount']]
        );

        $this->assertEquals(1, $del_otro['grupos_encontrados']);
        $this->assertEquals('Cosa ajena A3', $del_otro['grupos'][0]['grupo_etiqueta']);
    }

    /**
     * Agrupar por mes (y por semana, y por día) sobre una fecha.
     *
     * @group chat-ia
     * @test
     */
    public function agrupa_por_mes_y_ordena_por_grupo()
    {
        $mes_pasado = now()->subMonthNoOverflow()->startOfMonth();
        $este_mes = now()->startOfMonth();

        Sale::create(['user_id' => $this->comercio->id, 'total' => 100, 'created_at' => $mes_pasado->copy()->addDays(3)]);
        Sale::create(['user_id' => $this->comercio->id, 'total' => 150, 'created_at' => $mes_pasado->copy()->addDays(10)]);
        Sale::create(['user_id' => $this->comercio->id, 'total' => 700, 'created_at' => $este_mes->copy()->addHours(2)]);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'sale',
            [],
            [['campo' => 'created_at', 'por' => 'mes']],
            [['funcion' => 'suma', 'campo' => 'total'], ['funcion' => 'conteo']],
            ['por' => 'grupo', 'direccion' => 'ASC']
        );

        $this->assertEquals(2, $resultado['grupos_encontrados']);
        $this->assertEquals([['campo' => 'created_at', 'por' => 'mes']], $resultado['agrupado_por']);
        $this->assertEquals('grupo ASC', $resultado['orden']);

        $this->assertEquals($mes_pasado->format('Y-m'), $resultado['grupos'][0]['grupo']);
        $this->assertEquals(250.0, $resultado['grupos'][0]['suma_total']);
        $this->assertEquals(2, $resultado['grupos'][0]['conteo']);

        $this->assertEquals($este_mes->format('Y-m'), $resultado['grupos'][1]['grupo']);
        $this->assertEquals(700.0, $resultado['grupos'][1]['suma_total']);

        $this->assertEquals(['suma_total' => 950.0, 'conteo' => 3], $resultado['total_general']);

        // Por año, todo en uno (o dos, si el mes pasado fue diciembre).
        $por_anio = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'created_at', 'por' => 'anio']]);

        $this->assertEquals((int) $este_mes->format('Y'), $por_anio['grupos'][0]['grupo']);

        // Sin `por` sobre una fecha, se agrupa por día.
        $por_dia = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'created_at']]);

        $this->assertEquals(3, $por_dia['grupos_encontrados']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $por_dia['grupos'][0]['grupo']);
    }

    /**
     * Sin agrupar es una sola fila de totales: el "cuánto suman" genérico.
     *
     * @group chat-ia
     * @test
     */
    public function sin_agrupar_da_una_sola_fila_de_totales()
    {
        Client::create(['name' => 'Cliente A3 uno', 'user_id' => $this->comercio->id]);
        Client::create(['name' => 'Cliente A3 dos', 'user_id' => $this->comercio->id]);
        Client::create(['name' => 'Cliente ajeno A3', 'user_id' => $this->otro_comercio->id]);

        $resultado = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'client');

        $this->assertEquals([], $resultado['agrupado_por']);
        $this->assertEquals(['conteo'], $resultado['metricas'], 'Sin métricas, conteo.');
        $this->assertEquals(['conteo' => 2], $resultado['total_general']);
        $this->assertArrayNotHasKey('grupos', $resultado);
        $this->assertArrayNotHasKey('grupos_encontrados', $resultado);

        $con_filtro = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'client', [
            ['campo' => 'name', 'operador' => 'contiene', 'valor' => 'uno'],
        ]);

        $this->assertEquals(['conteo' => 1], $con_filtro['total_general']);
        $this->assertEquals('name', $con_filtro['filtros_aplicados'][0]['campo']);
    }

    /**
     * 🔴 Si hay registros en otra moneda y nadie filtró por moneda, se avisa. Con el filtro, no.
     *
     * @group chat-ia
     * @test
     */
    public function avisa_cuando_hay_registros_en_otra_moneda()
    {
        Sale::create(['user_id' => $this->comercio->id, 'total' => 100, 'moneda_id' => null]);
        Sale::create(['user_id' => $this->comercio->id, 'total' => 200, 'moneda_id' => 1]);
        Sale::create(['user_id' => $this->comercio->id, 'total' => 50, 'moneda_id' => 2]);
        Sale::create(['user_id' => $this->comercio->id, 'total' => 70, 'moneda_id' => 2]);

        $sin_filtro = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [], [['funcion' => 'suma', 'campo' => 'total']]);

        $this->assertEquals(420.0, $sin_filtro['total_general']['suma_total'], 'Sin filtro suma todo, y por eso avisa.');
        $this->assertArrayHasKey('en_otra_moneda', $sin_filtro);
        $this->assertEquals(2, $sin_filtro['en_otra_moneda']['registros']);
        $this->assertStringContainsString('moneda_id', $sin_filtro['en_otra_moneda']['aviso']);

        $en_pesos = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'sale',
            [['campo' => 'moneda_id', 'operador' => 'igual', 'valor' => 'pesos']],
            [],
            [['funcion' => 'suma', 'campo' => 'total']]
        );

        $this->assertEquals(300.0, $en_pesos['total_general']['suma_total'], 'NULL y 1 son pesos.');
        $this->assertArrayNotHasKey('en_otra_moneda', $en_pesos);

        // Una entidad sin moneda_id no avisa nunca.
        $this->assertArrayNotHasKey('en_otra_moneda', ResumenDeDatosIaHelper::resumir($this->comercio->id, 'client'));

        // Y una hija hereda la moneda del padre.
        $articulo = Article::create(['name' => 'Articulo A3 moneda', 'user_id' => $this->comercio->id]);
        $en_dolares = Sale::create(['user_id' => $this->comercio->id, 'total' => 10, 'moneda_id' => 2]);
        $en_dolares->articles()->attach($articulo->id, ['amount' => 4, 'price' => 2.5, 'cost' => 1]);

        $renglones = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'renglon_de_venta', [], [], [['funcion' => 'suma', 'campo' => 'amount']]);

        $this->assertEquals(1, $renglones['en_otra_moneda']['registros']);
    }

    /**
     * Tope de 100 grupos con `grupos_encontrados` diciendo cuántos hay en total; `limite` con
     * default 20.
     *
     * @group chat-ia
     * @test
     */
    public function el_tope_de_grupos_es_100_y_dice_cuantos_hay()
    {
        for ($i = 1; $i <= 105; $i++) {
            Client::create(['name' => 'Cliente A3 tope ' . str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'user_id' => $this->comercio->id]);
        }

        $por_defecto = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'client', [], [['campo' => 'name']]);

        $this->assertEquals(105, $por_defecto['grupos_encontrados']);
        $this->assertEquals(ResumenDeDatosIaHelper::LIMITE_DEFAULT, $por_defecto['grupos_en_esta_lista']);
        $this->assertCount(ResumenDeDatosIaHelper::LIMITE_DEFAULT, $por_defecto['grupos']);

        $al_tope = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'client', [], [['campo' => 'name']], [], null, 9999);

        $this->assertEquals(105, $al_tope['grupos_encontrados']);
        $this->assertEquals(ResumenDeDatosIaHelper::TOPE_DE_GRUPOS, $al_tope['grupos_en_esta_lista']);
        $this->assertEquals(['conteo' => 105], $al_tope['total_general'], 'El total general no lo recorta el tope.');
    }

    /**
     * 🔴 Lo que no se entiende corta: una métrica sobre texto, `por` sobre un número, una función
     * inventada, un orden por un alias que no existe, tres agrupaciones.
     *
     * @group chat-ia
     * @test
     */
    public function una_metrica_sobre_un_campo_no_numerico_corta_con_el_motivo()
    {
        Sale::create(['user_id' => $this->comercio->id, 'total' => 100, 'observations' => 'x']);

        $texto = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [], [['funcion' => 'suma', 'campo' => 'observations']]);

        $this->assertArrayHasKey('error', $texto);
        $this->assertArrayHasKey('campos_validos', $texto);
        $this->assertArrayNotHasKey('total_general', $texto);
        $this->assertStringContainsString('text', $texto['error']);

        $por_numero = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'total', 'por' => 'mes']]);
        $this->assertArrayHasKey('error', $por_numero);

        $funcion = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [], [['funcion' => 'mediana', 'campo' => 'total']]);
        $this->assertArrayHasKey('error', $funcion);

        $orden = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'client_id']], [['funcion' => 'suma', 'campo' => 'total']], ['por' => 'suma_cost']);
        $this->assertArrayHasKey('error', $orden);
        $this->assertStringContainsString('suma_total', $orden['error']);

        $tres = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'client_id'], ['campo' => 'employee_id'], ['campo' => 'moneda_id']]);
        $this->assertArrayHasKey('error', $tres);

        $campo = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [['campo' => 'color_de_la_caja']]);
        $this->assertArrayHasKey('error', $campo);

        $entidad = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'facturas_de_marte');
        $this->assertArrayHasKey('entidades_validas', $entidad);

        // Un filtro roto corta igual que en consultar_datos: nunca se suma la tabla entera.
        $filtro = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [['campo' => 'total', 'operador' => 'contiene', 'valor' => '1']]);
        $this->assertArrayHasKey('error', $filtro);

        // mínimo/máximo sí valen sobre una fecha.
        $fechas = ResumenDeDatosIaHelper::resumir($this->comercio->id, 'sale', [], [], [['funcion' => 'minimo', 'campo' => 'created_at'], ['funcion' => 'maximo', 'campo' => 'created_at']]);
        $this->assertEquals(date('d/m/Y'), $fechas['total_general']['minimo_created_at']);
    }

    /**
     * Dos agrupaciones (semana + relación): el grupo viaja como objeto y la etiqueta también.
     *
     * @group chat-ia
     * @test
     */
    public function agrupa_por_dos_campos_con_la_etiqueta_de_la_relacion()
    {
        $juan = Client::create(['name' => 'Juan A3', 'user_id' => $this->comercio->id]);
        $ana = Client::create(['name' => 'Ana A3', 'user_id' => $this->comercio->id]);

        $lunes = now()->startOfWeek();

        Sale::create(['user_id' => $this->comercio->id, 'client_id' => $juan->id, 'total' => 100, 'created_at' => $lunes->copy()->addHours(10)]);
        Sale::create(['user_id' => $this->comercio->id, 'client_id' => $juan->id, 'total' => 150, 'created_at' => $lunes->copy()->addDays(1)]);
        Sale::create(['user_id' => $this->comercio->id, 'client_id' => $ana->id, 'total' => 900, 'created_at' => $lunes->copy()->addDays(2)]);
        Sale::create(['user_id' => $this->comercio->id, 'client_id' => null, 'total' => 5, 'created_at' => $lunes->copy()->addDays(2)]);

        $resultado = ResumenDeDatosIaHelper::resumir(
            $this->comercio->id,
            'sale',
            [],
            [['campo' => 'created_at', 'por' => 'semana'], ['campo' => 'client_id']],
            [['funcion' => 'suma', 'campo' => 'total'], ['funcion' => 'promedio', 'campo' => 'total']]
        );

        $this->assertEquals(3, $resultado['grupos_encontrados'], 'Juan, Ana y sin cliente, todos en la misma semana.');

        $ana_grupo = $resultado['grupos'][0];

        $this->assertEquals($lunes->format('Y') . '-S' . str_pad((string) $lunes->isoWeek, 2, '0', STR_PAD_LEFT), $ana_grupo['grupo']['created_at']);
        $this->assertEquals($ana->id, $ana_grupo['grupo']['client_id']);
        $this->assertEquals(['client_id' => 'Ana A3'], $ana_grupo['grupo_etiqueta']);
        $this->assertEquals(900.0, $ana_grupo['suma_total']);
        $this->assertEquals(900.0, $ana_grupo['promedio_total']);

        $juan_grupo = $resultado['grupos'][1];

        $this->assertEquals('Juan A3', $juan_grupo['grupo_etiqueta']['client_id']);
        $this->assertEquals(250.0, $juan_grupo['suma_total']);
        $this->assertEquals(125.0, $juan_grupo['promedio_total']);

        // El grupo sin cliente existe (GROUP BY agrupa el NULL) y se etiqueta null.
        $sin_cliente = $resultado['grupos'][2];

        $this->assertNull($sin_cliente['grupo']['client_id']);
        $this->assertNull($sin_cliente['grupo_etiqueta']['client_id']);
    }

    /**
     * Una compra a un proveedor.
     *
     * @param  Provider  $proveedor
     * @param  int       $dias
     * @return ProviderOrder
     */
    protected function compra($proveedor, $dias)
    {
        return ProviderOrder::create([
            'user_id'     => $proveedor->user_id,
            'provider_id' => $proveedor->id,
            'moneda_id'   => 1,
            'created_at'  => now()->subDays($dias),
        ]);
    }

    /**
     * Un renglón de una compra (pivot article_provider_order), como lo siembra 16_.
     *
     * @param  ProviderOrder  $compra
     * @param  Article        $articulo
     * @param  float          $cantidad
     * @param  float          $costo
     * @return void
     */
    protected function renglon($compra, $articulo, $cantidad, $costo)
    {
        DB::table('article_provider_order')->insert([
            'provider_order_id' => $compra->id,
            'article_id'        => $articulo->id,
            'amount'            => $cantidad,
            'received'          => $cantidad,
            'cost'              => $costo,
            'cost_in_dollars'   => 0,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }
}
