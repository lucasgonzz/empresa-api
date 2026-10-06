<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Exige la clave de admin (header X-Admin-Api-Key) en POST /api/admin-sync/user-setup, detrás de
 * una variable de transición que está APAGADA por defecto (misión blindar-user-setup, 5/10/2026).
 *
 * POR QUÉ NO SE USA `AdminApiKey` (alias `admin.api.key`) Y LISTO
 * ----------------------------------------------------------------
 * `AdminApiKey` no valida nada mientras `services.admin_api.require_api_key`
 * (ADMIN_SYNC_REQUIRE_API_KEY) esté en false, que es como está en producción, así que no
 * protegería nada. Y prender esa variable global rompería rutas que hoy andan. Esta es la
 * versión que se puede prender SOLA para user-setup.
 *
 * 🔴 SE PRENDE SOLO CON SU VARIABLE PROPIA, NUNCA CON LA GLOBAL
 * -------------------------------------------------------------
 * Este middleware mira únicamente `services.admin_api.require_key_for_setup`
 * (ADMIN_SYNC_SETUP_REQUIRE_API_KEY). El flag global ADMIN_SYNC_REQUIRE_API_KEY NO lo activa, a
 * propósito y por compatibilidad hacia atrás: si algún cliente del VPS tuviera el global en true
 * (para las rutas del grupo `admin.api.key`), user-setup pasaría a dar 401 de un día para el otro
 * porque admin-api no manda la clave a esta ruta, y se rompería el alta de clientes. Si te tienta
 * agregar el OR con el global "porque es lo mismo", leé esto: es una decisión de diseño, no un olvido.
 *
 * 🔴 POR QUÉ LA CLAVE ESTÁ APAGADA POR DEFECTO
 * -------------------------------------------
 * Ninguno de los dos llamadores de admin-api (`RunUserSetupService` e
 * `ImplementationUserSetupService`) manda X-Admin-Api-Key a esta ruta. Exigirla ya mismo
 * rompería el alta de clientes: todos los user-setup darían 401. La protección que SÍ está
 * prendida desde ahora es la guarda de datos de `UserSetupHelper::run()` (no vacía una base con
 * datos). Esta clave es la segunda capa, y se prende (ADMIN_SYNC_SETUP_REQUIRE_API_KEY=true) el
 * día que admin-api mande el header. Si la prendés antes, el alta de clientes se rompe.
 *
 * Con la variable prendida FALLA CERRADO: si la instancia no tiene clave cargada
 * (ADMIN_API_INBOUND_KEY vacía) todo da 401, porque "prendida" significa "exijo clave" y una
 * instancia sin clave no puede validar a nadie.
 */
class ClaveDeAdminEnSetup
{
    /**
     * Deja pasar el request si la exigencia de clave está apagada (su variable propia, no la
     * global), o si trae la clave correcta.
     *
     * @param  Request $request Request HTTP entrante.
     * @param  Closure $next    Siguiente middleware / controlador.
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next)
    {
        // Apagada (hoy, por defecto): la ruta se comporta igual que antes de esta misión. Solo mira
        // la variable propia: el flag global `require_api_key` NO cuenta (ver el docblock de la clase).
        if (!config('services.admin_api.require_key_for_setup', false)) {
            return $next($request);
        }

        $esperada = (string) config('services.admin_api.api_key');
        $recibida = (string) $request->header('X-Admin-Api-Key');

        // Sin clave cargada en la instancia no hay con qué comparar: se rechaza todo (falla cerrado).
        if ($esperada === '' || $recibida === '' || !hash_equals($esperada, $recibida)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
