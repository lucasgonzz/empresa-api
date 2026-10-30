<?php

namespace Tests\Feature\AccesoSoporte;

use App\Http\Controllers\Helpers\asistente_ia\EsquemaDeDatosIaHelper;
use App\Models\AccesoSoporteToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * La EMISIÓN del acceso de soporte (contrato C1): `POST api/admin-sync/acceso-soporte`, que pide el admin con la
 * clave compartida `X-Admin-Api-Key` (misión acceso-soporte-firmado, 30/10/2026).
 *
 * Lo que este archivo sostiene:
 *   - 🔴 La FIRMA es la clave: sin ella, con una mala o en una instancia sin clave cargada → 401 y CERO filas.
 *     (La ruta es `admin.api.key:siempre`: con el `admin.api.key` a secas, una instancia sin clave la dejaría
 *     pasar libre y cualquiera podría pedir un acceso.)
 *   - 🔴 A la base va el sha256, nunca el token en claro; y el claro no aparece en ningún log.
 *   - Los códigos y la forma de error del contrato: 403 `acceso_soporte_desactivado`, 409 `dueno_no_resoluble`.
 *   - `solicitante` y `motivo` se sanean (sin saltos de línea, sin control, recortados, UTF-8 válido).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class EmisionDelAccesoDeSoporteTest extends AccesoSoporteTestCase
{
    /** @test */
    public function sin_header_es_401_y_no_se_crea_nada()
    {
        $respuesta = $this->postJson(self::URL_EMISION, []);

        $respuesta->assertStatus(401);
        $this->assertSame('{"error":"unauthorized"}', $respuesta->getContent());
        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /** @test */
    public function con_una_clave_mala_es_401_y_no_se_crea_nada()
    {
        $respuesta = $this->withHeaders($this->con_clave(self::CLAVE_MALA))->postJson(self::URL_EMISION, []);

        $respuesta->assertStatus(401);
        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /**
     * 🔴 El caso que justifica el `:siempre`: una instancia SIN `ADMIN_API_INBOUND_KEY` cargada (las demos, o un
     * cliente al que nunca se le cargó) no puede dejar pasar el pedido «porque no hay con qué comparar».
     *
     * @test
     */
    public function una_instancia_sin_clave_cargada_contesta_401_igual()
    {
        foreach ([null, '', '   '] as $clave_de_la_instancia) {

            config([
                'services.admin_api.api_key'         => $clave_de_la_instancia,
                'services.admin_api.require_api_key' => null,
            ]);

            // Sin header, y con un header cualquiera (no hay con qué compararlo): 401 las dos veces.
            $this->postJson(self::URL_EMISION, [])->assertStatus(401);
            $this->withHeaders($this->con_clave('cualquier-cosa'))->postJson(self::URL_EMISION, [])->assertStatus(401);
        }

        // Y con el flag apagado a propósito (que en el `admin.api.key` normal deja la puerta abierta) también.
        config([
            'services.admin_api.api_key'         => self::CLAVE,
            'services.admin_api.require_api_key' => false,
        ]);

        $this->postJson(self::URL_EMISION, [])->assertStatus(401);

        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /** @test */
    public function la_ruta_de_emision_va_con_admin_api_key_siempre_y_la_de_canje_con_throttle()
    {
        $emision = null;
        $canje = null;

        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if ($ruta->uri() === 'api/admin-sync/acceso-soporte') {
                $emision = $ruta;
            }
            if ($ruta->uri() === 'api/acceso-soporte/canjear') {
                $canje = $ruta;
            }
        }

        $this->assertNotNull($emision, 'Falta la ruta de emisión.');
        $this->assertNotNull($canje, 'Falta la ruta de canje.');

        $middleware_emision = app('router')->gatherRouteMiddleware($emision);

        $this->assertContains('App\Http\Middleware\AdminApiKey:siempre', $middleware_emision,
            'La emisión tiene que ir con `admin.api.key:siempre`: sin el `:siempre`, una instancia sin clave la deja pasar libre.');

        $this->assertContains('Illuminate\Routing\Middleware\ThrottleRequests:10,1', app('router')->gatherRouteMiddleware($canje));
        $this->assertSame(['POST'], array_values(array_diff($canje->methods(), ['HEAD'])));
        $this->assertSame(['POST'], array_values(array_diff($emision->methods(), ['HEAD'])));
    }

    /** @test */
    public function con_la_clave_correcta_emite_y_guarda_solo_el_hash()
    {
        $respuesta = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, [
            'solicitante' => 'codex',
            'motivo'      => 'Ticket 123: el listado no carga',
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJsonStructure(['ok', 'token', 'vence_en_segundos', 'usuario_id']);

        $this->assertTrue($respuesta->json('ok'));
        $this->assertSame(120, $respuesta->json('vence_en_segundos'));
        $this->assertSame(500, $respuesta->json('usuario_id'));

        $token = $respuesta->json('token');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}\z/', $token, 'El token son 64 caracteres alfanuméricos.');

        // El secreto no se cachea en ningún lado.
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));

        // La fila: el hash, el dueño, la auditoría del pedido y el vencimiento a ~120 s.
        $this->assertSame(1, $this->cantidad_de_tokens());
        $fila = AccesoSoporteToken::first();

        $this->assertSame(hash('sha256', $token), $fila->token_hash);
        $this->assertSame(500, $fila->owner_id);
        $this->assertSame('codex', $fila->solicitante);
        $this->assertSame('Ticket 123: el listado no carga', $fila->motivo);
        $this->assertSame('127.0.0.1', $fila->ip_solicitud);
        $this->assertNull($fila->usado_en);
        $this->assertNull($fila->ip_canje);

        $segundos = Carbon::now()->diffInSeconds($fila->vence_en, false);
        $this->assertGreaterThan(110, $segundos);
        $this->assertLessThanOrEqual(120, $segundos);
    }

    /**
     * 🔴 El claro no está en NINGUNA columna de NINGUNA fila de la tabla.
     *
     * @test
     */
    public function el_token_en_claro_no_esta_en_ninguna_columna_de_la_base()
    {
        $token = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, ['motivo' => 'x'])->json('token');

        $this->assertNotEmpty($token);

        $todo = json_encode(DB::table('acceso_soporte_tokens')->get());

        $this->assertStringNotContainsString($token, $todo);
        $this->assertStringContainsString(hash('sha256', $token), $todo);
    }

    /**
     * 🔴 El token en claro no se loguea: ni el nivel info de la emisión, ni el warning del 401, ni nada de lo
     * que loguee el pedido. El log vive más que el token y lo leen otras personas.
     *
     * @test
     */
    public function el_token_en_claro_no_aparece_en_ningun_log()
    {
        $token = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, ['motivo' => 'ver el log'])->json('token');

        // Y un canje también, que loguea su propio renglón.
        $this->postJson(self::URL_CANJE, ['t' => $token])->assertStatus(200);
        $this->postJson(self::URL_CANJE, ['t' => $token])->assertStatus(401);

        $texto = $this->logs_como_texto();

        $this->assertStringContainsString('Acceso de soporte: token emitido', $texto, 'Tiene que quedar rastro de la emisión.');
        $this->assertStringNotContainsString($token, $texto);
        $this->assertStringNotContainsString(substr($token, 0, 20), $texto, 'Tampoco un pedazo del token.');
        $this->assertStringNotContainsString(self::CLAVE, $texto, 'La clave del admin tampoco va al log.');
    }

    /**
     * 🔴 La auditoría de los accesos de soporte NO es un dato del negocio que el asistente del dueño pueda
     * consultar. `EsquemaDeDatosIaHelper` deriva como entidad toda tabla con columna `user_id` fuera de su
     * lista negra; por eso la columna se llama `owner_id` (mismo criterio que `ai_avisos_del_asistente`). Si
     * alguien la renombra a `user_id` sin sumar la tabla a TABLAS_EXCLUIDAS, este test se pone rojo.
     *
     * @test
     */
    public function la_tabla_de_auditoria_no_es_una_entidad_consultable_por_el_asistente()
    {
        EsquemaDeDatosIaHelper::olvidar();

        $this->assertFalse(EsquemaDeDatosIaHelper::existe('acceso_soporte_token'));
        $this->assertFalse(EsquemaDeDatosIaHelper::existe('acceso_soporte_tokens'));
        $this->assertArrayNotHasKey('acceso_soporte_token', EsquemaDeDatosIaHelper::entidades());
    }

    /** @test */
    public function sin_solicitante_ni_motivo_queda_claude_y_null()
    {
        $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, [])->assertStatus(200);

        $fila = AccesoSoporteToken::first();

        $this->assertSame('claude', $fila->solicitante);
        $this->assertNull($fila->motivo);
    }

    /** @test */
    public function solicitante_y_motivo_se_sanean()
    {
        $largo = str_repeat('á', 200);

        $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, [
            'solicitante' => "  claude\r\n\tFALSO: [ERROR] linea inventada\0  ",
            'motivo'      => "ver ticket\n[2026-10-30] local.ERROR: algo\u{202E}\u{200B}  raro",
        ])->assertStatus(200);

        $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, [
            'solicitante' => $largo,
            'motivo'      => $largo,
        ])->assertStatus(200);

        $filas = AccesoSoporteToken::orderBy('id')->get();

        // Sin saltos de línea, sin control, sin los invisibles de formato, con los espacios colapsados.
        $this->assertSame('claude FALSO: [ERROR] linea inventada', $filas[0]->solicitante);
        $this->assertSame('ver ticket [2026-10-30] local.ERROR: algo raro', $filas[0]->motivo);

        // Recortados al largo de la columna (en caracteres, no en bytes).
        $this->assertSame(60, mb_strlen($filas[1]->solicitante, 'UTF-8'));
        $this->assertSame(120, mb_strlen($filas[1]->motivo, 'UTF-8'));

        // Y en el log tampoco quedó un salto de línea metido por el solicitante o el motivo: se miran los
        // renglones de la emisión (el contexto se escribe tal cual, un `\n` ahí falsificaría una línea del log).
        $renglones_de_emision = 0;

        foreach ($this->logs as $linea) {
            if (strpos($linea['mensaje'], 'token emitido') !== false) {
                $renglones_de_emision++;
                $this->assertSame(0, preg_match('/[\r\n\0]/', (string) $linea['contexto']['solicitante']));
                $this->assertSame(0, preg_match('/[\r\n\0]/', (string) $linea['contexto']['motivo']));
            }
        }

        $this->assertSame(2, $renglones_de_emision);
    }

    /** @test */
    public function un_valor_que_no_es_texto_o_no_es_utf8_no_rompe_nada()
    {
        // Un array como solicitante no es un texto: vale el default. Lo mismo un motivo que es un objeto.
        $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, [
            'solicitante' => ['a', 'b'],
            'motivo'      => ['x' => 1],
        ])->assertStatus(200);

        // Bytes que no son UTF-8 (un formulario puede mandarlos): se reemplazan, no explota el INSERT.
        $this->withHeaders($this->con_clave())->post(self::URL_EMISION, [
            'solicitante' => "mal\xC3\x28",
            'motivo'      => "\xFF\xFE",
        ], ['Accept' => 'application/json'])->assertStatus(200);

        $filas = AccesoSoporteToken::orderBy('id')->get();

        $this->assertSame('claude', $filas[0]->solicitante);
        $this->assertNull($filas[0]->motivo);
        $this->assertTrue(mb_check_encoding($filas[1]->solicitante, 'UTF-8'));
        $this->assertStringStartsWith('mal', $filas[1]->solicitante);
    }

    /** @test */
    public function con_el_acceso_apagado_responde_403_con_su_code_y_no_crea_nada()
    {
        config(['acceso_soporte.habilitado' => false]);

        $respuesta = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, []);

        $respuesta->assertStatus(403);
        $this->assertSame('acceso_soporte_desactivado', $respuesta->json('code'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /** @test */
    public function sin_dueno_resoluble_responde_409_con_su_code_y_no_crea_nada()
    {
        // USER_ID apunta a un usuario que no existe: no hay dueño que decidir.
        config(['app.USER_ID' => 99999999]);

        $respuesta = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, []);

        $respuesta->assertStatus(409);
        $this->assertSame('dueno_no_resoluble', $respuesta->json('code'));
        $this->assertNotEmpty($respuesta->json('message'));
        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /**
     * El caso real de las bases compartidas: dos comercios y ningún USER_ID. NUNCA «el primero».
     *
     * @test
     */
    public function con_varios_duenos_y_sin_user_id_responde_409()
    {
        if (User::whereNull('owner_id')->count() < 2) {
            $this->markTestSkipped('La base de testing tiene un solo dueño: no se puede armar el caso de la base compartida.');
        }

        config(['app.USER_ID' => null]);

        $respuesta = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, []);

        $respuesta->assertStatus(409);
        $this->assertSame('dueno_no_resoluble', $respuesta->json('code'));
        $this->assertSame(0, $this->cantidad_de_tokens());
    }

    /** @test */
    public function el_ttl_configurado_viaja_en_la_respuesta_y_se_acota()
    {
        $casos = [
            // [valor del .env, segundos esperados]
            [300, 300],
            [5, 30],        // por debajo del piso → piso
            [99999, 600],   // por encima del techo → techo
            [0, 120],       // «no se entendió» → default
            [-4, 120],
        ];

        foreach ($casos as $caso) {
            config(['acceso_soporte.ttl_segundos' => $caso[0]]);

            $respuesta = $this->withHeaders($this->con_clave())->postJson(self::URL_EMISION, []);

            $respuesta->assertStatus(200);
            $this->assertSame($caso[1], $respuesta->json('vence_en_segundos'), 'TTL ' . $caso[0]);
        }
    }
}
