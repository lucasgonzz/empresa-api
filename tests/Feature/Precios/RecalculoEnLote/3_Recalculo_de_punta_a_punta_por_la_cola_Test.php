<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\ArticlePriceTypeMoneda;
use App\Models\BackgroundProcess;
use App\Models\PriceUpdateRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * El recálculo de punta a punta por la cola (misión recalculo-precios-motor-rapido, 28/9/2026):
 * productor → lotes (con el motor) → finalizador, con la cola `sync` de phpunit.xml, así todo
 * corre inline adentro del handle() del productor.
 *
 * Los tres alcances que el plan nombra: por proveedor, por el dólar global (la unión de costo en
 * dólares y listas por moneda que cotizan desde otra moneda) y por el dólar de un proveedor (la
 * intersección: solo los artículos de ese proveedor con costo en dólares). En cada uno:
 * price_update_run_articles tiene exactamente los que cambiaron, lo que quedó afuera del alcance no
 * se tocó, y el registro visible cierra con su total de lotes y al 100 %.
 *
 * El lote se baja a 2 artículos con config(): así un puñado de artículos son varios lotes y se
 * prueba el reparto de verdad.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Recalculo_de_punta_a_punta_por_la_cola_Test extends RecalculoEnLoteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'sync',
            config('queue.connections.' . config('queue.default') . '.driver'),
            'Este test necesita la cola inline para correr el recálculo de punta a punta.'
        );

        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);
    }

    /**
     * Por proveedor: se recalculan todos sus artículos (y solo los de ese dueño), en tres lotes.
     *
     * @return void
     */
    public function test_por_proveedor_recalcula_sus_articulos_en_lotes_y_cierra_la_corrida()
    {
        $dueno = $this->crear_dueno(['aplicar_iva_al_costo' => 1]);

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);
        $otro      = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);

        $del_proveedor = [];
        for ($i = 0; $i < 5; $i++) {
            $del_proveedor[] = $this->crear_articulo($dueno, ['cost' => 100 + $i, 'provider_id' => $proveedor->id])->id;
        }

        $del_otro = $this->crear_articulo($dueno, ['cost' => 300, 'provider_id' => $otro->id])->id;

        /* Un artículo de OTRA cuenta con el mismo provider_id (base compartida): no puede entrar. */
        $otro_dueno  = $this->crear_dueno();
        $de_otra_cuenta = $this->crear_articulo($otro_dueno, ['cost' => 999, 'provider_id' => $proveedor->id])->id;
        $this->actingAs(\App\Models\User::find($dueno->id), 'web');

        /* Calentamiento y precios viejos en tres de los cinco. */
        $this->recalcular_como_hoy(array_merge($del_proveedor, [$del_otro]), $dueno->id);
        $pisados = [$del_proveedor[0], $del_proveedor[2], $del_proveedor[4]];
        $this->pisar_precio_final($pisados, 1);
        $this->pisar_precio_final([$del_otro, $de_otra_cuenta], 1);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name));

        $this->assertSame('terminado', $run->status);
        $this->assertSame(3, (int) $run->total_chunks, 'Cinco artículos en lotes de dos son tres lotes.');
        $this->assertSame(3, (int) $run->processed_chunks);
        $this->assertSame(3, (int) $run->articles_updated);

        $this->assertEqualsCanonicalizing($pisados, $this->registrados($run), 'price_update_run_articles tiene que tener exactamente los que cambiaron.');

        $this->assertEquals(1, (float) DB::table('articles')->where('id', $del_otro)->value('final_price'), 'Un artículo de otro proveedor no se tenía que recalcular.');
        $this->assertEquals(1, (float) DB::table('articles')->where('id', $de_otra_cuenta)->value('final_price'), 'Un artículo de otra cuenta no puede entrar al recálculo.');

        $this->assertRegistroVisibleCompleto($run, 3);
    }

    /**
     * Dólar global: la unión de los artículos con costo en dólares y los que tienen alguna lista
     * por moneda que cotiza desde otra moneda (uno con dos entradas que cotizan, que tiene que
     * entrar una sola vez). Los demás quedan afuera.
     *
     * @return void
     */
    public function test_por_dolar_global_recalcula_la_union_de_costo_en_dolares_y_cotizacion_cruzada()
    {
        $dueno = $this->crear_dueno();

        $lista_1 = $this->crear_lista($dueno, 'Lista 1', 30, 1);
        $lista_2 = $this->crear_lista($dueno, 'Lista 2', 40, 2);

        $en_dolares = [];
        for ($i = 0; $i < 3; $i++) {
            $en_dolares[] = $this->crear_articulo($dueno, ['cost' => 10 + $i, 'cost_in_dollars' => 1])->id;
        }

        $que_cotizan = [];
        for ($i = 0; $i < 2; $i++) {
            $que_cotizan[] = $this->crear_articulo($dueno, ['cost' => 500 + $i])->id;
        }

        /* Uno con dos listas que cotizan: entra una sola vez. */
        $this->entrada($que_cotizan[0], $lista_1, 1);
        $this->entrada($que_cotizan[0], $lista_2, 1);
        $this->entrada($que_cotizan[1], $lista_1, 1);

        /* Uno en dólares Y que cotiza. */
        $los_dos = $this->crear_articulo($dueno, ['cost' => 7, 'cost_in_dollars' => 1])->id;
        $this->entrada($los_dos, $lista_1, 1);

        /* Afuera: sin dólares, con una entrada que NO cotiza, y uno borrado. */
        $afuera = [];
        $afuera[] = $this->crear_articulo($dueno, ['cost' => 800])->id;
        $afuera[] = $this->crear_articulo($dueno, ['cost' => 900])->id;
        $this->entrada($afuera[1], $lista_1, 0);

        $borrado = $this->crear_articulo($dueno, ['cost' => 15, 'cost_in_dollars' => 1]);
        $borrado->delete();

        $en_el_alcance = array_merge($en_dolares, $que_cotizan, [$los_dos]);

        $this->pisar_precio_final(array_merge($en_el_alcance, $afuera, [$borrado->id]), 1);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, null, null, true, 'dolar', 'Cotización cargada a mano'));

        $this->assertSame('terminado', $run->status);
        $this->assertSame(3, (int) $run->total_chunks, 'Seis artículos en el alcance, en lotes de dos.');
        $this->assertEqualsCanonicalizing($en_el_alcance, $this->registrados($run));

        foreach (array_merge($afuera, [$borrado->id]) as $id) {
            $this->assertEquals(1, (float) DB::table('articles')->where('id', $id)->value('final_price'), 'El artículo ' . $id . ' estaba fuera del alcance del dólar y se recalculó.');
        }

        $this->assertRegistroVisibleCompleto($run, 3);
    }

    /**
     * Dólar de un proveedor (from_model_id + from_dolar = true): solo los artículos de ese
     * proveedor con costo en dólares. Ni los del proveedor en pesos, ni los en dólares de otro.
     *
     * @return void
     */
    public function test_por_dolar_del_proveedor_recalcula_solo_sus_articulos_en_dolares()
    {
        $dueno = $this->crear_dueno();

        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 20, 'dolar' => 1500]);
        $otro      = $this->crear_proveedor($dueno, ['percentage_gain' => 20, 'dolar' => 1500]);

        $en_dolares = [];
        for ($i = 0; $i < 3; $i++) {
            $en_dolares[] = $this->crear_articulo($dueno, ['cost' => 5 + $i, 'cost_in_dollars' => 1, 'provider_id' => $proveedor->id])->id;
        }

        $en_pesos         = $this->crear_articulo($dueno, ['cost' => 5000, 'provider_id' => $proveedor->id])->id;
        $de_otro_en_dolar = $this->crear_articulo($dueno, ['cost' => 5, 'cost_in_dollars' => 1, 'provider_id' => $otro->id])->id;

        $this->pisar_precio_final(array_merge($en_dolares, [$en_pesos, $de_otro_en_dolar]), 1);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, true, 'proveedor', $proveedor->name));

        $this->assertSame('terminado', $run->status);
        $this->assertSame(2, (int) $run->total_chunks);
        $this->assertEqualsCanonicalizing($en_dolares, $this->registrados($run));

        $this->assertEquals(1, (float) DB::table('articles')->where('id', $en_pesos)->value('final_price'), 'Un artículo en pesos no lee el dólar del proveedor: no se tenía que recalcular.');
        $this->assertEquals(1, (float) DB::table('articles')->where('id', $de_otro_en_dolar)->value('final_price'), 'Un artículo de otro proveedor no se tenía que recalcular.');

        $this->assertRegistroVisibleCompleto($run, 2);
    }

    /**
     * Los tres jobs eligen su cola en el constructor: 'excel' en el shared hosting (la de los jobs
     * pesados, con --memory=512), 'default' (null) en el VPS. El re-despacho del finalizador
     * hereda la suya.
     *
     * @return void
     */
    public function test_los_tres_jobs_van_a_la_cola_excel_en_el_shared_y_a_default_en_el_vps()
    {
        $dueno = $this->crear_dueno();

        config(['app.VPS' => false]);

        $this->assertSame('excel', (new ProcessSetFinalPrices($dueno->id))->queue);
        $this->assertSame('excel', (new ProcessChunkSetFinalPrices([1], $dueno->id, null))->queue);
        $this->assertSame('excel', (new FinalizeSetFinalPrices($dueno->id, 1))->queue);

        config(['app.VPS' => true]);

        $this->assertNull((new ProcessSetFinalPrices($dueno->id))->queue);
        $this->assertNull((new ProcessChunkSetFinalPrices([1], $dueno->id, null))->queue);
        $this->assertNull((new FinalizeSetFinalPrices($dueno->id, 1))->queue);

        /* Los lotes se reintentan (la tanda es atómica); el productor no (abriría otra corrida). */
        $chunk = new ProcessChunkSetFinalPrices([1], $dueno->id, null);
        $this->assertSame(3, $chunk->tries);
        $this->assertSame(600, $chunk->timeout);
        $this->assertSame(10, $chunk->backoff);
        $this->assertSame(1, (new ProcessSetFinalPrices($dueno->id))->tries);
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Corre el productor (con la cola inline corren también los lotes y el finalizador) y
     * devuelve la corrida que abrió.
     *
     * @param  \App\Jobs\ProcessSetFinalPrices $job
     * @return \App\Models\PriceUpdateRun
     */
    protected function correr_el_productor($job)
    {
        $ids_previos = PriceUpdateRun::where('user_id', $job->user_id)->pluck('id')->toArray();

        $job->handle();

        $run = PriceUpdateRun::where('user_id', $job->user_id)
                                ->whereNotIn('id', $ids_previos)
                                ->orderBy('id', 'DESC')
                                ->first();

        $this->assertNotNull($run, 'El productor no abrió ninguna corrida.');

        return $run;
    }

    /**
     * @param  \App\Models\PriceUpdateRun $run
     * @return int[]
     */
    protected function registrados($run)
    {
        return DB::table('price_update_run_articles')
                    ->where('price_update_run_id', $run->id)
                    ->pluck('article_id')
                    ->map(function ($id) { return (int) $id; })
                    ->all();
    }

    /**
     * El registro visible de la corrida cerró completo, con el total de lotes y al 100 %.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @param  int                        $lotes
     * @return void
     */
    protected function assertRegistroVisibleCompleto($run, $lotes)
    {
        $proceso = BackgroundProcessHelper::por_referencia($run, false);

        $this->assertNotNull($proceso, 'La corrida no dejó registro visible.');
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame('lotes', $proceso->unidad);
        $this->assertSame($lotes, (int) $proceso->total);
        $this->assertSame($lotes, (int) $proceso->procesados);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame((int) $run->articles_updated, (int) $proceso->resultado()['articulos_actualizados']);
    }

    /**
     * Una entrada de price_type_monedas en pesos, que cotiza (o no) desde otra moneda.
     *
     * @param  int                   $article_id
     * @param  \App\Models\PriceType $lista
     * @param  int                   $cotiza
     * @return void
     */
    protected function entrada($article_id, $lista, $cotiza)
    {
        ArticlePriceTypeMoneda::create([
            'article_id'                => $article_id,
            'price_type_id'             => $lista->id,
            'moneda_id'                 => self::ARS,
            'percentage'                => 30,
            'final_price'               => 0,
            'setear_precio_final'       => 0,
            'cotizar_desde_otra_moneda' => $cotiza,
        ]);
    }
}
