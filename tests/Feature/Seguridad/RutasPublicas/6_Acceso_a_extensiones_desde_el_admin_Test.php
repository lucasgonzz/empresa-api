<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Http\Middleware\RutaDeMantenimiento;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/**
 * El acceso al configurador de extensiones desde el admin (misión extensiones-desde-el-admin, 15/10/2026).
 *
 * DOS PIEZAS:
 *   - `POST api/admin-sync/extensiones/acceso` (ExtensionesAccesoController): emite un token de un solo uso.
 *     Va con `admin.api.key:siempre`: exige `X-Admin-Api-Key` AUNQUE la instancia no tenga clave cargada (el
 *     tri-estado de `admin.api.key` a secas dejaría pasar sin clave; acá se emite un acceso).
 *   - `GET /extensiones?acceso=<token>` (RutaDeMantenimiento, solo en `:demo`): lo canjea una vez, abre la
 *     sesión SOLO para el configurador y para el comercio de la instancia, y redirige sin `acceso`.
 *
 * Todo se prueba "como producción" (`como_produccion()`): en la suite la puerta de mantenimiento está abierta.
 * El reloj se congela al principio de cada test para que las cuentas de 120 s y 2 h no dependan del milisegundo.
 */
class Acceso_a_extensiones_desde_el_admin_Test extends RutasPublicasTestCase
{
    /** La clave de admin "de la instancia" para estos tests. */
    const CLAVE_DE_ADMIN = 'clave-de-admin-del-test-extensiones-3e8a1c9d7b5f';

    /** @var User El comercio de la instancia (el `USER_ID` de la config). Existe de verdad: el configurador 404ea si no. */
    protected $comercio;

    /** @var User Otro comercio de la misma base, que el acceso NO tiene que abrir. */
    protected $otro_comercio;

    /** Ruta de prueba: lleva `:demo` pero NO es del configurador (no está en la lista del acceso del admin). */
    const RUTA_DEMO_AJENA = '/_prueba-demo-que-no-es-del-configurador';

    /** Ruta de prueba con la guarda de todo mantenimiento.php (sin `:demo`). */
    const RUTA_CERRADA = '/_prueba-cerrada-de-mantenimiento';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->como_produccion();

        $this->comercio = User::create([
            'name'     => 'Comercio del acceso',
            'email'    => 'acceso-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->otro_comercio = User::create([
            'name'     => 'Otro comercio del acceso',
            'email'    => 'acceso-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        config([
            'services.admin_api.api_key'         => self::CLAVE_DE_ADMIN,
            'services.admin_api.require_api_key' => null,
            'app.USER_ID'                        => $this->comercio->id,
        ]);

        Route::middleware(['web', 'solo.mantenimiento:demo'])->get(self::RUTA_DEMO_AJENA, function () {
            return 'ADENTRO-DEMO-AJENA';
        })->name('prueba.demo.ajena');

        Route::middleware(['web', 'solo.mantenimiento'])->get(self::RUTA_CERRADA, function () {
            return 'ADENTRO-CERRADA';
        });

        $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Pide un token al admin-sync con la clave de la instancia.
     *
     * @return string El token (64 hex).
     */
    protected function emitir_un_acceso()
    {
        $respuesta = $this->withHeaders(['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN])
            ->postJson('/api/admin-sync/extensiones/acceso');

        $respuesta->assertStatus(200);

        return $respuesta->json('acceso');
    }

    /**
     * El timestamp hasta el que quedó vigente el acceso del admin en la sesión, o null.
     *
     * @return int|null
     */
    protected function acceso_hasta()
    {
        return session(RutaDeMantenimiento::SESION_ACCESO_EXTENSIONES_HASTA);
    }

    // ----------------------------------------------------------------------------------------------
    // Emitir
    // ----------------------------------------------------------------------------------------------

    /** @test */
    public function emitir_sin_el_header_da_401()
    {
        $this->postJson('/api/admin-sync/extensiones/acceso')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    /** @test */
    public function emitir_con_el_header_equivocado_da_401()
    {
        $this->withHeaders(['X-Admin-Api-Key' => 'otra-clave-que-no-es'])
            ->postJson('/api/admin-sync/extensiones/acceso')
            ->assertStatus(401)
            ->assertExactJson(['error' => 'unauthorized']);
    }

    /**
     * 🔴 EL PUNTO CRÍTICO: una instancia SIN clave de admin cargada, o con el flag apagado a propósito, no
     * emite acceso. Con `admin.api.key` a secas esas instancias dejan pasar sin header.
     *
     * @test
     */
    public function una_instancia_sin_clave_cargada_y_sin_header_da_401()
    {
        foreach ([null, false, true] as $flag) {

            foreach ([null, '', '   '] as $clave) {

                config(['services.admin_api.api_key' => $clave, 'services.admin_api.require_api_key' => $flag]);

                $this->postJson('/api/admin-sync/extensiones/acceso')
                    ->assertStatus(401)
                    ->assertExactJson(['error' => 'unauthorized']);

                // Y tampoco sirve mandar un header cualquiera: no hay con qué compararlo.
                $this->withHeaders(['X-Admin-Api-Key' => 'cualquiera'])
                    ->postJson('/api/admin-sync/extensiones/acceso')
                    ->assertStatus(401);
            }
        }
    }

    /**
     * Control positivo de la misma ruta: con la clave de admin, 200 con la forma exacta del contrato, y en
     * cache queda SOLO el sha256 del token (nunca el token).
     *
     * @test
     */
    public function emitir_con_la_clave_da_200_con_la_forma_del_contrato()
    {
        $respuesta = $this->withHeaders(['X-Admin-Api-Key' => self::CLAVE_DE_ADMIN])
            ->postJson('/api/admin-sync/extensiones/acceso');

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertSame(['acceso', 'expira_en', 'ok', 'ruta'], $this->claves_ordenadas($json));
        $this->assertTrue($json['ok']);
        $this->assertSame(120, $json['expira_en']);
        $this->assertSame('/extensiones', $json['ruta']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $json['acceso']);

        $this->assertTrue(Cache::has(RutaDeMantenimiento::clave_de_cache_del_acceso($json['acceso'])));
        $this->assertFalse(Cache::has(RutaDeMantenimiento::PREFIJO_DE_CACHE_DEL_ACCESO . $json['acceso']), 'El token quedó en cache en claro.');
        $this->assertNull(Cache::get($json['acceso']));
    }

    /** @test */
    public function cada_emision_da_un_token_distinto()
    {
        $this->assertNotSame($this->emitir_un_acceso(), $this->emitir_un_acceso());
    }

    /**
     * @param  array  $json
     * @return array
     */
    protected function claves_ordenadas(array $json)
    {
        $claves = array_keys($json);
        sort($claves);

        return $claves;
    }

    // ----------------------------------------------------------------------------------------------
    // Canjear
    // ----------------------------------------------------------------------------------------------

    /** @test */
    public function sin_acceso_el_configurador_sigue_cerrado()
    {
        $this->get('/extensiones')->assertStatus(404);
        $this->assertNull($this->acceso_hasta());
    }

    /**
     * El canje válido: 302 RELATIVO a la misma URL sin `acceso` (el resto del query intacto), la sesión
     * queda habilitada 2 horas y el pedido siguiente entra al configurador sin token.
     *
     * @test
     */
    public function el_canje_valido_abre_el_configurador_y_redirige_sin_el_acceso()
    {
        $token = $this->emitir_un_acceso();

        $respuesta = $this->get('/extensiones?otro=1&acceso=' . $token);

        $respuesta->assertStatus(302);
        $this->assertSame('/extensiones?otro=1', $respuesta->headers->get('Location'));
        $this->assertStringNotContainsString($token, (string) $respuesta->headers->get('Location'));
        $this->assertSame(now()->addHours(2)->getTimestamp(), $this->acceso_hasta());

        $this->get('/extensiones')->assertStatus(200);
        $this->get('/user/extencions/edit')->assertStatus(200);
    }

    /**
     * Un HEAD (prefetch, vista previa de un enlace) no canjea ni gasta el token: solo un GET.
     *
     * @test
     */
    public function un_head_no_gasta_el_token()
    {
        $token = $this->emitir_un_acceso();

        $this->call('HEAD', '/extensiones', ['acceso' => $token])->assertStatus(404);
        $this->assertNull($this->acceso_hasta());

        $this->get('/extensiones?acceso=' . $token)->assertStatus(302);
    }

    /** @test */
    public function el_canje_solo_funciona_una_vez()
    {
        $token = $this->emitir_un_acceso();

        $this->get('/extensiones?acceso=' . $token)->assertStatus(302);

        // Otra sesión (otro navegador) con el mismo token ya usado.
        $this->flushSession();
        $this->get('/extensiones?acceso=' . $token)->assertStatus(404);
        $this->assertNull($this->acceso_hasta());
    }

    /** @test */
    public function un_token_vencido_da_404()
    {
        $token = $this->emitir_un_acceso();

        // 119 s: todavía vale. 121 s: no.
        $this->travel(121)->seconds();

        $this->get('/extensiones?acceso=' . $token)->assertStatus(404);
        $this->assertNull($this->acceso_hasta());
    }

    /** @test */
    public function un_token_que_no_se_emitio_o_mal_formado_da_404()
    {
        $this->get('/extensiones?acceso=' . str_repeat('a', 64))->assertStatus(404);
        $this->get('/extensiones?acceso=corto')->assertStatus(404);
        $this->get('/extensiones?acceso=')->assertStatus(404);
        $this->get('/extensiones?acceso[]=' . str_repeat('a', 64))->assertStatus(404);

        $this->assertNull($this->acceso_hasta());
    }

    /**
     * El token se canjea SOLO en el configurador (`:demo`). En una ruta de mantenimiento cualquiera da 404
     * y NO se consume: después sigue sirviendo en el configurador.
     *
     * @test
     */
    public function el_token_no_se_canjea_fuera_del_configurador_y_no_se_gasta()
    {
        $token = $this->emitir_un_acceso();

        $this->get(self::RUTA_CERRADA . '?acceso=' . $token)->assertStatus(404);
        $this->get('/helpers/servian?acceso=' . $token)->assertStatus(404);
        $this->assertNull($this->acceso_hasta());

        $this->get('/extensiones?acceso=' . $token)->assertStatus(302);
    }

    /**
     * El acceso vale 2 horas desde el canje (fijo, no se estira con el uso).
     *
     * @test
     */
    public function el_acceso_vence_a_las_dos_horas()
    {
        $this->get('/extensiones?acceso=' . $this->emitir_un_acceso())->assertStatus(302);

        $this->travel(2 * 3600 - 1)->seconds();
        $this->get('/extensiones')->assertStatus(200);

        $this->travel(2)->seconds();
        $this->get('/extensiones')->assertStatus(404);
    }

    // ----------------------------------------------------------------------------------------------
    // Alcance acotado
    // ----------------------------------------------------------------------------------------------

    /**
     * 🔴 El acceso NO abre el resto de mantenimiento: ni las rutas con la guarda de todo el archivo, ni los
     * helpers, ni una ruta con `:demo` que no sea del configurador (la lista es por NOMBRE de ruta).
     *
     * @test
     */
    public function el_acceso_no_abre_otras_rutas_de_mantenimiento()
    {
        $this->get('/extensiones?acceso=' . $this->emitir_un_acceso())->assertStatus(302);

        $this->get('/extensiones')->assertStatus(200);

        $this->get(self::RUTA_CERRADA)->assertStatus(404);
        $this->get(self::RUTA_DEMO_AJENA)->assertStatus(404);
        $this->get('/helpers/servian')->assertStatus(404);
        $this->get('/cambiar-bbdd/x/y')->assertStatus(404);

        // Control: la clave maestra SÍ abre la ruta con `:demo` ajena (el 404 de arriba es por el alcance).
        $this->get(self::RUTA_DEMO_AJENA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE])
            ->assertStatus(200);
    }

    /**
     * 🔴 El acceso es "configurar ESTE comercio": el configurador de otro `{user_id}` (una base compartida
     * tiene varios) da 404, y el del `USER_ID` de la instancia abre. También el POST de guardar.
     *
     * @test
     */
    public function el_acceso_solo_abre_el_comercio_de_la_instancia()
    {
        $this->get('/extensiones?acceso=' . $this->emitir_un_acceso())->assertStatus(302);

        $this->get('/user/extencions/edit/' . $this->comercio->id)->assertStatus(200);

        // El otro comercio EXISTE: el 404 es de la puerta, no del "no existe el usuario" del controlador.
        $this->get('/user/extencions/edit/' . $this->otro_comercio->id)->assertStatus(404);

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->post('/users/' . $this->otro_comercio->id . '/extencions')->assertStatus(404);
    }

    /**
     * Una instancia sin `USER_ID` configurado no abre ningún configurador con id: falla cerrado.
     *
     * @test
     */
    public function sin_user_id_en_la_instancia_no_abre_los_configuradores_con_id()
    {
        $this->get('/extensiones?acceso=' . $this->emitir_un_acceso())->assertStatus(302);

        config(['app.USER_ID' => null]);

        $this->get('/user/extencions/edit/' . $this->comercio->id)->assertStatus(404);
        $this->get('/user/extencions/edit/0')->assertStatus(404);
    }

    // ----------------------------------------------------------------------------------------------
    // Lo que no cambia, y los secretos
    // ----------------------------------------------------------------------------------------------

    /**
     * La clave maestra sigue andando igual: redirige sin `clave` y abre TODO (no queda acotada).
     *
     * @test
     */
    public function la_clave_maestra_sigue_andando()
    {
        $respuesta = $this->get('/extensiones?clave=' . urlencode(self::CLAVE));

        $respuesta->assertStatus(302);
        $this->assertSame('/extensiones', $respuesta->headers->get('Location'));

        $this->get('/extensiones')->assertStatus(200);
        $this->get(self::RUTA_CERRADA)->assertStatus(200);
        $this->get('/user/extencions/edit/' . $this->otro_comercio->id)->assertStatus(200); // la maestra no tiene la restricción del id
    }

    /**
     * Ni el token ni la clave de admin quedan en ningún log: ni en el bloqueo (404), ni en el 401 de emitir,
     * ni en el flujo feliz.
     *
     * @test
     */
    public function el_token_y_la_clave_no_aparecen_en_el_log()
    {
        $token = $this->emitir_un_acceso();

        // Un token válido canjeado en una ruta de mantenimiento común: se loguea el bloqueo.
        $this->get(self::RUTA_CERRADA . '?acceso=' . $token)->assertStatus(404);
        // Un token inválido: 404 con su log.
        $malo = str_repeat('b', 64);
        $this->get('/extensiones?acceso=' . $malo)->assertStatus(404);
        // Un 401 de emitir con un header malo.
        $this->withHeaders(['X-Admin-Api-Key' => 'header-malo-que-no-tiene-que-quedar'])->postJson('/api/admin-sync/extensiones/acceso')->assertStatus(401);
        // Y el flujo feliz.
        $this->get('/extensiones?acceso=' . $token)->assertStatus(302);

        $this->assertNotEmpty($this->logs_de_bloqueo());

        $todo_el_log = json_encode($this->logs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($token, $todo_el_log);
        $this->assertStringNotContainsString($malo, $todo_el_log);
        $this->assertStringNotContainsString(self::CLAVE_DE_ADMIN, $todo_el_log);
        $this->assertStringNotContainsString('header-malo-que-no-tiene-que-quedar', $todo_el_log);
    }
}
