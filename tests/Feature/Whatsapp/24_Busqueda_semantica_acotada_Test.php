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
 * - `persistir_embedding()` —el único escritor de vectores— mantiene el compacto sincronizado;
 * - el recorrido en varias tandas da el mismo top-K que la fuerza bruta;
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
    public function persistir_embedding_sincroniza_el_compacto_y_lo_borra_con_norma_cero()
    {
        $articulo = $this->articulo('Persistir', null, ['user_id' => $this->otro_comercio->id]);
        $servicio = new ArticleEmbeddingService();

        $servicio->persistir_embedding((int) $articulo->id, [0.0, 3.0, 4.0]);

        // Se compara decodificado: la columna es JSON y MySQL la re-serializa con espacios.
        $this->assertEquals(
            [0, 3, 4],
            json_decode(DB::table('articles')->where('id', $articulo->id)->value('embedding'), true),
            'El JSON completo sigue siendo la fuente de verdad.'
        );

        $fila = DB::table('article_compact_embeddings')->where('article_id', $articulo->id)->first();
        $this->assertNotNull($fila);
        $this->assertEquals($this->otro_comercio->id, $fila->user_id, 'El dueño se lee de articles, no se inventa.');

        $compacto = $this->compacto_de($articulo->id);
        $this->assertEqualsWithDelta(0.0, $compacto[0], 1e-6);
        $this->assertEqualsWithDelta(0.6, $compacto[1], 1e-6);
        $this->assertEqualsWithDelta(0.8, $compacto[2], 1e-6);

        // Re-indexar reemplaza el compacto (upsert), no agrega otra fila.
        $servicio->persistir_embedding((int) $articulo->id, [5.0, 0.0, 0.0]);
        $this->assertEquals(1, DB::table('article_compact_embeddings')->where('article_id', $articulo->id)->count());
        $this->assertEqualsWithDelta(1.0, $this->compacto_de($articulo->id)[0], 1e-6);

        // Norma cero: el compacto viejo se borra, para no rankear con un vector que ya no es el suyo.
        $servicio->persistir_embedding((int) $articulo->id, [0.0, 0.0, 0.0]);
        $this->assertNull($this->compacto_de($articulo->id));
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
            $articulo = $this->articulo('Tanda ' . $n, $n % 2 === 0 ? $vector : null);

            if ($n % 2 === 1) {
                $servicio->persistir_embedding((int) $articulo->id, $vector);
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
