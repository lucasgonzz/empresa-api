<?php

namespace Tests\Feature\CentroRecursos;

use App\Models\CentroRecursosPase;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * POST api/centro-recursos/pase — el pase con el que el menú "Centro de recursos" abre la página
 * (misión centro-de-recursos-clientes, 13/10/2026, contratos C1 y C4 del plan).
 *
 * 🔴 QUÉ PROTEGE ESTE ARCHIVO.
 *  - La forma de la respuesta y de la URL: la lee el SPA (`url`, `disponible`) y la página
 *    (`pase`, `api`, `v`). Un nombre cambiado acá rompe dos proyectos que no se enteran.
 *  - Que en la base quede el HASH del pase y nunca el texto: el pase abre una sesión del centro a
 *    nombre del usuario.
 *  - Que con la página caída NO se cree ningún pase y el SPA reciba `disponible: false`: es lo que
 *    deja salir este sistema con el botón antes de que la página exista.
 *  - Los tiempos del cache del chequeo (10 minutos si anda, 2 si no).
 *  - El login maestro (misión centro-recursos-soporte-y-tema, 14/10/2026): entra a la página como el
 *    dueño pero en SOLO LECTURA. Si la página declara `soporte: true` en su archivo de estado, recibe
 *    un pase marcado `soporte` (URL con `pase` y `api`); si la página es vieja y no lo declara, abre
 *    como invitado, exactamente como antes. Un usuario común no cambia en ningún caso.
 *
 * Nada sale a internet: el chequeo de la página siempre está falseado con `Http::fake()`.
 *
 * @group centro-recursos
 */
class PaseTest extends EmpresaTestCase
{
    /** Ruta del contrato C1. */
    const RUTA = 'api/centro-recursos/pase';

    /** Host con el que "el navegador" le pega a esta API: tiene que volver tal cual en `api`. */
    const HOST_API = 'http://api-prueba.comerciocity.test';

    /** El archivo de estado que publica la página (contrato C4). */
    const ESTADO = 'https://recursos.comerciocity.com/recursos-estado.json';

    /** @var User Dueño del fixture, que es el usuario logueado. */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        Carbon::setTestNow(Carbon::create(2026, 10, 13, 10, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * La página responde `{"ok": true}`.
     *
     * @return void
     */
    protected function la_pagina_anda()
    {
        Http::fake([
            self::ESTADO => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * La página responde `{"ok": true, "soporte": true}`: sabe abrir una sesión de solo lectura.
     *
     * @return void
     */
    protected function la_pagina_anda_con_soporte()
    {
        Http::fake([
            self::ESTADO => Http::response(['ok' => true, 'soporte' => true], 200),
        ]);
    }

    /**
     * Marca la sesión como un login maestro, igual que AuthController::login().
     *
     * @return void
     */
    protected function como_login_maestro()
    {
        $this->withSession(['master_login_bypass_user_last_activity' => true]);
    }

    /**
     * Pide un pase "desde el navegador", pegándole a esta API por HOST_API.
     *
     * @param  array $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_pase(array $cuerpo = [])
    {
        return $this->postJson(self::HOST_API . '/' . self::RUTA, $cuerpo);
    }

    /**
     * Los parámetros del query string de una URL.
     *
     * @param  string $url
     * @return array
     */
    protected function parametros_de($url)
    {
        $parametros = [];

        parse_str((string) parse_url($url, PHP_URL_QUERY), $parametros);

        return $parametros;
    }

    /**
     * Cuántos pases tiene un usuario.
     *
     * @param  User $user
     * @return int
     */
    protected function pases_de($user)
    {
        return CentroRecursosPase::where('user_id', $user->id)->count();
    }

    /**
     * Con sesión, devuelve la URL de la página con el pase y la raíz de esta API.
     */
    public function test_con_sesion_devuelve_la_url_de_la_pagina_con_el_pase_y_la_api()
    {
        $this->la_pagina_anda();

        $respuesta = $this->pedir_pase();

        $respuesta->assertStatus(200)->assertJson(['disponible' => true]);

        $url = $respuesta->json('url');

        $this->assertIsString($url);
        $this->assertStringStartsWith('https://recursos.comerciocity.com/?', $url);

        $parametros = $this->parametros_de($url);

        $this->assertSame(['pase', 'api'], array_keys($parametros), 'Sin video, la URL lleva pase y api, nada más.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $parametros['pase']);
        $this->assertSame(self::HOST_API, $parametros['api'], '`api` es la raíz de esta API tal como la alcanzó el navegador.');

        // La URL va codificada: la `api` no rompe el query string de la página.
        $this->assertStringContainsString('api=' . rawurlencode(self::HOST_API), $url);
    }

    /**
     * En la base queda el sha256 del pase, nunca el texto, con 5 minutos de vida y sin usar.
     */
    public function test_guarda_el_hash_del_pase_y_nunca_el_texto()
    {
        $this->la_pagina_anda();

        $antes = $this->pases_de($this->dueno);

        $pase = $this->parametros_de($this->pedir_pase()->assertStatus(200)->json('url'))['pase'];

        $this->assertSame($antes + 1, $this->pases_de($this->dueno));

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $pase))->first();

        $this->assertNotNull($fila, 'La fila se encuentra por el hash del pase.');
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id);
        $this->assertNull($fila->video);
        $this->assertFalse($fila->soporte, 'Un usuario común recibe un pase normal, no de soporte.');
        $this->assertNull($fila->usado_at);
        $this->assertTrue(Carbon::now()->addMinutes(5)->equalTo($fila->expires_at), 'Vence a los 5 minutos.');

        $this->assertSame(0, CentroRecursosPase::where('token_hash', $pase)->count(), 'El texto del pase no se guarda.');
        $this->assertArrayNotHasKey('token_hash', $fila->toArray(), 'El hash no se serializa.');
    }

    /**
     * Con `video`, la URL lleva `v` y el pase lo recuerda (el canje lo devuelve).
     */
    public function test_con_video_lo_agrega_a_la_url_y_al_pase()
    {
        $this->la_pagina_anda();

        $url = $this->pedir_pase(['video' => 'T1.29'])->assertStatus(200)->json('url');

        $parametros = $this->parametros_de($url);

        $this->assertSame('T1.29', $parametros['v']);

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $parametros['pase']))->first();

        $this->assertSame('T1.29', $fila->video);
    }

    /**
     * Un `video` con caracteres que no son de un código, o demasiado largo, da 422 y no crea pase:
     * lo que viaja en `v` no puede inyectar otros parámetros en la URL de la página.
     */
    public function test_un_video_que_no_es_un_codigo_da_422_y_no_crea_pase()
    {
        $this->la_pagina_anda();

        $antes = $this->pases_de($this->dueno);

        $this->pedir_pase(['video' => 'T1.29&pase=otro'])->assertStatus(422);
        $this->pedir_pase(['video' => str_repeat('A', 21)])->assertStatus(422);
        $this->pedir_pase(['video' => ['T1.29']])->assertStatus(422);

        $this->assertSame($antes, $this->pases_de($this->dueno));
    }

    /**
     * Sin sesión, 401 y nada creado (ni siquiera se consulta la página).
     */
    public function test_sin_sesion_da_401_y_no_crea_pase()
    {
        $this->la_pagina_anda();

        $antes = CentroRecursosPase::count();

        // `forgetGuards()` tira el guard que EmpresaTestCase::setUp() dejó con el usuario del fixture.
        Auth::forgetGuards();

        $this->pedir_pase()->assertStatus(401);

        $this->assertSame($antes, CentroRecursosPase::count());

        Http::assertNothingSent();
    }

    /**
     * Un empleado también consigue su pase, a su nombre (el centro guarda el avance de cada persona).
     */
    public function test_un_empleado_tambien_consigue_su_pase()
    {
        $this->la_pagina_anda();

        $empleado = User::create([
            'name'     => 'Empleado del centro de recursos',
            'email'    => 'empleado-centro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);

        Auth::forgetGuards();
        $this->actingAs($empleado, 'web');

        $url = $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => true])->json('url');

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $this->parametros_de($url)['pase']))->first();

        $this->assertSame((int) $empleado->id, (int) $fila->user_id, 'El pase es del empleado, no del dueño.');
    }

    /**
     * Con la página dando 500: `{"url": null, "disponible": false}` y ningún pase creado.
     */
    public function test_si_la_pagina_da_500_avisa_no_disponible_y_no_crea_pase()
    {
        Http::fake([
            self::ESTADO => Http::response('error', 500),
        ]);

        $antes = $this->pases_de($this->dueno);

        $this->pedir_pase()
             ->assertStatus(200)
             ->assertExactJson(['url' => null, 'disponible' => false]);

        $this->assertSame($antes, $this->pases_de($this->dueno));
    }

    /**
     * Una página de estacionamiento que contesta HTML con 200 no es "la página anda".
     */
    public function test_si_la_pagina_devuelve_html_no_esta_disponible()
    {
        Http::fake([
            self::ESTADO => Http::response('<html><body>Dominio estacionado</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);
    }

    /**
     * `{"ok": false}` tampoco es "anda".
     */
    public function test_si_el_estado_dice_ok_false_no_esta_disponible()
    {
        Http::fake([
            self::ESTADO => Http::response(['ok' => false], 200),
        ]);

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);
    }

    /**
     * Si la página no contesta (sin DNS, timeout), no está disponible y no revienta.
     */
    public function test_si_la_pagina_no_contesta_no_esta_disponible()
    {
        Http::fake(function (PedidoHttp $pedido) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $antes = $this->pases_de($this->dueno);

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);

        $this->assertSame($antes, $this->pases_de($this->dueno));
    }

    /**
     * El chequeo pide el JSON de estado por GET, a la URL de la página.
     */
    public function test_el_chequeo_pide_el_json_de_estado_de_la_pagina()
    {
        $this->la_pagina_anda();

        $this->pedir_pase()->assertStatus(200);

        Http::assertSentCount(1);
        Http::assertSent(function (PedidoHttp $pedido) {
            return $pedido->method() === 'GET' && $pedido->url() === self::ESTADO;
        });
    }

    /**
     * Si anda, el resultado se recuerda 10 minutos: a los 9 no se vuelve a consultar, a los 11 sí.
     */
    public function test_si_anda_el_chequeo_se_recuerda_diez_minutos()
    {
        $this->la_pagina_anda();

        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => true]);
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => true]);
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => true]);
        Http::assertSentCount(2);
    }

    /**
     * Si no anda, se recuerda 2 minutos: al minuto no se vuelve a consultar, a los 3 sí.
     */
    public function test_si_no_anda_el_chequeo_se_recuerda_dos_minutos()
    {
        Http::fake([
            self::ESTADO => Http::response('error', 500),
        ]);

        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => false]);
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => false]);
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => false]);
        Http::assertSentCount(2);
    }

    /**
     * 🔴 CAMBIO PEDIDO POR LUCAS (14/10/2026), no una aserción ajustada para que pase: hasta el
     * 13/10 este test afirmaba que un login maestro abría la página SIN pase (invitado). Lucas decidió
     * que el maestro entre como el dueño pero en SOLO LECTURA, así que ahora, con una página que
     * declara `soporte: true`, la URL lleva `pase` y `api` como la de cualquiera y la fila del pase
     * queda marcada `soporte`. El "sin pase" sigue vigente para la página vieja (test siguiente).
     */
    public function test_con_login_maestro_y_la_pagina_con_soporte_lleva_pase_de_soporte()
    {
        $this->la_pagina_anda_con_soporte();

        $this->como_login_maestro();

        $antes = $this->pases_de($this->dueno);

        $url = $this->pedir_pase()->assertStatus(200)->assertJson(['disponible' => true])->json('url');

        $parametros = $this->parametros_de($url);

        $this->assertSame(['pase', 'api'], array_keys($parametros), 'El maestro con soporte recibe pase y api, como cualquiera.');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/', $parametros['pase']);
        $this->assertSame(self::HOST_API, $parametros['api']);

        $this->assertSame($antes + 1, $this->pases_de($this->dueno));

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $parametros['pase']))->first();

        $this->assertNotNull($fila);
        $this->assertSame((int) $this->dueno->id, (int) $fila->user_id, 'El pase es del usuario logueado: el maestro entra como el dueño.');
        $this->assertTrue($fila->soporte, 'El pase del maestro va marcado soporte.');
        $this->assertNull($fila->usado_at);

        // Con video, la URL lleva también `v` y el pase lo recuerda.
        $url = $this->pedir_pase(['video' => 'T1.29'])->assertStatus(200)->json('url');

        $parametros = $this->parametros_de($url);

        $this->assertSame(['pase', 'api', 'v'], array_keys($parametros));
        $this->assertSame('T1.29', $parametros['v']);

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $parametros['pase']))->first();

        $this->assertTrue($fila->soporte);
        $this->assertSame('T1.29', $fila->video);

        // Una sola consulta a la página para los dos pedidos: `disponible` y `soporte` salen de la misma.
        Http::assertSentCount(1);
    }

    /**
     * Un usuario común no cambia por más que la página declare `soporte`: pase normal, `soporte`
     * apagado, y sin consultar nada más que lo de siempre.
     */
    public function test_un_usuario_comun_con_una_pagina_con_soporte_recibe_un_pase_normal()
    {
        $this->la_pagina_anda_con_soporte();

        $parametros = $this->parametros_de($this->pedir_pase()->assertStatus(200)->json('url'));

        $this->assertSame(['pase', 'api'], array_keys($parametros));

        $fila = CentroRecursosPase::where('token_hash', hash('sha256', $parametros['pase']))->first();

        $this->assertFalse($fila->soporte, 'Solo el login maestro lleva la marca de soporte.');

        Http::assertSentCount(1);
    }

    /**
     * 🔴 Login maestro + página VIEJA (su estado no trae `soporte`): se queda como estaba antes de la
     * misión de soporte. La página abre como invitado: la URL no trae `pase` ni `api`, no se crea
     * ninguna fila y `disponible` es true. Con pase común, lo que soporte hiciera en el centro
     * quedaría guardado como avance y preguntas del cliente; y una página que no sabe de solo lectura
     * no puede garantizar que no guarde. (Esta es la aserción original del 13/10, intacta.)
     */
    public function test_con_login_maestro_y_una_pagina_sin_soporte_abre_la_pagina_sin_pase_y_no_crea_fila()
    {
        $this->la_pagina_anda();

        // La misma marca que pone AuthController::login() en un login maestro.
        $this->como_login_maestro();

        $antes = CentroRecursosPase::count();

        $this->pedir_pase()
             ->assertStatus(200)
             ->assertExactJson([
                 'url'        => 'https://recursos.comerciocity.com/',
                 'disponible' => true,
             ]);

        $url = $this->pedir_pase(['video' => 'T1.29'])->assertStatus(200)->assertJson(['disponible' => true])->json('url');

        $this->assertSame('https://recursos.comerciocity.com/?v=T1.29', $url);
        $this->assertSame(['v' => 'T1.29'], $this->parametros_de($url), 'Sin pase y sin api: solo el video.');

        $this->assertSame($antes, CentroRecursosPase::count(), 'Con una página sin soporte, un login maestro no crea pases.');
    }

    /**
     * `soporte` que no es el booleano `true` (false, el texto "true", un 1) no es soporte: el maestro
     * sigue sin pase. Solo la declaración estricta activa el modo de solo lectura.
     */
    public function test_con_login_maestro_un_soporte_que_no_es_true_estricto_no_cuenta()
    {
        $this->como_login_maestro();

        $cuerpo = ['ok' => true, 'soporte' => false];

        // Un solo fake con callback (y no uno por vuelta): el primer fake que coincide gana.
        Http::fake(function (PedidoHttp $pedido) use (&$cuerpo) {
            return Http::response($cuerpo, 200);
        });

        $antes = CentroRecursosPase::count();

        foreach ([false, 'true', 1, null] as $soporte) {
            Cache::flush();

            $cuerpo = ['ok' => true, 'soporte' => $soporte];

            $this->pedir_pase()
                 ->assertStatus(200)
                 ->assertExactJson(['url' => 'https://recursos.comerciocity.com/', 'disponible' => true]);
        }

        $this->assertSame($antes, CentroRecursosPase::count(), 'Ningún valor distinto de true crea un pase.');

        Http::assertSentCount(4);
    }

    /**
     * Con login maestro el chequeo de la página vale igual: caída, no se abre nada ni se crea nada
     * (ni siquiera se pregunta por el soporte: con la página caída no hay a quién preguntarle).
     */
    public function test_con_login_maestro_y_la_pagina_caida_no_esta_disponible()
    {
        Http::fake([
            self::ESTADO => Http::response('error', 500),
        ]);

        $this->como_login_maestro();

        $antes = CentroRecursosPase::count();

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);

        $this->assertSame($antes, CentroRecursosPase::count());

        Http::assertSentCount(1);
    }

    /**
     * Una página que no responde (sin DNS, timeout) tampoco da soporte: `disponible: false` y nada creado.
     */
    public function test_con_login_maestro_y_la_pagina_sin_respuesta_no_esta_disponible()
    {
        Http::fake(function (PedidoHttp $pedido) {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->como_login_maestro();

        $antes = CentroRecursosPase::count();

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);

        $this->assertSame($antes, CentroRecursosPase::count());
    }

    /**
     * `{"ok": false, "soporte": true}`: una página que dice que no está lista no abre nada, por más
     * que declare soporte. El `ok` manda.
     */
    public function test_con_login_maestro_y_ok_false_aunque_diga_soporte_no_esta_disponible()
    {
        Http::fake([
            self::ESTADO => Http::response(['ok' => false, 'soporte' => true], 200),
        ]);

        $this->como_login_maestro();

        $antes = CentroRecursosPase::count();

        $this->pedir_pase()->assertStatus(200)->assertExactJson(['url' => null, 'disponible' => false]);

        $this->assertSame($antes, CentroRecursosPase::count());
    }

    /**
     * La marca de login maestro en `false` es una sesión común: lleva pase.
     */
    public function test_con_la_marca_de_login_maestro_apagada_lleva_pase()
    {
        $this->la_pagina_anda();

        $this->withSession(['master_login_bypass_user_last_activity' => false]);

        $parametros = $this->parametros_de($this->pedir_pase()->assertStatus(200)->json('url'));

        $this->assertArrayHasKey('pase', $parametros);
        $this->assertArrayHasKey('api', $parametros);
    }

    /**
     * La URL de la página tiene el default escrito en la config (los `.env` de los clientes no la
     * traen), y si se cambia la config, el chequeo y la URL siguen a la nueva.
     */
    public function test_la_url_de_la_pagina_sale_de_la_config_con_default_escrito()
    {
        $this->assertSame('https://recursos.comerciocity.com', config('services.centro_recursos.url'));

        config(['services.centro_recursos.url' => 'https://otra-pagina.test/']);

        Http::fake([
            'https://otra-pagina.test/recursos-estado.json' => Http::response(['ok' => true], 200),
        ]);

        $url = $this->pedir_pase()->assertStatus(200)->json('url');

        $this->assertStringStartsWith('https://otra-pagina.test/?pase=', $url);
    }
}
