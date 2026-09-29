<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Http\Controllers\Helpers\article\precios\RecalculoDePreciosEnLote;
use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * El contrato de RecalculoDePreciosEnLote que usan los demás (sección 7 del plan de la misión
 * recalculo-precios-motor-rapido, 28/9/2026): la forma del resultado, qué hace con ids raros, el
 * tamaño de tanda, y los tres casos en los que se niega a correr porque no podría garantizar el
 * resultado.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Contrato_del_motor_Test extends RecalculoEnLoteTestCase
{
    /**
     * @return void
     */
    public function test_el_tamanio_de_lote_sale_de_la_config_y_nunca_baja_de_uno()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 250]);
        $this->assertSame(250, RecalculoDePreciosEnLote::tamanio_de_lote());

        config(['app.RECALCULO_PRECIOS_LOTE' => 0]);
        $this->assertSame(1, RecalculoDePreciosEnLote::tamanio_de_lote());

        config(['app.RECALCULO_PRECIOS_LOTE' => 'basura']);
        $this->assertSame(1, RecalculoDePreciosEnLote::tamanio_de_lote());
    }

    /**
     * Ids repetidos, desordenados, borrados o inexistentes: se recalcula cada artículo vivo una vez,
     * en tandas del tamaño configurado, y el resultado tiene las tres claves del contrato.
     *
     * @return void
     */
    public function test_ids_repetidos_borrados_o_inexistentes_y_varias_tandas()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $dueno = $this->crear_dueno();

        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->crear_articulo($dueno, ['cost' => 100 + $i])->id;
        }

        $borrado = $this->crear_articulo($dueno, ['cost' => 50]);
        $borrado->delete();

        $pedidos = array_merge(array_reverse($ids), [$ids[0], $ids[3], $borrado->id, 999999999, 0, -3, '']);

        $resultado = RecalculoDePreciosEnLote::recalcular($pedidos, User::find($dueno->id));

        $this->assertSame(['recalculados', 'cambiaron', 'filas_escritas'], array_keys($resultado));
        $this->assertSame(5, $resultado['recalculados']);
        $this->assertSame($ids, $resultado['cambiaron'], 'Cada artículo vivo una vez, en orden de id.');
        $this->assertSame(5, $resultado['filas_escritas']);

        $this->assertNull(DB::table('articles')->where('id', $borrado->id)->value('final_price'), 'Un artículo borrado no se recalcula.');
    }

    /**
     * @return void
     */
    public function test_sin_ids_no_hace_nada()
    {
        $dueno = $this->crear_dueno();

        $this->assertSame(
            ['recalculados' => 0, 'cambiaron' => [], 'filas_escritas' => 0],
            RecalculoDePreciosEnLote::recalcular([], User::find($dueno->id))
        );
    }

    /**
     * Con un empleado en vez del dueño, el cálculo usaría la configuración equivocada sin ningún
     * error: se niega.
     *
     * @return void
     */
    public function test_se_niega_a_recalcular_con_un_empleado()
    {
        $dueno = $this->crear_dueno();
        $article = $this->crear_articulo($dueno, ['cost' => 100]);

        $empleado = User::create([
            'name'     => 'zz Empleado recalculo',
            'email'    => 'recalculo-empleado-' . uniqid('', true) . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        RecalculoDePreciosEnLote::recalcular([$article->id], User::find($empleado->id));
    }

    /**
     * Si el modo lote ya está encendido (otro cálculo en lote a mitad de camino en este proceso),
     * activarlo de nuevo le tiraría lo pendiente: se niega sin tocarlo.
     *
     * @return void
     */
    public function test_se_niega_si_el_modo_lote_ya_esta_encendido_y_no_se_lo_apaga_al_otro()
    {
        $dueno = $this->crear_dueno();
        $article = $this->crear_articulo($dueno, ['cost' => 100]);

        PreciosEnLote::activar($dueno, $dueno->id);

        $tiro = null;

        try {
            RecalculoDePreciosEnLote::recalcular([$article->id], User::find($dueno->id));
        } catch (\LogicException $e) {
            $tiro = $e;
        }

        $this->assertNotNull($tiro);
        $this->assertTrue(PreciosEnLote::esta_activo(), 'El motor apagó el modo lote de otro cálculo.');

        PreciosEnLote::descartar();
    }

    /**
     * Con el interruptor de pruebas PreciosEnLote::deshabilitar() prendido, el modo lote no se
     * enciende y el motor no puede garantizar el resultado: se niega y no escribe nada.
     *
     * @return void
     */
    public function test_se_niega_si_el_modo_lote_esta_deshabilitado()
    {
        $dueno = $this->crear_dueno();
        $article = $this->crear_articulo($dueno, ['cost' => 100]);

        PreciosEnLote::deshabilitar(true);

        $tiro = null;

        try {
            RecalculoDePreciosEnLote::recalcular([$article->id], User::find($dueno->id));
        } catch (\LogicException $e) {
            $tiro = $e;
        }

        PreciosEnLote::deshabilitar(false);

        $this->assertNotNull($tiro);
        $this->assertNull(DB::table('articles')->where('id', $article->id)->value('final_price'), 'Con el modo lote deshabilitado no se tenía que escribir nada.');
    }
}
