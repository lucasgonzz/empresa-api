<?php

namespace App\Http\Controllers;

use App\Models\DepositMovement;
use App\Models\DepositMovementStatus;
use Illuminate\Http\Request;

/**
 * ABM de los estados de los movimientos de depósito (ABM > Inventario).
 *
 * Desde la misión movimientos-deposito-auditoria (3/10/2026) los estados son de dos clases:
 *  - FIJOS (`user_id` NULL): "En proceso" y "Recibido", las filas globales que siempre existieron.
 *    Aparecen para todos los comercios y NO se renombran ni se borran: en las bases compartidas
 *    las usan 51 comercios a la vez, así que tocarlas le cambiaría el estado a movimientos ajenos.
 *  - PROPIOS (`user_id` = dueño): los que cada comercio crea, edita y borra.
 *
 * Los estados ya no mueven stock (eso lo hace el botón "Mover stock",
 * `DepositMovementController::move_stock()`): son etiquetas para seguir el traslado.
 *
 * Errores para la SPA: `{"message": "..."}` sin clave `errors` (la SPA lo muestra como toast).
 */
class DepositMovementStatusController extends Controller
{

    /**
     * Estados que puede usar el comercio autenticado: primero los fijos y después los propios,
     * cada grupo en el orden en que se crearon. Nunca los propios de otro dueño.
     *
     * 🔴 La firma NO puede pedir parámetros: `RecursosInicialesController` lo llama como
     * `$controller->index()` para armar los catálogos del arranque.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index() {

        // Fijos + propios del dueño: el mismo scope que usa el buscador (global-search) del ABM.
        $models = DepositMovementStatus::delDuenoConGlobales($this->userId())
                            // Fijos (user_id NULL) primero, después los propios.
                            ->orderByRaw('CASE WHEN user_id IS NULL THEN 0 ELSE 1 END')
                            ->orderBy('id', 'ASC')
                            ->withAll()
                            ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea un estado PROPIO del comercio autenticado.
     *
     * Se agrega un único chequeo que el plan no pedía: el nombre no puede venir vacío. La columna
     * `name` es NOT NULL, así que sin este chequeo un nombre vacío terminaba en un 500 de SQL.
     *
     * @param  \Illuminate\Http\Request  $request  `name`.
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {

        $nombre = trim((string) $request->name);

        if ($nombre === '') {
            return response()->json(['message' => 'Escribí el nombre del estado.'], 422);
        }

        $model = DepositMovementStatus::create([
            'name'      => $nombre,
            'user_id'   => $this->userId(),
        ]);

        $this->sendAddModelNotification('DepositMovementStatus', $model->id);

        return response()->json(['model' => $this->fullModel('DepositMovementStatus', $model->id)], 201);
    }

    /**
     * Un estado fijo o propio del comercio. El plan no lo pedía, pero `Route::resource` registra
     * la ruta GET `deposit-movement-status/{id}` y sin este método respondería un 500.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {

        $model = DepositMovementStatus::find($id);

        if (is_null($model)
            || (!is_null($model->user_id) && (int) $model->user_id !== (int) $this->userId())) {

            return response()->json(['message' => 'No se encontró el estado.'], 404);
        }

        return response()->json(['model' => $this->fullModel('DepositMovementStatus', $model->id)], 200);
    }

    /**
     * Renombra un estado PROPIO del comercio.
     *
     * - Fijo (`user_id` NULL) → 403: "En proceso" y "Recibido" no se tocan.
     * - De otro dueño → 404 (para ese comercio, el estado no existe).
     *
     * @param  \Illuminate\Http\Request  $request  `name`.
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {

        $error = $this->error_si_no_es_propio($id, 'modificar');

        if (!is_null($error)) {
            return $error;
        }

        $nombre = trim((string) $request->name);

        if ($nombre === '') {
            return response()->json(['message' => 'Escribí el nombre del estado.'], 422);
        }

        $model = DepositMovementStatus::find($id);
        $model->name = $nombre;
        $model->save();

        $this->sendAddModelNotification('DepositMovementStatus', $model->id);

        return response()->json(['model' => $this->fullModel('DepositMovementStatus', $model->id)], 200);
    }

    /**
     * Borra un estado PROPIO del comercio.
     *
     * - Fijo → 403. De otro dueño → 404.
     * - Propio pero EN USO por algún movimiento del dueño → 422: borrarlo dejaría esos movimientos
     *   apuntando a un estado que no existe (la SPA agrupa el listado por estado y no los
     *   mostraría). El usuario tiene que cambiarles el estado primero.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id) {

        $error = $this->error_si_no_es_propio($id, 'eliminar');

        if (!is_null($error)) {
            return $error;
        }

        $en_uso = DepositMovement::where('user_id', $this->userId())
                                    ->where('deposit_movement_status_id', $id)
                                    ->count();

        if ($en_uso > 0) {

            // Singular/plural: el texto del plan ("lo usan N movimientos") queda raro con N = 1.
            $cuantos = $en_uso == 1
                        ? 'lo usa 1 movimiento de depósito'
                        : 'lo usan '.$en_uso.' movimientos de depósito';

            return response()->json([
                'message' => 'Este estado '.$cuantos.'. Cambiales el estado antes de eliminarlo.',
            ], 422);
        }

        $model = DepositMovementStatus::find($id);
        $model->delete();

        $this->sendDeleteModelNotification('DepositMovementStatus', $model->id);

        return response(null);
    }

    /**
     * Guarda común de update y destroy: el estado tiene que existir y ser PROPIO del comercio.
     *
     * @param  int  $id
     * @param  string  $accion  "modificar" o "eliminar", para el texto del 403.
     * @return \Illuminate\Http\JsonResponse|null  La respuesta de error, o null si se puede seguir.
     */
    private function error_si_no_es_propio($id, $accion) {

        $model = DepositMovementStatus::find($id);

        if (is_null($model)) {
            return response()->json(['message' => 'No se encontró el estado.'], 404);
        }

        // Fijo del sistema: lo comparten todos los comercios de la base.
        if (is_null($model->user_id)) {
            return response()->json([
                'message' => 'Los estados En proceso y Recibido vienen con el sistema y no se pueden '.$accion.'.',
            ], 403);
        }

        // Propio de OTRO comercio: para este, no existe.
        if ((int) $model->user_id !== (int) $this->userId()) {
            return response()->json(['message' => 'No se encontró el estado.'], 404);
        }

        return null;
    }
}
