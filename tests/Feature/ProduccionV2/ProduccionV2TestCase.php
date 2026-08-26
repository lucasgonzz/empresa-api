<?php

namespace Tests\Feature\ProduccionV2;

use App\Models\Article;
use App\Models\OrderProductionStatus;
use App\Models\OrderProductionStatusGroup;
use App\Models\ProductionBatch;
use App\Models\ProductionBatchMovementType;
use App\Models\ProductionBatchStatus;
use App\Models\Recipe;
use App\Models\RecipeRoute;
use App\Models\RecipeRouteType;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Base comun de la suite de ProduccionV2 (mision produccion-v2-multinivel).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  Por que esta clase existe, y que NO hay que volver a descubrir cada vez
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. `DatabaseTransactions`, NUNCA `RefreshDatabase` — lo hereda de EmpresaTestCase. La base
 *     del slot esta sembrada de antes con TestingFerreteriaSeeder y un refresh la vaciaria,
 *     rompiendo todas las demas suites.
 *
 *  2. 🔴 La base del slot tiene CERO filas en todas las tablas de produccion
 *     (order_production_statuses, recipes, recipe_routes, article_recipe_route,
 *     production_batches, production_batch_movements, production_batch_statuses,
 *     production_batch_movement_types). `TestingFerreteriaSeeder` no siembra NADA de
 *     produccion: los seeders del modulo solo corren desde `DatabaseSeeder` en ramas gateadas
 *     por FOR_USER / APP_ENV=local, y la base de testing se siembra con el seeder de ferreteria
 *     a secas. Por eso cada test arma su propio fixture adentro de la transaccion, con estos
 *     builders.
 *
 *  3. 🔴 LOS ARTICULOS DEL FIXTURE SE CREAN SIN DEPOSITOS Y CON `stock` EXPLICITO, y no es un
 *     detalle de comodidad. `SetArticleStock` tiene tres caminos:
 *       - articulo SIN filas en `address_article` y con `stock` no-null -> `articles.stock += amount`;
 *       - articulo CON filas en `address_article` -> el stock global lo PISA
 *         `ArticleHelper::setArticleStockFromAddresses()` con la suma del pivot;
 *       - articulo CON depositos y un movimiento SIN `from_address_id` ni `to_address_id` ->
 *         se crea la fila en `stock_movements` y NO CAMBIA NADA, ni el global ni el pivot,
 *         sin error y sin log.
 *     Ese tercer camino es el que se come un test en silencio: el endpoint responde 201, el
 *     movimiento queda registrado, y la asercion sobre el stock falla por un motivo que no
 *     tiene nada que ver con lo que el test quiere probar. Los 10 articulos que SI trae el
 *     fixture de ferreteria (`Martillo acero`, `Pinza`, ...) tienen fila en `address_article`
 *     contra el deposito `Principal`, asi que NO sirven como insumos de un test de consumo
 *     global. De ahi que `crear_articulo()` cree los suyos.
 *
 *  4. Los modelos de produccion declaran todos `$guarded = []`, asi que `create()` asigna todo.
 *     Si alguno dejara de declararlo, el reemplazo es `forceCreate()` (lo que hace
 *     `PuntosTestCase::dar_extencion()` por el mismo motivo).
 *
 * Todo lo que estos tests verifican se lee de la BASE despues de pegarle al endpoint real:
 * nunca el valor de retorno de un helper.
 */
abstract class ProduccionV2TestCase extends EmpresaTestCase
{
    /**
     * El User del comercio de prueba, el mismo que autentica `EmpresaTestCase::setUp()`.
     *
     * @return \App\Models\User
     */
    protected function comercio()
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el comercio de prueba sembrado.');
        }

        return $user;
    }

    /**
     * Un grupo de estados de produccion.
     *
     * @param  string  $nombre
     * @param  int     $position
     * @return \App\Models\OrderProductionStatusGroup
     */
    protected function crear_grupo($nombre, $position = 1)
    {
        return OrderProductionStatusGroup::create([
            'name'      => $nombre,
            'position'  => $position,
            'user_id'   => $this->comercio()->id,
        ]);
    }

    /**
     * Un estado de produccion de la cuenta.
     *
     * @param  string    $nombre
     * @param  int       $position
     * @param  int|null  $group_id  Grupo al que pertenece el estado (null = sin grupo).
     * @return \App\Models\OrderProductionStatus
     */
    protected function crear_estado($nombre, $position, $group_id = null)
    {
        $data = [
            'name'      => $nombre,
            'position'  => $position,
            'user_id'   => $this->comercio()->id,
        ];

        // La columna del grupo se manda SOLO si el test pide un grupo. No es cosmetica: este
        // TestCase se escribio antes de la migracion que agrega la columna (UT-0 corre antes que
        // UT-1, a proposito, para fijar el comportamiento actual), y un `create()` con una clave
        // que todavia no es columna revienta en SQL. Los tests que no usan grupos no la nombran.
        if (!is_null($group_id)) {
            $data['order_production_status_group_id'] = $group_id;
        }

        return OrderProductionStatus::create($data);
    }

    /**
     * Un articulo del fixture: SIN depositos y con `stock` explicito (ver punto 3 del encabezado).
     *
     * @param  string  $nombre
     * @param  float   $stock
     * @param  float   $cost
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $stock, $cost = 100)
    {
        return Article::create([
            'name'      => $nombre,
            'stock'     => $stock,
            'cost'      => $cost,
            'status'    => 'active',
            'user_id'   => $this->comercio()->id,
        ]);
    }

    /**
     * @param  string  $nombre
     * @return \App\Models\RecipeRouteType
     */
    protected function crear_tipo_de_ruta($nombre)
    {
        return RecipeRouteType::create([
            'name'      => $nombre,
            'user_id'   => $this->comercio()->id,
        ]);
    }

    /**
     * @param  \App\Models\Article  $article  El producto que la receta fabrica.
     * @return \App\Models\Recipe
     */
    protected function crear_receta($article)
    {
        return Recipe::create([
            'name'          => $article->name,
            'article_id'    => $article->id,
            'user_id'       => $this->comercio()->id,
        ]);
    }

    /**
     * Una ruta de receta con sus insumos.
     *
     * ⚠️ Los insumos se cargan con `attach()` renglon por renglon y NO con `sync()`: el mismo
     * articulo puede ser insumo de la misma ruta mas de una vez, en estados distintos, y un
     * `sync()` colapsaria esos renglones en uno solo.
     *
     * @param  \App\Models\Recipe  $recipe
     * @param  array               $insumos   Cada uno: ['article' => Article, 'amount' => float,
     *                                        'order_production_status_id' => int|null,
     *                                        'address_id' => int|null]
     * @param  array               $opciones  Admite from_address_id, to_address_id,
     *                                        end_order_production_status_id,
     *                                        order_production_status_group_id, is_default,
     *                                        recipe_route_type_id.
     * @return \App\Models\RecipeRoute
     */
    protected function crear_ruta($recipe, $insumos, $opciones = [])
    {
        $data = [
            'recipe_id'                 => $recipe->id,
            'recipe_route_type_id'      => isset($opciones['recipe_route_type_id']) ? $opciones['recipe_route_type_id'] : null,
            // `recipe_routes.is_default` es NOT NULL en la base, aunque ningun flujo de la API la
            // setee (RecipeRouteController@store no la nombra y cae al default de la columna).
            'is_default'                => isset($opciones['is_default']) ? $opciones['is_default'] : 0,
            'from_address_id'           => isset($opciones['from_address_id']) ? $opciones['from_address_id'] : null,
            'to_address_id'             => isset($opciones['to_address_id']) ? $opciones['to_address_id'] : null,
        ];

        // Mismo motivo que en crear_estado(): las dos columnas nuevas de la ruta se nombran solo
        // si el test las pide, para que esta clase base funcione tambien antes de la migracion.
        $columnas_nuevas = ['end_order_production_status_id', 'order_production_status_group_id'];

        foreach ($columnas_nuevas as $columna) {
            if (isset($opciones[$columna])) {
                $data[$columna] = $opciones[$columna];
            }
        }

        $route = RecipeRoute::create($data);

        foreach ($insumos as $insumo) {
            $route->articles()->attach($insumo['article']->id, [
                'amount'                        => $insumo['amount'],
                'notes'                         => isset($insumo['notes']) ? $insumo['notes'] : null,
                'order_production_status_id'    => isset($insumo['order_production_status_id']) ? $insumo['order_production_status_id'] : null,
                'address_id'                    => isset($insumo['address_id']) ? $insumo['address_id'] : null,
            ]);
        }

        return $route->fresh();
    }

    /**
     * @param  string  $nombre
     * @param  string  $slug
     * @return \App\Models\ProductionBatchStatus
     */
    protected function crear_estado_de_lote($nombre, $slug)
    {
        return ProductionBatchStatus::create([
            'name'      => $nombre,
            'slug'      => $slug,
            'user_id'   => $this->comercio()->id,
        ]);
    }

    /**
     * @param  string  $nombre
     * @param  string  $slug
     * @return \App\Models\ProductionBatchMovementType
     */
    protected function crear_tipo_de_movimiento($nombre, $slug)
    {
        return ProductionBatchMovementType::create([
            'name'      => $nombre,
            'slug'      => $slug,
            'user_id'   => $this->comercio()->id,
        ]);
    }

    /**
     * @param  \App\Models\Article      $article  El producto que el lote fabrica.
     * @param  \App\Models\Recipe       $recipe
     * @param  \App\Models\RecipeRoute  $route
     * @param  float                    $planned
     * @return \App\Models\ProductionBatch
     */
    protected function crear_lote($article, $recipe, $route, $planned)
    {
        $status = $this->crear_estado_de_lote('En proceso', 'in_progress');

        return ProductionBatch::create([
            'article_id'                    => $article->id,
            'recipe_id'                     => $recipe->id,
            'recipe_route_id'               => $route->id,
            'production_batch_status_id'    => $status->id,
            'planned_amount'                => $planned,
            'user_id'                       => $this->comercio()->id,
        ]);
    }

    /**
     * El stock global vigente de un articulo, releido de la base.
     *
     * @param  \App\Models\Article  $article
     * @return float
     */
    protected function stock_de($article)
    {
        return (float) $article->fresh()->stock;
    }
}
