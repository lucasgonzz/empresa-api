<?php

namespace App\Services\MercadoLibre;

use App\Http\Controllers\Helpers\Order\CreateSaleOrderHelper;
use App\Models\MeliOrder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * El ÚNICO camino que crea la venta de un pedido de Mercado Libre (revisión de la misión
 * mercado-libre-integracion, 27/10/2026).
 *
 * Había tres caminos que la creaban por su cuenta: la venta automática de la consulta periódica /
 * el webhook, el botón "Crear venta" (`MeLiOrderController::create_sale`) y pasar el pedido a
 * Confirmado (`MeLiOrderController::confirmar_pedido_meli`). Los dos últimos no tenían candado ni
 * re-chequeo: venta automática + el botón de una pantalla vieja = dos ventas y el stock
 * descontado dos veces (reproducido por el chequeo independiente).
 *
 * Acá: candado por pedido (`meli_order_<id>`, el mismo para los tres), relectura del pedido,
 * re-chequeo de que no tenga venta, y la venta junto con el paso a Confirmado en una transacción.
 * Las condiciones propias de cada camino (pagado, renglones vinculados, fecha) las decide el
 * llamador ANTES de llamar.
 */
class VentaDelPedidoMeli
{
    /** Estado interno "Confirmado" de `meli_order_statuses`. */
    const ESTADO_CONFIRMADO = 2;

    /** Segundos que se espera el candado si otro proceso está creando la misma venta. */
    const SEGUNDOS_DE_ESPERA = 15;

    /**
     * Crea la venta del pedido si todavía no tiene una. Si otro proceso la está creando, espera a
     * que termine y no crea otra.
     *
     * @param MeliOrder $meli_order
     * @param mixed $instance Controller (para `num()`), como pide `CreateSaleOrderHelper`.
     * @param \App\Models\User|null $user Dueño (en procesos sin sesión); null = el de la sesión.
     * @return bool true si la creó; false si ya tenía (o no se pudo tomar el candado).
     */
    public static function generar_si_falta(MeliOrder $meli_order, $instance, $user = null)
    {
        if ($meli_order->sale()->exists()) {
            return false;
        }

        $lock = Cache::lock('meli_order_'.$meli_order->id, 30);

        try {
            $lock->block(self::SEGUNDOS_DE_ESPERA);
        } catch (LockTimeoutException $e) {
            Log::warning('VentaDelPedidoMeli: el pedido '.$meli_order->id.' quedó tomado por otro proceso; no se crea la venta acá.');

            return false;
        }

        try {
            $meli_order->refresh();

            if ($meli_order->sale()->exists()) {
                return false;
            }

            $meli_order->load('articles');

            DB::transaction(function () use ($meli_order, $instance, $user) {
                CreateSaleOrderHelper::save_sale($meli_order, $instance, false, true, $user);

                $meli_order->meli_order_status_id = self::ESTADO_CONFIRMADO;
                $meli_order->save();
            });

            return true;
        } finally {
            $lock->release();
        }
    }
}
