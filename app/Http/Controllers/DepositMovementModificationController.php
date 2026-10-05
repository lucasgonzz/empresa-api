<?php

namespace App\Http\Controllers;

use App\Models\DepositMovement;
use App\Models\DepositMovementModification;

/**
 * Historial de modificaciones de artículos de un movimiento de depósito (misión
 * movimientos-deposito-auditoria, 3/10/2026). Mismo patrón que `SaleModificationController`.
 *
 * Lo abre el botón "Modificaciones (N)" de cada movimiento en la SPA.
 */
class DepositMovementModificationController extends Controller
{
    /**
     * Devuelve las modificaciones de un movimiento, de la más vieja a la más nueva, con quién las
     * hizo y la foto de artículos antes/después.
     *
     * A diferencia de `SaleModificationController`, primero verifica que el movimiento sea del
     * dueño autenticado: en una base compartida por varios comercios, pedir el id de un
     * movimiento ajeno devuelve 404 y no su historial.
     *
     * @param  int  $deposit_movement_id
     * @return \Illuminate\Http\JsonResponse
     */
    function index($deposit_movement_id) {

        $es_del_duenio = DepositMovement::where('id', $deposit_movement_id)
                                    ->where('user_id', $this->userId())
                                    ->exists();

        if (!$es_del_duenio) {
            return response()->json(['message' => 'No se encontró el movimiento de depósito.'], 404);
        }

        $models = DepositMovementModification::where('deposit_movement_id', $deposit_movement_id)
                                    ->withAll()
                                    ->orderBy('created_at', 'ASC')
                                    ->orderBy('id', 'ASC')
                                    ->get();

        return response()->json(['models' => $models], 200);
    }
}
