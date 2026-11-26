<?php

namespace App\Http\Controllers\Helpers\Order;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Eliminación de pedidos de la tienda online.
 *
 * Reglas (casos del borrado):
 *  - Pedido sin venta y sin reserva: sólo se borra el pedido.
 *  - Pedido sin venta con reserva activa: se liberan las filas de reserva y se borra el pedido
 *    (el stock físico no se mueve; el disponible vuelve).
 *  - Pedido sin venta con egreso directo (`reserva_stock_web_egreso`): se devuelve exactamente
 *    lo egresado, una sola vez, y se borra el pedido.
 *  - Pedido con venta (en cualquier estado): se libera la reserva viva si quedara (venta en
 *    revisión) y se borra el pedido. La venta queda INTACTA: no se borra, no se edita, no se
 *    toca su stock, caja, cuenta corriente, factura ni su `order_id`.
 *  - Comercio sin esquema de reservas: `ReservaStockWebHelper` no hace nada y sólo se borra el pedido.
 *
 * Este helper NO lee ni bloquea la venta y NO llama a DeleteSaleHelper, a propósito.
 */
class DeleteOrderHelper
{
    /**
     * Elimina un pedido liberando su reserva o devolviendo su egreso, dentro de una transacción.
     *
     * Orden de candados: configuración -> pedido (el mismo que usan la edición de venta y
     * DeleteSaleHelper), así que no hay ciclos de espera.
     *
     * @param  int  $order_id  Id del pedido a eliminar.
     * @param  int  $user_id   Id del dueño del comercio (para el candado de configuración).
     * @return \App\Models\Order|null  El pedido borrado, o null si ya no existía
     *                                 (por ejemplo, otra pestaña lo borró mientras se esperaba el candado).
     */
    static function eliminar_pedido($order_id, $user_id) {

        return DB::transaction(function () use ($order_id, $user_id) {

            // Primer candado: configuración de reservas del comercio.
            ReservaStockWebHelper::bloquear_configuracion($user_id);

            // Segundo candado: el propio pedido.
            $pedido = Order::where('id', $order_id)->lockForUpdate()->first();

            // Ya no existe: el controlador contesta 404.
            if (!$pedido) {
                return null;
            }

            // Devuelve el egreso (si lo hubo) y libera las reservas vivas; no-op en el resto de los casos.
            ReservaStockWebHelper::cancelar($pedido);

            // Borrado físico del pedido (Order no usa SoftDeletes).
            $pedido->delete();

            return $pedido;
        });
    }
}
