<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Helpers\DemoIngresoTokenHelper;
use App\Models\DemoIngresoToken;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Valida en cada request que la sesion de demo siga vigente (grupo 233, prompt 03).
 *
 * Este middleware corre dentro del grupo 'api', que es el mismo codigo desplegado en
 * TODAS las instancias de clientes reales, no solo en las demo. Por eso el corte
 * temprano de las primeras lineas es obligatorio: para el 100% de los requests de un
 * cliente real (sesion sin el marcador `demo_ingreso_token_id`, o directamente sin
 * sesion iniciada) este middleware no debe pegarle a la base ni tocar la sesion, y
 * simplemente deja pasar el request sin hacer nada mas.
 *
 * Solo cuando la sesion actual fue iniciada por DemoIngresoController (prompt 02) y
 * lleva ese marcador, se resuelve el token contra `demo_ingreso_tokens` y se corta el
 * acceso si ya expiro o fue revocado despues de que el lead habia entrado.
 */
class DemoSessionVigente
{
    /**
     * Resuelve si la sesion demo actual sigue vigente y corta el acceso si no.
     *
     * @param Request $request Request HTTP entrante.
     * @param Closure $next Siguiente middleware / controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        /**
         * Corte temprano #1: sin sesion arrancada no hay nada que validar. Ademas,
         * llamar a $request->session() sin sesion iniciada tira excepcion, asi que
         * hasSession() se chequea antes que cualquier otra cosa.
         */
        if (!$request->hasSession()) {
            return $next($request);
        }

        /** Marcador que solo existe en sesiones iniciadas por DemoIngresoController. */
        $token_id = $request->session()->get('demo_ingreso_token_id');

        /**
         * Corte temprano #2: sin marcador de ingreso demo, la sesion es de un usuario
         * real (o de un request sin usuario). No se consulta la base.
         */
        if (empty($token_id)) {
            return $next($request);
        }

        /**
         * Cache dentro del mismo request: si por algun motivo el middleware se
         * resolviera mas de una vez para el mismo request, no se vuelve a pegar
         * contra la base la segunda vez.
         */
        if ($request->attributes->has('demo_session_vigente')) {
            if ($request->attributes->get('demo_session_vigente') === true) {
                return $next($request);
            }

            return $this->cerrarSesionExpirada($request);
        }

        // A partir de aca sabemos que la sesion actual es una sesion de demo.
        $registro = DemoIngresoToken::find($token_id);

        /**
         * Vigente = existe, no fue revocado y todavia no llego su expiracion. En local se
         * ignoran las ultimas dos condiciones (DemoIngresoTokenHelper::bypass_vigencia_local()),
         * pero el registro SIEMPRE tiene que existir: un token_id que no resuelve a ninguna
         * fila sigue sin dejar pasar, en cualquier entorno.
         */
        $vigente = !is_null($registro) && (
            DemoIngresoTokenHelper::bypass_vigencia_local()
            || (
                is_null($registro->revoked_at)
                && !is_null($registro->expires_at)
                && $registro->expires_at->greaterThan(Carbon::now())
            )
        );

        $request->attributes->set('demo_session_vigente', $vigente);

        if ($vigente) {
            return $next($request);
        }

        return $this->cerrarSesionExpirada($request);
    }

    /**
     * Cierra la sesion demo vencida/revocada y responde con un codigo distinguible
     * de un 401 comun para que el SPA pueda mostrar un mensaje especifico.
     *
     * @param Request $request Request HTTP entrante con la sesion a invalidar.
     * @return \Illuminate\Http\JsonResponse
     */
    private function cerrarSesionExpirada(Request $request)
    {
        /**
         * 🔴 Guard EXPLICITO, nunca `Auth::logout()` a secas.
         *
         * Laravel prioriza el middleware `Authenticate` (el de `auth:sanctum`) por encima de
         * este, via su `$middlewarePriority` interno -- pese a que en Kernel.php estan
         * declarados en el orden contrario. Cuando la sesion ya esta autenticada por
         * `auth:sanctum` en el mismo request, `Auth::shouldUse('sanctum')` ya mudo el guard
         * por defecto ANTES de que este metodo corra. `Auth::logout()` sin guard resuelve
         * entonces `RequestGuard` (el guard `sanctum`), que no tiene metodo `logout()` ->
         * `BadMethodCallException` -> 500, en vez del 401 `demo_expirada` que este metodo
         * existe para devolver. Las sesiones de demo son siempre sesion (`web`), sin importar
         * que haya mutado el guard por defecto durante el request.
         *
         * Medido en produccion el 17/8/2026 (storage/logs/laravel.log, "Method
         * Illuminate\Auth\RequestGuard::logout does not exist."): le pasa a CUALQUIER lead
         * real cuyo turno de demo venza mientras sigue navegando.
         */
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'error' => 'demo_expirada',
            'message' => 'Tu turno de demo termino.',
        ], 401);
    }
}
