<?php

namespace Tests\Feature\ConsumoIa;

use App\Http\Controllers\Helpers\import\article\AiExcelAnalyzer;
use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Http\Controllers\Helpers\import\provider\AiProviderAnalyzer;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\User;
use App\Services\ArticleDescriptionAiService;
use App\Services\ArticleEmbeddingService;
use App\Services\ArticleImageValidationService;
use App\Services\LogoPaletteAiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión tokens-por-cliente — §1.4, puntos 1, 2 y 3: los siete puntos de llamada que hasta
 * ahora gastaban sin dejar rastro registran su fila, con los tokens que vinieron en el
 * `usage`; los embeddings además traducen bien el formato de OpenAI; y una llamada que FALLA
 * no registra nada.
 *
 * 🔴 Lo que se está probando de verdad es el LUGAR donde quedó cada `registrar()`. El `usage`
 * vive un instante: en cuanto el método se queda con el texto (o con el vector) el bloque deja
 * de existir, así que un registro puesto dos líneas más abajo grabaría ceros — una fila que
 * existe, que no rompe nada, y que dice que el gasto fue cero. Por eso cada aserción mira los
 * números concretos y no solo que haya fila.
 *
 * 🔴 Ninguna de estas pruebas sale a la red: todas las llamadas están interceptadas con
 * Http::fake(), y las claves se pisan con valores de prueba.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Puntos_instrumentados_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User El comercio dueño del gasto. */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        // 🔴 Nunca las claves reales del .env.testing.
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        config(['services.openai.api_key'    => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'     => 'Comercio del metering',
            'email'    => 'consumo-ia-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);
    }

    /**
     * Las filas de consumo de este comercio, en orden de creación.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    protected function filas()
    {
        return AiTokenUsage::where('user_id', $this->comercio->id)->orderBy('id')->get();
    }

    /**
     * Un artículo del comercio.
     *
     * @param  string  $nombre
     * @return Article
     */
    protected function articulo($nombre = 'Pintura para pared con humedad')
    {
        return Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Respuesta típica de la API de Anthropic, con su bloque `usage` completo.
     *
     * @param  string  $texto
     * @return array
     */
    protected function respuesta_anthropic($texto)
    {
        return [
            'model'   => 'claude-modelo-de-prueba-20260101',
            'content' => [['type' => 'text', 'text' => $texto]],
            'usage'   => [
                'input_tokens'                => 1200,
                'output_tokens'               => 80,
                'cache_creation_input_tokens' => 14,
                'cache_read_input_tokens'     => 7,
            ],
        ];
    }

    /**
     * Un PNG chiquito real, para los dos servicios que mandan imágenes.
     *
     * @return string
     */
    protected function png()
    {
        $imagen = imagecreatetruecolor(24, 24);
        imagefill($imagen, 0, 0, imagecolorallocate($imagen, 12, 90, 190));

        ob_start();
        imagepng($imagen);

        return (string) ob_get_clean();
    }

    /**
     * Invoca un método protected vía reflexión.
     *
     * Los `call_claude()` de los tres analizadores son protected A PROPÓSITO y no se les toca
     * la visibilidad para poder testearlos — es el mismo criterio (y el mismo comentario) que
     * ya usa tests/Import/AnalyzerHojaYEncabezadoTest.php. Llegar hasta ellos por `analyze()`
     * obligaría a un Excel de fixture por cada uno, que no prueba nada de lo que esta misión
     * agregó: la línea instrumentada está adentro de `call_claude()`, y que `analyze()` llega
     * hasta ahí ya lo cubre la suite de importación.
     *
     * @param  object  $objeto
     * @param  string  $metodo
     * @param  array   $argumentos
     * @return mixed
     */
    protected function invocar($objeto, $metodo, array $argumentos = [])
    {
        $reflexion = new \ReflectionMethod(get_class($objeto), $metodo);
        $reflexion->setAccessible(true);

        return $reflexion->invokeArgs($objeto, $argumentos);
    }

    /**
     * 1. Indexar el catálogo: `embeddings_articulos`, proveedor OpenAI, y la traducción del
     *    `usage` — que es lo único delicado de todo el punto.
     *
     * @group consumo-ia
     * @test
     */
    public function indexar_un_articulo_registra_el_consumo_con_el_usage_de_openai_traducido()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [0.11, 0.22, 0.33]]],
                'usage' => ['prompt_tokens' => 137, 'total_tokens' => 137],
            ], 200),
        ]);

        $article = $this->articulo();

        (new ArticleEmbeddingService())->update_article_embedding($article);

        $filas = $this->filas();
        $this->assertCount(1, $filas, 'Una llamada a OpenAI tiene que dejar exactamente una fila.');

        $fila = $filas[0];
        $this->assertEquals('embeddings_articulos', $fila->proceso);
        $this->assertEquals('openai', $fila->proveedor, 'El proveedor no se adivina por el nombre del modelo: se guarda.');
        $this->assertEquals('text-embedding-3-small', $fila->modelo);
        $this->assertEquals((int) $article->id, (int) $fila->referencia_id);
        $this->assertNull($fila->auth_user_id, 'Lo dispara el scheduler, no una persona.');

        // 🔴 La traducción: prompt_tokens cae en input_tokens y NADA MÁS se llena. Si alguien
        // mapeara total_tokens acá también, el mismo gasto se contaría dos veces.
        $this->assertEquals(137, (int) $fila->input_tokens);
        $this->assertEquals(0, (int) $fila->output_tokens, 'Un embedding no genera texto: no hay salida que cobrar.');
        $this->assertEquals(0, (int) $fila->cache_creation_input_tokens);
        $this->assertEquals(0, (int) $fila->cache_read_input_tokens);
    }

    /**
     * 2. La búsqueda semántica del RAG va con OTRO proceso. Es el gasto que corre en cada
     *    respuesta de WhatsApp, y mezclarlo con el de indexar escondería justo eso.
     *
     * @group consumo-ia
     * @test
     */
    public function la_busqueda_semantica_registra_como_embeddings_busqueda_y_no_como_indexacion()
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'model' => 'text-embedding-3-small',
                'data'  => [['embedding' => [0.5, 0.6, 0.7]]],
                'usage' => ['prompt_tokens' => 19, 'total_tokens' => 19],
            ], 200),
        ]);

        (new ArticleEmbeddingService())->search_similar_articles(
            'algo para pintar una pared con humedad',
            (int) $this->comercio->id,
            3
        );

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals('embeddings_busqueda', $fila->proceso, 'Buscar y indexar son gastos distintos y no se mezclan.');
        $this->assertEquals('openai', $fila->proveedor);
        $this->assertEquals(19, (int) $fila->input_tokens);
        $this->assertNull($fila->referencia_id, 'La búsqueda no es de ningún artículo en particular.');
    }

    /**
     * 3. Una llamada que falla NO registra nada: no hubo consumo que imputar.
     *
     * @group consumo-ia
     * @test
     */
    public function una_llamada_que_falla_no_registra_ninguna_fila()
    {
        Http::fake([
            'api.openai.com/*'    => Http::response(['error' => ['message' => 'rate limit']], 429),
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529),
        ]);

        $article = $this->articulo();

        /* OpenAI: generate_embedding lanza, y la fila no se escribe. */
        $exploto = false;

        try {
            (new ArticleEmbeddingService())->update_article_embedding($article);
        } catch (\RuntimeException $exception) {
            $exploto = true;
        }

        $this->assertTrue($exploto, 'Un 429 de OpenAI tiene que seguir lanzando como antes.');

        /* Anthropic: la descripción degrada a not_found y tampoco registra. */
        $resultado = (new ArticleDescriptionAiService())->generate($article, $this->lookup());

        $this->assertFalse($resultado['found']);

        $this->assertCount(
            0,
            $this->filas(),
            'Una llamada rechazada no se paga: registrarla inflaría el consumo del cliente con gasto que no existió.'
        );
    }

    /**
     * 4. Los tres analizadores de Excel, cada uno con su proceso.
     *
     * @group consumo-ia
     * @test
     */
    public function los_tres_analizadores_de_excel_registran_su_propio_proceso()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_anthropic(json_encode([
                'politica_colision'      => 'actualizar_todos',
                'politica_intra_archivo' => 'ultima_gana',
                'explicacion'            => 'Prueba del metering.',
            ])), 200),
        ]);

        /* Artículos: por el camino público que ya existe (ask_claude_for_recomendation). */
        (new AiExcelAnalyzer((int) $this->comercio->id))->ask_claude_for_recomendation([
            'total_filas_datos'                           => 10,
            'bar_codes_duplicados_intra_archivo'          => 0,
            'provider_codes_duplicados_intra_archivo'     => 0,
            'provider_codes_existentes_mismo_proveedor'   => 0,
            'provider_codes_existentes_otros_proveedores' => 0,
        ]);

        /* Clientes y proveedores: a call_claude() derecho (ver invocar()). */
        $this->invocar(new AiClientAnalyzer((int) $this->comercio->id), 'call_claude', ['prompt de prueba']);
        $this->invocar(new AiProviderAnalyzer((int) $this->comercio->id), 'call_claude', ['prompt de prueba']);

        $procesos = $this->filas()->pluck('proceso')->sort()->values()->all();

        $this->assertEquals(
            ['import_excel_articulos', 'import_excel_clientes', 'import_excel_proveedores'],
            $procesos
        );

        foreach ($this->filas() as $fila) {
            $this->assertEquals('anthropic', $fila->proveedor);
            $this->assertEquals('claude-modelo-de-prueba-20260101', $fila->modelo, 'Se guarda el modelo que resolvió Anthropic, no el alias de la constante.');
            $this->assertEquals(1200, (int) $fila->input_tokens);
            $this->assertEquals(80, (int) $fila->output_tokens);
            $this->assertEquals(14, (int) $fila->cache_creation_input_tokens);
            $this->assertEquals(7, (int) $fila->cache_read_input_tokens);
        }
    }

    /**
     * 5. La validación por visión de las imágenes de artículos. El dueño lo pasa el job.
     *
     * @group consumo-ia
     * @test
     */
    public function la_validacion_de_imagen_registra_el_consumo_con_el_dueno_que_le_pasa_el_job()
    {
        config(['services.article_image_validation.enabled'         => true]);
        config(['services.article_image_validation.max_calls_batch' => 0]);
        config(['services.article_image_validation.model'           => 'claude-haiku-de-prueba']);
        config(['services.article_image_validation.max_side'        => 512]);
        config(['services.article_image_validation.timeout'         => 25]);

        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_anthropic(json_encode([
                'es_el_producto' => true,
                'tipo'           => 'producto',
                'confianza'      => 'high',
                'motivo'         => 'Prueba del metering.',
            ])), 200),
        ]);

        $article = $this->articulo();

        $resultado = (new ArticleImageValidationService())->validate(
            $this->png(),
            $article,
            (int) $this->comercio->id
        );

        $this->assertTrue(
            $resultado['evaluated'],
            'Si la imagen no llegó a evaluarse, la llamada a Anthropic nunca salió y este test no está probando nada.'
        );

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals('validacion_imagen_articulo', $fila->proceso);
        $this->assertEquals('anthropic', $fila->proveedor);
        $this->assertEquals((int) $article->id, (int) $fila->referencia_id);
        $this->assertEquals(1200, (int) $fila->input_tokens);
        $this->assertEquals(80, (int) $fila->output_tokens);
    }

    /**
     * 6. La descripción de tienda generada con IA.
     *
     * @group consumo-ia
     * @test
     */
    public function la_descripcion_de_articulo_registra_el_consumo()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_anthropic(json_encode([
                'found'      => true,
                'confidence' => 'medium',
                'sections'   => [['title' => 'Uso', 'content' => 'Para paredes con humedad.']],
            ])), 200),
        ]);

        $article = $this->articulo();

        (new ArticleDescriptionAiService())->generate($article, $this->lookup());

        $filas = $this->filas();
        $this->assertCount(1, $filas);

        $fila = $filas[0];
        $this->assertEquals('descripcion_articulo', $fila->proceso);
        $this->assertEquals('anthropic', $fila->proveedor);
        $this->assertEquals((int) $article->id, (int) $fila->referencia_id);
        $this->assertEquals(1200, (int) $fila->input_tokens);
    }

    /**
     * 7. La paleta de colores sacada del logo.
     *
     * @group consumo-ia
     * @test
     */
    public function la_paleta_del_logo_registra_el_consumo()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respuesta_anthropic(json_encode([
                'logo_readable' => true,
                'palettes'      => [],
            ])), 200),
        ]);

        /* Un logo real en disco: sin archivo, resolve_logo() corta antes de llamar a la IA. */
        $ruta = storage_path('app/logo-metering-' . uniqid() . '.png');
        file_put_contents($ruta, $this->png());

        $this->comercio->image_url = $ruta;
        $this->comercio->save();

        (new LogoPaletteAiService())->generate((int) $this->comercio->id);

        @unlink($ruta);

        $filas = $this->filas();
        $this->assertCount(1, $filas, 'Si no hay fila, la llamada no salió: revisá que el logo se haya resuelto.');

        $fila = $filas[0];
        $this->assertEquals('paleta_logo', $fila->proceso);
        $this->assertEquals('anthropic', $fila->proveedor);
        $this->assertEquals(1200, (int) $fila->input_tokens);
        $this->assertNull($fila->referencia_id, 'La paleta es del comercio, no de ningún registro puntual.');
    }

    /**
     * La evidencia mínima que ArticleDescriptionAiService espera de ProductInfoLookupService.
     *
     * @return array
     */
    protected function lookup()
    {
        return [
            'found'          => true,
            'source'         => 'google',
            'query'          => 'pintura para pared con humedad',
            'max_confidence' => 'medium',
            'structured'     => [],
            'evidence'       => [
                [
                    'title'   => 'Pintura antihumedad',
                    'snippet' => 'Pintura para interiores con problemas de humedad.',
                    'url'     => 'https://ejemplo.test/pintura',
                ],
            ],
        ];
    }
}
