<?php

namespace Tests\Feature\DimensionSearch;

use App\Console\Commands\MedidasReanudarTrabadas;
use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DimensionSearch\Dobles\TramoConMotorQueFalla;

/**
 * El vigía de las corridas y el tramo que aguanta un corte de la base (misión medidas-vigia-de-corridas,
 * 30/10/2026). La corrida #2 de Masquito se trabó dos noches seguidas: la base del VPS se cae cerca de las
 * 00:00, el tramo moría y la corrida quedaba en proceso sin ningún job en la cola.
 */
class Vigia_y_corte_de_base_Test extends DimensionSearchTestCase
{
    /**
     * Una corrida con un artículo por cada nombre, en el estado y con la última señal de vida pedidos.
     *
     * @param  string   $estado
     * @param  int|null $minutos_sin_senal  Hace cuántos minutos fue su última señal (null = sin ninguna).
     * @param  int      $articulos          Cuántos artículos tiene.
     * @return \App\Models\DimensionSearchRun
     */
    protected function corrida_en($estado, $minutos_sin_senal, $articulos = 1)
    {
        $lista = [];

        for ($i = 0; $i < $articulos; $i++) {
            $lista[] = $this->nuevo_articulo('ARTICULO DEL VIGIA '.uniqid());
        }

        $run = $this->corrida($lista);

        $this->fijar_senal($run, $estado, $minutos_sin_senal);

        // Del contador que pudo dejar otra corrida con el mismo id en la caché.
        Cache::forget('medidas-masivas:reanudaciones-automaticas:'.$run->id);
        Cache::forget('medidas-masivas:tope-avisado:'.$run->id);

        return $run->fresh();
    }

    /**
     * Pone a la corrida en un estado con la última señal de vida de hace X minutos.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @param  string   $estado
     * @param  int|null $minutos_sin_senal
     * @return void
     */
    protected function fijar_senal(DimensionSearchRun $run, $estado, $minutos_sin_senal)
    {
        $senal = is_null($minutos_sin_senal) ? null : Carbon::now()->subMinutes($minutos_sin_senal);

        DB::table('dimension_search_runs')->where('id', $run->id)->update([
            'status'           => $estado,
            'started_at'       => Carbon::now()->subHours(5),
            'last_progress_at' => $senal,
        ]);
    }

    /**
     * Cuántos tramos se despacharon para esa corrida (la cola está falseada).
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return int
     */
    protected function tramos_despachados_para(DimensionSearchRun $run)
    {
        return Queue::pushed(ProcessDimensionSearchRunJob::class)->filter(function ($job) use ($run) {
            $propiedad = new \ReflectionProperty($job, 'run_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($job) === (int) $run->id;
        })->count();
    }

    /**
     * Un artículo buscable de verdad: con código de barras, su página y la IA contestando «exacto».
     *
     * @return \App\Models\Article
     */
    protected function articulo_asignable()
    {
        $gtin     = $this->con_verificador('77912345'.str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT));
        $url      = 'https://catalogo.test/vigia-'.$gtin;
        $articulo = $this->nuevo_articulo('PRODUCTO DEL VIGIA '.$gtin, $gtin);

        $this->falsear(
            ['"'.$gtin.'"' => [$this->resultado($url)]],
            [$url => $this->pagina('Producto', 'Código '.$gtin.'. Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.')],
            function ($entrada) {
                $url = $entrada['documents'][0]['url'];

                return $this->ficha('exacto', [
                    'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                    'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                    'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                    'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
                ]);
            }
        );

        return $articulo;
    }

    /**
     * Una excepción de la base con el código del driver de MySQL.
     *
     * @param  int    $codigo
     * @param  string $mensaje
     * @return \Illuminate\Database\QueryException
     */
    protected function corte_de_base($codigo = 2002, $mensaje = 'SQLSTATE[HY000] [2002] Connection refused')
    {
        return new QueryException('select * from `dimension_search_items`', [], new \PDOException($mensaje, $codigo));
    }

    /* ------------------------------------------------------------------------------------------
     * El vigía
     * ---------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function el_vigia_reanuda_una_corrida_en_proceso_sin_senal_hace_20_minutos()
    {
        $run = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20, 2);

        // Un artículo que el worker muerto dejó «procesando» hace media hora.
        $a_medias = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->first();
        DB::table('dimension_search_items')->where('id', $a_medias->id)->update([
            'status'     => DimensionSearchItem::STATUS_PROCESANDO,
            'intentos'   => 1,
            'tramo'      => 'ficha-del-tramo-muerto',
            'updated_at' => Carbon::now()->subMinutes(30),
        ]);

        Queue::fake();

        $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));

        $this->assertSame(1, $this->tramos_despachados_para($run), 'Se despachó un tramo nuevo.');

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $run->status, 'La trabada sigue en proceso: el tramo nuevo la toma así.');
        $this->assertTrue($run->last_progress_at->gt(Carbon::now()->subMinutes(2)), 'Se renovó la señal de vida.');
        $this->assertFalse($run->esta_trabada());
        $this->assertSame(DimensionSearchItem::STATUS_PENDIENTE, $a_medias->fresh()->status, 'El artículo a medias volvió a la fila.');
        $this->assertSame(1, (int) $a_medias->fresh()->intentos, 'Conserva sus intentos.');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_vigia_no_toca_una_corrida_en_proceso_con_avance_reciente()
    {
        $run = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 5);

        $antes = $run->last_progress_at->toDateTimeString();

        Queue::fake();

        $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));

        $this->assertSame(0, $this->tramos_despachados_para($run));
        $this->assertSame($antes, $run->fresh()->last_progress_at->toDateTimeString());
        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $run->fresh()->status);
    }

    /**
     * Una detenida o fallida es una decisión de una persona (o un corte con motivo): el vigía no la reanuda.
     *
     * @group dimension-search
     * @test
     */
    public function el_vigia_no_toca_las_corridas_detenidas_fallidas_terminadas_ni_pendientes()
    {
        $corridas = [];

        foreach ([
            DimensionSearchRun::STATUS_DETENIDA,
            DimensionSearchRun::STATUS_FALLIDA,
            DimensionSearchRun::STATUS_TERMINADA,
            DimensionSearchRun::STATUS_PENDIENTE,
        ] as $estado) {
            // Hace tres horas sin señal: si fuera en_proceso, estaría trabada de sobra.
            $corridas[$estado] = $this->corrida_en($estado, 180);
        }

        Queue::fake();

        $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));

        foreach ($corridas as $estado => $run) {
            $this->assertSame(0, $this->tramos_despachados_para($run), "Una corrida $estado no se reanuda sola.");
            $this->assertSame($estado, $run->fresh()->status);
            $this->assertSame($run->last_progress_at->toDateTimeString(), $run->fresh()->last_progress_at->toDateTimeString());
        }
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_vigia_reanuda_como_mucho_seis_veces_por_dia_y_la_septima_deja_un_error()
    {
        $run = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);

        Queue::fake();
        Log::spy();

        for ($vez = 1; $vez <= MedidasReanudarTrabadas::MAX_REANUDACIONES_POR_VENTANA; $vez++) {
            // Se vuelve a trabar: otra noche, otra caída de la base.
            $this->fijar_senal($run, DimensionSearchRun::STATUS_EN_PROCESO, 20);

            $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));
            $this->assertSame($vez, $this->tramos_despachados_para($run), "La reanudación $vez sí se hace.");
        }

        Log::shouldNotHaveReceived('error');

        // La séptima: tope.
        $this->fijar_senal($run, DimensionSearchRun::STATUS_EN_PROCESO, 20);

        $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));

        $this->assertSame(6, $this->tramos_despachados_para($run), 'La séptima no despacha nada.');
        $this->assertTrue($run->fresh()->esta_trabada(), 'Sigue trabada: la pantalla sigue avisando.');

        Log::shouldHaveReceived('error')->once()->withArgs(function ($mensaje) use ($run) {
            return strpos($mensaje, '#'.$run->id) !== false
                && strpos($mensaje, '6 veces en 24 h') !== false
                && strpos($mensaje, 'se necesita una persona') !== false;
        });

        // Cada reanudación dejó su línea de log.
        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($run) {
            return $mensaje === '[MedidasMasivas] Reanudada automáticamente'
                && isset($contexto['run_id']) && (int) $contexto['run_id'] === (int) $run->id
                && array_key_exists('minutos_sin_senal', $contexto);
        })->times(6);

        // Otra corrida trabada no hereda el tope de la primera. (La primera se detiene: reanudar no deja que haya
        // dos corridas de catálogo en curso del mismo dueño.)
        $this->fijar_senal($run, DimensionSearchRun::STATUS_DETENIDA, 20);

        $otra = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);
        Queue::fake();

        Artisan::call('medidas:reanudar-trabadas');

        $this->assertSame(1, $this->tramos_despachados_para($otra));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function dry_run_lista_lo_que_reanudaria_sin_escribir_ni_despachar()
    {
        $run = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);

        $antes = $run->last_progress_at->toDateTimeString();

        Queue::fake();

        $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas', ['--dry-run' => true]));

        $salida = Artisan::output();

        $this->assertStringContainsString('Corrida #'.$run->id, $salida);
        $this->assertStringContainsString('se reanudaría', $salida);

        $this->assertSame(0, $this->tramos_despachados_para($run));
        $this->assertSame($antes, $run->fresh()->last_progress_at->toDateTimeString(), 'No escribió nada.');
        $this->assertNull(Cache::get('medidas-masivas:reanudaciones-automaticas:'.$run->id), 'Tampoco cuenta contra el tope.');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_comando_esta_agendado_cada_cinco_minutos_sin_superponerse_y_solo_corre_si_hay_una_trabada()
    {
        // Se fuerza el armado del schedule (los comandos del kernel de consola).
        Artisan::call('list');

        $evento = null;

        foreach (app(Schedule::class)->events() as $candidato) {
            if (strpos((string) $candidato->command, 'medidas:reanudar-trabadas') !== false) {
                $evento = $candidato;
            }
        }

        $this->assertNotNull($evento, 'El vigía está en el schedule.');
        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);

        // Con una corrida trabada, el gate deja pasar el comando.
        $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);

        $this->assertTrue($evento->filtersPass($this->app));
    }

    /* ------------------------------------------------------------------------------------------
     * El tramo ante un corte de la base
     * ---------------------------------------------------------------------------------------- */

    /**
     * @group dimension-search
     * @test
     */
    public function un_corte_de_la_base_no_cuenta_como_falla_del_articulo_y_el_tramo_sigue()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        // El motor revienta con "Connection refused" en el primer intento y después anda.
        $job         = new TramoConMotorQueFalla($run->id);
        $job->fallas = [$this->corte_de_base(2002)];
        $job->handle();

        $this->assertSame(1, $job->esperas, 'Esperó una vez a que volviera la base.');
        $this->assertSame([1, 1], $job->intentos_vistos, 'El intento del corte no se gastó: el segundo pasó con intentos = 1, no 2.');

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status, 'El artículo se procesó de verdad.');
        $this->assertSame(1, (int) $item->intentos);
        $this->assertEquals(0.82, (float) $articulo->fresh()->peso);

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status, 'El tramo siguió hasta terminar la corrida.');
        $this->assertSame(1, (int) $run->procesados);
        $this->assertSame(0, (int) $run->fallos_consecutivos);
    }

    /**
     * Los otros dos códigos de MySQL y la cadena de causas también son un corte.
     *
     * @group dimension-search
     * @test
     */
    public function se_reconocen_los_codigos_2006_y_2013_el_mensaje_y_la_causa_anidada()
    {
        $job = new TramoConMotorQueFalla(1);

        $metodo = new \ReflectionMethod($job, 'es_corte_de_base');
        $metodo->setAccessible(true);

        $this->assertTrue($metodo->invoke($job, $this->corte_de_base(2006, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away')));
        $this->assertTrue($metodo->invoke($job, $this->corte_de_base(2013, 'SQLSTATE[HY000]: General error: 2013 Lost connection to MySQL server during query')));

        // Sin el código a mano, por el texto.
        $this->assertTrue($metodo->invoke($job, $this->corte_de_base(0, 'SQLSTATE[HY000] [2002] Connection timed out')));

        // Envuelta en otra excepción.
        $this->assertTrue($metodo->invoke($job, new \RuntimeException('no se pudo guardar', 0, $this->corte_de_base(2002))));

        // Un error de SQL cualquiera no es un corte.
        $this->assertFalse($metodo->invoke($job, new QueryException('select 1', [], new \PDOException('SQLSTATE[42S02]: Base table or view not found', 42))));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function si_la_base_no_vuelve_se_relanza_la_excepcion_y_el_articulo_no_queda_error_interno()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job              = new TramoConMotorQueFalla($run->id);
        $job->fallas      = [$this->corte_de_base(2002)];
        $job->base_vuelve = false;

        try {
            $job->handle();

            $this->fail('El tramo tenía que morir si la base no vuelve.');
        } catch (QueryException $e) {
            $this->assertSame(1, $job->esperas);
        }

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertNotSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $item->status);
        $this->assertSame(DimensionSearchItem::STATUS_PROCESANDO, $item->status, 'Quedó reclamado por el tramo muerto, como siempre.');

        // Laravel llama a failed() (con la base ya de vuelta): el artículo vuelve a la fila y se re-despacha.
        $job->failed($e);

        $this->assertSame(DimensionSearchItem::STATUS_PENDIENTE, $item->fresh()->status);
        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $run->fresh()->status);
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);
    }

    /**
     * Regresión: un error que no es de la base sigue contando como antes (dos intentos y error_interno), aunque su
     * mensaje hable de una conexión (un timeout de Serper o de una página no es la base).
     *
     * @group dimension-search
     * @test
     */
    public function un_error_que_no_es_de_la_base_sigue_contando_como_antes()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                = new TramoConMotorQueFalla($run->id);
        $job->falla_siempre = new \RuntimeException('cURL error 28: Connection timed out after 15001 milliseconds');
        $job->handle();

        $this->assertSame(0, $job->esperas, 'No esperó a la base: la base no tenía nada que ver.');
        $this->assertSame([1, 2], $job->intentos_vistos);

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $item->status);
        $this->assertSame('error_interno', $item->motivo);
        $this->assertSame(2, (int) $item->intentos);

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status);
        $this->assertSame(1, (int) $run->procesados);
    }

    /**
     * La red de seguridad: un artículo que rompe la conexión por sí mismo no se reintenta gratis para siempre.
     *
     * @group dimension-search
     * @test
     */
    public function un_articulo_que_corta_la_conexion_una_y_otra_vez_deja_de_salir_gratis()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                = new TramoConMotorQueFalla($run->id);
        $job->falla_siempre = $this->corte_de_base(2006, 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        $job->handle();

        $this->assertSame(ProcessDimensionSearchRunJob::MAX_CORTES_DE_BASE_SIN_COSTO_POR_ARTICULO, $job->esperas);

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $item->status, 'Pasadas las salidas gratis, cuenta como una falla común.');
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->fresh()->status);
    }
}
