<?php

namespace Tests\Feature\Import;

use App\Http\Controllers\Helpers\import\article\ArticleIndexCache;
use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Models\ArticleImportResult;
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
 * EL DEFECTO
 * ----------
 * `ProcessArticleChunk` sube los contadores con `DB::table()->update()`, que NO toca `updated_at`.
 * El watchdog `imports:detectar-colgadas` mira justamente `updated_at`, así que una importación
 * de ~55 min (97 lotes de ~33 s) se veía idéntica a una muerta a los 45. Ahora cada lote lo
 * refresca al arrancar y al terminar.
 *
 * ⚠️ Lo que NO se hace, a propósito: impedir que un lote en vuelo devuelva a `en_proceso` una
 * importación que el watchdog marcó `fallo`. Se probó y se sacó en el chequeo independiente: con
 * un falso positivo del watchdog eso deja el catálogo importado a medias en vez de terminarlo.
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
     * Con el lote 2 ya adentro (el alta de su ArticleImportResult, después del refresco del
     * arranque), el `updated_at` de las dos tablas se lleva a hace dos horas: es un lote que
     * tardó mucho. El lote NO cambia de estado (sigue `en_proceso`), así que lo único que puede
     * devolver `updated_at` a "ahora" es la escritura de los contadores al terminar. Sin eso, el
     * watchdog corrido después la marca `fallo`. (El refresco del arranque lo cubre el test 2.)
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

        /* Envejecido con el lote ya adentro: lo que pase antes (el refresco del arranque) no lo salva. */
        $envejecido = false;

        ArticleImportResult::created(function ($resultado) use (&$envejecido, $import_history, $import_status, $hace_dos_horas) {
            if ($envejecido) {
                return;
            }

            $envejecido = true;

            DB::table('import_histories')->where('id', $import_history->id)->update(['updated_at' => $hace_dos_horas]);
            DB::table('import_statuses')->where('id', $import_status->id)->update(['updated_at' => $hace_dos_horas]);
        });

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

        $this->assertTrue($envejecido, 'el lote 2 nunca creó su ArticleImportResult: el test no está probando lo que dice');

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
     * Test 2. 🔴 Un lote que ARRANCA ya refresca `updated_at`, sin esperar a terminar.
     *
     * Con el refresco solo al final, el hueco que ve el watchdog es la espera en cola MÁS la
     * duración del lote; con el refresco al arranque, es el mayor de los dos. Se mira en el
     * momento en que el lote ya está adentro (el alta de su ArticleImportResult, lo primero que
     * hace después del guard de `fallo` y de marcar el estado), con el `updated_at` envejecido dos
     * horas y el status sin cambiar (sigue `en_proceso`, así que ningún update de Eloquent lo
     * refresca de rebote).
     *
     * @test
     */
    public function un_lote_que_arranca_refresca_updated_at_antes_de_procesar()
    {
        list($lote_1, $lote_2) = $this->importar_y_capturar_lotes();

        $lote_1->handle();

        $import_status  = $this->import_status_de_la_importacion();
        $import_history = ImportHistory::where('import_status_id', $import_status->id)->first();

        $this->assertSame('en_proceso', $import_history->status, 'el escenario pide que el lote 2 no cambie el status');
        $this->assertSame('en_proceso', $import_status->status, 'el escenario pide que el lote 2 no cambie el status');

        $hace_dos_horas = Carbon::now()->subHours(2);

        DB::table('import_histories')->where('id', $import_history->id)->update(['updated_at' => $hace_dos_horas]);
        DB::table('import_statuses')->where('id', $import_status->id)->update(['updated_at' => $hace_dos_horas]);

        $al_arrancar = null;

        ArticleImportResult::created(function ($resultado) use (&$al_arrancar, $import_history, $import_status) {
            if (!is_null($al_arrancar)) {
                return;
            }

            $al_arrancar = [
                'history' => ImportHistory::find($import_history->id)->updated_at,
                'status'  => ImportStatus::find($import_status->id)->updated_at,
            ];
        });

        $lote_2->handle();

        $this->assertNotNull($al_arrancar, 'el lote 2 nunca creó su ArticleImportResult: el test no está probando lo que dice');

        $un_minuto_atras = Carbon::now()->subMinute();

        $this->assertTrue(
            $al_arrancar['history']->greaterThan($un_minuto_atras),
            'al arrancar el lote, import_histories.updated_at seguía en ' . $al_arrancar['history'] . ': el watchdog ve la espera en cola más el lote entero'
        );
        $this->assertTrue(
            $al_arrancar['status']->greaterThan($un_minuto_atras),
            'al arrancar el lote, import_statuses.updated_at seguía en ' . $al_arrancar['status']
        );
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
