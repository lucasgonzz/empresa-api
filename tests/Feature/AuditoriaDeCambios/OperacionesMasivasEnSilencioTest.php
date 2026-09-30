<?php

namespace Tests\Feature\AuditoriaDeCambios;

use App\Jobs\ProcessDeleteModelsJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\MasiveUpdate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use App\Events\BackgroundProcessUpdated;

/**
 * Test 8 del plan: una operación masiva deja UNA fila de operación y no una por artículo (misión
 * auditoria-de-cambios, 30/9/2026, decisión de Lucas).
 *
 * El silencio se decide por clase de job en los eventos de la cola, sin tocar ningún job. Se
 * prueba de tres formas: con un job de prueba (mecanismo), con el job REAL de actualización masiva
 * por el endpoint (`PUT api/update/article`, cola `sync` en los tests) y con
 * `ProcessDeleteModelsJob`, que a propósito NO se silencia: borrar es irreversible y el detalle de
 * qué se borró importa más que el volumen.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class OperacionesMasivasEnSilencioTest extends AuditoriaTestCase
{
    /**
     * El job masivo no deja filas por artículo, pero sí la de su BackgroundProcess (solo creación).
     *
     * @return void
     */
    public function test_un_job_masivo_no_deja_filas_por_articulo_pero_si_la_fila_de_la_operacion()
    {
        config(['audit_log.jobs_masivos' => [AuditoriaTrabajoMasivoDePrueba::class => 'prueba']]);

        AuditoriaTrabajoMasivoDePrueba::dispatch($this->dueno->id, 5);

        $this->assertSame(5, Article::where('name', 'like', 'ZZ Auditoria masivo %')->count(), 'El job tenía que guardar sus cinco artículos.');

        $this->assertSame(0, $this->filas(Article::class)->count(), 'Cero filas por artículo: la operación masiva está en silencio.');

        $creados = $this->filas(BackgroundProcess::class, 'created');
        $this->assertCount(1, $creados, 'La fila de la operación SÍ se audita.');
        $this->assertSame('queue', $creados->first()->source);
        $this->assertSame(AuditoriaTrabajoMasivoDePrueba::class, $creados->first()->origin);

        $this->assertSame(
            0,
            $this->filas(BackgroundProcess::class, 'updated')->count(),
            'De BackgroundProcess solo se audita la creación: sus actualizaciones son el avance de la barra.'
        );
    }

    /**
     * El silencio termina con el job: lo que se guarda después vuelve a auditarse.
     *
     * @return void
     */
    public function test_al_terminar_el_job_el_silencio_se_levanta()
    {
        config(['audit_log.jobs_masivos' => [AuditoriaTrabajoMasivoDePrueba::class => 'prueba']]);

        AuditoriaTrabajoMasivoDePrueba::dispatch($this->dueno->id, 2);

        $this->assertSame(0, $this->filas(Article::class)->count());

        $this->crear_articulo(['name' => 'ZZ Auditoria despues del job']);

        $this->assertCount(1, $this->filas(Article::class, 'created'), 'Terminado el job, el silencio se levanta.');
    }

    /**
     * Un job que NO está en jobs_masivos se audita normal (y con source=queue).
     *
     * @return void
     */
    public function test_un_job_comun_se_audita_con_source_queue()
    {
        config(['audit_log.jobs_masivos' => []]);

        AuditoriaTrabajoMasivoDePrueba::dispatch($this->dueno->id, 2);

        // Dos artículos, cada uno con su created y su updated de costo.
        $this->assertCount(2, $this->filas(Article::class, 'created'));
        $this->assertCount(2, $this->filas(Article::class, 'updated'));

        foreach ($this->filas(Article::class) as $fila) {
            $this->assertSame('queue', $fila->source);
            $this->assertSame(AuditoriaTrabajoMasivoDePrueba::class, $fila->origin);
            $this->assertNull($fila->ip);
        }

        // Todo el job comparte un lote.
        $this->assertCount(1, $this->filas()->pluck('batch_uuid')->unique());
    }

    /**
     * El job REAL de actualización masiva, por el endpoint: una fila de MasiveUpdate por operación
     * y ninguna por artículo.
     *
     * @return void
     */
    public function test_la_actualizacion_masiva_real_deja_su_fila_y_ninguna_por_articulo()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $ids = [];

        foreach ([1, 2, 3] as $n) {
            $ids[] = $this->crear_articulo(['name' => 'ZZ Auditoria masiva real ' . $n, 'cost' => 1000])->id;
        }

        $desde = (int) \App\Models\AuditLog::max('id');

        $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => $ids,
            'update_form' => [
                ['type' => 'number', 'key' => 'increment_cost', 'value' => 5],
            ],
        ])->assertStatus(200);

        // El job corrió (cola sync) y cambió el costo de los tres.
        $this->assertEquals(1050, (float) Article::find($ids[0])->cost);

        $filas = \App\Models\AuditLog::where('id', '>', $desde)->get();

        $this->assertSame(
            0,
            $filas->where('auditable_type', Article::class)->whereIn('event', ['updated', 'created', 'deleted'])
                ->filter(function ($fila) {
                    return $fila->source === 'queue';
                })->count(),
            'Los artículos que toca el job masivo no dejan filas.'
        );

        $this->assertGreaterThanOrEqual(1, $filas->where('auditable_type', MasiveUpdate::class)->where('event', 'created')->count(), 'La fila de la operación (MasiveUpdate) sí queda.');
        $this->assertGreaterThanOrEqual(1, $filas->where('auditable_type', MasiveUpdate::class)->where('event', 'updated')->count(), 'Y su cierre (status completed) también.');
    }

    /**
     * ProcessDeleteModelsJob NO se silencia: cada artículo borrado deja su fila `deleted`.
     *
     * @return void
     */
    public function test_la_eliminacion_masiva_no_se_silencia_y_deja_el_detalle_de_lo_borrado()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $ids = [];

        foreach ([1, 2, 3] as $n) {
            $ids[] = $this->crear_articulo(['name' => 'ZZ Auditoria borrado masivo ' . $n])->id;
        }

        $desde = (int) \App\Models\AuditLog::max('id');

        ProcessDeleteModelsJob::dispatch('article', $ids, $this->dueno->id, $this->dueno->id, []);

        foreach ($ids as $id) {
            $this->assertNull(Article::find($id), 'El job tenía que borrar el artículo ' . $id . '.');
        }

        $borrados = \App\Models\AuditLog::where('id', '>', $desde)
            ->where('auditable_type', Article::class)
            ->where('event', 'deleted')
            ->whereIn('auditable_id', $ids)
            ->get();

        $this->assertCount(3, $borrados, 'Borrar es irreversible: el detalle de cada baja queda.');

        foreach ($borrados as $fila) {
            $this->assertSame('queue', $fila->source);
            $this->assertSame(ProcessDeleteModelsJob::class, $fila->origin);
            $this->assertStringStartsWith('ZZ Auditoria borrado masivo', json_decode($fila->old_values, true)['name']);
        }
    }
}
