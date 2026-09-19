<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Events\InventoryPerformanceGenerated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\inventoryPerformance\InventoryPerformanceHelper;
use App\Jobs\GenerateStockSuggestionChunksJob;
use App\Jobs\ProcessInventoryPerformanceJob;
use App\Jobs\ProcessSincronizarDescuentosProveedorJob;
use App\Jobs\SyncFromMeliArticlesJob;
use App\Models\BackgroundProcess;
use App\Models\Provider;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\SyncFromMeliArticle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Los procesos que no tienen suite propia en esta misión —reporte de inventario, sugerencias,
 * sincronización de descuentos e importación desde Mercado Libre— también dejan su rastro en el
 * registro de procesos en segundo plano (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Lo que se protege acá es la regla de "lo que el usuario pidió se ve, lo que corre solo no":
 * el reporte de inventario registra sólo si alguien apretó el botón, y las sugerencias sólo
 * cuando el controller las encoló por catálogo grande. Y que las salidas de error —que en
 * varios de estos jobs corren sobre otra instancia, en failed()— encuentren la misma fila.
 *
 * Los jobs de imágenes y descripciones IA no se corren acá: pegan a Google y a Anthropic por
 * artículo y no hay forma razonable de simularlos entero. Su instrumentación sigue el mismo
 * molde (iniciar / incrementar por artículo / completar / failed()) y se valida por lectura.
 *
 * @group procesos-en-segundo-plano
 */
class Otros_procesos_registran_su_avance_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class, InventoryPerformanceGenerated::class]);

        // Sin clave de Anthropic el cierre de la sugerencia no encola el resumen IA (mismo
        // criterio que tests/Feature/SugerenciasStock/4_Prioridad_cobertura_Test).
        config(['services.anthropic.api_key' => null]);
    }

    /**
     * La fila abierta más nueva de un tipo, del usuario del test.
     *
     * @param  string $tipo
     * @param  bool   $solo_activos
     * @return \App\Models\BackgroundProcess|null
     */
    protected function ultimo_proceso($tipo, $solo_activos = false)
    {
        $query = BackgroundProcess::where('user_id', $this->user_id)->where('tipo', $tipo)->orderBy('id', 'DESC');

        if ($solo_activos) {
            $query->activos();
        }

        return $query->first();
    }

    /** @test */
    public function el_reporte_de_inventario_registra_solo_cuando_lo_pide_el_usuario()
    {
        $antes = (int) BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'reporte_inventario')->count();

        /* El scheduler: sin quién lo pidió, no se registra nada. */
        $del_scheduler = new ProcessInventoryPerformanceJob($this->user_id);
        $del_scheduler->handle();

        $this->assertSame($antes, (int) BackgroundProcess::where('user_id', $this->user_id)->where('tipo', 'reporte_inventario')->count());

        /* El botón: se registra, cierra con el stock mínimo del reporte, y quién lo pidió. */
        $del_usuario = new ProcessInventoryPerformanceJob($this->user_id, $this->user_id);
        $del_usuario->handle();

        $proceso = $this->ultimo_proceso('reporte_inventario');
        $this->assertNotNull($proceso, 'El reporte pedido por el usuario no quedó registrado.');
        $this->assertSame('Reporte de inventario', $proceso->titulo);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertNull($proceso->total, 'El reporte no tiene unidades: barra indeterminada.');
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertArrayHasKey('stock_minimo', $proceso->resultado());

        /* Y el candado se liberó, como siempre. */
        $this->assertFalse(InventoryPerformanceHelper::esta_generando($this->user_id));
    }

    /** @test */
    public function encolar_generacion_arrastra_quien_lo_pidio_hasta_el_job()
    {
        Queue::fake();
        Cache::forget(InventoryPerformanceHelper::llave_generando($this->user_id));

        $this->assertTrue(InventoryPerformanceHelper::encolar_generacion($this->user_id, $this->user_id));

        Queue::assertPushed(ProcessInventoryPerformanceJob::class, function ($job) {
            $reflexion = new \ReflectionProperty($job, 'auth_user_id');
            $reflexion->setAccessible(true);

            return (int) $reflexion->getValue($job) === $this->user_id;
        });

        /* La firma vieja sigue andando igual: sin segundo argumento, null. */
        Cache::forget(InventoryPerformanceHelper::llave_generando($this->user_id));
        $this->assertTrue(InventoryPerformanceHelper::encolar_generacion($this->user_id));

        Queue::assertPushed(ProcessInventoryPerformanceJob::class, function ($job) {
            $reflexion = new \ReflectionProperty($job, 'auth_user_id');
            $reflexion->setAccessible(true);

            return is_null($reflexion->getValue($job));
        });

        Cache::forget(InventoryPerformanceHelper::llave_generando($this->user_id));
    }

    /** @test */
    public function el_failed_del_reporte_cierra_la_fila_en_fallo_solo_si_alguien_lo_pidio()
    {
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'reporte_inventario', 'Reporte de inventario', [
            'auth_user_id' => $this->user_id,
        ]);

        /* El del scheduler no toca nada, ni siquiera una fila ajena que esté abierta. */
        $del_scheduler = new ProcessInventoryPerformanceJob($this->user_id);
        $del_scheduler->failed(new \Exception('se murió el worker'));
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, BackgroundProcess::find($proceso->id)->status);

        $del_usuario = new ProcessInventoryPerformanceJob($this->user_id, $this->user_id);
        $del_usuario->failed(new \Exception('se murió el worker'));

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertSame('se murió el worker', $proceso->error_message);
    }

    /** @test */
    public function la_sugerencia_de_stock_registra_solo_cuando_el_controller_la_encolo()
    {
        $crear = function () {
            return StockSuggestion::create([
                'modo'          => 'minimo',
                'origen'        => 'absoluto',
                'limite_origen' => 'minimo',
                'status'        => 'pendiente',
                'user_id'       => $this->user_id,
            ]);
        };

        /* Inline (catálogo chico) o desde el comando: sin flag, sin fila. */
        $inline = $crear();
        (new GenerateStockSuggestionChunksJob($inline->id))->handle();

        $this->assertSame('terminado', $inline->fresh()->status);
        $this->assertNull(BackgroundProcessHelper::por_referencia($inline, false), 'El camino inline no tiene que abrir proceso.');

        /* Encolada por el controller: con flag, fila medible en lotes que cierra con los artículos sugeridos. */
        $encolada = $crear();
        (new GenerateStockSuggestionChunksJob($encolada->id, true))->handle();

        $encolada->refresh();
        $this->assertSame('terminado', $encolada->status);

        $proceso = BackgroundProcessHelper::por_referencia($encolada, false);
        $this->assertNotNull($proceso, 'La sugerencia encolada no dejó su fila.');
        $this->assertSame('sugerencias_stock', $proceso->tipo);
        $this->assertSame('Sugerencias de stock', $proceso->titulo);
        $this->assertSame('lotes', $proceso->unidad);
        $this->assertSame(StockSuggestion::class, $proceso->referencia_type);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame((int) $encolada->total_chunks, (int) $proceso->total);
        $this->assertSame((int) $proceso->total, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame(
            (int) StockSuggestionArticle::where('stock_suggestion_id', $encolada->id)->count(),
            (int) $proceso->resultado()['articulos']
        );

        /* failed() sobre la misma sugerencia después de cerrada: no reabre. */
        (new GenerateStockSuggestionChunksJob($encolada->id, true))->failed(new \Exception('tarde'));
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($proceso->id)->status);
    }

    /** @test */
    public function un_reintento_de_la_sugerencia_reusa_la_fila_abierta_y_failed_la_cierra()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->user_id,
        ]);

        /* El intento anterior dejó la fila abierta (murió a la mitad, $tries = 3). */
        $abierta = BackgroundProcessHelper::iniciar($this->user_id, 'sugerencias_stock', 'Sugerencias de stock', [
            'referencia' => $suggestion,
            'total'      => 7,
        ]);
        BackgroundProcessHelper::avanzar($abierta, 3);

        (new GenerateStockSuggestionChunksJob($suggestion->id, true))->handle();

        $this->assertSame(1, BackgroundProcess::where('referencia_type', StockSuggestion::class)
            ->where('referencia_id', $suggestion->id)
            ->count(), 'El reintento abrió una segunda fila en vez de reusar la del intento anterior.');

        $proceso = BackgroundProcess::find($abierta->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame((int) $suggestion->fresh()->total_chunks, (int) $proceso->total, 'El total se reescribió con el de esta corrida.');

        /* Y una que agota los reintentos cierra en fallo por failed(). */
        $otra = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->user_id,
        ]);
        $fila = BackgroundProcessHelper::iniciar($this->user_id, 'sugerencias_stock', 'Sugerencias de stock', ['referencia' => $otra]);

        (new GenerateStockSuggestionChunksJob($otra->id, true))->failed(new \Exception('se agotaron los reintentos'));

        $this->assertSame('error', $otra->fresh()->status);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, BackgroundProcess::find($fila->id)->status);
        $this->assertSame('se agotaron los reintentos', BackgroundProcess::find($fila->id)->error_message);
    }

    /** @test */
    public function sincronizar_descuentos_abre_por_proveedor_y_failed_la_encuentra()
    {
        $provider = Provider::create(['name' => 'zz Proveedor procesos descuentos', 'user_id' => $this->user_id]);

        $job = new ProcessSincronizarDescuentosProveedorJob($provider->id, $this->user_id, $this->user_id, 'todos', false, 'saltear', 'op-' . uniqid());
        $job->handle();

        $proceso = BackgroundProcessHelper::por_referencia($provider, false);
        $this->assertNotNull($proceso, 'La sincronización no dejó su fila.');
        $this->assertSame('sincronizar_descuentos', $proceso->tipo);
        $this->assertSame('Sincronización de descuentos de ' . $provider->name, $proceso->titulo);
        $this->assertSame($this->user_id, (int) $proceso->auth_user_id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertNull($proceso->total, 'El loop vive en ArticleProviderDiscountHelper y no expone avance: barra indeterminada.');

        $resultado = $proceso->resultado();
        $this->assertSame(0, (int) $resultado['creados'], 'Un proveedor sin descuentos en la ficha no crea nada.');
        $this->assertArrayHasKey('actualizados', $resultado);
        $this->assertArrayHasKey('al_dia', $resultado);

        /* Una corrida que muere: failed() corre sobre otra instancia y la encuentra por el proveedor. */
        $abierta = BackgroundProcessHelper::iniciar($this->user_id, 'sincronizar_descuentos', 'Sincronización de descuentos de ' . $provider->name, [
            'referencia' => $provider,
        ]);

        $otro = new ProcessSincronizarDescuentosProveedorJob($provider->id, $this->user_id, $this->user_id, 'todos', false, 'saltear', 'op-' . uniqid());
        $otro->failed(new \Exception('se quedó sin memoria'));

        $abierta = BackgroundProcess::find($abierta->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $abierta->status);
        $this->assertSame('se quedó sin memoria', $abierta->error_message);
        /* La primera, ya cerrada bien, no se tocó. */
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($proceso->id)->status);
    }

    /** @test */
    public function la_importacion_desde_mercado_libre_cae_en_fallo_si_no_hay_cuenta_conectada()
    {
        $sync_record = SyncFromMeliArticle::create([
            'user_id' => $this->user_id,
            'status'  => SyncFromMeliArticle::STATUS_PENDIENTE,
        ]);

        $job = new SyncFromMeliArticlesJob($sync_record->id);

        $tiro = false;

        try {
            $job->handle();
        } catch (\Throwable $e) {
            $tiro = true;
        }

        $this->assertTrue($tiro, 'Sin conector de Mercado Libre el servicio tiene que tirar, como antes.');

        $proceso = BackgroundProcessHelper::por_referencia($sync_record, false);
        $this->assertNotNull($proceso, 'La importación no dejó su fila.');
        $this->assertSame('importacion_meli', $proceso->tipo);
        $this->assertSame('Importación desde Mercado Libre', $proceso->titulo);
        $this->assertSame(SyncFromMeliArticle::class, $proceso->referencia_type);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('Mercado Libre', $proceso->error_message);

        /* El failed() del job, sobre otra instancia, no pisa el motivo original. */
        (new SyncFromMeliArticlesJob($sync_record->id))->failed(new \Exception('otro motivo'));
        $this->assertStringContainsString('Mercado Libre', BackgroundProcess::find($proceso->id)->error_message);
    }
}
