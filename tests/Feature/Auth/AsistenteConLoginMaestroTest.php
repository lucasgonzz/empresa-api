<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/** Política solicitada por Lucas: el maestro opera como el dueño sin otra autorización. */
class AsistenteConLoginMaestroTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;
    private $hilo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::whereNull('owner_id')->firstOrFail();
        AgentesIaHelper::fijar($this->dueno, true);
        $this->hilo = AiConversation::create([
            'user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id,
            'origen' => 'usuario', 'titulo' => 'Conversación de la cuenta',
        ]);
        config(['acceso_soporte.asistente_maestro_password_hash' => '']);
        $this->withHeader('Referer', rtrim(config('app.url'), '/') . '/');
    }

    /** @dataProvider modos */
    public function test_el_maestro_sin_hash_ni_cookie_extra_opera_como_el_dueno(string $comando, bool $omitir): void
    {
        $this->ingresar($comando)
            ->assertJsonPath('user.id', $this->dueno->id)
            ->assertJsonPath('user.owner_id', null)
            ->assertJsonPath('user.asistente_personal_autorizado', true)
            ->assertJsonPath('user.skip_offline_articles_sync', $omitir);
        $this->getJson('/api/user')->assertOk()
            ->assertJsonPath('user.asistente_personal_autorizado', true)
            ->assertJsonPath('user.skip_offline_articles_sync', $omitir);
        $this->getJson('/api/ai-conversations')->assertOk()->assertJsonFragment(['id' => $this->hilo->id]);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertOk();
        $this->getJson('/api/mostrador/reportes')->assertOk();
        $this->getJson('/api/user/asistente-config')->assertOk();
        $this->patchJson('/api/ai-conversations/' . $this->hilo->id, ['titulo' => 'Auditado'])->assertOk();
        $this->assertSame('Auditado', $this->hilo->fresh()->titulo);
        $this->postJson('/api/mcp/conexion')->assertCreated()->assertJsonPath('activa', true);
        $this->getJson('/api/mcp/conexion')->assertOk()->assertJsonPath('activa', true);
        $this->deleteJson('/api/mcp/conexion')->assertOk()->assertJsonPath('activa', false);

        config(['acceso_soporte.asistente_maestro_password_hash' => password_hash('configuracion-legada', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', true);
    }

    /** @dataProvider modos */
    public function test_transferir_la_sesion_conserva_acceso_y_modo_de_descarga(string $comando, bool $omitir): void
    {
        $this->ingresar($comando);
        $transferencia = $this->postJson('/version-session-token')->assertOk();
        $this->usar_cookie($transferencia);
        $login = $this->postJson('/login-from-version-session-token', ['token' => $transferencia->json('token')])
            ->assertOk()->assertJsonPath('login', true)
            ->assertJsonPath('user.es_acceso_maestro', true)
            ->assertJsonPath('user.asistente_personal_autorizado', true)
            ->assertJsonPath('user.skip_offline_articles_sync', $omitir);
        $this->usar_cookie($login);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertOk();
    }

    // El documento de un empleado abre la sesión de ESE empleado (lo usa la verificación de perfil
    // de soporte): el maestro ve lo que ve el empleado y el chat privado del dueño sigue cerrado.
    public function test_un_documento_de_empleado_abre_su_perfil_sin_el_chat_del_dueno(): void
    {
        $empleado = User::factory()->create(['owner_id' => $this->dueno->id, 'doc_number' => '99876452', 'admin_access' => false]);
        $this->ingresar($empleado->doc_number . ' login')
            ->assertJsonPath('user.id', $empleado->id)->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->assertFalse((bool) $empleado->fresh()->admin_access);
        $this->assertContains($this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->status(), [403, 404]);
    }

    public function test_el_maestro_de_otro_comercio_no_lee_hilos_ajenos(): void
    {
        $otro = User::factory()->create(['owner_id' => null, 'doc_number' => '99876453']);
        AgentesIaHelper::fijar($otro, true);
        config(['app.USER_ID' => $this->dueno->id]);
        $empleado = User::factory()->create(['owner_id' => $otro->id, 'doc_number' => '99876559', 'admin_access' => false]);
        $this->ingresar($otro->doc_number . ' login')->assertJsonPath('user.id', $otro->id)
            ->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertNotFound();
    }

    public function test_entrar_con_maestro_reemplaza_el_marcador_anterior_de_soporte(): void
    {
        $this->withSession(['acceso_soporte_token_id' => 12]);
        $this->ingresar('login')->assertSessionMissing('acceso_soporte_token_id')
            ->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.es_sesion_soporte', false);
    }

    public function test_un_maestro_autenticado_como_empleado_conserva_su_perfil_sin_chat_privado(): void
    {
        $otro = User::factory()->create(['owner_id' => null, 'doc_number' => '99876557']);
        AgentesIaHelper::fijar($otro, true);
        $empleado = User::factory()->create(['owner_id' => $otro->id, 'admin_access' => false]);
        $this->actingAs($empleado, 'web')->withSession([
            'master_login_bypass_user_last_activity' => true, 'skip_offline_articles_sync' => true,
            'auth_user' => $empleado, 'owner' => $otro,
        ])->getJson('/api/user')->assertOk()->assertJsonPath('user.id', $empleado->id)
            ->assertJsonPath('user.asistente_personal_autorizado', false)
            ->assertJsonPath('user.skip_offline_articles_sync', true);
        $this->assertContains($this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->status(), [403, 404]);
        $this->assertFalse((bool) $empleado->fresh()->admin_access);
    }

    public function test_un_login_propio_reemplaza_el_modo_maestro_y_las_marcas_de_soporte(): void
    {
        $cliente = User::factory()->create([
            'owner_id' => null, 'doc_number' => '99876563',
            'password' => \Illuminate\Support\Facades\Hash::make('clave-exclusiva-del-fixture'),
        ]);
        $this->withSession([
            'master_login_bypass_user_last_activity' => true, 'skip_offline_articles_sync' => true,
            'acceso_soporte_token_id' => 8,
        ]);
        $respuesta = $this->postJson('/login', [
            'doc_number' => $cliente->doc_number, 'password' => 'clave-exclusiva-del-fixture',
        ])->assertOk()->assertJsonPath('login', true)
            ->assertJsonPath('user.es_acceso_maestro', false)
            ->assertJsonPath('user.es_sesion_soporte', false)
            ->assertJsonPath('user.skip_offline_articles_sync', false)
            ->assertSessionMissing('acceso_soporte_token_id');
        $this->usar_cookie($respuesta);
    }

    public function test_una_transferencia_maestra_reemplaza_la_identidad_tecnica_del_destino(): void
    {
        $token = \App\Http\Controllers\Helpers\VersionSessionTransferHelper::create_for_user($this->dueno->id, true, true);
        $this->withSession(['acceso_soporte_token_id' => 7, 'demo_ingreso_token_id' => 8]);
        $respuesta = $this->postJson('/login-from-version-session-token', ['token' => $token])
            ->assertOk()->assertJsonPath('login', true)->assertJsonPath('user.id', $this->dueno->id)
            ->assertJsonPath('user.es_sesion_soporte', false)->assertJsonPath('user.asistente_personal_autorizado', true)
            ->assertSessionMissing('acceso_soporte_token_id')->assertSessionMissing('demo_ingreso_token_id');
        $this->usar_cookie($respuesta);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertOk();
    }

    public static function modos(): array
    {
        return [['login', true], ['login_full', false], ['login full', false]];
    }

    private function ingresar(string $comando)
    {
        $respuesta = $this->postJson('/login', ['doc_number' => $comando, 'password' => 'valor-local-sin-validacion-extra'])
            ->assertOk()->assertJsonPath('login', true)->assertJsonPath('user.es_acceso_maestro', true);
        $this->usar_cookie($respuesta);
        return $respuesta;
    }

    private function usar_cookie($respuesta): void
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
}
