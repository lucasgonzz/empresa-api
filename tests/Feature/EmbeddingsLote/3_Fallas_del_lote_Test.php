<?php

namespace Tests\Feature\EmbeddingsLote;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Jobs\FinalizeEmbeddingRun;
use App\Jobs\GenerateArticleEmbeddingsBatchJob;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\EmbeddingRun;
use App\Models\PruebaConfig;
use App\Observers\ArticleObserver;
use App\Services\ArticleEmbeddingService;
use App\Services\EmbeddingsCredencialCorte;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;
use Tests\Fakes\HttpFactorySinSalida;

/**
 * Misión embeddings-lote-cola (CA6, CA7, CA8, CA9 y CA15) — qué pasa cuando el lote sale mal.
 *
 * El lote junta hasta 100 artículos en un solo pedido, así que cada falla tiene que resolverse SIN castigar de más:
 *  - transitoria (500, 429 de rate limit, timeout): el job lanza, la tanda NO cuenta nada, los candidatos quedan marcados y el
 *    reintento corre el lote entero (se cuenta UNA sola vez al final);
 *  - un texto inválido (400): se aísla, cada candidato se reintenta solo dentro del mismo job y sólo el malo queda `fallados`;
 *  - credencial rechazada: corta el lote, abre el corte y no se vuelve a llamar a OpenAI;
 *  - cupo de la prueba gratuita: reserva por TEXTO (8192 tokens cada uno) y concilia con lo cobrado;
 *  - el vencimiento de la tanda crece con su tamaño (22.700 artículos no pueden vencer a los 20 minutos).
 *
 * 🔴 SIN OPENAI REAL: `Http::fake` (la fábrica se reemplaza por `HttpFactorySinSalida` en cada escenario: los stubs de `Http::fake()` se
 * acumulan y gana el primero). Los jobs se corren a mano con `handle()` resuelto por el contenedor; sin cola ni worker.
 * Las excepciones que se esperan se capturan con try/catch porque el código bajo prueba las tiene que dejar subir (o no).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types ni enum.
 *
 * @group whatsapp
 */
class Fallas_del_lote_Test extends EmpresaTestCase
{
    /** Proceso con el que `ai_token_usages` imputa el gasto de indexar el catálogo. */
    const PROCESO = 'embeddings_articulos';

    /** @var \App\Models\User Dueño de la instancia (el usuario autenticado de EmpresaTestCase). */
    protected $dueno;

    /**
     * Pausa apagada, clave de prueba, corte cerrado y el cache del corte en memoria.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = auth()->user();

        config(['services.openai.embeddings_generacion_pausada' => false]);
        config(['services.openai.api_key' => 'clave-de-prueba']);
        config(['services.anthropic.api_key' => null]);
        config(['broadcasting.default' => 'null']);
        config(['cache.default' => 'array']);
        Cache::forget(EmbeddingsCredencialCorte::CLAVE);

        // Sin extensión del lado de los observers: crear artículos no encola nada.
        ArticleObserver::resetear_cache_gate();
    }

    /**
     * Limpia el reloj, el memo de la prueba y el del gate.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PruebaHelper::olvidar_cache();
        ArticleObserver::resetear_cache_gate();

        parent::tearDown();
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /**
     * Crea artículos activos del dueño y los deja marcados como encolados (como los deja el despachador).
     *
     * @param int    $cantidad
     * @param string $prefijo
     *
     * @return int[]
     */
    protected function crear_lote($cantidad, $prefijo = 'Falla')
    {
        $ids = [];

        for ($i = 1; $i <= $cantidad; $i++) {
            $articulo = Article::create([
                'name'    => $prefijo . ' ' . uniqid() . ' ' . $i,
                'status'  => 'active',
                'user_id' => $this->dueno->id,
            ]);

            $ids[] = (int) $articulo->id;
        }

        DB::table('articles')->whereIn('id', $ids)->update(['embedding_queued_at' => now()]);

        return $ids;
    }

    /**
     * Tanda abierta del dueño.
     *
     * @param int    $total  `total_jobs`: cuenta ARTÍCULOS.
     * @param array  $extra  Pisa o completa los atributos.
     *
     * @return EmbeddingRun
     */
    protected function crear_tanda($total, array $extra = [])
    {
        return EmbeddingRun::create(array_merge([
            'user_id'    => $this->dueno->id,
            'origen'     => 'scheduler',
            'status'     => 'en_proceso',
            'total_jobs' => $total,
            'terminados' => 0,
            'generados'  => 0,
            'salteados'  => 0,
            'fallados'   => 0,
        ], $extra));
    }

    /**
     * Retrasa el `created_at` de la tanda (el reloj del vencimiento) con el query builder.
     *
     * @param EmbeddingRun $tanda
     * @param int          $minutos
     *
     * @return EmbeddingRun La tanda releída.
     */
    protected function envejecer_tanda(EmbeddingRun $tanda, $minutos)
    {
        DB::table('embedding_runs')->where('id', $tanda->id)->update(['created_at' => Carbon::now()->subMinutes($minutos)]);

        return EmbeddingRun::find($tanda->id);
    }

    /**
     * Fábrica HTTP limpia con la respuesta indicada para OpenAI.
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
     * Cuerpo de error de OpenAI (la forma que lee el servicio: `error.message`).
     *
     * @param string      $mensaje
     * @param string|null $codigo
     * @param string      $tipo
     *
     * @return array
     */
    protected function cuerpo_error($mensaje, $codigo = null, $tipo = 'invalid_request_error')
    {
        return ['error' => ['message' => $mensaje, 'type' => $tipo, 'code' => $codigo]];
    }

    /**
     * Cuerpo de éxito con un vector de 3 dimensiones por índice de entrada.
     *
     * @param int $cantidad       Cuántos textos se vectorizaron.
     * @param int $prompt_tokens  `usage.prompt_tokens`.
     *
     * @return array
     */
    protected function cuerpo_exito($cantidad, $prompt_tokens = 10)
    {
        $data = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $data[] = ['object' => 'embedding', 'index' => $i, 'embedding' => [$i + 0.5, 0.25, 0.125]];
        }

        return ['object' => 'list', 'data' => $data, 'usage' => ['prompt_tokens' => $prompt_tokens, 'total_tokens' => $prompt_tokens]];
    }

    /**
     * Corre el `handle()` del lote como lo hace el worker (el contenedor resuelve las dependencias).
     *
     * @param GenerateArticleEmbeddingsBatchJob $job
     *
     * @return \Throwable|null Lo que lanzó, o null si terminó sin lanzar.
     */
    protected function correr(GenerateArticleEmbeddingsBatchJob $job)
    {
        try {
            app()->call([$job, 'handle']);
        } catch (\Throwable $excepcion) {
            return $excepcion;
        }

        return null;
    }

    /**
     * Cantidad de artículos de la lista que siguen marcados como encolados.
     *
     * @param int[] $ids
     *
     * @return int
     */
    protected function marcados($ids)
    {
        return DB::table('articles')->whereIn('id', $ids)->whereNotNull('embedding_queued_at')->count();
    }

    /**
     * Filas de consumo del proceso de embeddings de esos artículos.
     *
     * @param int[] $ids
     *
     * @return int
     */
    protected function filas_de_consumo($ids)
    {
        return AiTokenUsage::where('proceso', self::PROCESO)->whereIn('referencia_id', $ids)->count();
    }

    /**
     * Prueba gratuita vigente del dueño, con el contador de tokens del cupo en el valor indicado.
     *
     * @param int $tokens_usados `embeddings_tokens_usados` de arranque.
     *
     * @return PruebaConfig
     */
    protected function activar_prueba($tokens_usados)
    {
        config(['app.FOR_USER' => 'prueba']);
        PruebaHelper::olvidar_cache();

        $prueba = PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7]);
        $prueba->created_at = now()->subDay();
        $prueba->embeddings_tokens_usados = $tokens_usados;
        $prueba->save();

        return $prueba;
    }

    /**
     * Tokens del cupo de la prueba consumidos (o reservados) hasta ahora.
     *
     * @param PruebaConfig $prueba
     *
     * @return int
     */
    protected function tokens_usados(PruebaConfig $prueba)
    {
        return (int) PruebaConfig::whereKey($prueba->id)->value('embeddings_tokens_usados');
    }

    // ─────────────────────────────── CA6 ───────────────────────────────

    /**
     * CA6 (transitoria). Con 500 (y con 429 de rate limit) en el primer intento el job LANZA, la tanda NO cambia, los candidatos
     * quedan marcados como encolados y no se registra consumo; el segundo intento, con 200, deja `generados=3, terminados=3`
     * (contado una sola vez: el reintento del lote entero no suma dos veces).
     *
     * @group whatsapp
     * @test
     */
    public function test_falla_transitoria_reintenta_sin_contar_dos_veces()
    {
        $escenarios = [
            '500'                 => [500, $this->cuerpo_error('The server had an error while processing your request', null, 'server_error')],
            '429 de rate limit'   => [429, $this->cuerpo_error('Rate limit reached for text-embedding-3-small', 'rate_limit_exceeded', 'requests')],
        ];

        foreach ($escenarios as $nombre => $escenario) {
            list($status, $cuerpo) = $escenario;

            $ids   = $this->crear_lote(3, 'Transitoria ' . $status);
            $tanda = $this->crear_tanda(3);
            $job   = new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id);

            $this->openai_responde(Http::sequence()
                ->push($cuerpo, $status)
                ->push($this->cuerpo_exito(3), 200));

            $lanzo = $this->correr($job);

            $this->assertNotNull($lanzo, $nombre . ': el primer intento lanza para que la cola lo reintente.');

            $tanda_1 = EmbeddingRun::find($tanda->id);
            $this->assertSame(0, (int) $tanda_1->terminados, $nombre . ': un intento fallido no cuenta nada en la tanda.');
            $this->assertSame(0, (int) $tanda_1->generados, $nombre . ': generados sigue en 0.');
            $this->assertSame(0, (int) $tanda_1->fallados, $nombre . ': fallados sigue en 0 (todavía quedan intentos).');
            $this->assertSame(3, $this->marcados($ids), $nombre . ': los candidatos quedan marcados (hay un reintento pendiente).');
            $this->assertSame(0, $this->filas_de_consumo($ids), $nombre . ': OpenAI no cobra un pedido fallido: sin filas de consumo.');
            $this->assertFalse(Cache::has(EmbeddingsCredencialCorte::CLAVE), $nombre . ': un error transitorio NO abre el corte de credencial.');

            // Segundo intento: sale bien.
            $lanzo = $this->correr($job);

            $this->assertNull($lanzo, $nombre . ': el segundo intento termina sin lanzar.');

            $tanda_2 = EmbeddingRun::find($tanda->id);
            $this->assertSame(3, (int) $tanda_2->generados, $nombre . ': generados = 3.');
            $this->assertSame(3, (int) $tanda_2->terminados, $nombre . ': terminados = 3 (contado una sola vez).');
            $this->assertSame(0, (int) $tanda_2->fallados);
            $this->assertSame(0, $this->marcados($ids), $nombre . ': con el lote hecho no queda ninguna marca.');
            $this->assertSame(3, $this->filas_de_consumo($ids), $nombre . ': ahora sí, una fila de consumo por artículo.');
        }
    }

    /**
     * CA6 (failed). Cuando el lote muere definitivamente (agotó los 3 intentos) `failed()` libera las 3 marcas y cuenta
     * `fallados = 3, terminados = 3`.
     *
     * @group whatsapp
     * @test
     */
    public function test_failed_libera_y_cuenta()
    {
        $ids   = $this->crear_lote(3, 'Failed');
        $tanda = $this->crear_tanda(3);
        $job   = new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id);

        $this->assertSame(3, $this->marcados($ids), 'Precondición: los tres están marcados.');

        $job->failed(new \RuntimeException('agotó los intentos (de prueba)'));

        $this->assertSame(0, $this->marcados($ids), 'failed() libera todas las marcas para que el comando pueda volver a encolarlos.');

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(3, (int) $tanda->fallados);
        $this->assertSame(3, (int) $tanda->terminados);
        $this->assertSame(0, (int) $tanda->generados);
    }

    // ─────────────────────────────── CA7 ───────────────────────────────

    /**
     * CA7. Un texto inválido no tira al resto: el pedido del lote recibe 400 (`invalid_request_error`) y, por separado, los tres
     * candidatos se reintentan de a uno (200, 400, 200). El job NO lanza; `generados=2, fallados=1, terminados=3`; el fallado queda
     * sin marca y sin huella nueva (el comando lo reintenta en la pasada siguiente); 4 pedidos en total.
     *
     * @group whatsapp
     * @test
     */
    public function test_un_texto_invalido_no_tira_al_resto()
    {
        list($a, $b, $c) = $ids = $this->crear_lote(3, 'Aislado');
        $tanda = $this->crear_tanda(3);

        $this->openai_responde(Http::sequence()
            ->push($this->cuerpo_error("Invalid 'input[1]': string too long.", null, 'invalid_request_error'), 400)
            ->push($this->cuerpo_exito(1, 11), 200)
            ->push($this->cuerpo_error("Invalid 'input[0]': string too long.", null, 'invalid_request_error'), 400)
            ->push($this->cuerpo_exito(1, 13), 200));

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNull($lanzo, 'Un 400 por un texto no es transitorio: el job no lanza (reintentar el lote entero daría lo mismo).');

        Http::assertSentCount(4);

        $pedidos = Http::recorded()->values();
        $this->assertCount(3, (array) $pedidos[0][0]['input'], 'El primer pedido es el lote entero.');
        foreach ([1, 2, 3] as $posicion) {
            $this->assertCount(1, (array) $pedidos[$posicion][0]['input'], 'Los siguientes pedidos llevan un candidato solo.');
        }

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(2, (int) $tanda->generados);
        $this->assertSame(1, (int) $tanda->fallados);
        $this->assertSame(0, (int) $tanda->salteados);
        $this->assertSame(3, (int) $tanda->terminados);

        $fila_b = DB::table('articles')->where('id', $b)->first();
        $this->assertNull($fila_b->embedding_queued_at, 'El fallado queda sin marca: el comando lo reintenta en la pasada siguiente.');
        $this->assertNull($fila_b->embedding_source_hash, 'Y sin huella nueva: sigue pendiente.');
        $this->assertNull($fila_b->embedding, 'Y sin vector.');

        foreach ([$a, $c] as $bueno) {
            $fila = DB::table('articles')->where('id', $bueno)->first();
            $this->assertNotNull($fila->embedding, 'Los otros dos quedaron vectorizados.');
            $this->assertNotNull($fila->embedding_source_hash);
            $this->assertNull($fila->embedding_queued_at);
        }

        $this->assertSame(2, $this->filas_de_consumo($ids), 'Sólo se registra consumo de los dos pedidos aceptados.');
    }

    // ─────────────────────────────── CA8 ───────────────────────────────

    /**
     * CA8. Credencial rechazada en un lote: 401 con cuerpo de OpenAI → corte abierto, `fallados = 3`, el job no lanza, marcas nulas y
     * UN solo pedido. Un segundo lote con el corte abierto no llama a OpenAI (0 pedidos nuevos) y suma `fallados` igual.
     *
     * @group whatsapp
     * @test
     */
    public function test_credencial_rechazada_corta_el_lote()
    {
        $ids   = $this->crear_lote(3, 'Credencial');
        $tanda = $this->crear_tanda(5);

        $this->openai_responde(Http::response(
            $this->cuerpo_error('Incorrect API key provided: sk-proj-ABCDEFGHIJKLMNOPQRSTUVWXYZ123456.', 'invalid_api_key'),
            401
        ));

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNull($lanzo, 'Un error permanente de credencial no se reintenta a ciegas: el job no lanza.');
        $this->assertTrue(Cache::has(EmbeddingsCredencialCorte::CLAVE), 'El 401 abre el corte de credencial.');
        Http::assertSentCount(1);

        $tanda_1 = EmbeddingRun::find($tanda->id);
        $this->assertSame(3, (int) $tanda_1->fallados);
        $this->assertSame(3, (int) $tanda_1->terminados);
        $this->assertSame(0, (int) $tanda_1->generados);
        $this->assertSame(0, $this->marcados($ids), 'Las marcas quedan liberadas: el comando los retoma con el corte vencido.');
        $this->assertSame(0, $this->filas_de_consumo($ids));

        // Segundo lote con el corte abierto: ni un pedido más.
        $mas_ids = $this->crear_lote(2, 'Credencial segundo');

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($mas_ids, (int) $tanda->id));

        $this->assertNull($lanzo);
        Http::assertSentCount(1);

        $tanda_2 = EmbeddingRun::find($tanda->id);
        $this->assertSame(5, (int) $tanda_2->fallados, 'fallados += 2 con el corte abierto.');
        $this->assertSame(5, (int) $tanda_2->terminados);
        $this->assertSame(0, $this->marcados($mas_ids));
    }

    // ─────────────────────────────── CA9 ───────────────────────────────

    /**
     * CA9 (reserva y conciliación). Prueba vigente con `embeddings_tokens_usados = 0`: un lote de 3 reserva 3 × 8192 ANTES del pedido
     * y, con `prompt_tokens = 30`, concilia y deja el contador en 30.
     *
     * @group whatsapp
     * @test
     */
    public function test_cupo_de_prueba_reserva_por_texto_y_concilia()
    {
        $prueba = $this->activar_prueba(0);
        $ids    = $this->crear_lote(3, 'Cupo');
        $tanda  = $this->crear_tanda(3);

        $reservado_al_enviar = [];
        $this->openai_responde(function () use (&$reservado_al_enviar, $prueba) {
            $reservado_al_enviar[] = $this->tokens_usados($prueba);

            return Http::response($this->cuerpo_exito(3, 30), 200);
        });

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNull($lanzo);
        Http::assertSentCount(1);
        $this->assertSame([3 * 8192], $reservado_al_enviar, 'Al salir el pedido ya están reservados 3 × 8192 tokens (un tope por texto).');
        $this->assertSame(30, $this->tokens_usados($prueba), 'Concilia con lo que cobró OpenAI: queda en 30.');
        $this->assertSame(3, (int) EmbeddingRun::find($tanda->id)->generados);
    }

    /**
     * CA9 (cupo agotado). Con `usados = 50.000.000 − 2 × 8192` entra UN texto más pero no tres: el lote de 3 queda `salteados = 3`,
     * ningún pedido sale y el contador no se mueve.
     *
     * @group whatsapp
     * @test
     */
    public function test_cupo_de_prueba_sin_lugar_para_el_lote_lo_saltea()
    {
        $usados = 50000000 - (2 * 8192);
        $prueba = $this->activar_prueba($usados);
        $ids    = $this->crear_lote(3, 'Cupo agotado');
        $tanda  = $this->crear_tanda(3);

        $this->openai_responde(Http::response($this->cuerpo_exito(3, 30), 200));

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNull($lanzo, 'Sin cupo no se reintenta: se saltea.');
        Http::assertNothingSent();
        $this->assertSame($usados, $this->tokens_usados($prueba), 'El contador no se mueve: no se reservó nada.');

        $tanda = EmbeddingRun::find($tanda->id);
        $this->assertSame(3, (int) $tanda->salteados);
        $this->assertSame(3, (int) $tanda->terminados);
        $this->assertSame(0, (int) $tanda->generados);
        $this->assertSame(0, $this->marcados($ids));
    }

    /**
     * CA9 (rechazo explícito). Un 400 libera TODA la reserva: el contador vuelve al valor previo. (El lote entra por el camino de
     * aislamiento y los tres textos también reciben 400: `fallados = 3`.)
     *
     * @group whatsapp
     * @test
     */
    public function test_un_400_libera_toda_la_reserva_del_cupo()
    {
        $prueba = $this->activar_prueba(1000);
        $ids    = $this->crear_lote(3, 'Cupo 400');
        $tanda  = $this->crear_tanda(3);

        $reservado_al_enviar = [];
        $this->openai_responde(function () use (&$reservado_al_enviar, $prueba) {
            $reservado_al_enviar[] = $this->tokens_usados($prueba);

            return Http::response($this->cuerpo_error('Invalid input', null, 'invalid_request_error'), 400);
        });

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNull($lanzo);
        $this->assertNotEmpty($reservado_al_enviar, 'Salió al menos el pedido del lote.');
        $this->assertSame(1000 + 3 * 8192, $reservado_al_enviar[0], 'El pedido del lote salió con 3 × 8192 reservados sobre lo previo.');
        $this->assertSame(1000, $this->tokens_usados($prueba), 'Un 400 explícito libera toda la reserva: el contador vuelve al valor previo.');
        $this->assertSame(3, (int) EmbeddingRun::find($tanda->id)->fallados);
    }

    /**
     * CA9 (resultado incierto). Un 5xx (no se sabe si OpenAI cobró) MANTIENE la reserva completa (regla de embeddings-cupo-prueba,
     * 5/11/2026): el job lanza para reintentar y el contador queda en 3 × 8192.
     *
     * @group whatsapp
     * @test
     */
    public function test_un_5xx_mantiene_la_reserva_del_cupo()
    {
        $prueba = $this->activar_prueba(0);
        $ids    = $this->crear_lote(3, 'Cupo 500');
        $tanda  = $this->crear_tanda(3);

        $this->openai_responde(Http::response($this->cuerpo_error('Internal server error', null, 'server_error'), 500));

        $lanzo = $this->correr(new GenerateArticleEmbeddingsBatchJob($ids, (int) $tanda->id));

        $this->assertNotNull($lanzo, 'Un 5xx es transitorio: el job lanza para que la cola lo reintente.');
        $this->assertSame(3 * 8192, $this->tokens_usados($prueba), 'Resultado incierto: la reserva se mantiene.');
        $this->assertSame(0, (int) EmbeddingRun::find($tanda->id)->terminados);
    }

    // ─────────────────────────────── CA15 ───────────────────────────────

    /**
     * CA15. El vencimiento de la tanda es proporcional a su tamaño: `created_at + max(20 min, ceil(total_jobs / 100) × 30 s)`.
     * 22.700 artículos → 6.810 s (113,5 min): a los 30 minutos con 5.000 terminados NO vence y el finalizador se re-despacha; una
     * tanda de 10 artículos sigue venciendo a los 20 como hoy.
     *
     * @group whatsapp
     * @test
     */
    public function test_vencimiento_proporcional_al_tamano()
    {
        Queue::fake();

        // vence_at: el cálculo mismo.
        $grande = $this->envejecer_tanda($this->crear_tanda(22700, ['terminados' => 5000]), 30);
        $this->assertTrue(
            Carbon::parse($grande->created_at)->addSeconds(6810)->equalTo(FinalizeEmbeddingRun::vence_at($grande)),
            '22.700 artículos = 227 lotes × 30 s = 6.810 s desde created_at.'
        );

        $chica = $this->envejecer_tanda($this->crear_tanda(10, ['terminados' => 3]), 25);
        $this->assertTrue(
            Carbon::parse($chica->created_at)->addMinutes(20)->equalTo(FinalizeEmbeddingRun::vence_at($chica)),
            'Una tanda chica sigue venciendo a los 20 minutos (el piso).'
        );

        // El finalizador: la grande, a los 30 min, todavía está viva.
        (new FinalizeEmbeddingRun($grande->id))->handle();

        $grande = EmbeddingRun::find($grande->id);
        $this->assertSame('en_proceso', $grande->status, 'A los 30 minutos una tanda de 22.700 no vence.');
        $this->assertNull($grande->notificado_at);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);

        // La chica, a los 25 min, vence como hoy.
        (new FinalizeEmbeddingRun($chica->id))->handle();

        $chica = EmbeddingRun::find($chica->id);
        $this->assertSame('vencida', $chica->status, 'Una tanda de 10 artículos a los 25 minutos vence (como hoy).');
        $this->assertNotNull($chica->notificado_at);
        Queue::assertPushed(FinalizeEmbeddingRun::class, 1);

        // Y la grande también vence, pero pasado SU plazo (115 min > 113,5).
        $grande_vieja = $this->envejecer_tanda($this->crear_tanda(22700, ['terminados' => 5000]), 115);

        (new FinalizeEmbeddingRun($grande_vieja->id))->handle();

        $this->assertSame('vencida', EmbeddingRun::find($grande_vieja->id)->status, 'Pasados los 113,5 minutos la tanda grande vence.');
    }
}
