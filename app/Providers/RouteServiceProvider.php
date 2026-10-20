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

        $this->definir_limites_de_recuperar_clave();

        $this->routes(function () {
            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));

            /*
             * Rutas de mantenimiento, debug y administración interna (misión cerrar-rutas-web-publicas,
             * 10/10/2026). TODO el archivo pasa por `solo.mantenimiento` desde acá, así que una ruta que
             * se agregue ahí queda cerrada sin acordarse de nada (falla cerrado). Va después de `web`
             * porque el middleware necesita la sesión, y se carga después de web.php: si alguna URI se
             * repitiera, gana la de este archivo, que es la cerrada.
             */
            Route::middleware(['web', 'solo.mantenimiento'])
                ->namespace($this->namespace)
                ->group(base_path('routes/mantenimiento.php'));

            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));
        });
    }

    /**
     * Texto del 429 de las rutas de recuperar clave. Es el MISMO para los tres pasos y para las dos
     * ventanas, y no dice nada del correo: un 429 no puede servir para averiguar si una cuenta existe.
     */
    const MENSAJE_DE_RECUPERAR_CLAVE_DEMASIADOS_INTENTOS = 'Hiciste demasiados intentos. Esperá unos minutos y volvé a probar.';

    /**
     * Registra los tres limiters (uno por paso) de las rutas públicas `POST /password-reset/*` de
     * routes/web.php (misión throttle-recuperar-clave-por-ip, 20/10/2026):
     *
     *   - `recuperar-clave-pedir`    -> send-verification-code  (5 por minuto,  20 por hora)
     *   - `recuperar-clave-verificar`-> check-verification-code (10 por minuto, 60 por hora)
     *   - `recuperar-clave-cambiar`  -> update-password         (10 por minuto, 30 por hora)
     *
     * Cada paso tiene su cubo propio: pedir muchos códigos no gasta los intentos de verificar.
     *
     * QUÉ HACE Y QUÉ NO HACE ESTE LÍMITE: es una capa EXTRA por IP. NO reemplaza ni cambia la protección
     * por cuenta de PasswordResetController (5 intentos por código, 5 códigos por hora, bajo candado), que
     * es lo único que protege una cuenta puntual y sigue igual. Lo que agrega esto es frenar los barridos
     * de muchos correos distintos, el "email bombing" (mandar códigos a casillas ajenas) y que una IP
     * agote los intentos de una víctima.
     *
     * 🔴 `$request->ip()` ES SPOOFABLE: TrustProxies acepta X-Forwarded-For de cualquier origen
     * (`$proxies = '*'`), así que mandando un header distinto en cada pedido se esquiva el límite. Igual
     * que el throttle de `print-agent/vincular` (routes/api.php): sirve como freno para un script
     * torpe, NO como defensa real. No se tocó TrustProxies porque afecta a todo el sistema.
     *
     * Los valores por hora dejan margen para una IP compartida (un local con varios empleados detrás de
     * un mismo router), que es el falso positivo posible de limitar por IP.
     *
     * @return void
     */
    protected function definir_limites_de_recuperar_clave()
    {
        RateLimiter::for('recuperar-clave-pedir', function (Request $request) {
            return $this->limites_de_recuperar_clave($request, 5, 20);
        });

        RateLimiter::for('recuperar-clave-verificar', function (Request $request) {
            return $this->limites_de_recuperar_clave($request, 10, 60);
        });

        RateLimiter::for('recuperar-clave-cambiar', function (Request $request) {
            return $this->limites_de_recuperar_clave($request, 10, 30);
        });
    }

    /**
     * Arma las dos ventanas (minuto y hora) de un paso de recuperar clave para la IP del pedido.
     *
     * 🔴 Las dos ventanas llevan CLAVES DISTINTAS ('m:' y 'h:'). ThrottleRequests hashea la clave de
     * cada Limit con el nombre del limiter (`md5($nombre.$limit->key)`), así que dos Limit con el mismo
     * `by()` compartirían UN solo contador y la ventana de la hora nunca contaría aparte.
     *
     * Pasado el tope, la respuesta es un 429 JSON con `message` y `errors.email` (así lo lee la SPA), más
     * los headers `Retry-After` y `X-RateLimit-*` que arma Laravel para la ventana que se pasó. Es una
     * respuesta nueva: ninguna respuesta existente de las rutas cambia.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $por_minuto  Pedidos permitidos por IP en un minuto.
     * @param  int  $por_hora  Pedidos permitidos por IP en una hora.
     * @return \Illuminate\Cache\RateLimiting\Limit[]
     */
    protected function limites_de_recuperar_clave(Request $request, $por_minuto, $por_hora)
    {
        $ip = $request->ip();

        /**
         * Respuesta del 429. Laravel le pasa el request y los headers ya calculados de la ventana vencida.
         *
         * @param  \Illuminate\Http\Request  $request
         * @param  array  $headers
         * @return \Illuminate\Http\JsonResponse
         */
        $responder_demasiados_intentos = function ($request, array $headers) {
            return response()->json([
                'message' => self::MENSAJE_DE_RECUPERAR_CLAVE_DEMASIADOS_INTENTOS,
                'errors'  => [
                    'email' => [self::MENSAJE_DE_RECUPERAR_CLAVE_DEMASIADOS_INTENTOS],
                ],
            ], 429, $headers);
        };

        return [
            Limit::perMinute($por_minuto)->by('m:' . $ip)->response($responder_demasiados_intentos),
            Limit::perHour($por_hora)->by('h:' . $ip)->response($responder_demasiados_intentos),
        ];
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
