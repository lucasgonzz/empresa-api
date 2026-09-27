<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\BackgroundProcess;
use App\Models\GeocoderCounter;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * El job por tramos (ProcessImageAssignmentRunJob, plan §6.7).
 *
 * Lo que protege: que un tramo se re-encole en vez de correr horas; que al terminar salga el evento
 * de siempre (con el uuid de la asignación y el payload chico) y el registro visible se cierre; que
 * el cupo diario corte la corrida (y que "todo el catálogo" no lo toque); que un tramo que muere se
 * re-despache sin perder el artículo y sin trabarse con uno venenoso; y que detenerla corte.
 */
class Job_por_tramos_Test extends ImagenesInteligentesTestCase
{
    /** Tres EAN-13 de fábrica válidos, distintos. */
    const CODIGOS = ['7791234567898', '7790001000019', '7790002000018'];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
    }

    /**
     * Artículos con código real, y el Serper / la IA falsos que les asignan una imagen a cada uno.
     *
     * @param  int $cantidad
     * @return array  Los artículos.
     */
    protected function articulos_asignables($cantidad)
    {
        $colores    = ['rojo', 'azul', 'verde', 'amarillo', 'violeta', 'naranja'];
        $articulos  = [];
        $serper     = [];
        $imagenes   = [];
        $veredictos = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $codigo = isset(self::CODIGOS[$i]) ? self::CODIGOS[$i] : $this->con_verificador('77900030000'.$i);
            $url    = $this->url_imagen('producto-'.$i);

            $articulos[] = $this->nuevo_articulo('Producto '.$i, $codigo);

            $serper[$codigo]                 = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]                  = $this->png(900, 900, $colores[$i % count($colores)]);
            $veredictos[$colores[$i % count($colores)]] = $this->veredicto('si', 'high');
        }

        $this->falsear($serper, $imagenes, $veredictos);

        return $articulos;
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function un_tramo_sin_presupuesto_procesa_uno_y_se_vuelve_a_encolar()
    {
        $articulos = $this->articulos_asignables(3);
        $run       = $this->asignacion($articulos);

        config(['services.imagenes_inteligentes.segundos_por_tramo' => 0]);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $run->status);
        $this->assertSame(1, (int) $run->procesados, 'Un tramo hace al menos un artículo.');
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);
        Event::assertNotDispatched(ArticleBatchImagesProcessed::class);

        // El registro visible ya no dice "En espera del procesador".
        $proceso = BackgroundProcess::find($run->background_process_id);
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, $proceso->status);
        $this->assertSame(1, (int) $proceso->procesados);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function al_terminar_emite_el_evento_con_el_uuid_y_payload_chico_y_cierra_el_proceso()
    {
        $articulos = $this->articulos_asignables(2);
        $run       = $this->asignacion($articulos);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(2, (int) $run->procesados);
        $this->assertSame(2, (int) $run->busquedas);

        Queue::assertNotPushed(ProcessImageAssignmentRunJob::class);

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid
                && $evento->user_id === (int) $this->owner->id
                && $evento->processed === 2
                && $evento->skipped === 0
                && $evento->needs_review === 0
                && $evento->quota_reached === false
                && array_keys($evento->broadcastWith()) === ['processed', 'skipped', 'needs_review', 'quota_reached', 'skipped_by_quota', 'batch_uuid']
                && strlen(json_encode($evento->broadcastWith())) < 400;
        });

        $proceso = BackgroundProcess::find($run->background_process_id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(ImageAssignmentRun::class, $proceso->referencia_type);
        $this->assertSame((int) $run->id, (int) $proceso->referencia_id);
        $this->assertSame(['asignadas' => 2, 'a_revisar' => 0, 'no_asignadas' => 0, 'busquedas' => 2], array_intersect_key($proceso->resultado(), array_flip(['asignadas', 'a_revisar', 'no_asignadas', 'busquedas'])));
    }

    /**
     * Selección del dueño (tope diario): al agotarse el cupo, el resto queda sin procesar y la
     * asignación termina diciéndolo; el contador del día se consumió.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_tope_diario_al_agotarse_el_cupo_el_resto_queda_sin_procesar()
    {
        $this->owner->google_cuota = 1;
        $this->owner->save();

        $articulos = $this->articulos_asignables(3);
        $run       = $this->asignacion($articulos, ['aplica_tope_diario' => true]);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertStringContainsString('cupo diario', (string) $run->motivo_estado);
        $this->assertSame(1, (int) $run->busquedas);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[0]->id)->value('status'));

        $sin_procesar = ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_SIN_PROCESAR)->get();
        $this->assertCount(2, $sin_procesar);

        foreach ($sin_procesar as $item) {
            $this->assertSame('sin_cupo', $item->motivo);
        }

        $contador = GeocoderCounter::where('user_id', $this->owner->id)->whereDate('created_at', Carbon::today())->first();
        $this->assertNotNull($contador);
        $this->assertSame(1, (int) $contador->counter, 'Cada búsqueda que respondió bien consume el cupo del día.');

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid
                && $evento->quota_reached === true
                && $evento->skipped_by_quota === 2
                && $evento->processed === 1;
        });

        $this->assertSame('Se agotó el cupo diario de búsquedas', BackgroundProcess::find($run->background_process_id)->etapa);
    }

    /**
     * "Todo el catálogo" (acceso maestro) no le come el cupo del día al dueño.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_asignacion_de_catalogo_no_consume_el_cupo_diario()
    {
        $this->owner->google_cuota = 1;
        $this->owner->save();

        $articulos = $this->articulos_asignables(2);
        $run       = $this->asignacion($articulos, ['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'aplica_tope_diario' => false]);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertSame(2, (int) $run->busquedas, 'Buscó para los dos aunque el cupo del dueño sea 1.');

        $contador = GeocoderCounter::where('user_id', $this->owner->id)->whereDate('created_at', Carbon::today())->first();
        $this->assertTrue(is_null($contador) || (int) $contador->counter === 0, 'El contador del día del dueño no se tocó.');
    }

    /**
     * La ficha de un tramo (el job la genera al despacharse y la escribe en lo que reclama).
     *
     * @param  \App\Jobs\ProcessImageAssignmentRunJob $job
     * @return string
     */
    protected function ficha_del_tramo(ProcessImageAssignmentRunJob $job)
    {
        $propiedad = new \ReflectionProperty($job, 'tramo');
        $propiedad->setAccessible(true);

        return (string) $propiedad->getValue($job);
    }

    /**
     * El tramo murió: el artículo vuelve a pendiente y se re-despacha; al segundo intento ese
     * artículo queda error_interno; con tres fallos seguidos la asignación queda fallida. Y un
     * artículo que está procesando OTRO tramo (otra ficha) no se toca.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function failed_redespacha_descarta_el_articulo_venenoso_y_con_tres_fallos_la_da_por_fallida()
    {
        $articulos = [$this->nuevo_articulo('Venenoso', self::CODIGOS[0]), $this->nuevo_articulo('Sano', self::CODIGOS[1])];
        $run       = $this->asignacion($articulos);

        $venenoso = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[0]->id)->first();
        $sano     = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[1]->id)->first();

        Queue::fake();

        $job   = new ProcessImageAssignmentRunJob($run->id);
        $ficha = $this->ficha_del_tramo($job);

        $this->assertNotSame('', $ficha, 'Cada tramo nace con su ficha.');

        // Un artículo que procesa OTRO tramo vivo: el failed() de este no lo puede tocar.
        $sano->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => 'ficha-de-otro-tramo']);

        // 1er fallo con el artículo en su primer intento (reclamado por ESTE tramo): vuelve a
        // pendiente y se re-despacha.
        $venenoso->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => $ficha]);
        $job->failed(new \RuntimeException('Se reinició el worker (prueba)'));

        $this->assertSame(ImageAssignmentItem::STATUS_PENDIENTE, $venenoso->fresh()->status);
        $this->assertSame(ImageAssignmentItem::STATUS_PROCESANDO, $sano->fresh()->status, 'El de otro tramo sigue procesando.');
        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        // 2do fallo con el mismo artículo, ya en su segundo intento: queda error_interno.
        // (refresh: el job lo devolvió a pendiente por SQL y el modelo en memoria seguía diciendo
        // "procesando"; sin releerlo, update() no vería el cambio de estado y no lo escribiría).
        $venenoso->refresh();
        $venenoso->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 2, 'tramo' => $ficha]);
        $job->failed(new \RuntimeException('Otra vez (prueba)'));

        $venenoso->refresh();
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $venenoso->status);
        $this->assertSame('error_interno', $venenoso->motivo);
        $this->assertStringContainsString('Falló 2 veces', $venenoso->motivo_detalle);
        $this->assertSame(1, (int) $run->fresh()->procesados);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 2);

        // 3er fallo seguido: la asignación queda fallida (y el artículo sano, que ahora sí reclamó
        // este tramo, vuelve a pendiente).
        $sano->refresh();
        $sano->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => $ficha]);
        $job->failed(new \RuntimeException('Tercera (prueba)'));

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('Se interrumpió 3 veces', (string) $run->motivo_estado);
        $this->assertSame(ImageAssignmentItem::STATUS_PENDIENTE, $sano->fresh()->status);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 2);

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid;
        });

        $this->assertSame(BackgroundProcess::STATUS_FALLO, BackgroundProcess::find($run->background_process_id)->status);
    }

    /**
     * Detenida antes de que el tramo arranque: no busca nada. Detenida en el medio: termina el
     * artículo que tiene entre manos y corta.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function detenerla_corta_el_tramo()
    {
        $articulos = $this->articulos_asignables(3);
        $run       = $this->asignacion($articulos);

        Queue::fake();

        // En el medio: la detienen mientras se busca el primero.
        $consultas = [];
        $serper    = [];
        $imagenes  = [];

        foreach ($articulos as $i => $articulo) {
            $url = $this->url_imagen('d-'.$i);
            $serper[$articulo->bar_code] = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]              = $this->png(900, 900, 'rojo');
        }

        $this->falsear($serper, $imagenes, ['rojo' => $this->veredicto('si', 'high')], function ($consulta) use ($run, &$consultas) {
            $consultas[] = $consulta;

            if (count($consultas) === 1) {
                ImageAssignmentRunHelper::detener($run->fresh());
            }
        });

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_DETENIDA, $run->status);
        $this->assertSame(1, (int) $run->procesados, 'Termina el artículo que tenía entre manos y corta.');
        $this->assertCount(1, $this->consultas_serper);
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());

        Queue::assertNotPushed(ProcessImageAssignmentRunJob::class);
        Event::assertDispatched(ArticleBatchImagesProcessed::class, 1);

        // Un tramo que llega con la asignación ya detenida no hace nada.
        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $this->assertCount(1, $this->consultas_serper);
        $this->assertSame(1, (int) $run->fresh()->procesados);
    }

    /**
     * Si el proveedor falla en 5 artículos seguidos (sin créditos, clave revocada), la asignación
     * frena como fallida en vez de recorrer miles de artículos sin poder buscar.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_el_proveedor_falla_cinco_articulos_seguidos_la_asignacion_frena()
    {
        $articulos = [];

        for ($i = 0; $i < 7; $i++) {
            $articulos[] = $this->nuevo_articulo('Sin proveedor '.$i, $this->con_verificador('77900040000'.$i));
        }

        $run = $this->asignacion($articulos);

        $this->falsear(['error' => 'Not enough credits'], [], []);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('falló en 5 artículos seguidos', (string) $run->motivo_estado);
        $this->assertStringContainsString('Not enough credits', (string) $run->motivo_estado);
        $this->assertSame(0, (int) $run->busquedas, 'Las búsquedas que fallaron no cuentan.');
        $this->assertSame(5, ImageAssignmentItem::where('run_id', $run->id)->where('motivo', 'error_de_busqueda')->count());
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());
    }

    /**
     * 🔴 El corte por el proveedor cuenta ENTRE tramos: con el proveedor colgado (15 s de timeout
     * por búsqueda) entra un artículo por tramo, y un contador que volviera a cero en cada tramo no
     * llegaba nunca al corte. Reanudarla arranca la cuenta de cero.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function los_errores_del_proveedor_se_cuentan_entre_tramos()
    {
        $articulos = [];

        for ($i = 0; $i < 7; $i++) {
            $articulos[] = $this->nuevo_articulo('Proveedor colgado '.$i, $this->con_verificador('77900050000'.$i));
        }

        $run = $this->asignacion($articulos);

        $this->falsear(['error' => 'Not enough credits'], [], []);

        // Un artículo por tramo, como con el proveedor colgado.
        config(['services.imagenes_inteligentes.segundos_por_tramo' => 0]);

        Queue::fake();

        for ($tramo = 1; $tramo <= 4; $tramo++) {
            (new ProcessImageAssignmentRunJob($run->id))->handle();

            $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $run->fresh()->status, 'Tramo '.$tramo.': todavía no llegó al corte.');
            $this->assertSame($tramo, (int) $run->fresh()->errores_proveedor_seguidos);
        }

        // El quinto tramo es el quinto artículo seguido sin poder buscar: frena.
        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('falló en 5 artículos seguidos', (string) $run->motivo_estado);
        $this->assertSame(5, (int) $run->procesados);
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 4);

        // Reanudada (cuando se resolvió lo del proveedor), la racha arranca de cero.
        $this->assertSame(200, (int) ImageAssignmentRunHelper::reanudar($run)['status']);
        $this->assertSame(0, (int) $run->fresh()->errores_proveedor_seguidos);
    }

    /**
     * 🔴 Si el último artículo gasta la última búsqueda del día, la asignación terminó BIEN: no
     * quedó nada sin procesar y el aviso no puede decir que se cortó por el cupo.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_el_ultimo_articulo_gasta_la_ultima_busqueda_termina_normal()
    {
        $this->owner->google_cuota = 2;
        $this->owner->save();

        $articulos = $this->articulos_asignables(2);
        $run       = $this->asignacion($articulos, ['aplica_tope_diario' => true]);

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertNull($run->motivo_estado, 'Terminó normal: no hay motivo de corte.');
        $this->assertSame(2, (int) $run->busquedas);
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_ASIGNADA)->count());
        $this->assertSame(0, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_SIN_PROCESAR)->count());

        $contador = GeocoderCounter::where('user_id', $this->owner->id)->whereDate('created_at', Carbon::today())->first();
        $this->assertSame(2, (int) $contador->counter, 'El cupo del día quedó en cero, justo.');

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid
                && $evento->quota_reached === false
                && $evento->skipped_by_quota === 0
                && $evento->processed === 2;
        });

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($run->background_process_id)->status);
    }
}
