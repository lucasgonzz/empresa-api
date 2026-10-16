<?php

namespace Tests\Feature\Caja;

use App\Http\Controllers\ConceptoMovimientoCajaController;
use App\Models\AperturaCaja;
use App\Models\Caja;
use App\Models\ConceptoMovimientoCaja;
use App\Models\MovimientoCaja;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión destrabar-videos-centro (16/10/2026) — los conceptos de movimiento de caja pasan a tener
 * dueño (`concepto_movimiento_cajas.user_id`).
 *
 * Hasta acá la tabla no tenía `user_id`: el listado devolvía los conceptos de TODOS los comercios
 * de la base y cualquiera podía renombrar o borrar los del sistema (que el código usa por id fijo,
 * `MovimientoCaja::CONCEPTO_*`, y por nombre). En una base compartida eso cruzaba datos entre
 * comercios. Ahora:
 *
 * - `user_id` NULL = del sistema: lo ven todos, no se renombra ni se borra (422).
 * - `user_id` del dueño = propio: lo ve, lo renombra y lo borra solo él (si no tiene movimientos).
 * - Lo de otro dueño responde 404, igual que lo inexistente.
 *
 * La base de testing no siembra `concepto_movimiento_cajas`: el concepto "del sistema" de cada
 * test se crea a mano con `user_id` NULL y un id que no choca con los fijos (1 a 10). Todo corre
 * dentro de la transacción de `EmpresaTestCase`.
 *
 * Los textos de los rechazos se comparan con las constantes del controlador: son los que ve el
 * usuario en el toast.
 *
 * @group caja
 */
class Conceptos_por_dueno_Test extends EmpresaTestCase
{

    /** @var User|null */
    protected $otro_dueno = null;

    /**
     * El dueño del fixture (el que autentica `EmpresaTestCase`). Se resuelve por el mail y no por
     * `auth()->id()`: después de `como($otro)` el autenticado es el otro comercio.
     *
     * @return User
     */
    protected function dueno()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Otro comercio: un dueño sin owner_id.
     *
     * @return User
     */
    protected function otro_dueno()
    {
        if (is_null($this->otro_dueno)) {
            $this->otro_dueno = User::create([
                'name'     => 'Otro comercio conceptos de caja',
                'email'    => 'conceptos-caja-otro-' . uniqid() . '@test.local',
                'password' => Hash::make('secret'),
                'owner_id' => null,
            ]);
        }
        return $this->otro_dueno;
    }

    /**
     * Autentica los próximos requests como `$user`. El guard de Sanctum CACHEA el usuario entre
     * requests del mismo test: sin `forgetGuards()` el segundo comercio seguiría entrando como el
     * primero y el aislamiento pasaría o fallaría de casualidad.
     *
     * @param  User  $user
     * @return void
     */
    protected function como($user)
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
    }

    /**
     * Un concepto del sistema (`user_id` NULL) con un id libre por encima de los fijos.
     *
     * @param  string  $nombre
     * @return ConceptoMovimientoCaja
     */
    protected function concepto_del_sistema($nombre)
    {
        $id = max((int) ConceptoMovimientoCaja::max('id'), 10) + 1;
        return ConceptoMovimientoCaja::create(['id' => $id, 'name' => $nombre]);
    }

    /**
     * Da de alta un concepto por el endpoint real, autenticado como `$user`.
     *
     * @param  User  $user
     * @param  string  $nombre
     * @return ConceptoMovimientoCaja
     */
    protected function alta_como($user, $nombre)
    {
        $this->como($user);
        $response = $this->postJson('api/concepto-movimiento-caja', ['name' => $nombre]);
        $response->assertStatus(201);
        return ConceptoMovimientoCaja::find($response->json('model.id'));
    }

    /**
     * Ids que devuelve el listado del ABM, autenticado como `$user`.
     *
     * @param  User  $user
     * @return array
     */
    protected function ids_del_listado_de($user)
    {
        $this->como($user);
        $response = $this->getJson('api/concepto-movimiento-caja');
        $response->assertStatus(200);
        return array_column($response->json('models'), 'id');
    }

    /**
     * El alta graba el dueño, y cada comercio ve los del sistema más los suyos, nunca los ajenos.
     *
     * @test
     */
    public function el_alta_es_del_dueno_y_el_listado_no_cruza_comercios()
    {
        $sistema = $this->concepto_del_sistema('ZZ Sistema conceptos');
        $dueno = $this->dueno();
        $otro = $this->otro_dueno();

        $mio = $this->alta_como($dueno, 'ZZ Retiro del dueño');
        $ajeno = $this->alta_como($otro, 'ZZ Concepto del otro comercio');

        $this->assertSame((int) $dueno->id, (int) $mio->user_id, 'El alta tiene que grabar el dueño que lo creó.');
        $this->assertSame((int) $otro->id, (int) $ajeno->user_id);

        $ids_dueno = $this->ids_del_listado_de($dueno);
        $this->assertContains($sistema->id, $ids_dueno, 'Los conceptos del sistema los ve todo comercio.');
        $this->assertContains($mio->id, $ids_dueno, 'El dueño ve su propio concepto.');
        $this->assertNotContains($ajeno->id, $ids_dueno, 'El dueño NO ve el concepto de otro comercio.');

        $ids_otro = $this->ids_del_listado_de($otro);
        $this->assertContains($sistema->id, $ids_otro);
        $this->assertContains($ajeno->id, $ids_otro);
        $this->assertNotContains($mio->id, $ids_otro, 'El otro comercio NO ve el concepto del dueño.');
    }

    /**
     * El buscador genérico (`global-search`, el que usan los listados del ABM) aplica el mismo
     * criterio: con la columna `user_id` nueva, sin el scope opt-in filtraría `user_id = dueño` y
     * los del sistema dejarían de salir.
     *
     * @test
     */
    public function el_buscador_generico_trae_los_del_sistema_y_los_propios_pero_no_los_ajenos()
    {
        $sistema = $this->concepto_del_sistema('ZZ Sistema buscador');
        $dueno = $this->dueno();
        $mio = $this->alta_como($dueno, 'ZZ Propio buscador');
        $ajeno = $this->alta_como($this->otro_dueno(), 'ZZ Ajeno buscador');

        $this->como($dueno);
        $response = $this->postJson('api/global-search/concepto_movimiento_caja', [
            'query_value' => 'ZZ',
            'props'       => ['name'],
        ]);
        $response->assertStatus(200);

        $ids = array_column($response->json('models.data'), 'id');
        $this->assertContains($sistema->id, $ids, 'El buscador tiene que traer los conceptos del sistema.');
        $this->assertContains($mio->id, $ids);
        $this->assertNotContains($ajeno->id, $ids, 'El buscador no puede traer conceptos de otro comercio.');
    }

    /**
     * Un concepto del sistema no se renombra ni se borra: 422 con el motivo, y queda como estaba.
     *
     * @test
     */
    public function los_del_sistema_no_se_renombran_ni_se_borran()
    {
        $sistema = $this->concepto_del_sistema('ZZ Sistema protegido');

        $edicion = $this->putJson('api/concepto-movimiento-caja/' . $sistema->id, ['name' => 'ZZ Renombrado']);
        $edicion->assertStatus(422);
        $this->assertSame(ConceptoMovimientoCajaController::MENSAJE_SISTEMA_EDITAR, $edicion->json('message'));
        $this->assertIsArray($edicion->json('errors'), 'El rechazo trae `errors` como mapa: es lo que la SPA muestra en el toast.');
        $this->assertSame('ZZ Sistema protegido', $sistema->fresh()->name);

        $baja = $this->deleteJson('api/concepto-movimiento-caja/' . $sistema->id);
        $baja->assertStatus(422);
        $this->assertSame(ConceptoMovimientoCajaController::MENSAJE_SISTEMA_ELIMINAR, $baja->json('message'));
        $this->assertNotNull(ConceptoMovimientoCaja::find($sistema->id), 'El concepto del sistema sigue existiendo.');
    }

    /**
     * Lo de otro comercio responde 404, igual que lo inexistente, y no se toca.
     *
     * @test
     */
    public function el_concepto_de_otro_comercio_da_404_y_no_se_toca()
    {
        $ajeno = $this->alta_como($this->otro_dueno(), 'ZZ Ajeno intocable');

        $this->como($this->dueno());

        $this->getJson('api/concepto-movimiento-caja/' . $ajeno->id)->assertStatus(404);

        $this->putJson('api/concepto-movimiento-caja/' . $ajeno->id, ['name' => 'ZZ Pisado'])->assertStatus(404);
        $this->assertSame('ZZ Ajeno intocable', $ajeno->fresh()->name);

        $this->deleteJson('api/concepto-movimiento-caja/' . $ajeno->id)->assertStatus(404);
        $this->assertNotNull(ConceptoMovimientoCaja::find($ajeno->id));
    }

    /**
     * El propio se renombra y, sin movimientos, se borra.
     *
     * @test
     */
    public function el_propio_se_renombra_y_se_borra()
    {
        $mio = $this->alta_como($this->dueno(), 'ZZ Propio editable');

        $edicion = $this->putJson('api/concepto-movimiento-caja/' . $mio->id, ['name' => 'ZZ Propio renombrado']);
        $edicion->assertStatus(200);
        $this->assertSame('ZZ Propio renombrado', $mio->fresh()->name);
        $this->assertSame((int) $this->dueno()->id, (int) $mio->fresh()->user_id, 'Renombrar no le cambia el dueño.');

        $this->deleteJson('api/concepto-movimiento-caja/' . $mio->id)->assertStatus(200);
        $this->assertNull(ConceptoMovimientoCaja::find($mio->id));
    }

    /**
     * El propio que ya se usó en un movimiento de caja no se borra: el movimiento quedaría con un
     * concepto que no existe (y la pantalla de movimientos lo busca por id).
     *
     * @test
     */
    public function el_propio_con_movimientos_no_se_borra()
    {
        $dueno = $this->dueno();
        $mio = $this->alta_como($dueno, 'ZZ Propio usado');

        $caja = Caja::create([
            'num'     => 1,
            'name'    => 'ZZ Caja conceptos ' . uniqid(),
            'user_id' => $dueno->id,
            'saldo'   => 0,
        ]);
        $apertura = AperturaCaja::create([
            'saldo_apertura'       => 0,
            'apertura_employee_id' => $dueno->id,
            'caja_id'              => $caja->id,
        ]);
        MovimientoCaja::create([
            'concepto_movimiento_caja_id' => $mio->id,
            'ingreso'                     => 100,
            'apertura_caja_id'            => $apertura->id,
            'caja_id'                     => $caja->id,
        ]);

        $baja = $this->deleteJson('api/concepto-movimiento-caja/' . $mio->id);
        $baja->assertStatus(422);
        $this->assertSame(ConceptoMovimientoCajaController::MENSAJE_CON_MOVIMIENTOS, $baja->json('message'));
        $this->assertNotNull(ConceptoMovimientoCaja::find($mio->id));
    }

    /**
     * Las dos entradas genéricas tampoco los saltean: la eliminación masiva respeta el rechazo
     * del destroy() (no lo cuenta como eliminado) y la actualización masiva se corta antes de
     * encolar nada.
     *
     * @test
     */
    public function las_entradas_masivas_no_saltean_la_proteccion()
    {
        $sistema = $this->concepto_del_sistema('ZZ Sistema masivas');

        $baja = $this->putJson('api/delete/concepto_movimiento_caja', ['models_id' => [$sistema->id]]);
        $this->assertNotNull(ConceptoMovimientoCaja::find($sistema->id), 'La eliminación masiva no puede borrar un concepto del sistema. Respuesta: ' . $baja->getStatusCode() . ' ' . substr((string) $baja->getContent(), 0, 500));

        $edicion = $this->putJson('api/update/concepto_movimiento_caja', [
            'models_id'   => [$sistema->id],
            'update_form' => [['key' => 'name', 'value' => 'ZZ Pisado masivo']],
        ]);
        $edicion->assertStatus(422);
        $this->assertSame('ZZ Sistema masivas', $sistema->fresh()->name);
    }
}
