<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Article;
use App\Models\User;
use App\Services\ArticleEmbeddingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Servicio de prueba con la tanda de la búsqueda bajada a 3, para ejercitar el recorrido en
 * varias tandas sin sembrar más de 500 artículos. Es lo ÚNICO que cambia: el método que se prueba
 * es el de producción, que lee la constante con `static::`.
 */
class ArticleEmbeddingServiceConTandaChica extends ArticleEmbeddingService
{
    const TANDA_BUSQUEDA = 3;
}

/**
 * Misión rag-whatsapp-memoria-acotada — la búsqueda semántica del agente con memoria acotada.
 *
 * El agente de WhatsApp moría por memoria en catálogos grandes: la búsqueda en MySQL traía el
 * JSON de 1536 floats de TODOS los artículos del dueño en cada mensaje. Ahora recorre por tandas
 * un vector compacto (512 dims normalizadas, float32) guardado en `article_compact_embeddings`,
 * quedándose con el top-K, y lo rellena de forma perezosa desde el JSON. Estos tests fijan que el
 * cambio de MECANISMO no cambió el RESULTADO:
 *
 * - el ranking es el de mayor coseno, con `$limit` resultados y las mismas 7 propiedades que
 *   consume `WhatsappBotAiService` (contrato que esta misión no toca);
 * - el backfill perezoso deja los compactos guardados y una segunda búsqueda da lo mismo;
 * - los filtros de siempre (activo, no borrado, del dueño, con embedding) siguen valiendo, y la
 *   búsqueda no compacta artículos de otro dueño;
 * - `persistir_embedding()` —el único escritor de vectores— BORRA el compacto en vez de escribirlo
 *   (el job pone el sello de frescura después, así que uno escrito ahí nacería viejo);
 * - un compacto viejo (su `embedding_generated_at` ya no coincide con el del artículo, como deja
 *   un frente con la versión anterior al re-indexar) no se usa: se recompacta desde el JSON, tanto
 *   en la búsqueda como en el comando;
 * - el recorrido en varias tandas da el mismo top-K que la fuerza bruta, y a igual score gana el
 *   id menor;
 * - el comando `articles:compactar-embeddings` compacta los que faltan y no pisa los que están.
 *
 * La memoria en sí no se testea acá (sembrar miles de vectores de 1536 floats haría la suite
 * lenta): la garantía es estructural —el recorrido por tandas— y la medición con 8.000 artículos
 * está en el informe de la misión.
 */
class Busqueda_semantica_acotada_Test extends TestCase
{
    use DatabaseTransactions;

    /** Las propiedades exactas que devuelve la búsqueda, en el orden del SELECT. */
    const PROPIEDADES = ['id', 'name', 'final_price', 'stock', 'bar_code', 'slug', 'online'];

    /** @var User */
    protected $comercio;

    /** @var User Otro comercio de la misma base, para probar el aislamiento por dueño. */
    protected $otro_comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing: ningún test de este archivo sale a la red.
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);

        $this->comercio = User::create([
            'name'         => 'Comercio busqueda acotada',
            'company_name' => 'Ferreteria busqueda acotada',
            'email'        => 'busqueda-acotada-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'         => 'Otro comercio busqueda acotada',
            'company_name' => 'Otra ferreteria',
            'email'        => 'busqueda-acotada-otro-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);
    }

    /**
     * Stub de OpenAI: el embedding de la consulta es el vector que se pasa. El `'*'` final evita
     * que una request sin stub salga de verdad.
     *
     * @param array $vector_consulta
     * @return void
     */
    protected function fingir_openai(array $vector_consulta)
    {
        Http::fake([
            'api.openai.com/*' => Http::response(['data' => [['embedding' => $vector_consulta]]], 200),
            '*'                => Http::response([], 200),
        ]);
    }

    /**
     * Artículo con su vector cargado SOLO como JSON en `articles.embedding`, escrito por SQL crudo
     * (como quedaron todos los artículos de la flota antes del deploy): sin fila compacta.
     *
     * @param string     $nombre
     * @param array|null $vector  Null = artículo sin embedding.
     * @param array      $extra   Columnas extra para el create (status, user_id...).
     * @return Article
     */
    protected function articulo($nombre, $vector, array $extra = [])
    {
        $article = Article::create(array_merge([
            'name'        => $nombre,
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'final_price' => 1000,
            'stock'       => 10,
            'slug'        => \Illuminate\Support\Str::slug($nombre),
        ], $extra));

        if (!is_null($vector)) {
            DB::table('articles')->where('id', $article->id)->update([
                'embedding' => json_encode($vector),
            ]);
        }

        return $article;
    }

    /**
     * Ids de una colección de resultados, en orden.
     *
     * @param \Illuminate\Support\Collection $resultados
     * @return array
     */
    protected function ids($resultados)
    {
        return $resultados->map(function ($fila) {
            return (int) $fila->id;
        })->values()->all();
    }

    /**
     * Vector compacto guardado de un artículo, desempaquetado (o null si no tiene fila).
     *
     * @param int $article_id
     * @return array|null
     */
    protected function compacto_de($article_id)
    {
        $binario = DB::table('article_compact_embeddings')->where('article_id', $article_id)->value('vector');

        return is_null($binario) ? null : array_values(unpack('g*', $binario));
    }

    /**
     * Deja un compacto FRESCO para el artículo, como lo dejaría el recorrido: el vector sale del
     * JSON actual y el sello es el `embedding_generated_at` actual del artículo.
     *
     * @param int $article_id
     * @return void
     */
    protected function compactar_a_mano($article_id)
    {
        $fila = DB::table('articles')->where('id', $article_id)->first();

        DB::table('article_compact_embeddings')->insert([
            'article_id'             => $article_id,
            'user_id'                => $fila->user_id,
            'vector'                 => (new ArticleEmbeddingService())->compactar_vector(json_decode($fila->embedding, true)),
            'embedding_generated_at' => $fila->embedding_generated_at,
        ]);
    }

    /**
     * Similitud de coseno en float64, para la cuenta de referencia de fuerza bruta.
     *
     * @param array $a
     * @param array $b
     * @return float
     */
    protected function coseno(array $a, array $b)
    {
        $punto = 0.0;
        $norma_a = 0.0;
        $norma_b = 0.0;

        foreach ($a as $i => $valor) {
            $punto   += $valor * $b[$i];
            $norma_a += $valor * $valor;
            $norma_b += $b[$i] * $b[$i];
        }

        return $punto / (sqrt($norma_a) * sqrt($norma_b));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_ranking_es_el_de_mayor_coseno_con_limit_resultados_y_las_siete_propiedades()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        // Cosenos contra [1,0,0]: 1.0, 0.8, 0.6, 0.0. Se crean desordenados a propósito para que
        // el orden por id no coincida con el del ranking.
        $c = $this->articulo('Articulo coseno 0.6', [0.6, 0.8, 0.0]);
        $d = $this->articulo('Articulo coseno 0', [0.0, 0.0, 1.0]);
        $a = $this->articulo('Articulo coseno 1', [2.0, 0.0, 0.0]);
        $b = $this->articulo('Articulo coseno 0.8', [0.8, 0.6, 0.0]);

        $resultados = (new ArticleEmbeddingService())
            ->search_similar_articles('tornillos', (int) $this->comercio->id, 3);

        $this->assertEquals([$a->id, $b->id, $c->id], $this->ids($resultados));

        foreach ($resultados as $fila) {
            $this->assertEquals(
                self::PROPIEDADES,
                array_keys(get_object_vars($fila)),
                'WhatsappBotAiService consume exactamente estas propiedades: ni una más (el vector no puede viajar), ni una menos.'
            );
        }

        $this->assertEquals('Articulo coseno 1', $resultados[0]->name);
        $this->assertEquals('articulo-coseno-1', $resultados[0]->slug);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function la_primera_busqueda_compacta_y_la_segunda_da_lo_mismo_sin_volver_al_json()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        $a = $this->articulo('Backfill A', [3.0, 4.0, 0.0]);
        $b = $this->articulo('Backfill B', [1.0, 0.0, 0.0]);

        $this->assertNull($this->compacto_de($a->id), 'Precondición: el artículo arranca sin compacto, como la flota antes del deploy.');

        $servicio = new ArticleEmbeddingService();
        $primera  = $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5));

        $this->assertEquals([$b->id, $a->id], $primera);

        // Los dos quedaron compactados, normalizados y con el dueño correcto.
        $compacto_a = $this->compacto_de($a->id);
        $this->assertCount(3, $compacto_a);
        $this->assertEqualsWithDelta(0.6, $compacto_a[0], 1e-6);
        $this->assertEqualsWithDelta(0.8, $compacto_a[1], 1e-6);
        $this->assertEquals(
            $this->comercio->id,
            DB::table('article_compact_embeddings')->where('article_id', $b->id)->value('user_id')
        );

        $segunda = $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5));
        $this->assertEquals($primera, $segunda);

        /*
         * Prueba de que la segunda búsqueda usa el compacto y no el JSON: se pisa el JSON de B por
         * SQL crudo (sin pasar por persistir_embedding) con un vector ortogonal a la consulta. Si
         * la búsqueda volviera a leer el JSON, B caería al fondo; leyendo el compacto, sigue arriba.
         */
        DB::table('articles')->where('id', $b->id)->update(['embedding' => json_encode([0.0, 0.0, 1.0])]);

        $tercera = $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5));
        $this->assertEquals($primera, $tercera, 'Una vez compactado, el JSON de 28 KB no tiene que volver a viajar.');
    }

    /**
     * Guarda de rendimiento: con todo compactado, ninguna consulta de la búsqueda puede nombrar la
     * columna `articles.embedding`. Medido con 8.000 artículos, un simple `a.embedding IS NOT NULL`
     * hace que InnoDB lea los ~28 KB de JSON de cada fila aunque no los devuelva (1,47 s contra
     * 0,08 s). Si alguien "restituye" ese filtro en la pasada de los compactos, esto se pone rojo.
     *
     * @group whatsapp
     * @test
     */
    public function con_todo_compactado_ninguna_consulta_toca_la_columna_embedding()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        $this->articulo('Guarda A', [1.0, 0.0, 0.0]);
        $this->articulo('Guarda B', [0.0, 1.0, 0.0]);

        $servicio = new ArticleEmbeddingService();

        // Primera búsqueda: compacta (acá SÍ se lee el JSON, es el backfill).
        $servicio->search_similar_articles('x', (int) $this->comercio->id, 5);

        $consultas = [];
        DB::listen(function ($query) use (&$consultas) {
            $consultas[] = $query->sql;
        });

        $resultados = $servicio->search_similar_articles('x', (int) $this->comercio->id, 5);
        $this->assertCount(2, $resultados);

        $this->assertNotEmpty($consultas);

        foreach ($consultas as $sql) {
            // El nombre de la tabla nueva y la columna del sello contienen "embedding": se sacan
            // antes de buscar la columna pesada. Comparar el sello es barato (no arrastra el JSON).
            $sin_tabla = str_replace(['article_compact_embeddings', 'embedding_generated_at'], '', $sql);

            $this->assertStringNotContainsString(
                'embedding',
                $sin_tabla,
                'Con todo compactado, la búsqueda no puede nombrar articles.embedding: ' . $sql
            );
        }
    }

    /**
     * @group whatsapp
     * @test
     */
    public function los_filtros_de_siempre_siguen_valiendo_y_no_se_compacta_lo_de_otro_dueno()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        $valido    = $this->articulo('Filtro valido', [0.1, 1.0, 0.0]);
        $inactivo  = $this->articulo('Filtro inactivo', [1.0, 0.0, 0.0], ['status' => 'inactive']);
        $borrado   = $this->articulo('Filtro borrado', [1.0, 0.0, 0.0]);
        $ajeno     = $this->articulo('Filtro ajeno', [1.0, 0.0, 0.0], ['user_id' => $this->otro_comercio->id]);
        $sin_vector = $this->articulo('Filtro sin vector', null);
        $json_roto = $this->articulo('Filtro json roto', null);

        DB::table('articles')->where('id', $borrado->id)->update(['deleted_at' => now()]);
        DB::table('articles')->where('id', $json_roto->id)->update(['embedding' => json_encode([])]);

        $resultados = (new ArticleEmbeddingService())
            ->search_similar_articles('x', (int) $this->comercio->id, 10);

        // Los inactivos, borrados y ajenos tienen el vector IDÉNTICO a la consulta: si algún filtro
        // se hubiera perdido, aparecerían primeros.
        $this->assertEquals([$valido->id], $this->ids($resultados));

        $this->assertNull($this->compacto_de($ajeno->id), 'La búsqueda de un dueño no puede leer ni escribir nada de otro.');
        $this->assertNull($this->compacto_de($inactivo->id));
        $this->assertNull($this->compacto_de($borrado->id));
        $this->assertNull($this->compacto_de($sin_vector->id));
        $this->assertNull($this->compacto_de($json_roto->id), 'Un JSON vacío se saltea, no se compacta.');
    }

    /**
     * @group whatsapp
     * @test
     */
    public function persistir_embedding_guarda_el_json_y_borra_el_compacto()
    {
        $this->fingir_openai([0.0, 1.0, 0.0]);

        $articulo = $this->articulo('Persistir', [1.0, 0.0, 0.0]);
        $this->compactar_a_mano($articulo->id);
        $this->assertNotNull($this->compacto_de($articulo->id), 'Precondición: el artículo arranca compactado.');

        $servicio = new ArticleEmbeddingService();
        $servicio->persistir_embedding((int) $articulo->id, [0.0, 3.0, 4.0]);

        // Se compara decodificado: la columna es JSON y MySQL la re-serializa con espacios.
        $this->assertEquals(
            [0, 3, 4],
            json_decode(DB::table('articles')->where('id', $articulo->id)->value('embedding'), true),
            'El JSON completo sigue siendo la fuente de verdad.'
        );

        // No lo escribe: el job pone el sello DESPUÉS, así que un compacto escrito acá nacería viejo.
        $this->assertNull($this->compacto_de($articulo->id), 'persistir_embedding borra el compacto; lo re-arma el recorrido.');

        // Y el artículo no se pierde: la búsqueda lo compacta desde el JSON nuevo.
        $resultados = $servicio->search_similar_articles('x', (int) $this->comercio->id, 5);
        $this->assertEquals([$articulo->id], $this->ids($resultados));

        $compacto = $this->compacto_de($articulo->id);
        $this->assertEqualsWithDelta(0.0, $compacto[0], 1e-6);
        $this->assertEqualsWithDelta(0.6, $compacto[1], 1e-6);
        $this->assertEqualsWithDelta(0.8, $compacto[2], 1e-6);

        // Norma cero: también borra, y la búsqueda no lo compacta (no hay dirección que comparar).
        $servicio->persistir_embedding((int) $articulo->id, [0.0, 0.0, 0.0]);
        $this->assertNull($this->compacto_de($articulo->id));
        $this->assertCount(0, $servicio->search_similar_articles('x', (int) $this->comercio->id, 5));
        $this->assertNull($this->compacto_de($articulo->id));
    }

    /**
     * El caso real de la flota: el cron quedó en el frente con la versión anterior, que re-indexa
     * `articles.embedding` y `embedding_generated_at` SIN conocer `article_compact_embeddings`.
     * Se simula escribiendo las dos columnas por SQL crudo, sin pasar por `persistir_embedding()`.
     *
     * @group whatsapp
     * @test
     */
    public function un_compacto_viejo_se_recompacta_en_la_busqueda()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        $cerca = $this->articulo('Frescura cerca', [1.0, 0.0, 0.0]);
        $lejos = $this->articulo('Frescura lejos', [0.6, 0.8, 0.0]);

        DB::table('articles')->whereIn('id', [$cerca->id, $lejos->id])
            ->update(['embedding_generated_at' => '2026-09-01 10:00:00']);
        $this->compactar_a_mano($cerca->id);
        $this->compactar_a_mano($lejos->id);

        $servicio = new ArticleEmbeddingService();
        $this->assertEquals([$cerca->id, $lejos->id], $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5)));

        // El frente viejo re-indexa "cerca" con un vector ortogonal a la consulta.
        DB::table('articles')->where('id', $cerca->id)->update([
            'embedding'              => json_encode([0.0, 0.0, 1.0]),
            'embedding_generated_at' => '2026-09-28 18:30:00',
        ]);

        // Con el compacto viejo "cerca" seguiría primero; con el vector vigente queda último.
        $this->assertEquals(
            [$lejos->id, $cerca->id],
            $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5)),
            'Un compacto cuyo sello no coincide no se puede usar: rankearía con el vector anterior para siempre.'
        );

        $fila = DB::table('article_compact_embeddings')->where('article_id', $cerca->id)->first();
        $this->assertEquals('2026-09-28 18:30:00', $fila->embedding_generated_at, 'El compacto nuevo lleva el sello nuevo.');
        $this->assertEqualsWithDelta(1.0, $this->compacto_de($cerca->id)[2], 1e-6);

        // Ya fresco: una búsqueda más da lo mismo y no deja filas repetidas.
        $this->assertEquals([$lejos->id, $cerca->id], $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5)));
        $this->assertEquals(1, DB::table('article_compact_embeddings')->where('article_id', $cerca->id)->count());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_comando_tambien_recompacta_los_viejos()
    {
        $articulo = $this->articulo('Frescura comando', [1.0, 0.0, 0.0]);
        DB::table('articles')->where('id', $articulo->id)->update(['embedding_generated_at' => '2026-09-01 10:00:00']);
        $this->compactar_a_mano($articulo->id);

        // Re-indexado por el frente viejo.
        DB::table('articles')->where('id', $articulo->id)->update([
            'embedding'              => json_encode([0.0, 2.0, 0.0]),
            'embedding_generated_at' => '2026-09-28 18:30:00',
        ]);

        Artisan::call('articles:compactar-embeddings', ['--user_id' => $this->comercio->id]);
        $this->assertStringContainsString('1 artículo(s) compactado(s)', Artisan::output());

        $this->assertEqualsWithDelta(1.0, $this->compacto_de($articulo->id)[1], 1e-6);
        $this->assertEquals(
            '2026-09-28 18:30:00',
            DB::table('article_compact_embeddings')->where('article_id', $articulo->id)->value('embedding_generated_at')
        );

        // Ya fresco: la segunda corrida no encuentra nada.
        Artisan::call('articles:compactar-embeddings', ['--user_id' => $this->comercio->id]);
        $this->assertStringContainsString('0 artículo(s) compactado(s)', Artisan::output());
    }

    /**
     * @group whatsapp
     * @test
     */
    public function a_igual_score_gana_el_id_menor_y_el_orden_es_estable()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);

        // Cuatro artículos con el MISMO vector; K = 2 obliga a desempatar.
        $primero = $this->articulo('Empate 1', [0.6, 0.8, 0.0]);
        $segundo = $this->articulo('Empate 2', [0.6, 0.8, 0.0]);
        $this->articulo('Empate 3', [0.6, 0.8, 0.0]);
        $this->articulo('Empate 4', [0.6, 0.8, 0.0]);

        $servicio = new ArticleEmbeddingServiceConTandaChica();

        // Primera corrida (desde el JSON), segunda (desde el compacto) y con la tanda de producción.
        $this->assertEquals([$primero->id, $segundo->id], $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 2)));
        $this->assertEquals([$primero->id, $segundo->id], $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 2)));
        $this->assertEquals(
            [$primero->id, $segundo->id],
            $this->ids((new ArticleEmbeddingService())->search_similar_articles('x', (int) $this->comercio->id, 2))
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function con_limit_cero_devuelve_una_coleccion_vacia()
    {
        $this->fingir_openai([1.0, 0.0, 0.0]);
        $this->articulo('Limit cero', [1.0, 0.0, 0.0]);

        $resultados = (new ArticleEmbeddingService())->search_similar_articles('x', (int) $this->comercio->id, 0);

        $this->assertCount(0, $resultados);
    }

    /**
     * @group whatsapp
     * @test
     */
    public function compactar_vector_trunca_a_512_y_empaqueta_2048_bytes()
    {
        $servicio = new ArticleEmbeddingService();

        // Vector de 1536 como los de OpenAI: solo los primeros 512 tienen que sobrevivir.
        $vector = array_fill(0, 1536, 0.0);
        $vector[0]   = 1.0;
        $vector[600] = 50.0; // fuera de los 512: no puede influir en la norma.

        $binario = $servicio->compactar_vector($vector);

        $this->assertEquals(2048, strlen($binario), '512 floats de 4 bytes.');

        $desempaquetado = array_values(unpack('g*', $binario));
        $this->assertCount(ArticleEmbeddingService::DIMENSIONES_COMPACTAS, $desempaquetado);
        $this->assertEqualsWithDelta(1.0, $desempaquetado[0], 1e-6, 'Si el valor 600 hubiera entrado en la norma, esto daría ~0.02.');

        $this->assertNull($servicio->compactar_vector([]));
        $this->assertNull($servicio->compactar_vector([0, 0, 0]));
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_recorrido_en_varias_tandas_da_el_mismo_top_k_que_la_fuerza_bruta()
    {
        // Semilla fija: el test tiene que ser reproducible.
        mt_srand(20260928);

        $aleatorio = function () {
            $vector = [];
            for ($i = 0; $i < 8; $i++) {
                $vector[] = mt_rand(-1000, 1000) / 1000;
            }
            return $vector;
        };

        $consulta = $aleatorio();
        $this->fingir_openai($consulta);

        // 20 artículos con tanda de 3 = 7 tandas. La mitad ya compactados y la mitad no, para que
        // las dos ramas del recorrido (compacto y JSON) se mezclen dentro de la misma tanda.
        $servicio  = new ArticleEmbeddingServiceConTandaChica();
        $similitud = [];

        for ($n = 0; $n < 20; $n++) {
            $vector   = $aleatorio();
            $articulo = $this->articulo('Tanda ' . $n, $vector);

            if ($n % 2 === 1) {
                $this->compactar_a_mano($articulo->id);
            }

            $similitud[$articulo->id] = $this->coseno($consulta, $vector);
        }

        arsort($similitud);
        $esperado = array_slice(array_keys($similitud), 0, 5);

        $obtenido = $this->ids($servicio->search_similar_articles('x', (int) $this->comercio->id, 5));

        $this->assertEquals($esperado, $obtenido);

        // Y la tanda de producción da exactamente lo mismo.
        $this->assertEquals(
            $esperado,
            $this->ids((new ArticleEmbeddingService())->search_similar_articles('x', (int) $this->comercio->id, 5))
        );
    }

    /**
     * @group whatsapp
     * @test
     */
    public function el_comando_compacta_los_faltantes_y_no_toca_los_que_ya_estaban()
    {
        $faltante_1 = $this->articulo('Comando faltante 1', [1.0, 0.0, 0.0]);
        $faltante_2 = $this->articulo('Comando faltante 2', [0.0, 2.0, 0.0], ['status' => 'inactive']);
        $ya_estaba  = $this->articulo('Comando ya estaba', [1.0, 0.0, 0.0]);
        $ajeno      = $this->articulo('Comando ajeno', [1.0, 0.0, 0.0], ['user_id' => $this->otro_comercio->id]);

        // Compacto "centinela" distinto del JSON: si el comando lo pisara, se notaría.
        DB::table('article_compact_embeddings')->insert([
            'article_id' => $ya_estaba->id,
            'user_id'    => $this->comercio->id,
            'vector'     => pack('g*', 0.0, 0.0, 1.0),
        ]);

        $codigo = Artisan::call('articles:compactar-embeddings', ['--user_id' => $this->comercio->id]);

        $this->assertEquals(0, $codigo);
        $this->assertStringContainsString('2 artículo(s) compactado(s)', Artisan::output());

        $this->assertEqualsWithDelta(1.0, $this->compacto_de($faltante_1->id)[0], 1e-6);
        $this->assertEqualsWithDelta(1.0, $this->compacto_de($faltante_2->id)[1], 1e-6, 'El comando compacta aunque el artículo esté inactivo.');
        $this->assertEquals([0.0, 0.0, 1.0], $this->compacto_de($ya_estaba->id), 'Lo que ya estaba no se toca.');
        $this->assertNull($this->compacto_de($ajeno->id), 'Con --user_id no se toca lo de otro dueño.');

        // Sin --user_id recorre toda la base: ahora sí compacta el ajeno, con SU dueño.
        Artisan::call('articles:compactar-embeddings');

        $this->assertEquals(
            $this->otro_comercio->id,
            DB::table('article_compact_embeddings')->where('article_id', $ajeno->id)->value('user_id')
        );

        // Idempotente: una segunda corrida con --user_id no encuentra nada que hacer.
        Artisan::call('articles:compactar-embeddings', ['--user_id' => $this->comercio->id]);
        $this->assertStringContainsString('0 artículo(s) compactado(s)', Artisan::output());
    }
}
