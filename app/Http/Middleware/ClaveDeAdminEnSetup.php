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
 * rompería el alta de clientes: todos los user-setup darían 401. Y aunque lo mandaran, una
 * instalación nueva nace sin ADMIN_API_INBOUND_KEY (la plantilla del `.env` no la trae; la escribe
 * recién `POST claude/clients/{id}/catalogo/clave` del admin), así que con la variable prendida el
 * alta fallaría cerrado igual. La protección que SÍ está prendida desde ahora es la guarda de
 * datos de `UserSetupHelper::run()` (no vacía una base con datos). Esta clave es la segunda capa,
 * y se prende (ADMIN_SYNC_SETUP_REQUIRE_API_KEY=true) el día que admin-api mande el header y las
 * instalaciones nazcan con la clave. Si la prendés antes, el alta de clientes se rompe.
 *
 * Con la variable prendida FALLA CERRADO: si la instancia no tiene clave cargada
 * (ADMIN_API_INBOUND_KEY vacía) todo da 401, porque "prendida" significa "exijo clave" y una
 * instancia sin clave no puede validar a nadie.
 *
 * 🔴 PARA EL BORRADO TOTAL LA CLAVE SE PIDE SIEMPRE, CON LA VARIABLE APAGADA O PRENDIDA
 * ------------------------------------------------------------------------------------
 * (Misión cerrar-forzado-user-setup, 10/10/2026.) Que la variable esté apagada deja pasar el setup
 * NORMAL, el de una base vacía. Lo que NO deja pasar sin clave es vaciar una base que ya tiene datos
 * (`forzar_borrado_total` + `confirmar_base_de_datos`): el nombre de la base se adivina, y con eso
 * solo alcanzaba para borrar una producción. `AdminSync\UserSetupController` le pasa a
 * `UserSetupHelper::run()` el resultado de `trae_la_clave_de_admin()` y la guarda
 * (BorradoTotalDeBaseHelper) acepta el forzado solo con ese `true`. No cambia nada para admin-api,
 * que nunca manda esos dos campos; quien necesita forzar lo hace desde el servidor del cliente, con
 * la clave de su propio `.env` (el porqué completo está en BorradoTotalDeBaseHelper).
 *
 * Por eso `trae_la_clave_de_admin()` es público y estático, y es el único que compara la clave:
 * la exigencia general de acá y la del forzado no pueden terminar con dos criterios distintos.
 */
class ClaveDeAdminEnSetup
{
    /**
     * Deja pasar el request si la exigencia de clave está apagada (su variable propia, no la
     * global), o si trae la clave correcta. Dejarlo pasar NO lo autoriza a forzar un borrado total:
     * eso lo decide la guarda, con lo que el controlador le pasa de `trae_la_clave_de_admin()`.
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

        // Sin clave cargada en la instancia no hay con qué comparar: se rechaza todo (falla cerrado).
        if (!self::trae_la_clave_de_admin($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        return $next($request);
    }

    /**
     * ¿El request trae la clave de admin de ESTA instancia en X-Admin-Api-Key?
     *
     * 🔴 Es el ÚNICO lugar donde se compara esa clave en user-setup: lo usa `handle()` (la exigencia
     * general, detrás de su variable) y lo usa `AdminSync\UserSetupController` para decirle a
     * `UserSetupHelper::run()` si puede aceptar un borrado total. Dos comparaciones escritas por
     * separado terminan distintas (una se olvida del vacío, la otra usa `===`); por eso hay una.
     *
     * Falla cerrado: si la instancia no tiene clave cargada (ADMIN_API_INBOUND_KEY vacía, que es como
     * nace una instalación nueva) o el header viene vacío, devuelve false. Sin ese chequeo,
     * `hash_equals('', '')` daría true y "sin clave de los dos lados" pasaría por "la clave correcta".
     * Compara con `hash_equals` (sin atajo por largo ni por el primer carácter distinto) y es sensible
     * a mayúsculas. No loguea nada: ni la clave esperada ni la recibida salen de acá.
     *
     * No mira `require_key_for_setup`: dice si la clave está, no si se exige. Quién la exige y para
     * qué lo decide cada llamador.
     *
     * @param  Request $request Request HTTP entrante.
     * @return bool
     */
    public static function trae_la_clave_de_admin(Request $request)
    {
        $esperada = (string) config('services.admin_api.api_key');
        $recibida = (string) $request->header('X-Admin-Api-Key');

        if ($esperada === '' || $recibida === '') {
            return false;
        }

        return hash_equals($esperada, $recibida);
    }
}
