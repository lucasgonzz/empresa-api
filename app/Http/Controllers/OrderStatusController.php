<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\Order\OrderStatusHelper;
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
        $orden = OrderStatusHelper::ORDEN_VISUAL;

        /**
         * Se ordena por nombre contra OrderStatusHelper::ORDEN_VISUAL y no por `id`/`created_at`:
         * esas dos columnas no son estables entre instalaciones (cada base corre
         * OrderStatusSeeder por su cuenta). Un estado que no esta en la lista (resto de una
         * instalacion vieja, de cuando se podian crear a mano) se manda al final en vez de romper.
         */
        $models = OrderStatus::withAll()
                            ->get()
                            ->sortBy(function ($model) use ($orden) {
                                $pos = array_search($model->name, $orden);
                                return $pos === false ? count($orden) : $pos;
                            })
                            ->values();

        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('OrderStatus', $id)], 200);
    }

}
