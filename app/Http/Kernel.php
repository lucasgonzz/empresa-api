<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \Fruitcake\Cors\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:custom-api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            /**
             * Va al final del grupo (grupo 233, prompt 03): tiene que correr despues de
             * que Sanctum haya establecido la sesion stateful, si no hasSession() puede
             * dar false en requests que si son de demo. Tiene corte temprano propio para
             * no afectar en nada a los requests de clientes reales.
             */
            \App\Http\Middleware\DemoSessionVigente::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array<string, class-string|string>
     */
    protected $routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        // 'set.user.database' => \App\Http\Middleware\SetUserDatabaseConnection::class,
        'admin.api.key' => \App\Http\Middleware\AdminApiKey::class,
        /* Verifica que el usuario tenga una extensión de empresa activa por su slug. */
        'check_extencion_empresa' => \App\Http\Middleware\CheckExtencionEmpresa::class,
        /* Valida vigencia del token de ingreso demo en cada request (grupo 233, prompt 03). */
        'demo.session.vigente' => \App\Http\Middleware\DemoSessionVigente::class,
        /* Autentica al agente de impresion por su token de equipo (X-Print-Agent-Token). */
        'print.agent.token' => \App\Http\Middleware\PrintAgentToken::class,
    ];
}
