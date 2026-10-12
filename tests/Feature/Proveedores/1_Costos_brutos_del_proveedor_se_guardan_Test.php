<?php

namespace Tests\Feature\Proveedores;

use App\Jobs\ProcessSetFinalPrices;
use App\Models\Provider;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Mision `proveedor-precios-incluyen-iva` (8/10/2026) — el tilde de la ficha del proveedor "Los
 * costos de este proveedor son BRUTOS (ya tienen el IVA adentro)" SE GUARDA.
 *
 * EL DEFECTO: la columna `providers.precios_incluyen_iva` existe desde el 20/7/2026 (boolean,
 * default false) y el modelo la castea a boolean, pero ProviderController::store() y ::update()
 * asignan campo por campo y nunca la tocaban (`git log -S` sobre el controller: nunca estuvo). El
 * dueño marcaba el tilde, guardaba la ficha y el tilde volvia apagado. Consecuencia: la precarga de
 * la compra (`provider_order.js`, `prefill_prop_on_select` con `source_prop: 'precios_incluyen_iva'`)
 * traia SIEMPRE el tilde apagado, y el asistente IA (PropuestaCompraConFacturaIaHelper /
 * ProveedorDeLaFacturaIaHelper) leia siempre false. Medido por Lucas en demo2 (empresa 4.3.8).
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO:
 *
 *   1. El alta (POST api/provider) guarda el tilde; sin la clave nace apagado (el default de la
 *      columna, igual que hasta hoy para quien no lo manda).
 *   2. La edicion (PUT api/provider/{id}) con el modelo ENTERO, como lo manda la SPA, lo prende y
 *      lo apaga.
 *   3. Los valores como los manda un formulario ('false', '0', 0 / 'true', '1', 1) se leen como un
 *      tilde: `(bool) 'false'` es TRUE en PHP, asi que un cast crudo guardaria prendido un "false".
 *   4. Un PUT que NO trae la clave no lo apaga (un cliente del endpoint que no la conoce, p. ej.
 *      el asistente IA).
 *   5. Cambiar SOLO el tilde no encola un recalculo de precios: no es un dato que lea el calculo,
 *      solo define como se lee el costo tipeado en la PROXIMA compra.
 *
 * Sobre el JSON las aserciones van con assertSame(true/false): el modelo castea a boolean, asi que
 * un 1/0 o un "1" en la respuesta tambien seria un defecto. Sobre la fila cruda de `providers` van
 * con assertEquals(1/0).
 *
 * La cola va con `Queue::fake()`: sin el fake, `phpunit.xml` pone la cola en `sync` y un recalculo
 * correria entero adentro del request, escondiendo justamente lo que el punto 5 tiene que probar.
 *
 * Los valores esperados son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que
 * coincida con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group proveedores
 */
class Costos_brutos_del_proveedor_se_guardan_Test extends EmpresaTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /* ==================================================================================
     * FIXTURES
     * ================================================================================== */

    /**
     * @return \App\Models\User
     */
    private function owner()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Proveedor propio de la suite, creado directo con el modelo (sin pasar por el controller), con
     * margen, dolar y modalidad conocidos y el tilde en el valor pedido.
     *
     * @param  bool   $precios_incluyen_iva
     * @param  string $sufijo
     * @return \App\Models\Provider
     */
    private function proveedor_de_la_suite($precios_incluyen_iva, $sufijo = '')
    {
        $provider = Provider::create([
            'name'                    => 'zz Proveedor costos brutos ' . $sufijo . uniqid(),
            'user_id'                 => $this->owner()->id,
            'percentage_gain'         => 50,
            'dolar'                   => 1000,
            'price_from_cost_mas_iva' => 0,
            'precios_incluyen_iva'    => $precios_incluyen_iva,
        ]);

        return $provider->fresh();
    }

    /**
     * Alta por el endpoint real, con lo que manda el formulario de la SPA. `price_from_cost_mas_iva`
     * va siempre porque la columna es NOT NULL y el formulario lo manda (es un checkbox, 0 por
     * defecto).
     *
     * @param  array $campos
     * @return \Illuminate\Testing\TestResponse
     */
    private function alta_por_el_endpoint(array $campos = [])
    {
        return $this->postJson('api/provider', array_merge([
            'name'                    => 'zz Proveedor costos brutos alta ' . uniqid(),
            'percentage_gain'         => 50,
            'dolar'                   => 1000,
            'price_from_cost_mas_iva' => 0,
        ], $campos));
    }

    /**
     * El modelo tal como lo devuelve `GET api/provider/{id}`: es lo que la SPA tiene en memoria y
     * manda ENTERO al guardar la ficha.
     *
     * @param  int $provider_id
     * @return array
     */
    private function modelo_del_get($provider_id)
    {
        return $this->getJson('api/provider/' . $provider_id)
                    ->assertStatus(200)
                    ->json('model');
    }

    /**
     * Guarda la ficha como la SPA: parte del modelo del GET, le aplica los cambios y lo manda entero.
     *
     * @param  int   $provider_id
     * @param  array $cambios
     * @return \Illuminate\Testing\TestResponse
     */
    private function guardar_modelo_entero($provider_id, array $cambios)
    {
        $modelo = array_merge($this->modelo_del_get($provider_id), $cambios);

        return $this->putJson('api/provider/' . $provider_id, $modelo);
    }

    /**
     * El valor crudo de la columna, sin pasar por el cast del modelo.
     *
     * @param  int $provider_id
     * @return mixed
     */
    private function tilde_en_la_base($provider_id)
    {
        return DB::table('providers')->where('id', $provider_id)->value('precios_incluyen_iva');
    }

    /**
     * Afirma el tilde en los tres lugares donde se lo ve: la respuesta del guardado, el GET de la
     * ficha (lo que precarga la compra) y la fila cruda en la base.
     *
     * @param  bool   $esperado
     * @param  int    $provider_id
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @param  string $mensaje
     * @return void
     */
    private function assert_tilde($esperado, $provider_id, $respuesta, $mensaje)
    {
        $this->assertSame(
            $esperado,
            $respuesta->json('model.precios_incluyen_iva'),
            $mensaje . ': la respuesta del guardado.'
        );

        $this->assertSame(
            $esperado,
            $this->modelo_del_get($provider_id)['precios_incluyen_iva'],
            $mensaje . ': GET api/provider/{id} (lo que precarga la compra).'
        );

        $this->assertEquals(
            $esperado ? 1 : 0,
            $this->tilde_en_la_base($provider_id),
            $mensaje . ': la fila en providers.'
        );
    }

    /**
     * Los valores del tilde como los puede mandar un formulario (texto o numero) y lo que significan.
     *
     * @return array
     */
    public function valores_del_formulario()
    {
        return [
            "'false' (texto)" => ['false', false],
            "'0' (texto)"     => ['0', false],
            '0 (numero)'      => [0, false],
            "'true' (texto)"  => ['true', true],
            "'1' (texto)"     => ['1', true],
            '1 (numero)'      => [1, true],
        ];
    }

    /* ==================================================================================
     * 1. ALTA
     * ================================================================================== */

    /**
     * 🔴 El corazon del defecto, del lado del alta: el tilde prendido se guarda.
     *
     * @test
     */
    public function el_alta_con_el_tilde_prendido_lo_guarda()
    {
        $respuesta = $this->alta_por_el_endpoint(['precios_incluyen_iva' => true])->assertStatus(201);

        $this->assert_tilde(true, $respuesta->json('model.id'), $respuesta, 'Alta con el tilde prendido');
    }

    /**
     * Apagado, o sin la clave (una SPA vieja que no la manda): nace apagado, el default de la columna.
     *
     * @test
     */
    public function el_alta_con_el_tilde_apagado_o_sin_la_clave_nace_apagado()
    {
        $apagado = $this->alta_por_el_endpoint(['precios_incluyen_iva' => false])->assertStatus(201);

        $this->assert_tilde(false, $apagado->json('model.id'), $apagado, 'Alta con el tilde apagado');

        $sin_la_clave = $this->alta_por_el_endpoint()->assertStatus(201);

        $this->assert_tilde(false, $sin_la_clave->json('model.id'), $sin_la_clave, 'Alta sin la clave');
    }

    /**
     * @test
     * @dataProvider valores_del_formulario
     *
     * @param  mixed $valor
     * @param  bool  $esperado
     */
    public function el_alta_lee_el_tilde_como_lo_manda_un_formulario($valor, $esperado)
    {
        $respuesta = $this->alta_por_el_endpoint(['precios_incluyen_iva' => $valor])->assertStatus(201);

        $this->assert_tilde(
            $esperado,
            $respuesta->json('model.id'),
            $respuesta,
            'Alta con ' . var_export($valor, true)
        );
    }

    /* ==================================================================================
     * 2. EDICION
     * ================================================================================== */

    /**
     * 🔴 El corazon del defecto, del lado de la edicion: con el modelo entero (como lo manda la SPA)
     * el tilde se prende, y despues se apaga.
     *
     * @test
     */
    public function la_edicion_con_el_modelo_entero_prende_y_apaga_el_tilde()
    {
        $provider = $this->proveedor_de_la_suite(false, 'edicion');

        $prendido = $this->guardar_modelo_entero($provider->id, ['precios_incluyen_iva' => true])->assertStatus(200);

        $this->assert_tilde(true, $provider->id, $prendido, 'Edicion que prende el tilde');

        $apagado = $this->guardar_modelo_entero($provider->id, ['precios_incluyen_iva' => false])->assertStatus(200);

        $this->assert_tilde(false, $provider->id, $apagado, 'Edicion que apaga el tilde');
    }

    /**
     * Cada valor arranca en el tilde CONTRARIO al esperado, para que el resultado pruebe que el
     * guardado lo leyo y no que quedo como estaba.
     *
     * @test
     * @dataProvider valores_del_formulario
     *
     * @param  mixed $valor
     * @param  bool  $esperado
     */
    public function la_edicion_lee_el_tilde_como_lo_manda_un_formulario($valor, $esperado)
    {
        $provider = $this->proveedor_de_la_suite(!$esperado, 'formulario');

        $respuesta = $this->guardar_modelo_entero($provider->id, ['precios_incluyen_iva' => $valor])->assertStatus(200);

        $this->assert_tilde($esperado, $provider->id, $respuesta, 'Edicion con ' . var_export($valor, true));
    }

    /**
     * Un cliente del endpoint que no manda la clave (p. ej. el asistente IA, o una SPA vieja) no
     * puede apagar un tilde que el dueño prendio.
     *
     * @test
     */
    public function una_edicion_que_no_trae_la_clave_no_apaga_el_tilde()
    {
        $provider = $this->proveedor_de_la_suite(true, 'sin clave');

        $this->assertEquals(1, $this->tilde_en_la_base($provider->id), 'Precondicion: el proveedor arranca con el tilde prendido.');

        $modelo = $this->modelo_del_get($provider->id);
        unset($modelo['precios_incluyen_iva']);
        $modelo['observations'] = 'Edicion sin la clave del tilde';

        $respuesta = $this->putJson('api/provider/' . $provider->id, $modelo)->assertStatus(200);

        $this->assert_tilde(true, $provider->id, $respuesta, 'Edicion sin la clave');

        $this->assertSame('Edicion sin la clave del tilde', $respuesta->json('model.observations'), 'Y el resto de la ficha si se guardo.');
    }

    /* ==================================================================================
     * 3. EL TILDE NO RECALCULA PRECIOS
     * ================================================================================== */

    /**
     * 🔴 Cambiar SOLO el tilde (margen, dolar y modalidad iguales a los guardados) no encola ningun
     * recalculo: el tilde no es un dato que lea el calculo de precios, solo define como se lee el
     * costo tipeado en la proxima compra (ver ProviderController::recalcular_precios_si_corresponde()).
     *
     * El control del final (cambiar el margen SI encola) es para que el "no encolo nada" no sea un
     * verde de casualidad: prueba que el fake ve los despachos de este endpoint.
     *
     * @test
     */
    public function cambiar_solo_el_tilde_no_encola_un_recalculo_de_precios()
    {
        $provider = $this->proveedor_de_la_suite(false, 'sin recalculo');

        $this->guardar_modelo_entero($provider->id, ['precios_incluyen_iva' => true])->assertStatus(200);

        $this->assertEquals(1, $this->tilde_en_la_base($provider->id), 'El tilde se guardo.');

        Queue::assertNotPushed(ProcessSetFinalPrices::class);

        /* Control: el mismo guardado cambiando el margen SI encola uno. */
        $this->guardar_modelo_entero($provider->id, ['percentage_gain' => 60])->assertStatus(200);

        Queue::assertPushed(ProcessSetFinalPrices::class, 1);

        $this->assertEquals(1, $this->tilde_en_la_base($provider->id), 'Y el tilde sigue prendido despues de cambiar el margen.');
    }
}
