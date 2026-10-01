<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\RecalcularCombosCalculados;
use App\Models\PriceUpdateRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * El recálculo de combos no bloquea el request ni la corrida que lo dispara (misión
 * combos-calculados, F5).
 *
 * EL RIESGO. Recalcular un combo cuesta ~8-10 consultas. Hecho en línea dentro de un request (guardar
 * un artículo, editar una lista) multiplica eso por los combos que contienen el artículo; y hecho
 * dentro del cierre de una corrida de precios, un timeout dejaba la corrida abierta para siempre
 * (`$tries = 120`). Por eso: hasta `ComboCalculadoHelper::MAXIMO_EN_LINEA` combos se recalcula en
 * línea, más se encola `RecalcularCombosCalculados`, y `FinalizeSetFinalPrices` cierra la corrida
 * ANTES de encolar.
 *
 * Los tests usan `Queue::fake()` para ver qué se encola, y corren el job a mano para verificar que
 * hace el trabajo (con la cola `sync` de producción-de-tests el resultado final es el mismo, pero
 * no se vería el despacho).
 *
 * @group combos-calculados
 */
class Recalculo_de_combos_en_segundo_plano_Test extends ComboCalculadoTestCase
{
    /**
     * Arma N combos calculados que incluyen el mismo artículo, todos viejos (su costo y su precio
     * están en un número que no es el de hoy), y devuelve [$articulo, $combos].
     *
     * @param  int  $cantidad
     * @return array
     */
    protected function combos_viejos($cantidad)
    {
        $this->con_listas(0);

        $articulo = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250]);

        $combos = [];

        for ($i = 0; $i < $cantidad; $i++) {

            $combo = $this->combo_calculado([[$articulo, 2]]);

            ComboCalculadoHelper::guardar($combo);

            $combos[] = $combo;
        }

        // Cambia el artículo por la puerta de atrás: los combos quedan viejos.
        $this->escribir_crudo($articulo, ['costo_real' => 180, 'final_price' => 400]);

        return [$articulo, $combos];
    }

    /**
     * @param  \App\Models\Combo  $combo
     * @return bool
     */
    protected function esta_al_dia($combo)
    {
        return $this->costo_en_base($combo) === 360.0 && $this->precio_en_base($combo) === 800.0;
    }

    /**
     * Hasta el tope, el recálculo es en línea y no encola nada.
     *
     * @test
     */
    public function hasta_el_tope_de_combos_se_recalcula_en_linea_sin_encolar()
    {
        Queue::fake();

        list($articulo, $combos) = $this->combos_viejos(ComboCalculadoHelper::MAXIMO_EN_LINEA);

        $recalculados = ComboCalculadoHelper::recalcular_por_articulos([$articulo->id]);

        $this->assertSame(ComboCalculadoHelper::MAXIMO_EN_LINEA, $recalculados);

        Queue::assertNotPushed(RecalcularCombosCalculados::class);

        foreach ($combos as $combo) {
            $this->assertTrue($this->esta_al_dia($combo), 'En línea: el combo ya quedó al día.');
        }
    }

    /**
     * 🔴 Con más combos que el tope, NO se recalcula en el request: se encola el job con esos
     * combos, y el job hace el trabajo.
     *
     * @test
     */
    public function con_mas_combos_que_el_tope_se_encola_el_job_y_el_job_hace_el_trabajo()
    {
        Queue::fake();

        list($articulo, $combos) = $this->combos_viejos(ComboCalculadoHelper::MAXIMO_EN_LINEA + 1);

        $encolados = ComboCalculadoHelper::recalcular_por_articulos([$articulo->id]);

        $this->assertSame(count($combos), $encolados);

        Queue::assertPushed(RecalcularCombosCalculados::class, 1);

        foreach ($combos as $combo) {
            $this->assertFalse($this->esta_al_dia($combo), 'Encolado: el request no los recalculó.');
        }

        Queue::pushed(RecalcularCombosCalculados::class)->first()->handle();

        foreach ($combos as $combo) {
            $this->assertTrue($this->esta_al_dia($combo), 'El job dejó el combo al día.');
        }
    }

    /**
     * Editar una lista de precios con más combos que el tope también encola (y con pocos, recalcula
     * en línea).
     *
     * @test
     */
    public function editar_una_lista_con_muchos_combos_encola_el_recalculo()
    {
        Queue::fake();

        list($articulo, $combos) = $this->combos_viejos(ComboCalculadoHelper::MAXIMO_EN_LINEA + 1);

        $lista = $this->lista('Para editar', 90);

        $this->putJson('api/price-type/' . $lista->id, [
            'name'           => 'zz Lista editada',
            'percentage'     => $lista->percentage,
            'position'       => 90,
            'categories'     => [],
            'sub_categories' => [],
        ])->assertStatus(200);

        Queue::assertPushed(RecalcularCombosCalculados::class, 1);

        $this->assertFalse($this->esta_al_dia($combos[0]), 'No se recalculó dentro del request.');

        Queue::pushed(RecalcularCombosCalculados::class)->first()->handle();

        $this->assertTrue($this->esta_al_dia($combos[0]));
    }

    /**
     * Sin combos calculados, cerrar una corrida no encola nada (no se llena la cola de jobs vacíos).
     *
     * @test
     */
    public function un_dueno_sin_combos_calculados_no_encola_nada()
    {
        Queue::fake();

        // La base de testing puede traer combos calculados de otras corridas: para el test, ninguno.
        \Illuminate\Support\Facades\DB::table('combos')->update(['calcular_desde_articulos' => 0]);

        $this->assertFalse(ComboCalculadoHelper::encolar_recalculo_de_un_dueno(self::DUENO));

        Queue::assertNotPushed(RecalcularCombosCalculados::class);
    }

    /**
     * El job en su modo "todos los del dueño" recalcula los combos sin que nadie le diga cuáles.
     *
     * @test
     */
    public function el_job_sin_lista_de_combos_recalcula_todos_los_del_dueno()
    {
        list($articulo, $combos) = $this->combos_viejos(3);

        (new RecalcularCombosCalculados(self::DUENO))->handle();

        foreach ($combos as $combo) {
            $this->assertTrue($this->esta_al_dia($combo));
        }
    }

    /**
     * 🔴 El cierre de la corrida va ANTES del recálculo de combos: con la cola falsa, la corrida ya
     * está cerrada (y avisada) y los combos siguen viejos hasta que corre el job. Un timeout del
     * recálculo de combos ya no puede dejar la corrida abierta.
     *
     * @test
     */
    public function la_corrida_cierra_antes_de_que_corra_el_recalculo_de_combos()
    {
        Notification::fake();
        Queue::fake();

        list($articulo, $combos) = $this->combos_viejos(2);

        $run = PriceUpdateRun::create([
            'user_id'          => self::DUENO,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);

        (new FinalizeSetFinalPrices(self::DUENO, $run->id))->handle();

        $this->assertNotEquals('en_proceso', $run->fresh()->status, 'La corrida cerró.');
        $this->assertFalse($this->esta_al_dia($combos[0]), 'Y los combos todavía no se recalcularon.');

        Queue::assertPushed(RecalcularCombosCalculados::class);
    }

    /**
     * El orden, medido con la cola `sync` (el job corre en el acto): el UPDATE que cierra la corrida
     * (`price_update_runs`) va ANTES que el primer UPDATE de un combo. Con `Queue::fake()` el orden
     * no se ve, por eso este test no la usa.
     *
     * @test
     */
    public function con_la_cola_sync_el_update_que_cierra_la_corrida_va_antes_que_el_de_los_combos()
    {
        Notification::fake();

        list($articulo, $combos) = $this->combos_viejos(1);

        $run = PriceUpdateRun::create([
            'user_id'          => self::DUENO,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);

        $orden = [];

        \Illuminate\Support\Facades\DB::listen(function ($consulta) use (&$orden) {

            if (strpos($consulta->sql, 'update `price_update_runs`') === 0 && strpos(json_encode($consulta->bindings), 'en_proceso') === false) {
                $orden[] = 'corrida';
            }

            if (strpos($consulta->sql, 'update `combos`') === 0) {
                $orden[] = 'combo';
            }
        });

        (new FinalizeSetFinalPrices(self::DUENO, $run->id))->handle();

        $this->assertTrue($this->esta_al_dia($combos[0]), 'Precondición: con la cola sync el combo se recalculó.');
        $this->assertContains('corrida', $orden);
        $this->assertContains('combo', $orden);
        $this->assertLessThan(array_search('combo', $orden), array_search('corrida', $orden), 'La corrida se cierra antes de tocar los combos: ' . implode(',', $orden));
    }

    /**
     * La corrida que se da por perdida por el tope de 2 horas también encola el recálculo: parte de
     * sus lotes pudo haber escrito precios.
     *
     * @test
     */
    public function la_corrida_dada_por_perdida_tambien_encola_el_recalculo()
    {
        Notification::fake();
        Queue::fake();

        list($articulo, $combos) = $this->combos_viejos(2);

        $run = PriceUpdateRun::create([
            'user_id'          => self::DUENO,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 5,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now()->subHours(FinalizeSetFinalPrices::TOPE_HORAS + 1),
        ]);

        (new FinalizeSetFinalPrices(self::DUENO, $run->id))->handle();

        Queue::assertPushed(RecalcularCombosCalculados::class);
    }
}
