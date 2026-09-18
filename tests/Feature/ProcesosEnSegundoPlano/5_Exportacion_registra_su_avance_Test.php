<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\ExportHistoryHelper;
use App\Http\Controllers\Helpers\jobs\BackgroundJobFailureHandler;
use App\Jobs\ProcessProviderExportJob;
use App\Models\BackgroundProcess;
use App\Models\ExportHistory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Maatwebsite\Excel\Facades\Excel;
use Tests\EmpresaTestCase;

/**
 * Las exportaciones dejan su rastro en el registro de procesos en segundo plano (misión
 * procesos-en-segundo-plano, 18/9/2026).
 *
 * Una exportación no tiene unidades que contar, así que su fila es de las "indeterminadas":
 * lo que se protege acá son los tres estados por los que pasa —`pendiente` cuando se creó el
 * historial en el request, `en_proceso` cuando un worker levantó el job, y el cierre con el
 * link de descarga o con el error— y que cada uno lo escriba quien corresponde.
 *
 * @group procesos-en-segundo-plano
 */
class Exportacion_registra_su_avance_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();
    }

    /** @test */
    public function create_pending_abre_la_fila_en_pendiente_y_sin_total()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $export_history = ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'client');

        $proceso = BackgroundProcessHelper::por_referencia($export_history);

        $this->assertNotNull($proceso, 'create_pending() no dejó ninguna fila en background_processes.');
        $this->assertSame('exportacion', $proceso->tipo);
        $this->assertSame('Exportación de clientes', $proceso->titulo);
        $this->assertSame(ExportHistory::class, $proceso->referencia_type);
        $this->assertSame($export_history->id, (int) $proceso->referencia_id);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id);

        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $proceso->status);
        $this->assertSame('En espera del procesador', $proceso->etapa);
        $this->assertNull($proceso->total, 'Generar un Excel no tiene unidades: la barra es indeterminada.');
        $this->assertNull($proceso->porcentaje);

        Event::assertDispatched(BackgroundProcessUpdated::class, function ($evento) use ($proceso) {
            return $evento->proceso['id'] === $proceso->id && $evento->proceso['status'] === 'pendiente';
        });
    }

    /** @test */
    public function mark_completed_cierra_la_fila_con_el_link_de_descarga()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $export_history = ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'article');
        $proceso = BackgroundProcessHelper::por_referencia($export_history);

        $link = ExportHistoryHelper::mark_completed($export_history, 'comerciocity-articulos_test.xlsx', 120);

        $this->assertSame('completed', $export_history->fresh()->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertNotNull($proceso->finished_at);

        $resultado = $proceso->resultado();
        $this->assertSame($link, $resultado['link']);
        $this->assertSame($export_history->fresh()->excel_url, $resultado['link'], 'El link del registro es el mismo que guardó el historial.');
        $this->assertSame('comerciocity-articulos_test.xlsx', $resultado['archivo']);
        $this->assertSame(120, (int) $resultado['exportados']);
    }

    /** @test */
    public function mark_failed_deja_la_fila_en_fallo_y_el_handler_de_fallos_no_la_reabre()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $export_history = ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'provider');
        $proceso = BackgroundProcessHelper::por_referencia($export_history);

        ExportHistoryHelper::mark_failed($export_history, 'Se quedó sin memoria');

        $this->assertSame('failed', $export_history->fresh()->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertSame('Se quedó sin memoria', $proceso->error_message);

        /* El failed() del job llega después por el mismo historial: ya está en failed, no toca nada. */
        BackgroundJobFailureHandler::marcar_export_fallido(
            $export_history->id,
            $this->user_id,
            $this->user_id,
            'No se pudo generar el excel de proveedores',
            'otro motivo'
        );

        $this->assertSame('Se quedó sin memoria', BackgroundProcess::find($proceso->id)->error_message);
    }

    /** @test */
    public function el_job_saca_la_fila_de_pendiente_al_arrancar_y_la_cierra_con_el_link()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);
        Excel::fake();

        $export_history = ExportHistoryHelper::create_pending($this->user_id, $this->user_id, 'provider');
        $proceso = BackgroundProcessHelper::por_referencia($export_history);
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $proceso->status);

        $job = new ProcessProviderExportJob($this->user_id, $this->user_id, $export_history->id);
        $job->handle();

        $this->assertSame('completed', $export_history->fresh()->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame($export_history->fresh()->excel_url, $proceso->resultado()['link']);

        /* Pasó por en_proceso con la etapa del job: es la emisión que saca la píldora de "en espera". */
        Event::assertDispatched(BackgroundProcessUpdated::class, function ($evento) use ($proceso) {
            return $evento->proceso['id'] === $proceso->id
                && $evento->proceso['status'] === 'en_proceso'
                && $evento->proceso['etapa'] === 'Generando el archivo';
        });
    }
}
