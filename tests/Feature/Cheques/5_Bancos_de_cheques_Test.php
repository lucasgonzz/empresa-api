<?php

namespace Tests\Feature\Cheques;

use App\Http\Controllers\Helpers\CatalogoDeDatosIaHelper;
use App\Models\Cheque;
use App\Models\ChequeBanco;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Misión cheques-endoso-y-bancos — el catálogo de bancos de cheques (`cheque-banco`): CRUD
 * scopeado por dueño, el cheque que lo referencia, y qué pasa con el cheque cuando el banco se
 * borra (queda sin banco del catálogo y con su texto intacto). Y que baje por recursos-iniciales.
 *
 * @group cheques
 */
class Bancos_de_cheques_Test extends ChequesTestCase
{
    /**
     * Un banco de OTRO dueño, para verificar el scope.
     *
     * @return ChequeBanco
     */
    protected function banco_ajeno()
    {
        $otro_dueno = User::create([
            'name'     => 'Otro comercio bancos',
            'email'    => 'cheques-bancos-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        return ChequeBanco::create(['name' => 'Banco ajeno', 'user_id' => $otro_dueno->id]);
    }

    /**
     * Los nombres que devuelve `GET cheque-banco`.
     *
     * @return array<int, string>
     */
    protected function nombres_del_catalogo()
    {
        $response = $this->getJson('api/cheque-banco');

        $response->assertStatus(200);

        $nombres = [];

        foreach ($response->json('models') as $model) {
            $nombres[] = $model['name'];
        }

        return $nombres;
    }

    /**
     * @test
     */
    public function el_abm_del_catalogo_es_por_dueno()
    {
        $ajeno = $this->banco_ajeno();

        // Arranca vacío para este dueño (decisión de Lucas: nada sembrado).
        $this->assertEquals([], $this->nombres_del_catalogo());

        // Alta.
        $response = $this->postJson('api/cheque-banco', ['name' => 'Banco Nación']);

        $response->assertStatus(201);
        $this->assertEquals('Banco Nación', $response->json('model.name'));
        $this->assertEquals($this->dueno->id, (int) $response->json('model.user_id'));

        $nacion_id = (int) $response->json('model.id');

        $this->postJson('api/cheque-banco', ['name' => 'Banco Galicia'])->assertStatus(201);

        // Listado: ordenado por nombre, y sin el del otro dueño.
        $this->assertEquals(['Banco Galicia', 'Banco Nación'], $this->nombres_del_catalogo());

        // Show.
        $this->getJson('api/cheque-banco/' . $nacion_id)->assertStatus(200)->assertJsonPath('model.name', 'Banco Nación');

        // Edición.
        $response = $this->putJson('api/cheque-banco/' . $nacion_id, ['name' => 'Banco de la Nación Argentina']);

        $response->assertStatus(200);
        $this->assertEquals('Banco de la Nación Argentina', $response->json('model.name'));
        $this->assertEquals('Banco de la Nación Argentina', ChequeBanco::find($nacion_id)->name);

        // Un id ajeno no se edita ni se borra desde esta cuenta.
        $this->putJson('api/cheque-banco/' . $ajeno->id, ['name' => 'Pisado'])->assertStatus(404);
        $this->assertEquals('Banco ajeno', $ajeno->fresh()->name);

        $this->deleteJson('api/cheque-banco/' . $ajeno->id)->assertStatus(404);
        $this->assertNotNull(ChequeBanco::find($ajeno->id));

        // Baja.
        $this->deleteJson('api/cheque-banco/' . $nacion_id)->assertStatus(200);
        $this->assertNull(ChequeBanco::find($nacion_id));
        $this->assertEquals(['Banco Galicia'], $this->nombres_del_catalogo());
    }

    /**
     * @test
     */
    public function un_cheque_nuevo_guarda_el_banco_del_catalogo_y_lo_lista_con_su_nombre()
    {
        $banco = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco Provincia'])->json('model.id'));

        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente banco ' . uniqid());

        // La SPA manda el id del catálogo Y el nombre como texto (compatibilidad con la API vieja).
        $recibido = $this->cobrar_con_cheque($cliente, $cuenta, [
            'numero'          => '8001',
            'banco'           => 'Banco Provincia',
            'cheque_banco_id' => $banco->id,
        ]);

        $this->assertEquals($banco->id, $recibido->cheque_banco_id);
        $this->assertEquals('Banco Provincia', $recibido->banco);

        $del_listado = $this->cheque_del_listado($recibido->id);

        $this->assertNotNull($del_listado);
        $this->assertEquals($banco->id, (int) $del_listado['cheque_banco']['id']);
        $this->assertEquals('Banco Provincia', $del_listado['cheque_banco']['name']);
        $this->assertEquals('Banco Provincia', $del_listado['banco']);
        $this->assertEquals($banco->id, (int) $del_listado['cheque_banco_id']);

        // Un cheque con `cheque_banco_id: 0` (el "Sin banco" del select) queda sin banco del catálogo.
        $sin_banco = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8002', 'banco' => 'Escrito a mano', 'cheque_banco_id' => 0]);

        $this->assertNull($sin_banco->cheque_banco_id);
        $this->assertEquals('Escrito a mano', $sin_banco->banco);
        $this->assertNull($this->cheque_del_listado($sin_banco->id)['cheque_banco']);
    }

    /**
     * @test
     */
    public function el_endoso_lleva_el_banco_del_catalogo_a_la_copia()
    {
        $banco = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco Macro'])->json('model.id'));

        list($cliente, $cuenta_cliente) = $this->cliente_con_cuenta('Cliente banco copia ' . uniqid());
        list($proveedor, $cuenta_proveedor) = $this->proveedor_con_cuenta('Proveedor banco copia ' . uniqid(), self::DEUDA_PROVEEDOR);

        $recibido = $this->cobrar_con_cheque($cliente, $cuenta_cliente, ['numero' => '8003', 'banco' => 'Banco Macro', 'cheque_banco_id' => $banco->id]);

        $response = $this->postJson('api/current-acount/pago', $this->payload_de_pago('provider', $proveedor->id, $cuenta_proveedor, [$this->fila_de_pago($this->claves_de_endoso($recibido))]));

        $response->assertStatus(201);
        $this->cobros_cc_creados_por_escenarios[] = (int) $response->json('current_acount.id');

        $copia = $this->copias_de($recibido)->first();

        $this->assertEquals($banco->id, $copia->cheque_banco_id);
        $this->assertEquals('Banco Macro', $copia->banco);
        $this->assertEquals('Banco Macro', $this->cheque_del_listado($copia->id)['cheque_banco']['name']);
    }

    /**
     * @test
     */
    public function borrar_un_banco_deja_los_cheques_sin_banco_del_catalogo_y_con_el_texto_intacto()
    {
        $banco = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco Santander'])->json('model.id'));
        $otro = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco Credicoop'])->json('model.id'));

        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente borrar banco ' . uniqid());

        $con_el_banco = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8004', 'banco' => 'Banco Santander', 'cheque_banco_id' => $banco->id]);
        $con_el_otro = $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8005', 'banco' => 'Banco Credicoop', 'cheque_banco_id' => $otro->id]);

        $this->deleteJson('api/cheque-banco/' . $banco->id)->assertStatus(200);

        $this->assertNull($con_el_banco->fresh()->cheque_banco_id);
        $this->assertEquals('Banco Santander', $con_el_banco->fresh()->banco);
        $this->assertNull($this->cheque_del_listado($con_el_banco->id)['cheque_banco']);
        $this->assertEquals('Banco Santander', $this->cheque_del_listado($con_el_banco->id)['banco']);

        // El otro banco y su cheque no se tocan.
        $this->assertEquals($otro->id, $con_el_otro->fresh()->cheque_banco_id);
        $this->assertEquals(['Banco Credicoop'], $this->nombres_del_catalogo());
    }

    /**
     * El asistente puede filtrar los cheques por el banco UNIFICADO y no solo por el texto escrito
     * (misión cheques-endoso-y-bancos, chequeo independiente): "los cheques del Banco Nación" tiene
     * que traer también los que decían "Bco Nacion" antes de unificarse.
     *
     * @test
     */
    public function la_consulta_generica_del_asistente_filtra_por_el_banco_unificado()
    {
        $banco = ChequeBanco::find((int) $this->postJson('api/cheque-banco', ['name' => 'Banco Nación'])->json('model.id'));

        list($cliente, $cuenta) = $this->cliente_con_cuenta('Cliente catálogo IA ' . uniqid());

        // Dos cheques del mismo banco unificado con el texto escrito distinto, y uno de otro banco.
        $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8101', 'banco' => 'Bco Nacion', 'cheque_banco_id' => $banco->id]);
        $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8102', 'banco' => 'banco nación', 'cheque_banco_id' => $banco->id]);
        $this->cobrar_con_cheque($cliente, $cuenta, ['numero' => '8103', 'banco' => 'Galicia']);

        // El catálogo declara el campo y la relación.
        $detalle = CatalogoDeDatosIaHelper::que_puedo_consultar('cheque');

        $this->assertContains('cheque_banco_id', array_column($detalle['campos'], 'campo'));

        $relaciones = array_column($detalle['relaciones'], 'campo_en_la_respuesta');

        $this->assertContains('banco_unificado', $relaciones);
        $this->assertEquals('cheque_banco_id', $detalle['relaciones'][array_search('banco_unificado', $relaciones, true)]['se_filtra_por']);

        // Y filtrando por él salen los dos, con el nombre del banco en la respuesta.
        $resultado = CatalogoDeDatosIaHelper::consultar_datos($this->dueno->id, 'cheque', [
            ['campo' => 'cheque_banco_id', 'operador' => 'igual', 'valor' => $banco->id],
        ]);

        $numeros = array_column($resultado['registros'], 'numero');

        sort($numeros);

        $this->assertEquals(['8101', '8102'], $numeros, 'Cuerpo completo: ' . json_encode($resultado));
        $this->assertEquals('Banco Nación', $resultado['registros'][0]['banco_unificado']);
        $this->assertNotEquals('', $resultado['registros'][0]['banco'], 'El texto escrito en el cheque sigue viajando al lado.');
    }

    /**
     * @test
     */
    public function recursos_iniciales_incluye_el_catalogo()
    {
        $this->postJson('api/cheque-banco', ['name' => 'Banco Ciudad'])->assertStatus(201);

        $response = $this->postJson('api/recursos-iniciales', ['models' => ['cheque_banco']]);

        $response->assertStatus(200);
        $this->assertEquals([], $response->json('no_soportados'));
        $this->assertEquals([], $response->json('con_error'));

        $nombres = [];

        foreach ($response->json('models.cheque_banco.models') as $model) {
            $nombres[] = $model['name'];
        }

        $this->assertEquals(['Banco Ciudad'], $nombres);
        $this->assertEquals($this->getJson('api/cheque-banco')->json('models'), $response->json('models.cheque_banco.models'));
    }
}
