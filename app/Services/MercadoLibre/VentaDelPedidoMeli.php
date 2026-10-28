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
 * Acá: candado por pedido (`meli_order_<id>`, el mismo para los tres) y, adentro de una
 * transacción, la FILA del pedido tomada con `lockForUpdate` (lo que de verdad serializa dos
 * procesos aunque el cache sea `array`), el re-chequeo de que no tenga venta, y la venta junto con
 * el paso a Confirmado.
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
     * Estados de Mercado Libre en los que un pedido NO lleva venta, por ningún camino: cancelado,
     * cancelándose (la cancelación está en curso) o inválido. Misión mercado-libre-pruebas
     * (27/10/2026): el botón "Crear venta" la creaba igual y bajaba el stock de algo que no se
     * vendió. Lo lee también la SPA (`meli_order_sin_venta_posible`, mismos tres estados).
     *
     * @var array<int, string>
     */
    const ESTADOS_SIN_VENTA = ['cancelled', 'pending_cancel', 'invalid'];

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
            return (bool) DB::transaction(function () use ($meli_order, $instance, $user) {
                // 🔴 El candado de verdad es la FILA del pedido (revisión de merge, 27/10/2026): con
                // `CACHE_DRIVER=array` (clientes viejos) el `Cache::lock` de arriba no cubre dos
                // procesos distintos (el scheduler y un listado abierto). `lockForUpdate` hace que
                // el segundo espere acá hasta que el primero confirme, y recién ahí re-chequea.
                $bloqueado = MeliOrder::where('id', $meli_order->id)->lockForUpdate()->first();

                if (!$bloqueado || $bloqueado->sale()->exists()) {
                    return false;
                }

                // El estado se vuelve a mirar ACÁ, con la fila tomada: si la consulta periódica lo
                // pasó a cancelado entre el chequeo del llamador y este punto, no se crea la venta.
                if (in_array((string) $bloqueado->status, self::ESTADOS_SIN_VENTA, true)) {
                    return false;
                }

                $meli_order->refresh();
                $meli_order->load('articles');

                CreateSaleOrderHelper::save_sale($meli_order, $instance, false, true, $user);

                $meli_order->meli_order_status_id = self::ESTADO_CONFIRMADO;
                $meli_order->save();

                return true;
            });
        } finally {
            $lock->release();
        }
    }
}
