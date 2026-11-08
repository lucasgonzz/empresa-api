<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Helpers\AgentesIaHelper;
use App\Models\User;
use App\Services\VinculacionMaestra;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VinculacionMaestraTest extends TestCase
{
    use DatabaseTransactions;

    private const API = 'https://api-ferreguay.test';
    private const SPA = 'https://ferreguay.test';
    private const INICIO = self::API . '/asistente-maestro/vinculacion';
    private const CONFIG = 'acceso_soporte.asistente_maestro_password_hash';

    private $dueno;
    private $cache_prueba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = User::whereNull('owner_id')->firstOrFail();
        User::whereNull('owner_id')->where('id', '!=', $this->dueno->id)->update(['owner_id' => $this->dueno->id]);
        AgentesIaHelper::fijar($this->dueno, true);

        $this->cache_prueba = storage_path('framework/testing/vinculacion-maestra-' . bin2hex(random_bytes(8)));
        config([
            self::CONFIG => password_hash('clave-maestra-de-prueba', PASSWORD_BCRYPT, ['cost' => 4]),
            'acceso_soporte.asistente_maestro_origen' => self::SPA,
            'cache.stores.file.path' => $this->cache_prueba,
            'app.url' => self::API,
            'cors.allowed_origins' => [self::SPA],
            'sanctum.stateful' => ['ferreguay.test'],
            'session.domain' => '.ferreguay.test',
        ]);

        $this->withCredentials()->withHeaders(['Origin' => self::SPA, 'Referer' => self::SPA . '/']);
    }

    protected function tearDown(): void
    {
        if ($this->cache_prueba && is_dir($this->cache_prueba)) {
            Cache::store('file')->flush();
            @rmdir($this->cache_prueba);
        }

        parent::tearDown();
    }

    public function test_solo_el_navegador_aprobado_recibe_confianza_y_puede_volver_a_ingresar(): void
    {
        $inicio = $this->postJson(self::INICIO)->assertOk();
        $codigo = $inicio->json('codigo');
        $this->postJson(self::INICIO . '/canjear')->assertStatus(410);
        $this->usar_sesion($inicio);
        $this->postJson(self::INICIO . '/canjear')->assertStatus(409);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}(?:-[A-F0-9]{4}){2}$/D', $codigo);
        $this->artisano_aprueba($codigo);
        $this->artisano_aprueba($codigo, 1);

        $this->unencryptedCookies = [];
        $this->postJson(self::INICIO . '/canjear', ['codigo' => $codigo])->assertStatus(410);
        $this->usar_sesion($inicio);
        $canje = $this->postJson(self::INICIO . '/canjear')->assertOk()->assertExactJson(['vinculado' => true]);
        $cookie = $this->cookie_de($canje, VinculacionMaestra::COOKIE);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('/', $cookie->getPath());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertStringNotContainsString($codigo, $cookie->getValue());
        $this->usar_sesion($canje);
        $this->withUnencryptedCookie(VinculacionMaestra::COOKIE, $cookie->getValue());
        $this->postJson(self::INICIO . '/canjear')->assertStatus(410);

        $this->ingresar()->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->obtener_csrf();
        $this->ingresar()->assertJsonPath('user.asistente_personal_autorizado', true);
        $this->getJson(self::API . '/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', true);

        $salida = $this->postJson(self::API . '/logout')->assertOk();
        $this->usar_sesion($salida);
        $this->obtener_csrf();
        $this->ingresar()->assertJsonPath('user.asistente_personal_autorizado', true);

        unset($this->unencryptedCookies[VinculacionMaestra::COOKIE]);
        $this->getJson(self::API . '/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', false);
    }

    public function test_la_confianza_expira_y_no_se_acepta_con_otro_origen_dueño_o_hash(): void
    {
        $inicio = $this->postJson(self::INICIO)->assertOk();
        $this->usar_sesion($inicio);
        $this->artisano_aprueba($inicio->json('codigo'));
        $canje = $this->postJson(self::INICIO . '/canjear')->assertOk();
        $this->usar_sesion($canje);
        $this->withUnencryptedCookie(VinculacionMaestra::COOKIE, $this->cookie_de($canje, VinculacionMaestra::COOKIE)->getValue());
        $this->obtener_csrf();

        $this->withHeader('Origin', 'https://otro.ferreguay.test');
        $this->ingresar()->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->withHeader('Origin', self::SPA);

        $otro = User::factory()->create(['owner_id' => null, 'doc_number' => '99876541']);
        $this->ingresar($otro->doc_number . ' login')->assertJsonPath('user.asistente_personal_autorizado', false);
        $comando = $this->dueno->doc_number . ' login';
        $this->ingresar($comando)->assertJsonPath('user.asistente_personal_autorizado', true);

        $this->travel(31)->days();
        $this->ingresar($comando)->assertJsonPath('user.asistente_personal_autorizado', false);
        $this->travelBack();

        $this->ingresar($comando)->assertJsonPath('user.asistente_personal_autorizado', true);
        config([self::CONFIG => password_hash('otra-clave-maestra', PASSWORD_BCRYPT, ['cost' => 4])]);
        $this->getJson(self::API . '/api/user')->assertOk()->assertJsonPath('user.asistente_personal_autorizado', false);
    }

    public function test_no_se_canjea_un_desafio_vencido_y_la_ruta_se_apaga_sin_configuracion(): void
    {
        $inicio = $this->postJson(self::INICIO)->assertOk();
        $this->usar_sesion($inicio);
        $this->artisano_aprueba($inicio->json('codigo'));
        $this->travel(11)->minutes();
        $this->postJson(self::INICIO . '/canjear')->assertStatus(410);
        $this->travelBack();

        $this->withHeader('Origin', 'https://otro.ferreguay.test');
        $this->postJson(self::INICIO)->assertNotFound();
        $this->withHeader('Origin', self::SPA);

        config([self::CONFIG => '']);
        $this->postJson(self::INICIO)->assertNotFound();
    }

    private function ingresar(string $comando = 'login')
    {
        $respuesta = $this->postJson(self::API . '/login', ['doc_number' => $comando, 'password' => '1234'])
            ->assertOk()->assertJsonPath('login', true);
        $this->usar_sesion($respuesta);

        return $respuesta;
    }

    private function artisano_aprueba(string $codigo, int $esperado = 0): void
    {
        $this->artisan('asistente:autorizar-navegador', ['codigo' => $codigo, '--dueno' => $this->dueno->id])
            ->assertExitCode($esperado);
    }

    private function obtener_csrf(): void
    {
        $respuesta = $this->get(self::API . '/sanctum/csrf-cookie')->assertStatus(204);
        $this->usar_sesion($respuesta);
        $this->withHeader('X-XSRF-TOKEN', $this->cookie_de($respuesta, 'XSRF-TOKEN')->getValue());
    }

    private function usar_sesion($respuesta): void
    {
        $cookie = $this->cookie_de($respuesta, config('session.cookie'));
        $this->app['auth']->forgetGuards();
        $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
    }

    private function cookie_de($respuesta, string $nombre)
    {
        foreach ($respuesta->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $nombre) {
                return $cookie;
            }
        }

        $this->fail('Faltó la cookie ' . $nombre);
    }
}
