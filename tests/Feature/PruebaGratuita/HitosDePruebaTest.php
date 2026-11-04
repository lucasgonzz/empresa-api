<?php

namespace Tests\Feature\PruebaGratuita;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use App\Http\Controllers\Helpers\PruebaHelper;
use App\Http\Controllers\Helpers\PruebaHitosHelper;
use App\Jobs\RegistrarHitoDePruebaJob;
use App\Models\DemoEvento;
use App\Models\PruebaHito;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Los hitos de la prueba gratuita hacia el admin (C2, misión prueba-experiencia-empresa, 3/11/2026).
 *
 *  1. Fuera de una instancia de prueba, `emitir()` no encola nada (cero costo en un cliente real).
 *  2. En una prueba, `emitir()` encola RegistrarHitoDePruebaJob; nada pasa en el request.
 *  3. El job registra cada tipo UNA vez (`prueba_hitos`) y escribe la fila de `demo_eventos` solo la
 *     primera vez; `prueba.ingreso` se emite todas las veces.
 *  4. El push al admin tiene la forma del canal de siempre: POST a `eventos_url` con
 *     `X-Demo-Eventos-Key` y `{eventos: [{uuid, nombre, clip_id, ocurrido_at, datos}]}`.
 */
class HitosDePruebaTest extends EmpresaTestCase
{
    const TOKEN = 'token-de-eventos-del-test-de-hitos-9f2a';
    const URL = 'https://admin.test.local/api/demo-eventos';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        // El modo prueba existe solo con FOR_USER=prueba (lo escribe el script de alta del admin).
        config(['app.FOR_USER' => 'prueba']);

        PruebaHelper::olvidar_cache();
        DemoTrackingConfigHelper::olvidar_cache();

        $this->dueno = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
        config(['app.USER_ID' => $this->dueno->id]);
    }

    protected function tearDown(): void
    {
        PruebaHelper::olvidar_cache();
        DemoTrackingConfigHelper::olvidar_cache();
        parent::tearDown();
    }

    private function prender_la_prueba()
    {
        PruebaHelper::activar_desde_payload($this->dueno, ['dias' => 7, 'whatsapp_soporte' => '+5493444622139']);
    }

    private function abrir_el_canal()
    {
        DemoTrackingConfigHelper::guardar(self::TOKEN, self::URL, null, null);
        DemoTrackingConfigHelper::olvidar_cache();
        $this->assertTrue(DemoTrackingConfigHelper::hay_canal());
    }

    public function test_fuera_de_una_instancia_de_prueba_no_se_encola_nada()
    {
        config(['app.env' => 'production', 'app.FOR_USER' => null]);
        Queue::fake();

        $this->assertFalse(PruebaHitosHelper::emitir('prueba.venta', ['id' => 1], $this->dueno->id));

        Queue::assertNothingPushed();
    }

    public function test_en_una_prueba_emitir_encola_el_job_y_no_escribe_nada_en_el_request()
    {
        $this->prender_la_prueba();
        Queue::fake();

        $this->assertTrue(PruebaHitosHelper::emitir('prueba.venta', ['id' => 77]));

        Queue::assertPushed(RegistrarHitoDePruebaJob::class, function ($job) {
            return $job->tipo === 'prueba.venta' && $job->datos === ['id' => 77] && $job->owner_id === $this->dueno->id;
        });
        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count(), 'El request no escribe el hito: lo hace el job.');
    }

    public function test_un_tipo_desconocido_se_descarta()
    {
        $this->prender_la_prueba();
        Queue::fake();

        $this->assertFalse(PruebaHitosHelper::emitir('prueba.inventado', [], $this->dueno->id));

        Queue::assertNothingPushed();
    }

    public function test_sin_config_de_prueba_el_job_no_registra_nada()
    {
        $this->abrir_el_canal();

        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.venta', ['id' => 1], Carbon::now()))->handle();

        $this->assertSame(0, PruebaHito::where('owner_id', $this->dueno->id)->count());
        $this->assertSame(0, DemoEvento::where('nombre', 'prueba.venta')->count());
    }

    public function test_cada_tipo_se_registra_y_se_emite_una_sola_vez()
    {
        $this->prender_la_prueba();
        $this->abrir_el_canal();
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);

        $primera = Carbon::parse('2026-11-04 10:00:00');
        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.venta', ['id' => 1], $primera))->handle();
        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.venta', ['id' => 2], Carbon::parse('2026-11-04 11:00:00')))->handle();

        $hitos = PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.venta')->get();
        $this->assertCount(1, $hitos);
        $this->assertSame('2026-11-04 10:00:00', $hitos->first()->ocurrido_at->format('Y-m-d H:i:s'), 'Queda el PRIMER momento.');

        $eventos = DemoEvento::where('nombre', 'prueba.venta')->get();
        $this->assertCount(1, $eventos, 'La segunda venta no vuelve a emitir.');
        $this->assertSame(['id' => 1], $eventos->first()->datos);
    }

    public function test_prueba_ingreso_se_emite_todas_las_veces_pero_el_hito_queda_con_la_primera()
    {
        $this->prender_la_prueba();
        $this->abrir_el_canal();
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);

        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.ingreso', ['user_id' => $this->dueno->id, 'via' => 'login'], Carbon::parse('2026-11-04 10:00:00')))->handle();
        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.ingreso', ['user_id' => $this->dueno->id, 'via' => 'login'], Carbon::parse('2026-11-05 10:00:00')))->handle();

        $this->assertSame(1, PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.ingreso')->count());
        $this->assertSame(2, DemoEvento::where('nombre', 'prueba.ingreso')->count());
    }

    public function test_el_push_al_admin_tiene_la_forma_del_canal_de_siempre()
    {
        $this->prender_la_prueba();
        $this->abrir_el_canal();
        Http::fake([self::URL => Http::response(['ok' => true], 200)]);

        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.importo', ['origen' => 'excel', 'import_history_id' => 5], Carbon::parse('2026-11-04T10:00:00-03:00')))->handle();

        Http::assertSent(function ($request) {
            $eventos = $request['eventos'];
            $evento = $eventos[0];

            return $request->url() === self::URL
                && $request->hasHeader('X-Demo-Eventos-Key', self::TOKEN)
                && count($eventos) === 1
                && $evento['nombre'] === 'prueba.importo'
                && is_null($evento['clip_id'])
                && $evento['datos'] === ['origen' => 'excel', 'import_history_id' => 5]
                && strlen((string) $evento['uuid']) === 36
                && is_string($evento['ocurrido_at']);
        });

        $this->assertNotNull(DemoEvento::where('nombre', 'prueba.importo')->value('sincronizado_at'), 'Con 200 del admin, el evento queda sincronizado.');
    }

    public function test_sin_canal_el_hito_queda_registrado_igual_pero_no_se_emite()
    {
        $this->prender_la_prueba();
        Http::fake();

        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.gasto', ['id' => 3], Carbon::now()))->handle();

        $this->assertSame(1, PruebaHito::where('owner_id', $this->dueno->id)->where('tipo', 'prueba.gasto')->count());
        $this->assertSame(0, DemoEvento::where('nombre', 'prueba.gasto')->count());
        Http::assertNothingSent();
    }

    public function test_el_estado_para_el_admin_trae_los_once_tipos_con_su_primer_momento()
    {
        $this->prender_la_prueba();

        (new RegistrarHitoDePruebaJob($this->dueno->id, 'prueba.cliente_creado', ['id' => 9], Carbon::parse('2026-11-04T10:00:00-03:00')))->handle();

        $estado = PruebaHelper::estado_para_admin($this->dueno->id);

        $this->assertCount(11, $estado['hitos']);
        $this->assertSame('2026-11-04T10:00:00-03:00', $estado['hitos']['prueba.cliente_creado']);
        $this->assertNull($estado['hitos']['prueba.venta']);
        $this->assertTrue($estado['activa']);
    }
}
