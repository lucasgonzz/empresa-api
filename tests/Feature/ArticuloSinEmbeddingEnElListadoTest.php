<?php

namespace Tests\Feature;

use App\Models\Article;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EmpresaTestCase;

/**
 * Misión optimizacion-vps-fase1 (10/9/2026, release 4.0.24) — el vector de embeddings no viaja en
 * el listado de artículos ni se lee de la base para armarlo.
 *
 * Dos capas, y las dos se prueban por separado porque cada una cubre lo que la otra no:
 *   - `$hidden = ['embedding']` en Article: la clave no sale en ningún JSON del modelo. Pero
 *     $hidden no evita que la columna se lea y se hidrate (29 KB por fila en memoria).
 *   - `scopeSinEmbedding()`: el SELECT del listado nombra todas las columnas menos `embedding`,
 *     así que el vector no sale de MySQL. Se verifica leyendo la consulta real que se ejecutó.
 *
 * Y lo que NO tiene que romperse: el atributo sigue legible por quien consulta sin el scope
 * (ArticleEmbeddingService, los observers), y las otras columnas `embedding_*` siguen viajando.
 */
class ArticuloSinEmbeddingEnElListadoTest extends EmpresaTestCase
{
    /** Un vector chico cualquiera: lo que importa es que exista en la fila. */
    const VECTOR = [0.5, 0.25, 0.125];

    /**
     * Le escribe un embedding al artículo centinela del fixture, directo en la tabla (Eloquent
     * dispararía los observers de embeddings).
     *
     * @return Article
     */
    protected function articulo_con_embedding()
    {
        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

        DB::table('articles')->where('id', $articulo->id)->update(['embedding' => json_encode(self::VECTOR)]);

        return $articulo;
    }

    /**
     * La consulta del listado (la que tiene el LIMIT de la página) entre todas las que se
     * ejecutaron durante el request.
     *
     * @param  array  $log  Salida de DB::getQueryLog().
     * @return string|null
     */
    protected function consulta_del_listado(array $log)
    {
        foreach ($log as $consulta) {
            if (strpos($consulta['query'], 'from `articles`') !== false
                && strpos($consulta['query'], 'limit 500') !== false) {
                return $consulta['query'];
            }
        }

        return null;
    }

    /**
     * GET article/index/from-status: la clave `embedding` no está en el JSON, y el SELECT que se
     * ejecutó no la pidió (pero sí pidió el resto, incluidas las otras columnas embedding_*).
     *
     * @return void
     */
    public function test_el_listado_no_trae_la_clave_embedding_y_el_select_no_la_pide()
    {
        $articulo = $this->articulo_con_embedding();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->getJson('api/article/index/from-status');

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $response->assertStatus(200);

        $fila = collect($response->json('models.data'))->firstWhere('id', $articulo->id);

        $this->assertNotNull($fila, 'El artículo centinela tiene que estar en la primera página del listado.');
        $this->assertArrayNotHasKey('embedding', $fila, 'El vector no tiene que viajar en el JSON.');
        $this->assertArrayHasKey('name', $fila);
        $this->assertArrayHasKey('embedding_generated_at', $fila, 'Las otras columnas embedding_* siguen viajando: el select explícito no las tiene que perder.');
        $this->assertArrayHasKey('images', $fila, 'withAll() sigue cargando las relaciones después del scope.');

        $listado = $this->consulta_del_listado($log);

        $this->assertNotNull($listado, 'No se encontró la consulta del listado en el log: ' . json_encode(array_column($log, 'query')));
        $this->assertStringNotContainsString('`embedding`', $listado, 'El SELECT del listado no tiene que nombrar la columna embedding.');
        $this->assertStringNotContainsString('select *', $listado, 'Tiene que ser un select explícito, no un *.');
        $this->assertStringContainsString('`articles`.`embedding_generated_at`', $listado, 'Las columnas van prefijadas con la tabla.');
        $this->assertStringContainsString('`articles`.`id`', $listado);
    }

    /**
     * $hidden saca la clave de toArray()/toJson() pero NO del acceso al atributo: quien consulta
     * sin el scope sigue viendo el vector. Y quien consulta CON el scope recibe null, no un error.
     *
     * @return void
     */
    public function test_el_atributo_sigue_legible_sin_el_scope_y_no_sale_en_toarray()
    {
        $articulo = $this->articulo_con_embedding();

        $completo = Article::find($articulo->id);

        $this->assertSame(self::VECTOR, json_decode($completo->embedding, true), 'Sin el scope, el atributo se lee igual que siempre.');
        $this->assertArrayNotHasKey('embedding', $completo->toArray(), '$hidden lo saca del array.');
        $this->assertArrayNotHasKey('embedding', json_decode($completo->toJson(), true), 'Y del JSON.');

        $liviano = Article::sinEmbedding()->find($articulo->id);

        $this->assertNotNull($liviano);
        $this->assertSame(TestingFerreteriaSeeder::ARTICULO_CENTINELA, $liviano->name, 'El resto del modelo se hidrata completo.');
        $this->assertNull($liviano->embedding, 'Con el scope, el vector no se leyó de la base.');
    }

    /**
     * La lista de columnas es "todas menos embedding", prefijada con la tabla, y se calcula una
     * sola vez por proceso (Schema::getColumnListing es un SELECT a information_schema).
     *
     * @return void
     */
    public function test_las_columnas_sin_embedding_son_todas_menos_una_y_se_calculan_una_vez()
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $primera = Article::columnas_sin_embedding();
        $segunda = Article::columnas_sin_embedding();

        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $todas = Schema::getColumnListing('articles');

        $this->assertCount(count($todas) - 1, $primera);
        $this->assertContains('articles.id', $primera);
        $this->assertContains('articles.embedding_generated_at', $primera);
        $this->assertContains('articles.embedding_source_hash', $primera);
        $this->assertNotContains('articles.embedding', $primera);
        $this->assertSame($primera, $segunda);

        $consultas_al_esquema = 0;
        foreach ($log as $consulta) {
            if (stripos($consulta['query'], 'information_schema') !== false) {
                $consultas_al_esquema++;
            }
        }

        // 0 si otro test del mismo proceso ya calentó la estática; nunca más de 1.
        $this->assertLessThanOrEqual(1, $consultas_al_esquema, 'Dos llamadas seguidas no pueden consultar el esquema dos veces.');
    }
}
