<?php

namespace Tests\Feature\ActualizacionDelSistema;

use App\Http\Controllers\Helpers\AccesoSoporteHelper;
use App\Models\AccesoSoporteToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CA2 y CA3 de la misión aviso-actualizacion-sistema (25/11/2026): las sesiones YA abiertas también quedan
 * afuera cuando el sistema pasa a `actualizando`, y el bloqueo vence solo.
 *
 * El middleware `BloqueoPorActualizacion` (último del grupo `api`) contesta la marca 503 en la primera
 * request con sesión y cierra la sesión del lado servidor: la request siguiente ya es 401. No toca:
 *  - la sesión maestra (acceso maestro del humo),
 *  - la sesión de soporte (token firmado del admin),
 *  - las rutas `api/admin-sync/*` (el admin tiene que poder liberar el estado).
 *
 * "Computadora" = sesión de Laravel. Cada una se simula con su cookie de sesión de 40 caracteres y el
 * usuario autenticado con `actingAs`; entre computadoras se limpia la sesión en memoria del test.
 *
 * Motivo esperado del rojo ANTES de la implementación: la tabla `actualizaciones_del_sistema` no existe.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class BloqueoDeSesionesTest extends TestCase
{
    use DatabaseTransactions;

    /** Contraseña conocida de los empleados del fixture. */
    const PASSWORD = 'clave-del-test-actualizacion-9f3';

    /** Texto que contesta el sistema del cliente mientras se actualiza (contrato §2.4). */
    const MENSAJE = 'Estamos actualizando el sistema. Mientras tanto no se puede usar. Cuando termine vas a poder ingresar de nuevo desde esta pantalla.';

    /** Clave de admin de la instancia en estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-sesiones-5d2c';

    /** Id de sesión (40 caracteres alfanuméricos) de la computadora de E1. */
    const SESION_E1 = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';

    /** @var \App\Models\User El dueño de la base de testing. */
    protected $dueno;

    /** @var \App\Models\User Un empleado del dueño (E1). */
    protected $empleado;

    /**
     * Escenario conocido: dueño 500, un empleado, clave de admin cargada y cache limpia.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        config([
            'app.USER_ID'                        => $this->dueno->id,
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
            'acceso_soporte.habilitado'          => true,
            'acceso_soporte.ttl_segundos'        => 120,
        ]);

        $this->empleado = User::factory()->create([
            'owner_id'         => $this->dueno->id,
            'name'             => 'Empleado Uno',
            'doc_number'       => '77000011',
            'password'         => Hash::make(self::PASSWORD),
            'admin_access'     => false,
            'activity_minutes' => 60,
        ]);

        Cache::flush();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Escribe la fila de estado de un dueño directamente en la tabla (contrato §4.2) y vacía la cache.
     *
     * @param string       $estado   libre|pidiendo_ok|rechazado|actualizando
     * @param Carbon|null  $vence_at Vencimiento del estado `actualizando`.
     * @param int|null     $owner_id Dueño de la fila (por defecto el del fixture).
     * @return void
     */
    protected function fijar_estado($estado, $vence_at = null, $owner_id = null)
    {
        $owner_id = $owner_id === null ? $this->dueno->id : $owner_id;

        DB::table('actualizaciones_del_sistema')->where('owner_id', $owner_id)->delete();

        DB::table('actualizaciones_del_sistema')->insert([
            'owner_id'           => $owner_id,
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
     * Cambia de "computadora": limpia la sesión en memoria, olvida los guards, autentica al usuario y fija el
     * id de la cookie de sesión. Opcionalmente deja claves en la sesión (marcas de maestro o soporte).
     *
     * @param \App\Models\User $usuario Quién está sentado en esa computadora.
     * @param string           $sesion  Id de sesión de 40 caracteres.
     * @param array            $datos   Claves a dejar en la sesión.
     * @return $this
     */
    protected function computadora($usuario, $sesion, array $datos = [])
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($usuario, 'web');
        $this->withCredentials()->withCookie(config('session.cookie'), $sesion);

        if (count($datos) > 0) {
            $this->withSession($datos);
        }

        return $this;
    }

    /**
     * Afirma la marca de "estamos actualizando" en una ruta con sesión (contrato §2.4).
     *
     * @param \Illuminate\Testing\TestResponse $respuesta
     * @return void
     */
    protected function assert_marca($respuesta)
    {
        $respuesta->assertStatus(503);
        $respuesta->assertHeader('Retry-After', '30');
        $respuesta->assertJsonPath('error', 'sistema_actualizando');
        $respuesta->assertJsonPath('message', self::MENSAJE);
        $respuesta->assertJsonPath('reintentar_en_segundos', 30);
    }

    /**
     * CA2: E1 tiene la sesión abierta; el estado pasa a `actualizando`. Su próximo `GET api/user` contesta la
     * marca 503 y la request siguiente ya es 401: la sesión quedó cerrada del lado servidor.
     *
     * @return void
     */
    public function test_sesion_abierta_recibe_marca_y_queda_cerrada()
    {
        $this->fijar_estado('libre');
        $this->computadora($this->empleado, self::SESION_E1);

        $this->getJson('/api/user')->assertStatus(200);

        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $this->assert_marca($this->getJson('/api/user'));

        $this->getJson('/api/user')->assertStatus(401);
    }

    /**
     * CA2: la sesión maestra y la de soporte siguen funcionando con el sistema bloqueado.
     *
     * @return void
     */
    public function test_maestro_y_soporte_siguen()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        // Acceso maestro: la clave de sesión que deja `loginLucas()`.
        $this->computadora($this->dueno, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2', [
            'master_login_bypass_user_last_activity' => true,
        ]);

        $maestro = $this->getJson('/api/user');
        $maestro->assertStatus(200);
        $maestro->assertJsonPath('user.es_acceso_maestro', true);

        // Acceso de soporte: token real emitido por el helper, marcador en la sesión.
        $token = AccesoSoporteHelper::emitir($this->dueno, 'test', 'prueba', '10.0.0.9');
        $fila = AccesoSoporteToken::where('token_hash', hash('sha256', $token))->firstOrFail();

        $this->computadora($this->dueno, 'ccccccccccccccccccccccccccccccccccccccc3', [
            'acceso_soporte_token_id' => $fila->id,
        ]);

        $soporte = $this->getJson('/api/user');
        $soporte->assertStatus(200);
        $soporte->assertJsonPath('user.es_sesion_soporte', true);
    }

    /**
     * CA2: las rutas `api/admin-sync/*` no se cortan, ni siquiera si el request trae una sesión abierta:
     * el admin tiene que poder leer y liberar el estado mientras el sistema está bloqueado.
     *
     * @return void
     */
    public function test_admin_sync_no_se_corta()
    {
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));

        $this->computadora($this->empleado, self::SESION_E1);

        $respuesta = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->getJson('/api/admin-sync/actualizacion-del-sistema');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'actualizando');

        $liberado = $this->withHeader('X-Admin-Api-Key', self::CLAVE_DE_ADMIN)
            ->putJson('/api/admin-sync/actualizacion-del-sistema', ['estado' => 'libre']);

        $liberado->assertStatus(200);
        $liberado->assertJsonPath('estado', 'libre');
    }

    /**
     * CA3: un `actualizando` con `vence_at` hace un segundo ya no bloquea (no queda cerrado para siempre
     * si el admin se cae a mitad del deploy): la sesión sigue y el login funciona.
     *
     * @return void
     */
    public function test_vencido_no_bloquea()
    {
        $this->fijar_estado('actualizando', Carbon::now()->subSecond());

        $this->computadora($this->empleado, self::SESION_E1);

        $respuesta = $this->getJson('/api/user');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('user.id', $this->empleado->id);
    }

    /**
     * El estado va por dueño: un `actualizando` de OTRO dueño de la misma base no toca a este.
     *
     * @return void
     */
    public function test_el_estado_de_otro_dueno_no_bloquea()
    {
        $this->fijar_estado('libre');
        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60), 987654);

        $this->computadora($this->empleado, self::SESION_E1);

        $this->getJson('/api/user')->assertStatus(200);
    }
}
