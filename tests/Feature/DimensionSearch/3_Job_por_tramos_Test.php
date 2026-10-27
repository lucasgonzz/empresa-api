<?php

namespace Tests\Feature\DimensionSearch;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Jobs\ProcessDimensionSearchRunJob;
use App\Models\DimensionSearchItem;
use App\Models\DimensionSearchRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\DimensionSearch\Dobles\TramoDePrueba;

/**
 * El job por tramos de la búsqueda masiva de peso y medidas (misión medidas-masivas-catalogo,
 * 27/10/2026): reclamo atómico, dos intentos, tramos que se re-encolan, cortes automáticos reanudables,
 * detener y reanudar.
 */
class Job_por_tramos_Test extends DimensionSearchTestCase
{
    /**
     * Arma `$cuantos` artículos con página y búsqueda falsa propias, y falsea todo: la IA contesta «exacto» con
     * los cuatro datos para cualquier página.
     *
     * @param  int  $cuantos
     * @param  bool $ia_cae
     * @return array  Los artículos.
     */
    protected function articulos_asignables($cuantos, $ia_cae = false)
    {
        $articulos = [];
        $serper    = [];
        $paginas   = [];

        for ($i = 0; $i < $cuantos; $i++) {
            $gtin = $this->con_verificador('77912345'.str_pad((string) (1000 + $i), 4, '0', STR_PAD_LEFT));
            $url  = 'https://catalogo.test/producto-'.$i;

            $articulos[] = $this->nuevo_articulo('PRODUCTO DE PRUEBA '.$i, $gtin);

            $serper['"'.$gtin.'"'] = [$this->resultado($url)];
            $paginas[$url]         = $this->pagina('Producto '.$i, 'Código '.$gtin.'. Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.');
        }

        $this->falsear($serper, $paginas, $ia_cae ? 'caida' : function ($entrada) {
            $url = $entrada['documents'][0]['url'];

            return $this->ficha('exacto', [
                'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ]);
        });

        return $articulos;
    }

    /**
     * @group dimension-search
     * @test
     */
    public function procesa_la_corrida_en_orden_y_la_termina()
    {
        $articulos = $this->articulos_asignables(3);
        // Un cuarto artículo que no se encuentra en ningún lado.
        $articulos[] = $this->nuevo_articulo('NADA QUE VER 9999');

        $run = $this->corrida($articulos);

        Queue::fake();

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(4, (int) $run->procesados);

        $conteos = DimensionSearchRunHelper::conteos_de([$run->id])[$run->id];
        $this->assertSame(['asignadas' => 3, 'a_revisar' => 0, 'sin_datos' => 1, 'pendientes' => 0, 'errores' => 0], $conteos);

        // Se escribió en los tres primeros (y solo en esos).
        foreach (array_slice($articulos, 0, 3) as $articulo) {
            $this->assertEquals(0.82, (float) $articulo->fresh()->peso);
        }

        $this->assertTrue(is_null($articulos[3]->fresh()->peso) || (float) $articulos[3]->fresh()->peso === 0.0);

        $this->assertSame(3, (int) $run->validaciones_ia);
        // 3 de código + 1 de nombre del cuarto ("NADA QUE VER 9999" no tiene código).
        $this->assertSame(4, (int) $run->busquedas);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function el_tramo_se_re_encola_al_agotar_su_presupuesto_y_siempre_hace_al_menos_un_articulo()
    {
        $articulos = $this->articulos_asignables(3);
        $run       = $this->corrida($articulos);

        Queue::fake();

        $job = new TramoDePrueba($run->id);
        $job->presupuesto = 0;
        $job->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $run->status);
        $this->assertSame(1, (int) $run->procesados, 'Un tramo con presupuesto cero hace un artículo y sigue en otro.');

        Queue::assertPushed(ProcessDimensionSearchRunJob::class, function ($job) use ($run) {
            $propiedad = new \ReflectionProperty($job, 'run_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($job) === (int) $run->id;
        });

        // El orden es el de la selección: se hizo el primero.
        $this->assertSame(DimensionSearchItem::STATUS_ASIGNADA, DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->first()->status);
        $this->assertSame(2, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function la_cola_es_excel_en_el_shared_y_la_default_en_el_vps()
    {
        config(['app.VPS' => false]);
        $this->assertSame('excel', (new ProcessDimensionSearchRunJob(1))->queue);

        config(['app.VPS' => true]);
        $this->assertNull((new ProcessDimensionSearchRunJob(1))->queue);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function una_corrida_detenida_no_se_procesa()
    {
        $articulos = $this->articulos_asignables(2);
        $run       = $this->corrida($articulos);

        DimensionSearchRunHelper::detener($run);

        Queue::fake();

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $this->assertSame(DimensionSearchRun::STATUS_DETENIDA, $run->fresh()->status);
        $this->assertSame(0, (int) $run->fresh()->procesados);
        $this->assertSame([], $this->consultas_serper);
    }

    /**
     * El reclamo es atómico: de dos tramos que quieren el mismo artículo, solo uno lo consigue.
     *
     * @group dimension-search
     * @test
     */
    public function el_reclamo_es_atomico_solo_un_tramo_se_queda_con_el_articulo()
    {
        $articulos = $this->articulos_asignables(1);
        $run       = $this->corrida($articulos);
        $item      = DimensionSearchItem::where('run_id', $run->id)->first();

        $a = new TramoDePrueba($run->id);
        $b = new TramoDePrueba($run->id);

        $this->assertNotSame($a->ficha(), $b->ficha(), 'Cada tramo tiene su ficha.');
        $this->assertTrue($a->reclamar_publico($item));
        $this->assertFalse($b->reclamar_publico($item), 'El segundo ya no lo encuentra pendiente.');

        $item->refresh();
        $this->assertSame(DimensionSearchItem::STATUS_PROCESANDO, $item->status);
        $this->assertSame($a->ficha(), $item->tramo);
        $this->assertSame(1, (int) $item->intentos);
    }

    /**
     * Un artículo que ya hizo caer el proceso dos veces se deja de lado.
     *
     * @group dimension-search
     * @test
     */
    public function un_articulo_venenoso_queda_error_interno_al_tercer_reclamo_y_no_traba_la_corrida()
    {
        $articulos = $this->articulos_asignables(2);
        $run       = $this->corrida($articulos);

        $venenoso = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->first();
        $venenoso->update(['intentos' => 2]);

        Queue::fake();

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $venenoso->refresh();

        $this->assertSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $venenoso->status);
        $this->assertSame('error_interno', $venenoso->motivo);
        $this->assertStringContainsString('Falló 2 veces', $venenoso->motivo_detalle);

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status, 'La corrida siguió con el otro y terminó.');
        $this->assertSame(2, (int) $run->procesados);

        $conteos = DimensionSearchRunHelper::conteos_de([$run->id])[$run->id];
        $this->assertSame(1, $conteos['errores']);
        $this->assertSame(1, $conteos['asignadas']);
        $this->assertSame(1, $conteos['sin_datos'], 'El error interno se lista en Sin datos.');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function failed_devuelve_solo_su_articulo_descarta_al_venenoso_y_con_tres_fallos_da_la_corrida_por_fallida()
    {
        $articulos = $this->articulos_asignables(2);
        $run       = $this->corrida($articulos);

        $venenoso = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->first();
        $sano     = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->skip(1)->first();

        Queue::fake();

        $job   = new TramoDePrueba($run->id);
        $ficha = $job->ficha();

        // Uno que procesa OTRO tramo vivo: el failed() de este no lo toca.
        $sano->update(['status' => DimensionSearchItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => 'ficha-de-otro-tramo']);

        // 1er fallo: el artículo estaba en su primer intento → vuelve a pendiente y se re-despacha.
        $venenoso->update(['status' => DimensionSearchItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => $ficha]);
        $job->failed(new \RuntimeException('Se reinició el worker (prueba)'));

        $this->assertSame(DimensionSearchItem::STATUS_PENDIENTE, $venenoso->fresh()->status);
        $this->assertSame(DimensionSearchItem::STATUS_PROCESANDO, $sano->fresh()->status, 'El de otro tramo sigue procesando.');
        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos);
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);

        // El mismo tramo fallando de nuevo no se cuenta dos veces (misma ficha).
        $job->failed(new \RuntimeException('otra vez (prueba)'));
        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos, 'failed() corre una sola vez por ficha.');

        // 2do fallo, con el artículo en su segundo intento (otro tramo): queda error_interno.
        $job2 = new TramoDePrueba($run->id);
        // fresh(): failed() lo cambió por fuera del modelo, y update() solo escribe lo que el modelo ve distinto.
        $venenoso = $venenoso->fresh();
        $venenoso->update(['status' => DimensionSearchItem::STATUS_PROCESANDO, 'intentos' => 2, 'tramo' => $job2->ficha()]);
        $job2->failed(new \RuntimeException('segundo (prueba)'));

        $this->assertSame(DimensionSearchItem::STATUS_ERROR_INTERNO, $venenoso->fresh()->status);
        $this->assertSame(2, (int) $run->fresh()->fallos_consecutivos);

        // 3er fallo seguido: la corrida queda fallida y se puede reanudar.
        $job3 = new TramoDePrueba($run->id);
        $job3->failed(new \RuntimeException('tercero (prueba)'));

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('3 veces seguidas', $run->motivo_estado);
        $this->assertStringNotContainsString('prueba', $run->motivo_estado, 'El detalle crudo no llega al usuario.');
    }

    /**
     * Sin IA no se sigue: 5 artículos seguidos sin respuesta frenan la corrida (reanudable).
     *
     * @group dimension-search
     * @test
     */
    public function cinco_articulos_seguidos_sin_ia_frenan_la_corrida_y_al_reanudar_se_reintentan()
    {
        $articulos = $this->articulos_asignables(7, true);
        $run       = $this->corrida($articulos);

        Queue::fake();

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('La IA no responde en 5 artículos seguidos', $run->motivo_estado);
        $this->assertSame(5, (int) $run->procesados);
        $this->assertSame(5, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_SIN_PROCESAR)->where('motivo', 'ia_sin_respuesta')->count());
        $this->assertSame(2, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());

        // Ahora la IA vuelve. Reanudar devuelve los cinco a la fila y la corrida termina bien.
        $this->articulos_asignables_con_los_mismos($articulos);

        $r = DimensionSearchRunHelper::reanudar($run->fresh());
        $this->assertSame(200, $r['status']);

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_PENDIENTE, $run->status);
        $this->assertSame(0, (int) $run->procesados, 'Los reintentables dejaron de contar como procesados.');
        $this->assertSame(0, (int) $run->errores_ia_seguidos);
        $this->assertSame(7, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status);
        $this->assertSame(7, (int) $run->procesados);
        $this->assertSame(7, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_ASIGNADA)->count());
    }

    /**
     * Vuelve a falsear la misma red para los mismos artículos, con la IA andando.
     *
     * @param  array $articulos
     * @return void
     */
    protected function articulos_asignables_con_los_mismos(array $articulos)
    {
        $serper  = [];
        $paginas = [];

        foreach ($articulos as $i => $articulo) {
            $url = 'https://catalogo.test/producto-'.$i;

            $serper['"'.$articulo->bar_code.'"'] = [$this->resultado($url)];
            $paginas[$url] = $this->pagina('Producto '.$i, 'Código '.$articulo->bar_code.'. Peso: 820 g. Ancho: 70 mm. Alto: 11 mm. Largo: 100 mm.');
        }

        $this->falsear($serper, $paginas, function ($entrada) {
            $url = $entrada['documents'][0]['url'];

            return $this->ficha('exacto', [
                'peso'  => $this->dato(820, 'g', 'Peso: 820 g', $url),
                'ancho' => $this->dato(70, 'mm', 'Ancho: 70 mm', $url),
                'alto'  => $this->dato(11, 'mm', 'Alto: 11 mm', $url),
                'largo' => $this->dato(100, 'mm', 'Largo: 100 mm', $url),
            ]);
        });
    }

    /**
     * @group dimension-search
     * @test
     */
    public function cinco_articulos_seguidos_con_el_buscador_caido_frenan_la_corrida()
    {
        $articulos = [];

        for ($i = 0; $i < 6; $i++) {
            $articulos[] = $this->nuevo_articulo('PRODUCTO SIN BUSCADOR '.$i);
        }

        $this->falsear(['error' => 'Not enough credits'], [], $this->ficha('otro_producto'));

        $run = $this->corrida($articulos);

        Queue::fake();

        (new ProcessDimensionSearchRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run->status);
        $this->assertStringContainsString('El proveedor de búsqueda falló en 5 artículos seguidos', $run->motivo_estado);
        $this->assertStringContainsString('Not enough credits', $run->motivo_estado);
        $this->assertSame(1, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_PENDIENTE)->count());
        // Ninguno de esos cinco quedó marcado como «ya buscado sin éxito».
        $this->assertSame(0, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_SIN_DATOS)->count());
    }

    /**
     * @group dimension-search
     * @test
     */
    public function sin_clave_de_serper_o_sin_ia_la_corrida_falla_sin_gastar()
    {
        $this->articulos_asignables(1);

        // 1. Le sacaron la clave de Serper.
        $run2 = $this->corrida([$this->nuevo_articulo('OTRO PRODUCTO 1')]);
        config(['services.serper.api_key' => '']);

        (new ProcessDimensionSearchRunJob($run2->id))->handle();

        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run2->fresh()->status);
        $this->assertStringContainsString('clave de Serper', $run2->fresh()->motivo_estado);

        config(['services.serper.api_key' => 'SERPER-DE-PRUEBA']);

        // 2. Sin clave de IA.
        $run3 = $this->corrida([$this->nuevo_articulo('OTRO PRODUCTO 2')]);
        config(['services.deepseek.api_key' => '']);

        (new ProcessDimensionSearchRunJob($run3->id))->handle();

        $this->assertSame(DimensionSearchRun::STATUS_FALLIDA, $run3->fresh()->status);
        $this->assertStringContainsString('clave de la IA', $run3->fresh()->motivo_estado);

        $this->assertSame([], $this->consultas_serper, 'Ninguna de las dos gastó una búsqueda.');
    }

    /**
     * @group dimension-search
     * @test
     */
    public function en_una_demo_sin_busquedas_la_corrida_termina_con_el_motivo_de_la_demo()
    {
        $anterior = [config('app.FOR_USER'), config('app.USER_ID')];
        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        try {
            $articulos = $this->articulos_asignables(3);
            $run       = $this->corrida($articulos);

            $ahora = Carbon::now();
            $filas = [];

            for ($i = 0; $i < 25; $i++) {
                $filas[] = [
                    'event_uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $this->owner->id, 'process' => 'imagenes_seleccion',
                    'endpoint' => 'images', 'query' => 'previa '.$i, 'ok' => true, 'occurred_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }

            \App\Models\SerperUsageEvent::insert($filas);

            Queue::fake();
            (new ProcessDimensionSearchRunJob($run->id))->handle();

            $run->refresh();

            $this->assertSame(DimensionSearchRun::STATUS_TERMINADA, $run->status);
            $this->assertStringContainsString('límite de búsquedas de la demo', $run->motivo_estado);
            $this->assertSame(3, DimensionSearchItem::where('run_id', $run->id)->where('status', DimensionSearchItem::STATUS_SIN_PROCESAR)->where('motivo', 'sin_cupo')->count());
            $this->assertSame([], $this->consultas_serper);
        } finally {
            config(['app.FOR_USER' => $anterior[0], 'app.USER_ID' => $anterior[1]]);
        }
    }

    /**
     * Detener y reanudar.
     *
     * @group dimension-search
     * @test
     */
    public function reanudar_vale_para_detenida_fallida_o_trabada_y_no_para_una_terminada()
    {
        $articulos = $this->articulos_asignables(2);
        $run       = $this->corrida($articulos);

        // Detenida → reanudable, y despacha un tramo.
        $this->assertSame(200, DimensionSearchRunHelper::detener($run)['status']);
        $this->assertSame(422, DimensionSearchRunHelper::detener($run->fresh())['status'], 'Una ya detenida no se detiene de nuevo.');

        // Un artículo que quedó «procesando» hace rato (worker muerto) y otro hace un instante (tramo vivo).
        $muerto = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->first();
        $vivo   = DimensionSearchItem::where('run_id', $run->id)->orderBy('orden')->skip(1)->first();
        DB::table('dimension_search_items')->where('id', $muerto->id)->update(['status' => 'procesando', 'updated_at' => Carbon::now()->subMinutes(30)]);
        DB::table('dimension_search_items')->where('id', $vivo->id)->update(['status' => 'procesando', 'updated_at' => Carbon::now()]);

        Queue::fake();

        $this->assertSame(200, DimensionSearchRunHelper::reanudar($run->fresh())['status']);

        $this->assertSame(DimensionSearchRun::STATUS_PENDIENTE, $run->fresh()->status);
        $this->assertSame(DimensionSearchItem::STATUS_PENDIENTE, $muerto->fresh()->status, 'El del worker muerto vuelve a la fila.');
        $this->assertSame(DimensionSearchItem::STATUS_PROCESANDO, $vivo->fresh()->status, 'El que toca un tramo vivo no se toca.');
        Queue::assertPushed(ProcessDimensionSearchRunJob::class);

        // Trabada: en proceso y sin señal de vida hace 20 minutos.
        $run->refresh();
        $run->update(['status' => DimensionSearchRun::STATUS_EN_PROCESO, 'last_progress_at' => Carbon::now()->subMinutes(20)]);
        $this->assertTrue($run->fresh()->esta_trabada());
        $this->assertSame(200, DimensionSearchRunHelper::reanudar($run->fresh())['status']);
        $this->assertSame(DimensionSearchRun::STATUS_EN_PROCESO, $run->fresh()->status);

        // Terminada: no se reanuda.
        $run->update(['status' => DimensionSearchRun::STATUS_TERMINADA]);
        $this->assertSame(422, DimensionSearchRunHelper::reanudar($run->fresh())['status']);
    }

    /**
     * @group dimension-search
     * @test
     */
    public function no_se_reanuda_si_hay_otra_corrida_de_catalogo_en_curso()
    {
        $a = $this->corrida([$this->nuevo_articulo('PRODUCTO A 1')]);
        DimensionSearchRunHelper::detener($a);

        $b = $this->corrida([$this->nuevo_articulo('PRODUCTO B 1')]);

        $r = DimensionSearchRunHelper::reanudar($a->fresh());

        $this->assertSame(422, $r['status']);
        $this->assertStringContainsString('otra búsqueda de todo el catálogo en curso', $r['message']);
        $this->assertSame(DimensionSearchRun::STATUS_PENDIENTE, $b->fresh()->status);
    }
}
