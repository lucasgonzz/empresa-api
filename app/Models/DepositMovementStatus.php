<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado de un movimiento de depósito.
 *
 * Desde la misión movimientos-deposito-auditoria (3/10/2026): `user_id` NULL = estado FIJO del
 * sistema ("En proceso", "Recibido"), compartido por todos los comercios de la base; con valor =
 * estado PROPIO de ese dueño. Los estados son etiquetas: ninguno mueve stock.
 */
class DepositMovementStatus extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }

    /**
     * Los estados que puede ver y usar un comercio: los fijos del sistema (`user_id` NULL) más los
     * propios del dueño. Nunca los propios de otro dueño.
     *
     * El `where` va AGRUPADO a propósito: cualquier condición que se encadene después (un `id`,
     * un filtro del buscador) queda AND'eada con el grupo entero y no solo con el `orWhere`.
     *
     * 🔴 `SearchController::query_base_del_modelo()` usa este scope si el modelo lo define (gancho
     * opt-in): es lo que hace que el ABM de estados, que lista por `global-search`, muestre los
     * fijos. También lo usan `DepositMovementStatusController::index()` y la validación del estado
     * de un movimiento (`DepositMovementHelper::estado_valido()`).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @param  int  $user_id  Id del DUEÑO del comercio.
     * @return \Illuminate\Database\Eloquent\Builder
     */
    function scopeDelDuenoConGlobales($q, $user_id) {
        return $q->where(function ($query) use ($user_id) {
            $query->whereNull('user_id')
                  ->orWhere('user_id', $user_id);
        });
    }
}
