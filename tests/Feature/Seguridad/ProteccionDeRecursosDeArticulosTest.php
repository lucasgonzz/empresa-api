<?php

namespace Tests\Feature\Seguridad;

use App\Http\Controllers\ArticleController;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class ProteccionDeRecursosDeArticulosTest extends TestCase
{
    use DatabaseTransactions;

    private $dueno;
    private $controller;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 10:01:00'));
        $this->dueno = $this->persona();
        $this->actingAs($this->dueno, 'web');
        // Las rutas y toda su cadena de autenticación son reales. Solo reemplazamos el trabajo
        // del controller: permite medir admisión sin alterar stock ni pagar el recorrido costoso.
        $this->controller = Mockery::mock(ArticleController::class)->makePartial();
        $this->controller->shouldReceive('show')->andReturn(response()->json(['admitido' => true]));
        $this->controller->shouldReceive('update')->andReturn(response()->json(['admitido' => true]));
        $this->controller->shouldReceive('update_addresses_stock')->andReturn(response()->json(['admitido' => true]));
        $this->controller->shouldReceive('update_variants_stock')->andReturn(response()->json(['admitido' => true]));
        $this->app->instance(ArticleController::class, $this->controller);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function persona($dueno = null)
    {
        return User::create([
            'name' => 'Prueba de recursos', 'email' => uniqid() . '@test.local',
            'password' => 'prueba-no-utilizable', 'owner_id' => $dueno,
        ]);
    }

    private function pedido($metodo = 'GET', $uri = 'api/article/1')
    {
        return $this->json($metodo, $uri);
    }

    private function cambiar_persona(User $persona)
    {
        // El RequestGuard Sanctum conserva al primer usuario entre requests de un test.
        // Un proceso PHP real vuelve a resolverlo; reproducimos ese límite entre clientes.
        $this->app['auth']->forgetGuards();
        $this->actingAs($persona, 'web');
    }

    public function test_el_guardado_normal_y_su_recarga_siguen_admitidos()
    {
        $this->pedido()->assertStatus(200)->assertJsonPath('admitido', true);
        $this->pedido('PUT', 'api/article-update-addresses')->assertStatus(200);
        $this->pedido()->assertStatus(200);
    }

    public function test_las_dos_escrituras_y_patch_comparten_el_cupo_personal()
    {
        config(['recursos_articulos.escritura.persona_minuto' => 2]);
        $this->pedido('PUT', 'api/article/1')->assertStatus(200);
        $this->pedido('PUT', 'api/article-update-addresses')->assertStatus(200);
        $this->pedido('PATCH', 'api/article/1e0')->assertStatus(429)->assertHeader('Retry-After', '60');
        // Los contadores de lectura son independientes de escritura.
        $this->pedido('GET', 'api/article/1.0')->assertStatus(200);
    }

    public function test_las_variantes_comparten_el_cupo_de_escrituras_sin_impedir_su_guardado_normal()
    {
        config(['recursos_articulos.escritura.persona_minuto' => 2]);
        $this->pedido('PUT', 'api/article-update-varians-stock')->assertStatus(200);
        $this->pedido('PUT', 'api/article-update-addresses')->assertStatus(200);
        $this->pedido('PUT', 'api/article/1')->assertStatus(429);
        $this->pedido()->assertStatus(200);
    }

    public function test_la_ventana_horaria_impide_un_recorrido_sostenido()
    {
        config(['recursos_articulos.lectura.persona_minuto' => 2, 'recursos_articulos.lectura.persona_hora' => 2]);
        $this->pedido()->assertStatus(200);
        Carbon::setTestNow(now()->addMinute());
        $this->pedido()->assertStatus(200);
        Carbon::setTestNow(now()->addMinute());
        $this->pedido()->assertStatus(429)->assertHeader('Retry-After', '3420');
        Carbon::setTestNow(now()->addHour());
        $this->pedido()->assertStatus(200);
    }

    public function test_la_ventana_de_minuto_se_renueva_y_head_cuenta_como_lectura()
    {
        config(['recursos_articulos.lectura.persona_minuto' => 1]);
        $this->pedido('HEAD')->assertStatus(200);
        $this->pedido()->assertStatus(429);
        Carbon::setTestNow(now()->addMinute());
        $this->pedido()->assertStatus(200);
    }

    public function test_los_empleados_comparten_el_cupo_del_negocio_sin_gastar_el_ajeno()
    {
        config(['recursos_articulos.escritura.negocio_minuto' => 2]);
        $this->pedido('PUT', 'api/article/1')->assertStatus(200);
        $empleado = $this->persona($this->dueno->id);
        $this->cambiar_persona($empleado);
        $this->pedido('PUT', 'api/article/2')->assertStatus(200);
        $this->pedido('PUT', 'api/article/3')->assertStatus(429);
        $otro = $this->persona();
        $this->cambiar_persona($otro);
        $this->pedido('PUT', 'api/article/3')->assertStatus(200);
    }

    public function test_los_cupos_personales_no_se_mezclan_entre_empleados()
    {
        config(['recursos_articulos.lectura.persona_minuto' => 1]);
        $this->pedido()->assertStatus(200);
        $this->pedido()->assertStatus(429);
        $this->cambiar_persona($this->persona($this->dueno->id));
        $this->pedido()->assertStatus(200);
    }

    public function test_el_cupo_horario_del_negocio_sigue_aunque_cambien_la_persona_y_la_ip()
    {
        config(['recursos_articulos.escritura.negocio_hora' => 1]);
        $this->pedido('PUT', 'api/article/1')->assertStatus(200);
        Carbon::setTestNow(now()->addMinute());
        $this->cambiar_persona($this->persona($this->dueno->id));
        $this->putJson('api/article-update-addresses', ['user_id' => 999999, 'owner_id' => 888888],
            ['X-Forwarded-For' => '203.0.113.99'])
            ->assertStatus(429)->assertHeader('Retry-After', '3480');
    }

    public function test_las_reservas_personales_y_del_negocio_rechazan_sin_esperar()
    {
        $prefijo = 'recursos-articulos:v1:negocio:' . $this->dueno->id;
        $propio = Cache::store('recursos_articulos_testing')->lock($prefijo . ':ocupado:persona:' . $this->dueno->id . ':0', 360);
        $this->assertTrue($propio->get());
        $this->pedido()->assertStatus(429)->assertHeader('Retry-After', '1');
        $propio->release();
        $uno = Cache::store('recursos_articulos_testing')->lock($prefijo . ':ocupado:negocio:0', 360);
        $dos = Cache::store('recursos_articulos_testing')->lock($prefijo . ':ocupado:negocio:1', 360);
        $this->assertTrue($uno->get());
        // Un lugar ocupado todavía deja entrar otra persona.
        $this->pedido()->assertStatus(200);
        $this->assertTrue($dos->get());
        $this->pedido()->assertStatus(429);
        $uno->release();
        $dos->release();
        $this->pedido()->assertStatus(200);
    }

    public function test_una_excepcion_del_controller_libera_las_reservas()
    {
        $controller = Mockery::mock(ArticleController::class)->makePartial();
        $controller->shouldReceive('show')->once()->andThrow(new \RuntimeException('Falla de prueba'));
        $controller->shouldReceive('show')->andReturn(response()->json(['admitido' => true]));
        $this->app->instance(ArticleController::class, $controller);
        $this->pedido()->assertStatus(500);
        $this->pedido()->assertStatus(200);
    }

    public function test_una_falla_de_infraestructura_no_deja_pasar_la_operacion()
    {
        config(['recursos_articulos.store' => 'proteccion-inexistente']);
        $this->pedido()->assertStatus(503)->assertJsonPath('code', 'proteccion_articulos_no_disponible');
        // El problema del limitador no bloquea endpoints que no recorren artículos.
        $this->getJson('api/plan-feature')->assertStatus(200);
    }

    public function test_un_cache_de_memoria_o_descartable_no_aparenta_proteger_produccion()
    {
        foreach (['array', 'null'] as $driver) {
            config(['cache.stores.recurso_' . $driver => ['driver' => $driver],
                'recursos_articulos.store' => 'recurso_' . $driver]);
            $this->pedido()->assertStatus(503)->assertJsonPath('code', 'proteccion_articulos_no_disponible');
        }
    }

    private function simular_falla_al_liberar_reservas()
    {
        $store = Cache::store('recursos_articulos_testing');
        $proxy = Mockery::mock($store);
        $proxy->shouldReceive('lock')->andReturnUsing(function ($clave, $ttl) use ($store) {
            $lock = $store->lock($clave, $ttl);
            if (strpos($clave, ':ocupado:') !== false) {
                $fallido = Mockery::mock($lock);
                $fallido->shouldReceive('release')->andThrow(new \RuntimeException('Cache caído al liberar'));
                return $fallido;
            }
            return $lock;
        });
        Cache::partialMock()->shouldReceive('store')->with('recursos_articulos_testing')->andReturn($proxy);
    }

    public function test_una_falla_al_liberar_no_oculta_una_escritura_exitosa()
    {
        $this->simular_falla_al_liberar_reservas();
        $this->preparar_escritura_unica();
        $this->pedido('PUT', 'api/article/1')->assertStatus(200)->assertJsonPath('admitido', true);
        $this->assertSame('Edición confirmada', $this->dueno->fresh()->name);
    }

    public function test_una_falla_del_logger_y_de_limpieza_tampoco_oculta_una_escritura_exitosa()
    {
        $this->simular_falla_al_liberar_reservas();
        $this->preparar_escritura_unica();
        Log::partialMock()->shouldReceive('error')->andThrow(new \RuntimeException('No se puede escribir el log'));
        $this->pedido('PUT', 'api/article/1')->assertStatus(200)->assertJsonPath('admitido', true);
        $this->assertSame('Edición confirmada', $this->dueno->fresh()->name);
    }

    private function preparar_escritura_unica()
    {
        $controller = Mockery::mock(ArticleController::class)->makePartial();
        $controller->shouldReceive('update')->once()->andReturnUsing(function () {
            // Escritura real sobre la fila descartable del test, dentro de su transacción.
            $this->dueno->update(['name' => 'Edición confirmada']);
            return response()->json(['admitido' => true]);
        });
        $this->app->instance(ArticleController::class, $controller);
    }

    public function test_una_falla_al_liberar_no_reemplaza_la_excepcion_del_controller()
    {
        $this->simular_falla_al_liberar_reservas();
        $controller = Mockery::mock(ArticleController::class)->makePartial();
        $controller->shouldReceive('update')->andThrow(new \DomainException('Falla original del controller'));
        $this->app->instance(ArticleController::class, $controller);
        $this->withoutExceptionHandling();
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Falla original del controller');
        $this->pedido('PUT', 'api/article/1');
    }

    public function test_un_rechazo_por_concurrencia_no_gasta_el_cupo_y_el_ttl_es_superior_a_fpm()
    {
        config(['recursos_articulos.lectura.persona_minuto' => 1, 'recursos_articulos.lock_ttl' => 5]);
        $store = Cache::store('recursos_articulos_testing');
        $proxy = Mockery::mock($store);
        $proxy->shouldReceive('lock')->andReturnUsing(function ($clave, $ttl) use ($store) {
            $this->assertGreaterThan(300, $ttl);
            return $store->lock($clave, $ttl);
        });
        Cache::partialMock()->shouldReceive('store')->with('recursos_articulos_testing')->andReturn($proxy);
        $prefijo = 'recursos-articulos:v1:negocio:' . $this->dueno->id;
        $ocupado = $store->lock($prefijo . ':ocupado:negocio:0', 360);
        $segundo = $store->lock($prefijo . ':ocupado:negocio:1', 360);
        $ocupado->get();
        $segundo->get();
        $this->pedido()->assertStatus(429);
        $ocupado->release();
        $segundo->release();
        $this->pedido()->assertStatus(200);
    }

    public function test_no_se_omite_la_autenticacion_ni_se_cargan_cupos_a_un_visitante()
    {
        $this->app['auth']->forgetGuards();
        $this->pedido()->assertStatus(401);
        $this->actingAs($this->dueno, 'web');
        config(['recursos_articulos.lectura.persona_minuto' => 1]);
        $this->pedido()->assertStatus(200);
    }

    public function test_importacion_venta_y_otras_acciones_no_reciben_este_middleware()
    {
        $rutas = Route::getRoutes();
        foreach ([['POST', 'api/article/excel/import'], ['PUT', 'api/update/article'], ['POST', 'api/sale']] as $datos) {
            $ruta = $rutas->match(\Illuminate\Http\Request::create($datos[1], $datos[0]));
            $this->assertNotContains('recursos.articulos', $ruta->gatherMiddleware());
        }
        foreach ([['GET', 'api/article/1'], ['PUT', 'api/article/1'], ['PATCH', 'api/article/1'], ['PUT', 'api/article-update-addresses'], ['PUT', 'api/article-update-varians-stock']] as $datos) {
            $ruta = $rutas->match(\Illuminate\Http\Request::create($datos[1], $datos[0]));
            $this->assertContains('auth:sanctum', $ruta->gatherMiddleware());
            $this->assertContains('recursos.articulos', $ruta->gatherMiddleware());
        }
    }
}
