<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ResumenDeVentasIaHelper;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\Sale;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión agente-mejoras-uso-real (E2 punto 4, 29/10/2026) — la ganancia por artículo que cuadra con el
 * total vendido del período.
 *
 * El caso real: Pablo (Ferre Total, CONV 11) preguntó «¿cuál me dejó más margen hoy?» y el agente
 * contestó «$1.663.883 de margen sobre $4.850.756 vendidos», con la suma bruta de los renglones; el
 * total del día (Rendimiento) era $3.626.407. Lo que protege:
 *  - agrupar por artículo prorratea cada venta (descuento o recargo global) y la suma de los grupos más
 *    lo que fue a combos/servicios (sin_desglosar_por_articulo) da EXACTAMENTE el total del período;
 *  - las ventas sin terminar no entran (es el conjunto de Rendimiento);
 *  - con orden ganancia cada grupo trae costo, ganancia y article_id, y asc pone primero el de margen
 *    negativo;
 *  - sin orden, los grupos tienen las claves de siempre (compatibilidad);
 *  - la herramienta acepta orden y direccion.
 */
class Ganancia_por_articulo_que_cuadra_con_el_total_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var string AAAA-MM-DD del día de las ventas. */
    protected $fecha;

    /** @var array<string, Article> */
    protected $articulos = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'     => 'Comercio ganancia E2',
            'email'    => 'ganancia-e2-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $dia = now()->subDays(3)->startOfDay();
        $this->fecha = $dia->format('Y-m-d');

        /*
         * Sin IVA en el costo (`aplicar_iva` en 0): así el costo neto del renglón es unidades × costo
         * tal cual y los números del test se leen solos. El neteo del IVA de compra en las cuentas
         * que lo traen adentro es de CostoDeVentaHelper y tiene sus propios tests.
         */
        $this->articulos['hidro'] = Article::create(['name' => 'HIDROLAVADORA LUSQTOFF 1800W E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);
        $this->articulos['membrana'] = Article::create(['name' => 'MEMBRANA EN PASTA TEJA X 20L E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);
        $this->articulos['tornillo'] = Article::create(['name' => 'TORNILLO DRYWALL 6 X 2 E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);

        // 1. La hidrolavadora con 10 % de descuento en la venta entera: 300.000 → 270.000.
        $this->venta($dia->copy()->addHours(9), 270000, [$this->articulos['hidro']->id => [1, 300000, 200000]]);

        // 2. La membrana (50.000) más un combo (10.000) por 55.000: la venta reparte 11/12.
        $con_combo = $this->venta($dia->copy()->addHours(10), 55000, [$this->articulos['membrana']->id => [1, 50000, 30000]]);
        DB::table('combo_sale')->insert(['combo_id' => 999001, 'sale_id' => $con_combo->id, 'price' => 10000, 'amount' => 1]);

        // 3. Tornillos a pérdida (costo 12 sobre precio 10).
        $this->venta($dia->copy()->addHours(11), 1000, [$this->articulos['tornillo']->id => [100, 10, 12]]);

        // 4. Tornillos con 10 % de recargo en la venta: 1.000 → 1.100 (costo 15: también a pérdida).
        $this->venta($dia->copy()->addHours(12), 1100, [$this->articulos['tornillo']->id => [100, 10, 15]]);

        // 5. Una venta SIN TERMINAR: no es del conjunto de Rendimiento.
        $this->venta($dia->copy()->addHours(13), 5000, [$this->articulos['tornillo']->id => [500, 10, 1]], ['terminada' => 0]);
    }

    /**
     * Una venta del comercio con sus renglones [article_id => [unidades, precio, costo]].
     *
     * @param  Carbon  $momento
     * @param  float  $total
     * @param  array  $renglones
     * @param  array  $extra
     * @return Sale
     */
    protected function venta(Carbon $momento, $total, array $renglones, array $extra = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $this->comercio->id,
            'total'      => $total,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));

        foreach ($renglones as $article_id => $datos) {
            $venta->articles()->attach($article_id, ['amount' => $datos[0], 'price' => $datos[1], 'cost' => $datos[2]]);
        }

        return $venta;
    }

    /**
     * El grupo de un artículo en la respuesta.
     *
     * @param  array  $respuesta
     * @param  string  $clave
     * @return array|null
     */
    protected function grupo(array $respuesta, $clave)
    {
        foreach ($respuesta['grupos'] as $grupo) {
            if ($grupo['etiqueta'] === $this->articulos[$clave]->name) {
                return $grupo;
            }
        }

        return null;
    }

    /**
     * @group chat-ia
     * @test
     */
    public function la_suma_de_los_grupos_mas_lo_sin_desglosar_da_el_total_del_periodo()
    {
        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo');

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertEquals(327100.0, $resumen['total'], '270.000 + 55.000 + 1.000 + 1.100: la venta sin terminar no entra.');
        $this->assertEquals(327100.0, $resumen['total_del_periodo']);

        $this->assertEqualsWithDelta(270000.0, $this->grupo($resumen, 'hidro')['total'], 0.01, 'Con el descuento de la venta repartido.');
        $this->assertEqualsWithDelta(45833.33, $this->grupo($resumen, 'membrana')['total'], 0.01, '50.000 × 55.000 / 60.000.');
        $this->assertEqualsWithDelta(2100.0, $this->grupo($resumen, 'tornillo')['total'], 0.01, '1.000 + 1.100 con el recargo; sin la venta sin terminar.');
        $this->assertEquals(200.0, $this->grupo($resumen, 'tornillo')['cantidad'], 'Las unidades de las dos ventas terminadas.');
        $this->assertEqualsWithDelta(9166.67, $resumen['sin_desglosar_por_articulo'], 0.01, 'La parte del combo.');

        $suma = 0.0;

        foreach ($resumen['grupos'] as $grupo) {
            $suma += $grupo['total'];
        }

        $this->assertEqualsWithDelta($resumen['total_del_periodo'], $suma + $resumen['sin_desglosar_por_articulo'], 0.02, '🔴 Cuadra con el total del día.');

        // Sin orden, los grupos tienen las claves de siempre.
        $this->assertSame(['etiqueta', 'cantidad', 'total'], array_keys($resumen['grupos'][0]));
        $this->assertStringContainsString('unidades', $resumen['nota']);
    }

    /**
     * «¿Cuál me dejó más margen hoy?» y «¿hay ventas con margen negativo?».
     *
     * @group chat-ia
     * @test
     */
    public function con_orden_ganancia_trae_costo_ganancia_y_article_id_en_las_dos_direcciones()
    {
        $mas = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'ganancia', 'desc');

        $this->assertArrayNotHasKey('error', $mas, json_encode($mas));
        $this->assertSame('ganancia desc', $mas['ordenado_por']);

        $primero = $mas['grupos'][0];

        $this->assertSame($this->articulos['hidro']->name, $primero['etiqueta']);
        $this->assertSame((int) $this->articulos['hidro']->id, $primero['article_id']);
        $this->assertEqualsWithDelta(200000.0, $primero['costo'], 0.01);
        $this->assertEqualsWithDelta(70000.0, $primero['ganancia'], 0.01);

        $membrana = $this->grupo($mas, 'membrana');
        $this->assertEqualsWithDelta(15833.33, $membrana['ganancia'], 0.01);

        $tornillo = $this->grupo($mas, 'tornillo');
        $this->assertEqualsWithDelta(2700.0, $tornillo['costo'], 0.01, '100 × 12 + 100 × 15.');
        $this->assertEqualsWithDelta(-600.0, $tornillo['ganancia'], 0.01);

        // 200.000 + 30.000 + 2.700; la ganancia, sobre lo atribuido a artículos (317.933,33).
        $this->assertEqualsWithDelta(232700.0, $mas['costo_del_periodo'], 0.01);
        $this->assertEqualsWithDelta(317933.33 - 232700.0, $mas['ganancia_del_periodo'], 0.02);

        // De menor a mayor: el de margen negativo primero.
        $menos = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'ganancia', 'asc');

        $this->assertSame($this->articulos['tornillo']->name, $menos['grupos'][0]['etiqueta']);

        // Por cantidad, de menor a mayor.
        $por_cantidad = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'cantidad', 'asc');
        $this->assertEquals(1.0, $por_cantidad['grupos'][0]['cantidad']);

        // Ganancia con una agrupación que no la tiene: se dice.
        $por_dia = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'dia', 'pesos', null, 'ganancia');
        $this->assertArrayHasKey('error', $por_dia);

        $invalido = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'margen');
        $this->assertArrayHasKey('error', $invalido);
    }

    /**
     * 🔴 Revisión independiente (30/10/2026): un renglón con `article_sale.cost` en NULL no vale costo 0.
     * Antes, una lija vendida a $ 100.000 sin costo cargado quedaba con ganancia = $ 100.000 y
     * encabezaba "cuál me dejó más margen". Ahora: sin ningún renglón con costo, ganancia null, marcada
     * y al final del ranking; con algunos, la ganancia es solo la de la parte con costo.
     *
     * @group chat-ia
     * @test
     */
    public function los_renglones_sin_costo_no_cuentan_como_costo_cero_ni_encabezan_el_ranking()
    {
        $dia = Carbon::parse($this->fecha)->startOfDay();

        $lija = Article::create(['name' => 'LIJA AL AGUA SIN COSTO E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);
        $mecha = Article::create(['name' => 'MECHA ACERO RAPIDO E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);

        // La lija: un renglón de $ 100.000 SIN costo (NULL).
        $this->venta($dia->copy()->addHours(14), 100000, [$lija->id => [1, 100000, null]]);

        // La mecha: un renglón con costo (100 a costo 60) y otro sin costo ($ 1.000).
        $this->venta($dia->copy()->addHours(15), 100, [$mecha->id => [1, 100, 60]]);
        $this->venta($dia->copy()->addHours(16), 1000, [$mecha->id => [1, 1000, null]]);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'ganancia', 'desc');

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));

        // La hidrolavadora (70.000 de ganancia de verdad) encabeza; la lija, nunca.
        $this->assertSame($this->articulos['hidro']->name, $resumen['grupos'][0]['etiqueta']);

        $grupo_lija = null;
        $grupo_mecha = null;

        foreach ($resumen['grupos'] as $grupo) {
            if ($grupo['etiqueta'] === $lija->name) {
                $grupo_lija = $grupo;
            }
            if ($grupo['etiqueta'] === $mecha->name) {
                $grupo_mecha = $grupo;
            }
        }

        $this->assertNull($grupo_lija['ganancia'], 'Sin ningún renglón con costo, la ganancia no se sabe.');
        $this->assertTrue($grupo_lija['sin_costo']);
        $this->assertSame(1, $grupo_lija['renglones_sin_costo']);
        $this->assertSame($lija->name, end($resumen['grupos'])['etiqueta'], 'Sin ganancia conocida, al final.');

        $this->assertEqualsWithDelta(40.0, $grupo_mecha['ganancia'], 0.01, 'Solo la parte con costo: 100 − 60 (no 1.100 − 60).');
        $this->assertTrue($grupo_mecha['sin_costo']);
        $this->assertSame(1, $grupo_mecha['renglones_sin_costo']);

        // Los que tienen todo el costo cargado dicen sin_costo false.
        $this->assertFalse($resumen['grupos'][0]['sin_costo']);

        // Al revés (asc) tampoco: el de ganancia desconocida sigue al final, no arriba.
        $al_reves = ResumenDeVentasIaHelper::resumen($this->comercio->id, $this->fecha, $this->fecha, 'articulo', 'pesos', null, 'ganancia', 'asc');
        $this->assertSame($lija->name, end($al_reves['grupos'])['etiqueta']);

        // El período: la ganancia es sobre lo que tiene costo, y lo vendido sin costo se informa aparte.
        $this->assertSame(2, $resumen['renglones_sin_costo_del_periodo']);
        $this->assertEqualsWithDelta(101000.0, $resumen['vendido_sin_costo_del_periodo'], 0.01);
        $this->assertEqualsWithDelta(317933.33 + 100 - (232700.0 + 60), $resumen['ganancia_del_periodo'], 0.02);
        $this->assertStringContainsString('sin_costo', $resumen['nota']);
    }

    /**
     * Revisión independiente (30/10/2026): una promoción de vinoteca es un renglón de la venta y va a
     * sin_desglosar_por_articulo (antes no estaba en la base y su plata se le cargaba al artículo); y
     * lo que la venta suma sin renglón propio (un envío) se reparte entre los renglones, como dice la
     * nota.
     *
     * @group chat-ia
     * @test
     */
    public function las_promociones_van_a_sin_desglosar_y_lo_que_no_tiene_renglon_se_reparte()
    {
        $dia = Carbon::parse($this->fecha)->copy()->subDay()->startOfDay();
        $fecha = $dia->format('Y-m-d');

        $vino = Article::create(['name' => 'VINO MALBEC E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);
        $copa = Article::create(['name' => 'COPA CRISTAL E2', 'user_id' => $this->comercio->id, 'aplicar_iva' => 0]);

        // El vino (1.000) más una promoción (500) por 1.500.
        $con_promo = $this->venta($dia->copy()->addHours(9), 1500, [$vino->id => [1, 1000, 600]]);
        DB::table('promocion_vinoteca_sale')->insert(['promocion_vinoteca_id' => 999002, 'sale_id' => $con_promo->id, 'price' => 500, 'amount' => 1]);

        // La copa (1.000) con un envío que no es renglón: la venta es de 1.100.
        $this->venta($dia->copy()->addHours(10), 1100, [$copa->id => [1, 1000, 400]]);

        $resumen = ResumenDeVentasIaHelper::resumen($this->comercio->id, $fecha, $fecha, 'articulo');

        $this->assertArrayNotHasKey('error', $resumen, json_encode($resumen));
        $this->assertEquals(2600.0, $resumen['total_del_periodo']);

        $totales = array_column($resumen['grupos'], 'total', 'etiqueta');

        $this->assertEqualsWithDelta(1000.0, $totales[$vino->name], 0.01, 'La promoción no se le carga al vino.');
        $this->assertEqualsWithDelta(1100.0, $totales[$copa->name], 0.01, 'El envío, que no tiene renglón, se reparte entre los renglones de su venta.');
        $this->assertEqualsWithDelta(500.0, $resumen['sin_desglosar_por_articulo'], 0.01, 'La promoción.');
        $this->assertStringContainsString('promociones', $resumen['nota']);
        $this->assertStringContainsString('un envio', $resumen['nota']);
    }

    /**
     * La herramienta declara y pasa orden y direccion.
     *
     * @group chat-ia
     * @test
     */
    public function la_herramienta_acepta_orden_y_direccion()
    {
        $servicio = new AsistenteIaService();

        $definicion = null;

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_resumen_de_ventas') {
                $definicion = $herramienta;
            }
        }

        $this->assertSame(ResumenDeVentasIaHelper::ORDENES, $definicion['input_schema']['properties']['orden']['enum']);
        $this->assertSame(['desc', 'asc'], $definicion['input_schema']['properties']['direccion']['enum']);
        $this->assertStringContainsString('SIN costo cargado', $definicion['description']);

        /*
         * Revisión (30/10/2026), CONV 9 de Pablo: «separámelos en unidades y en precio de venta» son DOS
         * rankings, en el resumen y en los más vendidos.
         */
        $this->assertStringContainsString('"en unidades y en pesos" son DOS rankings', $definicion['description']);

        $mas_vendidos = null;

        foreach ($servicio->herramientas_de_lectura() as $herramienta) {
            if ($herramienta['name'] === 'consultar_articulos_mas_vendidos') {
                $mas_vendidos = $herramienta;
            }
        }

        $this->assertStringContainsString('dale DOS rankings, uno ordenado por unidades y otro por plata', $mas_vendidos['description']);

        $conversation = AiConversation::create(['user_id' => $this->comercio->id, 'auth_user_id' => $this->comercio->id]);

        $resultados = $servicio->execute_tool_calls([[
            'type'  => 'tool_use',
            'id'    => 'toolu_' . uniqid(),
            'name'  => 'consultar_resumen_de_ventas',
            'input' => ['desde' => $this->fecha, 'hasta' => $this->fecha, 'agrupar_por' => 'articulo', 'orden' => 'ganancia', 'direccion' => 'asc'],
        ]], $conversation);

        $contenido = json_decode($resultados[0]['content'], true);

        $this->assertSame('ganancia asc', $contenido['ordenado_por']);
        $this->assertSame($this->articulos['tornillo']->name, $contenido['grupos'][0]['etiqueta']);
        $this->assertArrayHasKey('ganancia', $contenido['grupos'][0]);
    }
}
