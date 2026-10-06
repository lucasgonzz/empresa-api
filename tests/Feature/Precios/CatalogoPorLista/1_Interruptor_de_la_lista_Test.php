<?php

namespace Tests\Feature\Precios\CatalogoPorLista;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * El interruptor "Catálogo restringido en la tienda" de una lista de precios por
 * `POST/PUT api/price-type` (misión catalogo-por-lista-tienda, 5/10/2026, contrato C2).
 *
 *  - El alta sin la clave (SPA viejo) deja la lista en NULL = sin restricción.
 *  - 🔴 El update sin la clave NO lo pisa: PriceTypeController::update() asigna campo por campo, y
 *    un SPA viejo cacheado que no conoce el interruptor le apagaría la restricción a un comercio
 *    que la prendió desde otra pestaña.
 *  - Con la clave se guarda saneado a 1 o 0, y viaja de vuelta en el payload de la lista.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group catalogo-por-lista
 */
class Interruptor_de_la_lista_Test extends CatalogoPorListaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_comercios();

        // El alta de una lista encola el recálculo de precios del comercio: acá no interesa.
        Queue::fake();
    }

    /**
     * El body del alta tal como lo arma el formulario, sin el interruptor (SPA viejo).
     *
     * @param  array $cambios
     * @return array
     */
    protected function payload_de_alta(array $cambios = [])
    {
        return array_merge([
            'name'                                 => 'zz Lista nueva',
            'percentage'                           => 15,
            'position'                             => 3,
            'ocultar_al_publico'                   => 0,
            'incluir_en_lista_de_precios_de_excel' => 1,
            'setear_precio_final'                  => 0,
            'se_usa_en_tienda_nube'                => 0,
            'se_usa_en_ml'                         => 0,
            'categories'                           => [],
            'sub_categories'                       => [],
        ], $cambios);
    }

    /**
     * El body del PUT: la fila de la lista como está en la base, SIN el interruptor (lo que manda
     * un SPA viejo), con los cambios encima.
     *
     * @param  \App\Models\PriceType $lista
     * @param  array                 $cambios
     * @return array
     */
    protected function payload_de_edicion($lista, array $cambios = [])
    {
        $fila = DB::table('price_types')->where('id', $lista->id)->first();

        return array_merge([
            'name'                                 => $fila->name,
            'percentage'                           => $fila->percentage,
            'position'                             => $fila->position,
            'ocultar_al_publico'                   => $fila->ocultar_al_publico,
            'incluir_en_lista_de_precios_de_excel' => $fila->incluir_en_lista_de_precios_de_excel,
            'setear_precio_final'                  => $fila->setear_precio_final,
            'se_usa_en_tienda_nube'                => $fila->se_usa_en_tienda_nube,
            'se_usa_en_ml'                         => $fila->se_usa_en_ml,
            'categories'                           => [],
            'sub_categories'                       => [],
        ], $cambios);
    }

    /**
     * Alta sin la clave: la lista nace sin restricción (NULL, no 0).
     *
     * @return void
     */
    public function test_el_alta_sin_la_clave_nace_en_null()
    {
        $id = $this->postJson('api/price-type', $this->payload_de_alta())
                    ->assertStatus(201)
                    ->json('model.id');

        $this->assertNull($this->interruptor($id));
    }

    /**
     * Alta con la clave: 1 y 0 tal como vienen; un booleano de JSON también.
     *
     * @return void
     */
    public function test_el_alta_con_la_clave_guarda_uno_o_cero()
    {
        $prendida = $this->postJson('api/price-type', $this->payload_de_alta(['name' => 'zz Prendida', 'catalogo_restringido_en_tienda' => 1]))
                        ->assertStatus(201)
                        ->json('model.id');

        $apagada = $this->postJson('api/price-type', $this->payload_de_alta(['name' => 'zz Apagada', 'catalogo_restringido_en_tienda' => 0]))
                        ->assertStatus(201)
                        ->json('model.id');

        $booleana = $this->postJson('api/price-type', $this->payload_de_alta(['name' => 'zz Booleana', 'catalogo_restringido_en_tienda' => true]))
                        ->assertStatus(201)
                        ->json('model.id');

        $this->assertSame(1, $this->interruptor($prendida));
        $this->assertSame(0, $this->interruptor($apagada));
        $this->assertSame(1, $this->interruptor($booleana));
    }

    /**
     * 🔴 El SPA viejo: un PUT que no trae la clave NO le apaga la restricción a la lista. El resto
     * del guardado ocurre igual (cambia el nombre).
     *
     * @return void
     */
    public function test_el_update_sin_la_clave_no_pisa_la_restriccion()
    {
        $this->putJson('api/price-type/' . $this->mayorista->id, $this->payload_de_edicion($this->mayorista, [
            'name' => 'zz Mayorista renombrada',
        ]))->assertStatus(200);

        $this->assertSame('zz Mayorista renombrada', DB::table('price_types')->where('id', $this->mayorista->id)->value('name'), 'El guardado tenía que ocurrir.');
        $this->assertSame(1, $this->interruptor($this->mayorista->id), 'Sin la clave, la restricción se conserva.');

        // Y una lista en NULL sigue en NULL (no se convierte en 0).
        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista))->assertStatus(200);

        $this->assertNull($this->interruptor($this->minorista->id));
    }

    /**
     * Con la clave, el PUT la prende y la apaga (saneada: '1' y true son 1; 0 y '' son 0).
     *
     * @return void
     */
    public function test_el_update_con_la_clave_la_prende_y_la_apaga()
    {
        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => '1',
        ]))->assertStatus(200);

        $this->assertSame(1, $this->interruptor($this->minorista->id));

        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => 0,
        ]))->assertStatus(200);

        $this->assertSame(0, $this->interruptor($this->minorista->id));

        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => true,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->interruptor($this->minorista->id));

        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => '',
        ]))->assertStatus(200);

        $this->assertSame(0, $this->interruptor($this->minorista->id), 'Un valor vacío con la clave presente es "no restringida".');
    }

    /**
     * 🔴 M2 de la revisión independiente (6/10/2026): el ABM de la SPA reenvía `{...this.model}`
     * entero al guardar, y el JSON que cargó trae `catalogo_restringido_en_tienda: null` si cuando
     * se cargó la lista el interruptor todavía no estaba prendido. Ese eco NO es un "apagalo": el
     * `null` es "no sé nada de este campo" y no pisa lo guardado. Pasa de verdad cuando una pestaña
     * cargó las listas antes de que el dueño prendiera el interruptor desde otra, y alguien edita el
     * margen en la pestaña vieja: antes el PUT con `null` escribía 0 y los mayoristas volvían a ver
     * todo el catálogo sin que nadie lo notara.
     *
     * @return void
     */
    public function test_un_put_con_la_clave_en_null_no_apaga_la_restriccion()
    {
        $this->putJson('api/price-type/' . $this->mayorista->id, $this->payload_de_edicion($this->mayorista, [
            'name'                           => 'zz Mayorista editada desde una pestaña vieja',
            'catalogo_restringido_en_tienda' => null,
        ]))->assertStatus(200);

        $this->assertSame('zz Mayorista editada desde una pestaña vieja', DB::table('price_types')->where('id', $this->mayorista->id)->value('name'), 'El guardado tenía que ocurrir.');
        $this->assertSame(1, $this->interruptor($this->mayorista->id), 'El eco de un null no apaga la restricción.');
    }

    /**
     * M2, la otra punta: una lista que nunca tuvo el interruptor (NULL) y un PUT con la clave en
     * `null` (el SPA nuevo guardando una lista cuyo valor es NULL) sigue en NULL: no se convierte en 0.
     *
     * @return void
     */
    public function test_un_put_con_la_clave_en_null_deja_una_lista_en_null_en_null()
    {
        $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => null,
        ]))->assertStatus(200);

        $this->assertNull($this->interruptor($this->minorista->id), 'Un null con la clave presente no escribe nada: NULL sigue siendo NULL.');
    }

    /**
     * M2: lo que SÍ es un pedido explícito sigue escribiendo como siempre — `false` apaga igual que
     * 0, y `true` prende. (El vacío `''` lo fija test_el_update_con_la_clave_la_prende_y_la_apaga.)
     *
     * @return void
     */
    public function test_un_put_con_false_apaga_y_con_true_prende()
    {
        $this->putJson('api/price-type/' . $this->mayorista->id, $this->payload_de_edicion($this->mayorista, [
            'catalogo_restringido_en_tienda' => false,
        ]))->assertStatus(200);

        $this->assertSame(0, $this->interruptor($this->mayorista->id), 'false es un apagado explícito.');

        $this->putJson('api/price-type/' . $this->mayorista->id, $this->payload_de_edicion($this->mayorista, [
            'catalogo_restringido_en_tienda' => true,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->interruptor($this->mayorista->id), 'true prende.');
    }

    /**
     * 🔴 M3: el interruptor solo se escribe sobre una lista PROPIA. `update()` busca la lista por id
     * sin mirar el dueño (ya era así antes de esta misión y no se cambia acá: es otro frente), pero
     * esta misión suma un campo cuyo efecto es sacarle el catálogo ENTERO de la tienda a todos los
     * compradores de esa lista, y con ids secuenciales en una base compartida no puede quedar al
     * alcance de un id ajeno. El resto del update sigue como estaba (responde 200).
     *
     * @return void
     */
    public function test_el_interruptor_no_se_escribe_sobre_una_lista_de_otro_dueno()
    {
        \Illuminate\Support\Facades\Log::spy();

        $this->putJson('api/price-type/' . $this->lista_ajena->id, $this->payload_de_edicion($this->lista_ajena, [
            'catalogo_restringido_en_tienda' => 1,
        ]))->assertStatus(200);

        $this->assertNull($this->interruptor($this->lista_ajena->id), 'Una lista de otro comercio no se puede restringir.');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
            ->withArgs(function ($mensaje) {
                return strpos((string) $mensaje, 'PriceTypeController@update') !== false
                    && strpos((string) $mensaje, 'otro dueño') !== false
                    && strpos((string) $mensaje, 'interruptor') !== false;
            })
            ->once();
    }

    /**
     * M3, el espejo: tampoco se le puede APAGAR la restricción a la lista de otro comercio.
     *
     * @return void
     */
    public function test_el_interruptor_de_una_lista_ajena_no_se_apaga()
    {
        DB::table('price_types')->where('id', $this->lista_ajena->id)->update(['catalogo_restringido_en_tienda' => 1]);

        $this->putJson('api/price-type/' . $this->lista_ajena->id, $this->payload_de_edicion($this->lista_ajena, [
            'catalogo_restringido_en_tienda' => 0,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->interruptor($this->lista_ajena->id), 'La restricción de otro comercio no se apaga desde acá.');
    }

    /**
     * El interruptor viaja en el payload de la lista: en el listado y en la respuesta del PUT.
     *
     * @return void
     */
    public function test_el_interruptor_viaja_en_el_payload_de_la_lista()
    {
        $modelos = $this->getJson('api/price-type')->assertStatus(200)->json('models');

        $por_id = [];
        foreach ($modelos as $modelo) {
            $por_id[$modelo['id']] = $modelo;
        }

        $this->assertArrayHasKey('catalogo_restringido_en_tienda', $por_id[$this->mayorista->id]);
        $this->assertEquals(1, $por_id[$this->mayorista->id]['catalogo_restringido_en_tienda']);
        $this->assertNull($por_id[$this->minorista->id]['catalogo_restringido_en_tienda']);

        $respuesta = $this->putJson('api/price-type/' . $this->minorista->id, $this->payload_de_edicion($this->minorista, [
            'catalogo_restringido_en_tienda' => 1,
        ]))->assertStatus(200);

        $this->assertEquals(1, $respuesta->json('model.catalogo_restringido_en_tienda'));
    }
}
