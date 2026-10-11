<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Http\Middleware\RutaDeMantenimiento;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route as RutaDeLaravel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Base de los tests de la misión cerrar-rutas-web-publicas (10/10/2026): las rutas de mantenimiento,
 * debug y administración interna salen de `routes/web.php` a `routes/mantenimiento.php`, que entero pasa
 * por `solo.mantenimiento` (App\Http\Middleware\RutaDeMantenimiento).
 *
 * 🔴 EN LA SUITE ESA PUERTA ESTÁ ABIERTA (`APP_ENV=testing` es su primera condición, para que los tests de
 * esos controladores sigan llegando). Por eso estos tests fijan `$this->app['env'] = 'production'` con
 * `como_produccion()`: es el entorno de la APLICACIÓN lo que mira el middleware, no `config('app.env')`.
 * La app se recrea en cada test, así que no se arrastra.
 *
 * Usa `DatabaseTransactions` (lo que se siembra se revierte) y NO se loguea con `actingAs()`: lo que se
 * prueba es justamente el request de alguien sin sesión.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
abstract class RutasPublicasTestCase extends TestCase
{
    use DatabaseTransactions;

    /** La clave maestra "de la instancia" para estos tests (su sha256 va a la config). */
    const CLAVE = 'clave-maestra-del-test-7f3a9c1e5b2d8f4a6c0e9b7d5f3a1c8e';

    /** @var array<int, array{nivel: string, mensaje: string, contexto: array}> Lo que se logueó durante el test. */
    protected $logs = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🔴 RED DE SEGURIDAD: estos tests le pegan a rutas que, si la puerta fallara, corren de verdad
         * (`POST demo-setup` y `POST user-setup` arrancan con un `migrate:fresh` que vaciaría la base de
         * testing del slot). Con `Artisan` mockeado y `call` en `never()`, un test que llegue hasta ahí
         * FALLA en vez de borrar nada. Se verifica que el mock haya quedado puesto ANTES de seguir (mismo
         * recurso que BlindajeDelUserSetupTest).
         */
        Artisan::shouldReceive('call')->never();
        $this->assertInstanceOf(MockInterface::class, Artisan::getFacadeRoot(), 'Artisan no quedó mockeado: no se sigue.');

        $this->logs = [];

        $test = $this;

        Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($test) {
            $test->logs[] = [
                'nivel'    => (string) $evento->level,
                'mensaje'  => (string) $evento->message,
                'contexto' => (array) $evento->context,
            ];
        });
    }

    /**
     * Simula una instancia de cliente en producción: entorno `production`, sin la variable local, sin
     * marca de demo y con la clave maestra de este test cargada (un hash válido: así lo que se mide es
     * "sin clave", no el "falla cerrado" de un hash vacío).
     *
     * @return void
     */
    protected function como_produccion()
    {
        $this->app['env'] = 'production';

        config([
            'app.rutas_de_mantenimiento'     => false,
            'app.FOR_USER'                   => null,
            'app.mantenimiento_clave_sha256' => hash('sha256', self::CLAVE),
        ]);

        $this->assertFalse(app()->environment('testing'), 'La simulación de producción no quedó puesta.');
    }

    /**
     * Las rutas del router que pasan por RutaDeMantenimiento, con su middleware ya resuelto (el que corre
     * de verdad: con los grupos expandidos y los `withoutMiddleware` aplicados).
     *
     * @return array<int, array{ruta: RutaDeLaravel, guardas: array<int, string>}>
     */
    protected function rutas_de_mantenimiento()
    {
        $rutas = [];

        foreach (Route::getRoutes()->getRoutes() as $ruta) {

            $guardas = $this->guardas_de_mantenimiento($ruta);

            if (count($guardas)) {
                $rutas[] = ['ruta' => $ruta, 'guardas' => $guardas];
            }
        }

        return $rutas;
    }

    /**
     * Las entradas de RutaDeMantenimiento (con o sin parámetros) del middleware resuelto de una ruta.
     *
     * @param  RutaDeLaravel  $ruta
     * @return array<int, string>
     */
    protected function guardas_de_mantenimiento(RutaDeLaravel $ruta)
    {
        $guardas = [];

        foreach (app('router')->gatherRouteMiddleware($ruta) as $middleware) {

            if (!is_string($middleware)) {
                continue;
            }

            if ($middleware === RutaDeMantenimiento::class || strpos($middleware, RutaDeMantenimiento::class . ':') === 0) {
                $guardas[] = $middleware;
            }
        }

        return $guardas;
    }

    /**
     * La URI de una ruta con los parámetros obligatorios rellenos con `1` y los opcionales sin rellenar.
     *
     * @param  RutaDeLaravel  $ruta
     * @return string  Con la barra inicial.
     */
    protected function uri_de_prueba(RutaDeLaravel $ruta)
    {
        $uri = preg_replace('/\{[^}]+\?\}/', '', $ruta->uri());
        $uri = preg_replace('/\{[^}]+\}/', '1', $uri);
        $uri = preg_replace('#/+#', '/', $uri);

        return '/' . trim($uri, '/');
    }

    /**
     * Los métodos de una ruta, sin HEAD (Laravel lo agrega solo a cada GET).
     *
     * @param  RutaDeLaravel  $ruta
     * @return array<int, string>
     */
    protected function metodos_sin_head(RutaDeLaravel $ruta)
    {
        return array_values(array_filter($ruta->methods(), function ($metodo) {
            return $metodo !== 'HEAD';
        }));
    }

    /**
     * Las líneas de log "Ruta de mantenimiento cerrada" que dejó el middleware.
     *
     * @return array<int, array{nivel: string, mensaje: string, contexto: array}>
     */
    protected function logs_de_bloqueo()
    {
        return array_values(array_filter($this->logs, function ($linea) {
            return $linea['mensaje'] === 'Ruta de mantenimiento cerrada';
        }));
    }
}
