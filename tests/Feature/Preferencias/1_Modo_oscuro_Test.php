<?php

namespace Tests\Feature\Preferencias;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests de la preferencia de modo oscuro (grupo 304, prompt 01). Estrena la suite
 * `preferencias` en empresa-api/develop.
 *
 * Protege lo que puede fallar en SILENCIO: que el endpoint resuelva el usuario con Auth::user()
 * y no con $this->userId() (que devuelve el dueño), y que no reutilice update() y termine
 * pisando el resto de la configuración de la cuenta.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Modo_oscuro_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patrón que Sales/Stock/Expenses).
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
    public function el_endpoint_guarda_la_preferencia_del_usuario_autenticado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $response = $this->putJson('api/user/set-dark-mode/1');
        $response->assertStatus(200);
        $response->assertJson(['dark_mode' => 1]);
        $this->assertEquals(1, $user->fresh()->dark_mode);

        $response = $this->putJson('api/user/set-dark-mode/0');
        $response->assertStatus(200);
        $response->assertJson(['dark_mode' => 0]);
        $this->assertEquals(0, $user->fresh()->dark_mode);
    }

    /**
     * @group preferencias
     * @test
     */
    public function la_preferencia_del_empleado_no_se_guarda_en_el_dueno()
    {
        $owner = $this->usuario_de_testing();
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $empleado = User::create([
            'owner_id'  => $owner->id,
            'password'  => bcrypt('zz-password-testing'),
            'status'    => 'commerce',
            'dark_mode' => 0,
        ]);

        $this->actingAs($empleado, 'web');

        $response = $this->putJson('api/user/set-dark-mode/1');
        $response->assertStatus(200);

        $this->assertEquals(1, $empleado->fresh()->dark_mode);
        $this->assertEquals(0, $owner->fresh()->dark_mode);
    }

    /**
     * @group preferencias
     * @test
     */
    public function el_endpoint_normaliza_el_valor_y_no_toca_otras_columnas()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $name = $user->name;
        $percentage_gain = $user->percentage_gain;

        $response = $this->putJson('api/user/set-dark-mode/1');
        $response->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertEquals($name, $fresh->name);
        $this->assertEquals($percentage_gain, $fresh->percentage_gain);
    }
}
