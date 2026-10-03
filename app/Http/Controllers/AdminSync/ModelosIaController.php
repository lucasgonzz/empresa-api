<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\ModelosIaHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Los modelos de IA de este cliente, leídos y escritos desde el admin (misión modelos-ia-por-cliente,
 * 30/9/2026): la solapa "Inteligencia artificial" del detalle del cliente elige el modelo del
 * asistente, del bot de WhatsApp, de la verificación de imágenes y de la importación de Excel.
 *
 * `GET admin-sync/modelos-ia` devuelve el catálogo y, por tarea, lo elegido y lo que corre de verdad;
 * `PUT admin-sync/modelos-ia` guarda las tareas que vinieron y devuelve el mismo payload. El admin NO
 * persiste nada: lee en vivo (decisión 2 de Lucas, "los dos, gana el último": el dueño puede seguir
 * cambiando el asistente desde "Configurá tu asistente" y el admin ve lo que quedó).
 *
 * Toda la lógica vive en ModelosIaHelper (catálogo, validación, traducción del asistente a
 * `agente_proveedor` + `agente_pensamiento`, resolución con fallback y payload): este controlador
 * solo valida la clave, resuelve el dueño y arma la respuesta HTTP.
 *
 * 🔴 LA CLAVE SE VALIDA ADENTRO DEL CONTROLADOR, no en el middleware (molde: PlanIaController). El
 * middleware del grupo no exige nada mientras `services.admin_api.require_api_key` esté apagado, que
 * es como está en producción, y este endpoint ESCRIBE la configuración del cliente. Solo se exige si
 * el cliente tiene la clave cargada de este lado, igual que ConsumoIaController: más seguro que el
 * status quo sin romper a los clientes que todavía no tienen ADMIN_API_INBOUND_KEY en su .env.
 *
 * Contrato (compatible hacia atrás: endpoint nuevo, nada existente cambia de forma):
 *   200 payload de ModelosIaHelper::payload_para_admin()
 *   401 {error:'unauthorized'}
 *   409 {message} no se pudo resolver el dueño de esta instancia
 *   422 {message, errors:{<tarea>:[...]}} una opción que no existe o no vale para esa tarea
 */
class ModelosIaController extends Controller
{
    /**
     * GET api/admin-sync/modelos-ia
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $rechazo_clave = $this->rechazo_por_clave($request);

        if (! is_null($rechazo_clave)) {

            return $rechazo_clave;
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        return response()->json(ModelosIaHelper::payload_para_admin($dueno), 200);
    }

    /**
     * PUT api/admin-sync/modelos-ia
     *
     * Body: `{asistente?, whatsapp?, imagenes?, excel?}` con ids de opción. Una tarea ausente no se
     * toca; con una sola opción inválida no se guarda ninguna. Se acepta una opción de un proveedor
     * sin clave en esta instalación (el admin la deja elegida antes de cargar la clave); el payload
     * muestra lo que corre de verdad mientras tanto.
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

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        /* Solo las claves de tareas conocidas: cualquier otra cosa del body se ignora. */
        $cambios = [];

        foreach (ModelosIaHelper::TAREAS as $tarea) {

            if ($request->has($tarea)) {

                $cambios[$tarea] = $request->input($tarea);
            }
        }

        $resultado = ModelosIaHelper::guardar($dueno, $cambios);

        if (! $resultado['ok']) {

            return response()->json([
                'message' => 'Alguna de las opciones elegidas no vale para su tarea. No se guardó nada.',
                'errors'  => $resultado['errors'],
            ], 422);
        }

        return response()->json(ModelosIaHelper::payload_para_admin($dueno->fresh()), 200);
    }

    /**
     * El 409 de "no hay dueño resoluble", con el mismo texto que PlanIaController.
     *
     * @return JsonResponse
     */
    protected function sin_dueno(): JsonResponse
    {
        return response()->json([
            'message' => 'No se pudo resolver el dueño de esta instancia. '
                       . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
        ], 409);
    }

    /**
     * Valida el header `X-Admin-Api-Key` SOLO si este cliente tiene la clave cargada. Copia del
     * criterio de PlanIaController::rechazo_por_clave() (ver el 🔴 del docblock de la clase).
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
