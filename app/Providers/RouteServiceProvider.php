<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    // protected $namespace = null;
    protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        RateLimiter::for('custom-api', function (Request $request) {
            return Limit::perMinute(300)->by(
                optional($request->user())->id ?: $request->ip()
            );
        });

        /*
         * Limite propio para el agente de impresion, keyeado por su token y no por IP.
         *
         * 🔴 El agente no esta autenticado como usuario, asi que con `custom-api` caeria en la
         * rama del ip() -- y TODAS las cajas de un comercio salen por la misma IP publica. Cada
         * agente sondea cada 2 segundos (30 requests por minuto) mas el heartbeat, asi que con
         * cinco o seis cajas el comercio entero se comeria 429 y dejaria de imprimir sin ningun
         * diagnostico: el .exe recibe un 429 donde espera la lista de trabajos.
         *
         * Por token, cada equipo tiene su propio cupo y la cantidad de cajas deja de importar.
         */
        RateLimiter::for('print-agent', function (Request $request) {
            $token = $request->header('X-Print-Agent-Token');

            return Limit::perMinute(120)->by($token ? sha1($token) : $request->ip());
        });

        $this->routes(function () {
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    // protected function configureRateLimiting()
    // {
    //     RateLimiter::for('api', function (Request $request) {
    //         return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
    //     });
    // }
}
