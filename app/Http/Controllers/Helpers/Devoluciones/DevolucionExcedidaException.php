<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

/**
 * Una devolución intenta devolver más unidades de las que la venta tiene sin devolver.
 *
 * Se lanza desde adentro de la transacción (después del candado sobre la venta) para que el
 * controlador la distinga de cualquier otro error: revierte y responde 422 con el motivo, en vez
 * del 500 genérico. Ver ValidarDevolucionHelper.
 */
class DevolucionExcedidaException extends \Exception {

}
