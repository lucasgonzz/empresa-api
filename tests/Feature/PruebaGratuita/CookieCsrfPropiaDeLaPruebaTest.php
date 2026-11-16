<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\PruebaConfig;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * En una prueba vigente el sistema emite su token CSRF en una cookie con nombre propio y no escribe
 * `XSRF-TOKEN` (misión prueba-e2e-campana-imperio, ronda 4, X1).
 *
 * En la ronda 4 (14/11) el pedido de la tienda de la prueba falló cuatro veces con 419: el sistema y la tienda
 * escriben `XSRF-TOKEN @ .comerciocity.com` y, con el sistema abierto en otra pestaña, la tienda mandaba el
 * token del sistema. Acá se mide qué cookies salen, con qué atributos, y que la verificación CSRF de verdad
 * (no la que el framework saltea en la suite) acepta el token de la cookie propia.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class CookieCsrfPropiaDeLaPruebaTest extends TestCase
{
    use DatabaseTransactions;

    private const API = 'https://api-ferreteria-la-esquin.comerciocity.test';
    private const SPA = 'https://ferreteria-la-esquin.comerciocity.test';
    private const PROPIA = 'XSRF-api_ferreteria_la_esquin_comerciocity_test';

    private $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::whereNull('owner_id')->orderBy('id')->firstOrFail();

        config([
            'app.FOR_USER' => 'prueba',
            'app.USER_ID' => $this->dueno->id,
            'app.url' => self::API,
            'cors.allowed_origins' => [self::SPA],
            'sanctum.stateful' => ['ferreteria-la-esquin.comerciocity.test'],
            'session.domain' => '.comerciocity.test',
            'session.secure' => true,
            'session.same_site' => 'lax',
        ]);

        PruebaHelper::olvidar_cache();
        PruebaConfig::updateOrCreate(['owner_id' => $this->dueno->id], ['dias' => 7]);
        User::whereKey($this->dueno->id)->update(['expired_at' => Carbon::now()->addDays(5)]);
        PruebaHelper::olvidar_cache();

        $this->withCredentials()->withHeaders(['Origin' => self::SPA, 'Referer' => self::SPA . '/']);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();

        parent::tearDown();
    }

    /** La verificación CSRF de verdad: el framework la saltea cuando corre la suite. */
    private function verificar_csrf_de_verdad(): void
    {
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    private function cookie_de($respuesta, string $nombre): ?Cookie
    {
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $nombre) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return string[] */
    private function nombres_de_cookies($respuesta): array
    {
        return array_map(function (Cookie $cookie) { return $cookie->getName(); }, $respuesta->headers->getCookies());
    }

    private function usar_sesion($respuesta): void
    {
        $cookie = $this->cookie_de($respuesta, config('session.cookie'));
        $this->assertNotNull($cookie, 'La respuesta trae la cookie de sesión.');
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    private function token_de(Cookie $cookie): string
    {
        return CookieValuePrefix::remove($this->app['encrypter']->decrypt($cookie->getValue(), false));
    }

    public function test_el_nombre_es_el_que_calcula_el_spa(): void
    {
        $this->assertSame('XSRF-api_ferreteria_la_esquin_comerciocity_com', VerifyCsrfToken::nombre_de_la_cookie_propia('api-ferreteria-la-esquin.comerciocity.com'));
        $this->assertSame('XSRF-api_prueba_04_comerciocity_com', VerifyCsrfToken::nombre_de_la_cookie_propia('API-Prueba-04.comerciocity.com'));
    }

    public function test_en_una_prueba_vigente_sale_la_cookie_propia_y_no_xsrf_token(): void
    {
        $respuesta = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);

        $this->assertNull($this->cookie_de($respuesta, 'XSRF-TOKEN'), json_encode($this->nombres_de_cookies($respuesta)));
        $propia = $this->cookie_de($respuesta, self::PROPIA);
        $this->assertNotNull($propia, json_encode($this->nombres_de_cookies($respuesta)));

        // Los mismos atributos que el framework le pone a XSRF-TOKEN, y legible por el SPA.
        $this->assertSame('.comerciocity.test', $propia->getDomain());
        $this->assertSame('/', $propia->getPath());
        $this->assertTrue($propia->isSecure());
        $this->assertFalse($propia->isHttpOnly());
        $this->assertSame('lax', $propia->getSameSite());
        $this->assertEqualsWithDelta(time() + 60 * (int) config('session.lifetime'), $propia->getExpiresTime(), 5);

        // Su valor desencriptado es el token de la sesión de ese request.
        $this->assertSame($this->app['session']->driver()->token(), $this->token_de($propia));
    }

    public function test_tambien_en_las_respuestas_de_la_api(): void
    {
        $respuesta = $this->getJson(self::API . '/api/user');

        $this->assertNotNull($this->cookie_de($respuesta, self::PROPIA), json_encode($this->nombres_de_cookies($respuesta)));
        $this->assertNull($this->cookie_de($respuesta, 'XSRF-TOKEN'));
    }

    public function test_una_ruta_web_que_verifica_csrf_acepta_el_token_de_la_cookie_propia(): void
    {
        $this->verificar_csrf_de_verdad();

        $csrf = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);
        $this->usar_sesion($csrf);
        $propia = $this->cookie_de($csrf, self::PROPIA);

        // Control: con un token que no es el de la sesión, la verificación está prendida y rechaza.
        $this->withHeader('X-XSRF-TOKEN', 'otro')->postJson(self::API . '/logout')->assertStatus(419);

        // Con el valor de la cookie propia (como lo manda axios), pasa.
        $this->withHeader('X-XSRF-TOKEN', $propia->getValue());
        $salida = $this->postJson(self::API . '/logout');
        $this->assertNotSame(419, $salida->getStatusCode(), (string) $salida->getContent());

        // Y la autorización de los canales en vivo no queda trabada por CSRF.
        $canal = $this->postJson(self::API . '/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-prueba']);
        $this->assertNotSame(419, $canal->getStatusCode(), (string) $canal->getContent());
    }

    public function test_si_la_prueba_vencio_vuelve_xsrf_token_y_vence_la_propia(): void
    {
        User::whereKey($this->dueno->id)->update(['expired_at' => Carbon::now()->subDay()]);
        PruebaHelper::olvidar_cache();

        $this->withUnencryptedCookie(self::PROPIA, 'vieja');
        $respuesta = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);

        $this->assertNotNull($this->cookie_de($respuesta, 'XSRF-TOKEN'));
        $vencida = $this->cookie_de($respuesta, self::PROPIA);
        $this->assertNotNull($vencida, 'La cookie propia que traía el navegador se vence.');
        $this->assertTrue($vencida->isCleared());
        $this->assertSame('.comerciocity.test', $vencida->getDomain());
        $this->assertSame('/', $vencida->getPath());
    }

    public function test_si_contrato_vuelve_xsrf_token(): void
    {
        // Contratar deja `expired_at` en null (C3) sin borrar `prueba_config`.
        User::whereKey($this->dueno->id)->update(['expired_at' => null]);
        PruebaHelper::olvidar_cache();

        $respuesta = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);

        $this->assertNotNull($this->cookie_de($respuesta, 'XSRF-TOKEN'));
        $this->assertNull($this->cookie_de($respuesta, self::PROPIA));
    }

    public function test_fuera_de_una_prueba_queda_como_siempre(): void
    {
        config(['app.FOR_USER' => null]);
        PruebaHelper::olvidar_cache();

        $respuesta = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);

        // Solo las dos de siempre: ni la propia ni una vencida.
        $nombres = $this->nombres_de_cookies($respuesta);
        sort($nombres);
        $esperados = ['XSRF-TOKEN', config('session.cookie')];
        sort($esperados);
        $this->assertSame($esperados, $nombres);
        $xsrf = $this->cookie_de($respuesta, 'XSRF-TOKEN');
        $this->assertSame('.comerciocity.test', $xsrf->getDomain());
        $this->assertFalse($xsrf->isHttpOnly());
    }
}
