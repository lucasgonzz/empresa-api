<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use App\Models\ArticleDiscount;
use App\Models\ArticleSurchage;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\PriceTypeSurchage;
use App\Models\Provider;
use App\Models\ProviderPriceList;
use App\Models\SaleTax;
use App\Models\SubCategory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Base de los tests del motor del recálculo de precios (misión recalculo-precios-motor-rapido,
 * 28/9/2026).
 *
 * Lo central que da: `recalcular_como_hoy()`, que es el handle() de ProcessChunkSetFinalPrices
 * TAL COMO ESTABA en develop antes de esta misión (setFinalPrice por artículo con
 * $guardar_cambios = true), y `foto()`, que junta todo lo que el recálculo escribe para comparar
 * los dos caminos campo por campo. El job ya no tiene ese código: vive acá como la referencia
 * contra la que se mide el motor, y no se "moderniza" nunca, porque dejaría de ser la referencia.
 *
 * Cada test arma SU PROPIO comercio (User::create) con la configuración exacta que quiere probar:
 * el usuario 500 de la semilla trae un impuesto sobre ventas, listas y extensiones que ensuciarían
 * cualquier configuración que no sea la suya.
 *
 * Los dos caminos se comparan SOBRE LA MISMA BASE: cada corrida va adentro de un savepoint
 * (DB::beginTransaction() anidado en la transacción de DatabaseTransactions) que se revierte al
 * terminar, así la segunda arranca del mismo estado que la primera. Los ids autoincrementales no
 * se reusan después de un rollback, así que las fotos nunca se indexan por id de fila generada.
 *
 * El reloj se congela durante las dos corridas: así updated_at, final_price_updated_at y los
 * created_at de price_changes se comparan por valor, no se excluyen.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class RecalculoEnLoteTestCase extends TestCase
{
    use DatabaseTransactions;

    /** Instante congelado de las corridas: posterior a cualquier fila creada por el test. */
    const AHORA = '2030-01-15 10:00:00';

    /** Moneda pesos y dólares, tal como las usa ArticlePriceTypeMonedaHelper. */
    const ARS = 1;
    const USD = 2;

    /** Ids de la tabla ivas sembrada (21% y 10,5%). */
    const IVA_21 = 2;
    const IVA_10_5 = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->limpiar_estado_del_proceso();
    }

    protected function tearDown(): void
    {
        $this->limpiar_estado_del_proceso();

        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Lo que vive en estáticas del proceso y no en la base: la transacción del test no lo revierte
     * y se lo llevaría puesto el test siguiente.
     *
     * @return void
     */
    protected function limpiar_estado_del_proceso()
    {
        PreciosEnLote::descartar();
        PreciosEnLote::deshabilitar(false);

        ArticlePricesHelper::$sale_taxes_cache = [];
        ArticlePricesHelper::$payment_method_layer3_cache = [];
    }

    /* ------------------------------------------------------------------------------------------
     * Armado de datos
     * ---------------------------------------------------------------------------------------- */

    /**
     * Un comercio nuevo (dueño), con sus flags de precios y sus extensiones. Se devuelve RELEÍDO de
     * la base: Eloquent no hidrata los defaults de MySQL y el cálculo lee muchos flags.
     *
     * @param  array $flags        Columnas de users (listas_de_precio, aplicar_iva_al_costo, ...).
     * @param  array $extensiones  Slugs de extencion_empresas.
     * @return \App\Models\User
     */
    protected function crear_dueno(array $flags = [], array $extensiones = [])
    {
        $dueno = User::create([
            'name'         => 'zz Comercio recalculo en lote',
            'company_name' => 'zz Comercio recalculo en lote',
            'email'        => 'recalculo-lote-' . uniqid('', true) . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* Un comercio "neutro": sin margen general, sin redondeos, IVA al precio de venta. */
        $base = [
            'listas_de_precio'                                            => 0,
            'aplicar_iva_al_costo'                                        => 0,
            'usar_condicion_fiscal_en_costeo'                             => 0,
            'condicion_iva_precios'                                       => User::CONDICION_RRII,
            'percentage_gain'                                             => null,
            'dollar'                                                      => 1000,
            'cotizar_precios_en_dolares'                                  => 1,
            'redondear_precios_en_centavos'                               => 0,
            'redondear_miles_en_vender'                                   => 0,
            'redondear_centenas_en_vender'                                => 0,
            'redondear_precios_en_decenas'                                => 0,
            'redondear_de_a_50'                                           => 0,
            'aplicar_descuentos_en_articulos_antes_del_margen_de_ganancia' => 1,
        ];

        DB::table('users')->where('id', $dueno->id)->update(array_merge($base, $flags));

        foreach ($extensiones as $slug) {

            $extension = ExtencionEmpresa::where('slug', $slug)->first();

            $this->assertNotNull($extension, 'La base de testing no tiene la extensión "' . $slug . '".');

            $dueno->extencions()->attach($extension->id);
        }

        $this->actingAs(User::find($dueno->id), 'web');

        return User::find($dueno->id);
    }

    /**
     * @param  \App\Models\User $dueno
     * @param  array            $atributos
     * @return \App\Models\Provider
     */
    protected function crear_proveedor($dueno, array $atributos = [])
    {
        return Provider::create(array_merge([
            'name'    => 'zz Proveedor ' . uniqid(),
            'user_id' => $dueno->id,
        ], $atributos));
    }

    /**
     * @param  \App\Models\User $dueno
     * @param  array            $atributos
     * @return \App\Models\Category
     */
    protected function crear_categoria($dueno, array $atributos = [])
    {
        return Category::create(array_merge([
            'name'    => 'zz Categoria ' . uniqid(),
            'user_id' => $dueno->id,
        ], $atributos));
    }

    /**
     * @param  \App\Models\User     $dueno
     * @param  \App\Models\Category $categoria
     * @return \App\Models\SubCategory
     */
    protected function crear_subcategoria($dueno, $categoria)
    {
        return SubCategory::create([
            'name'        => 'zz Subcategoria ' . uniqid(),
            'user_id'     => $dueno->id,
            'category_id' => $categoria->id,
        ]);
    }

    /**
     * Una lista de precios del dueño, con sus recargos de lista ([['percentage' => x] o
     * ['amount' => y]], en orden de position).
     *
     * @param  \App\Models\User $dueno
     * @param  string           $nombre
     * @param  float|null       $porcentaje
     * @param  int              $posicion
     * @param  array            $recargos
     * @return \App\Models\PriceType
     */
    protected function crear_lista($dueno, $nombre, $porcentaje, $posicion, array $recargos = [])
    {
        $lista = PriceType::create([
            'name'       => 'zz ' . $nombre,
            'user_id'    => $dueno->id,
            'percentage' => $porcentaje,
            'position'   => $posicion,
        ]);

        foreach ($recargos as $i => $recargo) {
            PriceTypeSurchage::create(array_merge([
                'name'          => 'zz Recargo ' . ($i + 1),
                'price_type_id' => $lista->id,
                'position'      => $i + 1,
            ], $recargo));
        }

        return $lista;
    }

    /**
     * Un artículo del dueño, RELEÍDO de la base (mismo motivo que crear_dueno()).
     *
     * @param  \App\Models\User $dueno
     * @param  array            $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($dueno, array $atributos = [])
    {
        $article = Article::create(array_merge([
            'name'    => 'zz Articulo recalculo en lote ' . uniqid(),
            'user_id' => $dueno->id,
            'status'  => 'active',
            'iva_id'  => self::IVA_21,
        ], $atributos));

        return Article::find($article->id);
    }

    /**
     * @param  \App\Models\Article $article
     * @param  array               $atributos ['percentage' => x] o ['amount' => y]
     * @return void
     */
    protected function descuento($article, array $atributos)
    {
        ArticleDiscount::create(array_merge(['article_id' => $article->id], $atributos));
    }

    /**
     * @param  \App\Models\Article $article
     * @param  array               $atributos ['percentage' => x] o ['amount' => y], y
     *                                        opcionalmente 'luego_del_precio_final' => 1.
     * @return void
     */
    protected function recargo($article, array $atributos)
    {
        ArticleSurchage::create(array_merge(['article_id' => $article->id], $atributos));
    }

    /**
     * @param  \App\Models\User $dueno
     * @param  float            $porcentaje
     * @param  bool             $para_todos
     * @return \App\Models\SaleTax
     */
    protected function impuesto_sobre_ventas($dueno, $porcentaje, $para_todos = true)
    {
        return SaleTax::create([
            'user_id'      => $dueno->id,
            'name'         => 'zz IIBB ' . $porcentaje,
            'percentage'   => $porcentaje,
            'apply_to_all' => $para_todos ? 1 : 0,
            'activo'       => 1,
        ]);
    }

    /**
     * Pone un precio de venta viejo a mano en la base, sin pasar por el cálculo: es lo que hace
     * que el recálculo tenga algo que cambiar.
     *
     * @param  array      $ids
     * @param  float|null $precio
     * @return void
     */
    protected function pisar_precio_final(array $ids, $precio = 1)
    {
        DB::table('articles')->whereIn('id', $ids)->update(['final_price' => $precio]);
    }

    /* ------------------------------------------------------------------------------------------
     * Los dos caminos
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 LA REFERENCIA: el handle() de ProcessChunkSetFinalPrices como estaba en develop antes de
     * esta misión (commit base de la rama), sin una línea de más. User::find del dueño, los
     * artículos leídos sin relaciones, y setFinalPrice($article, $user->id, $user) por artículo
     * con $guardar_cambios = true (el default). Único agregado: orderBy('id'), para que el orden
     * sea determinístico (MySQL ya los devolvía por PK).
     *
     * @param  array $ids
     * @param  int   $owner_id
     * @return int[] Ids cuyo final_price cambió (la comparación suelta de hoy).
     */
    protected function recalcular_como_hoy(array $ids, $owner_id)
    {
        $user = User::find($owner_id);

        $articles = Article::whereIn('id', $ids)->orderBy('id')->get();

        $ids_que_cambiaron = [];

        foreach ($articles as $article) {

            $precio_anterior = $article->final_price;

            ArticleHelper::setFinalPrice($article, $user->id, $user);

            if ($precio_anterior != $article->final_price) {
                $ids_que_cambiaron[] = (int) $article->id;
            }
        }

        return $ids_que_cambiaron;
    }

    /**
     * El motor, como lo llama ProcessChunkSetFinalPrices ahora: dueño releído, auth_user_id null.
     *
     * @param  array    $ids
     * @param  int      $owner_id
     * @param  int|null $price_update_run_id
     * @return array
     */
    protected function recalcular_con_el_motor(array $ids, $owner_id, $price_update_run_id = null)
    {
        return RecalculoDePreciosEnLote::recalcular($ids, User::find($owner_id), null, [
            'price_update_run_id' => $price_update_run_id,
        ]);
    }

    /**
     * Corre los dos caminos sobre la misma base y afirma que dejan exactamente lo mismo.
     *
     * @param  array $ids
     * @param  int   $owner_id
     * @param  int   $pasadas  Con 2, cada camino recalcula dos veces seguidas (la segunda no
     *                         tiene que cambiar nada, en ninguno de los dos).
     * @return array ['foto_hoy', 'foto_motor', 'cambiaron_hoy', 'resultado_motor']
     */
    protected function comparar_caminos(array $ids, $owner_id, $pasadas = 1)
    {
        $marca = (int) DB::table('price_changes')->max('id');

        /* Camino de hoy. */
        DB::beginTransaction();

        Carbon::setTestNow(self::AHORA);

        $cambiaron_hoy = [];
        $cambiaron_hoy_por_pasada = [];

        for ($pasada = 1; $pasada <= $pasadas; $pasada++) {
            $cambiaron = $this->recalcular_como_hoy($ids, $owner_id);
            $cambiaron_hoy_por_pasada[] = $cambiaron;
            if ($pasada === 1) {
                $cambiaron_hoy = $cambiaron;
            }
        }

        $foto_hoy = $this->foto($ids, $marca);

        Carbon::setTestNow();
        DB::rollBack();

        $this->limpiar_estado_del_proceso();

        /* Motor. */
        DB::beginTransaction();

        Carbon::setTestNow(self::AHORA);

        $resultado_motor = null;
        $resultados_por_pasada = [];

        for ($pasada = 1; $pasada <= $pasadas; $pasada++) {
            $resultado = $this->recalcular_con_el_motor($ids, $owner_id);
            $resultados_por_pasada[] = $resultado;
            if ($pasada === 1) {
                $resultado_motor = $resultado;
            }
        }

        $foto_motor = $this->foto($ids, $marca);

        Carbon::setTestNow();
        DB::rollBack();

        $this->assertFalse(PreciosEnLote::esta_activo(), 'El motor dejó el modo lote encendido.');

        $this->assertEquals(
            $foto_hoy,
            $foto_motor,
            'El motor dejó en la base algo distinto del camino por artículo de hoy.'
        );

        sort($cambiaron_hoy);
        $cambiaron_motor = $resultado_motor['cambiaron'];
        sort($cambiaron_motor);

        $this->assertSame($cambiaron_hoy, $cambiaron_motor, 'Los artículos "que cambiaron de precio" no son los mismos en los dos caminos.');

        for ($pasada = 1; $pasada < $pasadas; $pasada++) {
            $this->assertSame([], $cambiaron_hoy_por_pasada[$pasada], 'Referencia: la pasada ' . ($pasada + 1) . ' de hoy cambió precios.');
            $this->assertSame([], $resultados_por_pasada[$pasada]['cambiaron'], 'La pasada ' . ($pasada + 1) . ' del motor cambió precios que ya estaban recalculados.');
        }

        return [
            'foto_hoy'        => $foto_hoy,
            'foto_motor'      => $foto_motor,
            'cambiaron_hoy'   => $cambiaron_hoy,
            'resultado_motor' => $resultado_motor,
        ];
    }

    /* ------------------------------------------------------------------------------------------
     * La foto
     * ---------------------------------------------------------------------------------------- */

    /**
     * Todo lo que el recálculo puede escribir, para los artículos dados: las filas de `articles`
     * (todas las columnas menos el vector de embeddings), las de article_price_type y
     * article_price_type_monedas (todas las columnas menos los ids de fila), y los price_changes
     * creados después de $marca con sus filas de price_change_price_type (todas las columnas menos
     * los ids generados). Los decimales se comparan como los devuelve la base.
     *
     * @param  array $ids
     * @param  int   $marca  Id máximo de price_changes antes de las corridas.
     * @return array
     */
    protected function foto(array $ids, $marca)
    {
        $foto = [
            'articles'   => [],
            'pivots'     => [],
            'monedas'    => [],
            'cambios'    => [],
        ];

        $columnas = [];

        foreach (DB::select('SHOW COLUMNS FROM `articles`') as $columna) {
            if ($columna->Field !== 'embedding') {
                $columnas[] = $columna->Field;
            }
        }

        foreach (DB::table('articles')->whereIn('id', $ids)->orderBy('id')->get($columnas) as $fila) {
            $foto['articles'][(int) $fila->id] = (array) $fila;
        }

        /* Pivots como lista (sin id de fila), ordenada: así un duplicado se ve. */
        $pivots = [];

        foreach (DB::table('article_price_type')->whereIn('article_id', $ids)->get() as $fila) {
            $fila = (array) $fila;
            unset($fila['id']);
            $pivots[] = $fila;
        }

        usort($pivots, function ($a, $b) {
            return strcmp(json_encode($a), json_encode($b));
        });

        $foto['pivots'] = $pivots;

        /* Las entradas por moneda ya existen antes de las corridas: su id es estable. */
        foreach (DB::table('article_price_type_monedas')->whereIn('article_id', $ids)->orderBy('id')->get() as $fila) {
            $foto['monedas'][(int) $fila->id] = (array) $fila;
        }

        /* price_changes de las corridas, por artículo y en orden, con sus listas. */
        $cambios = DB::table('price_changes')
                        ->where('id', '>', $marca)
                        ->whereIn('article_id', $ids)
                        ->orderBy('id')
                        ->get();

        $listas_por_cambio = [];

        if (count($cambios) > 0) {

            $filas_de_listas = DB::table('price_change_price_type')
                                    ->whereIn('price_change_id', $cambios->pluck('id')->all())
                                    ->get();

            foreach ($filas_de_listas as $fila) {
                $fila = (array) $fila;
                $price_change_id = (int) $fila['price_change_id'];
                unset($fila['id'], $fila['price_change_id']);
                $listas_por_cambio[$price_change_id][] = $fila;
            }
        }

        foreach ($cambios as $cambio) {

            $cambio = (array) $cambio;
            $price_change_id = (int) $cambio['id'];
            unset($cambio['id']);

            $listas = isset($listas_por_cambio[$price_change_id]) ? $listas_por_cambio[$price_change_id] : [];

            usort($listas, function ($a, $b) {
                return strcmp(json_encode($a), json_encode($b));
            });

            $cambio['listas'] = $listas;

            $foto['cambios'][(int) $cambio['article_id']][] = $cambio;
        }

        ksort($foto['cambios']);

        return $foto;
    }
}
