<?php

namespace Tests\Feature\RetencionImpuesto;

use App\Models\RetencionImpuesto;
use App\Models\RetencionSufrida;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión retenciones-abm-impuestos (8/10/2026) — el ABM de los impuestos de retención propios del
 * comercio (`retencion-impuesto`), calcado del de bancos de cheques: CRUD scopeado por dueño, el
 * 404 de un id ajeno, el 422 de un nombre repetido (también contra los tres impuestos de siempre) y
 * el 422 de borrar un impuesto que ya tiene certificados cargados. Y que baje por
 * recursos-iniciales.
 *
 * @group retenciones
 */
class Abm_de_impuestos_Test extends EmpresaTestCase
{
    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Un impuesto de OTRO dueño, para verificar el scope.
     *
     * @return \App\Models\RetencionImpuesto
     */
    protected function impuesto_ajeno()
    {
        $otro_dueno = User::create([
            'name'     => 'Otro comercio retenciones',
            'email'    => 'retenciones-impuestos-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        return RetencionImpuesto::create(['name' => 'Impuesto ajeno', 'user_id' => $otro_dueno->id]);
    }

    /**
     * Los nombres que devuelve `GET retencion-impuesto`, en el orden en que salen.
     *
     * @return array<int, string>
     */
    protected function nombres_del_catalogo()
    {
        $response = $this->getJson('api/retencion-impuesto');

        $response->assertStatus(200);

        $nombres = [];

        foreach ($response->json('models') as $model) {
            $nombres[] = $model['name'];
        }

        return $nombres;
    }

    /**
     * Un certificado cargado con la clave del impuesto, para probar el borrado.
     *
     * @param  int $impuesto_id
     * @param  int|null $user_id
     * @return \App\Models\RetencionSufrida
     */
    protected function certificado_con($impuesto_id, $user_id = null)
    {
        return RetencionSufrida::create([
            'user_id'  => is_null($user_id) ? $this->dueno->id : $user_id,
            'impuesto' => RetencionImpuesto::clave($impuesto_id),
            'fecha'    => '2026-10-01',
            'importe'  => 1500,
            'origen'   => RetencionSufrida::ORIGEN_COBRO,
        ]);
    }

    /**
     * @test
     */
    public function el_abm_es_por_dueno_y_se_lista_ordenado_por_nombre()
    {
        $ajeno = $this->impuesto_ajeno();

        // Arranca vacío para este dueño: nada sembrado, los tres de siempre no viven en la tabla.
        $this->assertEquals([], $this->nombres_del_catalogo());

        // Alta.
        $response = $this->postJson('api/retencion-impuesto', ['name' => 'SUSS']);

        $response->assertStatus(201);
        $this->assertEquals('SUSS', $response->json('model.name'));
        $this->assertEquals($this->dueno->id, (int) $response->json('model.user_id'));

        $suss_id = (int) $response->json('model.id');

        $this->postJson('api/retencion-impuesto', ['name' => 'Seguridad e Higiene'])->assertStatus(201);

        // Listado: ordenado por nombre, y sin el del otro dueño.
        $this->assertEquals(['Seguridad e Higiene', 'SUSS'], $this->nombres_del_catalogo());

        // Show.
        $this->getJson('api/retencion-impuesto/' . $suss_id)->assertStatus(200)->assertJsonPath('model.name', 'SUSS');

        // Edición.
        $response = $this->putJson('api/retencion-impuesto/' . $suss_id, ['name' => 'SUSS - Seguridad Social']);

        $response->assertStatus(200);
        $this->assertEquals('SUSS - Seguridad Social', $response->json('model.name'));
        $this->assertEquals('SUSS - Seguridad Social', RetencionImpuesto::find($suss_id)->name);

        // Editarlo sin cambiarle el nombre no choca consigo mismo.
        $this->putJson('api/retencion-impuesto/' . $suss_id, ['name' => 'SUSS - Seguridad Social'])->assertStatus(200);

        // Baja.
        $this->deleteJson('api/retencion-impuesto/' . $suss_id)->assertStatus(200);
        $this->assertNull(RetencionImpuesto::find($suss_id));
        $this->assertEquals(['Seguridad e Higiene'], $this->nombres_del_catalogo());

        // El del otro dueño sigue intacto.
        $this->assertNotNull(RetencionImpuesto::find($ajeno->id));
    }

    /**
     * Un id de otra cuenta, uno que no existe y uno que no es un id son el MISMO 404, con un
     * mensaje de comerciante, en show, update y destroy.
     *
     * @test
     */
    public function un_id_ajeno_inexistente_o_que_no_es_un_id_es_el_mismo_404()
    {
        $ajeno = $this->impuesto_ajeno();

        $propio = RetencionImpuesto::create(['name' => 'Propio', 'user_id' => $this->dueno->id]);

        $mensaje = 'El impuesto no existe o no es de tu cuenta.';

        foreach ([$ajeno->id, $propio->id + 100000, $propio->id . 'abc'] as $id) {

            $this->getJson('api/retencion-impuesto/' . $id)->assertStatus(404)->assertJsonPath('message', $mensaje);
            $this->putJson('api/retencion-impuesto/' . $id, ['name' => 'Pisado'])->assertStatus(404)->assertJsonPath('message', $mensaje);
            $this->deleteJson('api/retencion-impuesto/' . $id)->assertStatus(404)->assertJsonPath('message', $mensaje);
        }

        // Lo ajeno no se tocó.
        $this->assertEquals('Impuesto ajeno', $ajeno->fresh()->name);
        $this->assertNotNull(RetencionImpuesto::find($ajeno->id));
        $this->assertEquals('Propio', $propio->fresh()->name);
    }

    /**
     * Un nombre vacío (o que no es un texto) no se guarda.
     *
     * @test
     */
    public function un_nombre_vacio_es_un_422()
    {
        $propio = RetencionImpuesto::create(['name' => 'Propio', 'user_id' => $this->dueno->id]);

        $this->postJson('api/retencion-impuesto', ['name' => ''])->assertStatus(422)->assertJsonStructure(['message']);
        $this->postJson('api/retencion-impuesto', ['name' => '   '])->assertStatus(422);
        $this->postJson('api/retencion-impuesto', [])->assertStatus(422);
        $this->postJson('api/retencion-impuesto', ['name' => ['SUSS']])->assertStatus(422);

        $this->putJson('api/retencion-impuesto/' . $propio->id, ['name' => ''])->assertStatus(422);

        $this->assertEquals('Propio', $propio->fresh()->name);
        $this->assertEquals(['Propio'], $this->nombres_del_catalogo());
    }

    /**
     * Un nombre repetido es un 422, comparado sin mayúsculas, sin espacios y sin tildes, contra los
     * demás impuestos del dueño Y contra los tres de siempre (y "Ingresos Brutos").
     *
     * @test
     */
    public function un_nombre_repetido_es_un_422_tambien_contra_los_tres_de_siempre()
    {
        $this->postJson('api/retencion-impuesto', ['name' => 'Retención Municipal'])->assertStatus(201);

        $otro = RetencionImpuesto::find((int) $this->postJson('api/retencion-impuesto', ['name' => 'SUSS'])->json('model.id'));

        // Contra uno propio.
        foreach (['retención municipal', '  RETENCION   MUNICIPAL ', 'retencionmunicipal'] as $repetido) {

            $this->postJson('api/retencion-impuesto', ['name' => $repetido])->assertStatus(422)->assertJsonStructure(['message']);
        }

        // Al editar: chocar con OTRO es 422.
        $this->putJson('api/retencion-impuesto/' . $otro->id, ['name' => 'retencion municipal'])->assertStatus(422);
        $this->assertEquals('SUSS', $otro->fresh()->name);

        // Contra los tres de siempre y contra IIBB escrito entero.
        foreach (['Ganancias', 'IVA', 'iva', 'IIBB', 'Ingresos Brutos', ' ingresos  brutos ', 'GANANCIAS'] as $reservado) {

            $this->postJson('api/retencion-impuesto', ['name' => $reservado])->assertStatus(422)->assertJsonStructure(['message']);
            $this->putJson('api/retencion-impuesto/' . $otro->id, ['name' => $reservado])->assertStatus(422);
        }

        $this->assertEquals(['Retención Municipal', 'SUSS'], $this->nombres_del_catalogo());
    }

    /**
     * El mismo nombre en DOS comercios distintos no es un repetido.
     *
     * @test
     */
    public function el_mismo_nombre_en_otro_comercio_no_cuenta_como_repetido()
    {
        $ajeno = $this->impuesto_ajeno();

        $this->postJson('api/retencion-impuesto', ['name' => $ajeno->name])->assertStatus(201);
    }

    /**
     * Un impuesto con certificados cargados no se borra: 422 con el mensaje de comerciante y el
     * número de certificados. Sin certificados, se borra. Los certificados de OTRO dueño con un
     * `imp_<id>` igual no cuentan.
     *
     * @test
     */
    public function no_se_puede_borrar_un_impuesto_con_certificados_cargados()
    {
        $con_certificados = RetencionImpuesto::find((int) $this->postJson('api/retencion-impuesto', ['name' => 'SUSS'])->json('model.id'));
        $sin_certificados = RetencionImpuesto::find((int) $this->postJson('api/retencion-impuesto', ['name' => 'Municipal'])->json('model.id'));

        $ajeno = $this->impuesto_ajeno();

        // Un certificado de otro dueño con la misma clave NO bloquea el borrado de uno propio.
        $this->certificado_con($sin_certificados->id, $ajeno->user_id);

        $this->certificado_con($con_certificados->id);

        // Con un certificado: singular.
        $this->deleteJson('api/retencion-impuesto/' . $con_certificados->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede borrar: hay 1 certificado de retención cargado con este impuesto.');

        $this->certificado_con($con_certificados->id);
        $this->certificado_con($con_certificados->id);

        // Con tres: el texto del contrato.
        $this->deleteJson('api/retencion-impuesto/' . $con_certificados->id)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No se puede borrar: hay 3 certificados de retención cargados con este impuesto.');

        $this->assertNotNull(RetencionImpuesto::find($con_certificados->id), 'Se borró un impuesto con certificados cargados.');
        $this->assertEquals(3, RetencionSufrida::where('impuesto', RetencionImpuesto::clave($con_certificados->id))->where('user_id', $this->dueno->id)->count());

        // Sin certificados propios: se borra.
        $this->deleteJson('api/retencion-impuesto/' . $sin_certificados->id)->assertStatus(200);
        $this->assertNull(RetencionImpuesto::find($sin_certificados->id));
    }

    /**
     * Baja por recursos-iniciales con la clave `retencion_impuesto`, y trae lo mismo que su
     * endpoint individual.
     *
     * @test
     */
    public function baja_por_recursos_iniciales()
    {
        $this->postJson('api/retencion-impuesto', ['name' => 'SUSS'])->assertStatus(201);

        $response = $this->postJson('api/recursos-iniciales', ['models' => ['retencion_impuesto']]);

        $response->assertStatus(200);

        $json = $response->json();

        $this->assertEquals([], $json['no_soportados'], 'retencion_impuesto no está en la whitelist de recursos-iniciales.');

        $nombres = [];

        foreach ($json['models']['retencion_impuesto']['models'] as $model) {
            $nombres[] = $model['name'];
        }

        $this->assertEquals(['SUSS'], $nombres);
    }

    /**
     * El borrado masivo (`PUT delete/retencion_impuesto`) respeta el 422 de un impuesto con
     * certificados: lo devuelve en `not_deleted` y no en `deleted_models`, y la fila sigue ahí.
     * Sin que el modelo esté en `DeleteModelsHelper::MODELOS_QUE_RESPETAN_RECHAZO`, el listado lo
     * sacaría de pantalla como eliminado.
     *
     * @test
     */
    public function el_borrado_masivo_respeta_el_rechazo_por_certificados()
    {
        $impuesto = RetencionImpuesto::create(['name' => 'zz Masivo SUSS', 'user_id' => $this->dueno->id]);
        $this->certificado_con($impuesto->id);

        $respuesta = $this->putJson('api/delete/retencion_impuesto', ['models_id' => [$impuesto->id]]);

        $respuesta->assertStatus(200);
        $this->assertSame($impuesto->id, (int) $respuesta->json('not_deleted.0.id'));
        $this->assertEmpty($respuesta->json('deleted_models'));
        $this->assertNotNull(RetencionImpuesto::find($impuesto->id));

        RetencionSufrida::where('impuesto', RetencionImpuesto::clave($impuesto->id))->delete();
        RetencionImpuesto::where('id', $impuesto->id)->delete();
    }
}
