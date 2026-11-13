<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VerificacionDePerfilConLoginMaestroTest extends TestCase
{
    use DatabaseTransactions;

    public function test_la_capacidad_publica_no_se_cachea_y_no_entrega_datos()
    {
        $respuesta = $this->getJson('/api/acceso-soporte/verificacion-perfil');
        $respuesta->assertOk()->assertExactJson(['verificacion_perfil' => 1]);
        $this->assertStringContainsString('no-store', $respuesta->headers->get('Cache-Control'));
    }

    /** @dataProvider modos_maestros */
    public function test_ingresar_y_salir_preserva_las_credenciales_y_la_sesion_real($modo, $empleado)
    {
        $dueno = User::whereNull('owner_id')->firstOrFail();
        $usuario = $empleado ? User::factory()->create([
            'owner_id' => $dueno->id,
            'doc_number' => '90989811',
        ]) : $dueno;
        $this->preparar_sesion_real($usuario);
        $anterior = $usuario->fresh();

        DB::enableQueryLog();
        $login = $this->ingresar($usuario, $modo, $empleado);
        $login->assertJsonPath('user.skip_offline_articles_sync', $modo === 'login');
        $this->usar_cookie($login);
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.id', $dueno->id)
            ->assertJsonPath('user.es_acceso_maestro', true);

        $logout = $this->postJson('/logout')->assertOk();
        $this->usar_cookie($logout);
        $this->getJson('/api/user')->assertUnauthorized();

        $this->assert_sesion_real_intacta($anterior, $usuario->fresh());
        $actualizaciones = array_filter(DB::getQueryLog(), function ($entrada) {
            return preg_match('/^update\s+\x60?users\b/i', $entrada['query']);
        });
        DB::disableQueryLog();
        $this->assertEmpty($actualizaciones, 'Ni siquiera se tiene que escribir temporalmente en el usuario real.');
    }

    public function modos_maestros()
    {
        return [
            'dueño por defecto' => ['login', false],
            'empleado por documento' => ['login', true],
            'empleado por documento con sincronización offline' => ['login full', true],
        ];
    }

    public function test_transferir_una_sesion_maestra_no_cierra_la_sesion_real()
    {
        $dueno = User::whereNull('owner_id')->firstOrFail();
        $this->preparar_sesion_real($dueno);
        $anterior = $dueno->fresh();

        $this->usar_cookie($this->ingresar($dueno, 'login', false));
        $respuesta = $this->postJson('/version-session-token')->assertOk();
        $this->assertNotEmpty($respuesta->json('token'));
        $this->assert_sesion_real_intacta($anterior, $dueno->fresh());

        $this->usar_cookie($respuesta);
        $this->getJson('/api/user')->assertUnauthorized();
        $this->postJson('/login-from-version-session-token', ['token' => $respuesta->json('token')])
            ->assertOk()
            ->assertJsonPath('login', true)
            ->assertJsonPath('user.es_acceso_maestro', true);
        $this->assert_sesion_real_intacta($anterior, $dueno->fresh());
    }

    private function ingresar(User $usuario, $modo, $empleado)
    {
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
        return $this->postJson('/login', [
            'doc_number' => $empleado ? $usuario->doc_number . ' ' . $modo : $modo,
        ])->assertOk()
            ->assertJsonPath('login', true)
            ->assertJsonPath('user.id', $usuario->owner_id ?: $usuario->id)
            ->assertJsonPath('user.es_acceso_maestro', true);
    }

    private function preparar_sesion_real(User $usuario)
    {
        $usuario->forceFill([
            'prev_password' => bcrypt('hash-de-prueba-anterior'),
            'remember_token' => 'recordarme-del-dispositivo-real',
            'activity_minutes' => 60,
            'session_id' => 'candado-del-dispositivo-real',
            'last_activity' => now(),
            'login_at' => '2026-10-01 10:00:00',
            'logout_at' => '2026-10-01 09:00:00',
        ])->save();
    }

    private function usar_cookie($respuesta)
    {
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $this->app['auth']->forgetGuards();
                $this->withCredentials()->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
                return;
            }
        }
        $this->fail('La respuesta no emitió la cookie de sesión esperada.');
    }

    private function assert_sesion_real_intacta(User $anterior, User $despues)
    {
        foreach (['password', 'prev_password', 'remember_token', 'session_id', 'last_activity', 'login_at', 'logout_at'] as $atributo) {
            $this->assertSame($anterior->getRawOriginal($atributo), $despues->getRawOriginal($atributo), $atributo);
        }
    }
}
