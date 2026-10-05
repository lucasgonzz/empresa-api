<?php

namespace Tests\Feature\CategoryProposals;

use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\SubCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Las ayudas de los tests de elegir, revisar y volver atrás (misión categorizacion-tres-modelos,
 * 5/10/2026): categorías REALES del comercio, extensiones, los pedidos HTTP de cada acción y un par de
 * lecturas para comparar contra la base.
 *
 * Es un trait propio y no parte de CategoryProposalsTestCase porque esa base la edita solo el
 * orquestador: dos constructores en el mismo worktree no pueden pisarse una clase compartida.
 *
 * IMPORTANTE (PHP 7.4): nada de `?->`, `match`, `str_contains` ni argumentos nombrados.
 */
trait AyudasDeLaEleccion
{
    /**
     * Correlativo de `num` para las categorías que se siembran a mano (la columna admite NULL, pero un
     * `num` real se parece más a lo que hay en producción).
     *
     * @var int
     */
    protected $num_sembrado = 0;

    // ---------------------------------------------------------------------------------------------
    // Datos del comercio
    // ---------------------------------------------------------------------------------------------

    /**
     * Una categoría REAL (de `categories`) del comercio dado (por defecto el del test).
     *
     * @param  string $nombre
     * @param  \App\Models\User|null $dueno
     * @param  array  $extra  Columnas extra (`percentage_gain`, `deleted_at`...).
     * @return \App\Models\Category
     */
    protected function categoria_real($nombre, $dueno = null, array $extra = [])
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $this->num_sembrado++;

        return Category::create(array_merge([
            'name'    => $nombre,
            'user_id' => $dueno->id,
            'num'     => $this->num_sembrado,
        ], $extra));
    }

    /**
     * Una subcategoría REAL colgada de una categoría real.
     *
     * @param  string $nombre
     * @param  \App\Models\Category $categoria
     * @param  \App\Models\User|null $dueno
     * @return \App\Models\SubCategory
     */
    protected function subcategoria_real($nombre, Category $categoria, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $this->num_sembrado++;

        return SubCategory::create([
            'name'        => $nombre,
            'category_id' => $categoria->id,
            'user_id'     => $dueno->id,
            'num'         => $this->num_sembrado,
        ]);
    }

    /**
     * Le da una extensión al comercio (la crea si el slot no la tiene sembrada: un slot arranca con
     * `extencion_empresas` vacía).
     *
     * @param  string $slug
     * @param  \App\Models\User|null $dueno
     * @return void
     */
    protected function dar_extension($slug, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug]);
        }

        $dueno->extencions()->syncWithoutDetaching([$extencion->id]);
    }

    /**
     * Lo que tiene hoy un artículo (fila fresca, aunque esté borrado): categoría y subcategoría como
     * enteros o NULL.
     *
     * @param  \App\Models\Article $articulo
     * @return array  ['category_id' => int|null, 'sub_category_id' => int|null]
     */
    protected function categorias_de($articulo)
    {
        $fila = DB::table('articles')->where('id', $articulo->id)->first(['category_id', 'sub_category_id']);

        return [
            'category_id'     => is_null($fila->category_id) ? null : (int) $fila->category_id,
            'sub_category_id' => is_null($fila->sub_category_id) ? null : (int) $fila->sub_category_id,
        ];
    }

    /**
     * Le pone una fecha vieja a `updated_at` de los artículos, para comprobar después si algo los tocó.
     *
     * @param  array $articulos
     * @return void
     */
    protected function envejecer($articulos)
    {
        $ids = [];

        foreach ($articulos as $articulo) {
            $ids[] = $articulo->id;
        }

        DB::table('articles')->whereIn('id', $ids)->update(['updated_at' => '2020-01-01 00:00:00']);
    }

    /**
     * El `updated_at` de un artículo como texto (leído de la base).
     *
     * @param  \App\Models\Article $articulo
     * @return string|null
     */
    protected function actualizado_el($articulo)
    {
        return DB::table('articles')->where('id', $articulo->id)->value('updated_at');
    }

    /**
     * Cuántas consultas SQL hace una acción.
     *
     * @param  callable $accion
     * @return int
     */
    protected function cantidad_de_consultas(callable $accion)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $accion();

        $cantidad = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $cantidad;
    }

    // ---------------------------------------------------------------------------------------------
    // Los pedidos HTTP de cada acción (contrato B del plan, §6)
    // ---------------------------------------------------------------------------------------------

    /**
     * POST category-proposal-runs/{id}/elegir.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @param  \App\Models\CategoryProposal    $propuesta
     * @param  bool|null $eliminar  `eliminar_categorias_vacias` (null = no se manda).
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_elegir($run, $propuesta, $eliminar = null)
    {
        $cuerpo = ['propuesta_id' => $propuesta->id];

        if (!is_null($eliminar)) {
            $cuerpo['eliminar_categorias_vacias'] = $eliminar;
        }

        return $this->postJson('api/category-proposal-runs/'.$run->id.'/elegir', $cuerpo);
    }

    /**
     * POST category-proposal-runs/{id}/volver-atras.
     *
     * @param  \App\Models\CategoryProposalRun $run
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_volver_atras($run)
    {
        return $this->postJson('api/category-proposal-runs/'.$run->id.'/volver-atras');
    }

    /**
     * POST category-proposal-items/{id}/aprobar.
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_aprobar($item)
    {
        return $this->postJson('api/category-proposal-items/'.$item->id.'/aprobar');
    }

    /**
     * POST category-proposal-items/{id}/rechazar.
     *
     * @param  \App\Models\CategoryProposalItem $item
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_rechazar($item)
    {
        return $this->postJson('api/category-proposal-items/'.$item->id.'/rechazar');
    }

    /**
     * POST category-proposal-items/aprobar-varios o rechazar-varios.
     *
     * @param  string $accion  'aprobar' | 'rechazar'
     * @param  array  $ids
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_en_lote($accion, array $ids)
    {
        return $this->postJson('api/category-proposal-items/'.$accion.'-varios', ['ids' => $ids]);
    }

    /**
     * Los ids de artículos que quedaron encolados para recalcular precios (con `Queue::fake()`
     * prendida), ordenados. Los lotes guardan sus ids en una propiedad protegida: se leen por reflexión,
     * igual que en los tests del recálculo en lote.
     *
     * @return array  [int, ...]
     */
    protected function ids_encolados_para_recalcular()
    {
        $ids = [];

        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class) as $lote) {
            $propiedad = new \ReflectionProperty($lote, 'article_ids');
            $propiedad->setAccessible(true);

            foreach ($propiedad->getValue($lote) as $id) {
                $ids[] = (int) $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Pone `USA_TIENDA_NUBE` en el entorno del proceso (los observers y `add_article_to_sync` leen
     * `env()` directo). Hay que dejarla como estaba con `restaurar_tienda_nube()` en un `finally`: el
     * entorno es del proceso, no del test.
     *
     * @param  string $valor  Lo que se escribe en la variable; por defecto 'true' (prendida). Se puede
     *                        pasar 'false', '0' o '' para probar cómo se leen los apagados.
     * @return array  Lo que había antes, para `restaurar_tienda_nube()`.
     */
    protected function prender_tienda_nube($valor = 'true')
    {
        $anterior = [
            'server' => array_key_exists('USA_TIENDA_NUBE', $_SERVER) ? $_SERVER['USA_TIENDA_NUBE'] : null,
            'env'    => array_key_exists('USA_TIENDA_NUBE', $_ENV) ? $_ENV['USA_TIENDA_NUBE'] : null,
            'putenv' => getenv('USA_TIENDA_NUBE'),
        ];

        $_SERVER['USA_TIENDA_NUBE'] = $valor;
        $_ENV['USA_TIENDA_NUBE']    = $valor;
        putenv('USA_TIENDA_NUBE='.$valor);

        return $anterior;
    }

    /**
     * Deja `USA_TIENDA_NUBE` como estaba antes de `prender_tienda_nube()`.
     *
     * @param  array $anterior
     * @return void
     */
    protected function restaurar_tienda_nube(array $anterior)
    {
        if (is_null($anterior['server'])) {
            unset($_SERVER['USA_TIENDA_NUBE']);
        } else {
            $_SERVER['USA_TIENDA_NUBE'] = $anterior['server'];
        }

        if (is_null($anterior['env'])) {
            unset($_ENV['USA_TIENDA_NUBE']);
        } else {
            $_ENV['USA_TIENDA_NUBE'] = $anterior['env'];
        }

        if ($anterior['putenv'] === false) {
            putenv('USA_TIENDA_NUBE');
        } else {
            putenv('USA_TIENDA_NUBE='.$anterior['putenv']);
        }
    }

    /**
     * Veinticinco artículos y un sistema A, para probar que elegir y volver atrás recorren VARIOS lotes
     * (con `catalogo_ia.articulos_por_lote_de_escritura` chico) sin saltearse ni repetir a nadie.
     *
     * El artículo `i` (de 0 a 24):
     *  - tiene la categoría `Vieja` si `i % 3 == 0` (el resto no tiene ninguna);
     *  - según `i % 5`: 0 = sin ubicar, 1 = dudoso (Bisagras), 2, 3 y 4 = seguro (los pares en
     *    Bisagras / Comunes y los impares en Correderas).
     *
     * @return array  ['vieja', 'articulos', 'run', 'proposal', 'items', 'nodos']
     */
    protected function sembrar_veinticinco()
    {
        // La categoría que tienen de antes los artículos múltiplos de tres.
        $vieja = $this->categoria_real('Vieja');

        $articulos = [];

        for ($i = 0; $i < 25; $i++) {
            $articulos[] = $this->crear_articulo('Articulo '.$i, ($i % 3 === 0) ? ['category_id' => $vieja->id] : []);
        }

        // Los ítems de la propuesta A, uno por artículo.
        $items = [];

        foreach ($articulos as $i => $articulo) {
            switch ($i % 5) {
                case 0:
                    $items[] = [$articulo, null, null, 'ninguna', 'nombre ambiguo'];
                    break;

                case 1:
                    $items[] = [$articulo, 'Bisagras', null, 'dudosa', 'no queda claro'];
                    break;

                default:
                    $items[] = ($i % 2 === 0)
                        ? [$articulo, 'Bisagras', 'Comunes', 'segura']
                        : [$articulo, 'Correderas', null, 'segura'];
            }
        }

        $sembrado = $this->sembrar_corrida([
            'propuestas' => [
                'A' => [
                    'arbol' => ['Bisagras' => ['Comunes'], 'Correderas' => []],
                    'items' => $items,
                ],
            ],
        ]);

        return [
            'vieja'     => $vieja,
            'articulos' => $articulos,
            'run'       => $sembrado['run'],
            'proposal'  => $sembrado['propuestas']['A']['proposal'],
            'items'     => $sembrado['propuestas']['A']['items'],
            'nodos'     => $sembrado['propuestas']['A']['nodos'],
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Dos procesos reales (las pruebas de carrera)
    // ---------------------------------------------------------------------------------------------

    /**
     * Lanza un proceso PHP hijo (ProcesoHijoDeLaCarrera.php) que ejecuta UNA acción del flujo. Hereda el
     * entorno del test (APP_ENV=testing y la misma base de datos).
     *
     * 🔴 El hijo solo ve lo que está COMMITEADO: el test tiene que haber salido de su transacción
     * (`salir_de_la_transaccion_del_test`) antes de sembrar lo que el hijo va a usar.
     *
     * @param  string $accion  `elegir` | `crear` | `volver_atras`.
     * @param  array  $args    Los argumentos del hijo (user, run, propuesta, demora...).
     * @return array  ['proceso' => recurso, 'tuberias' => [...]] para `terminar_hijo`.
     */
    protected function iniciar_hijo($accion, array $args)
    {
        // La base que usa este test: el hijo tiene que usar exactamente la misma.
        $base = DB::connection()->getDatabaseName();

        // El entorno del hijo: el del proceso actual (solo las variables de texto) más lo que lo apunta a la base del test.
        $entorno = array_merge(array_filter(getenv(), 'is_string'), [
            'APP_ENV'     => 'testing',
            'DB_DATABASE' => $base,
            'XDEBUG_MODE' => 'off',
        ]);

        $tuberias = [];

        $proceso = proc_open(
            [PHP_BINARY, __DIR__.'/ProcesoHijoDeLaCarrera.php', $accion, json_encode($args)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $tuberias,
            base_path(),
            $entorno
        );

        return ['proceso' => $proceso, 'tuberias' => $tuberias];
    }

    /**
     * Espera a que termine un hijo y devuelve lo que informó.
     *
     * @param  array $hijo  Lo que devolvió `iniciar_hijo`.
     * @return array  ['status' => .., 'error' => .., 'ya_estaba' => bool, 'excepcion' => ..] o, si el hijo ni
     *                llegó a informar, ['sin_resultado' => true, 'salida' => texto, 'errores' => texto].
     */
    protected function terminar_hijo(array $hijo)
    {
        $salida  = stream_get_contents($hijo['tuberias'][1]);
        $errores = stream_get_contents($hijo['tuberias'][2]);

        proc_close($hijo['proceso']);

        if (preg_match('/RESULTADO:(.*)/', $salida, $coincidencia)) {
            return json_decode($coincidencia[1], true);
        }

        return ['sin_resultado' => true, 'salida' => $salida, 'errores' => $errores];
    }

    /**
     * Cierra la transacción del test (DatabaseTransactions) para que lo sembrado hasta acá, y lo que se siembre
     * después, quede COMMITEADO y lo vean los procesos hijos. Quien la use tiene que limpiar lo que dejó
     * (`limpiar_comercio_commiteado`) en un `finally`: nada de esto se revierte solo.
     *
     * @return void
     */
    protected function salir_de_la_transaccion_del_test()
    {
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
    }

    /**
     * Borra TODO lo que un comercio dejó commiteado: filas de cada tabla con `user_id` y el usuario. Es la
     * limpieza de las pruebas de carrera (que no pueden apoyarse en el rollback del test). Solo sirve en una
     * base de testing (EmpresaTestCase ya lo exige antes de cada test).
     *
     * @param  int $user_id
     * @return void
     */
    protected function limpiar_comercio_commiteado($user_id)
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $tablas = DB::select(
            'SELECT DISTINCT table_name AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = ?',
            ['user_id']
        );

        foreach ($tablas as $tabla) {
            DB::table($tabla->t)->where('user_id', (int) $user_id)->delete();
        }

        DB::table('users')->where('id', (int) $user_id)->delete();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Las categorías vivas del comercio con ese nombre (con la collation de la columna: sin distinguir
     * mayúsculas ni acentos, igual que el sistema).
     *
     * @param  string $nombre
     * @param  \App\Models\User|null $dueno
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function categorias_llamadas($nombre, $dueno = null)
    {
        $dueno = is_null($dueno) ? $this->owner : $dueno;

        return Category::where('user_id', $dueno->id)->where('name', $nombre)->orderBy('id')->get();
    }
}
