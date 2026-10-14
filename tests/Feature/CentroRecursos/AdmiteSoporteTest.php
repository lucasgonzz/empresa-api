<?php

namespace Tests\Feature\CentroRecursos;

use App\Http\Controllers\Helpers\CentroRecursosDisponibilidadHelper as Disponibilidad;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as PedidoHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * `CentroRecursosDisponibilidadHelper::admite_soporte()` — ¿la página del centro de recursos sabe
 * abrir una sesión de solo lectura? (misión centro-recursos-soporte-y-tema, 14/10/2026, contrato C4
 * ampliado: `recursos-estado.json` pasa de `{"ok": true}` a `{"ok": true, "soporte": true}`).
 *
 * 🔴 QUÉ PROTEGE ESTE ARCHIVO.
 *  - `soporte` solo vale como `true` ESTRICTO y solo si la página además anda. Cualquier otra cosa
 *    (campo ausente, `false`, `"true"`, `ok: false`, página caída) es "no admite": el login maestro
 *    sigue abriendo como invitado, que es lo que garantiza que una página vieja no reciba pases de
 *    soporte que no sabe respetar.
 *  - Que `disponible()` NO cambió: ni su firma ni su resultado, con o sin el campo nuevo.
 *  - La política de cache: una sola consulta llena las dos claves, y si la de soporte falta (cache
 *    de antes del deploy) se vuelve a consultar.
 *
 * Nada sale a internet: la página siempre está falseada con `Http::fake()`. Los fakes de más de una
 * respuesta usan un callback sobre una variable y no `Http::sequence()`: una secuencia agotada da
 * verde falso (la última respuesta se repite), y con `assertSentCount` se comprueba que cada
 * consulta realmente se hizo.
 *
 * @group centro-recursos
 */
class AdmiteSoporteTest extends EmpresaTestCase
{
    /** El archivo de estado que publica la página (contrato C4). */
    const ESTADO = 'https://recursos.comerciocity.com/recursos-estado.json';

    /** @var mixed Lo que contesta la página en este momento (un array JSON, o un texto). */
    protected $cuerpo;

    /** @var int Status con el que contesta la página. */
    protected $status = 200;

    /** @var bool true = la página no contesta (sin DNS, timeout): la consulta tira ConnectionException. */
    protected $sin_respuesta = false;

    /** @var int Cuántas veces se le pegó a la página (cuenta también las que tiran: Http::assertSent no las registra). */
    protected $intentos = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 10, 14, 10, 0, 0));

        Cache::flush();

        $this->cuerpo = ['ok' => true, 'soporte' => true];
        $this->status = 200;
        $this->sin_respuesta = false;
        $this->intentos = 0;

        // Un solo fake para todo el test (el primero que coincide gana: no se vuelve a falsear):
        // lo que contesta se cambia con $this->cuerpo / $this->status / $this->sin_respuesta.
        Http::fake(function (PedidoHttp $pedido) {
            $this->intentos++;

            if ($this->sin_respuesta) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response($this->cuerpo, $this->status);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Con `{"ok": true, "soporte": true}` admite soporte, y la misma consulta deja resuelta también
     * la disponibilidad: no hay una segunda consulta para `disponible()`.
     */
    public function test_con_soporte_true_admite_y_una_sola_consulta_llena_las_dos_claves()
    {
        $this->assertTrue(Disponibilidad::admite_soporte());

        Http::assertSentCount(1);
        Http::assertSent(function (PedidoHttp $pedido) {
            return $pedido->method() === 'GET' && $pedido->url() === self::ESTADO;
        });

        $this->assertTrue(Disponibilidad::disponible(), 'La consulta de soporte dejó la disponibilidad en el cache.');
        $this->assertTrue(Disponibilidad::admite_soporte());

        Http::assertSentCount(1);
    }

    /**
     * Al revés: preguntar primero por `disponible()` deja resuelto el soporte (es lo que pasa en
     * `pase()`, que chequea la disponibilidad antes de mirar si hay login maestro).
     */
    public function test_disponible_primero_deja_resuelto_el_soporte()
    {
        $this->assertTrue(Disponibilidad::disponible());
        $this->assertTrue(Disponibilidad::admite_soporte());

        Http::assertSentCount(1);
    }

    /**
     * Una página vieja (`{"ok": true}` sin el campo): no admite soporte, y `disponible()` sigue
     * dando true exactamente como antes.
     */
    public function test_sin_el_campo_soporte_no_admite_y_disponible_no_cambia()
    {
        $this->cuerpo = ['ok' => true];

        $this->assertFalse(Disponibilidad::admite_soporte());
        $this->assertTrue(Disponibilidad::disponible(), 'disponible() no depende de `soporte`.');

        Http::assertSentCount(1);
    }

    /**
     * `soporte` solo cuenta como el booleano `true`: false, el texto "true", un 1, un null o un
     * arreglo no activan el modo de solo lectura.
     */
    public function test_solo_el_true_estricto_cuenta_como_soporte()
    {
        $consultas = 0;

        foreach ([false, 'true', 1, '1', null, [true]] as $valor) {
            Cache::flush();

            $this->cuerpo = ['ok' => true, 'soporte' => $valor];

            $this->assertFalse(Disponibilidad::admite_soporte(), 'No debería admitir con soporte = ' . json_encode($valor));
            $this->assertTrue(Disponibilidad::disponible(), 'La página igual anda.');

            $consultas++;
        }

        Http::assertSentCount($consultas);
    }

    /**
     * `{"ok": false, "soporte": true}`: no anda, entonces no admite nada.
     */
    public function test_con_ok_false_no_admite_aunque_diga_soporte()
    {
        $this->cuerpo = ['ok' => false, 'soporte' => true];

        $this->assertFalse(Disponibilidad::admite_soporte());
        $this->assertFalse(Disponibilidad::disponible());

        Http::assertSentCount(1);
    }

    /**
     * Una respuesta que no es JSON (una página de estacionamiento con 200) o un 500: no admite.
     */
    public function test_con_html_o_con_un_error_de_la_pagina_no_admite()
    {
        $this->cuerpo = '<html><body>Dominio estacionado</body></html>';
        $this->assertFalse(Disponibilidad::admite_soporte());
        $this->assertFalse(Disponibilidad::disponible());

        Cache::flush();

        $this->cuerpo = ['ok' => true, 'soporte' => true];
        $this->status = 500;
        $this->assertFalse(Disponibilidad::admite_soporte(), 'Un 500 no es soporte aunque el cuerpo lo diga.');
        $this->assertFalse(Disponibilidad::disponible());

        Http::assertSentCount(2);
    }

    /**
     * Si la página no contesta (sin DNS, timeout), no admite y no revienta.
     */
    public function test_si_la_pagina_no_contesta_no_admite_y_no_revienta()
    {
        $this->sin_respuesta = true;

        $this->assertFalse(Disponibilidad::admite_soporte());
        $this->assertFalse(Disponibilidad::disponible());

        // Una sola consulta (la que tiró): el "no anda" también se recuerda.
        $this->assertSame(1, $this->intentos);
    }

    /**
     * Si admite, se recuerda 10 minutos: a los 9 no se vuelve a consultar, a los 11 sí.
     */
    public function test_si_admite_el_resultado_se_recuerda_diez_minutos()
    {
        $this->assertTrue(Disponibilidad::admite_soporte());
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(9));
        $this->assertTrue(Disponibilidad::admite_soporte());
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertTrue(Disponibilidad::admite_soporte());
        Http::assertSentCount(2);
    }

    /**
     * Si NO admite se recuerda 2 minutos: al minuto no se vuelve a consultar, a los 3 sí. Es lo que
     * hace que, apenas se publica la página nueva, el login maestro lo note en 2 minutos y no en 10.
     * Se prueba el cambio de verdad: la página vieja pasa a la nueva entre dos consultas.
     */
    public function test_si_no_admite_se_recuerda_dos_minutos_y_se_nota_la_pagina_nueva()
    {
        $this->cuerpo = ['ok' => true];

        $this->assertFalse(Disponibilidad::admite_soporte());
        Http::assertSentCount(1);

        // Se publica la página nueva.
        $this->cuerpo = ['ok' => true, 'soporte' => true];

        Carbon::setTestNow(Carbon::now()->addMinute());
        $this->assertFalse(Disponibilidad::admite_soporte(), 'Al minuto todavía se recuerda lo anterior.');
        Http::assertSentCount(1);

        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->assertTrue(Disponibilidad::admite_soporte(), 'A los 3 minutos ya vio la página nueva.');
        Http::assertSentCount(2);
    }

    /**
     * Cache de antes del deploy: solo está la clave de disponibilidad. `disponible()` la usa tal cual
     * (sin consultar), y `admite_soporte()` —que no encuentra la suya— consulta de nuevo y deja las
     * dos al día.
     */
    public function test_con_el_cache_de_antes_del_deploy_consulta_de_nuevo_el_soporte()
    {
        Cache::put('centro_recursos:disponible:' . md5('https://recursos.comerciocity.com'), 1, 600);

        $this->assertTrue(Disponibilidad::disponible());
        Http::assertNothingSent();

        $this->assertTrue(Disponibilidad::admite_soporte());
        Http::assertSentCount(1);

        // Ahora están las dos: ni una ni otra vuelven a consultar.
        $this->assertTrue(Disponibilidad::disponible());
        $this->assertTrue(Disponibilidad::admite_soporte());
        Http::assertSentCount(1);
    }

    /**
     * La clave lleva la URL de la página: si se cambia la config, no se arrastra el soporte de la otra.
     */
    public function test_la_clave_de_soporte_sigue_a_la_url_de_la_config()
    {
        $this->assertTrue(Disponibilidad::admite_soporte());

        config(['services.centro_recursos.url' => 'https://otra-pagina.test/']);

        $this->cuerpo = ['ok' => true];

        $this->assertFalse(Disponibilidad::admite_soporte(), 'La otra página no hereda el soporte de la primera.');

        Http::assertSentCount(2);
        Http::assertSent(function (PedidoHttp $pedido) {
            return $pedido->url() === 'https://otra-pagina.test/recursos-estado.json';
        });
    }

    /**
     * `disponible()` conserva su firma pública: sin parámetros y devuelve un booleano. El resto del
     * sistema (y el contrato C1) lo llama así.
     */
    public function test_disponible_conserva_su_firma()
    {
        $metodo = new \ReflectionMethod(Disponibilidad::class, 'disponible');

        $this->assertTrue($metodo->isPublic());
        $this->assertTrue($metodo->isStatic());
        $this->assertSame(0, $metodo->getNumberOfParameters());

        $this->assertIsBool(Disponibilidad::disponible());
    }
}
