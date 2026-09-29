<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * El dólar junto con otro parámetro de precios en el mismo guardado (misión
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * UserController::check_actualizar_articulos() acotaba el recálculo a los artículos que dependen del
 * dólar apenas cambiaba el dólar, aunque en el mismo guardado cambiara también el redondeo, el
 * margen general o el IVA: el resto del catálogo se quedaba con lo viejo. Ahora:
 *  - solo el dólar → alcance del dólar (from_dolar = true, origen `dolar`), como siempre;
 *  - el dólar y otro parámetro → recálculo completo (from_dolar = false, origen
 *    `configuracion_usuario`);
 *  - otro parámetro sin el dólar → recálculo completo, como siempre.
 *
 * Por el request real (PUT api/user/{id}) con el modelo entero en el payload, igual que la
 * pantalla (mismo armado que tests/Feature/Preferencias/2_Modo_redondeo_Test). Usuario 500 de la
 * semilla, que es dueño.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Dolar_junto_con_otro_parametro_Test extends RecalculoEnLoteTestCase
{
    /** @var \App\Models\User */
    protected $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::find(500);

        if (is_null($this->usuario)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        /* Punto de partida conocido: sin ningún modo de redondeo y con margen general en null. */
        foreach (['redondear_miles_en_vender', 'redondear_centenas_en_vender', 'redondear_precios_en_decenas', 'redondear_de_a_50', 'redondear_precios_en_centavos'] as $columna) {
            $this->usuario->$columna = 0;
        }
        $this->usuario->percentage_gain = null;
        $this->usuario->dollar = 1000;
        $this->usuario->save();

        $this->actingAs(User::find(500), 'web');

        Queue::fake();
    }

    /**
     * @return void
     */
    public function test_solo_el_dolar_recalcula_con_el_alcance_del_dolar()
    {
        $this->guardar(['dollar' => 1250]);

        $this->assertRecalculo(true, 'dolar');
    }

    /**
     * @return void
     */
    public function test_el_dolar_y_el_redondeo_juntos_recalculan_todo_el_catalogo()
    {
        $this->guardar(['dollar' => 1250, 'modo_redondeo' => 'cincuenta']);

        $this->assertSame(1, (int) User::find(500)->redondear_de_a_50, 'Precondición: el redondeo tenía que haber cambiado en el mismo guardado.');

        $this->assertRecalculo(false, 'configuracion_usuario');
    }

    /**
     * @return void
     */
    public function test_el_dolar_y_el_margen_general_juntos_recalculan_todo_el_catalogo()
    {
        $this->guardar(['dollar' => 1250, 'percentage_gain' => 15]);

        $this->assertRecalculo(false, 'configuracion_usuario');
    }

    /**
     * @return void
     */
    public function test_otro_parametro_sin_el_dolar_recalcula_todo_como_siempre()
    {
        $this->guardar(['modo_redondeo' => 'centavos']);

        $this->assertRecalculo(false, 'configuracion_usuario');
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * El PUT de la pantalla: el modelo entero, con lo que se quiera cambiar encima.
     *
     * @param  array $cambios
     * @return void
     */
    protected function guardar(array $cambios)
    {
        $payload = array_merge(User::find(500)->toArray(), $cambios);

        $this->putJson('api/user/500', $payload)->assertStatus(200);
    }

    /**
     * Se encoló UN recálculo, con ese alcance y ese origen, del dueño.
     *
     * @param  bool   $from_dolar
     * @param  string $origen
     * @return void
     */
    protected function assertRecalculo($from_dolar, $origen)
    {
        Queue::assertPushed(ProcessSetFinalPrices::class, 1);

        Queue::assertPushed(ProcessSetFinalPrices::class, function ($job) use ($from_dolar, $origen) {
            return (int) $job->user_id === 500
                && is_null($job->from_model_id)
                && (bool) $job->from_dolar === $from_dolar
                && $job->origen === $origen;
        });
    }
}
