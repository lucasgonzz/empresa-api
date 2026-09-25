<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            Log::error('Error: ', ['error' => $e]);
            (new \App\Services\GitHubErrorReporterService())->report($e);
        });
    }

    /**
     * Un request no autenticado a `api/*` contesta 401 en JSON, pida lo que pida.
     *
     * 🔴 SIN ESTO, EL null DE Authenticate::redirectTo() NO ALCANZA. El handler de Laravel 8 decide
     * por `expectsJson()` y, si da false, redirige a `$exception->redirectTo() ?? route('login')`:
     * con el null vuelve a caer en `route('login')`, que en este proyecto no existe, y el request
     * muere en 500 con `Route [login] not defined` (y encima se reporta como error). Es lo que
     * pasaba en demo3 el 24/9/2026 con el `<img>` de las fotos del asistente, que no manda
     * `Accept: application/json`. Para todo lo que no es `api/*` queda el comportamiento de siempre.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Auth\AuthenticationException  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function unauthenticated($request, \Illuminate\Auth\AuthenticationException $exception)
    {
        if ($request->is('api/*')) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return parent::unauthenticated($request, $exception);
    }

    /**
     * Convierte una ValidationException en JSON con mensaje traducido (locale de la app, p. ej. español).
     *
     * @param \Illuminate\Http\Request $request Solicitud actual.
     * @param \Illuminate\Validation\ValidationException $exception Excepción de validación.
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalidJson($request, ValidationException $exception)
    {
        return response()->json([
            'message' => __('validation.invalid_data'),
            'errors' => $exception->errors(),
        ], $exception->status);
    }
}
