<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Models\BackgroundProcess;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;
use Tests\Import\ImportTestCase;

/**
 * La importación de artículos por Excel registra su avance en el registro único de procesos
 * (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Lo que protege:
 *
 *  1. Una importación REAL (InitExcelImport → chunks → FinalizeArticleImport, corrida inline
 *     porque phpunit.xml fija QUEUE_CONNECTION=sync) deja una fila `importacion_articulos`
 *     completada, con `total = total_chunks`, `porcentaje = 100` y los mismos números que el
 *     `ImportStatus`. Si la fila no cierra o los números no coinciden, la píldora de la SPA
 *     miente, que es peor que no existir.
 *  2. Un lote que falla deja la fila en `fallo` con el mensaje humano que arma
 *     `ImportFailureHandler`, y la segunda llamada (el `failed()` del job después del catch del
 *     `handle()`) no la pisa.
 *  3. 🔴 Un broadcaster caído NO voltea la importación. Hasta esta misión
 *     `ProcessArticleChunk::notificar_import_status()` llamaba a `broadcast()` adentro del try
 *     del `handle()`: un timeout de Pusher marcaba la importación en `fallo` por un aviso que no
 *     salió. Acá se instala un driver de broadcast que tira en cada `broadcast()` y se exige que
 *     la importación termine igual, en las tres tablas.
 *
 * El Excel y el mapeo de columnas son los de `tests/Import` (fixture `01_codigos_de_proveedor.xlsx`
 * + `ImportTestCase::columnas()` / `config_por_defecto()`): no se inventa otro fixture. Se corre
 * como el usuario de `EmpresaTestCase`, no como el tenant 900, porque acá no importa contra qué se
 * matchea cada fila sino que el proceso cuente bien lo que el `ImportStatus` ya cuenta.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group procesos-en-segundo-plano
 */
class Importacion_registra_su_avance_Test extends EmpresaTestCase
{
    /** Fixture de tests/Import: 7 filas de datos, todas con código de proveedor. */
    const FIXTURE = '01_codigos_de_proveedor.xlsx';

    /**
     * Tamaño de lote para estos tests. El fixture tiene 7 filas y el chunk de testing es 50: con
     * 3 salen tres lotes, que es lo mínimo para ver "Lote N de M" avanzar de verdad.
     */
    const FILAS_POR_LOTE = 3;

    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        /*
         * Mismo reset que ImportTestCase: el índice de artículos vive en cache y en estáticas del
         * proceso de PHPUnit; sin esto, un test hereda el índice del anterior.
         */
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();

        /* Ningún test de esta suite sale a la red: ni IA ni embeddings post-importación. */
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        ArticleIndexCache::reset_runtime_de_tests();
        Cache::flush();

        parent::tearDown();
    }

    /** @test */
    public function una_importacion_real_deja_su_proceso_completado_con_los_numeros_del_import_status()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $import_status = $this->importar_fixture();

        $this->assertSame('completado', $import_status->status);
        $this->assertSame(3, (int) $import_status->total_chunks, '7 filas en lotes de 3 son 3 lotes');
        $this->assertGreaterThan(0, (int) $import_status->created_models, 'el fixture tiene que crear artículos: si no, el test no prueba nada');

        $proceso = BackgroundProcessHelper::por_referencia($import_status, false);

        $this->assertNotNull($proceso, 'la importación no dejó su fila en background_processes');
        $this->assertSame('importacion_articulos', $proceso->tipo);
        $this->assertSame('Importación de artículos', $proceso->titulo);
        $this->assertSame(ImportStatus::class, $proceso->referencia_type);
        $this->assertSame($import_status->id, (int) $proceso->referencia_id);
        $this->assertSame($this->user_id, (int) $proceso->user_id);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id);
        $this->assertSame('lotes', $proceso->unidad);
        $this->assertStringContainsString('7 filas', (string) $proceso->detalle);

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame((int) $import_status->total_chunks, (int) $proceso->total);
        $this->assertSame((int) $import_status->total_chunks, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame('Terminado', $proceso->etapa);
        $this->assertNull($proceso->error_message);
        $this->assertNotNull($proceso->finished_at);

        $resultado = $proceso->resultado();

        $this->assertSame((int) $import_status->created_models, $resultado['creados']);
        $this->assertSame((int) $import_status->updated_models, $resultado['actualizados']);
        $this->assertSame((int) $import_status->articles_match, $resultado['coincidencias']);
        $this->assertSame((int) $import_status->filas_procesadas, $resultado['filas_procesadas']);
        $this->assertSame((int) $import_status->articles_repetidos, $resultado['repetidos']);
        $this->assertSame(7, $resultado['filas_procesadas']);

        /*
         * Los hitos que SIEMPRE salen por Pusher, sin importar el throttle: el alta en
         * `pendiente` (encolado), el primer lote (cambio a `en_proceso`), el último lote (llega al
         * total) y el cierre. Los intermedios pueden caer en el throttle de 2 s y no se asertan.
         */
        $emitidos = Event::dispatched(BackgroundProcessUpdated::class)
            ->map(function ($argumentos) {
                return $argumentos[0];
            })
            ->filter(function ($evento) use ($proceso) {
                return $evento->proceso['id'] === $proceso->id;
            })
            ->values();

        $this->assertGreaterThanOrEqual(4, $emitidos->count());
        $this->assertSame('pendiente', $emitidos->first()->proceso['status']);
        $this->assertSame('En espera del procesador', $emitidos->first()->proceso['etapa']);
        $this->assertSame('completado', $emitidos->last()->proceso['status']);
        $this->assertSame(100, $emitidos->last()->proceso['porcentaje']);

        $etapas = $emitidos->map(function ($evento) {
            return $evento->proceso['etapa'];
        })->all();

        $this->assertContains('Lote 1 de 3', $etapas);
        $this->assertContains('Lote 3 de 3', $etapas);
        $this->assertContains('Terminado', $etapas);

        /* Y el canal es el del dueño, con el nombre exacto que escucha la SPA. */
        $this->assertSame('background_processes.' . $this->user_id, $emitidos->last()->broadcastOn()->name);
        $this->assertSame('BackgroundProcessUpdated', $emitidos->last()->broadcastAs());
    }

    /** @test */
    public function un_lote_que_falla_deja_el_proceso_en_fallo_con_el_mensaje_humano()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        /* Una importación a mitad de camino, como la deja el primer lote. */
        $import_status = ImportStatus::create([
            'user_id'          => $this->user_id,
            'total_chunks'     => 4,
            'processed_chunks' => 1,
            'created_models'   => 12,
            'status'           => 'en_proceso',
        ]);

        $import_history = ImportHistory::create([
            'user_id'          => $this->user_id,
            'model_name'       => 'article',
            'status'           => 'en_proceso',
            'total_chunks'     => 4,
            'processed_chunks' => 1,
            'import_status_id' => $import_status->id,
        ]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'importacion_articulos', 'Importación de artículos', [
            'referencia' => $import_status,
            'total'      => 4,
            'unidad'     => 'lotes',
        ]);

        BackgroundProcessHelper::avanzar($proceso, 1, [
            'etapa'     => 'Lote 1 de 4',
            'resultado' => ['creados' => 12],
        ]);

        /* El catch del handle() del lote 2. */
        ImportFailureHandler::desde_excepcion(
            $import_history->id,
            $import_status->id,
            $this->user_id,
            new \RuntimeException('Memoria insuficiente al iniciar el lote 2'),
            2,
            ['start_row' => 4, 'finish_row' => 6]
        );

        $proceso = BackgroundProcess::find($proceso->id);

        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('Falló en el lote 2 (filas 4–6)', $proceso->error_message);
        $this->assertStringContainsString('Memoria insuficiente al iniciar el lote 2', $proceso->error_message);
        $this->assertSame('Falló', $proceso->etapa);
        $this->assertNotNull($proceso->finished_at);
        $this->assertSame(12, $proceso->resultado()['creados'], 'los parciales que ya había se conservan');

        /* El mismo mensaje que le queda al ImportStatus: una sola verdad para las dos tarjetas. */
        $this->assertSame('fallo', ImportStatus::find($import_status->id)->status);
        $this->assertSame(ImportStatus::find($import_status->id)->error_message, $proceso->error_message);

        /* El failed() del job llega después del catch: no duplica ni pisa. */
        ImportFailureHandler::desde_excepcion(
            $import_history->id,
            $import_status->id,
            $this->user_id,
            new \RuntimeException('Otro mensaje que no tiene que ganar'),
            2
        );

        $otra_vez = BackgroundProcess::find($proceso->id);

        $this->assertSame($proceso->error_message, $otra_vez->error_message);
        $this->assertEquals($proceso->finished_at, $otra_vez->finished_at);

        /* Un cerrado ya no cuenta como abierto para los lotes que todavía puedan arrancar. */
        $this->assertNull(BackgroundProcessHelper::por_referencia($import_status));
    }

    /** @test */
    public function un_broadcaster_caido_no_voltea_la_importacion()
    {
        /*
         * Acá NO se hace Event::fake: los dos eventos (ImportStatusUpdated y
         * BackgroundProcessUpdated) tienen que llegar de verdad al broadcaster que tira, que es
         * exactamente lo que pasa en producción cuando Pusher devuelve un 502 o un timeout.
         */
        $broadcaster = $this->instalar_broadcaster_que_tira();

        $import_status = $this->importar_fixture();

        $this->assertGreaterThan(
            0,
            $broadcaster->llamadas,
            'el broadcaster falso nunca fue llamado: el test no está probando lo que dice'
        );

        /* La importación terminó en las tres tablas como si Pusher hubiera andado. */
        $this->assertSame('completado', $import_status->status, 'un aviso que no salió marcó la importación como fallida');
        $this->assertNull($import_status->error_message);
        $this->assertSame((int) $import_status->total_chunks, (int) $import_status->processed_chunks);

        $import_history = ImportHistory::where('import_status_id', $import_status->id)->first();

        $this->assertNotNull($import_history);
        $this->assertSame('terminado', $import_history->status);

        $proceso = BackgroundProcessHelper::por_referencia($import_status, false);

        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(100, (int) $proceso->porcentaje);
    }

    /**
     * Corre la importación del fixture contra el endpoint real, como el usuario autenticado, y
     * devuelve el ImportStatus resultante releído de la base.
     *
     * Con QUEUE_CONNECTION=sync (phpunit.xml) la cadena de jobs corre inline dentro del POST, así
     * que al volver de acá la importación ya terminó (bien o mal).
     *
     * @return \App\Models\ImportStatus
     */
    protected function importar_fixture()
    {
        /* Tres lotes, no uno: ver FILAS_POR_LOTE. La cola es sync, así que el config llega a los jobs. */
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => self::FILAS_POR_LOTE]);

        $origen = base_path('tests/Import/fixtures/' . self::FIXTURE);

        $this->assertFileExists($origen, 'Falta el fixture ' . self::FIXTURE);

        /*
         * ArticleController@import MUEVE el archivo con storeAs(): se importa siempre una copia
         * temporal, igual que ImportTestCase::importar().
         */
        $copia = sys_get_temp_dir() . '/' . uniqid('proceso_import_') . '_' . self::FIXTURE;
        copy($origen, $copia);

        $data = array_merge(
            [
                'models' => new UploadedFile(
                    $copia,
                    self::FIXTURE,
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    null,
                    true
                ),
                'start_row'   => 2,
                /* InitExcelImport::ajustar_finish_row_segun_excel_real() lo baja al real (8). */
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            ImportTestCase::config_por_defecto(),
            ImportTestCase::columnas()
        );

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $import_status = ImportStatus::where('user_id', $this->user_id)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($import_status, 'La importación no dejó ImportStatus.');

        return $import_status;
    }

    /**
     * Reemplaza el driver de broadcast por uno que tira en cada `broadcast()`, contando las
     * llamadas para poder asertar que de verdad lo golpearon.
     *
     * Es un driver real registrado con `Broadcast::extend()` y no un mock del manager: así la
     * excepción nace en el mismo lugar donde la tira `PusherBroadcaster` (adentro de
     * `BroadcastEvent::handle()`), y sube por el mismo camino hasta el `broadcast()` de cada
     * emisor. Cualquier cosa que un Pusher caído voltearía, esto la voltea igual.
     *
     * @return \Illuminate\Contracts\Broadcasting\Broadcaster  Con la propiedad pública `llamadas`.
     */
    protected function instalar_broadcaster_que_tira()
    {
        $broadcaster = new class implements Broadcaster {
            /** @var int */
            public $llamadas = 0;

            public function auth($request)
            {
                return true;
            }

            public function validAuthenticationResponse($request, $result)
            {
                return $result;
            }

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->llamadas++;

                throw new BroadcastException('Pusher caído (simulado por el test) al emitir ' . $event);
            }
        };

        Broadcast::extend('pusher_caido', function () use ($broadcaster) {
            return $broadcaster;
        });

        config([
            'broadcasting.connections.pusher_caido' => ['driver' => 'pusher_caido'],
            'broadcasting.default'                  => 'pusher_caido',
        ]);

        return $broadcaster;
    }
}
