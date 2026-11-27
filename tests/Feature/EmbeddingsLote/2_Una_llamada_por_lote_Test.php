<?php

namespace Tests\Feature\EmbeddingsLote;

use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\User;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use App\Services\EmbeddingsCredencialCorte;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Misión embeddings-lote-cola (CA3, CA4 y CA5) — UNA llamada a OpenAI por lote de artículos.
 *
 * Hoy un job = un artículo = una llamada (`'input' => $text`). Con el lote, `GenerateArticleEmbeddingsBatchJob` arma la lista de
 * textos de sus artículos y hace UN POST con `input` = lista; OpenAI devuelve `data[]` con un `index` por texto (no tiene por qué
 * venir en orden) y UN solo `usage.prompt_tokens` para todo el pedido, que se reparte entre los artículos para no perder el
 * "consumo por artículo" (`ai_token_usages.referencia_id`).
 *
 * 🔴 SIN OPENAI REAL: `Http::fake` sobre `api.openai.com/*` (como EmbeddingsCorteCredencialTest) y el freno de `tests/TestCase.php`
 * detecta cualquier pedido que no esté cubierto. Los jobs se corren a mano con `handle()` (resuelto por el contenedor, así el test no
 * depende de qué dependencias inyecta): lo que se mide es el contrato del lote, no la cola.
 *
 * Los vectores de prueba son de 3 dimensiones y distintos por artículo: si el job mezclara los `index`, el artículo recibiría el
 * vector de otro y se vería.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types ni enum.
 *
 * @group whatsapp
 */
class Una_llamada_por_lote_Test extends TestCase
{
    use DatabaseTransactions;

    /** Proceso con el que `ai_token_usages` imputa el gasto de indexar el catálogo. */
    const PROCESO = 'embeddings_articulos';

    /** @var User Comercio dueño de la instancia de prueba. */
    protected $comercio;

    /** @var TestHandler Captura lo que se loguea. */
    protected $log_handler;

    /**
     * Pausa apagada y clave de prueba; ninguna extensión (los observers no encolan nada al crear los artículos); logs capturados.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.embeddings_generacion_pausada' => false]);
        config(['services.openai.api_key' => 'clave-de-prueba']);
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);
        config(['cache.default' => 'array']);
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        ArticleObserver::resetear_cache_gate();
        $this->capturar_logs();

        $this->comercio = User::create([
            'name'         => 'Comercio una llamada por lote',
            'company_name' => 'Ferreteria una llamada por lote',
            'email'        => 'embeddings-lote-llamada-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * Deja limpio el memo estático del gate.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Redirige el canal `daily` a un handler en memoria para poder contar lo que se loguea.
     *
     * @return void
     */
    protected function capturar_logs()
    {
        $this->log_handler = new TestHandler();
        $handler = $this->log_handler;

        Log::extend('captura_test', function () use ($handler) {
            return new \Monolog\Logger('captura', [$handler]);
        });

        config([
            'logging.default'        => 'daily',
            'logging.channels.daily' => ['driver' => 'captura_test'],
        ]);

        Log::forgetChannel('daily');
        Log::forgetChannel('stack');
    }

    /**
     * Crea un artículo activo del comercio.
     *
     * @param string $nombre
     * @param array  $extra Pisa o completa los atributos.
     *
     * @return Article
     */
    protected function articulo($nombre, array $extra = [])
    {
        return Article::create(array_merge([
            'name'    => $nombre,
            'status'  => 'active',
            'user_id' => $this->comercio->id,
        ], $extra));
    }

    /**
     * Tanda abierta para el lote.
     *
     * @param int $total Artículos que la tanda espera.
     *
     * @return EmbeddingRun
     */
    protected function crear_tanda($total)
    {
        return EmbeddingRun::create([
            'user_id'    => $this->comercio->id,
            'origen'     => 'scheduler',
            'status'     => 'en_proceso',
            'total_jobs' => $total,
            'terminados' => 0,
            'generados'  => 0,
            'salteados'  => 0,
            'fallados'   => 0,
        ]);
    }

    /**
     * Texto que se vectoriza hoy para un artículo.
     *
     * @param int $article_id
     *
     * @return string
     */
    protected function texto_de($article_id)
    {
        $fresco = Article::with(['category', 'brand', 'descriptions'])->find($article_id);

        return (new ArticleEmbeddingService())->embedding_for_article($fresco);
    }

    /**
     * Marca como encolados a los artículos, como los deja el despachador antes de que corra el job.
     *
     * @param int[] $ids
     *
     * @return void
     */
    protected function marcar_como_encolados(array $ids)
    {
        DB::table('articles')->whereIn('id', $ids)->update(['embedding_queued_at' => now()]);
    }

    /**
     * Fábrica HTTP limpia con la respuesta indicada para OpenAI (los stubs de `Http::fake()` se acumulan y gana el primero).
     *
     * @param mixed $respuesta Lo que devuelve `Http::response()`, un callable o una secuencia.
     *
     * @return void
     */
    protected function openai_responde($respuesta)
    {
        Http::swap(new HttpFactorySinSalida(app('events')));

        Http::fake(['api.openai.com/*' => $respuesta]);
    }

    /**
     * Cuerpo de éxito con un vector por texto; `$indices` define el orden en que vienen en `data` (el real no está garantizado).
     *
     * @param int[]    $indices       Índices de entrada, en el orden en que se devuelven.
     * @param int|null $prompt_tokens `usage.prompt_tokens`, o null para omitir `usage`.
     *
     * @return array
     */
    protected function cuerpo_exito(array $indices, $prompt_tokens)
    {
        $data = [];

        foreach ($indices as $indice) {
            $data[] = ['object' => 'embedding', 'index' => $indice, 'embedding' => $this->vector_del_indice($indice)];
        }

        $cuerpo = ['object' => 'list', 'data' => $data, 'model' => 'text-embedding-3-small'];

        if (! is_null($prompt_tokens)) {
            $cuerpo['usage'] = ['prompt_tokens' => $prompt_tokens, 'total_tokens' => $prompt_tokens];
        }

        return $cuerpo;
    }

    /**
     * Vector de 3 dimensiones propio de cada índice de entrada (0.1/0.2/0.3 para el 0; 1.1/1.2/1.3 para el 1; ...).
     *
     * @param int $indice
     *
     * @return float[]
     */
    protected function vector_del_indice($indice)
    {
        return [$indice + 0.1, $indice + 0.2, $indice + 0.3];
    }

    /**
     * Corre el job de lote como lo hace el worker: el contenedor resuelve las dependencias de `handle()`.
     *
     * @param int[]    $ids
     * @param int|null $tanda_id
     *
     * @return GenerateArticleEmbeddingsBatchJob
     */
    protected function correr_lote(array $ids, $tanda_id)
    {
        $job = new GenerateArticleEmbeddingsBatchJob($ids, $tanda_id);

        app()->call([$job, 'handle']);

        return $job;
    }

    /**
     * Los pedidos que salieron hacia OpenAI, en orden.
     *
     * @return \Illuminate\Support\Collection de [Request, Response]
     */
    protected function pedidos()
    {
        return Http::recorded();
    }

    /**
     * Filas de consumo del proceso de embeddings de este comercio, ordenadas por artículo.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function filas_de_consumo()
    {
        return AiTokenUsage::where('user_id', $this->comercio->id)
            ->where('proceso', self::PROCESO)
            ->orderBy('referencia_id')
            ->get();
    }

    /**
     * Vector guardado de un artículo (JSON de la columna `embedding`).
     *
     * @param int $article_id
     *
     * @return array|null
     */
    protected function vector_de($article_id)
    {
        $crudo = DB::table('articles')->where('id', $article_id)->value('embedding');

        return is_null($crudo) ? null : json_decode($crudo, true);
    }

    /**
     * Reparto esperado de `$total` tokens entre textos de largos `$largos`: proporcional al largo, piso de cada cuota y el sobrante
     * de a 1 a las fracciones más altas (desempate por posición). La suma es EXACTA. (Aritmética entera: sin errores de redondeo.)
     *
     * @param int[] $largos
     * @param int   $total
     *
     * @return int[]
     */
    protected function reparto_esperado(array $largos, $total)
    {
        $suma   = array_sum($largos);
        $pisos  = [];
        $restos = [];

        foreach ($largos as $posicion => $largo) {
            $pisos[$posicion]  = intdiv($total * $largo, $suma);
            $restos[$posicion] = ($total * $largo) % $suma;
        }

        $sobrante = $total - array_sum($pisos);
        $posiciones = array_keys($largos);

        // Mayor resto primero; a igual resto, la posición más baja.
        usort($posiciones, function ($x, $y) use ($restos) {
            if ($restos[$x] === $restos[$y]) {
                return $x - $y;
            }

            return $restos[$y] - $restos[$x];
        });

        for ($i = 0; $i < $sobrante; $i++) {
            $pisos[$posiciones[$i]]++;
        }

        ksort($pisos);

        return array_values($pisos);
    }

    // ─────────────────────────────── CA3 ───────────────────────────────

    /**
     * CA3. Un lote de 3 artículos, con una respuesta cuyo `data` viene con `index` 2, 0, 1: UN solo pedido, `input` = lista de los
     * 3 textos en el orden de los ids, y cada artículo guarda SU vector (el de su `index`), `embedding_generated_at` y
     * `embedding_source_hash = sha1(texto)`. La tanda queda `generados=3, terminados=3` y las 3 marcas en null.
     *
     * @group whatsapp
     * @test
     */
    public function test_una_sola_llamada_y_cada_vector_a_su_articulo()
    {
        $a = $this->articulo('Taladro percutor 13 mm');
        $b = $this->articulo('Mecha para concreto 8 mm');
        $c = $this->articulo('Llave francesa 12 pulgadas');
        $ids = [(int) $a->id, (int) $b->id, (int) $c->id];
        $tanda = $this->crear_tanda(3);
        $this->marcar_como_encolados($ids);

        $this->openai_responde(Http::response($this->cuerpo_exito([2, 0, 1], 30), 200));

        $this->correr_lote($ids, (int) $tanda->id);

        Http::assertSentCount(1);

        $enviado = $this->pedidos()->first()[0];
        $this->assertSame('text-embedding-3-small', $enviado['model']);
        $this->assertSame(
            [$this->texto_de($ids[0]), $this->texto_de($ids[1]), $this->texto_de($ids[2])],
            $enviado['input'],
            '`input` es la LISTA de textos, en el orden de los ids del lote.'
        );

        // El artículo i recibe el vector de SU index (la respuesta vino desordenada: 2, 0, 1).
        $this->assertEquals($this->vector_del_indice(0), $this->vector_de($ids[0]), 'El primer artículo recibe el vector del index 0.');
        $this->assertEquals($this->vector_del_indice(1), $this->vector_de($ids[1]), 'El segundo artículo recibe el vector del index 1.');
        $this->assertEquals($this->vector_del_indice(2), $this->vector_de($ids[2]), 'El tercero recibe el vector del index 2.');

        foreach ($ids as $id) {
            $fila = DB::table('articles')->where('id', $id)->first();
            $this->assertNotNull($fila->embedding_generated_at, 'Queda sellado como generado.');
            $this->assertSame(sha1($this->texto_de($id)), $fila->embedding_source_hash, 'Queda la huella del texto vectorizado.');
            $this->assertNull($fila->embedding_queued_at, 'La marca se libera al empezar el job.');
        }

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(3, (int) $tanda->generados);
        $this->assertSame(3, (int) $tanda->terminados);
        $this->assertSame(0, (int) $tanda->salteados);
        $this->assertSame(0, (int) $tanda->fallados);
    }

    // ─────────────────────────────── CA4 ───────────────────────────────

    /**
     * CA4 (reparto). Con `usage.prompt_tokens = 100` para 3 textos de largos distintos: 3 filas de `ai_token_usages`
     * (`proveedor=openai`, `proceso=embeddings_articulos`, `referencia_id` = cada artículo) cuya suma de `input_tokens` es EXACTAMENTE
     * 100 (OpenAI cobró 100), repartida proporcional al largo del texto por resto mayor; el texto más largo recibe ≥ que el más corto.
     *
     * @group whatsapp
     * @test
     */
    public function test_el_consumo_se_reparte_exacto_por_articulo()
    {
        $corto = $this->articulo('Clavo');
        $medio = $this->articulo('Martillo carpintero mango de madera');
        $largo = $this->articulo('Sierra circular de banco profesional con disco de widia de 250 milímetros y guía paralela');
        $ids   = [(int) $corto->id, (int) $medio->id, (int) $largo->id];
        $this->marcar_como_encolados($ids);

        $this->openai_responde(Http::response($this->cuerpo_exito([0, 1, 2], 100), 200));

        $this->correr_lote($ids, null);

        Http::assertSentCount(1);

        $filas = $this->filas_de_consumo();
        $this->assertCount(3, $filas, 'Una fila de consumo POR ARTÍCULO (no una por llamada).');
        $this->assertSame($ids, $filas->pluck('referencia_id')->map(function ($id) {
            return (int) $id;
        })->all(), 'Cada fila lleva como referencia a su artículo.');

        foreach ($filas as $fila) {
            $this->assertSame('openai', $fila->proveedor);
            $this->assertSame(self::PROCESO, $fila->proceso);
        }

        $tokens = $filas->pluck('input_tokens')->map(function ($t) {
            return (int) $t;
        })->all();

        $this->assertSame(100, array_sum($tokens), 'La suma es EXACTA al total cobrado por OpenAI.');
        $this->assertGreaterThanOrEqual($tokens[0], $tokens[2], 'El texto más largo recibe al menos lo que recibe el más corto.');

        $largos = [];
        foreach ($ids as $id) {
            $largos[] = mb_strlen($this->texto_de($id));
        }
        $this->assertSame(
            $this->reparto_esperado($largos, 100),
            $tokens,
            'Reparto proporcional al largo del texto, por resto mayor (piso de cada cuota y el sobrante a las fracciones más altas).'
        );
    }

    /**
     * CA4 (lote de uno). Un lote de 1 artículo con `prompt_tokens = 7` deja UNA fila con 7: idéntico a hoy (una fila con todo el
     * consumo).
     *
     * @group whatsapp
     * @test
     */
    public function test_lote_de_uno_igual_que_antes()
    {
        $articulo = $this->articulo('Cinta métrica 5 metros');
        $this->marcar_como_encolados([(int) $articulo->id]);

        $this->openai_responde(Http::response($this->cuerpo_exito([0], 7), 200));

        $this->correr_lote([(int) $articulo->id], null);

        Http::assertSentCount(1);
        $filas = $this->filas_de_consumo();
        $this->assertCount(1, $filas);
        $this->assertSame((int) $articulo->id, (int) $filas->first()->referencia_id);
        $this->assertSame(7, (int) $filas->first()->input_tokens, 'Con un solo artículo la fila lleva todo el consumo.');
    }

    /**
     * CA4 (sin usage). Si OpenAI responde sin `usage.prompt_tokens`: una fila por artículo en cero y UN solo warning por lote
     * (no uno por artículo, que ensuciaría el log de un catálogo de miles).
     *
     * @group whatsapp
     * @test
     */
    public function test_sin_prompt_tokens_un_solo_aviso()
    {
        $a = $this->articulo('Pegamento de contacto');
        $b = $this->articulo('Pincel de cerda 2 pulgadas');
        $c = $this->articulo('Rodillo de lana 22 cm');
        $ids = [(int) $a->id, (int) $b->id, (int) $c->id];
        $this->marcar_como_encolados($ids);

        $this->openai_responde(Http::response($this->cuerpo_exito([0, 1, 2], null), 200));

        $this->correr_lote($ids, null);

        $filas = $this->filas_de_consumo();
        $this->assertCount(3, $filas, 'Se registran las tres filas igual.');
        foreach ($filas as $fila) {
            $this->assertSame(0, (int) $fila->input_tokens, 'Sin usage, el consumo se registra en cero.');
        }

        $avisos = 0;
        foreach ($this->log_handler->getRecords() as $registro) {
            if ($registro['level_name'] === 'WARNING' && strpos($registro['message'], 'prompt_tokens') !== false) {
                $avisos++;
            }
        }
        $this->assertSame(1, $avisos, 'Un solo aviso por lote por la falta de usage.prompt_tokens.');
    }

    // ─────────────────────────────── CA5 ───────────────────────────────

    /**
     * CA5. De 3 artículos, uno ya tiene vector y huella al día: ese NO viaja a OpenAI (el `input` lleva 2 textos), la tanda queda
     * `generados=2, salteados=1, terminados=3` y hay 2 filas de consumo (no se paga lo que no se pidió).
     *
     * @group whatsapp
     * @test
     */
    public function test_el_corte_por_huella_no_viaja_a_openai()
    {
        $al_dia = $this->articulo('Destornillador Phillips');
        $b      = $this->articulo('Pinza universal');
        $c      = $this->articulo('Alicate de corte');
        $ids    = [(int) $al_dia->id, (int) $b->id, (int) $c->id];

        DB::table('articles')->where('id', $al_dia->id)->update([
            'embedding'              => json_encode([9.0, 9.0, 9.0]),
            'embedding_source_hash'  => sha1($this->texto_de($al_dia->id)),
            'embedding_generated_at' => now()->subDay(),
        ]);

        $tanda = $this->crear_tanda(3);
        $this->marcar_como_encolados($ids);

        $this->openai_responde(Http::response($this->cuerpo_exito([0, 1], 20), 200));

        $this->correr_lote($ids, (int) $tanda->id);

        Http::assertSentCount(1);
        $enviado = $this->pedidos()->first()[0];
        $this->assertSame(
            [$this->texto_de($b->id), $this->texto_de($c->id)],
            $enviado['input'],
            'El artículo al día no viaja: el `input` lleva sólo los otros dos textos.'
        );

        $this->assertEquals([9.0, 9.0, 9.0], $this->vector_de($al_dia->id), 'El vector del artículo al día no se pisa.');
        $this->assertEquals($this->vector_del_indice(0), $this->vector_de($b->id));
        $this->assertEquals($this->vector_del_indice(1), $this->vector_de($c->id));
        $this->assertNull(DB::table('articles')->where('id', $al_dia->id)->value('embedding_queued_at'), 'Su marca también se libera.');
        $this->assertTrue(
            strtotime(DB::table('articles')->where('id', $al_dia->id)->value('embedding_generated_at')) >= strtotime(now()->subMinute()),
            'Al artículo al día se le refresca embedding_generated_at (para que el comando deje de verlo).'
        );

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(2, (int) $tanda->generados);
        $this->assertSame(1, (int) $tanda->salteados);
        $this->assertSame(0, (int) $tanda->fallados);
        $this->assertSame(3, (int) $tanda->terminados);

        $filas = $this->filas_de_consumo();
        $this->assertCount(2, $filas, 'Sólo se registra consumo de lo que se mandó a OpenAI.');
        $this->assertSame(20, (int) $filas->sum('input_tokens'));
    }
}
