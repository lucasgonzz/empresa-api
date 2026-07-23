<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\WhatsappTemplateHelper;
use App\Models\WhatsappTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de plantillas de WhatsApp (grupo 137, Prompt 04), consumido por empresa-spa.
 * Toda la lógica de negocio vive en `WhatsappTemplateHelper`; este controller solo
 * valida pertenencia al owner autenticado y orquesta. Rutas gateadas por
 * `auth:sanctum` + `check_extencion_empresa:whatsapp` (ver routes/api.php).
 */
class WhatsappTemplateController extends Controller
{
    /**
     * Lista las plantillas del owner autenticado (estándar + propias).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $models = WhatsappTemplate::where('user_id', $this->userId())
            ->orderBy('is_system', 'DESC')
            ->orderBy('name')
            ->withAll()
            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea una plantilla propia de la empresa. Nace siempre `borrador` (ver
     * `WhatsappTemplateHelper::build_create_data`); recién se puede enviar cuando el
     * equipo de ComercioCity la carga en Meta y la marca `aprobada`.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $model = WhatsappTemplate::create(
            WhatsappTemplateHelper::build_create_data($request, $this->userId())
        );

        return response()->json(['model' => $this->fullModel('WhatsappTemplate', $model->id)], 201);
    }

    /**
     * Actualiza una plantilla propia de la empresa. Las `is_system` (estándar de
     * ComercioCity) no se pueden editar.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id): JsonResponse
    {
        $model = $this->find_owned_template($id);
        if (is_null($model)) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        if (! WhatsappTemplateHelper::is_editable($model)) {
            return response()->json(['message' => 'Las plantillas estándar de ComercioCity no se pueden editar.'], 403);
        }

        $model->fill(WhatsappTemplateHelper::build_update_data($request));
        $model->save();

        return response()->json(['model' => $this->fullModel('WhatsappTemplate', $model->id)], 200);
    }

    /**
     * Borra una plantilla propia de la empresa. Las `is_system` (estándar de
     * ComercioCity) no se pueden borrar.
     *
     * @param  int  $id
     * @return JsonResponse|\Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $model = $this->find_owned_template($id);
        if (is_null($model)) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        if (! WhatsappTemplateHelper::is_editable($model)) {
            return response()->json(['message' => 'Las plantillas estándar de ComercioCity no se pueden borrar.'], 403);
        }

        $model->delete();

        return response(null);
    }

    /**
     * Pasa una plantilla propia de `borrador` a `pendiente_meta`: el equipo de
     * ComercioCity la carga a mano en Meta y luego la marca `aprobada` (o `rechazada`)
     * vía `update`.
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function solicitar_alta($id): JsonResponse
    {
        $model = $this->find_owned_template($id);
        if (is_null($model)) {
            return response()->json(['message' => 'Plantilla no encontrada.'], 404);
        }

        $model->status = 'pendiente_meta';
        $model->save();

        return response()->json(['model' => $this->fullModel('WhatsappTemplate', $model->id)], 200);
    }

    /**
     * Busca una plantilla por id validando que pertenezca al owner del usuario
     * autenticado (patrón multi-empleado del proyecto: los empleados operan sobre los
     * datos del dueño).
     *
     * @param  int  $id
     * @return WhatsappTemplate|null
     */
    private function find_owned_template($id)
    {
        return WhatsappTemplate::where('id', $id)
            ->where('user_id', $this->userId())
            ->first();
    }
}
