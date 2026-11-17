<?php

namespace Tests\Feature\Listado;

use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\masivas\EjecucionPorTandasHelper as Tandas;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Models\Article;
use App\Models\MasiveUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MasivasPorTandasTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;
    private $cola_real;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::findOrFail(500);
        $this->actingAs($this->dueno, 'web');
        $this->cola_real = Queue::getFacadeRoot();
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-17 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function articulos($cantidad): array
    {
        $ids = [];
        for ($i = 0; $i < $cantidad; $i++) {
            $ids[] = DB::table('articles')->insertGetId(['name' => 'zz tanda ' . bin2hex(random_bytes(5)),
                'user_id' => $this->dueno->id, 'cost' => 100, 'percentage_gain' => 10, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()]);
        }
        return $ids;
    }

    private function masiva(array $ids, array $formularios = null)
    {
        return MasiveUpdateHelper::create_pending_update($this->dueno->id, $this->dueno->id, 'article', false,
            ['resolved_models_id' => $ids, 'used_filters' => [['key' => 'Seleccion manual']],
                'update_form' => $formularios ?? [['type' => 'number', 'key' => 'increment_cost', 'value' => 10]]]);
    }

    private function continuar($masiva)
    {
        $estado = Tandas::estado($masiva->fresh());
        Tandas::ejecutar($masiva->id, $estado['cursor'], $estado['generacion']);
    }

    public function test_123_se_procesan_50_50_23_con_pausa_y_las_entregas_viejas_no_reaplican()
    {
        $ids = $this->articulos(123);
        $masiva = $this->masiva($ids);
        $consultas_de_articulos = [];
        DB::listen(function ($consulta) use (&$consultas_de_articulos) {
            if (stripos($consulta->sql, 'select * from `articles`') !== false) {
                $consultas_de_articulos[] = $consulta;
            }
        });
        $this->continuar($masiva);
        $this->assertSame(50, Tandas::estado($masiva->fresh())['cursor']);
        $this->assertSame(50, (int) $masiva->fresh()->affected_count);
        $this->assertSame(50, DB::table('articles')->whereIn('id', $ids)->where('cost', 110)->count());
        $this->assertSame(73, DB::table('articles')->whereIn('id', $ids)->where('cost', 100)->count());
        $this->assertLessThanOrEqual(51, count($consultas_de_articulos[0]->bindings)); // 50 IDs y el dueño.
        $this->assertSame('processing', $masiva->fresh()->status);
        Tandas::ejecutar($masiva->id, 0, 0);
        $this->continuar($masiva); // Prematuro: ni cursor ni porcentaje cambian.
        $this->assertSame(50, Tandas::estado($masiva->fresh())['cursor']);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->continuar($masiva);
        $this->assertSame(100, Tandas::estado($masiva->fresh())['cursor']);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->continuar($masiva);
        $this->assertSame('completed', $masiva->fresh()->status);
        $this->assertSame(123, (int) $masiva->fresh()->affected_count);
        $this->assertSame(123, $masiva->articles()->count());
        $this->assertSame(123, DB::table('articles')->whereIn('id', $ids)->where('cost', 110)->count());
        $this->assertSame(123, DB::table('background_processes')->where('referencia_id', $masiva->id)
            ->where('referencia_type', MasiveUpdate::class)->value('procesados'));
        Tandas::ejecutar($masiva->id, 100, 2);
        $this->assertSame(123, $masiva->articles()->count());
    }

    public function test_un_fallo_en_la_segunda_tanda_revierte_solo_esa_tanda_y_deja_historial_revertible()
    {
        $ids = $this->articulos(65);
        $masiva = $this->masiva($ids);
        $this->continuar($masiva);
        $escuchador = function ($articulo) use ($ids) {
            if ((int) $articulo->id === (int) $ids[54]) {
                throw new \RuntimeException('Fallo de prueba durante la segunda tanda');
            }
        };
        $dispatcher_original = Article::getEventDispatcher();
        Article::setEventDispatcher(clone $dispatcher_original);
        Article::updating($escuchador);
        try {
            Carbon::setTestNow(now()->addSeconds(5));
            $this->continuar($masiva);
        } finally {
            // Restaurar listeners del modelo sin borrar los observadores del resto de la suite.
            Article::setEventDispatcher($dispatcher_original);
        }
        $this->assertSame('failed', $masiva->fresh()->status);
        $this->assertStringContainsString('Fallo de prueba', $masiva->fresh()->error_message);
        $this->assertSame(50, Tandas::estado($masiva->fresh())['cursor']);
        $this->assertSame(50, $masiva->articles()->count());
        $this->assertSame(50, DB::table('articles')->whereIn('id', $ids)->where('cost', 110)->count());
        $this->assertSame(15, DB::table('articles')->whereIn('id', $ids)->where('cost', 100)->count());
        $this->assertTrue(MasiveUpdateHelper::can_revert($masiva->fresh()));
        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $this->dueno->id);
        $this->continuar($reversion);
        $this->assertSame('completed', $reversion->fresh()->status);
        $this->assertSame(65, DB::table('articles')->whereIn('id', $ids)->where('cost', 100)->count());
    }

    public function test_no_incorpora_articulos_nuevos_ni_ajenos_y_avanza_si_un_seleccionado_se_borro()
    {
        $ids = $this->articulos(2);
        $masiva = $this->masiva($ids);
        DB::table('articles')->where('id', $ids[0])->update(['deleted_at' => now()]);
        $nuevo = $this->articulos(1)[0];
        $otro_dueno = User::create(['name' => 'zz otro', 'email' => uniqid() . '@test.local', 'password' => 'prueba']);
        $ajeno = DB::table('articles')->insertGetId(['name' => 'zz ajeno', 'user_id' => $otro_dueno->id, 'cost' => 100]);
        $criterio = json_decode($masiva->criteria_json, true);
        $criterio['resolved_models_id'][] = $ajeno;
        $masiva->criteria_json = json_encode($criterio);
        $masiva->save();
        $this->continuar($masiva);
        $this->assertSame(3, Tandas::estado($masiva->fresh())['cursor']);
        $this->assertSame(1, (int) $masiva->fresh()->affected_count);
        $this->assertEquals(100, DB::table('articles')->where('id', $nuevo)->value('cost'));
        $this->assertEquals(100, DB::table('articles')->where('id', $ajeno)->value('cost'));
    }

    public function test_admision_maximo_dos_y_sync_rechazado_sin_crear_operacion()
    {
        $ids = $this->articulos(1);
        $crear = function () use ($ids) { return $this->masiva($ids); };
        $this->assertSame(200, Tandas::encolar($this->dueno->id, $crear)['status']);
        $this->assertSame(200, Tandas::encolar($this->dueno->id, $crear)['status']);
        $this->assertSame(429, Tandas::encolar($this->dueno->id, $crear)['status']);
        Queue::swap($this->cola_real);
        config(['queue.default' => 'sync']);
        $cantidad = MasiveUpdate::count();
        $this->assertSame(503, Tandas::encolar($this->dueno->id, $crear)['status']);
        $this->assertSame($cantidad, MasiveUpdate::count());
    }

    public function test_reconciliacion_tiene_cadencia_techo_durable_y_vencimiento_visible()
    {
        $masiva = $this->masiva($this->articulos(1));
        $this->assertSame(0, Tandas::reconciliar());
        Carbon::setTestNow(now()->addSeconds(120));
        $this->assertSame(1, Tandas::reconciliar());
        $this->assertSame(0, Tandas::reconciliar());
        Carbon::setTestNow(now()->addSeconds(120));
        $this->assertSame(1, Tandas::reconciliar());
        Carbon::setTestNow(now()->addSeconds(60));
        $this->assertSame(0, Tandas::reconciliar());
        $this->assertSame('pending', $masiva->fresh()->status, 'La tercera entrega conserva su ventana de gracia.');
        Carbon::setTestNow(now()->addSeconds(60));
        $this->assertSame(0, Tandas::reconciliar());
        $this->assertSame('failed', $masiva->fresh()->status);
        Queue::assertPushed(ProcessMasiveUpdateJob::class, 2);
        $this->assertEquals(100, Article::find(json_decode($masiva->criteria_json, true)['resolved_models_id'][0])->cost);
    }

    public function test_un_job_legacy_processing_no_se_reaplica_y_un_pending_con_snapshot_se_inicia()
    {
        $masiva = $this->masiva($this->articulos(1));
        $criterio = json_decode($masiva->criteria_json, true);
        unset($criterio['tandas']);
        $masiva->criteria_json = json_encode($criterio);
        $masiva->save();
        Tandas::ejecutar($masiva->id);
        $this->assertSame('completed', $masiva->fresh()->status);
        $otra = $this->masiva($this->articulos(1));
        $criterio = json_decode($otra->criteria_json, true);
        unset($criterio['tandas']);
        $otra->criteria_json = json_encode($criterio);
        $otra->status = 'processing';
        $otra->save();
        Tandas::ejecutar($otra->id);
        $this->assertSame('processing', $otra->fresh()->status);
        Carbon::setTestNow(now()->addSeconds(3601));
        Tandas::ejecutar($otra->id);
        $this->assertSame('failed', $otra->fresh()->status);
        $this->assertSame(0, $otra->articles()->count());
    }

    public function test_el_candado_global_otorgado_a_otra_pdo_impide_tanda_y_luego_se_libera()
    {
        $config = config('database.connections.' . config('database.default'));
        // Otro esquema del mismo mysqld, sin leer ni escribir datos de otro negocio.
        $config['database'] = 'information_schema';
        $config['options'][\PDO::ATTR_PERSISTENT] = false;
        config(['database.connections.tandas_segunda_pdo' => $config]);
        $otra = DB::connection('tandas_segunda_pdo')->getPdo();
        $stmt = $otra->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([Tandas::CLAVE_GLOBAL]);
        $this->assertEquals(1, $stmt->fetchColumn());
        $masiva = $this->masiva($this->articulos(1));
        try {
            $this->continuar($masiva);
            $this->assertSame(0, Tandas::estado($masiva->fresh())['cursor']);
        } finally {
            $stmt = $otra->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([Tandas::CLAVE_GLOBAL]);
            DB::disconnect('tandas_segunda_pdo');
        }
        Carbon::setTestNow(now()->addSeconds(5));
        $this->continuar($masiva);
        $this->assertSame('completed', $masiva->fresh()->status);
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS ocupado', [Tandas::CLAVE_GLOBAL])->ocupado);
    }

    public function test_seleccion_manual_duplicada_no_repite_incrementos()
    {
        $id = $this->articulos(1)[0];
        $respuesta = MasiveUpdateHelper::encolar_actualizacion('article', false, [],
            [['type' => 'number', 'key' => 'increment_cost', 'value' => 10]], [$id, (string) $id, $id],
            $this->dueno->id, $this->dueno->id);
        $this->assertSame(200, $respuesta['status']);
        $masiva = MasiveUpdate::find($respuesta['body']['masive_update_id']);
        $this->assertSame([$id], json_decode($masiva->criteria_json, true)['resolved_models_id']);
        $this->continuar($masiva);
        $this->assertEquals(110, Article::find($id)->cost);
    }

    public function test_failed_de_entrega_vieja_no_cancela_una_generacion_nueva()
    {
        $masiva = $this->masiva($this->articulos(60));
        $viejo = new ProcessMasiveUpdateJob($masiva->id, 0, 0);
        $this->continuar($masiva);
        $viejo->failed(new \RuntimeException('Timeout de entrega anterior'));
        $this->assertSame('processing', $masiva->fresh()->status);
        $this->assertSame(50, Tandas::estado($masiva->fresh())['cursor']);
    }

    public function test_una_reversion_parcial_fallida_no_se_puede_reiniciar_desde_cero()
    {
        $masiva = $this->masiva($this->articulos(60));
        $this->continuar($masiva);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->continuar($masiva);
        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $this->dueno->id);
        $this->continuar($reversion);
        $estado = Tandas::estado($reversion->fresh());
        Tandas::fallar_si_vigente($reversion->id, $estado['cursor'], $estado['generacion'], 'Fallo posterior a primera tanda');
        $this->assertSame(50, $reversion->articles()->count());
        $this->assertFalse(MasiveUpdateHelper::can_revert($masiva->fresh()));
    }

    public function test_reconciliador_aisla_checkpoint_invalido_y_respeta_legacy_processing_fresco()
    {
        $invalida = $this->masiva($this->articulos(1));
        $criterio = json_decode($invalida->criteria_json, true);
        $criterio['resolved_models_id'][] = $criterio['resolved_models_id'][0];
        $invalida->criteria_json = json_encode($criterio);
        $invalida->save();
        $valida = $this->masiva($this->articulos(1));
        $legacy = $this->masiva($this->articulos(1));
        $criterio_legacy = json_decode($legacy->criteria_json, true);
        unset($criterio_legacy['tandas']);
        $legacy->criteria_json = json_encode($criterio_legacy);
        $legacy->status = 'processing';
        $legacy->save();
        Carbon::setTestNow(now()->addSeconds(120));
        $this->assertSame(1, Tandas::reconciliar());
        $this->assertSame('failed', $invalida->fresh()->status);
        $this->assertSame('pending', $valida->fresh()->status);
        $this->assertSame('processing', $legacy->fresh()->status);
    }

    public function test_la_contencion_real_no_gasta_el_presupuesto_de_entregas_perdidas()
    {
        $masiva = $this->masiva($this->articulos(1));
        $config = config('database.connections.' . config('database.default'));
        $config['options'][\PDO::ATTR_PERSISTENT] = false;
        config(['database.connections.tandas_ocupadas' => $config]);
        $otra = DB::connection('tandas_ocupadas')->getPdo();
        $stmt = $otra->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([Tandas::CLAVE_GLOBAL]);
        try {
            for ($i = 0; $i < 5; $i++) {
                $this->continuar($masiva);
                $estado = Tandas::estado($masiva->fresh());
                $this->assertSame(0, $estado['cursor']);
                $this->assertSame(1, $estado['despachos']);
                Carbon::setTestNow(now()->addSeconds(5));
            }
            $this->assertSame('pending', $masiva->fresh()->status);
        } finally {
            $stmt = $otra->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([Tandas::CLAVE_GLOBAL]);
            DB::disconnect('tandas_ocupadas');
        }
        $this->continuar($masiva);
        $this->assertSame('completed', $masiva->fresh()->status);
    }

    public function test_un_lock_filtrado_de_la_misma_pdo_no_se_adquiere_reentrantemente()
    {
        $masiva = $this->masiva($this->articulos(1));
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([Tandas::CLAVE_GLOBAL]);
        try {
            $this->continuar($masiva);
            $this->assertSame('failed', $masiva->fresh()->status);
            $this->assertTrue(app('queue.worker')->shouldQuit);
        } finally {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $stmt->execute([Tandas::CLAVE_GLOBAL]);
            $propiedad = new \ReflectionProperty(Tandas::class, 'procesador_inservible');
            $propiedad->setAccessible(true);
            $propiedad->setValue(null, false);
            app('queue.worker')->shouldQuit = false;
        }
        $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS ocupado', [Tandas::CLAVE_GLOBAL])->ocupado);
    }

    public function test_stock_y_movimientos_son_atomicos_y_no_se_duplican_por_reentrega()
    {
        $ids = $this->articulos(2);
        DB::table('articles')->whereIn('id', $ids)->update(['stock' => 10]);
        $masiva = $this->masiva($ids, [['type' => 'number', 'key' => 'increment_stock', 'value' => 10]]);
        $antes = DB::table('stock_movements')->whereIn('article_id', $ids)->count();
        $this->continuar($masiva);
        $this->assertSame('completed', $masiva->fresh()->status);
        $this->assertSame(2, DB::table('articles')->whereIn('id', $ids)->where('stock', 11)->count());
        $this->assertSame($antes + 2, DB::table('stock_movements')->whereIn('article_id', $ids)->count());
        Tandas::ejecutar($masiva->id, 0, 0);
        $this->assertSame($antes + 2, DB::table('stock_movements')->whereIn('article_id', $ids)->count());
    }

    public function test_release_fallido_conserva_el_commit_y_retira_el_worker_sin_repetir_la_escritura()
    {
        $ids = $this->articulos(1);
        $masiva = $this->masiva($ids);
        $conexion = DB::connection();
        $original = $conexion->getPdo();
        $pdo = \Mockery::mock(\PDO::class);
        $pdo->shouldReceive('prepare')->andReturnUsing(function ($sql, ...$resto) use ($original) {
            if (strpos($sql, 'RELEASE_LOCK') !== false) {
                throw new \RuntimeException('Liberación interrumpida de prueba');
            }
            return $original->prepare($sql, ...$resto);
        });
        foreach (['query', 'exec', 'lastInsertId', 'inTransaction'] as $metodo) {
            $pdo->shouldReceive($metodo)->andReturnUsing(function (...$argumentos) use ($original, $metodo) {
                return $original->{$metodo}(...$argumentos);
            });
        }
        $nivel = $conexion->transactionLevel();
        $nivel_interno = new \ReflectionProperty(\Illuminate\Database\Connection::class, 'transactions');
        $nivel_interno->setAccessible(true);
        $conexion->setPdo($pdo);
        $nivel_interno->setValue($conexion, $nivel);
        try {
            $this->continuar($masiva);
            $this->assertSame('completed', $masiva->fresh()->status);
            $this->assertEquals(110, Article::find($ids[0])->cost);
            $this->assertSame(1, $masiva->articles()->count());
            $this->assertTrue(app('queue.worker')->shouldQuit);
        } finally {
            $conexion->setPdo($original);
            $nivel_interno->setValue($conexion, $nivel);
            foreach ([Tandas::CLAVE_GLOBAL, 'cc:masivas:v2:dueno:' . sha1($conexion->getDatabaseName() . ':' . $masiva->user_id)] as $nombre) {
                $stmt = $original->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([$nombre]);
            }
            $propiedad = new \ReflectionProperty(Tandas::class, 'procesador_inservible');
            $propiedad->setAccessible(true);
            $propiedad->setValue(null, false);
            app('queue.worker')->shouldQuit = false;
        }
    }

    public function test_legacy_alias_de_articulo_usa_tandas_y_no_el_trabajo_entero()
    {
        $masiva = $this->masiva($this->articulos(60));
        $criterio = json_decode($masiva->criteria_json, true);
        unset($criterio['tandas']);
        $masiva->criteria_json = json_encode($criterio);
        $masiva->model_name = 'a_r-ticle';
        $masiva->save();
        $job = new ProcessMasiveUpdateJob($masiva->id);
        $this->assertSame(120, $job->timeout);
        $job->handle();
        $this->assertSame('article', $masiva->fresh()->model_name);
        $this->assertSame('processing', $masiva->fresh()->status);
        $this->assertSame(50, Tandas::estado($masiva->fresh())['cursor']);
    }

    public function test_segunda_operacion_espera_al_dueno_incluso_entre_tandas_y_precheck_no_busca_ids()
    {
        $ids = $this->articulos(60);
        $primera = $this->masiva($ids);
        $segunda = $this->masiva($ids, [['type' => 'number', 'key' => 'set_cost', 'value' => 200]]);
        $consultas_de_articulos = 0;
        DB::listen(function ($query) use (&$consultas_de_articulos) {
            if (stripos($query->sql, 'from `articles`') !== false) {
                $consultas_de_articulos++;
            }
        });
        $respuesta = MasiveUpdateHelper::encolar_actualizacion('Article', false, [], [], $ids, $this->dueno->id, $this->dueno->id);
        $this->assertSame(429, $respuesta['status']);
        $this->assertSame(0, $consultas_de_articulos);
        $this->continuar($primera);
        $this->continuar($segunda);
        $this->assertSame(0, Tandas::estado($segunda->fresh())['cursor']);
        Carbon::setTestNow(now()->addSeconds(5));
        $this->continuar($primera);
        $this->assertSame('completed', $primera->fresh()->status);
        $this->continuar($segunda);
        $this->assertSame(50, Tandas::estado($segunda->fresh())['cursor']);
        $this->assertSame(50, DB::table('articles')->whereIn('id', $ids)->where('cost', 200)->count());
    }

    public function test_un_checkpoint_con_espera_futura_no_oculta_el_vencimiento()
    {
        $masiva = $this->masiva($this->articulos(1));
        $criterio = json_decode($masiva->criteria_json, true);
        $criterio['tandas']['no_antes'] = $criterio['tandas']['deadline'] + 5;
        $masiva->criteria_json = json_encode($criterio);
        $masiva->save();
        Carbon::setTestNow(now()->addSeconds(7201));
        $this->assertSame(0, Tandas::reconciliar());
        $this->assertSame('failed', $masiva->fresh()->status);
        $this->assertSame(0, $masiva->articles()->count());
    }
}
