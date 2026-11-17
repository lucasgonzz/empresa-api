<?php

namespace Tests\Feature\Listado;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\masivas\EjecucionPorTandasHelper as Tandas;
use App\Jobs\GenerateArticleEmbeddingJob;
use App\Jobs\ProcessSendAdviseMail;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EfectosDeTandasTest extends TestCase
{
    use DatabaseTransactions;

    public function test_jobs_y_progreso_no_salen_en_rollback_y_salen_una_vez_al_commit_real()
    {
        $default = config('database.default');
        $config = config('database.connections.' . $default);
        $config['options'][\PDO::ATTR_PERSISTENT] = false;
        config(['database.connections.efectos_tanda' => $config,
            'queue.connections.efectos_tanda' => ['driver' => 'database', 'connection' => $default,
                'table' => 'jobs', 'queue' => 'zz-efectos-tanda', 'retry_after' => 4200, 'after_commit' => false]]);
        $conexion = DB::connection('efectos_tanda');
        $proceso_id = null;
        Event::fake([BackgroundProcessUpdated::class]);
        $base = DB::table('jobs')->where('queue', 'zz-efectos-tanda')->count();
        try {
            foreach ([false, true] as $confirmar) {
                $conexion->beginTransaction();
                config(['database.default' => 'efectos_tanda']);
                // No se ejecuta ningún correo/embedding: se observa la cola database REAL.
                ProcessSendAdviseMail::dispatch(new User(['email' => 'prueba@test.local']), new Article(['id' => 1]))->onConnection('efectos_tanda');
                GenerateArticleEmbeddingJob::dispatch(1)->onConnection('efectos_tanda');
                $proceso = BackgroundProcessHelper::iniciar(500, 'actualizacion_masiva', 'zz efectos de tanda');
                $this->assertNotNull($proceso);
                Event::assertNotDispatched(BackgroundProcessUpdated::class);
                $this->assertSame($base, DB::connection($default)->table('jobs')->where('queue', 'zz-efectos-tanda')->count());
                if ($confirmar) {
                    $proceso_id = $proceso->id;
                    $conexion->commit();
                } else {
                    $conexion->rollBack();
                }
                config(['database.default' => $default]);
                if (!$confirmar) {
                    Event::assertNotDispatched(BackgroundProcessUpdated::class);
                    $this->assertSame($base, DB::table('jobs')->where('queue', 'zz-efectos-tanda')->count());
                }
            }
            $this->assertSame($base + 2, DB::table('jobs')->where('queue', 'zz-efectos-tanda')->count());
            Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);
        } finally {
            config(['database.default' => $default]);
            if ($conexion->transactionLevel() > 0) {
                $conexion->rollBack();
            }
            if ($proceso_id !== null) {
                $conexion->table('background_processes')->where('id', $proceso_id)->where('titulo', 'zz efectos de tanda')->delete();
            }
            DB::disconnect('efectos_tanda');
        }
    }

    public function test_cli_admite_pdo_persistente_y_limpia_los_candados_al_terminar()
    {
        $this->assertSame('cli', PHP_SAPI);
        $default = config('database.default');
        $config = config('database.connections.' . $default);
        $config['options'][\PDO::ATTR_PERSISTENT] = true;
        config(['database.connections.tanda_persistente' => $config]);
        $conexion = DB::connection('tanda_persistente');
        $conexion->beginTransaction();
        config(['database.default' => 'tanda_persistente']);
        Queue::fake();
        try {
            $this->assertTrue($conexion->getPdo()->getAttribute(\PDO::ATTR_PERSISTENT));
            $id = DB::table('articles')->insertGetId(['name' => 'zz persistente tanda', 'user_id' => 500, 'cost' => 100]);
            $masiva = MasiveUpdateHelper::create_pending_update(500, 500, 'article', false,
                ['resolved_models_id' => [$id], 'update_form' => [['type' => 'number', 'key' => 'increment_cost', 'value' => 10]]]);
            Tandas::ejecutar($masiva->id, 0, 0);
            $this->assertSame('completed', $masiva->fresh()->status);
            $this->assertEquals(110, Article::find($id)->cost);
            $this->assertNull(DB::selectOne('SELECT IS_USED_LOCK(?) AS ocupado', [Tandas::CLAVE_GLOBAL])->ocupado);
        } finally {
            $conexion->rollBack();
            config(['database.default' => $default]);
            DB::disconnect('tanda_persistente');
        }
    }
}
