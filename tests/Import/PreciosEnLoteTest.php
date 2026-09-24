<?php

namespace Tests\Import;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use App\Models\PriceType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Modo lote del cálculo de precios de la importación (PreciosEnLote, misión
 * importacion-excel-motor-rapido, 24/9/2026).
 *
 * Lo que se fija: con el modo lote encendido, ArticleHelper::setFinalPrice() deja en la base
 * EXACTAMENTE lo mismo que con el modo apagado -- las filas de article_price_type (todas sus
 * columnas), las de price_changes y las de price_change_price_type --, solo que escribiéndolas en
 * bloque al final del lote en vez de tres o cuatro consultas por lista por artículo. Y fuera del
 * modo lote, nada cambia: mismas escrituras de siempre.
 *
 * Los dos modos se comparan SOBRE LA MISMA BASE: cada corrida va adentro de un savepoint
 * (DB::beginTransaction() anidado en la transacción de DatabaseTransactions) que se revierte al
 * terminar, así la segunda arranca del mismo estado que la primera. Los ids autoincrementales no
 * se reusan después de un rollback, así que las fotos se indexan por artículo y lista, nunca por
 * id de fila.
 *
 * El escenario cubre: artículo con listas ya atadas y otro sin ninguna, con cambio de precio y sin
 * cambio, una lista con precio fijado a mano, un precio manual con margen propio (el camino que
 * pone `price` en null) y un artículo sin costo ni precio.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class PreciosEnLoteTest extends ImportTestCase
{
    /** Fixture cuyo mapeo por defecto no trae columnas de lista: crea PC-STK-NEW y actualiza cinco. */
    const ARCHIVO = '04_stock.xlsx';

    /** Fixture de 50 filas, para la medición de consultas por artículo. */
    const ARCHIVO_50_FILAS = '06_incidente_servian.xlsx';

    /** Tablas cuyas escrituras difiere el modo lote. */
    const TABLAS_DE_PRECIOS = '/`?(article_price_type|price_changes|price_change_price_type)`?/i';

    /** @var \App\Models\PriceType */
    protected $lista_a;

    /** @var \App\Models\PriceType */
    protected $lista_b;

    /** @var array SQL de las consultas ejecutadas mientras $contando está prendido. */
    protected $consultas = [];

    /** @var bool */
    protected $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        /* Por si un test anterior del mismo proceso dejó el modo prendido o deshabilitado. */
        PreciosEnLote::descartar();
        PreciosEnLote::deshabilitar(false);

        /*
         * Mismo montaje que ListasDePrecioPorDefectoTest: el tenant 900 no trabaja con listas y no
         * tiene ninguna, así que se fabrican acá, adentro de la transacción del test. Va después de
         * parent::setUp() para que el sembrado de A1..A15 no las ate todavía: así hay artículos
         * CON listas (los que pasan por la importación) y SIN listas (los demás sembrados).
         */
        $this->tenant->listas_de_precio = 1;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $this->lista_a = $this->crear_price_type('Lista A', 30, 1, 0, 1);
        $this->lista_b = $this->crear_price_type('Lista B', 40, 0, 1, 2);

        $this->consultas = [];
        $this->contando  = false;

        DB::listen(function ($query) {
            if ($this->contando) {
                $this->consultas[] = $query->sql;
            }
        });
    }

    protected function tearDown(): void
    {
        PreciosEnLote::descartar();
        PreciosEnLote::deshabilitar(false);

        /* Caches estáticos que viven en el proceso, no en la base (mismo criterio que RecalculoNoInflaPreciosCambiadosTest). */
        ArticlePricesHelper::$sale_taxes_cache = [];
        ArticlePricesHelper::$payment_method_layer3_cache = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------------------------------
     * Los tests
     * ---------------------------------------------------------------------------------------- */

    /**
     * El test central: sobre el mismo escenario, el modo lote y el modo por artículo dejan las
     * mismas filas, campo por campo, en article_price_type, price_changes y
     * price_change_price_type, y setFinalPrice() devuelve los mismos valores calculados. Además
     * el modo lote no escribe nada en esas tablas hasta volcar(), y volcar() lo hace en cuatro
     * consultas para todo el lote.
     *
     * @return void
     */
    public function test_el_modo_lote_deja_lo_mismo_en_la_base_que_el_modo_por_articulo()
    {
        $ids = $this->armar_escenario();

        $marca = $this->marca_de_price_changes();

        $this->calentar_caches_estaticos($ids[0]);

        /* Modo por artículo, como hoy: los modelos se releen sin relaciones cargadas. */
        DB::beginTransaction();
        $por_articulo      = $this->calcular(Article::whereIn('id', $ids)->orderBy('id')->get(), false);
        $foto_por_articulo = $this->foto_de_precios($ids, $marca);
        DB::rollBack();

        /* Modo lote, como en ActualizarBBDD: relectura con las relaciones precargadas. */
        DB::beginTransaction();
        $en_lote      = $this->calcular(Article::with(PreciosEnLote::RELACIONES_A_PRECARGAR)->whereIn('id', $ids)->orderBy('id')->get(), true);
        $foto_en_lote = $this->foto_de_precios($ids, $marca);
        DB::rollBack();

        /* Guardas: si el escenario no produjo pivots ni cambios de precio, la comparación no prueba nada. */
        $this->assertGreaterThanOrEqual(2 * count($ids), $foto_por_articulo['filas_de_pivot'], 'Cada artículo tenía que quedar con sus dos listas.');
        $this->assertGreaterThanOrEqual(3, count($foto_por_articulo['cambios']), 'El escenario tenía que registrar varios cambios de precio.');

        $this->assertEquals(
            $foto_por_articulo,
            $foto_en_lote,
            'El modo lote dejó en la base algo distinto del modo por artículo.'
        );

        $this->assertEquals(
            $por_articulo['resultados'],
            $en_lote['resultados'],
            'setFinalPrice() devolvió valores distintos según el modo: el cálculo no puede depender de cómo se escribe.'
        );

        /* En modo lote, las tablas de precios no se tocan durante el loop... */
        $this->assertSame(
            0,
            $this->escrituras_sobre_precios($en_lote['loop']),
            'Con el modo lote encendido no puede haber ninguna escritura de pivots o price_changes por artículo.'
        );

        $this->assertGreaterThan(
            0,
            $this->escrituras_sobre_precios($por_articulo['loop']),
            'El modo por artículo sí escribe adentro del loop: si no, el test no compara nada.'
        );

        /* ...y volcar() las escribe en cuatro consultas: INSERT de pares nuevos, UPDATE de los que
           existían, INSERT de price_changes e INSERT de price_change_price_type. */
        $this->assertSame(
            4,
            $this->escrituras_sobre_precios($en_lote['volcar']),
            'volcar() tenía que escribir en cuatro consultas: ' . implode("\n", $en_lote['volcar'])
        );

        /* El `price = null; save()` de setFinalPrice(): en lote corre solo para A5 (precio manual
           que hay que borrar); A4 tiene margen propio con price ya en null y costo_real sucio, y NO
           se guarda: eso lo escribe updateMasivo(). Por artículo, A4 sí se guarda, como hoy. */
        $this->assertSame(1, $this->consultas_que_nombran($en_lote['loop'], '/^update `articles`/i'), 'En lote, el único save() del loop es el de A5. Loop: ' . implode("\n", $en_lote['loop']));
        $this->assertSame(2, $this->consultas_que_nombran($por_articulo['loop'], '/^update `articles`/i'), 'Por artículo se guardan A4 (costo_real sucio) y A5, como hoy.');

        /* Y con las relaciones precargadas, el loop no consulta ni el pivot ni el IVA por artículo. */
        $this->assertSame(0, $this->consultas_que_nombran($en_lote['loop'], '/article_price_type/i'), 'En modo lote el pivot se lee de la relación precargada, sin consultas.');
        $this->assertSame(0, $this->consultas_que_nombran($en_lote['loop'], '/from `?ivas`?/i'), 'En modo lote el IVA sale de la relación precargada, sin consultas.');

        $this->assertLessThan(
            count($ids),
            count($en_lote['loop']),
            'En modo lote el loop tiene que ejecutar menos de una consulta por artículo. Ejecutó: ' . implode("\n", $en_lote['loop'])
        );
    }

    /**
     * Fuera del modo lote, una llamada suelta a setFinalPrice() escribe exactamente como siempre:
     * por cada lista, el select + insert de syncWithoutDetaching() y el update de
     * updateExistingPivot(); un insert en price_changes y un attach por lista. Nada en bloque, y
     * ninguna consulta nueva: la única diferencia medible respecto de antes es que la relación
     * `iva` ya no se vuelve a consultar en cada paso (antes eran 1 + 2 por lista).
     *
     * @return void
     */
    public function test_fuera_del_modo_lote_setFinalPrice_escribe_igual_que_siempre()
    {
        /*
         * IVA DESPUÉS del margen (el tenant 900 lo lleva al costo): así aplicar_iva() corre en el
         * precio único y en cada lista, y quitar_iva_y_sale_taxes() en cada lista. Son 1 + 2 por
         * lista cargas de `iva` en develop (cinco con dos listas); con cargar_iva_vigente(), una.
         */
        $this->tenant->aplicar_iva_al_costo = 0;
        $this->tenant->save();
        $this->actingAs($this->tenant, 'web');

        $a2 = $this->recargar('A2');
        $a2->cost = 250;
        $a2->save();

        $this->calentar_caches_estaticos($a2->id);

        $price_types = $this->listas_del_tenant();

        $this->assertFalse(PreciosEnLote::esta_activo(), 'El modo lote tiene que estar apagado en una llamada suelta.');

        /* Como cualquier llamador de hoy: el modelo recién leído, sin relaciones cargadas. */
        $article = Article::find($a2->id);

        $this->consultas = [];
        $this->contando  = true;

        ArticleHelper::setFinalPrice($article, $this->tenant->id, $this->tenant, $this->tenant->id, false, $price_types);

        $this->contando = false;

        $consultas = $this->consultas;

        $cantidad_de_listas = count($price_types);

        $this->assertSame(2, $cantidad_de_listas);

        $this->assertSame($cantidad_de_listas, $this->consultas_que_nombran($consultas, '/^insert into `article_price_type`/i'), 'Un insert por lista (syncWithoutDetaching, el artículo no tenía listas).');
        $this->assertSame($cantidad_de_listas, $this->consultas_que_nombran($consultas, '/^update `article_price_type` set/i'), 'Un update por lista (updateExistingPivot).');
        $this->assertSame(0, $this->consultas_que_nombran($consultas, '/^update `article_price_type` set .* case /i'), 'Fuera del modo lote no hay UPDATE ... CASE.');
        $this->assertSame(1, $this->consultas_que_nombran($consultas, '/^insert into `price_changes`/i'), 'Un insert en price_changes (cambió el precio).');
        $this->assertSame($cantidad_de_listas, $this->consultas_que_nombran($consultas, '/^insert into `price_change_price_type`/i'), 'Un attach por lista.');
        $this->assertSame(0, $this->consultas_que_nombran($consultas, '/values \(.*\), \(/i'), 'Fuera del modo lote no hay inserts multi-fila.');

        $this->assertSame(1, $this->consultas_que_nombran($consultas, '/from `ivas`/i'), 'El IVA se consulta una sola vez por llamada: la relación cargada se reusa mientras coincida con iva_id.');

        $pendientes = PreciosEnLote::pendientes();

        $this->assertSame(0, $pendientes['pares'] + $pendientes['cambios'], 'Fuera del modo lote no se registra nada.');

        /* Y la base quedó como la deja el camino de siempre. */
        $pivots = $this->pivots_de([$a2->id]);

        $this->assertSame(2, $pivots['filas'], 'A2 quedó atado a las dos listas, sin duplicados.');
        $this->assertDecimal(30, $pivots['por_articulo'][$a2->id][$this->lista_a->id]['percentage']);
        $this->assertDecimal(40, $pivots['por_articulo'][$a2->id][$this->lista_b->id]['percentage']);
    }

    /**
     * De punta a punta: la misma importación con el modo lote deshabilitado y habilitado deja la
     * misma base (artículos, pivots de listas y cambios de precio).
     *
     * Depende de que ActualizarBBDD::set_precios_finales() encienda el modo lote (constructor B1
     * de la misión): si todavía no lo hace, las dos corridas son idénticas por definición y el test
     * se marca como salteado en vez de pasar en falso.
     *
     * @return void
     */
    public function test_una_importacion_en_modo_lote_deja_la_misma_base_que_por_articulo()
    {
        $marca = $this->marca_de_price_changes();

        $volcados_antes = PreciosEnLote::volcados();

        /* Corrida 1: por artículo. */
        PreciosEnLote::deshabilitar(true);

        DB::beginTransaction();
        $this->importar(self::ARCHIVO, ['provider_id' => null]);
        $foto_por_articulo = $this->foto_del_tenant($marca);
        DB::rollBack();

        $this->assertSame($volcados_antes, PreciosEnLote::volcados(), 'Con el modo deshabilitado, volcar() no tenía que escribir nada.');

        /* Entre corridas: el índice de artículos vive en cache y en estáticos, no en la base. */
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();

        /* Corrida 2: en lote. */
        PreciosEnLote::deshabilitar(false);

        DB::beginTransaction();
        $this->importar(self::ARCHIVO, ['provider_id' => null]);
        $foto_en_lote = $this->foto_del_tenant($marca);
        DB::rollBack();

        if (PreciosEnLote::volcados() === $volcados_antes) {
            $this->markTestSkipped('ActualizarBBDD::set_precios_finales() todavía no enciende PreciosEnLote (lo hace el constructor B1): las dos corridas son idénticas por definición.');
        }

        $this->assertGreaterThan(0, $foto_por_articulo['filas_de_pivot'], 'La importación tenía que dejar pivots de listas.');
        $this->assertGreaterThan(0, count($foto_por_articulo['cambios']), 'La importación tenía que registrar cambios de precio.');

        /*
         * Artículos, pivots y cambios de precio se comparan entre los dos modos, MENOS el precio de
         * cada lista dentro del cambio (price_change_price_type.final_price). Ahí la corrida por
         * artículo ya no es la referencia: con la precarga de relaciones que hace ActualizarBBDD
         * (misma misión), PriceChangeController::store() lee `price_types` cargada ANTES de que
         * el helper de listas escribiera el pivot, y registra el precio viejo (o ninguna fila para
         * un artículo recién atado). develop, sin precarga, releía la relación fresca y registraba
         * el precio recién escrito. El modo lote reproduce a develop, y eso es lo que se asierta
         * abajo: el precio de cada lista en el cambio es el que quedó en el pivot.
         */
        $sin_listas = function (array $foto) {
            foreach ($foto['cambios'] as $clave => $cambios) {
                foreach ($cambios as $i => $cambio) {
                    unset($foto['cambios'][$clave][$i]['listas']);
                }
            }
            return $foto;
        };

        $this->assertEquals(
            $sin_listas($foto_por_articulo),
            $sin_listas($foto_en_lote),
            'La importación en modo lote dejó una base distinta de la importación por artículo.'
        );

        $cambios_con_listas = 0;

        foreach ($foto_en_lote['cambios'] as $clave => $cambios) {

            $esperado = [];

            foreach ($foto_en_lote['pivots'][$clave] as $price_type_id => $pivot) {
                $esperado[$price_type_id] = $pivot['final_price'];
            }

            ksort($esperado);

            foreach ($cambios as $cambio) {

                $this->assertEquals(
                    $esperado,
                    $cambio['listas'],
                    'El cambio de precio de ' . $clave . ' tiene que registrar el precio de cada lista tal como quedó en el pivot (lo que develop hacía al releer la relación recién escrita).'
                );

                if (count($cambio['listas']) > 0) {
                    $cambios_con_listas++;
                }
            }
        }

        $this->assertGreaterThan(0, $cambios_con_listas, 'Algún cambio de precio tenía que llevar el detalle por lista.');
    }

    /**
     * pivot_actual() devuelve la fila del pivot tal como quedaría después de lo que el lote tiene
     * pendiente: lo registrado encima de lo cargado, un par nuevo con los defaults del esquema, y
     * null para un par que no existe ni se va a crear.
     *
     * @return void
     */
    public function test_pivot_actual_devuelve_la_fila_con_lo_pendiente_del_lote_encima()
    {
        $a1 = $this->recargar('A1');

        $a1->price_types()->attach($this->lista_a->id, ['percentage' => 30, 'final_price' => 500, 'setear_precio_final' => 1]);

        PreciosEnLote::activar($this->tenant, $this->tenant->id);

        $article = Article::find($a1->id);

        /* Sin nada pendiente: la fila cargada. */
        $fila = PreciosEnLote::pivot_actual($article, $this->lista_a->id);

        $this->assertNotNull($fila);
        $this->assertDecimal(500, $fila->pivot->final_price);
        $this->assertSame(1, (int) $fila->pivot->setear_precio_final);

        $this->assertNull(PreciosEnLote::pivot_actual($article, $this->lista_b->id), 'Un par que no existe y no tiene alta pendiente no está.');

        /* Con una escritura pendiente encima. */
        PreciosEnLote::registrar_pivot($article, $this->lista_a->id, ['final_price' => 777, 'previus_final_price' => 500]);

        $fila = PreciosEnLote::pivot_actual($article, $this->lista_a->id);

        $this->assertDecimal(777, $fila->pivot->final_price, 'Lo pendiente pisa lo cargado.');
        $this->assertDecimal(500, $fila->pivot->previus_final_price);
        $this->assertSame(1, (int) $fila->pivot->setear_precio_final, 'Lo que no se registró sigue siendo lo cargado.');

        /* Un alta pendiente: los defaults del esquema más lo registrado. */
        PreciosEnLote::registrar_pivot($article, $this->lista_b->id, ['percentage' => 40, 'final_price' => 900]);

        $fila = PreciosEnLote::pivot_actual($article, $this->lista_b->id);

        $this->assertNotNull($fila, 'Un par con alta pendiente ya se ve.');
        $this->assertDecimal(900, $fila->pivot->final_price);
        $this->assertSame(0, (int) $fila->pivot->setear_precio_final, 'Default del esquema para lo no registrado.');
        $this->assertNull($fila->pivot->previus_final_price);

        /* Un update-only sobre un par inexistente no lo hace aparecer. */
        $a2 = Article::find($this->recargar('A2')->id);

        PreciosEnLote::registrar_pivot($a2, $this->lista_a->id, ['final_price' => 1], false);

        $this->assertNull(PreciosEnLote::pivot_actual($a2, $this->lista_a->id));

        $pendientes = PreciosEnLote::pendientes();

        $this->assertSame(3, $pendientes['pares']);

        PreciosEnLote::descartar();

        $this->assertFalse(PreciosEnLote::esta_activo());
        $this->assertSame(0, $this->pivots_de([$a1->id, $a2->id])['filas'] - 1, 'descartar() no escribe nada: A1 sigue con su única fila y A2 sin ninguna.');
    }

    /**
     * volcar() escribe lo pendiente y apaga el modo; con el modo apagado o sin nada pendiente no
     * hace nada; un update-only sobre un par inexistente no crea la fila.
     *
     * @return void
     */
    public function test_volcar_escribe_lo_pendiente_y_deja_el_modo_apagado_y_sin_restos()
    {
        $a1 = $this->recargar('A1');
        $a2 = $this->recargar('A2');

        /* A1 ya está atado a las dos listas; A2 a ninguna. */
        $a1->price_types()->attach($this->lista_a->id, ['percentage' => 30, 'final_price' => 500, 'precio_luego_de_recargos' => 480]);
        $a1->price_types()->attach($this->lista_b->id, ['percentage' => 40, 'final_price' => 300, 'setear_precio_final' => 1]);

        $marca = $this->marca_de_price_changes();

        PreciosEnLote::activar($this->tenant, $this->tenant->id);

        $this->assertTrue(PreciosEnLote::esta_activo());

        $articulo_1 = Article::find($a1->id);
        $articulo_2 = Article::find($a2->id);

        /* Un par existente con tres columnas y otro existente con UNA sola (como el espejo en pesos):
           el UPDATE ... CASE tiene que dejar intactas las columnas que cada par no trae. */
        PreciosEnLote::registrar_pivot($articulo_1, $this->lista_a->id, ['final_price' => 650, 'previus_final_price' => 500, 'percentage' => 30]);
        PreciosEnLote::registrar_pivot($articulo_1, $this->lista_b->id, ['percentage' => 41], false);

        /* Un par nuevo que se ata, y un update-only sobre un par inexistente, que no. */
        PreciosEnLote::registrar_pivot($articulo_2, $this->lista_a->id, ['final_price' => 700, 'previus_final_price' => null, 'percentage' => 40]);
        PreciosEnLote::registrar_pivot($articulo_2, $this->lista_b->id, ['final_price' => 1], false);

        $articulo_1->final_price = 999.5;

        PreciosEnLote::registrar_cambio_de_precio($articulo_1, $this->tenant->id);

        PreciosEnLote::volcar();

        $this->assertFalse(PreciosEnLote::esta_activo(), 'volcar() apaga el modo.');

        $pendientes = PreciosEnLote::pendientes();

        $this->assertSame(0, $pendientes['pares'] + $pendientes['cambios'], 'volcar() deja el recolector vacío.');

        $resumen = PreciosEnLote::ultimo_resumen();

        $this->assertSame(4, $resumen['pares']);
        $this->assertSame(1, $resumen['insertados'], 'Solo el par nuevo de A2 con la lista A se inserta.');
        $this->assertSame(2, $resumen['actualizados'], 'Los dos pares existentes de A1 se actualizan.');
        $this->assertSame(1, $resumen['cambios']);
        $this->assertSame(2, $resumen['filas_de_listas']);

        $pivots = $this->pivots_de([$a1->id, $a2->id]);

        $this->assertSame(3, $pivots['filas'], 'A1 con dos listas, A2 con una: el update-only sobre un par inexistente no crea la fila.');

        $a1_a = $pivots['por_articulo'][$a1->id][$this->lista_a->id];
        $a1_b = $pivots['por_articulo'][$a1->id][$this->lista_b->id];
        $a2_a = $pivots['por_articulo'][$a2->id][$this->lista_a->id];

        $this->assertDecimal(650, $a1_a['final_price']);
        $this->assertDecimal(500, $a1_a['previus_final_price']);
        $this->assertDecimal(480, $a1_a['precio_luego_de_recargos'], 'La columna que el par no registró queda como estaba (ELSE columna).');

        $this->assertDecimal(41, $a1_b['percentage'], 'La única columna registrada del par se escribe...');
        $this->assertDecimal(300, $a1_b['final_price'], '...y las demás quedan como estaban, aunque otro par de la misma tanda las traiga.');
        $this->assertSame(1, (int) $a1_b['setear_precio_final']);

        $this->assertDecimal(700, $a2_a['final_price']);
        $this->assertNull($a2_a['previus_final_price']);
        $this->assertNull($a2_a['created_at'], 'La relación no tiene withTimestamps(): el pivot queda sin created_at, como hoy.');
        $this->assertSame(0, (int) $a2_a['setear_precio_final'], 'Default del esquema para la columna no registrada.');

        $cambios = $this->cambios_de_precio_desde($marca, [$a1->id]);

        $this->assertCount(1, $cambios[$a1->id]);
        $this->assertDecimal(999.5, $cambios[$a1->id][0]['final_price']);
        $this->assertSame((string) $this->tenant->id, (string) $cambios[$a1->id][0]['employee_id']);
        $this->assertEquals(
            [$this->lista_a->id => $this->normalizar(650), $this->lista_b->id => $this->normalizar(300)],
            $cambios[$a1->id][0]['listas'],
            'El precio de cada lista en price_change_price_type es el que quedó DESPUÉS de las escrituras del lote: el registrado para la lista A, el del pivot cargado para la lista B, que el lote no le tocó el precio.'
        );

        /* Volver a volcar sin nada, o sin el modo encendido, no hace nada. */
        PreciosEnLote::volcar();
        PreciosEnLote::activar($this->tenant, $this->tenant->id);
        PreciosEnLote::volcar();

        $this->assertSame(3, $this->pivots_de([$a1->id, $a2->id])['filas']);
        $this->assertCount(1, $this->cambios_de_precio_desde($marca, [$a1->id])[$a1->id]);
    }

    /**
     * Medición, no solo aserción: consultas por artículo del cálculo de precios de un lote de 50
     * filas con dos listas, por artículo (como antes de la misión) y en lote. Los números se
     * imprimen en la salida de PHPUnit para el informe; la aserción es que el modo lote ejecuta
     * menos de una consulta por artículo más las cuatro escrituras del volcado.
     *
     * @return void
     */
    public function test_medicion_consultas_por_articulo_con_50_filas_y_dos_listas()
    {
        $import = $this->importar(self::ARCHIVO_50_FILAS);

        $this->assertSame(50, (int) $import->filas_procesadas);

        /* Los artículos que pasaron por set_precios_finales(): todos los del tenant tocados por la importación. */
        $ids = Article::where('user_id', $this->tenant->id)
                        ->whereHas('price_types')
                        ->orderBy('id')
                        ->pluck('id')
                        ->map(function ($id) { return (int) $id; })
                        ->all();

        $this->assertGreaterThanOrEqual(40, count($ids), 'La importación de 50 filas tenía que dejar al menos 40 artículos con listas.');

        /* Un cambio de precio en todos, que es el caso de una actualización de costos (Servian). */
        Article::whereIn('id', $ids)->update(['cost' => DB::raw('COALESCE(cost, 100) * 1.1')]);

        $marca = $this->marca_de_price_changes();

        $this->calentar_caches_estaticos($ids[0]);

        DB::beginTransaction();
        $por_articulo      = $this->calcular(Article::whereIn('id', $ids)->orderBy('id')->get(), false);
        $foto_por_articulo = $this->foto_de_precios($ids, $marca);
        DB::rollBack();

        DB::beginTransaction();
        $en_lote      = $this->calcular(Article::with(PreciosEnLote::RELACIONES_A_PRECARGAR)->whereIn('id', $ids)->orderBy('id')->get(), true);
        $foto_en_lote = $this->foto_de_precios($ids, $marca);
        DB::rollBack();

        $this->assertEquals($foto_por_articulo, $foto_en_lote, 'Con 50 filas, el modo lote dejó una base distinta.');

        $cantidad = count($ids);

        $antes   = count($por_articulo['loop']) + count($por_articulo['volcar']);
        $despues = count($en_lote['loop']) + count($en_lote['volcar']);

        fwrite(STDERR, sprintf(
            "\n[PreciosEnLote] medición sobre %d artículos con 2 listas y cambio de precio en todos:\n" .
            "  por artículo: %d consultas (%.1f por artículo), %d escrituras sobre precios\n" .
            "  en lote:      %d consultas (%.2f por artículo), %d escrituras sobre precios en volcar() y %d en el loop\n" .
            "  (la relectura con las %d relaciones precargadas son %d consultas por lote, fuera de este conteo)\n",
            $cantidad,
            $antes, $antes / $cantidad, $this->escrituras_sobre_precios($por_articulo['loop']),
            $despues, $despues / $cantidad, $this->escrituras_sobre_precios($en_lote['volcar']), $this->escrituras_sobre_precios($en_lote['loop']),
            count(PreciosEnLote::RELACIONES_A_PRECARGAR), count(PreciosEnLote::RELACIONES_A_PRECARGAR) + 1
        ));

        $this->assertLessThan($cantidad + 5, $despues, 'En lote: menos de una consulta por artículo más las escrituras del volcado.');
        $this->assertGreaterThan(8 * $cantidad, $antes, 'Por artículo: el punto de partida son al menos ocho consultas por artículo; si bajó, actualizar la medición.');
    }

    /* ------------------------------------------------------------------------------------------
     * Escenario
     * ---------------------------------------------------------------------------------------- */

    /**
     * Artículos en los estados que el modo lote tiene que reproducir. Devuelve sus ids.
     *
     * @return array
     */
    protected function armar_escenario()
    {
        /* La importación crea PC-STK-NEW y actualiza A1, A2, A7, A8 y A12: todos quedan con las dos listas. */
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $nuevo = Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', 'PC-STK-NEW')
                        ->first();

        $this->assertNotNull($nuevo, 'La importación tenía que crear PC-STK-NEW.');

        $a1 = $this->recargar('A1');   /* con listas, sin cambio de precio: UPDATE de pivots, sin price_change */
        $a2 = $this->recargar('A2');   /* con listas, cambia el costo: UPDATE de pivots + price_change con listas */
        $a3 = $this->recargar('A3');   /* sin listas, cambia el costo: INSERT de pivots + price_change */
        $a4 = $this->recargar('A4');   /* sin listas, margen propio con price ya en null y costo cambiado: en lote NO se guarda (costo_real lo escribe updateMasivo), por artículo sí */
        $a5 = $this->recargar('A5');   /* precio manual + margen propio: el `price = null; save()` sigue corriendo en lote */
        $a6 = $this->recargar('A6');   /* lista B con precio fijado a mano: margen derivado, sin redondeo */
        $a9 = $this->recargar('A9');   /* sin costo ni precio */

        $this->assertSame(2, $this->pivots_de([$a1->id])['filas']);
        $this->assertSame(0, $this->pivots_de([$a3->id])['filas']);

        $a2->cost = 250;
        $a2->save();

        $a3->cost = 333;
        $a3->save();

        $a4->cost            = 444;
        $a4->percentage_gain = 20;
        $a4->price           = null;
        $a4->save();

        $a5->price           = 999;
        $a5->percentage_gain = 10;
        $a5->save();

        $a6->price_types()->attach($this->lista_b->id, [
            'percentage'          => 40,
            'final_price'         => 1234,
            'setear_precio_final' => 1,
        ]);

        $a9->cost  = null;
        $a9->price = null;
        $a9->save();

        return [(int) $nuevo->id, (int) $a1->id, (int) $a2->id, (int) $a3->id, (int) $a4->id, (int) $a5->id, (int) $a6->id, (int) $a9->id];
    }

    /**
     * Corre setFinalPrice() sobre los artículos como lo hace ActualizarBBDD::set_precios_finales()
     * ($guardar_cambios = false, las listas del tenant), en el modo pedido, contando consultas.
     *
     * @param  \Illuminate\Support\Collection $articulos
     * @param  bool                           $en_lote
     * @return array{resultados:array,loop:array,volcar:array}
     */
    protected function calcular($articulos, $en_lote)
    {
        $price_types = $this->listas_del_tenant();

        $this->tenant->load('extencions');

        $resultados = [];

        $this->consultas = [];
        $this->contando  = true;

        if ($en_lote) {
            PreciosEnLote::activar($this->tenant, $this->tenant->id);
            $this->assertTrue(PreciosEnLote::esta_activo());
        }

        try {

            foreach ($articulos as $article) {

                $res = ArticleHelper::setFinalPrice($article, $this->tenant->id, $this->tenant, $this->tenant->id, false, $price_types);

                $resultados[(int) $article->id] = [
                    'costo_real'          => $this->normalizar($res['costo_real']),
                    'final_price'         => $this->normalizar($res['final_price']),
                    'current_final_price' => $this->normalizar($res['current_final_price']),
                    'base_margen'         => $this->normalizar($res['base_margen']),
                ];
            }

            $loop = $this->consultas;

            $this->consultas = [];

        } finally {

            if ($en_lote) {
                PreciosEnLote::volcar();
            }
        }

        $volcar = $this->consultas;

        $this->contando  = false;
        $this->consultas = [];

        $this->assertFalse(PreciosEnLote::esta_activo());

        return [
            'resultados' => $resultados,
            'loop'       => $loop,
            'volcar'     => $volcar,
        ];
    }

    /**
     * Los caches estáticos por usuario (sale_taxes, recargo por tarjeta) se pagan una sola vez
     * por proceso: se calientan antes de medir para que no le carguen a la primera corrida.
     *
     * @param  int $article_id
     * @return void
     */
    protected function calentar_caches_estaticos($article_id)
    {
        $article = Article::find($article_id);

        ArticlePricesHelper::get_sale_taxes_para_articulo($article, $this->tenant);
        ArticlePricesHelper::calcular_precios_por_metodo_pago_con_tarjeta_incluida(100, $this->tenant->id);
    }

    /* ------------------------------------------------------------------------------------------
     * Fotos de la base
     * ---------------------------------------------------------------------------------------- */

    /**
     * Pivots, cambios de precio y el `price` de los artículos dados. Sin ids de fila ni fechas.
     *
     * @param  array $ids
     * @param  int   $marca  Último id de price_changes antes de la corrida.
     * @return array
     */
    protected function foto_de_precios(array $ids, $marca)
    {
        $pivots = $this->pivots_de($ids);

        $precios = [];

        foreach (Article::whereIn('id', $ids)->orderBy('id')->get() as $article) {
            $precios[(int) $article->id] = $this->normalizar($article->price);
        }

        return [
            'filas_de_pivot' => $pivots['filas'],
            'pivots'         => $pivots['por_articulo'],
            'cambios'        => $this->cambios_de_precio_desde($marca, $ids),
            'price'          => $precios,
        ];
    }

    /**
     * Foto de todo el tenant después de una importación, indexada por identidad del artículo
     * (provider_code + nombre) porque los ids cambian entre corridas.
     *
     * @param  int $marca
     * @return array
     */
    protected function foto_del_tenant($marca)
    {
        $articulos = Article::where('user_id', $this->tenant->id)->orderBy('id')->get();

        $ids       = [];
        $clave_por = [];

        foreach ($articulos as $article) {
            $ids[]                        = (int) $article->id;
            $clave_por[(int) $article->id] = $article->provider_code . '|' . $article->name;
        }

        $this->assertSame(count($ids), count(array_unique($clave_por)), 'Las claves provider_code|name tienen que ser únicas en el escenario.');

        $pivots  = $this->pivots_de($ids);
        $cambios = $this->cambios_de_precio_desde($marca, $ids);

        $foto = [
            'filas_de_pivot' => $pivots['filas'],
            'articulos'      => [],
            'pivots'         => [],
            'cambios'        => [],
        ];

        foreach ($articulos as $article) {

            $clave = $clave_por[(int) $article->id];

            $foto['articulos'][$clave] = [
                'cost'                => $this->normalizar($article->cost),
                'price'               => $this->normalizar($article->price),
                'costo_real'          => $this->normalizar($article->costo_real),
                'final_price'         => $this->normalizar($article->final_price),
                'previus_final_price' => $this->normalizar($article->previus_final_price),
            ];

            $foto['pivots'][$clave]  = isset($pivots['por_articulo'][(int) $article->id]) ? $pivots['por_articulo'][(int) $article->id] : [];
            $foto['cambios'][$clave] = isset($cambios[(int) $article->id]) ? $cambios[(int) $article->id] : [];
        }

        ksort($foto['articulos']);
        ksort($foto['pivots']);
        ksort($foto['cambios']);

        return $foto;
    }

    /**
     * Filas de article_price_type de los artículos dados: [article_id => [price_type_id => columnas]]
     * más el total de filas (para que un duplicado no quede tapado por el índice).
     *
     * @param  array $ids
     * @return array{filas:int,por_articulo:array}
     */
    protected function pivots_de(array $ids)
    {
        $filas = DB::table('article_price_type')
                    ->whereIn('article_id', $ids)
                    ->orderBy('article_id')
                    ->orderBy('price_type_id')
                    ->orderBy('id')
                    ->get();

        $por_articulo = [];

        foreach ($filas as $fila) {
            $por_articulo[(int) $fila->article_id][(int) $fila->price_type_id] = [
                'percentage'                     => $this->normalizar($fila->percentage),
                'price'                          => $this->normalizar($fila->price),
                'final_price'                    => $this->normalizar($fila->final_price),
                'previus_final_price'            => $this->normalizar($fila->previus_final_price),
                'incluir_en_excel_para_clientes' => $this->normalizar($fila->incluir_en_excel_para_clientes),
                'setear_precio_final'            => $this->normalizar($fila->setear_precio_final),
                'precio_luego_de_recargos'       => $this->normalizar($fila->precio_luego_de_recargos),
                'monto_ganancia'                 => $this->normalizar($fila->monto_ganancia),
                'created_at'                     => $fila->created_at,
                'updated_at'                     => $fila->updated_at,
            ];
        }

        return [
            'filas'        => count($filas),
            'por_articulo' => $por_articulo,
        ];
    }

    /**
     * Cambios de precio posteriores a la marca, por artículo, con el precio de cada lista.
     *
     * @param  int   $marca
     * @param  array $ids
     * @return array [article_id => [[cost, price, final_price, employee_id, listas => [price_type_id => final_price]], ...]]
     */
    protected function cambios_de_precio_desde($marca, array $ids)
    {
        $cambios = DB::table('price_changes')
                        ->whereIn('article_id', $ids)
                        ->where('id', '>', $marca)
                        ->orderBy('id')
                        ->get();

        $ids_de_cambios = [];

        foreach ($cambios as $cambio) {
            $ids_de_cambios[] = (int) $cambio->id;
        }

        $listas_por_cambio = [];

        if (count($ids_de_cambios) > 0) {

            $filas = DB::table('price_change_price_type')
                        ->whereIn('price_change_id', $ids_de_cambios)
                        ->orderBy('price_type_id')
                        ->get();

            foreach ($filas as $fila) {
                $listas_por_cambio[(int) $fila->price_change_id][(int) $fila->price_type_id] = $this->normalizar($fila->final_price);
            }
        }

        $por_articulo = [];

        foreach ($cambios as $cambio) {

            $listas = isset($listas_por_cambio[(int) $cambio->id]) ? $listas_por_cambio[(int) $cambio->id] : [];

            ksort($listas);

            $por_articulo[(int) $cambio->article_id][] = [
                'cost'        => $this->normalizar($cambio->cost),
                'price'       => $this->normalizar($cambio->price),
                'final_price' => $this->normalizar($cambio->final_price),
                'employee_id' => is_null($cambio->employee_id) ? null : (int) $cambio->employee_id,
                'listas'      => $listas,
            ];
        }

        return $por_articulo;
    }

    /**
     * @return int  Último id de price_changes (0 si no hay).
     */
    protected function marca_de_price_changes()
    {
        return (int) (DB::table('price_changes')->max('id') ?: 0);
    }

    /* ------------------------------------------------------------------------------------------
     * Utilidades
     * ---------------------------------------------------------------------------------------- */

    /**
     * @param  string $name
     * @param  float  $percentage
     * @param  int    $incluir_en_lista_de_precios_de_excel
     * @param  int    $setear_precio_final
     * @param  int    $position
     * @return \App\Models\PriceType
     */
    protected function crear_price_type($name, $percentage, $incluir_en_lista_de_precios_de_excel, $setear_precio_final, $position)
    {
        $price_type = new PriceType();

        $price_type->name                                 = $name;
        $price_type->percentage                           = $percentage;
        $price_type->incluir_en_lista_de_precios_de_excel = $incluir_en_lista_de_precios_de_excel;
        $price_type->setear_precio_final                  = $setear_precio_final;
        $price_type->position                             = $position;
        $price_type->user_id                              = $this->tenant->id;

        $price_type->save();

        return $price_type;
    }

    /**
     * Las listas como las pasa ActualizarBBDD (set_price_types()).
     *
     * @return \Illuminate\Support\Collection
     */
    protected function listas_del_tenant()
    {
        return PriceType::where('user_id', $this->tenant->id)
                        ->orderBy('position', 'ASC')
                        ->get();
    }

    /**
     * Escrituras (insert/update/delete) sobre las tablas que el modo lote difiere.
     *
     * @param  array $consultas
     * @return int
     */
    protected function escrituras_sobre_precios(array $consultas)
    {
        $cantidad = 0;

        foreach ($consultas as $sql) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $sql) && preg_match(self::TABLAS_DE_PRECIOS, $sql)) {
                $cantidad++;
            }
        }

        return $cantidad;
    }

    /**
     * @param  array  $consultas
     * @param  string $patron
     * @return int
     */
    protected function consultas_que_nombran(array $consultas, $patron)
    {
        $cantidad = 0;

        foreach ($consultas as $sql) {
            if (preg_match($patron, $sql)) {
                $cantidad++;
            }
        }

        return $cantidad;
    }

    /**
     * Decimales a string con 6 decimales (MySQL devuelve "100.00", PHP tiene 100.0): mismo criterio
     * que ArticleSnapshot. null queda null; lo no numérico, tal cual.
     *
     * @param  mixed $valor
     * @return string|null
     */
    protected function normalizar($valor)
    {
        if (is_null($valor)) {
            return null;
        }

        if (is_numeric($valor)) {
            return number_format((float) $valor, 6, '.', '');
        }

        return (string) $valor;
    }
}
