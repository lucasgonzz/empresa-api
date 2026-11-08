<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AsistenteConLoginMaestroTest extends TestCase
{
    use DatabaseTransactions;

    private const CLAVE = 'credencial-maestra-de-prueba-local';
    private const CONFIG = 'acceso_soporte.asistente_maestro_password_hash';

    private $dueno;
    private $hilo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::whereNull('owner_id')->firstOrFail();
        AgentesIaHelper::fijar($this->dueno, true);
        $this->hilo = AiConversation::create([
            'user_id' => $this->dueno->id,
            'auth_user_id' => $this->dueno->id,
            'origen' => 'usuario',
            'titulo' => 'Conversación de la cuenta',
        ]);
        config([self::CONFIG => password_hash(self::CLAVE, PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->withHeader('Referer', rtrim(config('app.url'), '/') . '/');
    }

    public function test_el_dueno_indicado_puede_abrir_el_asistente_y_la_rotacion_corta_el_acceso(): void
    {
        $this->ingresar($this->dueno->doc_number . ' login', self::CLAVE)
            ->assertJsonPath('user.id', $this->dueno->id)
            ->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson('/api/ai-conversations')->assertOk()->assertJsonFragment(['id' => $this->hilo->id]);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertOk();
        $this->getJson('/api/mostrador/reportes')->assertOk();
        $this->getJson('/api/user/asistente-config')->assertOk();
        $this->postJson('/api/mcp/conexion')->assertForbidden();

        config([self::CONFIG => password_hash('otra-clave-de-prueba', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->assert_asistente_privado();
    }

    public function test_reingresar_con_la_clave_incorrecta_no_conserva_el_permiso(): void
    {
        $documento = $this->dueno->doc_number . ' login full';
        $this->ingresar($documento, self::CLAVE)->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->ingresar($documento, 'clave-incorrecta')->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->assert_asistente_privado();
    }

    public function test_un_documento_ambiguo_o_inexistente_solo_da_mantenimiento(): void
    {
        User::factory()->create(['owner_id' => null, 'doc_number' => $this->dueno->doc_number]);

        foreach (['login', 'documento-inexistente login', $this->dueno->doc_number . ' login'] as $comando) {
            $this->ingresar($comando, self::CLAVE)->assertJsonPath('user.asistente_personal_autorizado', false);
            $this->assert_asistente_privado();
        }
    }

    public function test_otro_dueno_de_la_base_puede_ingresar_por_documento_sin_leer_hilos_ajenos(): void
    {
        $otro_dueno = User::factory()->create(['owner_id' => null, 'doc_number' => '99876453']);
        AgentesIaHelper::fijar($otro_dueno, true);
        config(['app.USER_ID' => $this->dueno->id]);

        $this->ingresar($otro_dueno->doc_number . ' login', self::CLAVE)
            ->assertJsonPath('user.id', $otro_dueno->id)
            ->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertNotFound();
        $this->getJson('/api/mostrador/reportes')->assertOk();
    }

    public function test_transferir_la_sesion_maestra_conserva_mantenimiento_y_requiere_reautorizacion(): void
    {
        $this->ingresar($this->dueno->doc_number . ' login', self::CLAVE)
            ->assertJsonPath('user.asistente_personal_autorizado', true);
        $transferencia = $this->postJson('/version-session-token')->assertOk();
        $this->usar_cookie($transferencia);
        $login = $this->postJson('/login-from-version-session-token', ['token' => $transferencia->json('token')])
            ->assertOk()
            ->assertJsonPath('user.es_acceso_maestro', true)
            ->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->usar_cookie($login);
        $this->assert_asistente_privado();
    }

    public function test_un_empleado_con_acceso_administrativo_no_se_eleva_con_el_comando_maestro(): void
    {
        $empleado = User::factory()->create([
            'owner_id' => $this->dueno->id,
            'doc_number' => '99876452',
            'admin_access' => true,
        ]);
        $this->ingresar($empleado->doc_number . ' login', self::CLAVE)
            ->assertJsonPath('user.id', $empleado->id)
            ->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->assert_asistente_privado();
    }

    private function ingresar(string $documento, string $clave)
    {
        $respuesta = $this->postJson('/login', ['doc_number' => $documento, 'password' => $clave])
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

    private function assert_asistente_privado(): void
    {
        $this->getJson('/api/ai-conversations')->assertOk()->assertJsonCount(0, 'models');
        $this->getJson('/api/ai-conversations/' . $this->hilo->id . '/messages')->assertNotFound();
        $this->getJson('/api/mostrador/reportes')->assertForbidden();
        $this->getJson('/api/user/asistente-config')->assertForbidden();
    }
}
