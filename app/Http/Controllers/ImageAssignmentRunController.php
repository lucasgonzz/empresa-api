<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Las asignaciones inteligentes de imágenes, para Alertas → Imágenes de la SPA (misión
 * imagenes-catalogo-completo, 27/9/2026). Contrato: §5.3 del plan de la misión.
 *
 * Solo traduce a HTTP: la lógica vive en ImageAssignmentRunHelper.
 *
 * Todo filtrado por el DUEÑO (`$this->userId()`): un empleado ve las asignaciones de su comercio, y
 * un id de otro comercio responde 404 (no existe para él). "Todo el catálogo", detener y reanudar son
 * solo para la sesión del acceso maestro (decisión de Lucas): sin ella, 403 con `{message}`.
 */
class ImageAssignmentRunController extends Controller
{
    /**
     * GET image-assignment-runs?page=&per_page=25 — las asignaciones del dueño, más nuevas primero.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {models: paginador de RunPayload, resumen}
     */
    public function index(Request $request)
    {
        return response()->json(ImageAssignmentRunHelper::listado(
            $this->userId(),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', ImageAssignmentRunHelper::POR_PAGINA_DEFECTO)
        ), 200);
    }

    /**
     * GET image-assignment-runs/resumen — liviano, para el badge de Alertas.
     *
     * @return \Illuminate\Http\JsonResponse  {a_revisar, sin_ver, en_proceso}
     */
    public function resumen()
    {
        return response()->json(ImageAssignmentRunHelper::resumen($this->userId()), 200);
    }

    /**
     * GET image-assignment-runs/{id} — una asignación; la marca vista si ya terminó.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: RunPayload}
     */
    public function show($id)
    {
        $run = $this->asignacion_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        ImageAssignmentRunHelper::marcar_vista($run);

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_asignacion($run)], 200);
    }

    /**
     * GET image-assignment-runs/por-uuid/{uuid} — la asignación de un lote, por el uuid que devolvió
     * POST google/batch-assign-images (lo usa el aviso de fin de corrida). La marca vista si ya terminó.
     *
     * @param  string $uuid
     * @return \Illuminate\Http\JsonResponse  {model: RunPayload}
     */
    public function por_uuid($uuid)
    {
        $run = ImageAssignmentRun::where('user_id', $this->userId())
            ->where('uuid', (string) $uuid)
            ->first();

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        ImageAssignmentRunHelper::marcar_vista($run);

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_asignacion($run)], 200);
    }

    /**
     * GET image-assignment-runs/{id}/items?solapa=no_asignadas|a_revisar|asignadas&page=&per_page=25&buscar=
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {models: paginador de ItemPayload, conteos}
     */
    public function items(Request $request, $id)
    {
        $run = $this->asignacion_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        $solapa = (string) $request->query('solapa', 'no_asignadas');

        if (!array_key_exists($solapa, ImageAssignmentRunHelper::SOLAPAS)) {
            return response()->json(['message' => 'La solapa tiene que ser no_asignadas, a_revisar o asignadas.'], 422);
        }

        return response()->json(ImageAssignmentRunHelper::items_de(
            $run,
            $solapa,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', ImageAssignmentRunHelper::POR_PAGINA_DEFECTO),
            (string) $request->query('buscar', '')
        ), 200);
    }

    /**
     * POST image-assignment-items/{id}/aprobar
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: ItemPayload} | 422 {message}
     */
    public function aprobar($id)
    {
        return $this->respuesta_de_item(ImageAssignmentRunHelper::aprobar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST image-assignment-items/{id}/rechazar
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: ItemPayload} | 422 {message}
     */
    public function rechazar($id)
    {
        return $this->respuesta_de_item(ImageAssignmentRunHelper::rechazar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST image-assignment-items/{id}/quitar — solo asignada / aprobada → quitada.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: ItemPayload} | 422 {message}
     */
    public function quitar($id)
    {
        return $this->respuesta_de_item(ImageAssignmentRunHelper::quitar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST image-assignment-items/aprobar-varios {ids: []}
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {aprobados: n, fallidos: [{id, message}]}
     */
    public function aprobar_varios(Request $request)
    {
        $ids = $this->ids_del_lote($request);

        if (!is_array($ids)) {
            return $ids;
        }

        $resultado = ImageAssignmentRunHelper::en_lote('aprobar', $this->userId(), $ids, $this->userId(false));

        return response()->json(['aprobados' => $resultado['hechos'], 'fallidos' => $resultado['fallidos']], 200);
    }

    /**
     * POST image-assignment-items/rechazar-varios {ids: []}
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  {rechazados: n, fallidos: [{id, message}]}
     */
    public function rechazar_varios(Request $request)
    {
        $ids = $this->ids_del_lote($request);

        if (!is_array($ids)) {
            return $ids;
        }

        $resultado = ImageAssignmentRunHelper::en_lote('rechazar', $this->userId(), $ids, $this->userId(false));

        return response()->json(['rechazados' => $resultado['hechos'], 'fallidos' => $resultado['fallidos']], 200);
    }

    /**
     * GET image-assignment-runs/catalogo/previa — acceso maestro.
     *
     * @return \Illuminate\Http\JsonResponse  Contrato §5.4.
     */
    public function previa_catalogo()
    {
        if (!ImageAssignmentRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $owner = User::find($this->userId());

        if (is_null($owner)) {
            return response()->json(['message' => 'No se pudo identificar la cuenta.'], 422);
        }

        return response()->json(ImageAssignmentRunHelper::previa_del_catalogo($owner), 200);
    }

    /**
     * POST image-assignment-runs/catalogo — acceso maestro: lanza "buscar imágenes para todo el catálogo".
     *
     * @return \Illuminate\Http\JsonResponse  201 {model: RunPayload} | 422 {message}
     */
    public function crear_catalogo()
    {
        if (!ImageAssignmentRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $owner = User::find($this->userId());

        if (is_null($owner)) {
            return response()->json(['message' => 'No se pudo identificar la cuenta.'], 422);
        }

        $resultado = ImageAssignmentRunHelper::crear_del_catalogo($owner, $this->userId(false));

        if ($resultado['status'] !== 201) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_asignacion($resultado['run']->fresh())], 201);
    }

    /**
     * POST image-assignment-runs/{id}/detener — acceso maestro.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: RunPayload} | 422 {message}
     */
    public function detener($id)
    {
        if (!ImageAssignmentRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $run = $this->asignacion_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        $resultado = ImageAssignmentRunHelper::detener($run);

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_asignacion($run->fresh())], 200);
    }

    /**
     * POST image-assignment-runs/{id}/reanudar — acceso maestro; vale para detenida, fallida o trabada.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  {model: RunPayload} | 422 {message}
     */
    public function reanudar($id)
    {
        if (!ImageAssignmentRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $run = $this->asignacion_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        $resultado = ImageAssignmentRunHelper::reanudar($run);

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_asignacion($run->fresh())], 200);
    }

    /**
     * La asignación con ese id, si es del dueño logueado.
     *
     * @param  mixed $id
     * @return \App\Models\ImageAssignmentRun|null
     */
    protected function asignacion_del_dueno($id)
    {
        return ImageAssignmentRun::where('user_id', $this->userId())
            ->where('id', (int) $id)
            ->first();
    }

    /**
     * Los ids de un aprobar / rechazar en lote, o la respuesta 422 si no sirven.
     *
     * @param  \Illuminate\Http\Request $request
     * @return array|\Illuminate\Http\JsonResponse
     */
    protected function ids_del_lote(Request $request)
    {
        $ids = $request->input('ids');

        if (!is_array($ids) || empty($ids)) {
            return response()->json(['message' => 'No se mandó ninguna imagen.'], 422);
        }

        if (count($ids) > ImageAssignmentRunHelper::MAXIMO_EN_LOTE) {
            return response()->json(['message' => 'Se pueden resolver hasta '.ImageAssignmentRunHelper::MAXIMO_EN_LOTE.' imágenes por vez.'], 422);
        }

        return $ids;
    }

    /**
     * Traduce el resultado de aprobar / rechazar / quitar.
     *
     * @param  array $resultado  ['status', 'message', 'item']
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_item(array $resultado)
    {
        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return response()->json(['model' => ImageAssignmentRunHelper::payload_de_item($resultado['item']->fresh())], 200);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function no_encontrada()
    {
        return response()->json(['message' => 'No se encontró esa asignación de imágenes.'], 404);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function solo_acceso_maestro()
    {
        return response()->json(['message' => 'Solo el acceso maestro de ComercioCity puede hacer esto.'], 403);
    }
}
