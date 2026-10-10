<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Events\BackgroundProcessUpdated;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\PriceUpdateRun;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * El aviso "Precios actualizados" de un cambio en la configuración cuenta los artículos que
 * cambiaron (misión aviso-recalculo-cero-articulos, 10/10/2026).
 *
 * Origen: filmando el centro de recursos en demo2 (4.3.8, 46 artículos), un sondeo
 * (_video/centro8/s172.js) leyó "0 artículos cambiaron de precio · Se actualizaron precios de 20
 * proveedores" al poner el margen de ganancia global en 10, aunque los 46 precios cambiaron; la
 * toma del mismo cambio mostró 46. Se sospechó del conteo (price_update_run_articles contra el
 * finalizador) y de dos corridas del mismo dueño pisándose.
 *
 * Lo que dicen estos tests: la API cuenta bien en los dos sentidos (vacío → 10 y 10 → vacío), por
 * el request real de la pantalla, y dos corridas encoladas antes de que corra el worker cuentan
 * cada una lo suyo, sin que el finalizador cierre antes de que su lote registre. El único cero
 * que la API puede mandar es el de una corrida que de verdad no movió nada, y ese viaja con
 * sin_cambios = true: el modal muestra "No cambió ningún precio", nunca "0 artículos cambiaron de
 * precio".
 *
 * El "0" del sondeo era el SPA (PriceUpdateResultModal.vue): el número grande arranca en 0 y
 * cuenta hasta el total recién en el evento `shown` de bootstrap-vue, al terminar el fundido,
 * mientras que la clase `show` —la que esperaba el sondeo— se pone al empezarlo. Leído en el
 * primer sondeo, el modal todavía dice 0 (y no muestra "Cargando los proveedores...", que también
 * arranca en `shown`). Diagnóstico completo en el informe de la misión.
 *
 * Todo corre con la cola `sync` de phpunit.xml salvo el test de las dos corridas, que usa
 * Queue::fake() para correr los jobs en el orden que se quiere probar.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Aviso_del_margen_global_cuenta_lo_que_cambio_Test extends RecalculoEnLoteTestCase
{
    /** Proveedores del catálogo del test. */
    const PROVEEDORES = 3;

    /** Artículos por proveedor. */
    const POR_PROVEEDOR = 2;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var int[] */
    protected $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->dueno = $this->crear_dueno();

        /*
         * Como la CINTA de demo2: margen propio del artículo y el margen general de la cuenta
         * vacío. Seis artículos de tres proveedores, con el precio ya calculado: el punto de
         * partida es un catálogo estable, donde recalcular sin cambiar nada no mueve ningún
         * precio.
         */
        for ($p = 0; $p < self::PROVEEDORES; $p++) {

            $proveedor = $this->crear_proveedor($this->dueno);

            for ($a = 0; $a < self::POR_PROVEEDOR; $a++) {
                $this->ids[] = $this->crear_articulo($this->dueno, [
                    'cost'            => 1000 + $p * 100 + $a * 37,
                    'percentage_gain' => 50,
                    'provider_id'     => $proveedor->id,
                ])->id;
            }
        }

        $this->recalcular_con_el_motor($this->ids, $this->dueno->id);
    }

    /**
     * El margen global de vacío a 10 por la pantalla: cambian los seis precios y el aviso dice
     * seis artículos de tres proveedores.
     *
     * @return void
     */
    public function test_el_margen_global_en_10_avisa_todos_los_articulos_que_cambiaron()
    {
        $this->assertColaInline();

        $antes = $this->precios();

        $run = $this->guardar(['percentage_gain' => 10]);

        $this->assertSame('configuracion_usuario', $run->origen);
        $this->assertSame('terminado', $run->status);
        $this->assertSame(count($this->ids), (int) $run->articles_updated);

        foreach ($this->precios() as $id => $precio) {
            $this->assertNotEquals($antes[$id], $precio, 'El artículo ' . $id . ' tenía que cambiar de precio con el margen general en 10.');
        }

        $this->assertAviso($run, count($this->ids), self::PROVEEDORES, false);
    }

    /**
     * De vuelta a vacío (los "dos guardados seguidos" de s172): el segundo aviso también cuenta
     * los seis, y los precios vuelven exactos.
     *
     * @return void
     */
    public function test_vaciarlo_despues_vuelve_a_contar_todos_y_los_precios_vuelven_exactos()
    {
        $this->assertColaInline();

        $antes = $this->precios();

        $con_10 = $this->guardar(['percentage_gain' => 10]);
        $vacio  = $this->guardar(['percentage_gain' => '']);

        $this->assertNull(User::find($this->dueno->id)->percentage_gain, 'Precondición: el margen general tenía que quedar vacío.');

        $this->assertNotSame((int) $con_10->id, (int) $vacio->id, 'Cada guardado abre su propia corrida.');

        $this->assertSame(count($this->ids), (int) $con_10->articles_updated);
        $this->assertSame(count($this->ids), (int) $vacio->articles_updated);

        $this->assertEquals($antes, $this->precios(), 'Vaciando el margen general los precios tenían que volver exactos.');

        $this->assertAviso($con_10, count($this->ids), self::PROVEEDORES, false);
        $this->assertAviso($vacio, count($this->ids), self::PROVEEDORES, false);
    }

    /**
     * Dos guardados que llegan antes de que el worker levante la primera corrida (margen y
     * redondeo). Se corren los jobs en el peor orden: los dos productores, los finalizadores
     * ANTES que los lotes, los lotes y otra vez los finalizadores.
     *
     *  - Un finalizador que corre antes que su lote no cierra: se re-despacha.
     *  - La primera corrida calcula con la configuración que hay cuando corre su lote (las dos
     *    cosas ya guardadas) y cuenta los seis; la segunda ya no encuentra nada que mover.
     *  - Cada una cuenta solo sus filas de price_update_run_articles.
     *  - El cero de la segunda viaja con sin_cambios = true: el modal dice "No cambió ningún
     *    precio", no "0 artículos cambiaron de precio".
     *
     * @return void
     */
    public function test_dos_corridas_encoladas_antes_del_worker_cuentan_cada_una_lo_suyo()
    {
        Queue::fake();

        $this->putJson('api/user/' . $this->dueno->id, $this->payload(['percentage_gain' => 10]))->assertStatus(200);
        $this->putJson('api/user/' . $this->dueno->id, $this->payload(['modo_redondeo' => 'decenas']))->assertStatus(200);

        $productores = Queue::pushed(ProcessSetFinalPrices::class)->values();

        $this->assertCount(2, $productores, 'Precondición: cada guardado tenía que encolar su recálculo.');

        $ids_previos = PriceUpdateRun::where('user_id', $this->dueno->id)->pluck('id')->toArray();

        foreach ($productores as $productor) {
            $productor->handle();
        }

        $corridas = PriceUpdateRun::where('user_id', $this->dueno->id)
                                    ->whereNotIn('id', $ids_previos)
                                    ->orderBy('id')
                                    ->get();

        $this->assertCount(2, $corridas);

        $primera = $corridas[0];
        $segunda = $corridas[1];

        /* Los finalizadores encolados hasta acá (dos por corrida), antes que cualquier lote. */
        $finalizadores_antes = Queue::pushed(FinalizeSetFinalPrices::class)->values();

        foreach ($finalizadores_antes as $finalizador) {
            $finalizador->handle();
        }

        foreach ($corridas as $corrida) {
            $this->assertSame('en_proceso', $corrida->fresh()->status, 'El finalizador cerró la corrida ' . $corrida->id . ' antes de que corriera su lote.');
        }

        Notification::assertNothingSent();

        /* Los lotes, en el orden en que se encolaron (primero los de la primera corrida). */
        foreach (Queue::pushed(ProcessChunkSetFinalPrices::class)->values() as $lote) {
            $lote->handle();
        }

        /* Los re-despachos (y cualquier otro finalizador) cierran. */
        foreach (Queue::pushed(FinalizeSetFinalPrices::class)->values() as $finalizador) {
            $finalizador->handle();
        }

        $primera = $primera->fresh();
        $segunda = $segunda->fresh();

        $this->assertSame('terminado', $primera->status);
        $this->assertSame(count($this->ids), (int) $primera->articles_updated);
        $this->assertEqualsCanonicalizing($this->ids, $this->registrados($primera));

        $this->assertSame('sin_cambios', $segunda->status);
        $this->assertSame(0, (int) $segunda->articles_updated);
        $this->assertSame([], $this->registrados($segunda), 'La segunda corrida registró artículos que ya había cambiado la primera.');

        $this->assertAviso($primera, count($this->ids), self::PROVEEDORES, false);
        $this->assertAviso($segunda, 0, 0, true);

        Notification::assertSentToTimes(User::find($this->dueno->id), GlobalNotification::class, 2);
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * @return void
     */
    protected function assertColaInline()
    {
        $this->assertSame(
            'sync',
            config('queue.connections.' . config('queue.default') . '.driver'),
            'Este test necesita la cola inline para correr el recálculo de punta a punta.'
        );
    }

    /**
     * El modelo entero, con los cambios encima: lo que postea la pantalla (mismo armado que
     * 13_Dolar_junto_con_otro_parametro_Test).
     *
     * @param  array $cambios
     * @return array
     */
    protected function payload(array $cambios)
    {
        return array_merge(User::find($this->dueno->id)->toArray(), $cambios);
    }

    /**
     * El PUT de "Guardar y cerrar" con la cola inline: devuelve la corrida que abrió (y ya cerró).
     *
     * @param  array $cambios
     * @return \App\Models\PriceUpdateRun
     */
    protected function guardar(array $cambios)
    {
        $ids_previos = PriceUpdateRun::where('user_id', $this->dueno->id)->pluck('id')->toArray();

        $this->putJson('api/user/' . $this->dueno->id, $this->payload($cambios))->assertStatus(200);

        $run = PriceUpdateRun::where('user_id', $this->dueno->id)
                                ->whereNotIn('id', $ids_previos)
                                ->orderBy('id', 'DESC')
                                ->first();

        $this->assertNotNull($run, 'El guardado no abrió ninguna corrida de recálculo.');

        return $run;
    }

    /**
     * @return array id => final_price
     */
    protected function precios()
    {
        return DB::table('articles')
                    ->whereIn('id', $this->ids)
                    ->orderBy('id')
                    ->pluck('final_price', 'id')
                    ->map(function ($precio) { return (float) $precio; })
                    ->all();
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
     * Salió el aviso de esa corrida con lo que el modal va a mostrar.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @param  int                        $articulos
     * @param  int                        $proveedores
     * @param  bool                       $sin_cambios
     * @return void
     */
    protected function assertAviso($run, $articulos, $proveedores, $sin_cambios)
    {
        Notification::assertSentTo(User::find($this->dueno->id), GlobalNotification::class, function ($notification) use ($run, $articulos, $proveedores, $sin_cambios) {
            return $notification->notification_modal === 'price_update_result'
                && (int) $notification->price_stats['run_id'] === (int) $run->id
                && $notification->price_stats['articulos_actualizados'] === $articulos
                && $notification->price_stats['proveedores_cantidad'] === $proveedores
                && $notification->price_stats['sin_cambios'] === $sin_cambios
                && $notification->price_stats['origen'] === 'configuracion_usuario';
        });
    }
}
