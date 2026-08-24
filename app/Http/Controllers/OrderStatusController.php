<?php

namespace App\Http\Controllers;

use App\Models\OrderStatus;

/**
 * Estados de pedido: SOLO LECTURA.
 *
 * 🔴 `store`, `update` y `destroy` se borraron el 24/8/2026 por decision de Lucas. No los vuelvas a
 * agregar sin hablarlo: `OrderStatusHelper` decide si un pedido puede avanzar comparando los NOMBRES
 * de estas filas, asi que renombrar "Confirmado" deja todos los pedidos de ese comercio solo
 * cancelables, y borrar un estado deja tapiados a los que estaban en el.
 *
 * Cuando se sacaron no habia ninguna pantalla que los usara ni ningun consumidor en los seis repos.
 * Y `store` ademas estaba roto: devolvia 500. La ruta quedo acotada con `->only(['index', 'show'])`.
 */
class OrderStatusController extends Controller
{

    public function index() {
        $models = OrderStatus::orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderStatus', $id)], 200);
    }

}
