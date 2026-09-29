<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\BackgroundProcess;
use App\Models\PriceUpdateRun;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * El recálculo por el guardado de una categoría no avisa cuando no cambió ningún precio
 * (misión recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * El aviso "Precios actualizados" va a todas las sesiones del dueño. En una cuenta con listas de
 * precio por categoría, CADA guardado de una categoría dispara el recálculo, y con cero cambios
 * era un modal en cada computadora del comercio sin nada que contar. Regla: origen `categoria` +
 * corrida `sin_cambios` → sin aviso (la píldora igual cierra con "Sin cambios"); con cambios, el
 * aviso de siempre; los demás orígenes, como siempre (avisan también sin cambios).
 *
 * Las dos puertas por las que una corrida cierra sin error: el finalizador (hubo artículos que
 * recalcular) y el productor cuando no encuentra ninguno (cerrar_sin_articulos()). Todo corre de
 * punta a punta con la cola `sync` de phpunit.xml.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Categoria_sin_cambios_no_avisa_Test extends RecalculoEnLoteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'sync',
            config('queue.connections.' . config('queue.default') . '.driver'),
            'Este test necesita la cola inline para correr el recálculo de punta a punta.'
        );

        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);
    }

    /**
     * Categoría con artículos cuyo precio no se mueve: la corrida cierra sin cambios, la
     * píldora lo dice y no sale ningún aviso.
     *
     * @return void
     */
    public function test_categoria_sin_cambios_no_avisa_y_la_pildora_dice_sin_cambios()
    {
        $dueno     = $this->crear_dueno();
        $categoria = $this->crear_categoria($dueno);
        $ids       = $this->articulos_con_precio_estable($dueno, ['category_id' => $categoria->id], 3);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'category_id', $categoria->id, false, 'categoria', $categoria->name));

        $this->assertSame('sin_cambios', $run->status);
        $this->assertSame(1, (int) $run->total_chunks, 'Precondición: tenía que haber pasado por el finalizador, con al menos un lote.');

        Notification::assertNothingSent();

        $this->assertPildoraCerradaCon($run, 'Sin cambios');
    }

    /**
     * Categoría donde algún precio cambia: el aviso de siempre.
     *
     * @return void
     */
    public function test_categoria_con_cambios_avisa_como_siempre()
    {
        $dueno     = $this->crear_dueno();
        $categoria = $this->crear_categoria($dueno);
        $ids       = $this->articulos_con_precio_estable($dueno, ['category_id' => $categoria->id], 3);

        $this->pisar_precio_final([$ids[1]], 1);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'category_id', $categoria->id, false, 'categoria', $categoria->name));

        $this->assertSame('terminado', $run->status);
        $this->assertSame(1, (int) $run->articles_updated);

        $this->assertAvisoDePreciosActualizados($dueno, $run);

        $this->assertPildoraCerradaCon($run, 'Terminado');
    }

    /**
     * Otro origen (proveedor) sin cambios: avisa, como siempre.
     *
     * @return void
     */
    public function test_otro_origen_sin_cambios_sigue_avisando()
    {
        $dueno     = $this->crear_dueno();
        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);

        $this->articulos_con_precio_estable($dueno, ['provider_id' => $proveedor->id], 3);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name));

        $this->assertSame('sin_cambios', $run->status);

        $this->assertAvisoDePreciosActualizados($dueno, $run);
    }

    /**
     * Categoría SIN artículos (la rama del productor que cierra sin repartir nada): sin aviso,
     * y la píldora cerrada con "Sin cambios".
     *
     * @return void
     */
    public function test_categoria_sin_articulos_no_avisa()
    {
        $dueno     = $this->crear_dueno();
        $categoria = $this->crear_categoria($dueno);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'category_id', $categoria->id, false, 'categoria', $categoria->name));

        $this->assertSame('sin_cambios', $run->status);
        $this->assertSame(0, (int) $run->total_chunks, 'Precondición: tenía que cerrar en el productor, sin repartir lotes.');

        Notification::assertNothingSent();

        $this->assertPildoraCerradaCon($run, 'Sin cambios');
    }

    /**
     * Otro origen sin artículos: avisa, como siempre.
     *
     * @return void
     */
    public function test_otro_origen_sin_articulos_sigue_avisando()
    {
        $dueno     = $this->crear_dueno();
        $proveedor = $this->crear_proveedor($dueno);

        $run = $this->correr_el_productor(new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name));

        $this->assertSame('sin_cambios', $run->status);
        $this->assertSame(0, (int) $run->total_chunks);

        $this->assertAvisoDePreciosActualizados($dueno, $run);
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Artículos del dueño con costo, recalculados una vez con el motor: un segundo recálculo no
     * les mueve el precio.
     *
     * @param  \App\Models\User $dueno
     * @param  array            $atributos
     * @param  int              $cantidad
     * @return int[]
     */
    protected function articulos_con_precio_estable($dueno, array $atributos, $cantidad)
    {
        $ids = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $ids[] = $this->crear_articulo($dueno, array_merge(['cost' => 100 + $i * 7, 'percentage_gain' => 30], $atributos))->id;
        }

        $this->recalcular_con_el_motor($ids, $dueno->id);

        return $ids;
    }

    /**
     * Corre el productor inline y devuelve la corrida que abrió.
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
     * Salió exactamente un aviso "Precios actualizados" para el dueño, con el modal de esta corrida.
     *
     * @param  \App\Models\User           $dueno
     * @param  \App\Models\PriceUpdateRun $run
     * @return void
     */
    protected function assertAvisoDePreciosActualizados($dueno, $run)
    {
        Notification::assertSentToTimes(User::find($dueno->id), GlobalNotification::class, 1);

        Notification::assertSentTo(User::find($dueno->id), GlobalNotification::class, function ($notification) use ($run) {
            return $notification->message_text === 'Precios actualizados'
                && isset($notification->price_stats['run_id'])
                && (int) $notification->price_stats['run_id'] === (int) $run->id;
        });
    }

    /**
     * La píldora de la corrida cerró completa, con esa etapa.
     *
     * @param  \App\Models\PriceUpdateRun $run
     * @param  string                     $etapa
     * @return void
     */
    protected function assertPildoraCerradaCon($run, $etapa)
    {
        $proceso = BackgroundProcessHelper::por_referencia($run, false);

        $this->assertNotNull($proceso, 'La corrida no dejó registro visible.');
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame($etapa, $proceso->etapa);
    }
}
