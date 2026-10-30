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
        Cache::forget('medidas-masivas:rechazo-avisado:'.$run->id);

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
     * El evento del vigía en el schedule de la aplicación.
     *
     * @return \Illuminate\Console\Scheduling\Event|null
     */
    protected function evento_del_vigia()
    {
        // Se fuerza el armado del schedule (los comandos del kernel de consola).
        Artisan::call('list');

        $evento = null;

        foreach (app(Schedule::class)->events() as $candidato) {
            if (strpos((string) $candidato->command, 'medidas:reanudar-trabadas') !== false) {
                $evento = $candidato;
            }
        }

        return $evento;
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_comando_esta_agendado_cada_cinco_minutos_sin_superponerse_y_solo_corre_si_hay_una_trabada()
    {
        $evento = $this->evento_del_vigia();

        $this->assertNotNull($evento, 'El vigía está en el schedule.');
        $this->assertSame('*/5 * * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);

        // Con una corrida trabada, el gate deja pasar el comando.
        $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);

        $this->assertTrue($evento->filtersPass($this->app));
    }

    /**
     * La regla del schedule: cero procesos de artisan cuando no hay trabajo.
     *
     * @group dimension-search
     * @test
     */
    public function el_gate_del_schedule_no_arranca_el_comando_si_no_hay_corridas_trabadas()
    {
        $evento = $this->evento_del_vigia();

        // Dentro de la transacción del test: ninguna corrida en proceso de lo que pudiera haber en la base.
        DB::table('dimension_search_runs')
            ->where('status', DimensionSearchRun::STATUS_EN_PROCESO)
            ->update(['status' => DimensionSearchRun::STATUS_DETENIDA]);

        Cache::forget('schedule_tabla_existe_dimension_search_runs');

        $this->assertFalse($evento->filtersPass($this->app), 'Sin ninguna corrida en proceso no se arranca nada.');

        // En proceso, pero avanzando hace poco: tampoco.
        $reciente = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 5);

        $this->assertFalse($evento->filtersPass($this->app), 'Una corrida con avance reciente no dispara el comando.');

        // Una trabada que no está en proceso (detenida): tampoco.
        $this->corrida_en(DimensionSearchRun::STATUS_DETENIDA, 180);

        $this->assertFalse($evento->filtersPass($this->app));

        // En cuanto una se traba, sí.
        $this->fijar_senal($reciente, DimensionSearchRun::STATUS_EN_PROCESO, 20);

        $this->assertTrue($evento->filtersPass($this->app));

        // Y una en proceso que nunca dio señal, pero arrancó hace horas, también está trabada.
        $this->fijar_senal($reciente, DimensionSearchRun::STATUS_EN_PROCESO, null);

        $this->assertTrue($evento->filtersPass($this->app));
    }

    /**
     * Un cliente con una versión vieja no tiene la tabla: el gate lo sabe (con el resultado cacheado, ver
     * Kernel::tabla_existe_para_el_schedule) y no arranca nada aunque haya "trabadas". Se simula el "no existe"
     * con ese resultado cacheado para no tocar el esquema de la base de pruebas.
     *
     * @group dimension-search
     * @test
     */
    public function el_gate_del_schedule_no_arranca_el_comando_si_la_tabla_no_existe()
    {
        $evento = $this->evento_del_vigia();

        $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);

        Cache::put('schedule_tabla_existe_dimension_search_runs', false, 300);

        $this->assertFalse($evento->filtersPass($this->app));

        Cache::forget('schedule_tabla_existe_dimension_search_runs');
    }

    /**
     * El rechazo de reanudar() (acá, hay otra corrida de catálogo en curso) se avisa en el log una vez por hora.
     *
     * @group dimension-search
     * @test
     */
    public function el_rechazo_de_reanudar_se_avisa_en_el_log_una_sola_vez_por_hora()
    {
        $trabada = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 20);
        $otra    = $this->corrida_en(DimensionSearchRun::STATUS_EN_PROCESO, 1);

        Queue::fake();
        Log::spy();

        // Tres pasadas del scheduler (15 minutos): reanudar() la rechaza las tres veces.
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, Artisan::call('medidas:reanudar-trabadas'));
        }

        $this->assertSame(0, $this->tramos_despachados_para($trabada), 'No se reanudó: hay otra de catálogo en curso.');
        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $otra->fresh()->status);
        $this->assertNull(Cache::get('medidas-masivas:reanudaciones-automaticas:'.$trabada->id), 'Un rechazo no cuenta para el tope.');

        Log::shouldHaveReceived('info')->withArgs(function ($mensaje, $contexto = []) use ($trabada) {
            return $mensaje === '[MedidasMasivas] El vigía no pudo reanudar la corrida.'
                && isset($contexto['run_id']) && (int) $contexto['run_id'] === (int) $trabada->id;
        })->once();
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

    /**
     * El mensaje de una QueryException trae el SQL con sus valores: lo que haya guardado adentro (un timeout de
     * Serper, por ejemplo) no puede hacerla pasar por un corte de la conexión.
     *
     * @group dimension-search
     * @test
     */
    public function el_texto_de_los_valores_de_la_consulta_no_hace_pasar_un_error_por_corte_de_base()
    {
        $job = new TramoConMotorQueFalla(1);

        $metodo = new \ReflectionMethod($job, 'es_corte_de_base');
        $metodo->setAccessible(true);

        $error = new QueryException(
            'update `dimension_search_items` set `diagnostico` = ? where `id` = ?',
            ['cURL error 28: Connection timed out after 15001 ms | Lost connection | server has gone away', 10],
            new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1048 Column cannot be null', 23000)
        );

        $this->assertStringContainsString('Connection timed out', $error->getMessage(), 'El mensaje completo sí trae el texto.');
        $this->assertFalse($metodo->invoke($job, $error), 'Pero el mensaje del driver no habla de la conexión.');

        // De punta a punta: el motor revienta así, y el artículo cuenta sus dos intentos como siempre.
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $tramo                = new TramoConMotorQueFalla($run->id);
        $tramo->falla_siempre = $error;
        $tramo->handle();

        $this->assertSame(0, $tramo->esperas);
        $this->assertSame([1, 2], $tramo->intentos_vistos);
        $this->assertSame(DimensionSearchItem::STATUS_ERROR_INTERNO, DimensionSearchItem::where('run_id', $run->id)->first()->status);
    }

    /**
     * Otros síntomas de conexión perdida (los de DetectsLostConnections de Laravel y el reinicio de MySQL).
     *
     * @group dimension-search
     * @test
     */
    public function se_reconocen_mas_sintomas_de_conexion_perdida_y_el_codigo_1053()
    {
        $job = new TramoConMotorQueFalla(1);

        $metodo = new \ReflectionMethod($job, 'es_corte_de_base');
        $metodo->setAccessible(true);

        // Por el código del driver.
        $this->assertTrue($metodo->invoke($job, $this->corte_de_base(1053, 'SQLSTATE[08S01]: Communication link failure: 1053 Server shutdown in progress')));

        // Por el texto, sin código.
        foreach ([
            'SQLSTATE[HY000]: General error: server closed the connection unexpectedly',
            'SQLSTATE[08006] [7] no connection to the server',
            'SQLSTATE[HY000]: General error: Error while sending QUERY packet. PID=1234',
            'SQLSTATE[HY000] [2002] Connection reset by peer',
            'SQLSTATE[HY000]: General error: Server shutdown in progress',
        ] as $mensaje) {
            $this->assertTrue($metodo->invoke($job, $this->corte_de_base(0, $mensaje)), $mensaje);
        }

        // Y el mismo texto metido en los valores de la consulta, no.
        $en_los_valores = new QueryException('insert into t (a) values (?)', ['Error while sending QUERY packet'], new \PDOException('SQLSTATE[22001]: Data too long', 22001));
        $this->assertFalse($metodo->invoke($job, $en_los_valores));
    }

    /**
     * @group dimension-search
     * @test
     */
    public function las_esperas_de_un_tramo_tienen_un_presupuesto_acumulado_y_agotado_se_relanza()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        // Tres cortes seguidos; cada espera "tarda" lo que se le permite, hasta 150 s, y la base siempre vuelve.
        $job                      = new TramoConMotorQueFalla($run->id);
        $job->fallas              = [$this->corte_de_base(), $this->corte_de_base(), $this->corte_de_base()];
        $job->segundos_por_espera = 150;

        try {
            $job->handle();

            $this->fail('Con el presupuesto agotado el tramo tenía que morir.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        // La 1ª espera pudo ser de 150 s; a la 2ª le quedaban 50 (200 - 150); a la 3ª, nada: no esperó y relanzó.
        $this->assertSame(2, $job->esperas);
        $this->assertSame([150, 50], $job->maximos_pedidos);

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertNotSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $item->status);

        // Un tramo nuevo (otra ficha, otro handle()) arranca con el presupuesto entero.
        $siguiente                      = new TramoConMotorQueFalla($run->id);
        $siguiente->fallas              = [$this->corte_de_base()];
        $siguiente->segundos_por_espera = 150;

        DB::table('dimension_search_items')->where('id', $item->id)->update(['status' => DimensionSearchItem::STATUS_PENDIENTE, 'intentos' => 0, 'tramo' => null]);

        $siguiente->handle();

        $this->assertSame([150], $siguiente->maximos_pedidos);
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->fresh()->status);
    }

    /**
     * Cada espera se acota además a lo que queda del timeout del tramo menos 30 s, contando lo ya transcurrido.
     *
     * @group dimension-search
     * @test
     */
    public function cada_espera_se_acota_a_lo_que_queda_del_timeout_menos_un_margen()
    {
        $job = new TramoConMotorQueFalla(1);
        $job->iniciar_presupuesto_publico();

        $this->assertSame(150, $job->permitidos_publico(), 'Recién arrancado: el tope de una espera.');

        // 340 s transcurridos: 480 - 30 - 340 = 110.
        $job->avanzar_reloj(340);
        $this->assertSame(110, $job->permitidos_publico());

        // 440 s: quedan 10 s antes del margen.
        $job->avanzar_reloj(100);
        $this->assertSame(10, $job->permitidos_publico());

        // 460 s: ya no queda nada.
        $job->avanzar_reloj(20);
        $this->assertLessThanOrEqual(0, $job->permitidos_publico());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function failed_por_un_corte_de_base_devuelve_el_articulo_sin_gastar_el_intento_y_deja_su_linea_de_log()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);
        $item     = DimensionSearchItem::where('run_id', $run->id)->first();

        Queue::fake();
        Log::spy();

        $job = new TramoConMotorQueFalla($run->id);

        // El tramo murió con el artículo en su segundo intento: sin el arreglo, quedaba error_interno.
        $item->update(['status' => DimensionSearchItem::STATUS_PROCESANDO, 'intentos' => 2, 'tramo' => $job->ficha()]);

        $job->failed($this->corte_de_base());

        $item->refresh();
        $this->assertSame(DimensionSearchItem::STATUS_PENDIENTE, $item->status);
        $this->assertSame(1, (int) $item->intentos, 'Se le devolvió el intento del tramo que murió.');
        $this->assertNull($item->tramo);

        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos, 'El fallo seguido se suma como siempre.');
        $this->assertTrue($run->fresh()->esta_activa(), 'La corrida sigue activa.');
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje) {
            return strpos($mensaje, 'lo reanuda el vigía') !== false;
        })->once();
    }

    /**
     * Si la conexión se pierde DESPUÉS de que la base aplicó el UPDATE del reclamo, el artículo ya es de este tramo.
     *
     * @group dimension-search
     * @test
     */
    public function un_corte_justo_despues_de_aplicado_el_reclamo_no_lo_reclama_dos_veces()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                    = new TramoConMotorQueFalla($run->id);
        $job->corte_al_reclamar = 'despues';
        $job->handle();

        $this->assertSame(1, $job->esperas);
        $this->assertSame([1], $job->intentos_vistos, 'Un solo reclamo: el intento no se duplicó.');

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(1, (int) $item->intentos);
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->fresh()->status);
    }

    /**
     * Y si el corte fue ANTES de aplicarse, al volver la base el reclamo se repite.
     *
     * @group dimension-search
     * @test
     */
    public function un_corte_justo_antes_del_reclamo_lo_repite_al_volver_la_base()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                    = new TramoConMotorQueFalla($run->id);
        $job->corte_al_reclamar = 'antes';
        $job->handle();

        $this->assertSame(1, $job->esperas);
        $this->assertSame([1], $job->intentos_vistos);
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, DimensionSearchItem::where('run_id', $run->id)->first()->status);
    }

    /**
     * Un error común del motor y, mientras se registra, la base se corta: se espera, se repite el registro y el
     * artículo sigue su camino normal (primer intento gastado, vuelve a la fila y el segundo anda).
     *
     * @group dimension-search
     * @test
     */
    public function un_corte_mientras_se_registra_la_falla_de_un_articulo_se_espera_y_se_repite_el_registro()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                     = new TramoConMotorQueFalla($run->id);
        $job->fallas             = [new \RuntimeException('falla común del motor')];
        $job->fallas_al_devolver = [$this->corte_de_base()];
        $job->handle();

        $this->assertSame(1, $job->esperas, 'Esperó por el corte de devolver_o_descartar().');
        $this->assertSame([1, 2], $job->intentos_vistos, 'La falla común sí gastó el primer intento.');

        $item = DimensionSearchItem::where('run_id', $run->id)->first();
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->fresh()->status);
    }

    /**
     * Si lo que falla al registrar la falla NO es la base, no se espera nada: se propaga (la recoge failed()).
     *
     * @group dimension-search
     * @test
     */
    public function un_error_que_no_es_de_la_base_al_registrar_la_falla_se_propaga_sin_esperar()
    {
        $articulo = $this->articulo_asignable();
        $run      = $this->corrida([$articulo]);

        Queue::fake();

        $job                     = new TramoConMotorQueFalla($run->id);
        $job->fallas             = [new \RuntimeException('falla común del motor')];
        $job->fallas_al_devolver = [new \LogicException('otra cosa')];

        try {
            $job->handle();

            $this->fail('Tenía que propagarse.');
        } catch (\LogicException $e) {
            $this->assertSame('otra cosa', $e->getMessage());
        }

        $this->assertSame(0, $job->esperas);
    }
}
