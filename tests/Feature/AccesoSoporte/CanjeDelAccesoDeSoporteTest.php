<?php

namespace Tests\Feature\AccesoSoporte;

use App\Http\Controllers\Helpers\AccesoSoporteHelper;
use App\Models\AccesoSoporteToken;
use App\Models\DemoIngresoToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El CANJE del acceso de soporte (contrato C2): `POST api/acceso-soporte/canjear`, pública, que el navegador del
 * agente llama con el token de un solo uso (misión acceso-soporte-firmado, 30/10/2026).
 *
 * Lo que este archivo sostiene:
 *   - El token vale UNA vez: el segundo canje, un token vencido, uno mal formado, uno inexistente, uno de un
 *     usuario que ya no es dueño o el acceso apagado → siempre el MISMO 401 `{"error":"token_invalido"}`.
 *   - 🔴 El consumo es atómico: si otro pedido consumió el token entre que este lo leyó y lo marcó, este pierde.
 *   - Queda la auditoría completa en la fila (cuándo, desde qué IP y navegador).
 *   - La sesión que queda es la del dueño con el marcador de soporte, SIN `es_acceso_maestro`.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class CanjeDelAccesoDeSoporteTest extends AccesoSoporteTestCase
{
    /**
     * Afirma que la respuesta es EL 401 del contrato: mismo código y mismo cuerpo, byte a byte, sin importar el
     * motivo. Si un motivo se distinguiera, quien prueba tokens aprendería algo.
     *
     * @param  \Illuminate\Testing\TestResponse $respuesta
     * @param  string                           $caso
     * @return void
     */
    protected function assert_es_el_401_generico($respuesta, $caso)
    {
        $this->assertSame(401, $respuesta->getStatusCode(), $caso);
        $this->assertSame(self::CUERPO_401, $respuesta->getContent(), $caso);
    }

    /** @test */
    public function un_canje_valido_inicia_la_sesion_del_duenio_y_deja_el_marcador()
    {
        list($token, $fila) = $this->emitir_token();

        $respuesta = $this->withHeader('User-Agent', 'Mozilla/5.0 (pruebas) Playwright')
            ->postJson(self::URL_CANJE, ['t' => $token]);

        $respuesta->assertStatus(200);
        $this->assertSame('{"ok":true}', $respuesta->getContent());

        $this->assertAuthenticatedAs($this->dueno, 'web');
        $respuesta->assertSessionHas('acceso_soporte_token_id', $fila->id);
        $respuesta->assertSessionHas('skip_offline_articles_sync', true);

        // 🔴 NO es el login maestro: sin la clave de sesión del maestro, no hay `es_acceso_maestro`.
        $respuesta->assertSessionMissing('master_login_bypass_user_last_activity');
    }

    /** @test */
    public function la_auditoria_de_la_fila_queda_completa()
    {
        list($token, $fila) = $this->emitir_token('codex', 'Ticket 77');

        $this->assertNull($fila->usado_en);
        $this->assertNull($fila->ip_canje);
        $this->assertNull($fila->user_agent_canje);

        $antes = Carbon::now()->subSecond();

        $this->withHeader('User-Agent', 'Mozilla/5.0 (pruebas) Playwright')
            ->postJson(self::URL_CANJE, ['t' => $token])
            ->assertStatus(200);

        $fila = $fila->fresh();

        $this->assertNotNull($fila->usado_en);
        $this->assertTrue($fila->usado_en->gte($antes));
        $this->assertSame('127.0.0.1', $fila->ip_canje);
        $this->assertSame('Mozilla/5.0 (pruebas) Playwright', $fila->user_agent_canje);

        // Y la fila de la emisión sigue ahí, intacta: la auditoría no se pierde al usarse.
        $this->assertSame('codex', $fila->solicitante);
        $this->assertSame('Ticket 77', $fila->motivo);
        $this->assertSame('10.0.0.9', $fila->ip_solicitud);
        $this->assertSame(hash('sha256', $token), $fila->token_hash);
    }

    /** @test */
    public function el_user_agent_se_recorta_a_la_columna_y_no_puede_meter_saltos_de_linea()
    {
        list($token, $fila) = $this->emitir_token();

        $this->withHeader('User-Agent', str_repeat('Navegador/1.0 ', 40))
            ->postJson(self::URL_CANJE, ['t' => $token])
            ->assertStatus(200);

        $guardado = $fila->fresh()->user_agent_canje;

        $this->assertLessThanOrEqual(150, mb_strlen($guardado, 'UTF-8'));
        $this->assertStringStartsWith('Navegador/1.0', $guardado);
    }

    /** @test */
    public function acepta_el_token_tambien_como_token_y_tolera_espacios_alrededor()
    {
        list($token, ) = $this->emitir_token();

        $this->postJson(self::URL_CANJE, ['token' => '  ' . $token . "\n"])->assertStatus(200);
    }

    /** @test */
    public function el_segundo_canje_del_mismo_token_es_401()
    {
        list($token, ) = $this->emitir_token();

        $this->postJson(self::URL_CANJE, ['t' => $token])->assertStatus(200);

        $segundo = $this->postJson(self::URL_CANJE, ['t' => $token]);

        $this->assert_es_el_401_generico($segundo, 'segundo canje');
    }

    /** @test */
    public function un_token_vencido_es_401_y_no_se_marca_como_usado()
    {
        list($token, $fila) = $this->emitir_token();

        DB::table('acceso_soporte_tokens')->where('id', $fila->id)->update(['vence_en' => Carbon::now()->subSecond()]);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $token]), 'vencido');

        $this->assertNull($fila->fresh()->usado_en, 'Un token vencido no se marca como usado.');
    }

    /** @test */
    public function una_fila_sin_vencimiento_no_se_puede_canjear_nunca()
    {
        list($token, $fila) = $this->emitir_token();

        DB::table('acceso_soporte_tokens')->where('id', $fila->id)->update(['vence_en' => null]);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $token]), 'sin vencimiento');
    }

    /** @test */
    public function un_token_inexistente_es_401()
    {
        $inventado = str_repeat('a', 64);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $inventado]), 'inexistente');
    }

    /**
     * 🔴 El hash NO se acepta como token: si alguien lee la base, `token_hash` no le sirve para entrar.
     *
     * @test
     */
    public function el_hash_guardado_no_sirve_como_token()
    {
        list(, $fila) = $this->emitir_token();

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $fila->token_hash]), 'hash como token');
        $this->assertNull($fila->fresh()->usado_en);
    }

    /** @test */
    public function los_tokens_mal_formados_son_401_y_ni_siquiera_consultan_la_tabla()
    {
        list($token, ) = $this->emitir_token();

        $consultas_a_la_tabla = 0;

        DB::listen(function ($consulta) use (&$consultas_a_la_tabla) {
            if (strpos($consulta->sql, 'acceso_soporte_tokens') !== false) {
                $consultas_a_la_tabla++;
            }
        });

        $mal_formados = [
            'vacío'                  => '',
            'corto'                  => 'abc',
            '63 caracteres'          => substr($token, 0, 63),
            '65 caracteres'          => $token . 'a',
            'con un símbolo'         => substr($token, 0, 63) . '-',
            'con un espacio adentro' => substr($token, 0, 10) . ' ' . substr($token, 11),
            'con un salto adentro'   => substr($token, 0, 10) . "\n" . substr($token, 11),
            'array'                  => ['x' => $token],
        ];

        foreach ($mal_formados as $caso => $valor) {
            $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $valor]), $caso);
        }

        // Sin cuerpo en absoluto.
        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, []), 'sin cuerpo');

        $this->assertSame(0, $consultas_a_la_tabla, 'Un token mal formado se descarta antes de tocar la base.');
    }

    /**
     * El formato se ancla con `\z`: «<64 caracteres>\n» NO es un token válido para el helper (con `$` pasaría).
     * El controlador hace `trim()`, el helper es estricto.
     *
     * @test
     */
    public function el_helper_es_estricto_con_el_salto_de_linea_final()
    {
        list($token, ) = $this->emitir_token();

        $this->assertTrue(AccesoSoporteHelper::formato_valido($token));
        $this->assertFalse(AccesoSoporteHelper::formato_valido($token . "\n"));
        $this->assertFalse(AccesoSoporteHelper::formato_valido(null));
        $this->assertFalse(AccesoSoporteHelper::formato_valido(12345));

        $this->assertNull(AccesoSoporteHelper::canjear($token . "\n", '127.0.0.1', 'ua'));
        $this->assertNull(AccesoSoporteToken::first()->usado_en, 'El canje rechazado por formato no consume nada.');
    }

    /** @test */
    public function con_el_acceso_apagado_el_canje_es_el_mismo_401_y_no_consume_el_token()
    {
        list($token, $fila) = $this->emitir_token();

        config(['acceso_soporte.habilitado' => false]);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $token]), 'apagado');

        $this->assertNull($fila->fresh()->usado_en, 'Apagado no consume el token.');
        $this->assertGuest('web');
    }

    /** @test */
    public function si_el_usuario_dejo_de_ser_duenio_el_canje_es_401()
    {
        list($token, $fila) = $this->emitir_token();

        // Por SQL directo: sin disparar los observers de User. Todo dentro de la transacción del test.
        DB::table('users')->where('id', $this->dueno->id)->update(['owner_id' => 1]);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $token]), 'ya no es dueño');
        $this->assertNull($fila->fresh()->usado_en);
        $this->assertGuest('web');
    }

    /** @test */
    public function si_el_usuario_ya_no_existe_el_canje_es_401()
    {
        list($token, $fila) = $this->emitir_token();

        DB::table('acceso_soporte_tokens')->where('id', $fila->id)->update(['owner_id' => 99999999]);

        $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => $token]), 'usuario inexistente');
    }

    /** @test */
    public function todos_los_rechazos_son_byte_a_byte_el_mismo_cuerpo()
    {
        list($token_vencido, $fila) = $this->emitir_token();
        DB::table('acceso_soporte_tokens')->where('id', $fila->id)->update(['vence_en' => Carbon::now()->subMinute()]);

        list($token_usado, ) = $this->emitir_token();
        $this->postJson(self::URL_CANJE, ['t' => $token_usado])->assertStatus(200);

        $cuerpos = [];

        foreach ([$token_vencido, $token_usado, str_repeat('b', 64), 'corto', ''] as $valor) {
            $cuerpos[] = $this->postJson(self::URL_CANJE, ['t' => $valor])->getContent();
        }

        $this->assertSame([self::CUERPO_401], array_values(array_unique($cuerpos)));
    }

    /**
     * 🔴 LA CARRERA. Dos canjes del mismo token: gana uno solo.
     *
     * Se simula la peor ventana: este pedido ya LEYÓ la fila (libre, vigente) y justo antes de marcarla otro
     * pedido la consume. Con «buscar, ver que está libre y después marcar» los dos entrarían; con la condición
     * dentro del UPDATE (`usado_en IS NULL AND vence_en > ahora`) este afecta 0 filas y pierde.
     *
     * @test
     */
    public function si_otro_pedido_consume_el_token_entre_la_lectura_y_la_marca_este_pierde()
    {
        list($token, $fila) = $this->emitir_token();

        $competidor_actuo = false;

        DB::listen(function ($consulta) use (&$competidor_actuo, $fila) {
            // Apenas termina la primera lectura de la tabla, «otro pedido» la gana. La bandera se levanta ANTES
            // de escribir para que el UPDATE del competidor (que también dispara este listener) no recurra.
            if (!$competidor_actuo && stripos($consulta->sql, 'select') === 0 && strpos($consulta->sql, 'acceso_soporte_tokens') !== false) {
                $competidor_actuo = true;

                DB::table('acceso_soporte_tokens')->where('id', $fila->id)->whereNull('usado_en')->update([
                    'usado_en' => Carbon::now(),
                    'ip_canje' => '203.0.113.7',
                ]);
            }
        });

        $respuesta = $this->postJson(self::URL_CANJE, ['t' => $token]);

        $this->assertTrue($competidor_actuo, 'El test no simuló al competidor: no prueba nada.');
        $this->assert_es_el_401_generico($respuesta, 'perdió la carrera');
        $this->assertGuest('web');
        $this->assertSame('203.0.113.7', $fila->fresh()->ip_canje, 'La fila quedó con el canje del que ganó, no del que perdió.');
    }

    /** @test */
    public function si_el_token_vence_entre_la_lectura_y_la_marca_el_canje_pierde()
    {
        list($token, $fila) = $this->emitir_token();

        $vencio = false;

        DB::listen(function ($consulta) use (&$vencio, $fila) {
            if (!$vencio && stripos($consulta->sql, 'select') === 0 && strpos($consulta->sql, 'acceso_soporte_tokens') !== false) {
                $vencio = true;

                DB::table('acceso_soporte_tokens')->where('id', $fila->id)->update(['vence_en' => Carbon::now()->subSecond()]);
            }
        });

        $this->assertNull(AccesoSoporteHelper::canjear($token, '127.0.0.1', 'ua'));
        $this->assertTrue($vencio);
        $this->assertNull($fila->fresh()->usado_en);
    }

    /** @test */
    public function dos_canjes_seguidos_del_mismo_token_dejan_un_solo_ganador()
    {
        list($token, $fila) = $this->emitir_token();

        $primero = AccesoSoporteHelper::canjear($token, '198.51.100.1', 'primero');
        $segundo = AccesoSoporteHelper::canjear($token, '198.51.100.2', 'segundo');

        $this->assertNotNull($primero);
        $this->assertSame(500, $primero['user']->id);
        $this->assertSame($fila->id, $primero['token_id']);
        $this->assertNull($segundo);

        // La auditoría es la del primero: el segundo no pisó nada.
        $this->assertSame('198.51.100.1', $fila->fresh()->ip_canje);
        $this->assertSame('primero', $fila->fresh()->user_agent_canje);
    }

    /**
     * La sesión de un navegador que traía marcadores de maestro o de demo NO los arrastra al canje: `regenerate()`
     * conserva los datos de la sesión anterior y la de soporte no puede ser `es_acceso_maestro` ni `es_sesion_demo`.
     *
     * @test
     */
    public function el_canje_limpia_los_marcadores_de_maestro_y_de_demo_que_traiga_la_sesion()
    {
        list($token, ) = $this->emitir_token();

        // Un ingreso de demo REAL: el middleware DemoSessionVigente corta con 401 un marcador que no resuelve a
        // ninguna fila, y ese corte no es lo que se quiere probar acá.
        $ingreso_de_demo = DemoIngresoToken::create([
            'token_hash' => hash('sha256', 'zz-soporte-' . uniqid('', true)),
            'user_id'    => $this->dueno->id,
            'expires_at' => Carbon::now()->addHours(4),
            'revoked_at' => null,
        ]);

        $respuesta = $this->withSession([
            'master_login_bypass_user_last_activity' => true,
            'demo_ingreso_token_id'                  => $ingreso_de_demo->id,
        ])->postJson(self::URL_CANJE, ['t' => $token]);

        $respuesta->assertStatus(200);
        $respuesta->assertSessionMissing('master_login_bypass_user_last_activity');
        $respuesta->assertSessionMissing('demo_ingreso_token_id');
        $respuesta->assertSessionHas('acceso_soporte_token_id');
    }

    /** @test */
    public function el_canje_tiene_throttle_de_10_por_minuto()
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->assert_es_el_401_generico($this->postJson(self::URL_CANJE, ['t' => 'x']), 'intento ' . $i);
        }

        $this->postJson(self::URL_CANJE, ['t' => 'x'])->assertStatus(429);
    }

    /**
     * De punta a punta con la cookie: después del canje, el SIGUIENTE pedido (sin `actingAs`, solo con la cookie
     * de sesión que devolvió el canje) es el dueño, marcado como sesión de soporte, y NO es acceso maestro.
     *
     * @test
     */
    public function despues_del_canje_el_siguiente_pedido_con_la_cookie_es_el_duenio_en_modo_soporte()
    {
        list($token, ) = $this->emitir_token();

        $canje = $this->postJson(self::URL_CANJE, ['t' => $token]);
        $canje->assertStatus(200);

        $cookie_de_sesion = null;

        foreach ($canje->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $cookie_de_sesion = $cookie;
            }
        }

        $this->assertNotNull($cookie_de_sesion, 'El canje tiene que devolver la cookie de sesión.');

        // Un navegador nuevo pidiendo con esa cookie: sin el usuario cacheado en el guard del test.
        $this->app['auth']->forgetGuards();

        $respuesta = $this->withUnencryptedCookie($cookie_de_sesion->getName(), $cookie_de_sesion->getValue())
            ->getJson('/api/user');

        $respuesta->assertStatus(200);
        $this->assertSame(500, $respuesta->json('user.id'));
        $this->assertTrue($respuesta->json('user.es_sesion_soporte'));
        $this->assertTrue($respuesta->json('user.skip_offline_articles_sync'));
        $this->assertFalse((bool) $respuesta->json('user.es_acceso_maestro'));
        $this->assertFalse((bool) $respuesta->json('user.es_sesion_demo'));
    }
}
