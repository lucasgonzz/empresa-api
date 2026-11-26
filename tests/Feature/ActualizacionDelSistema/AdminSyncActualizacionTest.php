<?php

namespace Tests\Feature\ActualizacionDelSistema;

use App\Events\ActualizacionDelSistemaCambio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * CA4 y CA3 (lado admin) de la misión aviso-actualizacion-sistema (25/11/2026): los endpoints
 * `api/admin-sync/actualizacion-del-sistema` con los que admin-api prende y libera el estado de
 * actualización durante el pipeline (contrato §4.1, header `X-Admin-Api-Key`).
 *
 * Qué fija este archivo:
 *  - `PUT estado=actualizando`: vence en 120 min por defecto, libera el candado de sesión única (`session_id`
 *    y `last_activity` en NULL) del dueño y de todos sus empleados, y emite `ActualizacionDelSistemaCambio`
 *    por el canal público `actualizacion_del_sistema.{owner_id}`.
 *  - Sin clave (o con una equivocada) todas las rutas contestan 401 `unauthorized`.
 *  - `PUT estado=libre` suelta y deja volver a entrar.
 *  - La validación es contrato (422 `validacion` / `owner_no_encontrado`), hecha a mano en el controlador.
 *  - Un `actualizando` vencido se lee como `libre` (y queda guardado como `libre`).
 *
 * Motivo esperado del rojo ANTES de la implementación: las rutas no existen (404) y la tabla
 * `actualizaciones_del_sistema` tampoco.
 *
 * PHP 7.4 estricto: nada de la sintaxis de PHP 8 (ni match, ni operador nullsafe, ni funciones str_*_with, ni argumentos con nombre).
 */
class AdminSyncActualizacionTest extends TestCase
{
    use DatabaseTransactions;

    /** Clave de admin de la instancia en estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-de-actualizacion-8e1a';

    /** Ruta del contrato (§4.1). */
    const URL = '/api/admin-sync/actualizacion-del-sistema';

    /** Contraseña conocida de los empleados del fixture. */
    const PASSWORD = 'clave-del-test-actualizacion-9f3';

    /** @var \App\Models\User El dueño de la base de testing. */
    protected $dueno;

    /** @var \App\Models\User[] Tres empleados del dueño (E1, E2, E3). */
    protected $empleados = [];

    /**
     * Escenario conocido: dueño 500, tres empleados, clave de admin cargada y cache limpia. El reloj queda
     * fijo para poder afirmar `vence_at` al segundo.
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
        ]);

        for ($n = 1; $n <= 3; $n++) {
            $this->empleados[$n] = User::factory()->create([
                'owner_id'         => $this->dueno->id,
                'name'             => 'Empleado ' . $n,
                'doc_number'       => '7700002' . $n,
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
     * Los headers de un pedido del admin con la clave dada.
     *
     * @param string $valor
     * @return array
     */
    protected function con_clave($valor = self::CLAVE_DE_ADMIN)
    {
        return ['X-Admin-Api-Key' => $valor];
    }

    /**
     * CA4: `PUT estado=actualizando` prende el estado: 200 con `AvisoEstado`, `vence_at` a 120 minutos, el
     * candado de sesión única liberado para el dueño y sus empleados, y el evento emitido.
     *
     * @return void
     */
    public function test_prender_libera_candados_y_emite()
    {
        Event::fake([ActualizacionDelSistemaCambio::class]);

        // Todos tienen el candado tomado por alguna computadora.
        User::where('id', $this->dueno->id)->orWhere('owner_id', $this->dueno->id)->update([
            'session_id'    => 'candado-tomado-por-una-computadora',
            'last_activity' => Carbon::now(),
        ]);

        $respuesta = $this->withHeaders($this->con_clave())->putJson(self::URL, [
            'estado'     => 'actualizando',
            'upgrade_id' => 77,
            'version'    => '4.3.90',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('ok', true);
        $respuesta->assertJsonPath('owner_id', $this->dueno->id);
        $respuesta->assertJsonPath('estado', 'actualizando');
        $respuesta->assertJsonPath('upgrade_id', 77);
        $respuesta->assertJsonPath('version', '4.3.90');

        $esperado = Carbon::now()->addMinutes(120);
        $this->assertTrue(
            Carbon::parse($respuesta->json('vence_at'))->equalTo($esperado),
            'vence_at tiene que ser now()+120 min; vino ' . $respuesta->json('vence_at')
        );

        $fila = DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->first();
        $this->assertSame('actualizando', $fila->estado);
        $this->assertTrue(Carbon::parse($fila->vence_at)->equalTo($esperado));

        // Candados liberados: dueño y los tres empleados.
        $this->assertNull($this->dueno->fresh()->session_id);
        $this->assertNull($this->dueno->fresh()->last_activity);
        foreach ($this->empleados as $empleado) {
            $this->assertNull($empleado->fresh()->session_id, 'El empleado ' . $empleado->id . ' conserva el candado.');
            $this->assertNull($empleado->fresh()->last_activity);
        }

        // Evento por el canal público del dueño, con el payload del contrato §4.3.
        Event::assertDispatched(ActualizacionDelSistemaCambio::class, function ($evento) {
            $canales = $evento->broadcastOn();
            $payload = $evento->broadcastWith();

            return count($canales) === 1
                && $canales[0]->name === 'actualizacion_del_sistema.' . $this->dueno->id
                && $evento->broadcastAs() === 'ActualizacionDelSistemaCambio'
                && $payload['estado'] === 'actualizando'
                && array_key_exists('total', $payload)
                && array_key_exists('aceptaron', $payload)
                && array_key_exists('faltan', $payload)
                && array_key_exists('vence_respuesta_at', $payload)
                && array_key_exists('rechazado_por', $payload);
        });
    }

    /**
     * `vence_en_minutos` se respeta dentro de su rango (5..240).
     *
     * @return void
     */
    public function test_vence_en_minutos_se_respeta()
    {
        $respuesta = $this->withHeaders($this->con_clave())->putJson(self::URL, [
            'estado'          => 'actualizando',
            'vence_en_minutos' => 30,
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue(Carbon::parse($respuesta->json('vence_at'))->equalTo(Carbon::now()->addMinutes(30)));
    }

    /**
     * CA4: sin la clave del admin (o con una equivocada) las cuatro rutas contestan 401 `unauthorized` y no
     * tocan nada.
     *
     * @return void
     */
    public function test_sin_clave_401()
    {
        $this->getJson(self::URL)->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->putJson(self::URL, ['estado' => 'actualizando'])->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->postJson(self::URL . '/pedido')->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);
        $this->deleteJson(self::URL . '/pedido')->assertStatus(401)->assertExactJson(['error' => 'unauthorized']);

        $this->withHeaders($this->con_clave('una-clave-que-no-es'))
            ->putJson(self::URL, ['estado' => 'actualizando'])
            ->assertStatus(401);

        $this->assertSame(0, DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->count());
    }

    /**
     * CA4: `PUT estado=libre` suelta el estado y el login vuelve a funcionar.
     *
     * @return void
     */
    public function test_liberar()
    {
        $this->withHeaders($this->con_clave())->putJson(self::URL, ['estado' => 'actualizando'])->assertStatus(200);

        $bloqueado = $this->postJson('/login', [
            'doc_number' => $this->empleados[1]->doc_number,
            'password'   => self::PASSWORD,
        ]);
        $bloqueado->assertStatus(503);

        $respuesta = $this->withHeaders($this->con_clave())->putJson(self::URL, ['estado' => 'libre']);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'libre');

        $this->postJson('/login', [
            'doc_number' => $this->empleados[1]->doc_number,
            'password'   => self::PASSWORD,
        ])->assertStatus(200)->assertJsonPath('login', true);
    }

    /**
     * CA4: la validación a mano del controlador (contrato): `vence_en_minutos` fuera de 5..240, `estado`
     * inválido o ausente, `upgrade_id` no numérico y `version` de más de 40 caracteres son 422 `validacion`;
     * un `user_id` que no es dueño es 422 `owner_no_encontrado`. Nada de eso escribe.
     *
     * @return void
     */
    public function test_validacion()
    {
        $malos = [
            ['estado' => 'actualizando', 'vence_en_minutos' => 300],
            ['estado' => 'actualizando', 'vence_en_minutos' => 4],
            ['estado' => 'pidiendo_ok'],
            ['estado' => 'cualquiera'],
            [],
            ['estado' => 'actualizando', 'upgrade_id' => 'abc'],
            ['estado' => 'actualizando', 'version' => str_repeat('9', 41)],
        ];

        foreach ($malos as $cuerpo) {
            $respuesta = $this->withHeaders($this->con_clave())->putJson(self::URL, $cuerpo);
            $respuesta->assertStatus(422);
            $respuesta->assertJsonPath('error', 'validacion');
        }

        $sin_dueno = $this->withHeaders($this->con_clave())->putJson(self::URL, [
            'estado'  => 'actualizando',
            'user_id' => 99999999,
        ]);
        $sin_dueno->assertStatus(422);
        $sin_dueno->assertJsonPath('error', 'owner_no_encontrado');

        $this->assertSame(0, DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->count());
    }

    /**
     * CA3 (lado admin): un `actualizando` con `vence_at` hace un segundo se lee como `libre`, y la lectura lo
     * deja guardado como `libre`.
     *
     * @return void
     */
    public function test_vencido_se_lee_como_libre()
    {
        DB::table('actualizaciones_del_sistema')->insert([
            'owner_id'           => $this->dueno->id,
            'estado'             => 'actualizando',
            'actualizando_desde' => Carbon::now()->subHour(),
            'vence_at'           => Carbon::now()->subSecond(),
            'created_at'         => Carbon::now(),
            'updated_at'         => Carbon::now(),
        ]);

        $respuesta = $this->withHeaders($this->con_clave())->getJson(self::URL);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('estado', 'libre');
        $this->assertSame('libre', DB::table('actualizaciones_del_sistema')->where('owner_id', $this->dueno->id)->value('estado'));
    }

    /**
     * Sin fila de estado el dueño está `libre` (y el GET no escribe nada: es lectura).
     *
     * @return void
     */
    public function test_sin_fila_es_libre()
    {
        $respuesta = $this->withHeaders($this->con_clave())->getJson(self::URL);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('ok', true);
        $respuesta->assertJsonPath('estado', 'libre');
        $respuesta->assertJsonPath('pedido', null);
    }
}
