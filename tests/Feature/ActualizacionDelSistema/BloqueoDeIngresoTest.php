<?php

namespace Tests\Feature\ActualizacionDelSistema;

use App\Http\Controllers\Helpers\VersionSessionTransferHelper;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CA1 de la misión aviso-actualizacion-sistema (25/11/2026): mientras el sistema se actualiza no se puede
 * INGRESAR. El estado vive en la tabla `actualizaciones_del_sistema` (una fila por dueño); con estado
 * `actualizando` y `vence_at` en el futuro, los tres caminos de login (`login`, `login-forzado` y
 * `login-from-version-session-token`) contestan la marca 503 `sistema_actualizando` en vez de abrir sesión.
 *
 * Qué NO se corta (y por eso tiene su test):
 *  - Credenciales inválidas: 200 `login:false` sin `error`, igual que hoy (el corte va DESPUÉS del
 *    `Auth::attempt` exitoso, así que un atacante no aprende nada del estado).
 *  - El acceso maestro (`"<doc> login"`): es lo que usa el humo del pipeline para probar la versión nueva
 *    mientras el sistema está bloqueado para los usuarios.
 *
 * Motivo esperado del rojo ANTES de la implementación: la tabla `actualizaciones_del_sistema` no existe
 * (todo test inserta el estado con `DB::table`, que es el contrato §4.2 que también lee tienda-api).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot ya está sembrada (dueño 500).
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class BloqueoDeIngresoTest extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña conocida de los empleados del fixture. */
    const PASSWORD = 'clave-del-test-actualizacion-9f3';

    /** Texto que contesta el sistema del cliente mientras se actualiza (contrato §2.4). */
    const MENSAJE = 'Estamos actualizando el sistema. Mientras tanto no se puede usar. Cuando termine vas a poder ingresar de nuevo desde esta pantalla.';

    /** @var \App\Models\User El dueño de la base de testing. */
    protected $dueno;

    /** @var \App\Models\User Un empleado del dueño. */
    protected $empleado;

    /**
     * Deja el escenario conocido: dueño 500, un empleado con contraseña conocida, cache limpia y el
     * Referer del dominio stateful (sin ese header no hay sesión en el request).
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        config(['app.USER_ID' => $this->dueno->id]);

        $this->empleado = User::factory()->create([
            'owner_id'         => $this->dueno->id,
            'name'             => 'Empleado Uno',
            'doc_number'       => '77000001',
            'password'         => Hash::make(self::PASSWORD),
            'admin_access'     => false,
            'activity_minutes' => 60,
        ]);

        Cache::flush();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Escribe la fila de estado del dueño directamente en la tabla (contrato §4.2) y vacía la cache de
     * lectura para que el próximo request vea el valor nuevo.
     *
     * @param string      $estado   libre|pidiendo_ok|rechazado|actualizando
     * @param Carbon|null $vence_at Vencimiento del estado `actualizando`.
     * @return void
     */
    protected function fijar_estado($estado, $vence_at = null)
    {
        DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->delete();

        DB::table('actualizaciones_del_sistema')->insert([
            'owner_id'           => $this->dueno->id,
            'estado'             => $estado,
            'upgrade_id'         => 77,
            'version'            => '4.3.90',
            'actualizando_desde' => $estado === 'actualizando' ? Carbon::now() : null,
            'vence_at'           => $vence_at,
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);

        Cache::flush();
    }

    /**
     * Afirma la marca de "estamos actualizando" que contesta el login (contrato §2.4): 503, Retry-After 30, el
     * `error` y el `message` de siempre, y las claves de login en su forma de "no entró".
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @return void
     */
    protected function assert_marca_de_login($respuesta)
    {
        $respuesta->assertStatus(503);
        $respuesta->assertHeader('Retry-After', '30');
        $respuesta->assertJsonPath('error', 'sistema_actualizando');
        $respuesta->assertJsonPath('message', self::MENSAJE);
        $respuesta->assertJsonPath('reintentar_en_segundos', 30);
        $respuesta->assertJsonPath('login', false);
        $respuesta->assertJsonPath('user', null);
        $respuesta->assertJsonPath('user_last_activity', false);
        $respuesta->assertJsonPath('user_last_activity_wait_minutes', 0);
    }

    /**
     * CA1: con el estado `actualizando` (vence en 60 min) un login con credenciales válidas no abre sesión,
     * contesta la marca y NO toma el candado de sesión única (`users.session_id` sigue en NULL).
     *
     * @return void
     */
    public function test_login_bloqueado_mientras_actualiza()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->empleado->doc_number,
            'password'   => self::PASSWORD,
        ]);

        $this->assert_marca_de_login($respuesta);
        $this->assertNull($this->empleado->fresh()->session_id, 'El login bloqueado no puede tomar el candado de sesión única.');
        $this->assertNull($this->empleado->fresh()->last_activity);
    }

    /**
     * CA1: credenciales inválidas se contestan igual que hoy, aunque el sistema esté actualizando: 200
     * `login:false` y SIN la clave `error`.
     *
     * @return void
     */
    public function test_credenciales_invalidas_igual_que_hoy()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->empleado->doc_number,
            'password'   => 'una-clave-que-no-es',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('login', false);
        $this->assertArrayNotHasKey('error', $respuesta->json(), 'Una credencial inválida no tiene que revelar el estado de la actualización.');
        $this->assertNull($this->empleado->fresh()->session_id);
    }

    /**
     * CA1: el acceso maestro (`"<doc> login"`) entra igual: el humo del pipeline prueba la versión nueva
     * con él mientras el sistema está bloqueado para los usuarios.
     *
     * @return void
     */
    public function test_acceso_maestro_entra()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->dueno->doc_number . ' login',
            'password'   => 'valor-local-sin-validacion-extra',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('login', true);
        $respuesta->assertJsonPath('user.es_acceso_maestro', true);
    }

    /**
     * CA1: `login-forzado` ("cerrar la otra sesión e ingresar acá") tiene el mismo corte que `login`.
     *
     * @return void
     */
    public function test_login_forzado_bloqueado()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $respuesta = $this->postJson('/login-forzado', [
            'doc_number' => $this->empleado->doc_number,
            'password'   => self::PASSWORD,
        ]);

        $this->assert_marca_de_login($respuesta);
        $this->assertNull($this->empleado->fresh()->session_id);
    }

    /**
     * El tercer camino de ingreso, la transferencia de sesión entre versiones, también queda cortado: si
     * no, bastaría cambiar de versión para entrar con el sistema bloqueado.
     *
     * @return void
     */
    public function test_transferencia_de_version_bloqueada()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $token = VersionSessionTransferHelper::create_for_user($this->empleado->id, false, false);

        $respuesta = $this->postJson('/login-from-version-session-token', ['token' => $token]);

        $this->assert_marca_de_login($respuesta);
        $this->assertNull($this->empleado->fresh()->session_id);
    }

    /**
     * CA1: con el estado `libre` el login funciona como siempre (200, `login:true`, y toma el candado).
     *
     * @return void
     */
    public function test_libre_deja_entrar()
    {
        $this->fijar_estado('libre');

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->empleado->doc_number,
            'password'   => self::PASSWORD,
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('login', true);
        $respuesta->assertJsonPath('user.id', $this->empleado->id);
        $this->assertNotNull($this->empleado->fresh()->session_id);
    }

    /**
     * CA3 (parte del ingreso): un `actualizando` con `vence_at` hace un segundo ya no bloquea el login.
     *
     * @return void
     */
    public function test_vencido_deja_entrar()
    {
        $this->fijar_estado('actualizando', Carbon::now()->subSecond());

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->empleado->doc_number,
            'password'   => self::PASSWORD,
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('login', true);
    }
}
