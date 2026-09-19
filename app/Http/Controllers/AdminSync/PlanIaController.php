<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El plan de IA que empuja el admin (misión foto-sucursal-y-asistente-configurable, 17/9/2026).
 *
 * El admin maneja los paquetes de IA y su precio; cuando le asigna uno a un cliente, pushea acá el
 * nombre y los dos topes con `PUT api/admin-sync/plan-ia`. Este proyecto los guarda en el DUEÑO, y
 * el footer del chat los muestra (consumo del mes contra el tope) y el corte por tope los usa.
 *
 * 🔴 LA CLAVE SE VALIDA ADENTRO DEL CONTROLADOR, no en el middleware. Igual que
 * ConsumoIaController y el canal de WhatsApp: el middleware del grupo (AdminApiKey) no exige nada
 * mientras `services.admin_api.require_api_key` esté apagado, que es como está en producción. Este
 * endpoint ESCRIBE el plan del cliente, así que valida el header por su cuenta —pero solo si el
 * cliente tiene la clave cargada de este lado—, con el mismo criterio de rechazo_por_clave() de
 * ConsumoIaController: estrictamente más seguro que el status quo, sin romper a los clientes que
 * todavía no tienen ADMIN_API_INBOUND_KEY en su .env.
 *
 * 🔴 LAS CLAVES DEL BODY SON ESPEJO EXACTO del emisor (contrato): `nombre`, `tope_tokens_mensual`,
 * `tope_interacciones_diarias`. Es el punto donde este proyecto ya se quemó (`manual_tasks` vs
 * `tareas`): un renombre de un lado deja al otro leyendo null sin un solo error.
 *
 * Idempotente: pushear el mismo plan dos veces deja el dueño igual. Un tope 0 o null se guarda como
 * null = sin tope, que es la guarda de compatibilidad (el agente no corta hasta que llega un tope > 0).
 */
class PlanIaController extends Controller
{
    /**
     * PUT api/admin-sync/plan-ia
     *
     * 200 {ok:true} guardado en el dueño
     * 401 la clave del header no coincide con la que tiene cargada este cliente
     * 409 {message} no se pudo resolver el dueño de esta instancia
     * 422 body mal formado
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        $rechazo_clave = $this->rechazo_por_clave($request);

        if (! is_null($rechazo_clave)) {

            return $rechazo_clave;
        }

        $request->validate([
            'nombre'                     => 'nullable|string|max:255',
            'tope_tokens_mensual'        => 'nullable|integer|min:0',
            'tope_interacciones_diarias' => 'nullable|integer|min:0',
        ]);

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409);
        }

        $dueno->plan_ia_nombre = $this->nombre_limpio($request->input('nombre'));
        $dueno->plan_ia_tope_tokens_mensual = $this->tope($request->input('tope_tokens_mensual'));
        $dueno->plan_ia_tope_interacciones_diarias = $this->tope($request->input('tope_interacciones_diarias'));
        $dueno->save();

        return response()->json(['ok' => true], 200);
    }

    /**
     * El nombre del paquete, recortado, o null si vino vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected function nombre_limpio($valor)
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    /**
     * Un tope tal como se guarda: > 0 es el tope, 0 o null es "sin tope" (null en la base).
     *
     * @param  mixed  $valor
     * @return int|null
     */
    protected function tope($valor)
    {
        if (is_null($valor)) {

            return null;
        }

        $valor = (int) $valor;

        return $valor > 0 ? $valor : null;
    }

    /**
     * Valida el header `X-Admin-Api-Key` SOLO si este cliente tiene la clave cargada. Copia del
     * criterio de ConsumoIaController::rechazo_por_clave() (ver el 🔴 del docblock de la clase).
     *
     * @param  Request  $request
     * @return JsonResponse|null
     */
    protected function rechazo_por_clave(Request $request)
    {
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '') {

            return null;
        }

        $recibida = (string) $request->header('X-Admin-Api-Key');

        if ($recibida === '' || ! hash_equals($esperada, $recibida)) {

            return response()->json(['error' => 'unauthorized'], 401);
        }

        return null;
    }
}
