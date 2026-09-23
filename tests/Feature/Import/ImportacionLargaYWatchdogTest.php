<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Http\Controllers\Helpers\import\article\ImportFailureHandler;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ArticleImportResult;
use App\Models\BackgroundProcess;
use App\Models\ImportHistory;
use App\Models\ImportStatus;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;
use Tests\Import\ImportTestCase;

/**
 * Misión `importaciones-largas-y-cruce-de-codigos` (23/9/2026): lo que pasó en Servian con las
 * importaciones 35 y 37, que el panel mostró en "Falló" y en realidad terminaron 97/97.
 *
 * DOS DEFECTOS, UNO ATRÁS DEL OTRO
 * --------------------------------
 *  1. `ProcessArticleChunk` sube los contadores con `DB::table()->update()`, que NO toca
 *     `updated_at`. El watchdog `imports:detectar-colgadas` mira justamente `updated_at`, así que
 *     una importación de ~55 min (97 lotes de ~33 s) se veía idéntica a una muerta a los 45.
 *  2. Marcada `fallo` con un lote en vuelo, ese lote recalculaba el estado al terminar y la
 *     devolvía a `en_proceso`: el guard del arranque del lote siguiente ya no la veía y la chain
 *     seguía, con el registro de procesos clavado en `fallo`.
 *
 * CÓMO SE ARMA EL ESCENARIO
 * -------------------------
 * La importación es REAL (endpoint, `InitExcelImport`, CSV, columnas del fixture de tests/Import),
 * pero con `Queue::fake()` alrededor del POST: la chain queda capturada en vez de correr inline, y
 * cada lote se corre a mano, en el orden y en el momento que el test necesita. El primer eslabón es
 * el job que quedó en la cola falsa; los siguientes viajan serializados en su `$chained`, igual que
 * en producción.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group import
 */
class ImportacionLargaYWatchdogTest extends EmpresaTestCase
{
    /**
     * Fixture de tests/Import: 5 filas de datos, todas con provider_code propio.
     *
     * ⚠️ No es `01_codigos_de_proveedor.xlsx` a propósito: ese fixture trae placeholders y
     * códigos ambiguos que generan conflictos, y la persistencia de conflictos hace un
     * `ImportHistory::increment('conflicts_count')` por Eloquent, que SÍ toca `updated_at`. Con
     * ese fixture el lado del ImportHistory del test 1 quedaba verde sin el fix, por suerte del
     * archivo. Este fixture, importado por el usuario de EmpresaTestCase, no genera conflictos
     * (lo asierta el propio test 1), igual que la importación de Servian, que solo actualizaba
     * precios.
     */
    const FIXTURE = '05_rollback.xlsx';

    /** 5 filas en lotes de 2 = 3 lotes: uno para arrancar, uno "en vuelo" y uno "siguiente". */
    const FILAS_POR_LOTE = 2;

    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        /* Mismo reset que ImportTestCase: el índice de artículos vive en cache y en estáticas. */
        Cache::flush();
        ArticleIndexCache::reset_runtime_de_tests();

        /* Ningún test de esta suite sale a la red. */
        config(['services.anthropic.api_key' => null]);
        config(['services.openai.api_key' => null]);
        config(['broadcasting.default' => 'null']);
        Http::fake(['*' => Http::response([], 200)]);
    }

    protected function tearDown(): void
    {
        ArticleIndexCache::reset_runtime_de_tests();
        Cache::flush();

        parent::tearDown();
    }

    /**
     * Test 1. 🔴 Una importación larga pero viva no la mata el watchdog.
     *
     * El lote 2 arranca con `updated_at` de hace dos horas en las dos tablas (como si el lote 1
     * hubiera terminado hace rato y el 2 viniera tardando) y NO cambia de estado (sigue
     * `en_proceso`), así que la única escritura que puede refrescar `updated_at` es la de los
     * contadores. Sin el fix, el watchdog corrido después la marca `fallo`.
     *
     * El control negativo va en la MISMA corrida del watchdog: una importación activa sin lotes y
     * con `updated_at` viejo de verdad SÍ se marca. Así el test no puede pasar por un watchdog que
     * no hace nada.
     *
     * @test
     */
    public function un_lote_que_termina_refresca_updated_at_y_el_watchdog_no_la_marca()
    {
        list($lote_1, $lote_2) = $this->importar_y_capturar_lotes();

        $lote_1->handle();

        $import_status  = $this->import_status_de_la_importacion();
        $import_history = ImportHistory::where('import_status_id', $import_status->id)->first();

        $this->assertSame('en_proceso', $import_history->status);
        $this->assertSame('en_proceso', $import_status->status);

        $hace_dos_horas = Carbon::now()->subHours(2);

        DB::table('import_histories')->where('id', $import_history->id)->update(['updated_at' => $hace_dos_horas]);
        DB::table('import_statuses')->where('id', $import_status->id)->update(['updated_at' => $hace_dos_horas]);

        /* El control negativo: activa, sin lotes que la muevan, y de verdad vieja. */
        $muerta = ImportHistory::create([
            'user_id'          => $this->user_id,
            'model_name'       => 'article',
            'status'           => 'en_proceso',
            'total_chunks'     => 5,
            'processed_chunks' => 1,
        ]);
        DB::table('import_histories')->where('id', $muerta->id)->update(['updated_at' => $hace_dos_horas]);

        $conflictos_antes = (int) $import_history->fresh()->conflicts_count;

        $lote_2->handle();

        $un_minuto_atras = Carbon::now()->subMinute();

        $import_history = ImportHistory::find($import_history->id);
        $import_status  = ImportStatus::find($import_status->id);

        $this->assertSame(2, (int) $import_history->processed_chunks, 'el lote 2 no sumó: el test no está probando lo que dice');
        $this->assertSame('en_proceso', $import_history->status);

        /*
         * Candado del propio escenario: si el lote registra conflictos, el increment de Eloquent
         * refresca `updated_at` por su cuenta y el test pasaría sin el fix (ver FIXTURE).
         */
        $this->assertSame(
            $conflictos_antes,
            (int) $import_history->conflicts_count,
            'el lote 2 registró conflictos: toca updated_at por otro camino y el test deja de probar los contadores'
        );

        $this->assertTrue(
            $import_history->updated_at->greaterThan($un_minuto_atras),
            'import_histories.updated_at quedó en ' . $import_history->updated_at . ': el lote no lo refrescó y el watchdog la va a ver colgada'
        );
        $this->assertTrue(
            $import_status->updated_at->greaterThan($un_minuto_atras),
            'import_statuses.updated_at quedó en ' . $import_status->updated_at . ': el lote no lo refrescó'
        );

        Artisan::call('imports:detectar-colgadas');

        $this->assertSame(
            'en_proceso',
            ImportHistory::find($import_history->id)->status,
            'el watchdog marcó fallida una importación que acababa de terminar un lote'
        );
        $this->assertSame('en_proceso', ImportStatus::find($import_status->id)->status);

        $this->assertSame(
            'fallo',
            ImportHistory::find($muerta->id)->status,
            'el control negativo no se marcó: el watchdog no está corriendo o cambió el criterio'
        );
    }

    /**
     * Test 2. 🔴 Un `fallo` marcado con un lote en vuelo no se resucita.
     *
     * El watchdog (o ImportFailureHandler) marca `fallo` DESPUÉS de que el lote 2 pasó el guard
     * del arranque: se engancha al alta de su ArticleImportResult, que es lo primero que el lote
     * hace una vez adentro. Al terminar, el lote recalcula el estado; sin el fix, lo devuelve a
     * `en_proceso` y el lote 3 procesa como si nada.
     *
     * @test
     */
    public function un_fallo_marcado_con_un_lote_en_vuelo_no_se_resucita_y_el_siguiente_corta()
    {
        list($lote_1, $lote_2, $lote_3) = $this->importar_y_capturar_lotes();

        $lote_1->handle();

        $import_status  = $this->import_status_de_la_importacion();
        $import_history = ImportHistory::where('import_status_id', $import_status->id)->first();
        $user_id        = $this->user_id;

        $marcado = false;

        ArticleImportResult::created(function ($resultado) use (&$marcado, $import_history, $import_status, $user_id) {
            if ($marcado) {
                return;
            }

            $marcado = true;

            ImportFailureHandler::registrar(
                $import_history->id,
                $import_status->id,
                $user_id,
                'La importación quedó sin actividad por más de 45 minutos (simulado por el test).',
                null,
                1
            );
        });

        $lote_2->handle();

        $this->assertTrue($marcado, 'el fallo nunca se marcó en vuelo: el test no está probando lo que dice');

        $import_history = ImportHistory::find($import_history->id);
        $import_status  = ImportStatus::find($import_status->id);

        $this->assertSame('fallo', $import_history->status, 'el lote en vuelo resucitó el ImportHistory');
        $this->assertSame('fallo', $import_status->status, 'el lote en vuelo resucitó el ImportStatus');

        /* Los contadores sí suman: el trabajo del lote 2 se hizo de verdad en la base. */
        $this->assertSame(2, (int) $import_history->processed_chunks);
        $this->assertSame(2, (int) $import_status->processed_chunks);

        /* El registro visible dice lo mismo que las dos tablas. */
        $proceso = BackgroundProcessHelper::por_referencia($import_status, false);
        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);

        /* El lote siguiente corta en el guard del arranque: no suma ni deja resultado. */
        $resultados_antes = ArticleImportResult::where('import_history_id', $import_history->id)->count();

        $lote_3->handle();

        $this->assertSame(2, (int) ImportStatus::find($import_status->id)->processed_chunks, 'el lote 3 procesó una importación fallida');
        $this->assertSame(2, (int) ImportHistory::find($import_history->id)->processed_chunks);
        $this->assertSame($resultados_antes, ArticleImportResult::where('import_history_id', $import_history->id)->count());
        $this->assertSame('fallo', ImportHistory::find($import_history->id)->status);
        $this->assertSame('fallo', ImportStatus::find($import_status->id)->status);
    }

    /**
     * Test 2 bis. El `fallo` solo en el ImportHistory tampoco se pisa al arrancar un lote.
     *
     * Es la combinación que dejan el watchdog con `import_status_id` en null y el early-return
     * de ImportFailureHandler::registrar() (ver FinalizeArticleImportTest, caso 3): el guard del
     * arranque mira solo el ImportStatus, así que el lote entra, y sin el fix
     * set_import_history_status_at_chunk_start() devolvía el history a `en_proceso`.
     *
     * @test
     */
    public function un_fallo_solo_en_el_import_history_no_lo_pisa_el_lote_que_arranca()
    {
        list($lote_1, $lote_2) = $this->importar_y_capturar_lotes();

        $lote_1->handle();

        $import_status  = $this->import_status_de_la_importacion();
        $import_history = ImportHistory::where('import_status_id', $import_status->id)->first();

        ImportFailureHandler::registrar(
            $import_history->id,
            null,
            $this->user_id,
            'Marcado solo en el history (simulado por el test).',
            null,
            1
        );

        $this->assertSame('en_proceso', ImportStatus::find($import_status->id)->status, 'el escenario pide el ImportStatus activo');

        $lote_2->handle();

        $this->assertSame(2, (int) ImportHistory::find($import_history->id)->processed_chunks, 'el lote 2 no corrió: el test no está probando lo que dice');
        $this->assertSame('fallo', ImportHistory::find($import_history->id)->status, 'un lote que arrancó pisó el fallo del ImportHistory');
    }

    /**
     * Arranca la importación del fixture contra el endpoint real con la cola falseada y devuelve
     * los lotes capturados, en orden (sin el FinalizeArticleImport del final).
     *
     * @return ProcessArticleChunk[]
     */
    protected function importar_y_capturar_lotes()
    {
        config(['app.ARTICLE_EXCEL_CHUNK_SIZE' => self::FILAS_POR_LOTE]);

        $origen = base_path('tests/Import/fixtures/' . self::FIXTURE);

        $this->assertFileExists($origen, 'Falta el fixture ' . self::FIXTURE);

        /* ArticleController@import MUEVE el archivo: se importa una copia temporal. */
        $copia = sys_get_temp_dir() . '/' . uniqid('watchdog_import_') . '_' . self::FIXTURE;
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
                'finish_row'  => 99999,
                'provider_id' => null,
            ],
            ImportTestCase::config_por_defecto(),
            ImportTestCase::columnas()
        );

        Queue::fake();

        $this->postJson('/api/article/excel/import', $data)->assertStatus(200);

        $primero = Queue::pushed(ProcessArticleChunk::class)->first();

        $this->assertNotNull($primero, 'la importación no encoló su chain de lotes');

        $lotes = [$primero];

        foreach ((array) $primero->chained as $serializado) {
            $job = unserialize($serializado);

            if ($job instanceof ProcessArticleChunk) {
                $lotes[] = $job;
            } else {
                $this->assertInstanceOf(FinalizeArticleImport::class, $job);
            }
        }

        $this->assertCount(3, $lotes, '5 filas en lotes de 2 son 3 lotes');

        return $lotes;
    }

    /**
     * @return ImportStatus
     */
    protected function import_status_de_la_importacion()
    {
        $import_status = ImportStatus::where('user_id', $this->user_id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($import_status, 'La importación no dejó ImportStatus.');

        return $import_status;
    }
}
