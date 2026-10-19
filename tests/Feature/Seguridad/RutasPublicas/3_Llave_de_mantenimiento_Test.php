<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Http\Middleware\RutaDeMantenimiento;
use App\Http\Middleware\VerifyCsrfToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Cuándo abre la puerta `solo.mantenimiento` (App\Http\Middleware\RutaDeMantenimiento, misión
 * cerrar-rutas-web-publicas, 10/10/2026), sobre rutas de prueba registradas acá con el mismo middleware
 * que pone RouteServiceProvider, y al final sobre una real (`/extensiones`).
 *
 * Abre: la suite (`testing`), la variable explícita `RUTAS_DE_MANTENIMIENTO`, la marca de demo en las
 * rutas con `:demo`, una sesión habilitada y vigente (2 h) y la clave maestra por header o por query (en
 * un GET, redirige a la misma URL sin la clave). Todo lo demás, 404 — incluido `APP_ENV=local` — y el log
 * del bloqueo nunca lleva la clave.
 *
 * El reloj se congela al principio de cada test (travelTo) para que la cuenta de las 2 horas no dependa
 * de en qué milisegundo cae cada request.
 */
class Llave_de_mantenimiento_Test extends RutasPublicasTestCase
{
    /** Ruta de prueba con la guarda de todo mantenimiento.php. */
    const RUTA = '/_prueba-de-la-llave';

    /** Ruta de prueba con la variante del configurador. */
    const RUTA_DEMO = '/_prueba-de-la-llave-demo';

    /** Lo que contestan las rutas de prueba cuando la puerta deja pasar. */
    const ADENTRO = 'ADENTRO-DE-MANTENIMIENTO';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'solo.mantenimiento'])->group(function () {
            Route::get(self::RUTA, function () {
                return self::ADENTRO;
            });
            Route::post(self::RUTA, function () {
                return self::ADENTRO . '-POST';
            });
        });

        Route::middleware(['web', 'solo.mantenimiento:demo'])->get(self::RUTA_DEMO, function () {
            return self::ADENTRO . '-DEMO';
        });

        $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
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
     * El timestamp hasta el que quedó habilitada la sesión, o null.
     *
     * @return int|null
     */
    protected function habilitada_hasta()
    {
        return session(RutaDeMantenimiento::SESION_HABILITADA_HASTA);
    }

    /** @test */
    public function en_la_suite_de_tests_abre_sin_clave()
    {
        $this->assertTrue(app()->environment('testing'));

        $this->get(self::RUTA)->assertStatus(200)->assertSee(self::ADENTRO);
    }

    /** @test */
    public function en_produccion_sin_clave_da_404()
    {
        $this->como_produccion();

        $this->get(self::RUTA)->assertStatus(404);
        $this->assertNull($this->habilitada_hasta());
    }

    /**
     * El CUERPO del 404 de la puerta es el mismo de una ruta que no existe: la página no le dice a quien
     * barre URLs que ahí hay algo.
     *
     * Compara solo el cuerpo y el código, a propósito: los headers NO son iguales (la puerta corre dentro
     * del grupo `web`, que ya puso el Set-Cookie de la sesión y el de XSRF; ver el docblock de
     * RutaDeMantenimiento, que explica por qué se acepta).
     *
     * @test
     */
    public function el_cuerpo_del_404_es_igual_al_de_una_ruta_que_no_existe()
    {
        $this->como_produccion();

        $cerrada = $this->get(self::RUTA);
        $inexistente = $this->get('/_ruta-que-no-existe-en-ningun-lado');

        $cerrada->assertStatus(404);
        $inexistente->assertStatus(404);
        $this->assertSame($inexistente->getContent(), $cerrada->getContent());
    }

    /** @test */
    public function la_variable_explicita_abre()
    {
        $this->como_produccion();
        config(['app.rutas_de_mantenimiento' => true]);

        $this->get(self::RUTA)->assertStatus(200)->assertSee(self::ADENTRO);
    }

    /**
     * Decisión de Lucas (10/10/2026): NO se abre por `APP_ENV=local`, porque no se pudo medir que ningún
     * cliente de producción no lo tenga.
     *
     * @test
     */
    public function app_env_local_no_abre()
    {
        $this->como_produccion();
        $this->app['env'] = 'local';

        $this->get(self::RUTA)->assertStatus(404);
    }

    /**
     * Por query en un GET: 302 a la misma URL sin la clave (el resto del query intacto), con la sesión
     * habilitada; el pedido siguiente entra sin clave; a las 2 h + 1 s vuelve el 404.
     *
     * @test
     */
    public function la_clave_por_query_redirige_sin_la_clave_y_habilita_la_sesion_dos_horas()
    {
        $this->como_produccion();

        $respuesta = $this->get(self::RUTA . '?otro=1&clave=' . urlencode(self::CLAVE));

        $respuesta->assertStatus(302);
        $respuesta->assertRedirect(url(self::RUTA . '?otro=1'));
        $this->assertStringNotContainsString('clave', (string) $respuesta->headers->get('Location'));
        $this->assertSame(now()->addHours(2)->getTimestamp(), $this->habilitada_hasta());

        // Sin clave, con la sesión habilitada.
        $this->get(self::RUTA . '?otro=1')->assertStatus(200)->assertSee(self::ADENTRO);

        // Un segundo antes de las 2 horas, todavía entra.
        $this->travel(2 * 3600 - 1)->seconds();
        $this->get(self::RUTA)->assertStatus(200);

        // A las 2 h + 1 s, no.
        $this->travel(2)->seconds();
        $this->get(self::RUTA)->assertStatus(404);
    }

    /** @test */
    public function la_clave_por_query_sola_redirige_a_la_url_limpia()
    {
        $this->como_produccion();

        $this->get(self::RUTA . '?clave=' . urlencode(self::CLAVE))->assertRedirect(url(self::RUTA));
    }

    /**
     * Por header (lo que usa un script): pasa directo, sin redirección, y también habilita la sesión.
     *
     * @test
     */
    public function la_clave_por_header_pasa_sin_redireccion()
    {
        $this->como_produccion();

        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE])
            ->assertStatus(200)
            ->assertSee(self::ADENTRO);

        $this->assertSame(now()->addHours(2)->getTimestamp(), $this->habilitada_hasta());
    }

    /**
     * En un POST la clave por query no redirige (se perdería el cuerpo): sigue al controlador.
     *
     * @test
     */
    public function en_un_post_la_clave_por_query_sigue_al_controlador()
    {
        $this->como_produccion();

        // El CSRF corre antes que la puerta (grupo `web`); acá se mide la puerta.
        $this->withoutMiddleware(VerifyCsrfToken::class);

        $this->post(self::RUTA . '?clave=' . urlencode(self::CLAVE))->assertStatus(200)->assertSee(self::ADENTRO . '-POST');

        $this->post(self::RUTA)->assertStatus(200);
    }

    /** @test */
    public function una_clave_equivocada_da_404_y_no_habilita_nada()
    {
        $this->como_produccion();

        $this->get(self::RUTA . '?clave=otra-clave-cualquiera')->assertStatus(404);
        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => 'otra-clave-cualquiera'])->assertStatus(404);

        $this->assertNull($this->habilitada_hasta());
        $this->get(self::RUTA)->assertStatus(404);
    }

    /**
     * Falla cerrado: sin hash, con un hash de largo distinto de 64 o que no es hexadecimal, ninguna clave
     * abre. Y la clave vacía no abre ni siquiera si el hash cargado es el de la cadena vacía.
     *
     * @test
     */
    public function falla_cerrado_con_el_hash_vacio_o_mal_formado_y_con_la_clave_vacia()
    {
        $this->como_produccion();

        foreach (['', substr(hash('sha256', self::CLAVE), 0, 63), str_repeat('z', 64)] as $hash) {
            config(['app.mantenimiento_clave_sha256' => $hash]);

            $this->get(self::RUTA . '?clave=' . urlencode(self::CLAVE))->assertStatus(404);
            $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE])->assertStatus(404);
        }

        config(['app.mantenimiento_clave_sha256' => hash('sha256', '')]);

        $this->get(self::RUTA . '?clave=')->assertStatus(404);
        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => ''])->assertStatus(404);

        $this->assertNull($this->habilitada_hasta());
    }

    /**
     * El hash se compara sin importar mayúsculas ni espacios alrededor (como quede pegado en un .env).
     *
     * @test
     */
    public function el_hash_en_mayusculas_tambien_sirve()
    {
        $this->como_produccion();
        config(['app.mantenimiento_clave_sha256' => ' ' . strtoupper(hash('sha256', self::CLAVE)) . ' ']);

        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE])->assertStatus(200);
    }

    /**
     * `?clave[]=x` no es una clave: 404, no un 500.
     *
     * @test
     */
    public function una_clave_que_no_es_texto_da_404_y_no_500()
    {
        $this->como_produccion();

        $this->get(self::RUTA . '?clave[]=' . urlencode(self::CLAVE))->assertStatus(404);
    }

    /**
     * En una demo abren SOLO las rutas con `:demo` (el configurador); `helpers/` y el resto siguen
     * cerrados. Y `:demo` fuera de una demo no abre nada.
     *
     * @test
     */
    public function la_marca_de_demo_abre_solo_las_rutas_con_el_parametro()
    {
        $this->como_produccion();

        // Fuera de una demo, `:demo` no abre.
        $this->get(self::RUTA_DEMO)->assertStatus(404);
        $this->get('/extensiones')->assertStatus(404);

        config(['app.FOR_USER' => 'demo']);

        $this->get(self::RUTA_DEMO)->assertStatus(200)->assertSee(self::ADENTRO . '-DEMO');
        $this->get(self::RUTA)->assertStatus(404);

        // Las reales: el configurador sí, un helper no.
        $this->get('/extensiones')->assertStatus(200);
        $this->get('/user/extencions/edit/500')->assertStatus(200);
        $this->get('/helpers/servian')->assertStatus(404);
        $this->get('/cambiar-bbdd/x/y')->assertStatus(404);

        $this->assertGuest('web');
    }

    /**
     * El log del bloqueo dice qué ruta (el patrón), qué método, desde qué IP y que vino una clave, pero
     * NUNCA la clave ni la URL completa (la llevaría en el query).
     *
     * @test
     */
    public function el_log_del_bloqueo_no_lleva_la_clave()
    {
        $this->como_produccion();

        $clave_intentada = 'intento-de-clave-que-no-tiene-que-quedar-en-el-log';

        $this->get(self::RUTA . '?clave=' . $clave_intentada)->assertStatus(404);
        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => $clave_intentada])->assertStatus(404);
        $this->get(self::RUTA)->assertStatus(404);

        $bloqueos = $this->logs_de_bloqueo();

        $this->assertCount(3, $bloqueos);

        foreach ($bloqueos as $indice => $bloqueo) {
            $this->assertSame('warning', $bloqueo['nivel']);
            $this->assertSame(ltrim(self::RUTA, '/'), $bloqueo['contexto']['ruta']);
            $this->assertSame('GET', $bloqueo['contexto']['metodo']);
            $this->assertArrayHasKey('ip', $bloqueo['contexto']);
            $this->assertSame($indice < 2, $bloqueo['contexto']['con_clave']);
        }

        $todo_el_log = json_encode($this->logs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($clave_intentada, $todo_el_log);
        $this->assertStringNotContainsString('clave=', $todo_el_log);
    }

    /**
     * Una real, de punta a punta: `/extensiones?clave=` → 302 a `/extensiones` → el configurador.
     *
     * @test
     */
    public function una_ruta_real_con_la_clave_redirige_y_abre()
    {
        $this->como_produccion();

        $this->get('/extensiones')->assertStatus(404);

        $this->get('/extensiones?clave=' . urlencode(self::CLAVE))->assertRedirect(url('/extensiones'));

        $this->get('/extensiones')->assertStatus(200)->assertSee('extencions[]', false);
    }

    /**
     * El 302 de después de la clave es RELATIVO: con `TrustProxies::$proxies = '*'`, una URL absoluta
     * tomaría el host de `X-Forwarded-Host`, que pone cualquiera, y mandaría el navegador a otro dominio.
     *
     * @test
     */
    public function la_redireccion_es_relativa_aunque_venga_x_forwarded_host()
    {
        $this->como_produccion();

        $respuesta = $this->get(self::RUTA . '?otro=1&clave=' . urlencode(self::CLAVE), [
            'X-Forwarded-Host' => 'evil.example.com',
        ]);

        $respuesta->assertStatus(302);

        $location = (string) $respuesta->headers->get('Location');

        $this->assertSame(self::RUTA . '?otro=1', $location);
        $this->assertSame('/', substr($location, 0, 1), 'La redirección tiene que empezar con /.');
        $this->assertStringNotContainsString('evil', $location);
    }

    /**
     * Con la sesión YA habilitada, un GET que igual trae `clave` en la URL se redirige sin ella (sin
     * validarla: no hace falta); si no, la clave quedaría en el historial. En un POST sigue de largo.
     *
     * @test
     */
    public function con_la_sesion_ya_habilitada_un_get_con_clave_igual_redirige_sin_la_clave()
    {
        $this->como_produccion();

        // Se habilita por header (sin redirección).
        $this->get(self::RUTA, [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE])->assertStatus(200);

        // Con la clave en la URL (aunque sea cualquier cosa): 302 relativo sin ella, el resto del query intacto.
        $respuesta = $this->get(self::RUTA . '?otro=1&clave=cualquier-cosa');
        $respuesta->assertStatus(302);
        $this->assertSame(self::RUTA . '?otro=1', $respuesta->headers->get('Location'));

        // Y también con la clave verdadera.
        $this->assertSame(self::RUTA, $this->get(self::RUTA . '?clave=' . urlencode(self::CLAVE))->headers->get('Location'));

        // Sin la clave, entra.
        $this->get(self::RUTA . '?otro=1')->assertStatus(200)->assertSee(self::ADENTRO);

        // En un POST no se redirige: se perdería el cuerpo.
        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->post(self::RUTA . '?clave=cualquier-cosa')->assertStatus(200)->assertSee(self::ADENTRO . '-POST');
    }

    /**
     * Pide la ruta de prueba con una cookie de sesión fijada y devuelve el id de sesión con el que quedó
     * el store después del request.
     *
     * @param  string  $id_fijado  Id de sesión que viaja en la cookie (40 alfanuméricos).
     * @param  string  $sufijo     Lo que va después de la ruta (el query).
     * @param  array  $headers
     * @param  int  $estado_esperado
     * @return string
     */
    protected function id_de_sesion_despues_de($id_fijado, $sufijo, array $headers, $estado_esperado)
    {
        $this->withCookie(config('session.cookie'), $id_fijado)
            ->get(self::RUTA . $sufijo, $headers)
            ->assertStatus($estado_esperado);

        return app('session')->driver()->getId();
    }

    /**
     * Al habilitar la sesión con la clave por QUERY, el id de sesión cambia (como en un login): un id
     * fijado antes en el navegador no hereda el privilegio. Control: sin clave el id no cambia.
     *
     * @test
     */
    public function habilitar_por_query_cambia_el_id_de_sesion()
    {
        $this->como_produccion();

        $control = Str::random(40);
        $this->assertSame($control, $this->id_de_sesion_despues_de($control, '', [], 404), 'Control: sin clave el id de sesión tiene que quedar igual.');

        $fijado = Str::random(40);
        $despues = $this->id_de_sesion_despues_de($fijado, '?clave=' . urlencode(self::CLAVE), [], 302);

        $this->assertNotSame($fijado, $despues, 'Al habilitar la sesión por query el id tiene que cambiar.');
        $this->assertNotNull($this->habilitada_hasta());
    }

    /**
     * Lo mismo, con la clave por HEADER.
     *
     * @test
     */
    public function habilitar_por_header_cambia_el_id_de_sesion()
    {
        $this->como_produccion();

        $control = Str::random(40);
        $this->assertSame($control, $this->id_de_sesion_despues_de($control, '', [], 404), 'Control: sin clave el id de sesión tiene que quedar igual.');

        $fijado = Str::random(40);
        $despues = $this->id_de_sesion_despues_de($fijado, '', [RutaDeMantenimiento::HEADER_DE_LA_CLAVE => self::CLAVE], 200);

        $this->assertNotSame($fijado, $despues, 'Al habilitar la sesión por header el id tiene que cambiar.');
        $this->assertNotNull($this->habilitada_hasta());
    }
}
