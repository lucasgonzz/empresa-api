<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ConceptoMovimientoCaja extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }

    /**
     * Los conceptos que ve un comercio: los del sistema (`user_id` NULL) más los propios del dueño.
     * Nunca los de otro dueño (misión destrabar-videos-centro, 16/10/2026).
     *
     * El `where` va AGRUPADO a propósito: cualquier condición que se encadene después (un `id`, un
     * filtro del buscador) queda AND'eada con el grupo entero y no solo con el `orWhere`.
     *
     * 🔴 `SearchController::query_base_del_modelo()` usa este scope si el modelo lo define (gancho
     * opt-in, mismo que `DepositMovementStatus`). Sin él, apenas la tabla tuvo `user_id` el buscador
     * genérico pasaba a filtrar `user_id = dueño` y los conceptos del sistema dejaban de salir.
     * También lo usa `ConceptoMovimientoCajaController` para el listado y para resolver un id.
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
