<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;

/**
 * El porcentaje de una lista aplicado a los artículos existentes, en bloque (misión
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * PriceTypeHelper::sync_existing_articles_percentage() (PriceTypeController::update, al cambiar el
 * porcentaje de una lista con "aplicar a los existentes") hacía un updateExistingPivot() y un
 * Log::info POR ARTÍCULO, sincrónico en el request. Ahora es un UPDATE en bloque con exactamente la
 * misma semántica. Se prueba:
 *
 *  - equivalencia contra el camino de hoy (actualizar_como_hoy(), el cuerpo de develop tal cual),
 *    modo por modo, sobre filas armadas para que cada modo elija distinto: porcentaje igual al
 *    viejo, null, personalizado, la lista atada dos veces al mismo artículo, un artículo borrado,
 *    y filas de OTRA lista que no se pueden tocar;
 *  - que las consultas no crecen con los artículos (50 y 500 atados, las mismas);
 *  - que los lotes del recálculo salen a nombre del dueño aunque la lista esté a nombre de un
 *    empleado (el motor rechaza a un empleado).
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Porcentaje_de_lista_en_bloque_Test extends RecalculoEnLoteTestCase
{
    /** @var array SQL ejecutado mientras $contando está prendido. */
    protected $consultas = [];

    /** @var bool */
    protected $contando = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consultas = [];
        $this->contando  = false;

        DB::listen(function ($query) {
            if ($this->contando) {
                $this->consultas[] = $query->sql;
            }
        });
    }

    /**
     * Los modos, con el porcentaje viejo de la lista que llega en cada caso.
     *
     * @return array
     */
    public function modos()
    {
        return [
            'todos'                         => ['all', '30'],
            'solo los del viejo por defecto' => ['only_default_matches', '30'],
            'solo los que estaban en null'  => ['only_default_matches', null],
            'ninguno'                       => ['none', '30'],
        ];
    }

    /**
     * @dataProvider modos
     *
     * @param  string      $modo
     * @param  string|null $porcentaje_viejo
     * @return void
     */
    public function test_el_bloque_deja_los_mismos_pivots_que_el_camino_de_hoy($modo, $porcentaje_viejo)
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        $lista = $this->crear_lista($dueno, 'Mayorista', 30, 1);
        $otra  = $this->crear_lista($dueno, 'Otra', 30, 2);

        $atar = function ($article, $lista_id, $porcentaje) {
            DB::table('article_price_type')->insert([
                'article_id' => $article->id, 'price_type_id' => $lista_id, 'percentage' => $porcentaje, 'final_price' => 100,
            ]);
        };

        $igual_al_viejo  = $this->crear_articulo($dueno, ['cost' => 10]);
        $en_null         = $this->crear_articulo($dueno, ['cost' => 10]);
        $personalizado   = $this->crear_articulo($dueno, ['cost' => 10]);
        $dos_veces       = $this->crear_articulo($dueno, ['cost' => 10]);
        $borrado         = $this->crear_articulo($dueno, ['cost' => 10]);
        $solo_en_la_otra = $this->crear_articulo($dueno, ['cost' => 10]);

        $atar($igual_al_viejo, $lista->id, '30.00');
        $atar($igual_al_viejo, $otra->id, '30.00');
        $atar($en_null, $lista->id, null);
        $atar($personalizado, $lista->id, '12.50');
        $atar($dos_veces, $lista->id, '30.00');
        $atar($dos_veces, $lista->id, '99.00');
        $atar($borrado, $lista->id, '30.00');
        $atar($solo_en_la_otra, $otra->id, null);

        $borrado->delete();

        $todos = [$igual_al_viejo->id, $en_null->id, $personalizado->id, $dos_veces->id, $borrado->id, $solo_en_la_otra->id];

        /* El porcentaje nuevo de la lista, como lo deja PriceTypeController::update() antes de llamar. */
        DB::table('price_types')->where('id', $lista->id)->update(['percentage' => 45]);

        Queue::fake();

        /* Camino de hoy. */
        DB::beginTransaction();
        $ids_hoy = $this->actualizar_como_hoy(\App\Models\PriceType::find($lista->id), $porcentaje_viejo, $modo);
        $pivots_hoy = $this->pivots($todos);
        DB::rollBack();

        /* En bloque. */
        DB::beginTransaction();
        PriceTypeHelper::sync_existing_articles_percentage(\App\Models\PriceType::find($lista->id), $porcentaje_viejo, $modo);
        $pivots_en_bloque = $this->pivots($todos);
        $ids_en_bloque = $this->ids_encolados();
        DB::rollBack();

        $this->assertEquals($pivots_hoy, $pivots_en_bloque, 'El UPDATE en bloque dejó el pivot distinto del camino por artículo de hoy.');

        sort($ids_hoy);
        $this->assertSame($ids_hoy, $ids_en_bloque, 'Los artículos que se mandan a recalcular no son los mismos.');

        /* Guarda: salvo "ninguno", el modo tenía que cambiar alguna fila. */
        if ($modo !== 'none') {
            $this->assertNotEquals($this->pivots($todos), $pivots_hoy, 'El modo no cambió ninguna fila: la comparación no prueba nada.');
        }
    }

    /**
     * Con 50 y con 500 artículos atados, las mismas consultas: el UPDATE no es por artículo.
     *
     * @return void
     */
    public function test_las_consultas_no_crecen_con_los_articulos()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        $con_50  = $this->contar_consultas($dueno, 50);
        $con_500 = $this->contar_consultas($dueno, 500);

        $this->assertSame(
            count($con_50),
            count($con_500),
            "Las consultas crecen con los artículos.\n50:\n" . implode("\n", $con_50) . "\n500:\n" . implode("\n", $con_500)
        );

        $this->assertSame(1, count(array_filter($con_500, function ($sql) {
            return preg_match('/^update `article_price_type`/i', $sql) === 1;
        })), 'Tenía que haber UN solo UPDATE del pivot para los 500 artículos.');
    }

    /**
     * Una lista a nombre de un empleado: los lotes del recálculo salen igual a nombre del dueño.
     *
     * @return void
     */
    public function test_los_lotes_salen_a_nombre_del_dueno_aunque_la_lista_sea_de_un_empleado()
    {
        $dueno = $this->crear_dueno(['listas_de_precio' => 1]);

        $empleado = User::create([
            'name'     => 'zz Empleado lista',
            'email'    => 'lista-empleado-' . uniqid('', true) . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
        ]);

        $lista = $this->crear_lista($dueno, 'Del empleado', 30, 1);
        DB::table('price_types')->where('id', $lista->id)->update(['user_id' => $empleado->id, 'percentage' => 45]);

        $article = $this->crear_articulo($dueno, ['cost' => 10]);
        DB::table('article_price_type')->insert(['article_id' => $article->id, 'price_type_id' => $lista->id, 'percentage' => '30.00']);

        Queue::fake();

        PriceTypeHelper::sync_existing_articles_percentage(\App\Models\PriceType::find($lista->id), '30', 'all');

        $dueno_id = (int) $dueno->id;

        Queue::assertPushed(ProcessChunkSetFinalPrices::class, function ($chunk) use ($dueno_id) {
            $propiedad = new \ReflectionProperty($chunk, 'user_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($chunk) === $dueno_id;
        });
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 LA REFERENCIA: el cuerpo de PriceTypeHelper::sync_existing_articles_percentage() de
     * develop, tal cual, hasta el punto en que encolaba el recálculo (que acá se reemplaza por
     * devolver los ids). No se moderniza: dejaría de ser la referencia.
     *
     * @param  \App\Models\PriceType $price_type
     * @param  mixed                 $old_percentage
     * @param  string                $update_mode
     * @return int[] Los artículos que se mandaban a recalcular.
     */
    protected function actualizar_como_hoy($price_type, $old_percentage, $update_mode)
    {
        if ($update_mode == 'none') {
            return [];
        }

        $articles_query = $price_type->articles()->select('articles.id');

        if ($update_mode == 'only_default_matches') {
            if (is_null($old_percentage) || $old_percentage === '') {
                $articles_query->wherePivotNull('percentage');
            } else {
                $normalized = PriceTypeHelper::normalize_decimal_percentage($old_percentage);
                $articles_query->wherePivot('percentage', $normalized);
            }
        }

        $new_percentage = is_null($price_type->percentage) || $price_type->percentage === ''
            ? null
            : PriceTypeHelper::normalize_decimal_percentage($price_type->percentage);

        $article_ids = $articles_query->pluck('id')->unique()->values()->all();

        $batch_size = 200;
        for ($offset = 0; $offset < count($article_ids); $offset += $batch_size) {
            $article_id_chunk = array_slice($article_ids, $offset, $batch_size);
            foreach ($article_id_chunk as $article_id) {
                $price_type->articles()->updateExistingPivot($article_id, [
                    'percentage' => $new_percentage,
                ]);
            }
        }

        return array_map('intval', $article_ids);
    }

    /**
     * Todas las filas del pivot de esos artículos, con todas sus columnas (id incluido: un UPDATE
     * no cambia ids), ordenadas.
     *
     * @param  array $ids
     * @return array
     */
    protected function pivots(array $ids)
    {
        return DB::table('article_price_type')
                    ->whereIn('article_id', $ids)
                    ->orderBy('id')
                    ->get()
                    ->map(function ($fila) { return (array) $fila; })
                    ->all();
    }

    /**
     * Los ids que quedaron encolados para recalcular (en los lotes de la cola falsa), ordenados.
     *
     * @return int[]
     */
    protected function ids_encolados()
    {
        $ids = [];

        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class) as $chunk) {
            $propiedad = new \ReflectionProperty($chunk, 'article_ids');
            $propiedad->setAccessible(true);

            foreach ($propiedad->getValue($chunk) as $id) {
                $ids[] = (int) $id;
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * Arma una lista atada a $cantidad artículos y cuenta las consultas de aplicar el porcentaje
     * nuevo a todos (con la cola falsa, así el recálculo no corre).
     *
     * @param  \App\Models\User $dueno
     * @param  int              $cantidad
     * @return array
     */
    protected function contar_consultas($dueno, $cantidad)
    {
        $lista = $this->crear_lista($dueno, 'Medida ' . $cantidad, 30, 1);

        $ahora = now()->format('Y-m-d H:i:s');
        $filas = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = ['name' => 'zz Pivot ' . $cantidad . ' ' . $i, 'user_id' => $dueno->id, 'status' => 'active', 'cost' => 10, 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        foreach (array_chunk($filas, 250) as $tanda) {
            DB::table('articles')->insert($tanda);
        }

        $ids = DB::table('articles')->where('user_id', $dueno->id)->where('name', 'like', 'zz Pivot ' . $cantidad . ' %')->pluck('id')->all();

        $pivots = [];
        foreach ($ids as $id) {
            $pivots[] = ['article_id' => $id, 'price_type_id' => $lista->id, 'percentage' => '30.00'];
        }
        foreach (array_chunk($pivots, 250) as $tanda) {
            DB::table('article_price_type')->insert($tanda);
        }

        DB::table('price_types')->where('id', $lista->id)->update(['percentage' => 45]);

        $price_type = \App\Models\PriceType::find($lista->id);

        Queue::fake();

        $this->consultas = [];
        $this->contando  = true;

        PriceTypeHelper::sync_existing_articles_percentage($price_type, '30', 'all');

        $this->contando = false;

        $this->assertSame($cantidad, DB::table('article_price_type')->where('price_type_id', $lista->id)->where('percentage', '45.00')->count(), 'No se actualizaron todos los pivots.');

        return $this->consultas;
    }
}
