<?php

namespace Tests\Feature\AccesoSoporte;

use Carbon\Carbon;

/**
 * El acceso de soporte NO toca el candado de sesión única del dueño (misión acceso-soporte-firmado,
 * 30/10/2026): `users.session_id` / `users.last_activity`.
 *
 * EL RIESGO. El agente entra COMO el dueño. Si esa sesión tomara el candado, el dueño real vería «tu cuenta está
 * siendo utilizada en otro dispositivo» en su propia caja mientras el agente mira un problema; y si el logout del
 * agente lo soltara, otro dispositivo podría entrar encima de la sesión que el dueño tiene abierta. Es el mismo
 * motivo que ya eximió al login maestro y a la demo (`AuthController::debe_omitir_candado_de_actividad()`).
 *
 * 🔴 `activity_minutes` se fuerza a 60. El usuario 500 sembrado en las bases de testing lo tiene en 0 y con 0
 * minutos de ventana `ya_paso_el_tiempo()` da `true` SIEMPRE: el candado se considera vencido de entrada y
 * ningún test del candado prueba nada (es la causa de que
 * `IngresoDemoConCandadoTomadoTest::test_un_login_normal_sigue_rechazado_si_otra_sesion_tiene_el_candado` esté
 * rojo en `develop` limpio). Sin esto, el contraste con el login normal de acá abajo no podría ser rojo nunca.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class CandadoDeSesionUnicaConAccesoDeSoporteTest extends AccesoSoporteTestCase
{
    /** Valor de `session_id` con el que se simula que OTRA sesión (la caja del dueño) tiene el candado. */
    const CANDADO_DEL_DUENIO = 'candado-tomado-por-la-caja-del-duenio';

    /** Una fecha fija y vieja para `logout_at` / `login_at`, para ver si alguien la pisa. */
    const FECHA_VIEJA = '2026-10-01 10:00:00';

    /** @var string Lo que tenía `last_activity` cuando otra sesión tomó el candado. */
    protected $last_activity_original;

    /**
     * El dueño real tiene su caja abierta: candado vigente de OTRA sesión, ventana de 60 minutos y fechas de
     * login/logout conocidas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno->activity_minutes = 60;
        $this->dueno->session_id = self::CANDADO_DEL_DUENIO;
        $this->dueno->last_activity = Carbon::now();
        $this->dueno->login_at = Carbon::parse(self::FECHA_VIEJA);
        $this->dueno->logout_at = Carbon::parse(self::FECHA_VIEJA);
        $this->dueno->save();

        $this->last_activity_original = (string) $this->dueno->fresh()->last_activity;
    }

    /**
     * Afirma que el candado y las fechas del dueño quedaron exactamente como las dejó su caja.
     *
     * @param  string $contexto
     * @return void
     */
    protected function assert_el_candado_del_duenio_sigue_intacto($contexto)
    {
        $despues = $this->dueno->fresh();

        $this->assertSame(self::CANDADO_DEL_DUENIO, $despues->session_id, $contexto . ': el session_id del dueño cambió.');
        $this->assertSame($this->last_activity_original, (string) $despues->last_activity, $contexto . ': el last_activity del dueño cambió.');
        $this->assertSame(self::FECHA_VIEJA, (string) $despues->login_at, $contexto . ': el login_at del dueño cambió.');
        $this->assertSame(self::FECHA_VIEJA, (string) $despues->logout_at, $contexto . ': el logout_at del dueño cambió.');
    }

    /**
     * 🔴 El contraste que da sentido a todo lo demás: con este escenario, un login NORMAL (sin marcador) es
     * rechazado con 403. Si esto no fuera rojo, los tests de abajo no probarían nada.
     *
     * @test
     */
    public function un_login_normal_con_el_candado_tomado_por_otra_sesion_sigue_rechazado()
    {
        $respuesta = $this->actingAs($this->dueno, 'web')->getJson('/api/user');

        $respuesta->assertStatus(403);
        $this->assertNull($respuesta->json('user'));
        $this->assertSame(self::CANDADO_DEL_DUENIO, $this->dueno->fresh()->session_id);
    }

    /** @test */
    public function la_sesion_de_soporte_entra_aunque_otra_sesion_tenga_el_candado()
    {
        list(, $fila) = $this->emitir_token();

        $respuesta = $this->actingAs($this->dueno, 'web')
            ->withSession(['acceso_soporte_token_id' => $fila->id, 'skip_offline_articles_sync' => true])
            ->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertSame(500, $respuesta->json('user.id'));
        $this->assertTrue($respuesta->json('user.es_sesion_soporte'));
        $this->assertTrue($respuesta->json('user.skip_offline_articles_sync'));
        $this->assertFalse((bool) $respuesta->json('user.es_acceso_maestro'), 'La sesión de soporte NO es acceso maestro.');
        $this->assertFalse((bool) $respuesta->json('user.es_sesion_demo'));
    }

    /**
     * 🔴 No le roba el candado a la caja del dueño ni se lo refresca (alargaría un candado ajeno sin que nadie
     * lo pida). Se pide varias veces: el refresco del `last_activity` ocurriría en el segundo pedido.
     *
     * @test
     */
    public function la_sesion_de_soporte_no_roba_ni_refresca_el_candado()
    {
        list(, $fila) = $this->emitir_token();

        $this->actingAs($this->dueno, 'web')->withSession(['acceso_soporte_token_id' => $fila->id]);

        $this->getJson('/api/user')->assertStatus(200);
        $this->getJson('/api/user')->assertStatus(200);

        $this->assert_el_candado_del_duenio_sigue_intacto('GET /api/user con soporte');
    }

    /**
     * De punta a punta: el CANJE tampoco toca el candado ni `login_at` (no pasa por `procesar_login()` ni por
     * `checkUserLastActivity()`).
     *
     * @test
     */
    public function el_canje_no_toca_el_candado_ni_el_login_at_del_duenio()
    {
        list($token, ) = $this->emitir_token();

        $this->postJson(self::URL_CANJE, ['t' => $token])->assertStatus(200);

        $this->assert_el_candado_del_duenio_sigue_intacto('canje');
    }

    /**
     * 🔴 El logout de la sesión de soporte NO suelta el candado del dueño ni le marca `logout_at`: son de su
     * caja, no de la sesión del agente.
     *
     * @test
     */
    public function el_logout_de_una_sesion_de_soporte_no_libera_el_candado()
    {
        list(, $fila) = $this->emitir_token();

        $this->actingAs($this->dueno, 'web')
            ->withSession(['acceso_soporte_token_id' => $fila->id])
            ->post('/logout')
            ->assertStatus(200);

        $this->assert_el_candado_del_duenio_sigue_intacto('logout de soporte');
    }

    /**
     * El contraste del logout: SIN el marcador, el mismo logout sí libera el candado y marca `logout_at`. Si
     * esto no fuera así, el test de arriba pasaría aunque el logout de soporte estuviera roto.
     *
     * @test
     */
    public function el_logout_de_un_usuario_normal_si_libera_el_candado()
    {
        $this->actingAs($this->dueno, 'web')->post('/logout')->assertStatus(200);

        $despues = $this->dueno->fresh();

        $this->assertNull($despues->session_id);
        $this->assertNull($despues->last_activity);
        $this->assertNotSame(self::FECHA_VIEJA, (string) $despues->logout_at);
    }

    /**
     * Crear el token de cambio de versión desde una sesión de soporte tampoco suelta el candado ni marca
     * `logout_at` (también los soltaba). El token se crea igual y la sesión de soporte se cierra.
     *
     * @test
     */
    public function el_cambio_de_version_desde_una_sesion_de_soporte_no_libera_el_candado()
    {
        list(, $fila) = $this->emitir_token();

        $respuesta = $this->actingAs($this->dueno, 'web')
            ->withSession(['acceso_soporte_token_id' => $fila->id])
            ->postJson('/version-session-token');

        $respuesta->assertStatus(200);
        $this->assertNotEmpty($respuesta->json('token'));

        $this->assert_el_candado_del_duenio_sigue_intacto('version-session-token con soporte');
    }

    /**
     * Con el candado LIBRE, la sesión de soporte lo deja libre: entrar a mirar no «ocupa» al dueño, que después
     * entra como siempre.
     *
     * @test
     */
    public function con_el_candado_libre_la_sesion_de_soporte_lo_deja_libre()
    {
        $this->dueno->session_id = null;
        $this->dueno->last_activity = null;
        $this->dueno->save();

        list(, $fila) = $this->emitir_token();

        $this->actingAs($this->dueno, 'web')->withSession(['acceso_soporte_token_id' => $fila->id])
            ->getJson('/api/user')->assertStatus(200);

        $this->assertNull($this->dueno->fresh()->session_id, 'La sesión de soporte no tomó el candado libre.');
        $this->assertNull($this->dueno->fresh()->last_activity);
    }

    /**
     * El otro lado: con el candado libre, el dueño real (sin marcador) entra y lo toma, como siempre. La
     * exención es por el marcador y no se filtró al camino normal.
     *
     * @test
     */
    public function con_el_candado_libre_el_duenio_real_entra_y_lo_toma_como_siempre()
    {
        $this->dueno->session_id = null;
        $this->dueno->last_activity = null;
        $this->dueno->save();

        $this->actingAs($this->dueno, 'web')->getJson('/api/user')->assertStatus(200);

        $this->assertNotNull($this->dueno->fresh()->session_id, 'El dueño real tomó el candado normalmente.');
        $this->assertNotNull($this->dueno->fresh()->last_activity);
    }
}
