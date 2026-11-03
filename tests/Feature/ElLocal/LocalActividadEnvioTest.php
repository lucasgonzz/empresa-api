<?php

namespace Tests\Feature\ElLocal;

use App\Models\LocalActividadEvento;
use App\Services\LocalActividad;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\ElLocal\Concerns\ConfiguraElLocal;
use Tests\TestCase;

/**
 * La cola de «El local» y su envío al admin (misión el-local-fase-2, 3/11/2026): encolar no toca la red, el lote viaja
 * con la forma exacta del contrato, 200 vacía, 422 descarta y lo demás reintenta con tope.
 *
 * Todo `Http::fake` lleva stub por URL o `'*'`: en Laravel 8 una request sin stub sale a la red de verdad.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class LocalActividadEnvioTest extends TestCase
{
    use DatabaseTransactions;
    use ConfiguraElLocal;

    const CLAVE = 'clave-de-salida-del-test-del-local';
    const DUENO = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurar_el_local();
    }

    /** @test */
    public function registrar_encola_y_no_toca_la_red()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 1], 200)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo', ['a' => 1]);

        Http::assertNothingSent();

        $evento = LocalActividadEvento::first();
        $this->assertSame(1, LocalActividadEvento::count());
        $this->assertSame(self::DUENO, (int) $evento->owner_id);
        $this->assertSame('login', $evento->tipo);
        $this->assertSame('Pablo', $evento->usuario);
        $this->assertSame(['a' => 1], $evento->detalle);
        $this->assertSame(0, $evento->intentos);
    }

    /**
     * Sin URL, sin clave o sin USER_ID no hace nada: ni fila, ni comando, ni ruido en el log.
     *
     * @test
     * @dataProvider configuraciones_incompletas
     */
    public function sin_configuracion_completa_no_hace_nada_ni_loguea($clave_de_config)
    {
        config([$clave_de_config => null]);

        $logs = $this->capturar_logs();

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        $this->assertSame(0, LocalActividadEvento::count());
        $this->assertFalse(LocalActividad::hay_para_enviar());
        $this->assertSame(0, (new LocalActividad())->enviar());
        $this->assertSame([], $logs->all());
    }

    public function configuraciones_incompletas()
    {
        return [
            'sin url'     => ['services.admin_api.url'],
            'sin clave'   => ['services.admin_api.inbound_key'],
            'sin USER_ID' => ['app.USER_ID'],
        ];
    }

    /**
     * 🔴 Base compartida: la clave representa a un solo dueño. Si otro comercio entra por esta instancia, su evento no
     * se encola, porque ninguna instancia lo mandaría nunca.
     *
     * @test
     */
    public function el_evento_de_otro_dueno_no_se_encola()
    {
        LocalActividad::registrar('login', self::DUENO + 1, 'De otro comercio');

        $this->assertSame(0, LocalActividadEvento::count());
    }

    /**
     * Y si ya hay una fila de otro dueño en la base (otra instancia de la misma base), esta instancia no la manda.
     *
     * @test
     */
    public function solo_se_manda_lo_del_dueno_de_esta_instancia()
    {
        $this->fila(['owner_id' => self::DUENO + 1, 'usuario' => 'De otro comercio']);

        $this->assertFalse(LocalActividad::hay_para_enviar());

        $this->fila(['usuario' => 'Pablo']);
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 1], 200)]);

        $this->assertSame(1, (new LocalActividad())->enviar());

        Http::assertSent(function (Request $request) {
            return count($request['eventos']) === 1 && $request['eventos'][0]['usuario'] === 'Pablo';
        });
        $this->assertSame(1, LocalActividadEvento::where('owner_id', self::DUENO + 1)->count());
    }

    /** @test */
    public function un_tipo_que_el_admin_no_acepta_no_se_encola()
    {
        LocalActividad::registrar('inventado', self::DUENO, 'Pablo');

        $this->assertSame(0, LocalActividadEvento::count());
    }

    /**
     * Un `usuario` o un `detalle` de más le daría 422 a todo el lote: se recortan antes de encolar, con el tope que
     * mide el admin.
     *
     * @test
     */
    public function usuario_y_detalle_se_recortan_a_los_topes_del_contrato()
    {
        LocalActividad::registrar('login', self::DUENO, str_repeat('ñ', 200), ['texto' => str_repeat('a', 3000)]);
        LocalActividad::registrar('login', self::DUENO, '   ', []);

        $eventos = LocalActividadEvento::orderBy('id')->get();

        $this->assertSame(120, mb_strlen($eventos[0]->usuario));
        $this->assertSame(['recortado' => true], $eventos[0]->detalle);
        $this->assertLessThanOrEqual(LocalActividad::TOPE_DETALLE_BYTES, strlen(json_encode($eventos[0]->detalle)));

        $this->assertNull($eventos[1]->usuario);
        $this->assertNull($eventos[1]->detalle);
    }

    /** @test */
    public function el_lote_viaja_con_la_forma_exacta_del_contrato_y_el_200_vacia_la_cola()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 2], 200)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');
        LocalActividad::registrar('escaneo', self::DUENO, null, ['scan_id' => 9]);

        $this->assertTrue(LocalActividad::hay_para_enviar());
        $this->assertSame(2, (new LocalActividad())->enviar());

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            $eventos = $request['eventos'];

            return $request->url() === 'https://admin.test/public/api/inbound/local/actividad'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Admin-Api-Key', self::CLAVE)
                && $request->hasHeader('Accept', 'application/json')
                && array_keys($request->data()) === ['eventos']
                && array_keys($eventos[0]) === ['tipo', 'ocurrido_at', 'usuario', 'detalle']
                && $eventos[0]['tipo'] === 'login'
                && $eventos[0]['usuario'] === 'Pablo'
                && $eventos[0]['detalle'] === null
                && $eventos[1]['tipo'] === 'escaneo'
                && $eventos[1]['usuario'] === null
                && $eventos[1]['detalle'] === ['scan_id' => 9]
                // ISO-8601 con zona: Buenos Aires es -03:00.
                && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-03:00$/', $eventos[0]['ocurrido_at']) === 1;
        });

        $this->assertSame(0, LocalActividadEvento::count());
        $this->assertFalse(LocalActividad::hay_para_enviar());
    }

    /**
     * Hay clientes con ADMIN_API_URL cargada con `/api` al final: no puede quedar `/api/api`.
     *
     * @test
     */
    public function la_url_con_api_al_final_no_duplica_el_prefijo()
    {
        config(['services.admin_api.url' => 'https://admin.test/public/api/']);
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 1], 200)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');
        (new LocalActividad())->enviar();

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://admin.test/public/api/inbound/local/actividad';
        });
    }

    /**
     * El tope del contrato: un POST lleva hasta 100, los más viejos primero, y el resto queda para la próxima corrida.
     *
     * @test
     */
    public function un_lote_lleva_100_como_maximo_y_el_resto_queda_para_la_proxima_corrida()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 100], 200)]);

        for ($i = 0; $i < 101; $i++) {
            $this->fila(['usuario' => 'Persona ' . $i]);
        }

        $this->assertSame(100, (new LocalActividad())->enviar());

        Http::assertSent(function (Request $request) {
            return count($request['eventos']) === 100 && $request['eventos'][0]['usuario'] === 'Persona 0';
        });

        $restante = LocalActividadEvento::get();
        $this->assertCount(1, $restante);
        $this->assertSame('Persona 100', $restante[0]->usuario);
        $this->assertTrue(LocalActividad::hay_para_enviar(), 'Un envío que anduvo no pausa la cola.');
    }

    /**
     * 422: el lote está mal y reintentarlo daría lo mismo. Se descarta, sin pausa.
     *
     * @test
     */
    public function el_422_descarta_el_lote_sin_reintentar()
    {
        Http::fake(['*' => Http::response(['error' => 'no válido'], 422)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        $this->assertSame(0, (new LocalActividad())->enviar());

        $this->assertSame(0, LocalActividadEvento::count());
        $this->assertFalse(Cache::has(LocalActividad::CLAVE_PAUSA));
    }

    /**
     * @test
     * @dataProvider respuestas_que_se_reintentan
     */
    public function lo_que_no_es_200_ni_422_conserva_el_lote_y_pausa_la_cola($status, $cuerpo)
    {
        Http::fake(['*' => Http::response($cuerpo, $status)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        $this->assertSame(0, (new LocalActividad())->enviar());

        $evento = LocalActividadEvento::first();
        $this->assertNotNull($evento, 'El lote se conserva para reintentar.');
        $this->assertSame(1, $evento->intentos);
        $this->assertTrue(Cache::has(LocalActividad::CLAVE_PAUSA));
        $this->assertFalse(LocalActividad::hay_para_enviar(), 'En pausa, el scheduler no arranca un proceso.');
    }

    public function respuestas_que_se_reintentan()
    {
        return [
            '500'                 => [500, ['message' => 'error']],
            '503'                 => [503, 'Service Unavailable'],
            '401 clave mala'      => [401, ['message' => 'La credencial del negocio no es válida.']],
            '404 admin sin ruta'  => [404, ['message' => 'Not Found']],
            '429'                 => [429, ['message' => 'Too Many Attempts.']],
            '200 de otro servidor' => [200, '<html>hosting</html>'],
            '200 sin ok'          => [200, ['ok' => false]],
        ];
    }

    /** @test */
    public function sin_red_conserva_el_lote_y_no_tira_la_excepcion()
    {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out');
        });

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        $this->assertSame(0, (new LocalActividad())->enviar());

        $this->assertSame(1, LocalActividadEvento::first()->intentos);
        $this->assertTrue(Cache::has(LocalActividad::CLAVE_PAUSA));
    }

    /**
     * El tope: un admin caído o una clave mal cargada no pueden hacer crecer la cola para siempre.
     *
     * @test
     */
    public function al_llegar_al_tope_de_intentos_el_lote_se_descarta()
    {
        Http::fake(['*' => Http::response(['message' => 'error'], 500)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        for ($i = 1; $i < LocalActividad::TOPE_INTENTOS; $i++) {
            Cache::forget(LocalActividad::CLAVE_PAUSA);
            (new LocalActividad())->enviar();
            $this->assertSame($i, LocalActividadEvento::first()->intentos);
        }

        Cache::forget(LocalActividad::CLAVE_PAUSA);
        (new LocalActividad())->enviar();

        $this->assertSame(0, LocalActividadEvento::count());
    }

    /**
     * La pausa crece con los intentos y tiene techo.
     *
     * @test
     */
    public function la_pausa_crece_con_los_intentos_y_tiene_techo()
    {
        Http::fake(['*' => Http::response(['message' => 'error'], 500)]);

        $this->fila(['intentos' => 2]);

        $inicio = now();
        \Carbon\Carbon::setTestNow($inicio);

        (new LocalActividad())->enviar();

        \Carbon\Carbon::setTestNow($inicio->copy()->addMinutes(3));
        $this->assertTrue(Cache::has(LocalActividad::CLAVE_PAUSA), 'Tercer intento: 4 minutos de pausa.');

        \Carbon\Carbon::setTestNow($inicio->copy()->addMinutes(5));
        $this->assertFalse(Cache::has(LocalActividad::CLAVE_PAUSA));

        LocalActividadEvento::query()->update(['intentos' => 6]);
        (new LocalActividad())->enviar();

        \Carbon\Carbon::setTestNow($inicio->copy()->addMinutes(5 + 29));
        $this->assertTrue(Cache::has(LocalActividad::CLAVE_PAUSA), 'Séptimo intento: el techo de 30 minutos.');

        \Carbon\Carbon::setTestNow($inicio->copy()->addMinutes(5 + 31));
        $this->assertFalse(Cache::has(LocalActividad::CLAVE_PAUSA));

        \Carbon\Carbon::setTestNow();
    }

    /**
     * Si falla, lo que ya no le sirve a la escena se descarta, aunque no haya llegado al tope.
     *
     * @test
     */
    public function al_fallar_se_descarta_lo_de_mas_de_24_horas()
    {
        Http::fake(['*' => Http::response(['message' => 'error'], 500)]);

        for ($i = 0; $i < 100; $i++) {
            $this->fila();
        }
        $this->fila(['usuario' => 'Viejo fuera del lote', 'ocurrido_at' => now()->subHours(25)]);

        (new LocalActividad())->enviar();

        $this->assertSame(100, LocalActividadEvento::count());
        $this->assertSame(0, LocalActividadEvento::where('usuario', 'Viejo fuera del lote')->count());
    }

    /**
     * El comando programado usa el mismo servicio, y el scheduler lo tiene cada minuto.
     *
     * @test
     */
    public function el_comando_manda_la_cola_y_esta_programado()
    {
        Http::fake(['*' => Http::response(['ok' => true, 'guardados' => 1], 200)]);

        LocalActividad::registrar('login', self::DUENO, 'Pablo');

        $this->artisan('local:enviar-actividad')->assertExitCode(0);

        $this->assertSame(0, LocalActividadEvento::count());

        $programados = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(function ($evento) {
            return strpos((string) $evento->command, 'local:enviar-actividad') !== false;
        });

        $this->assertCount(1, $programados);
        $this->assertSame('* * * * *', $programados->first()->expression);
    }

    /**
     * Crea una fila directo, para armar la cola de un test.
     *
     * @param  array $datos
     * @return LocalActividadEvento
     */
    private function fila(array $datos = [])
    {
        return LocalActividadEvento::create(array_merge([
            'owner_id'    => self::DUENO,
            'tipo'        => 'actividad',
            'usuario'     => 'Pablo',
            'ocurrido_at' => now(),
        ], $datos));
    }
}
