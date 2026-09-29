<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El motor sella updated_at y final_price_updated_at al ESCRIBIR, con un solo instante por tanda
 * (misión recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * Antes cada artículo se sellaba con la hora de su cálculo y la fila recién se veía al confirmarse
 * la tanda: un lector incremental ("cambiados desde HH:MM:SS") podía saltearse filas. Se prueba
 * con un reloj que avanza un segundo cada vez que se lo consulta: si el motor sellara al calcular,
 * cada fila tendría otra hora; sellando al escribir, todas comparten la misma, en las dos columnas.
 *
 * Y el sello va SOLO en las filas que ya traían esas columnas para escribir, como el camino por
 * artículo: final_price_updated_at donde cambió el precio, y updated_at donde el save() de
 * setFinalPrice() lo tocaba, que es cuando el costo real salió de alguna cuenta (un descuento, un
 * recargo, el IVA al costo). Por eso los dos casos:
 *  - dueño con IVA al costo: todo artículo con costo lleva updated_at, cambie o no su precio;
 *  - dueño sin ninguna cuenta en el costo: el costo real queda igual al costo, updated_at no se
 *    toca (tampoco lo tocaba el camino por artículo) y la fecha de precio lleva el sello.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Sellos_de_tiempo_al_escribir_Test extends RecalculoEnLoteTestCase
{
    /** @var int Cuántas veces se le preguntó la hora al reloj que avanza. */
    protected $consultas_al_reloj = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Con IVA al costo: todas las filas de la tanda con el mismo instante, en las dos columnas.
     *
     * @return void
     */
    public function test_todas_las_filas_de_la_tanda_llevan_el_mismo_instante_en_las_dos_columnas()
    {
        /*
         * IVA al costo: el costo real sale de una cuenta (costo x 1,21), queda "sucio" para
         * Eloquent y el save() de setFinalPrice() le ponía updated_at = ahora a cada artículo con
         * costo. Sin ninguna cuenta en el costo, updated_at no se toca (el segundo test).
         */
        $dueno = $this->crear_dueno(['aplicar_iva_al_costo' => 1]);

        $cambian = [];
        for ($i = 0; $i < 5; $i++) {
            $cambian[] = $this->crear_articulo($dueno, ['cost' => 100 + $i * 11])->id;
        }

        $no_cambia = $this->crear_articulo($dueno, ['cost' => 480])->id;

        $todos = array_merge($cambian, [$no_cambia]);

        /* Precios estables; después, precio viejo en los que tienen que cambiar. */
        $this->recalcular_con_el_motor($todos, $dueno->id);

        $fecha_de_precio_del_que_no_cambia = DB::table('articles')->where('id', $no_cambia)->value('final_price_updated_at');

        $this->assertNotNull($fecha_de_precio_del_que_no_cambia, 'Precondición: el artículo que no cambia tenía que tener su fecha de precio de la primera pasada.');

        $this->pisar_precio_final($cambian, 1);

        $this->poner_un_reloj_que_avanza();

        RecalculoDePreciosEnLote::recalcular($todos, User::find($dueno->id));

        Carbon::setTestNow();

        $this->assertGreaterThan(count($todos), $this->consultas_al_reloj, 'Precondición: el reloj tenía que avanzar durante el cálculo, o el test no distingue nada.');

        $filas = DB::table('articles')->whereIn('id', $todos)->get(['id', 'updated_at', 'final_price_updated_at'])->keyBy('id');

        $sello = $filas[$cambian[0]]->updated_at;

        $this->assertNotNull($sello);
        $this->assertStringStartsWith('2030-01-15 10:00:', $sello, 'El sello no salió del reloj del test.');

        foreach ($cambian as $id) {
            $this->assertSame($sello, $filas[$id]->updated_at, 'El artículo ' . $id . ' quedó con otro updated_at: se selló al calcular, no al escribir.');
            $this->assertSame($sello, $filas[$id]->final_price_updated_at, 'El artículo ' . $id . ' quedó con final_price_updated_at distinto de updated_at: no es el mismo instante.');
        }

        /* El que no cambió de precio: updated_at con el mismo sello (lo toca como siempre), y la fecha de precio intacta. */
        $this->assertSame($sello, $filas[$no_cambia]->updated_at);
        $this->assertSame($fecha_de_precio_del_que_no_cambia, $filas[$no_cambia]->final_price_updated_at, 'final_price_updated_at apareció en un artículo cuyo precio no cambió.');
    }

    /**
     * Sin ninguna cuenta en el costo: updated_at queda como estaba y la fecha de precio lleva un
     * solo instante para toda la tanda.
     *
     * @return void
     */
    public function test_sin_cuentas_en_el_costo_updated_at_no_se_toca_y_la_fecha_de_precio_lleva_el_sello()
    {
        $dueno = $this->crear_dueno();

        $cambian = [];
        for ($i = 0; $i < 4; $i++) {
            $cambian[] = $this->crear_articulo($dueno, ['cost' => 100 + $i * 11])->id;
        }

        $this->recalcular_con_el_motor($cambian, $dueno->id);

        $this->pisar_precio_final($cambian, 1);

        /* Un updated_at conocido y viejo, para ver que no se mueve. */
        DB::table('articles')->whereIn('id', $cambian)->update(['updated_at' => '2020-05-05 05:05:05']);

        $this->poner_un_reloj_que_avanza();

        RecalculoDePreciosEnLote::recalcular($cambian, User::find($dueno->id));

        Carbon::setTestNow();

        $this->assertGreaterThan(count($cambian), $this->consultas_al_reloj, 'Precondición: el reloj tenía que avanzar durante el cálculo, o el test no distingue nada.');

        $filas = DB::table('articles')->whereIn('id', $cambian)->get(['id', 'updated_at', 'final_price', 'final_price_updated_at'])->keyBy('id');

        $sello = $filas[$cambian[0]]->final_price_updated_at;

        $this->assertNotNull($sello);
        $this->assertStringStartsWith('2030-01-15 10:00:', $sello, 'La fecha de precio no salió del reloj del test.');

        foreach ($cambian as $id) {
            $this->assertNotEquals(1, (float) $filas[$id]->final_price, 'Precondición: el precio del artículo ' . $id . ' tenía que cambiar.');
            $this->assertSame($sello, $filas[$id]->final_price_updated_at, 'El artículo ' . $id . ' quedó con otra fecha de precio: se selló al calcular, no al escribir.');
            $this->assertSame('2020-05-05 05:05:05', $filas[$id]->updated_at, 'El artículo ' . $id . ' recibió updated_at, y el camino por artículo no se lo ponía (el costo real no salió de ninguna cuenta).');
        }
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * Un reloj que avanza un segundo cada vez que alguien le pregunta la hora.
     *
     * @return void
     */
    protected function poner_un_reloj_que_avanza()
    {
        $this->consultas_al_reloj = 0;

        $test = $this;

        Carbon::setTestNow(function () use ($test) {
            $test->consultas_al_reloj++;
            return Carbon::parse('2030-01-15 10:00:00')->addSeconds($test->consultas_al_reloj);
        });
    }
}
