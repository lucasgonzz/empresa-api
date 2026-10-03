<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\DepositMovementHelper;
use App\Http\Controllers\Pdf\DepositMovementPdf;
use App\Models\DepositMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Movimientos de stock entre depósitos.
 *
 * Desde la misión movimientos-deposito-auditoria (3/10/2026):
 *  - El stock NO se mueve al cambiar el estado (ni al crear el movimiento, aunque venga
 *    "Recibido"). Se mueve con `move_stock()` → `POST deposit-movement/{id}/move-stock`, que
 *    registra quién y cuándo. Desde ahí los artículos y los depósitos quedan bloqueados y el
 *    movimiento no se puede eliminar.
 *  - Cada cambio de artículos de un movimiento ya creado queda como una
 *    `DepositMovementModification` (foto antes/después).
 *  - Permisos con efecto: `deposit_movement.update` (datos: estado, depósitos, empleado, notas),
 *    `deposit_movement.update_articles` (artículos) y `deposit_movement.move_stock`. El dueño y
 *    los empleados con `admin_access` pueden todo. Ver, crear y eliminar siguen sin chequeo de
 *    permiso (en el catálogo están en "Sin efecto por ahora").
 *
 * Errores para la SPA: `{"message": "..."}` sin clave `errors` (la SPA lo muestra como toast).
 */
class DepositMovementController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = DepositMovement::where('user_id', $this->userId())
                        ->orderBy('created_at', 'DESC')
                        ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    /**
     * Movimientos "en curso" del empleado autenticado (alertas): los suyos cuyo stock TODAVÍA no
     * se movió.
     *
     * Antes filtraba por `deposit_movement_status_id = 1` ("En proceso"). Con estados propios eso
     * ya no alcanza, y para los datos existentes es equivalente: antes de esta misión "no movido"
     * era lo mismo que "En proceso". Se suma además el filtro por dueño, que faltaba.
     *
     * "No movido" son las DOS marcas vacías (`stock_moved_at` y `recibido_at`), el mismo criterio
     * que `DepositMovementHelper::stock_movido()`: uno que trasladó la versión anterior del sistema
     * solo tiene `recibido_at`.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    function en_curso() {
        $models = DepositMovement::where('user_id', $this->userId())
                                    ->where('employee_id', $this->userId(false))
                                    ->whereNull('stock_moved_at')
                                    ->whereNull('recibido_at')
                                    ->orderBy('created_at', 'ASC')
                                    ->withAll()
                                    ->get();

        return response()->json(['models' => $models], 200);
    }

    /**
     * Crea el movimiento con sus artículos. Crear NUNCA mueve stock, aunque el estado que venga
     * sea "Recibido": el traslado es el botón "Mover stock". Tampoco cuenta como modificación.
     *
     * - Un estado que no es fijo ni del dueño → 422, antes de escribir nada.
     * - `recibido_at` NO se toma del request: queda NULL. Es la marca de "stock movido" compartida
     *   con la versión anterior del sistema y solo la escribe "Mover stock" (ver
     *   `DepositMovementHelper::stock_movido()`).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request) {

        $error = DepositMovementHelper::error_de_estado($request->all(), $this->userId());

        if (!is_null($error)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $model = DepositMovement::create([
            'num'                   		=> $this->num('deposit_movements'),
            'from_address_id'               => $request->from_address_id,
            'to_address_id'                 => $request->to_address_id,
            'employee_id'                 	=> $request->employee_id,
            'deposit_movement_status_id'    => $request->deposit_movement_status_id,
            'recibido_at'                 	=> null,
            'notes'                 		=> $request->notes,
            'user_id'               		=> $this->userId(),
        ]);

        $helper = new DepositMovementHelper($model);
        $helper->attach_articles($request->articles);

        $this->sendAddModelNotification('DepositMovement', $model->id);
        return response()->json(['model' => $this->fullModel('DepositMovement', $model->id)], 201);
    }

    /**
     * Un movimiento del dueño autenticado. El de otro comercio de la misma base → 404.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id) {

        $es_del_duenio = DepositMovement::where('id', $id)
                                    ->where('user_id', $this->userId())
                                    ->exists();

        if (!$es_del_duenio) {
            return response()->json(['message' => 'No se encontró el movimiento de depósito.'], 404);
        }

        return response()->json(['model' => $this->fullModel('DepositMovement', $id)], 200);
    }

    /**
     * Edita un movimiento.
     *
     * Reglas (el detalle está en `DepositMovementHelper::error_de_actualizacion()`):
     *  - Sin ninguno de los dos permisos de edición → 403.
     *  - Artículos distintos de los guardados: con el stock movido → 422; sin
     *    `deposit_movement.update_articles` → 403.
     *  - Datos distintos de los guardados: sin `deposit_movement.update` → 403; un estado nuevo que
     *    no es fijo ni del dueño → 422; con el stock movido, si cambian los depósitos → 422.
     *  - `recibido_at` no se toma del request (ver `DepositMovementHelper::guardar_datos()`).
     *
     * Todas las validaciones corren ANTES de escribir nada, dentro de una transacción y con la
     * fila bloqueada (`lockForUpdate`), para que un "Mover stock" simultáneo no se cuele entre la
     * validación y la escritura.
     *
     * Si los artículos cambiaron se guarda una `DepositMovementModification` (foto antes, sync,
     * foto después). Si no cambiaron, el pivot no se toca y no hay modificación.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function update(Request $request, $id) {

        $puede_datos = $this->tiene_permiso('deposit_movement.update');
        $puede_articulos = $this->tiene_permiso('deposit_movement.update_articles');

        if (!$puede_datos && !$puede_articulos) {
            return response()->json(['message' => 'No tenés permiso para editar movimientos de depósito.'], 403);
        }

        $owner_id = $this->userId();
        $user_id = $this->userId(false);
        $datos = $request->all();

        $error = DB::transaction(function () use ($id, $owner_id, $user_id, $datos, $puede_datos, $puede_articulos) {

            $model = DepositMovement::where('id', $id)
                                    ->where('user_id', $owner_id)
                                    ->lockForUpdate()
                                    ->first();

            if (is_null($model)) {
                return ['status' => 404, 'message' => 'No se encontró el movimiento de depósito.'];
            }

            $helper = new DepositMovementHelper($model);

            $articles = array_key_exists('articles', $datos) ? $datos['articles'] : null;

            $articulos_cambiaron = $helper->articulos_cambiaron($articles);

            // 1) Validar todo. Hasta acá no se escribió nada.
            $error = $helper->error_de_actualizacion($datos, $articulos_cambiaron, $puede_datos, $puede_articulos);

            if (!is_null($error)) {
                return $error;
            }

            // 2) Datos (estado, depósitos, empleado, notas): solo con su permiso.
            if ($puede_datos) {
                $helper->guardar_datos($datos);
            }

            // 3) Artículos: foto antes → sync → modificación con la foto después.
            if ($articulos_cambiaron) {
                $foto_antes = $helper->foto_de_articulos();
                $helper->attach_articles($articles);
                $helper->registrar_modificacion($foto_antes, $user_id);
            }

            return null;
        });

        if (!is_null($error)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $this->sendAddModelNotification('DepositMovement', $id);
        return response()->json(['model' => $this->fullModel('DepositMovement', $id)], 200);
    }

    /**
     * El botón "Mover stock" (`POST deposit-movement/{id}/move-stock`).
     *
     * 1. Sin el permiso `deposit_movement.move_stock` (y sin ser dueño/admin) → 403.
     * 2. Dentro de una transacción, con la fila bloqueada: movimiento de otro dueño → 404; ya
     *    movido (por este botón o por una versión anterior), sin artículos, sin alguno de los dos
     *    depósitos, u origen igual a destino → 422.
     * 3. Marca `stock_moved_at` / `stock_moved_user_id` (el usuario autenticado) —y `recibido_at`
     *    si estaba vacío, para la versión anterior— y traslada: un StockMovement "Mov entre
     *    depositos" por artículo. No cambia el estado.
     *
     * El `lockForUpdate` es lo que impide trasladar dos veces con dos clics seguidos (o dos
     * pestañas): el segundo request espera al primero y, cuando lee la fila, ya la ve movida.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    function move_stock($id) {

        if (!$this->tiene_permiso('deposit_movement.move_stock')) {
            return response()->json(['message' => 'No tenés permiso para mover el stock de un movimiento de depósito.'], 403);
        }

        $owner_id = $this->userId();
        $user_id = $this->userId(false);

        $error = DB::transaction(function () use ($id, $owner_id, $user_id) {

            $model = DepositMovement::where('id', $id)
                                    ->where('user_id', $owner_id)
                                    ->lockForUpdate()
                                    ->first();

            if (is_null($model)) {
                return ['status' => 404, 'message' => 'No se encontró el movimiento de depósito.'];
            }

            $helper = new DepositMovementHelper($model);

            $error = $helper->error_para_mover_stock();

            if (!is_null($error)) {
                return $error;
            }

            $helper->mover_stock($user_id);

            return null;
        });

        if (!is_null($error)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $this->sendAddModelNotification('DepositMovement', $id);
        return response()->json(['model' => $this->fullModel('DepositMovement', $id)], 200);
    }

    /**
     * Elimina un movimiento del dueño. Con el stock ya movido (por "Mover stock" o por una versión
     * anterior del sistema) → 422: borrarlo no devuelve el stock, así que el registro del traslado
     * tiene que quedar.
     *
     * Va en una transacción con la fila bloqueada (`lockForUpdate`), igual que move-stock: si los
     * dos llegan a la vez, uno espera al otro y el segundo ve el estado real (no se borra un
     * movimiento que se acaba de trasladar).
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function destroy($id) {

        $owner_id = $this->userId();

        $error = DB::transaction(function () use ($id, $owner_id) {

            $model = DepositMovement::where('id', $id)
                                    ->where('user_id', $owner_id)
                                    ->lockForUpdate()
                                    ->first();

            if (is_null($model)) {
                return ['status' => 404, 'message' => 'No se encontró el movimiento de depósito.'];
            }

            $helper = new DepositMovementHelper($model);

            if ($helper->stock_movido()) {
                return ['status' => 422, 'message' => 'No se puede eliminar un movimiento cuyo stock ya se movió.'];
            }

            ImageController::deleteModelImages($model);
            $model->delete();

            return null;
        });

        if (!is_null($error)) {
            return response()->json(['message' => $error['message']], $error['status']);
        }

        $this->sendDeleteModelNotification('DepositMovement', $id);
        return response(null);
    }

    function pdf($id) {
        $model = DepositMovement::find($id);

        if (is_null($model)) {
            abort(404);
        }

        $pdf = new DepositMovementPdf($model);
    }

    /**
     * ¿El usuario autenticado tiene este permiso? El dueño y los empleados con `admin_access`
     * pueden todo. Mismo patrón que `RecordatorioCobroController::check_permiso()`.
     *
     * @param  string  $slug
     * @return bool
     */
    private function tiene_permiso($slug) {

        if ($this->is_admin()) {
            return true;
        }

        return $this->user(false)->permissions->contains('slug', $slug);
    }
}
