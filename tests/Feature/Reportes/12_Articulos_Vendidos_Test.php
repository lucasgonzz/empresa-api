<?php

namespace Tests\Feature\Reportes;

use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión reporte-articulos-totales-del-periodo (10/10/2026) — Reportes → Artículos
 * (`POST api/article-purchase`, `ArticlePurchaseController::index()`).
 *
 * 🔴 QUÉ PROTEGE. Cuatro defectos medidos en `demo` (4.3.8):
 *
 * 1. Sin dueño: el reporte leía `article_purchases` sin filtrar por comercio, y en una base
 *    compartida (`u767360347_empresa`, 51 comercios) mezclaba las ventas de todos.
 * 2. Uno de menos: `array_slice(..., $cantidad - 1)` devolvía 9 con 10 (y con null sacaba la última).
 * 3. Categorías, proveedores y el "Total" salían solo de la lista recortada. El dueño lee "Total"
 *    como lo vendido en el período: ahora viene `totales`, calculado sobre todo el período.
 * 4. El orden restaba unidades y `usort` lo casteaba a int: 1,5 kg empataba con 1,0.
 *
 * Cada test crea sus propios comercios (`User::create()` mínimo, el rollback los limpia), así que lo
 * que haya sembrado en la base del slot no entra en ninguna aserción: si el filtro por dueño se
 * rompiera, los tests de dos dueños lo denuncian.
 *
 * Año 2011 a propósito: `git grep` sobre `tests/` no da ninguna fecha de 2011 (la carpeta usa 2012 a
 * 2021 y 2024 a 2037).
 *
 * PHP 7.4 (nada de `?->`, `match` ni `str_contains`).
 *
 * @group reportes
 */
class Articulos_Vendidos_Test extends EmpresaTestCase
{
    /** Delta para comparar floats, mismo criterio que el resto de la carpeta. */
    const DELTA = 0.01;

    /** Primer día del período bajo prueba. */
    const DESDE = '2011-06-10';

    /** Último día del período bajo prueba (inclusive). */
    const HASTA = '2011-06-20';

    /** @var \App\Models\User */
    protected $dueno_a;

    /** @var \App\Models\User */
    protected $dueno_b;

    /**
     * Dos comercios en la misma base, y el usuario autenticado pasa a ser el primero.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno_a = User::create([
            'name'     => 'Comercio A articulos vendidos',
            'email'    => 'articulos-vendidos-a-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->dueno_b = User::create([
            'name'     => 'Comercio B articulos vendidos',
            'email'    => 'articulos-vendidos-b-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->actuar_como($this->dueno_a);
    }

    /**
     * Dos dueños venden en el mismo período: cada uno ve solo lo suyo, en la lista, en los totales,
     * en las categorías y en los proveedores.
     *
     * @test
     */
    public function dos_duenos_en_la_misma_base_cada_uno_ve_solo_lo_suyo()
    {
        $cat_a = $this->crear_categoria($this->dueno_a, 'Herramientas A');
        $prov_a = $this->crear_proveedor($this->dueno_a, 'Proveedor A');
        $cat_b = $this->crear_categoria($this->dueno_b, 'Herramientas B');
        $prov_b = $this->crear_proveedor($this->dueno_b, 'Proveedor B');

        $a1 = $this->crear_articulo($this->dueno_a, 'Martillo A', $cat_a, $prov_a);
        $a2 = $this->crear_articulo($this->dueno_a, 'Pinza A', $cat_a, $prov_a);
        $b1 = $this->crear_articulo($this->dueno_b, 'Taladro B', $cat_b, $prov_b);

        $this->vender($this->dueno_a, $this->momento(15), [[$a1, 2, 100], [$a2, 1, 50]]);
        $this->vender($this->dueno_b, $this->momento(15), [[$b1, 5, 1000]]);

        // Como A.
        $de_a = $this->pedir();

        $this->assertSame([$a1->id, $a2->id], $this->ids($de_a));
        $this->assertEqualsWithDelta(3, $de_a['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(250, $de_a['totales']['price'], self::DELTA);
        $this->assertSame(2, $de_a['totales']['cantidad_articulos']);
        $this->assertSame(['Herramientas A'], array_column($de_a['categories'], 'category_name'));
        $this->assertSame(['Proveedor A'], array_column($de_a['providers'], 'provider_name'));

        // Como B.
        $this->actuar_como($this->dueno_b);

        $de_b = $this->pedir();

        $this->assertSame([$b1->id], $this->ids($de_b));
        $this->assertEqualsWithDelta(5, $de_b['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(5000, $de_b['totales']['price'], self::DELTA);
        $this->assertSame(1, $de_b['totales']['cantidad_articulos']);
        $this->assertSame(['Herramientas B'], array_column($de_b['categories'], 'category_name'));
        $this->assertSame(['Proveedor B'], array_column($de_b['providers'], 'provider_name'));
    }

    /**
     * Un empleado ve lo de su dueño (`userId()` resuelve al dueño).
     *
     * @test
     */
    public function el_empleado_ve_lo_de_su_dueno()
    {
        $a1 = $this->crear_articulo($this->dueno_a, 'Martillo A empleado');
        $b1 = $this->crear_articulo($this->dueno_b, 'Taladro B empleado');

        $this->vender($this->dueno_a, $this->momento(12), [[$a1, 4, 25]]);
        $this->vender($this->dueno_b, $this->momento(12), [[$b1, 9, 999]]);

        $empleado = User::create([
            'name'     => 'Empleado de A articulos vendidos',
            'email'    => 'articulos-vendidos-empleado-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno_a->id,
        ]);

        $this->actuar_como($empleado);

        $respuesta = $this->pedir();

        $this->assertSame([$a1->id], $this->ids($respuesta));
        $this->assertEqualsWithDelta(4, $respuesta['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(100, $respuesta['totales']['price'], self::DELTA);
        $this->assertSame(1, $respuesta['totales']['cantidad_articulos']);
    }

    /**
     * La lista trae EXACTAMENTE `cantidad_resultados` filas, las de más unidades en `mayor-menor` y
     * las de menos en `menor-mayor`. Sin una cantidad válida, trae todas.
     *
     * @test
     */
    public function la_lista_trae_exactamente_la_cantidad_pedida()
    {
        $articulos = $this->sembrar_cinco_articulos();

        $mayor_menor = $this->pedir(['cantidad_resultados' => 3, 'orden' => 'mayor-menor']);
        $this->assertSame(
            [$articulos['u5']->id, $articulos['u4']->id, $articulos['u3']->id],
            $this->ids($mayor_menor)
        );

        $menor_mayor = $this->pedir(['cantidad_resultados' => 3, 'orden' => 'menor-mayor']);
        $this->assertSame(
            [$articulos['u1']->id, $articulos['u2']->id, $articulos['u3']->id],
            $this->ids($menor_mayor)
        );

        // El SPA manda lo que tiene el input: un texto numérico vale igual.
        $this->assertCount(3, $this->pedir(['cantidad_resultados' => '3'])['models']);

        // Con 5 pedidos y 5 vendidos vienen los 5 (el defecto devolvía 4).
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => 5])['models']);
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => 10])['models']);

        // Sin una cantidad válida, todas (con null el defecto sacaba la última).
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => null])['models']);
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => ''])['models']);
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => 0])['models']);
        $this->assertCount(5, $this->pedir(['cantidad_resultados' => -2])['models']);
    }

    /**
     * 🔴 `totales`, `categories` y `providers` son del período entero, no de la lista recortada:
     * pedir 3 o pedir 100 da los mismos números, y las categorías/proveedores de los artículos que
     * quedaron fuera de la lista están igual.
     *
     * @test
     */
    public function los_totales_y_los_graficos_son_del_periodo_y_no_de_la_lista()
    {
        $this->sembrar_cinco_articulos();

        $recortada = $this->pedir(['cantidad_resultados' => 3]);
        $completa = $this->pedir(['cantidad_resultados' => 100]);

        $this->assertCount(3, $recortada['models']);
        $this->assertCount(5, $completa['models']);

        $this->assertEquals($completa['totales'], $recortada['totales']);

        // 5 + 4 + 3 + 2 + 1 unidades; precio unitario 10 → 50 + 40 + 30 + 20 + 10.
        $this->assertEqualsWithDelta(15, $recortada['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(150, $recortada['totales']['price'], self::DELTA);
        $this->assertEqualsWithDelta(60, $recortada['totales']['cost'], self::DELTA);
        $this->assertEqualsWithDelta(90, $recortada['totales']['beneficio'], self::DELTA);
        $this->assertEqualsWithDelta(0, $recortada['totales']['price_dolar'], self::DELTA);
        $this->assertSame(5, $recortada['totales']['cantidad_articulos']);

        // Los dos artículos de menos unidades (fuera de la lista de 3) están en los gráficos.
        $categorias = $this->por_nombre($recortada['categories'], 'category_name');
        $this->assertEquals(['Top', 'Cola'], array_keys($categorias));
        $this->assertEqualsWithDelta(12, $categorias['Top']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(3, $categorias['Cola']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(30, $categorias['Cola']['price'], self::DELTA);

        $proveedores = $this->por_nombre($recortada['providers'], 'provider_name');
        $this->assertEquals(['Proveedor top', 'Proveedor cola'], array_keys($proveedores));
        $this->assertEqualsWithDelta(3, $proveedores['Proveedor cola']['unidades_vendidas'], self::DELTA);

        $this->assertEquals($completa['categories'], $recortada['categories']);
        $this->assertEquals($completa['providers'], $recortada['providers']);
    }

    /**
     * Una venta borrada (con su `article_purchase` todavía ahí) y una contenedora de consolidación
     * AFIP no suman. Una con `is_consolidacion_facturacion = 0` sí, y el último día del período es
     * inclusivo hasta las 23:59.
     *
     * @test
     */
    public function la_venta_borrada_y_la_consolidacion_no_suman()
    {
        $articulo = $this->crear_articulo($this->dueno_a, 'Martillo borrada');

        $this->vender($this->dueno_a, $this->momento(15), [[$articulo, 2, 100]]);

        $borrada = $this->vender($this->dueno_a, $this->momento(15), [[$articulo, 10, 100]]);
        $borrada->delete();

        $this->vender($this->dueno_a, $this->momento(15), [[$articulo, 7, 100]], ['is_consolidacion_facturacion' => 1]);
        $this->vender($this->dueno_a, $this->momento(16), [[$articulo, 3, 100]], ['is_consolidacion_facturacion' => 0]);
        $this->vender($this->dueno_a, Carbon::parse(self::HASTA.' 23:30:00'), [[$articulo, 1, 100]]);
        $this->vender($this->dueno_a, Carbon::parse(self::HASTA)->addDay()->setTime(0, 30), [[$articulo, 50, 100]]);

        // Precondición: el borrado es blando y el renglón vendido sigue en article_purchases.
        $this->assertNotNull(Sale::withTrashed()->find($borrada->id)->deleted_at);
        $this->assertSame(1, ArticlePurchase::where('sale_id', $borrada->id)->count());

        $respuesta = $this->pedir();

        $this->assertSame([$articulo->id], $this->ids($respuesta));
        $this->assertEqualsWithDelta(6, $respuesta['models'][0]['unidades_vendidas'], self::DELTA, '2 + 3 + 1.');
        $this->assertEqualsWithDelta(600, $respuesta['models'][0]['price'], self::DELTA);
        $this->assertEqualsWithDelta(6, $respuesta['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(600, $respuesta['totales']['price'], self::DELTA);
    }

    /**
     * 1,5 unidades ordena antes que 1,0 en `mayor-menor` (antes la resta se casteaba a int y
     * empataban), en la lista y en las categorías.
     *
     * @test
     */
    public function las_unidades_fraccionarias_ordenan_bien()
    {
        $por_unidad = $this->crear_categoria($this->dueno_a, 'Por unidad');
        $por_kilo = $this->crear_categoria($this->dueno_a, 'Por kilo');

        // El de 1,0 se crea y se vende primero, y con precio más alto: si el orden volviera a
        // empatar 1,5 con 1,0, quedaría adelante y el test lo denuncia.
        $entero = $this->crear_articulo($this->dueno_a, 'Pala entera', $por_unidad);
        $fraccion = $this->crear_articulo($this->dueno_a, 'Clavos sueltos', $por_kilo);

        $this->vender($this->dueno_a, $this->momento(11), [[$entero, 1, 1000]]);
        $this->vender($this->dueno_a, $this->momento(12), [[$fraccion, 1.5, 10]]);

        $mayor_menor = $this->pedir(['orden' => 'mayor-menor']);
        $this->assertSame([$fraccion->id, $entero->id], $this->ids($mayor_menor));
        $this->assertSame(['Por kilo', 'Por unidad'], array_column($mayor_menor['categories'], 'category_name'));
        $this->assertEqualsWithDelta(2.5, $mayor_menor['totales']['unidades_vendidas'], self::DELTA);

        $menor_mayor = $this->pedir(['orden' => 'menor-mayor']);
        $this->assertSame([$entero->id, $fraccion->id], $this->ids($menor_mayor));
        $this->assertSame(['Por unidad', 'Por kilo'], array_column($menor_mayor['categories'], 'category_name'));
    }

    /**
     * Con las mismas unidades, el empate se desarma siempre igual: más plata primero y, con la misma
     * plata, el id de artículo más bajo. Y el recorte respeta ese orden.
     *
     * @test
     */
    public function los_empates_se_desarman_por_precio_y_despues_por_id()
    {
        $primero = $this->crear_articulo($this->dueno_a, 'Empate barato 1');
        $caro = $this->crear_articulo($this->dueno_a, 'Empate caro');
        $segundo = $this->crear_articulo($this->dueno_a, 'Empate barato 2');

        $this->vender($this->dueno_a, $this->momento(13), [[$segundo, 2, 25], [$caro, 2, 40], [$primero, 2, 25]]);

        $this->assertSame([$caro->id, $primero->id, $segundo->id], $this->ids($this->pedir(['orden' => 'mayor-menor'])));
        $this->assertSame([$caro->id, $primero->id, $segundo->id], $this->ids($this->pedir(['orden' => 'menor-mayor'])));
        $this->assertSame([$caro->id, $primero->id], $this->ids($this->pedir(['cantidad_resultados' => 2])));
    }

    /**
     * El filtro por proveedor sigue andando, y los totales lo respetan.
     *
     * @test
     */
    public function el_filtro_por_proveedor_sigue_andando_y_los_totales_lo_respetan()
    {
        $uno = $this->crear_proveedor($this->dueno_a, 'Proveedor uno');
        $dos = $this->crear_proveedor($this->dueno_a, 'Proveedor dos');

        $de_uno = $this->crear_articulo($this->dueno_a, 'Articulo del uno', null, $uno);
        $de_dos = $this->crear_articulo($this->dueno_a, 'Articulo del dos', null, $dos);

        $this->vender($this->dueno_a, $this->momento(14), [[$de_uno, 3, 100], [$de_dos, 4, 200]]);

        $sin_filtro = $this->pedir();
        $this->assertSame(2, $sin_filtro['totales']['cantidad_articulos']);
        $this->assertEqualsWithDelta(1100, $sin_filtro['totales']['price'], self::DELTA);

        $filtrado = $this->pedir(['provider_id' => $uno->id]);

        $this->assertSame([$de_uno->id], $this->ids($filtrado));
        $this->assertEqualsWithDelta(3, $filtrado['totales']['unidades_vendidas'], self::DELTA);
        $this->assertEqualsWithDelta(300, $filtrado['totales']['price'], self::DELTA);
        $this->assertSame(1, $filtrado['totales']['cantidad_articulos']);
        $this->assertSame(['Proveedor uno'], array_column($filtrado['providers'], 'provider_name'));
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Cinco artículos del dueño A con 5, 4, 3, 2 y 1 unidades vendidas a $10 (costo $4). Los tres
     * de más unidades van a la categoría "Top" / "Proveedor top"; los dos de menos, a "Cola" /
     * "Proveedor cola" — así, con la lista recortada a 3, la cola queda afuera de `models`.
     *
     * @return array<string, \App\Models\Article> Indexado 'u5' … 'u1'.
     */
    protected function sembrar_cinco_articulos()
    {
        $top = $this->crear_categoria($this->dueno_a, 'Top');
        $cola = $this->crear_categoria($this->dueno_a, 'Cola');
        $prov_top = $this->crear_proveedor($this->dueno_a, 'Proveedor top');
        $prov_cola = $this->crear_proveedor($this->dueno_a, 'Proveedor cola');

        $articulos = [];

        // Se crean de menos a más unidades para que el orden de inserción no coincida con el pedido.
        foreach ([1, 2, 3, 4, 5] as $unidades) {

            $es_top = $unidades >= 3;

            $articulo = $this->crear_articulo(
                $this->dueno_a,
                'Articulo '.$unidades.' unidades',
                $es_top ? $top : $cola,
                $es_top ? $prov_top : $prov_cola
            );

            $this->vender($this->dueno_a, $this->momento(10 + $unidades), [[$articulo, $unidades, 10, 4]]);

            $articulos['u'.$unidades] = $articulo;
        }

        return $articulos;
    }

    /**
     * Pega al endpoint como lo hace el SPA y devuelve el JSON decodificado.
     *
     * @param array $parametros Pisan a los de por defecto (período entero, 100 resultados, mayor-menor).
     * @return array
     */
    protected function pedir(array $parametros = [])
    {
        $respuesta = $this->postJson('api/article-purchase', array_merge([
            'mes_inicio'          => self::DESDE,
            'mes_fin'             => self::HASTA,
            'cantidad_resultados' => 100,
            'orden'               => 'mayor-menor',
            'sale_channel_id'     => 0,
        ], $parametros));

        $respuesta->assertStatus(200);

        return $respuesta->json();
    }

    /**
     * Cambia el usuario autenticado para las requests que siguen.
     *
     * 🔴 El `Auth::forgetGuards()` no es decorativo: la ruta vive bajo `auth:sanctum`, cuyo guard es
     * un `RequestGuard` que cachea el primer usuario que resolvió. Sin olvidarlo, el segundo
     * `actingAs()` escribe en `web` pero la request sigue resolviendo al primero (ver
     * `tests/Feature/Alertas/1_Ventas_sin_cobrar_dias_Test.php`).
     *
     * @param \App\Models\User $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * Una venta del dueño con sus renglones en `article_purchases`, escritos a mano (es setup: lo
     * que se prueba es el reporte, no `ArticlePurchaseHelper`).
     *
     * @param \App\Models\User $dueno
     * @param \Carbon\Carbon   $momento
     * @param array            $renglones Cada uno: [Article, cantidad, precio unitario, costo unitario = 0].
     * @param array            $extra     Columnas de la venta que pisan a las de por defecto.
     * @return \App\Models\Sale
     */
    protected function vender(User $dueno, Carbon $momento, array $renglones, array $extra = [])
    {
        $venta = Sale::create(array_merge([
            'user_id'    => $dueno->id,
            'total'      => 0,
            'terminada'  => 1,
            'moneda_id'  => 1,
            'created_at' => $momento,
        ], $extra));

        foreach ($renglones as $renglon) {

            ArticlePurchase::create([
                'sale_id'     => $venta->id,
                'article_id'  => $renglon[0]->id,
                'category_id' => $renglon[0]->category_id,
                'amount'      => $renglon[1],
                'price'       => $renglon[2],
                'cost'        => isset($renglon[3]) ? $renglon[3] : 0,
                'created_at'  => $momento,
            ]);
        }

        return $venta;
    }

    /**
     * @param \App\Models\User          $dueno
     * @param string                    $nombre
     * @param \App\Models\Category|null $categoria
     * @param \App\Models\Provider|null $proveedor
     * @return \App\Models\Article
     */
    protected function crear_articulo(User $dueno, $nombre, $categoria = null, $proveedor = null)
    {
        return Article::create([
            'name'        => $nombre,
            'user_id'     => $dueno->id,
            'category_id' => $categoria ? $categoria->id : null,
            'provider_id' => $proveedor ? $proveedor->id : null,
        ]);
    }

    /**
     * @param \App\Models\User $dueno
     * @param string           $nombre
     * @return \App\Models\Category
     */
    protected function crear_categoria(User $dueno, $nombre)
    {
        return Category::create(['name' => $nombre, 'user_id' => $dueno->id]);
    }

    /**
     * @param \App\Models\User $dueno
     * @param string           $nombre
     * @return \App\Models\Provider
     */
    protected function crear_proveedor(User $dueno, $nombre)
    {
        return Provider::create(['name' => $nombre, 'user_id' => $dueno->id]);
    }

    /**
     * Un momento del período bajo prueba: el día `$dia` de junio de 2011, a las 10.
     *
     * @param int $dia
     * @return \Carbon\Carbon
     */
    protected function momento($dia)
    {
        return Carbon::create(2011, 6, $dia, 10, 0, 0);
    }

    /**
     * Ids de artículo de la lista, en el orden en que vienen.
     *
     * @param array $respuesta
     * @return array<int, int>
     */
    protected function ids(array $respuesta)
    {
        return array_map('intval', array_column($respuesta['models'], 'article_id'));
    }

    /**
     * Indexa las filas de categorías o proveedores por nombre, conservando el orden.
     *
     * @param array  $filas
     * @param string $campo
     * @return array<string, array>
     */
    protected function por_nombre(array $filas, $campo)
    {
        $indexadas = [];

        foreach ($filas as $fila) {
            $indexadas[$fila[$campo]] = $fila;
        }

        return $indexadas;
    }
}
