<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\PriceUpdateRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * El productor del recálculo reparte los ids sin OFFSET y siempre por dueño (misión
 * recalculo-precios-motor-rapido, 28/9/2026).
 *
 * Por qué importa: el ->chunk(100) de antes paginaba con OFFSET, y en Servian cada página por
 * proveedor (`provider_id = ? ... OFFSET 20000 LIMIT 100`, sin índice) leía y tiraba todas las
 * anteriores: 112 ms por página. Y la consulta por columna no llevaba user_id: ni usaba el índice
 * por (user_id, provider_id), ni impedía que un id de otra cuenta de una base compartida entrara.
 *
 * Todo el catálogo y el dólar global van por keyset. Un alcance por columna, desde el 29/9/2026,
 * es UNA consulta sin ORDER BY con los ids ordenados en PHP: con ORDER BY id, MySQL dejaba el
 * índice (user_id, provider_id, status, deleted_at) y recorría el catálogo entero del dueño
 * (ETMAN, 64.500 artículos: 8,4 s de reparto contra 50 ms). Por eso esos tests miran además que
 * la consulta sea una sola, sin ORDER BY ni status, y que los lotes salgan ordenados y completos
 * aunque el índice devuelva los ids agrupados por status.
 *
 * Con la cola falsa, los lotes no corren: se mira qué encoló el productor y con qué consultas.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class El_productor_es_keyset_y_filtra_por_dueno_Test extends RecalculoEnLoteTestCase
{
    /** @var array SQL ejecutado mientras $contando está prendido. */
    protected $consultas = [];

    /** @var bool */
    protected $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $this->consultas = [];
        $this->contando  = false;

        DB::listen(function ($query) {
            if ($this->contando) {
                $this->consultas[] = $query->sql;
            }
        });
    }

    /**
     * Por proveedor: UNA consulta sin ORDER BY, y lotes en orden de id y completos (los inactivos
     * incluidos), sin OFFSET, con user_id, sin artículos borrados ni de otra cuenta.
     *
     * Los status van intercalados a propósito: el índice (user_id, provider_id, status, ...)
     * devuelve los ids agrupados por status, así que el orden de los lotes lo tiene que armar el
     * productor.
     *
     * @return void
     */
    public function test_por_proveedor_reparte_de_una_consulta_en_orden_con_user_id_y_sin_offset()
    {
        $dueno = $this->crear_dueno();

        $proveedor = $this->crear_proveedor($dueno);

        $ids = [];
        foreach (['active', 'inactive', 'active', 'from_budget', 'active'] as $status) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 10, 'provider_id' => $proveedor->id, 'status' => $status])->id;
        }

        $borrado = $this->crear_articulo($dueno, ['cost' => 10, 'provider_id' => $proveedor->id]);
        $borrado->delete();

        $otro_dueno = $this->crear_dueno();
        $ajeno = $this->crear_articulo($otro_dueno, ['cost' => 10, 'provider_id' => $proveedor->id])->id;

        $this->assertOrdenCrudoNoEsPorId($dueno->id, 'provider_id', $proveedor->id);

        $lotes = $this->lotes_encolados(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor'));

        $this->assertSame([[$ids[0], $ids[1]], [$ids[2], $ids[3]], [$ids[4]]], $lotes, 'Los lotes tienen que salir en orden de id, de a dos, completos (con los inactivos), sin el borrado ni el de otra cuenta.');

        $this->assertSinOffsetYConUserId();
        $this->assertUnaSolaConsultaSinOrden('provider_id');
    }

    /**
     * El dólar de un proveedor (columna + costo en dólares): la misma consulta única, con la
     * intersección; solo los del proveedor con costo en dólares, en orden.
     *
     * @return void
     */
    public function test_dolar_del_proveedor_reparte_de_una_consulta_solo_los_de_costo_en_dolares()
    {
        $dueno = $this->crear_dueno();

        $proveedor = $this->crear_proveedor($dueno);

        $en_dolares = [];
        foreach (['inactive', 'active', 'active', 'inactive'] as $i => $status) {
            $en_dolares[] = $this->crear_articulo($dueno, ['cost' => 10, 'provider_id' => $proveedor->id, 'status' => $status, 'cost_in_dollars' => 1])->id;
            $this->crear_articulo($dueno, ['cost' => 10, 'provider_id' => $proveedor->id, 'status' => $status]);
        }

        $lotes = $this->lotes_encolados(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, true, 'proveedor'));

        $this->assertSame([[$en_dolares[0], $en_dolares[1]], [$en_dolares[2], $en_dolares[3]]], $lotes);

        $this->assertSinOffsetYConUserId();
        $this->assertUnaSolaConsultaSinOrden('provider_id');

        $this->assertMatchesRegularExpression('/`cost_in_dollars`\s*=\s*\?/i', $this->consultas_de_articulos()[0], 'La consulta del dólar del proveedor perdió la intersección con el costo en dólares.');
    }

    /**
     * Por categoría (una columna sin índice propio): el mismo reparto de una consulta, lotes
     * ordenados y completos.
     *
     * @return void
     */
    public function test_por_categoria_reparte_de_una_consulta_en_orden()
    {
        $dueno = $this->crear_dueno();

        $categoria = $this->crear_categoria($dueno);

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 10, 'category_id' => $categoria->id, 'status' => $i === 1 ? 'inactive' : 'active'])->id;
        }

        /* De otra categoría: afuera. */
        $this->crear_articulo($dueno, ['cost' => 10]);

        $lotes = $this->lotes_encolados(new ProcessSetFinalPrices($dueno->id, 'category_id', $categoria->id, false, 'categoria'));

        $this->assertSame([[$ids[0], $ids[1]], [$ids[2]]], $lotes);

        $this->assertSinOffsetYConUserId();
        $this->assertUnaSolaConsultaSinOrden('category_id');
    }

    /**
     * Todo el catálogo del dueño (el recálculo por configuración de la cuenta).
     *
     * @return void
     */
    public function test_todo_el_catalogo_reparte_solo_los_articulos_del_dueno()
    {
        $dueno = $this->crear_dueno();

        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 10])->id;
        }

        $otro_dueno = $this->crear_dueno();
        $this->crear_articulo($otro_dueno, ['cost' => 10]);

        $lotes = $this->lotes_encolados(new ProcessSetFinalPrices($dueno->id, null, null, false, 'configuracion_usuario'));

        $this->assertSame([[$ids[0], $ids[1]], [$ids[2]]], $lotes);

        $this->assertSinOffsetYConUserId();
    }

    /**
     * Dólar global: la unión, en orden, sin repetidos, paginada por keyset (las dos ramas).
     * El artículo que está en las dos ramas, y el que tiene dos entradas que cotizan, aparecen
     * una sola vez; y el keyset no se come ids de una rama cuando la otra avanza más rápido.
     *
     * @return void
     */
    public function test_dolar_global_reparte_la_union_sin_repetidos_por_keyset()
    {
        $dueno = $this->crear_dueno();

        $lista_1 = $this->crear_lista($dueno, 'Lista 1', 30, 1);
        $lista_2 = $this->crear_lista($dueno, 'Lista 2', 30, 2);

        /*
         * Intercalados a propósito: c = cotiza, d = costo en dólares, x = afuera, dc = las dos.
         *
         * 🔴 El orden c1 (con DOS entradas que cotizan) < c2 < d1 es el que prueba el DISTINCT de
         * la rama de monedas: sin él, esa rama devuelve [c1, c1] para un lote de 2, la unión corta
         * en [c1, d1] y el cursor salta por encima de c2, que no se recalcula nunca.
         */
        $c1  = $this->crear_articulo($dueno, ['cost' => 1])->id;
        $c2  = $this->crear_articulo($dueno, ['cost' => 1])->id;
        $d1  = $this->crear_articulo($dueno, ['cost' => 1, 'cost_in_dollars' => 1])->id;
        $x1  = $this->crear_articulo($dueno, ['cost' => 1])->id;
        $dc  = $this->crear_articulo($dueno, ['cost' => 1, 'cost_in_dollars' => 1])->id;
        $c3  = $this->crear_articulo($dueno, ['cost' => 1])->id;
        $d2  = $this->crear_articulo($dueno, ['cost' => 1, 'cost_in_dollars' => 1])->id;

        foreach ([$c1, $c2, $c3, $dc] as $id) {
            $this->entrada($id, $lista_1, 1);
        }

        /* c1 con dos listas que cotizan: tiene que entrar una sola vez. */
        $this->entrada($c1, $lista_2, 1);

        /* x1 tiene una entrada que NO cotiza. */
        $this->entrada($x1, $lista_1, 0);

        /* Uno de otra cuenta con costo en dólares y que cotiza: afuera. */
        $otro_dueno = $this->crear_dueno();
        $ajeno = $this->crear_articulo($otro_dueno, ['cost' => 1, 'cost_in_dollars' => 1])->id;
        $this->entrada($ajeno, $lista_1, 1);

        $lotes = $this->lotes_encolados(new ProcessSetFinalPrices($dueno->id, null, null, true, 'dolar'));

        $this->assertSame([[$c1, $c2], [$d1, $dc], [$c3, $d2]], $lotes);

        $this->assertSinOffsetYConUserId();
    }

    /**
     * Una columna de alcance fuera de la lista blanca no llega a la consulta: la corrida cierra
     * en error y avisa (el finalizador ya quedó encolado).
     *
     * @return void
     */
    public function test_una_columna_fuera_de_la_lista_blanca_cierra_la_corrida_en_error()
    {
        $dueno = $this->crear_dueno();
        $this->crear_articulo($dueno, ['cost' => 10]);

        Notification::fake();
        Queue::fake();

        $ids_previos = PriceUpdateRun::where('user_id', $dueno->id)->pluck('id')->toArray();

        $this->contando = true;
        (new ProcessSetFinalPrices($dueno->id, 'name', 'x', false, 'proveedor'))->handle();
        $this->contando = false;

        $run = PriceUpdateRun::where('user_id', $dueno->id)->whereNotIn('id', $ids_previos)->first();

        $this->assertNotNull($run);
        $this->assertSame('error', $run->status);
        $this->assertStringContainsString('name', (string) $run->error_detalle);

        Queue::assertNotPushed(ProcessChunkSetFinalPrices::class);
        Queue::assertPushed(FinalizeSetFinalPrices::class);

        foreach ($this->consultas as $sql) {
            $this->assertStringNotContainsString('`name` =', $sql, 'La columna fuera de la lista blanca llegó a una consulta.');
        }
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Corre el productor con la cola falsa y devuelve los ids de cada lote encolado, en orden.
     *
     * @param  \App\Jobs\ProcessSetFinalPrices $job
     * @return array
     */
    protected function lotes_encolados($job)
    {
        Notification::fake();
        Queue::fake();

        $this->consultas = [];
        $this->contando  = true;

        $job->handle();

        $this->contando = false;

        $lotes = [];

        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class) as $chunk) {

            $propiedad = new \ReflectionProperty($chunk, 'article_ids');
            $propiedad->setAccessible(true);

            $ids = [];
            foreach ($propiedad->getValue($chunk) as $id) {
                $ids[] = (int) $id;
            }

            $lotes[] = $ids;
        }

        return $lotes;
    }

    /**
     * Ninguna consulta del productor usa OFFSET, y toda consulta que elige ids de artículos
     * filtra por user_id.
     *
     * @return void
     */
    protected function assertSinOffsetYConUserId()
    {
        $de_articulos = 0;

        foreach ($this->consultas as $sql) {

            $this->assertDoesNotMatchRegularExpression('/\boffset\b/i', $sql, 'El productor paginó con OFFSET: ' . $sql);

            /* Toda consulta que lee `articles` (directo o por join) es una consulta de alcance. */
            if (preg_match('/^select .*(\bfrom `articles`|\bjoin `articles`)/i', $sql)) {
                $de_articulos++;
                $this->assertMatchesRegularExpression('/(`articles`\.)?`user_id`\s*=\s*\?/i', $sql, 'Una consulta de ids de artículos no filtra por user_id: ' . $sql);
            }
        }

        $this->assertGreaterThan(0, $de_articulos, 'No se vio ninguna consulta de ids de artículos: el test no está mirando lo que dice.');
    }

    /**
     * Las consultas del productor que leen `articles`, en orden.
     *
     * @return string[]
     */
    protected function consultas_de_articulos()
    {
        $de_articulos = [];

        foreach ($this->consultas as $sql) {
            if (preg_match('/^select .*\bfrom `articles`/i', $sql)) {
                $de_articulos[] = $sql;
            }
        }

        return $de_articulos;
    }

    /**
     * El alcance por columna se juntó en UNA consulta, sin ORDER BY ni LIMIT (con ORDER BY id,
     * MySQL deja el índice por (user_id, provider_id) y recorre todo el catálogo del dueño), con la
     * columna, sin borrados y sin filtrar por status (los inactivos también se recalculan).
     *
     * @param  string $columna
     * @return void
     */
    protected function assertUnaSolaConsultaSinOrden($columna)
    {
        $de_articulos = $this->consultas_de_articulos();

        $this->assertCount(1, $de_articulos, 'El alcance por columna se tiene que juntar en una sola consulta: ' . implode(' || ', $de_articulos));

        $sql = $de_articulos[0];

        $this->assertDoesNotMatchRegularExpression('/\border\s+by\b/i', $sql, 'La consulta del alcance por columna volvió a ordenar en la base: ' . $sql);
        $this->assertDoesNotMatchRegularExpression('/\blimit\b/i', $sql, 'La consulta del alcance por columna volvió a paginar: ' . $sql);
        $this->assertMatchesRegularExpression('/`' . $columna . '`\s*=\s*\?/i', $sql);
        $this->assertMatchesRegularExpression('/`deleted_at`\s+is\s+null/i', $sql, 'La consulta dejó de excluir los borrados: ' . $sql);
        $this->assertDoesNotMatchRegularExpression('/`status`/i', $sql, 'El alcance no filtra por status: los inactivos también se recalculan.');
    }

    /**
     * Precondición: la misma consulta, sin ORDER BY, devuelve los ids en un orden que NO es el de
     * id (el índice los agrupa por status). Si los devolviera ordenados, el test no probaría que
     * el productor ordena.
     *
     * @param  int    $user_id
     * @param  string $columna
     * @param  int    $valor
     * @return void
     */
    protected function assertOrdenCrudoNoEsPorId($user_id, $columna, $valor)
    {
        $crudos = [];

        foreach (DB::select('select `articles`.`id` from `articles` where `user_id` = ? and `' . $columna . '` = ? and `articles`.`deleted_at` is null', [$user_id, $valor]) as $fila) {
            $crudos[] = (int) $fila->id;
        }

        $ordenados = $crudos;
        sort($ordenados);

        $this->assertNotSame($ordenados, $crudos, 'Precondición: la base tenía que devolver los ids agrupados por status, no en orden de id (si no, el test no prueba el orden que arma el productor).');
    }

    /**
     * @param  int                   $article_id
     * @param  \App\Models\PriceType $lista
     * @param  int                   $cotiza
     * @return void
     */
    protected function entrada($article_id, $lista, $cotiza)
    {
        ArticlePriceTypeMoneda::create([
            'article_id'                => $article_id,
            'price_type_id'             => $lista->id,
            'moneda_id'                 => self::ARS,
            'percentage'                => 30,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => $cotiza,
        ]);
    }
}
