<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\PriceUpdateRunHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\PriceUpdateRun;
use App\Models\Provider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\EmpresaTestCase;

/**
 * El recálculo de precios deja su rastro en el registro de procesos en segundo plano (misión
 * procesos-en-segundo-plano, 18/9/2026).
 *
 * Es el flujo que Lucas nombró explícitamente ("recalcular precios al cambiar el margen de
 * ganancia de un proveedor"), y hasta esta misión no emitía ningún avance: sólo la
 * GlobalNotification al cerrar. Lo que se protege acá es que el productor, los chunks y el
 * finalizador escriban en la MISMA fila (`por_referencia` sobre el PriceUpdateRun), que los
 * números de esa fila coincidan con los de `price_update_runs`, y que los cierres —bien o
 * mal— dejen la fila cerrada.
 *
 * Con la cola `sync` (phpunit.xml) el recálculo entero corre inline dentro del handle() del
 * productor: los chunks suman antes de que el productor fije el total, y el finalizador que
 * se encola al final es el que cierra. Es el mismo camino que ya cubre
 * `RecalculoPreciosNotificacionTest::el_recalculo_entero_corre_y_cierra_con_la_cola_inline`.
 *
 * @group procesos-en-segundo-plano
 */
class Recalculo_de_precios_registra_su_avance_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();

        $this->assertEquals(
            'sync',
            config('queue.connections.' . config('queue.default') . '.driver'),
            'Este test necesita la cola inline para correr el recálculo de punta a punta.'
        );
    }

    /**
     * Artículo con costo y ganancia, para que setFinalPrice tenga algo que calcular.
     *
     * @param  string   $nombre
     * @param  int|null $provider_id
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $provider_id = null)
    {
        return Article::create([
            'name'            => $nombre,
            'user_id'         => $this->user_id,
            'provider_id'     => $provider_id,
            'cost'            => 100,
            'percentage_gain' => 50,
        ]);
    }

    /**
     * La corrida más nueva del usuario, abierta por el test que la pide.
     *
     * @param  array $ids_previos
     * @return \App\Models\PriceUpdateRun|null
     */
    protected function corrida_nueva(array $ids_previos)
    {
        return PriceUpdateRun::where('user_id', $this->user_id)
            ->whereNotIn('id', $ids_previos)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /** @test */
    public function un_recalculo_entero_deja_la_fila_completada_con_los_numeros_de_la_corrida()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $proveedor = Provider::create(['name' => 'zz Proveedor procesos recalculo', 'user_id' => $this->user_id]);

        $this->crear_articulo('zz Articulo procesos recalculo 1', $proveedor->id);
        $this->crear_articulo('zz Articulo procesos recalculo 2', $proveedor->id);

        $ids_previos = PriceUpdateRun::where('user_id', $this->user_id)->pluck('id')->toArray();

        /* Acotado a un proveedor para no recalcular el catálogo entero de la base sembrada. */
        $job = new ProcessSetFinalPrices($this->user_id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name);
        $job->handle();

        $run = $this->corrida_nueva($ids_previos);
        $this->assertNotNull($run, 'No se abrió ninguna corrida.');
        $this->assertContains($run->status, ['terminado', 'sin_cambios'], 'La corrida no cerró bien.');
        $this->assertGreaterThan(0, (int) $run->total_chunks);

        $proceso = BackgroundProcessHelper::por_referencia($run, false);

        $this->assertNotNull($proceso, 'La corrida no dejó ninguna fila en background_processes.');
        $this->assertSame(PriceUpdateRun::class, $proceso->referencia_type);
        $this->assertSame($run->id, (int) $proceso->referencia_id);
        $this->assertSame('recalculo_precios', $proceso->tipo);
        $this->assertSame('Recálculo de precios', $proceso->titulo);
        $this->assertSame('lotes', $proceso->unidad);
        $this->assertSame($this->user_id, (int) $proceso->user_id);

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame((int) $run->total_chunks, (int) $proceso->total, 'El total del registro no es el total de lotes de la corrida.');
        $this->assertSame((int) $proceso->total, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertNotNull($proceso->finished_at);

        $resultado = $proceso->resultado();
        $this->assertSame((int) $run->articles_updated, (int) $resultado['articulos_actualizados']);
        $this->assertSame($run->origen_texto, $resultado['origen_texto']);
        $this->assertArrayHasKey('proveedores', $resultado);

        /* El detalle nombra el origen y el proveedor: es lo que lee el usuario debajo del título. */
        $this->assertStringContainsString('proveedor', $proceso->detalle);
        $this->assertStringContainsString($proveedor->name, $proceso->detalle);
        $this->assertSame($run->articles_updated > 0 ? 'Terminado' : 'Sin cambios', $proceso->etapa);

        /* Una sola fila por corrida: productor, chunks y finalizador escriben en la misma. */
        $this->assertSame(1, BackgroundProcess::where('referencia_type', PriceUpdateRun::class)
            ->where('referencia_id', $run->id)
            ->count());

        /* Y se emitió por Pusher al abrir y al cerrar, como mínimo. */
        Event::assertDispatched(BackgroundProcessUpdated::class, function ($evento) use ($proceso) {
            return $evento->proceso['id'] === $proceso->id && $evento->proceso['status'] === 'completado';
        });
    }

    /** @test */
    public function los_chunks_suman_de_a_uno_y_el_productor_fija_el_total()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        /* Una corrida abierta a mano, como la deja abrir(): sin total todavía. */
        $run = PriceUpdateRunHelper::abrir($this->user_id, 'dolar');

        $proceso = BackgroundProcessHelper::por_referencia($run);
        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, $proceso->status);
        $this->assertNull($proceso->total, 'Antes de encolar, el total no se conoce: la barra es indeterminada.');
        $this->assertNull($proceso->porcentaje);
        $this->assertSame('Preparando los artículos', $proceso->etapa);

        /* El productor terminó de encolar tres lotes. */
        \DB::table('price_update_runs')->where('id', $run->id)->update(['total_chunks' => 3, 'chunks_encolados' => 1]);
        BackgroundProcessHelper::avanzar($proceso, null, ['total' => 3, 'etapa' => 'Recalculando']);

        /* Un chunk termina (sin artículos que cambien: lo que se prueba es el contador). */
        $chunk = new ProcessChunkSetFinalPrices([], $this->user_id, $run->id);
        $chunk->handle();

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(3, (int) $proceso->total);
        $this->assertSame(1, (int) $proceso->procesados);
        $this->assertSame(33, (int) $proceso->porcentaje);
        $this->assertSame('Lote 1 de 3', $proceso->etapa);

        $run->refresh();
        $this->assertSame(1, (int) $run->processed_chunks, 'El contador propio de la corrida se sigue moviendo igual que antes.');
    }

    /** @test */
    public function una_corrida_que_cierra_con_error_deja_la_fila_en_fallo()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $run = PriceUpdateRunHelper::abrir($this->user_id, 'tipo_de_precio');

        $proceso = BackgroundProcessHelper::por_referencia($run);
        $this->assertNotNull($proceso);

        $cierre = PriceUpdateRunHelper::cerrar_con_error($run->id, 'Se quedó sin memoria (SQL: update articles set x = 1)');
        $this->assertTrue($cierre['avisar']);

        $run->refresh();
        $this->assertSame('error', $run->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertNotNull($proceso->finished_at);
        /* El mismo texto que quedó en la corrida, ya sin el SQL. */
        $this->assertSame($run->error_detalle, $proceso->error_message);
        $this->assertStringNotContainsString('SQL:', $proceso->error_message);

        /* Los otros 499 lotes que caen por lo mismo no reabren ni pisan nada. */
        $segundo = PriceUpdateRunHelper::cerrar_con_error($run->id, 'la misma causa otra vez');
        $this->assertFalse($segundo['avisar']);
        $this->assertSame('Se quedó sin memoria', BackgroundProcess::find($proceso->id)->error_message);
    }

    /** @test */
    public function una_corrida_que_cerro_bien_no_se_marca_en_fallo_por_un_error_posterior()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $run = PriceUpdateRunHelper::abrir($this->user_id, 'dolar');
        $proceso = BackgroundProcessHelper::por_referencia($run);

        /* Cierra bien por el finalizador, sin chunks (no hay nada que recalcular). */
        PriceUpdateRunHelper::cerrar_sin_articulos($run);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame('Sin cambios', $proceso->etapa);
        $this->assertSame(0, (int) $proceso->resultado()['articulos_actualizados']);

        /* Lo que falla después es el aviso: la corrida no se toca, y el registro tampoco. */
        $cierre = PriceUpdateRunHelper::cerrar_con_error($run->id, 'no se pudo avisar el resultado');
        $this->assertTrue($cierre['avisar']);
        $this->assertSame('sin_cambios', $run->fresh()->status);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($proceso->id)->status);
    }

    /** @test */
    public function el_finalizador_que_se_pasa_del_tope_cierra_la_fila_en_fallo_una_sola_vez()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $run = PriceUpdateRunHelper::abrir($this->user_id, 'proveedor');
        $proceso = BackgroundProcessHelper::por_referencia($run);

        \DB::table('price_update_runs')->where('id', $run->id)->update([
            'total_chunks'     => 4,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'started_at'       => now()->subHours(FinalizeSetFinalPrices::TOPE_HORAS + 1),
        ]);

        $finalizador = new FinalizeSetFinalPrices($this->user_id, $run->id);
        $finalizador->handle();

        $this->assertSame('error', $run->fresh()->status);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertStringContainsString('1 de 4 lotes', $proceso->error_message);

        /* El failed() del finalizador llega después por la misma corrida: no duplica el cierre. */
        $finalizador->failed(new \Exception('el worker se reinicio'));
        $this->assertStringContainsString('1 de 4 lotes', BackgroundProcess::find($proceso->id)->error_message);
    }

    /** @test */
    public function el_recalculo_por_lista_de_precios_tambien_registra_su_avance()
    {
        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $ids = [
            $this->crear_articulo('zz Articulo procesos lista 1')->id,
            $this->crear_articulo('zz Articulo procesos lista 2')->id,
        ];

        $ids_previos = PriceUpdateRun::where('user_id', $this->user_id)->pluck('id')->toArray();

        PriceTypeHelper::dispatch_recalculate_for_articles($ids, $this->user_id, 'listas_de_precio', 'Mayorista');

        $run = $this->corrida_nueva($ids_previos);
        $this->assertNotNull($run);

        $proceso = BackgroundProcessHelper::por_referencia($run, false);
        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(1, (int) $proceso->total);
        $this->assertSame(1, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertStringContainsString('Mayorista', $proceso->detalle);
    }
}
