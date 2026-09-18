<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\DeleteModelsHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Jobs\ProcessDeleteModelsJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * La actualización masiva, su reversión y la eliminación masiva dejan su rastro en el registro
 * de procesos en segundo plano (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * La masiva se dispara por el endpoint real (`PUT api/update/article`), igual que en
 * `tests/Feature/Listado/4_Masiva_de_costo_recalcula_y_revierte_Test`: con la cola `sync`,
 * ProcessMasiveUpdateJob corre inline dentro del request y al volver la fila ya está cerrada.
 * La eliminación se corre por el helper del camino en segundo plano, que es el único que abre
 * proceso (el camino síncrono del listado no lo hace a propósito).
 *
 * @group procesos-en-segundo-plano
 */
class Masiva_y_eliminacion_registran_su_avance_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();
    }

    /**
     * Artículo propio del test, con precio final calculado como en el formulario real.
     *
     * @param  string $nombre
     * @param  array  $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, array $atributos = [])
    {
        $provider = Provider::firstOrCreate([
            'name'    => 'zz Proveedor procesos masiva',
            'user_id' => $this->user_id,
        ]);

        $article = Article::create(array_merge([
            'name'            => $nombre,
            'user_id'         => $this->user_id,
            'provider_id'     => $provider->id,
            'cost'            => 1000,
            'percentage_gain' => 50,
        ], $atributos));

        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $this->user_id);

        return $article->fresh();
    }

    /** @test */
    public function una_masiva_por_el_endpoint_deja_la_fila_completada_con_los_registros_y_los_afectados()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $ids = [
            $this->crear_articulo('zz Procesos masiva A')->id,
            $this->crear_articulo('zz Procesos masiva B')->id,
            $this->crear_articulo('zz Procesos masiva C')->id,
        ];

        $response = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => $ids,
            'update_form' => [
                [
                    'type'  => 'number',
                    'key'   => 'increment_cost',
                    'value' => 5,
                ],
            ],
        ]);

        $response->assertStatus(200);

        $masive_update = MasiveUpdate::find($response->json('masive_update_id'));
        $this->assertNotNull($masive_update);
        $this->assertSame('completed', $masive_update->status, 'La masiva no terminó de procesarse.');

        $proceso = BackgroundProcessHelper::por_referencia($masive_update, false);

        $this->assertNotNull($proceso, 'La masiva no dejó ninguna fila en background_processes.');
        $this->assertSame('actualizacion_masiva', $proceso->tipo);
        $this->assertSame('Actualización masiva de artículos', $proceso->titulo);
        $this->assertSame('registros', $proceso->unidad);
        $this->assertSame(MasiveUpdate::class, $proceso->referencia_type);
        $this->assertSame($this->user_id, (int) $proceso->user_id);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id, 'Quien la lanzó es el usuario autenticado.');

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(3, (int) $proceso->total, 'El total son los registros resueltos por el criterio.');
        $this->assertSame(3, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertNotNull($proceso->finished_at);

        $resultado = $proceso->resultado();
        $this->assertSame((int) $masive_update->affected_count, (int) $resultado['afectados']);
        $this->assertSame((int) $masive_update->changes_count, (int) $resultado['cambios']);
        $this->assertSame(3, (int) $resultado['afectados'], 'Los tres cambiaron de costo.');

        /* Una sola fila por masiva. */
        $this->assertSame(1, BackgroundProcess::where('referencia_type', MasiveUpdate::class)
            ->where('referencia_id', $masive_update->id)
            ->count());

        /* Y revertirla abre SU propia fila, apuntando a la masiva de reversión. */
        $this->postJson('api/masive-update/' . $masive_update->id . '/revert')->assertStatus(200);

        $reversion = MasiveUpdate::where('parent_masive_update_id', $masive_update->id)
            ->where('action', 'revert')
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($reversion);
        $this->assertSame('completed', $reversion->status);

        $proceso_reversion = BackgroundProcessHelper::por_referencia($reversion, false);
        $this->assertNotNull($proceso_reversion, 'La reversión no dejó su fila.');
        $this->assertSame('reversion_masiva', $proceso_reversion->tipo);
        $this->assertSame('Reversión de una actualización masiva', $proceso_reversion->titulo);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso_reversion->status);
        $this->assertSame(3, (int) $proceso_reversion->total, 'El total de la reversión son los artículos del pivot.');
        $this->assertSame(100, (int) $proceso_reversion->porcentaje);
        $this->assertSame(3, (int) $proceso_reversion->resultado()['afectados']);
    }

    /** @test */
    public function mark_failed_deja_la_fila_en_fallo_con_el_mismo_motivo()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $masive_update = MasiveUpdateHelper::create_pending_update($this->user_id, $this->user_id, 'article', false, [
            'models_id'   => [$this->crear_articulo('zz Procesos masiva fallida')->id],
            'update_form' => [],
        ]);

        /*
         * create_pending_update() ya anunció la fila en `pendiente` (nace en el request, no
         * cuando el worker levanta el job): es la que mark_failed() tiene que cerrar.
         */
        $proceso = BackgroundProcessHelper::por_referencia($masive_update);
        $this->assertNotNull($proceso, 'Encolar la masiva no anunció ningún proceso.');
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $proceso->status);
        $this->assertSame('En espera del procesador', $proceso->etapa);

        MasiveUpdateHelper::mark_failed($masive_update, 'No se permitio actualizar 3500 registros');

        $this->assertSame('failed', $masive_update->fresh()->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertSame('No se permitio actualizar 3500 registros', $proceso->error_message);
        $this->assertNotNull($proceso->finished_at);
    }

    /** @test */
    public function una_masiva_rechazada_por_el_tope_queda_visible_como_fallida()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        /* Ids inventados en cantidad: resolve_models_from_criteria() sólo cuenta los que existen,
           así que se usan ids REALES repetidos, que find() resuelve todos. */
        $articulo = $this->crear_articulo('zz Procesos masiva tope');
        $ids = array_fill(0, 3000, $articulo->id);

        $masive_update = MasiveUpdateHelper::create_pending_update($this->user_id, $this->user_id, 'article', false, [
            'models_id'   => $ids,
            'update_form' => [],
        ]);

        $tiro = false;

        try {
            MasiveUpdateHelper::process_update($masive_update);
        } catch (\Exception $e) {
            $tiro = true;
            MasiveUpdateHelper::mark_failed($masive_update, $e->getMessage());
        }

        $this->assertTrue($tiro, 'El tope de 3000 no tiró.');

        /* El iniciar() va ANTES del tope: por eso mark_failed() encuentra la fila y el usuario ve por qué. */
        $proceso = BackgroundProcessHelper::por_referencia($masive_update, false);
        $this->assertNotNull($proceso, 'La masiva rechazada por tamaño no quedó visible.');
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('3000', $proceso->error_message);
    }

    /** @test */
    public function la_eliminacion_en_segundo_plano_deja_la_fila_completada_con_los_eliminados()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $ids = [
            $this->crear_articulo('zz Procesos eliminar 1')->id,
            $this->crear_articulo('zz Procesos eliminar 2')->id,
            $this->crear_articulo('zz Procesos eliminar 3')->id,
        ];

        /* Un id que ya no existe: se saltea, y no cuenta como eliminado. */
        $ids[] = 999999999;

        $antes = BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'eliminacion_masiva')->max('id');

        $job = new ProcessDeleteModelsJob('article', $ids, $this->user_id, $this->user_id, []);
        $job->handle();

        $proceso = BackgroundProcess::where('user_id', $this->user_id)
            ->where('tipo', 'eliminacion_masiva')
            ->where('id', '>', (int) $antes)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($proceso, 'La eliminación no dejó ninguna fila en background_processes.');
        $this->assertSame('Eliminación de artículos', $proceso->titulo);
        $this->assertSame('registros', $proceso->unidad);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(4, (int) $proceso->total, 'El total son los ids recibidos.');
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame(3, (int) $proceso->resultado()['eliminados'], 'El que no existía no se cuenta.');

        foreach (array_slice($ids, 0, 3) as $id) {
            $this->assertNull(Article::find($id), 'El artículo ' . $id . ' sigue existiendo.');
        }

        /*
         * Ojo al leer este test: process_background_delete() termina con clear_auth_context()
         * (Auth::logout), que en el test desloguea también al usuario de actingAs(). Es
         * comportamiento previo del helper, no de esta misión; por eso acá no se vuelve a
         * pegar a ningún endpoint después de correr el job.
         */
    }

    /** @test */
    public function la_eliminacion_sincronica_del_listado_no_abre_proceso()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $id = $this->crear_articulo('zz Procesos eliminar sincronico')->id;

        $antes = (int) BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'eliminacion_masiva')->count();

        $resultado = DeleteModelsHelper::process_delete('article', [$id], false);

        $this->assertSame(1, (int) $resultado['deleted_count']);
        $this->assertSame($antes, (int) BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'eliminacion_masiva')->count());
        Event::assertNotDispatched(BackgroundProcessUpdated::class);
    }

    /** @test */
    public function un_borrado_que_revienta_deja_la_fila_en_fallo_por_el_catch_del_job()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        /* Un usuario que no existe: process_background_delete() tira antes de borrar nada. */
        $antes = BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'eliminacion_masiva')->max('id');

        $job = new ProcessDeleteModelsJob('article', [1, 2, 3], $this->user_id, 999999999, []);
        $job->handle();

        $proceso = BackgroundProcess::where('user_id', $this->user_id)
            ->where('tipo', 'eliminacion_masiva')
            ->where('id', '>', (int) $antes)
            ->orderBy('id', 'DESC')
            ->first();

        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('Usuario autenticado no encontrado', $proceso->error_message);

        /* failed() en un proceso fresco (sin el estático) lo encuentra igual y no lo pisa. */
        $job->failed(new \Exception('otro motivo'));
        $this->assertStringContainsString('Usuario autenticado no encontrado', BackgroundProcess::find($proceso->id)->error_message);
    }

    /** @test */
    public function la_eliminacion_por_el_endpoint_se_anuncia_al_encolar_y_el_job_retoma_la_misma_fila()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);
        Queue::fake();

        $ids = [
            $this->crear_articulo('zz Procesos eliminar endpoint 1')->id,
            $this->crear_articulo('zz Procesos eliminar endpoint 2')->id,
        ];

        $antes = (int) BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'eliminacion_masiva')->max('id');

        $this->putJson('api/delete/article', [
            'from_filter' => 0,
            'models_id'   => $ids,
        ])->assertStatus(200);

        /*
         * Con la cola falsa el job no corrió: lo que existe es lo que el controller anunció al
         * encolar, en `pendiente`. Es lo que el usuario ve en el minuto que el worker del shared
         * hosting tarda en levantar el job.
         */
        $pendiente = BackgroundProcess::where('user_id', $this->user_id)
            ->where('tipo', 'eliminacion_masiva')
            ->where('id', '>', $antes)
            ->first();

        $this->assertNotNull($pendiente, 'Encolar el borrado no anunció ningún proceso.');
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $pendiente->status);
        $this->assertSame(2, (int) $pendiente->total);
        $this->assertSame($this->user_id, (int) $pendiente->auth_user_id);

        /*
         * El job, tal como lo encoló el controller, corre en el worker (contexto de consola,
         * como en producción: adentro del request el guard de Sanctum no tiene loginUsingId).
         * Tiene que retomar la MISMA fila, no abrir otra.
         */
        $jobs = Queue::pushed(ProcessDeleteModelsJob::class);
        $this->assertCount(1, $jobs);

        /*
         * El request de arriba dejó al guard de Sanctum (RequestGuard) como guard activo, y
         * setup_auth_context() usa loginUsingId(), que solo tiene el SessionGuard `web`: es
         * exactamente el guard que hay en el worker. Se vuelve a ese antes de correr el job.
         */
        Auth::shouldUse('web');
        $jobs->first()->handle();

        $procesos = BackgroundProcess::where('user_id', $this->user_id)
            ->where('tipo', 'eliminacion_masiva')
            ->where('id', '>', $antes)
            ->get();

        $this->assertCount(1, $procesos, 'El endpoint y el job abrieron filas distintas.');
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $procesos->first()->status, 'Motivo: ' . $procesos->first()->error_message);
        $this->assertSame(2, (int) $procesos->first()->total);
        $this->assertSame(2, (int) $procesos->first()->resultado()['eliminados']);
    }
}
