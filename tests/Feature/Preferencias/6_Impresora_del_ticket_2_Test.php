<?php

namespace Tests\Feature\Preferencias;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la impresora del Ticket 2.0.
 *
 * Hasta esta version la columna `users.impresora` no tenia ningun camino de escritura: se
 * llenaba una sola vez al crear la cuenta con el literal 'comerciocity' y de ahi salia la
 * obligacion de renombrar la impresora en Windows.
 *
 * Lo que protege, que es todo lo que puede fallar EN SILENCIO:
 * - que la ruta no se la coma el comodin `user/{id}`, que aceptaria el PUT con id =
 *   "set-impresora" y pisaria media configuracion de la cuenta con nulls, devolviendo 200;
 * - que resuelva con Auth::user() y no con $this->userId(), que devuelve el dueño.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria, rompiendo el resto de las suites.
 */
class Impresora_del_ticket_2_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Modo_oscuro).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_endpoint_guarda_la_impresora_del_usuario_autenticado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/set-impresora', [
            'impresora' => 'XP-80',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['impresora' => 'XP-80']);
        $this->assertEquals('XP-80', $user->fresh()->impresora);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_endpoint_acepta_null_para_dejar_de_usar_una_impresora()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $this->putJson('api/user/set-impresora', ['impresora' => 'XP-80'])->assertStatus(200);

        $response = $this->putJson('api/user/set-impresora', ['impresora' => null]);

        $response->assertStatus(200);
        $this->assertNull($user->fresh()->impresora);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_endpoint_rechaza_un_nombre_mas_largo_que_la_columna()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/set-impresora', [
            'impresora' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422);
    }

    /**
     * La eleccion de un empleado es suya: con $this->userId() en vez de Auth::user() esta
     * quedaria grabada en la fila del dueño y le cambiaria la impresora al dueño.
     *
     * @group preferencias
     * @test
     */
    public function la_impresora_del_empleado_no_se_guarda_en_el_dueno()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $owner->impresora = 'IMPRESORA-DEL-DUENO';
        $owner->save();

        $empleado = User::create([
            'owner_id' => $owner->id,
            'password' => bcrypt('zz-password-testing'),
            'status'   => 'commerce',
        ]);

        $this->actingAs($empleado, 'web');

        $response = $this->putJson('api/user/set-impresora', [
            'impresora' => 'IMPRESORA-DE-LA-CAJA-2',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('IMPRESORA-DE-LA-CAJA-2', $empleado->fresh()->impresora);
        $this->assertEquals('IMPRESORA-DEL-DUENO', $owner->fresh()->impresora);
    }

    /**
     * Si la ruta cayera en el comodin `user/{id}`, UserController@update asignaria todos los
     * campos del request -- ausentes -- y dejaria la cuenta con medio perfil en null,
     * devolviendo 200 igual.
     *
     * @group preferencias
     * @test
     */
    public function el_endpoint_no_pisa_el_resto_del_perfil()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $name = $user->name;
        $company_name = $user->company_name;
        $percentage_gain = $user->percentage_gain;

        $response = $this->putJson('api/user/set-impresora', [
            'impresora' => 'XP-80',
        ]);

        $response->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertEquals($name, $fresh->name);
        $this->assertEquals($company_name, $fresh->company_name);
        $this->assertEquals($percentage_gain, $fresh->percentage_gain);
    }
}
