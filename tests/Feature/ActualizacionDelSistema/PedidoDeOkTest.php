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
 * CA5 a CA11 de la misión aviso-actualizacion-sistema (25/11/2026): el pedido de OK a los usuarios
 * conectados, que es lo que pasa cuando el admin actualiza "con force" en horario de trabajo.
 *
 * Flujo que se fija (plan §2.2, §2.3, §2.6):
 *  - Presencia: cada computadora (= sesión de Laravel, guardada como `hash('sha256', session()->getId())`) que
 *    pollea `GET api/actualizacion-del-sistema/estado` deja `visto_at`. Los participantes de un pedido son las
 *    sesiones del dueño vistas en los últimos 3 minutos; maestro y soporte no cuentan.
 *  - `POST admin-sync/actualizacion-del-sistema/pedido` pasa a `pidiendo_ok` (idempotente) o, sin nadie
 *    conectado, directo a `actualizando`.
 *  - Cada computadora responde `acepto` (sigue trabajando y ve cuántas faltan) o `ahora_no` (cancela el pedido).
 *  - Cuando aceptan todas, o pasan los minutos para responder, el estado pasa a `actualizando` por 30 minutos
 *    y las computadoras que no contestaron quedan `expulsado`.
 *  - Las transiciones son perezosas: se evalúan en cada lectura (polling, admin) con `lockForUpdate`.
 *
 * Motivo esperado del rojo ANTES de la implementación: las rutas no existen (404) y las tablas
 * `actualizaciones_del_sistema` / `actualizacion_dispositivos` tampoco.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class PedidoDeOkTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de admin de la instancia en estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-pedido-ok-3c7b';

    /** Ruta admin del contrato (§4.1). */
    const URL_ADMIN = '/api/admin-sync/actualizacion-del-sistema';

    /** Rutas con sesión del contrato (§4.3). */
    const URL_ESTADO = '/api/actualizacion-del-sistema/estado';
    const URL_RESPUESTA = '/api/actualizacion-del-sistema/respuesta';
    const URL_PUBLICO = '/api/actualizacion-del-sistema/publico';

    /** Contraseña conocida de los empleados del fixture. */
    const PASSWORD = 'clave-del-test-actualizacion-9f3';

    /** Texto que contesta el sistema del cliente mientras se actualiza (contrato §2.4). */
    const MENSAJE = 'Estamos actualizando el sistema. Mientras tanto no se puede usar. Cuando termine vas a poder ingresar de nuevo desde esta pantalla.';

    /** @var \App\Models\User El dueño de la base de testing. */
    protected $dueno;

    /** @var \App\Models\User[] Cuatro empleados del dueño, indexados 1..4. */
    protected $empleados = [];

    /**
     * Ids de sesión de 40 caracteres de las computadoras de E1..E4.
     *
     * @var string[]
     */
    protected $sesiones = [
        1 => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1',
        2 => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2',
        3 => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa3',
        4 => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa4',
    ];

    /**
     * Escenario conocido: dueño 500, cuatro empleados (E4 es el que se fue hace 10 minutos), clave de admin,
     * cache limpia y reloj fijo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 11, 26, 10, 0, 0));

        $this->dueno = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        config([
            'app.USER_ID'                        => $this->dueno->id,
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
            'acceso_soporte.habilitado'          => true,
            'acceso_soporte.ttl_segundos'        => 120,
        ]);

        for ($n = 1; $n <= 4; $n++) {
            $this->empleados[$n] = User::factory()->create([
                'owner_id'         => $this->dueno->id,
                'name'             => 'Empleado ' . $n,
                'doc_number'       => '7700003' . $n,
                'password'         => Hash::make(self::PASSWORD),
                'admin_access'     => false,
                'activity_minutes' => 60,
            ]);
        }

        Cache::flush();

        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');
    }

    /**
     * Devuelve el reloj a la realidad para no contaminar al test siguiente.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
     * La computadora de E{n} (usuario y sesión fijos del fixture).
     *
     * @param int $n Número de empleado, 1..4.
     * @return $this
     */
    protected function como_empleado($n)
    {
        return $this->computadora($this->empleados[$n], $this->sesiones[$n]);
    }

    /**
     * Deja una fila de presencia: la computadora de E{n} fue vista hace `$minutos` minutos.
     *
     * @param int      $n       Número de empleado, 1..4.
     * @param int|float $minutos Hace cuántos minutos se la vio.
     * @return void
     */
    protected function presencia($n, $minutos)
    {
        DB::table('actualizacion_dispositivos')->insert([
            'owner_id'   => $this->dueno->id,
            'user_id'    => $this->empleados[$n]->id,
            'sesion'     => hash('sha256', $this->sesiones[$n]),
            'visto_at'   => Carbon::now()->subSeconds((int) round($minutos * 60)),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * La fila de presencia de la computadora de E{n}, o null.
     *
     * @param int $n Número de empleado, 1..4.
     * @return object|null
     */
    protected function fila_de_presencia($n)
    {
        return DB::table('actualizacion_dispositivos')
            ->where('owner_id', $this->dueno->id)
            ->where('sesion', hash('sha256', $this->sesiones[$n]))
            ->first();
    }

    /**
     * Los headers del admin con la clave de la instancia.
     *
     * @return array
     */
    protected function admin()
    {
        return ['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN];
    }

    /**
     * La fila de estado del dueño, o null.
     *
     * @return object|null
     */
    protected function fila_de_estado()
    {
        return DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->first();
    }

    /**
     * Escribe la fila de estado del dueño directamente en la tabla (contrato §4.2) y vacía la cache.
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
     * Escenario base de CA5 a CA8: E1, E2 y E3 vistos hace 1 minuto, E4 hace 10 (ya no cuenta). Pide el OK como
     * el admin y devuelve la respuesta del pedido.
     *
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_ok_con_tres_conectados()
    {
        $this->presencia(1, 1);
        $this->presencia(2, 1);
        $this->presencia(3, 1);
        $this->presencia(4, 10);

        return $this->withHeaders($this->admin())->postJson(self::URL_ADMIN . '/pedido', [
            'upgrade_id' => 77,
            'version'    => '4.3.90',
        ]);
    }

    /**
     * CA5: el pedido cuenta sólo las computadoras vistas en los últimos 3 minutos (E1..E3; E4 hace 10 no; una
     * sesión maestra nunca se registra), arranca `pidiendo_ok` con 600 segundos para responder, y es
     * idempotente: un segundo POST devuelve el mismo estado sin crear otro pedido.
     *
     * @return void
     */
    public function test_ca5_presencia_y_pedido()
    {
        $respuesta = $this->pedir_ok_con_tres_conectados();

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('ok', true);
        $respuesta->assertJsonPath('owner_id', $this->dueno->id);
        $respuesta->assertJsonPath('estado', 'pidiendo_ok');
        $respuesta->assertJsonPath('upgrade_id', 77);
        $respuesta->assertJsonPath('version', '4.3.90');
        $respuesta->assertJsonPath('pedido.total', 3);
        $respuesta->assertJsonPath('pedido.aceptaron', 0);
        $respuesta->assertJsonPath('pedido.faltan', 3);
        $respuesta->assertJsonPath('pedido.segundos_para_responder', 600);
        $respuesta->assertJsonPath('pedido.rechazado_por', null);
        $this->assertEqualsCanonicalizing(
            ['Empleado 1', 'Empleado 2', 'Empleado 3'],
            $respuesta->json('pedido.pendientes')
        );

        // Los tres participantes quedan `pendiente`; E4 (visto hace 10 minutos) ni entra.
        foreach ([1, 2, 3] as $n) {
            $this->assertSame('pendiente', $this->fila_de_presencia($n)->respuesta, 'E' . $n . ' tenía que quedar pendiente.');
        }
        $this->assertNull($this->fila_de_presencia(4)->respuesta);

        $uuid = $this->fila_de_estado()->pedido_uuid;
        $this->assertNotEmpty($uuid);

        // Idempotente: mismo estado, mismo pedido, mismo total.
        $segunda = $this->withHeaders($this->admin())->postJson(self::URL_ADMIN . '/pedido', [
            'minutos_para_responder' => 5,
        ]);

        $segunda->assertStatus(200);
        $segunda->assertJsonPath('estado', 'pidiendo_ok');
        $segunda->assertJsonPath('pedido.total', 3);
        $segunda->assertJsonPath('pedido.segundos_para_responder', 600);
        $this->assertSame($uuid, $this->fila_de_estado()->pedido_uuid, 'Un segundo POST no puede crear otro pedido.');
    }

    /**
     * CA6: cada computadora que acepta baja el "faltan" y sigue trabajando; cuando aceptan las tres el estado
     * pasa a `actualizando` por 30 minutos, se cierran los candados y las sesiones reciben la marca.
     *
     * @return void
     */
    public function test_ca6_aceptar_y_seguir_trabajando()
    {
        $this->pedir_ok_con_tres_conectados()->assertStatus(200);

        // E1 acepta: 200, faltan 2, y su sesión sigue andando.
        $this->como_empleado(1);
        $uno = $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'acepto']);
        $uno->assertStatus(200);
        $uno->assertJsonPath('estado', 'pidiendo_ok');
        $uno->assertJsonPath('mi_respuesta', 'acepto');
        $uno->assertJsonPath('total', 3);
        $uno->assertJsonPath('aceptaron', 1);
        $uno->assertJsonPath('faltan', 2);

        $this->getJson('/api/user')->assertStatus(200);

        // E2 acepta: faltan 1.
        $this->como_empleado(2);
        $dos = $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'acepto']);
        $dos->assertStatus(200);
        $dos->assertJsonPath('estado', 'pidiendo_ok');
        $dos->assertJsonPath('faltan', 1);

        // E3 acepta: era la última. Pasa a `actualizando` por 30 minutos.
        $this->como_empleado(3);
        $tres = $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'acepto']);
        $tres->assertStatus(200);
        $tres->assertJsonPath('estado', 'actualizando');

        $fila = $this->fila_de_estado();
        $this->assertSame('actualizando', $fila->estado);
        $this->assertTrue(
            Carbon::parse($fila->vence_at)->equalTo(Carbon::now()->addMinutes(30)),
            'Tras el OK de todos, vence_at tiene que ser now()+30 min; es ' . $fila->vence_at
        );

        // Los candados se liberaron: nadie queda "en otro dispositivo" cuando termine la actualización.
        foreach ([1, 2, 3] as $n) {
            $this->assertNull($this->empleados[$n]->fresh()->session_id, 'E' . $n . ' conserva el candado.');
        }

        // E1, que había seguido trabajando, recibe ahora la marca.
        $this->como_empleado(1);
        $this->assert_marca($this->getJson('/api/user'));
    }

    /**
     * CA7: "Ahora no" cancela el pedido (`rechazado`), el admin ve quién fue, el resto sigue trabajando y el
     * admin puede soltarlo con DELETE. DELETE sobre un `actualizando` es 409 `ya_actualizando`.
     *
     * @return void
     */
    public function test_ca7_ahora_no()
    {
        $this->pedir_ok_con_tres_conectados()->assertStatus(200);

        $this->como_empleado(2);
        $respuesta = $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'ahora_no']);
        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'rechazado');

        $admin = $this->withHeaders($this->admin())->getJson(self::URL_ADMIN);
        $admin->assertStatus(200);
        $admin->assertJsonPath('estado', 'rechazado');
        $admin->assertJsonPath('pedido.rechazado_por', 'Empleado 2');

        // El resto sigue usando el sistema.
        $this->como_empleado(1);
        $this->getJson('/api/user')->assertStatus(200);

        // El admin cancela el pedido: queda libre.
        $liberado = $this->withHeaders($this->admin())->deleteJson(self::URL_ADMIN . '/pedido');
        $liberado->assertStatus(200);
        $liberado->assertJsonPath('estado', 'libre');

        // Con el sistema ya actualizando, el DELETE del pedido no lo suelta: eso es PUT estado=libre.
        $this->withHeaders($this->admin())->putJson(self::URL_ADMIN, ['estado' => 'actualizando'])->assertStatus(200);
        $conflicto = $this->withHeaders($this->admin())->deleteJson(self::URL_ADMIN . '/pedido');
        $conflicto->assertStatus(409);
        $conflicto->assertJsonPath('error', 'ya_actualizando');
    }

    /**
     * CA8: las computadoras que no contestan en 10 minutos quedan `expulsado` y el estado pasa solo a
     * `actualizando` (evaluación perezosa: la dispara la lectura del admin). Su próxima request recibe la marca.
     *
     * @return void
     */
    public function test_ca8_diez_minutos()
    {
        $this->pedir_ok_con_tres_conectados()->assertStatus(200);

        $this->como_empleado(1);
        $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'acepto'])->assertStatus(200);

        // Pasan 10 minutos y 1 segundo: E2 y E3 no contestaron.
        Carbon::setTestNow(Carbon::now()->addMinutes(10)->addSecond());

        $admin = $this->withHeaders($this->admin())->getJson(self::URL_ADMIN);
        $admin->assertStatus(200);
        $admin->assertJsonPath('estado', 'actualizando');

        $this->assertSame('acepto', $this->fila_de_presencia(1)->respuesta);
        $this->assertSame('expulsado', $this->fila_de_presencia(2)->respuesta);
        $this->assertSame('expulsado', $this->fila_de_presencia(3)->respuesta);
        $this->assertTrue(
            Carbon::parse($this->fila_de_estado()->vence_at)->equalTo(Carbon::now()->addMinutes(30)),
            'La transición automática pone vence_at = now()+30 min.'
        );

        $this->como_empleado(2);
        $this->assert_marca($this->getJson('/api/user'));
    }

    /**
     * CA9: sin ninguna computadora conectada el pedido no espera a nadie: pasa de una a `actualizando`, con
     * total 0.
     *
     * @return void
     */
    public function test_ca9_nadie_conectado()
    {
        // Sólo hay presencia vieja (hace 10 minutos): no cuenta.
        $this->presencia(4, 10);

        $respuesta = $this->withHeaders($this->admin())->postJson(self::URL_ADMIN . '/pedido', ['upgrade_id' => 77]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'actualizando');
        $respuesta->assertJsonPath('pedido.total', 0);
        $this->assertSame('actualizando', $this->fila_de_estado()->estado);
    }

    /**
     * CA10: alguien que entra durante un pedido abierto se suma como `pendiente` con el mismo vencimiento
     * global (ve el mismo modal), y repetir el polling no lo cuenta dos veces.
     *
     * @return void
     */
    public function test_ca10_llega_tarde()
    {
        $this->presencia(1, 1);
        $this->presencia(2, 1);

        $this->withHeaders($this->admin())->postJson(self::URL_ADMIN . '/pedido')
            ->assertStatus(200)
            ->assertJsonPath('pedido.total', 2);

        // E3 hace su primer GET estado con el pedido ya abierto.
        $this->como_empleado(3);
        $primero = $this->getJson(self::URL_ESTADO);
        $primero->assertStatus(200);
        $primero->assertJsonPath('estado', 'pidiendo_ok');
        $primero->assertJsonPath('mi_respuesta', 'pendiente');
        $primero->assertJsonPath('total', 3);
        $primero->assertJsonPath('segundos_para_responder', 600);

        $segundo = $this->getJson(self::URL_ESTADO);
        $segundo->assertJsonPath('total', 3);

        $this->assertSame('pendiente', $this->fila_de_presencia(3)->respuesta);
    }

    /**
     * CA11: el GET público (sin sesión, lo mira la pantalla de login) contesta el estado del dueño de la
     * instancia: `libre` y `pidiendo_ok` en 200, `actualizando` con la marca 503.
     *
     * @return void
     */
    public function test_ca11_publico()
    {
        // Sin fila: libre.
        $this->getJson(self::URL_PUBLICO)->assertStatus(200)->assertJsonPath('estado', 'libre');

        $this->fijar_estado('libre');
        $this->getJson(self::URL_PUBLICO)->assertStatus(200)->assertJsonPath('estado', 'libre');

        $this->fijar_estado('pidiendo_ok', Carbon::now()->addMinutes(30));
        $this->getJson(self::URL_PUBLICO)->assertStatus(200)->assertJsonPath('estado', 'pidiendo_ok');

        $this->fijar_estado('actualizando', Carbon::now()->addMinutes(60));
        $this->assert_marca($this->getJson(self::URL_PUBLICO));

        // Vencido: vuelve a ser libre.
        $this->fijar_estado('actualizando', Carbon::now()->subSecond());
        $this->getJson(self::URL_PUBLICO)->assertStatus(200)->assertJsonPath('estado', 'libre');
    }

    /**
     * El GET estado de una computadora común registra su presencia (una fila con el sha256 de la sesión, nunca
     * el id) y contesta el cuerpo del contrato en `libre`.
     *
     * @return void
     */
    public function test_el_estado_registra_la_presencia_con_el_hash_de_la_sesion()
    {
        $this->como_empleado(1);

        $respuesta = $this->getJson(self::URL_ESTADO);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'libre');
        $respuesta->assertJsonPath('mi_respuesta', null);
        $respuesta->assertJsonPath('total', 0);

        $fila = $this->fila_de_presencia(1);
        $this->assertNotNull($fila, 'El GET estado tenía que registrar la presencia de la computadora.');
        $this->assertSame($this->empleados[1]->id, (int) $fila->user_id);
        $this->assertSame($this->dueno->id, (int) $fila->owner_id);
        $this->assertTrue(Carbon::parse($fila->visto_at)->equalTo(Carbon::now()));
        $this->assertSame(
            0,
            DB::table('actualizacion_dispositivos')->where('sesion', $this->sesiones[1])->count(),
            'Nunca se guarda el id de sesión en claro.'
        );
    }

    /**
     * Las sesiones maestra y de soporte no se registran ni cuentan para el pedido.
     *
     * @return void
     */
    public function test_maestro_y_soporte_no_se_registran()
    {
        $this->computadora($this->dueno, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb2', [
            'master_login_bypass_user_last_activity' => true,
        ]);
        $this->getJson(self::URL_ESTADO)->assertStatus(200);

        $token = AccesoSoporteHelper::emitir($this->dueno, 'test', 'prueba', '10.0.0.9');
        $fila = AccesoSoporteToken::where('token_hash', hash('sha256', $token))->firstOrFail();

        $this->computadora($this->dueno, 'ccccccccccccccccccccccccccccccccccccccc3', [
            'acceso_soporte_token_id' => $fila->id,
        ]);
        $this->getJson(self::URL_ESTADO)->assertStatus(200);

        $this->assertSame(0, DB::table('actualizacion_dispositivos')->where('owner_id', $this->dueno->id)->count());
    }

    /**
     * `POST respuesta` sin un pedido abierto es 409 `sin_pedido`; con un valor que no es `acepto` ni `ahora_no`
     * es 422.
     *
     * @return void
     */
    public function test_respuesta_sin_pedido_o_invalida()
    {
        $this->como_empleado(1);

        $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'acepto'])
            ->assertStatus(409)
            ->assertJsonPath('error', 'sin_pedido');

        $this->presencia(1, 1);
        $this->withHeaders($this->admin())->postJson(self::URL_ADMIN . '/pedido')->assertStatus(200);

        $this->como_empleado(1);
        $this->postJson(self::URL_RESPUESTA, ['respuesta' => 'quizas'])->assertStatus(422);
        $this->postJson(self::URL_RESPUESTA, [])->assertStatus(422);
    }
}
