<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DimensionSearchRunHelper;
use App\Models\DimensionSearchRun;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * La búsqueda masiva de peso y medidas, para Alertas → Catálogo → Medidas de la SPA (misión
 * medidas-masivas-catalogo, 27/10/2026). Es el espejo de ImageAssignmentRunController.
 *
 * Solo traduce a HTTP: la lógica vive en DimensionSearchRunHelper.
 *
 * Todo filtrado por el DUEÑO (`$this->userId()`): un empleado ve las corridas de su comercio, y un id de
 * otro comercio responde 404 (no existe para él). «Todo el catálogo» (la previa y el lanzamiento), detener
 * y reanudar son solo para la sesión del acceso maestro: sin ella, 403 con `{message}`. Las rutas llevan el
 * middleware de la extensión `agentes_ia` (routes/api.php), igual que las de imágenes.
 *
 * FORMA DE LAS RESPUESTAS: los payloads van PLANOS (`{id, status, ...}` para una corrida, `{data, total, ...}`
 * para un listado paginado) y, por compatibilidad con la SPA de imágenes, también bajo `model` / `models`
 * (el mismo contenido): quien consume puede usar cualquiera de las dos formas.
 */
class DimensionSearchRunController extends Controller
{
    /**
     * GET dimension-search-runs?page=&per_page=25 — las corridas del dueño, más nuevas primero.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  Paginador de RunPayload (`data`, `total`, ...), `models` (igual) y `resumen`.
     */
    public function index(Request $request)
    {
        $resultado = DimensionSearchRunHelper::listado(
            $this->userId(),
            (int) $request->query('page', 1),
            (int) $request->query('per_page', DimensionSearchRunHelper::POR_PAGINA_DEFECTO)
        );

        $paginador = $resultado['paginador']->toArray();

        return response()->json(array_merge($paginador, ['models' => $paginador, 'resumen' => $resultado['resumen']]), 200);
    }

    /**
     * GET dimension-search-runs/resumen — liviano, para el badge de Alertas.
     *
     * @return \Illuminate\Http\JsonResponse  {a_revisar, sin_ver, badge, en_proceso}
     */
    public function resumen()
    {
        return response()->json(DimensionSearchRunHelper::resumen($this->userId()), 200);
    }

    /**
     * GET dimension-search-runs/{id} — una corrida; la marca vista si ya terminó.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  RunPayload (plano y también bajo `model`).
     */
    public function show($id)
    {
        $run = $this->corrida_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        DimensionSearchRunHelper::marcar_vista($run);

        return $this->con_modelo(DimensionSearchRunHelper::payload_de_corrida($run), 200);
    }

    /**
     * GET dimension-search-runs/{id}/items?solapa=asignadas|a_revisar|sin_datos&page=&per_page=25&buscar=
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  Paginador de ItemPayload (`data`, `total`, ...), `models` (igual) y `conteos`.
     */
    public function items(Request $request, $id)
    {
        $run = $this->corrida_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        $solapa = (string) $request->query('solapa', 'a_revisar');

        if (!array_key_exists($solapa, DimensionSearchRunHelper::SOLAPAS)) {
            return response()->json(['message' => 'La solapa tiene que ser asignadas, a_revisar o sin_datos.'], 422);
        }

        $resultado = DimensionSearchRunHelper::items_de(
            $run,
            $solapa,
            (int) $request->query('page', 1),
            (int) $request->query('per_page', DimensionSearchRunHelper::POR_PAGINA_DEFECTO),
            (string) $request->query('buscar', '')
        );

        $paginador = $resultado['paginador']->toArray();

        return response()->json(array_merge($paginador, ['models' => $paginador, 'conteos' => $resultado['conteos']]), 200);
    }

    /**
     * POST dimension-search-items/{id}/aprobar {valores?: {peso, alto, ancho, profundidad}}
     *
     * `valores` es opcional: lo que la persona editó en el lugar (kg / cm). Sin él se aprueba lo propuesto.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  ItemPayload | 404 | 409 {message, llenados, salteados} | 422 {message}
     */
    public function aprobar(Request $request, $id)
    {
        return $this->respuesta_de_item(DimensionSearchRunHelper::aprobar(
            $this->userId(),
            (int) $id,
            $this->userId(false),
            $request->input('valores')
        ));
    }

    /**
     * POST dimension-search-items/{id}/rechazar
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  ItemPayload | 404 | 422 {message}
     */
    public function rechazar($id)
    {
        return $this->respuesta_de_item(DimensionSearchRunHelper::rechazar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST dimension-search-items/{id}/quitar — solo asignada / aprobada → quitada.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  ItemPayload | 404 | 422 {message}
     */
    public function quitar($id)
    {
        return $this->respuesta_de_item(DimensionSearchRunHelper::quitar($this->userId(), (int) $id, $this->userId(false)));
    }

    /**
     * POST dimension-search-items/aprobar-varios {ids: []}
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

        $resultado = DimensionSearchRunHelper::en_lote('aprobar', $this->userId(), $ids, $this->userId(false));

        return response()->json(['aprobados' => $resultado['hechos'], 'fallidos' => $resultado['fallidos']], 200);
    }

    /**
     * POST dimension-search-items/rechazar-varios {ids: []}
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

        $resultado = DimensionSearchRunHelper::en_lote('rechazar', $this->userId(), $ids, $this->userId(false));

        return response()->json(['rechazados' => $resultado['hechos'], 'fallidos' => $resultado['fallidos']], 200);
    }

    /**
     * GET dimension-search-runs/catalogo/previa?solo_con_stock=1 — acceso maestro.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  Previa (ver DimensionSearchRunHelper::previa_del_catalogo()).
     */
    public function previa_catalogo(Request $request)
    {
        if (!DimensionSearchRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $owner = User::find($this->userId());

        if (is_null($owner)) {
            return response()->json(['message' => 'No se pudo identificar la cuenta.'], 422);
        }

        return response()->json(DimensionSearchRunHelper::previa_del_catalogo($owner, $this->booleano($request->query('solo_con_stock'))), 200);
    }

    /**
     * POST dimension-search-runs/catalogo {solo_con_stock?, limite?} — acceso maestro: lanza «buscar peso y
     * medidas de todo el catálogo».
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse  201 RunPayload | 403 | 422 {message}
     */
    public function crear_catalogo(Request $request)
    {
        if (!DimensionSearchRunHelper::es_acceso_maestro()) {
            return $this->solo_acceso_maestro();
        }

        $owner = User::find($this->userId());

        if (is_null($owner)) {
            return response()->json(['message' => 'No se pudo identificar la cuenta.'], 422);
        }

        $limite = $request->input('limite');

        $resultado = DimensionSearchRunHelper::crear_del_catalogo(
            $owner,
            $this->userId(false),
            $this->booleano($request->input('solo_con_stock')),
            is_numeric($limite) && (int) $limite > 0 ? (int) $limite : null
        );

        if ($resultado['status'] !== 201) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return $this->con_modelo(DimensionSearchRunHelper::payload_de_corrida($resultado['run']->fresh()), 201);
    }

    /**
     * POST dimension-search-runs/{id}/detener — solo el acceso maestro.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  RunPayload | 403 | 404 | 422 {message}
     */
    public function detener($id)
    {
        $run = $this->corrida_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        if (!$this->puede_detener_o_reanudar($run)) {
            return $this->solo_acceso_maestro();
        }

        $resultado = DimensionSearchRunHelper::detener($run);

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return $this->con_modelo(DimensionSearchRunHelper::payload_de_corrida($run->fresh()), 200);
    }

    /**
     * POST dimension-search-runs/{id}/reanudar — vale para detenida, fallida o trabada; solo el acceso maestro.
     *
     * @param  int $id
     * @return \Illuminate\Http\JsonResponse  RunPayload | 403 | 404 | 422 {message}
     */
    public function reanudar($id)
    {
        $run = $this->corrida_del_dueno($id);

        if (is_null($run)) {
            return $this->no_encontrada();
        }

        if (!$this->puede_detener_o_reanudar($run)) {
            return $this->solo_acceso_maestro();
        }

        $resultado = DimensionSearchRunHelper::reanudar($run);

        if ($resultado['status'] !== 200) {
            return response()->json(['message' => $resultado['message']], $resultado['status']);
        }

        return $this->con_modelo(DimensionSearchRunHelper::payload_de_corrida($run->fresh()), 200);
    }

    /**
     * ¿Puede la sesión actual detener o reanudar esta corrida? Las de todo el catálogo las lanzó el acceso
     * maestro y son solo suyas; las de selección (previstas, sin interfaz todavía), de cualquiera del comercio.
     *
     * @param  \App\Models\DimensionSearchRun $run
     * @return bool
     */
    protected function puede_detener_o_reanudar(DimensionSearchRun $run)
    {
        return $run->origen !== DimensionSearchRun::ORIGEN_CATALOGO || DimensionSearchRunHelper::es_acceso_maestro();
    }

    /**
     * La corrida con ese id, si es del dueño logueado.
     *
     * @param  mixed $id
     * @return \App\Models\DimensionSearchRun|null
     */
    protected function corrida_del_dueno($id)
    {
        return DimensionSearchRun::where('user_id', $this->userId())
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
            return response()->json(['message' => 'No se mandó ningún artículo.'], 422);
        }

        if (count($ids) > DimensionSearchRunHelper::MAXIMO_EN_LOTE) {
            return response()->json(['message' => 'Se pueden resolver hasta '.DimensionSearchRunHelper::MAXIMO_EN_LOTE.' artículos por vez.'], 422);
        }

        return $ids;
    }

    /**
     * Traduce el resultado de aprobar / rechazar / quitar.
     *
     * @param  array $resultado  ['status', 'message', 'item', 'extra']
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respuesta_de_item(array $resultado)
    {
        if ($resultado['status'] !== 200) {
            // Los errores llevan además lo que ayuda a la SPA (en el 409: qué se llenó y qué se salteó).
            return response()->json(array_merge(['message' => $resultado['message']], $resultado['extra']), $resultado['status']);
        }

        $payload = DimensionSearchRunHelper::payload_de_item($resultado['item']->fresh());

        // `llenados` / `salteados` (aprobar) y `revertidos` / `conservados` (quitar): campos extra opcionales.
        return $this->con_modelo(array_merge($payload, $resultado['extra']), 200);
    }

    /**
     * El payload plano y también bajo `model`.
     *
     * @param  array $payload
     * @param  int   $status
     * @return \Illuminate\Http\JsonResponse
     */
    protected function con_modelo(array $payload, $status)
    {
        return response()->json(array_merge($payload, ['model' => $payload]), $status);
    }

    /**
     * Un valor de query o de body como booleano («1», «true», true, «on»).
     *
     * @param  mixed $valor
     * @return bool
     */
    protected function booleano($valor)
    {
        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function no_encontrada()
    {
        return response()->json(['message' => 'No se encontró esa búsqueda de medidas.'], 404);
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    protected function solo_acceso_maestro()
    {
        return response()->json(['message' => 'Solo el acceso maestro de ComercioCity puede hacer esto.'], 403);
    }
}
