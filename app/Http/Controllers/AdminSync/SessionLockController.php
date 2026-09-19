<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Recibe el toggle de "bloquear pestañas duplicadas del mismo navegador" que empuja admin-api
 * (ClientSessionLockSyncService) por PUT api/admin-sync/session-lock, autenticado con
 * X-Admin-Api-Key (middleware admin.api.key), mismo patrón que
 * AdminSync\BusinessHoursController -seguido acá como referencia literal de forma-.
 *
 * A diferencia de business-hours, este endpoint NO tiene tabla de configuración propia: guarda
 * directo en la columna `users.bloquear_pestanas_duplicadas` del OWNER, porque es un solo
 * booleano y ya existe `AuthHelper::debe_bloquear_pestanas_duplicadas()` leyéndolo de ahí.
 */
class SessionLockController extends Controller
{
    /**
     * Guarda (o pisa) el flag del owner.
     *
     * Body del contrato (ver ClientSessionLockSyncService::build_payload() del admin-api):
     *  - bloquear_pestanas_duplicadas (bool):     requerido.
     *  - user_id                      (int|null): opcional; el emisor de hoy NO lo manda.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        /*
         * Mismo guard que BusinessHoursController::update(), adaptado a un solo booleano: un
         * body vacío o mal formado llega a Laravel como input vacío. Sin este corte, ese
         * request pisaría el flag guardado con `false` por un push roto, con 200 y sin que nadie
         * se entere. Se exige que la clave esté PRESENTE y sea USABLE (no que sea "truthy"): un
         * push legítimo de `bloquear_pestanas_duplicadas: false` (apagar la capacidad) tiene que
         * pasar el guard igual que uno de `true`.
         */
        $valor_crudo = $request->input('bloquear_pestanas_duplicadas');

        if ($valor_crudo === null) {
            return response()->json([
                'error'   => 'payload_vacio',
                'message' => 'El body no trae "bloquear_pestanas_duplicadas". No se pisa el valor '
                    . 'guardado con un push vacio o mal formado.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'bloquear_pestanas_duplicadas' => 'required|boolean',
            'user_id'                      => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error'   => 'validacion',
                'message' => (string) $validator->errors()->first(),
            ], 422);
        }

        $owner = $this->resolve_owner($request);

        /*
         * Owner inexistente ⇒ 422, mismo criterio que BusinessHoursController: un 200 acá sería
         * mentir ("lo guarde") y el admin marcaria `success` sobre un push que no persistio nada.
         */
        if ($owner === null) {
            return response()->json([
                'error'   => 'owner_no_encontrado',
                'message' => 'No se encontro un owner en el sistema al que guardarle el flag.',
            ], 422);
        }

        $bloquear = $request->boolean('bloquear_pestanas_duplicadas');

        $owner->bloquear_pestanas_duplicadas = $bloquear;

        /*
         * Al APAGAR el modo estricto, normalizamos el session_id del owner sacándole el sufijo
         * ":tabId" si lo tiene. Sin esto, la pestaña que en este momento tiene el candado tomado
         * (guardado como "sessionId:tabId" mientras el modo estaba prendido) queda espuriamente
         * bloqueada en su próximo chequeo: AuthHelper::checkUserLastActivity() va a componer un
         * candado SIN sufijo (porque $estricto ya es false acá) y no va a matchear contra el
         * valor con sufijo que quedó guardado, aunque sea exactamente la misma pestaña de la
         * misma sesión. No se toca `last_activity` ni se cierra ninguna sesión: es solo un
         * cambio de formato del mismo candado, para que quede consistente con el modo nuevo.
         */
        if (! $bloquear && $owner->session_id !== null && strpos($owner->session_id, ':') !== false) {
            $owner->session_id = explode(':', $owner->session_id, 2)[0];
        }

        $owner->save();

        Log::info('AdminSync session-lock OK', [
            'user_id'                      => $owner->id,
            'bloquear_pestanas_duplicadas' => $bloquear,
        ]);

        /*
         * Acuse con los valores recién guardados: el push 1 y el push N con el mismo contenido
         * devuelven exactamente lo mismo (updateOrCreate/save sobre el mismo booleano es
         * naturalmente idempotente, no hace falta el patrón de reintento de business-hours que
         * existe por el `updateOrCreate` con unique de una tabla aparte).
         */
        return response()->json([
            'ok'                            => true,
            'bloquear_pestanas_duplicadas'  => $bloquear,
        ], 200);
    }

    /**
     * Owner al que se le guarda el flag.
     *
     * Mismo criterio que BusinessHoursController::resolve_owner() / SistemaQueryController: un
     * `user_id` explícito del body (el emisor de hoy NO lo manda, pero el contrato lo deja
     * abierto), y si no, el owner de la instancia. Inline y no
     * `BusinessHoursConfig::owner_de_la_instancia()` porque este endpoint no tiene una tabla de
     * configuración propia de la cual colgar ese helper.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|null
     */
    protected function resolve_owner(Request $request): ?User
    {
        $explicit_user_id = $request->input('user_id');

        if ($explicit_user_id !== null && is_numeric($explicit_user_id) && (int) $explicit_user_id > 0) {
            $owner = User::find((int) $explicit_user_id);

            if ($owner !== null) {
                return $owner;
            }
        }

        // Por defecto: la cuenta principal de la instalación.
        return User::whereNull('owner_id')->orderBy('id')->first();
    }
}
