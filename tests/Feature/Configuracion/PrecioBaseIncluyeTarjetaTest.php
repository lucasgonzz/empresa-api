<?php

namespace Tests\Feature\Configuracion;

use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\EmpresaTestCase;

/**
 * Misión destrabar-videos-centro (16/10/2026) — Configuración guarda el tilde
 * `users.precio_base_incluye_tarjeta` ("el precio de etiqueta ya incluye el recargo de la tarjeta
 * más cara").
 *
 * `UserController::update()` no tenía el campo: se prendía, decía "Actualizado" y al reabrir
 * volvía apagado. Ahora se guarda con los tres guards de `aplicar_descuentos_proveedor_al_asignar`:
 * vive en el dueño, un request sin la clave (o con null) no la toca, y un empleado no la escribe.
 *
 * @group configuracion
 */
class PrecioBaseIncluyeTarjetaTest extends EmpresaTestCase
{

    /**
     * @return User
     */
    protected function dueno()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * El valor guardado en la fila del dueño, leído directo de la tabla.
     *
     * @return int
     */
    protected function valor_del_dueno()
    {
        return (int) DB::table('users')->where('id', $this->dueno()->id)->value('precio_base_incluye_tarjeta');
    }

    /**
     * El PUT de Configuración: el modelo entero, como lo postea `ModelForm`.
     *
     * @param  User  $user
     * @param  array  $overrides
     * @return array
     */
    protected function payload_usuario($user, $overrides = [])
    {
        return array_merge($user->fresh()->toArray(), $overrides);
    }

    /**
     * El dueño lo prende y lo apaga, y la respuesta lo trae.
     *
     * @test
     */
    public function el_dueno_lo_prende_y_lo_apaga()
    {
        $dueno = $this->dueno();

        foreach ([1, 0, 1] as $valor) {
            $response = $this->putJson('api/user/' . $dueno->id, $this->payload_usuario($dueno, [
                'precio_base_incluye_tarjeta' => $valor,
            ]));
            $response->assertStatus(200);

            $this->assertSame($valor, $this->valor_del_dueno(), 'El PUT de Configuración tiene que persistir ' . $valor . ' en el dueño.');
            $this->assertEquals($valor, (int) $response->json('model.precio_base_incluye_tarjeta'), 'La respuesta trae el valor nuevo.');
        }
    }

    /**
     * Un request sin la clave (una SPA vieja) o con null no apaga lo que el dueño prendió.
     *
     * @test
     */
    public function sin_la_clave_o_con_null_no_se_toca()
    {
        $dueno = $this->dueno();
        DB::table('users')->where('id', $dueno->id)->update(['precio_base_incluye_tarjeta' => 1]);

        $payload = $this->payload_usuario($dueno);
        unset($payload['precio_base_incluye_tarjeta']);
        $this->putJson('api/user/' . $dueno->id, $payload)->assertStatus(200);
        $this->assertSame(1, $this->valor_del_dueno(), 'Un request sin la clave no puede apagar el tilde.');

        $this->putJson('api/user/' . $dueno->id, $this->payload_usuario($dueno, [
            'precio_base_incluye_tarjeta' => null,
        ]))->assertStatus(200);
        $this->assertSame(1, $this->valor_del_dueno(), 'Un null no puede apagar el tilde.');
    }

    /**
     * Un empleado que guarda su Configuración (por ejemplo, para cambiarse la contraseña) postea su
     * propia columna en 0: eso no le apaga el tilde al comercio.
     *
     * @test
     */
    public function un_empleado_no_se_lo_apaga_al_dueno()
    {
        $dueno = $this->dueno();
        DB::table('users')->where('id', $dueno->id)->update(['precio_base_incluye_tarjeta' => 1]);

        $empleado = User::create([
            'name'     => 'ZZ Empleado precio con tarjeta',
            'email'    => 'precio-tarjeta-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
            // Con acceso de administrador: sin él, Configuración le responde 403 y el test no
            // mediría el guard del controlador.
            'admin_access' => 1,
            'precio_base_incluye_tarjeta' => 0,
        ]);

        Auth::forgetGuards();
        $this->actingAs($empleado, 'web');

        $this->putJson('api/user/' . $empleado->id, $this->payload_usuario($empleado, [
            'precio_base_incluye_tarjeta' => 0,
        ]))->assertStatus(200);

        $this->assertSame(1, $this->valor_del_dueno(), 'El guardado de un empleado no puede apagarle el tilde al comercio.');
    }
}
