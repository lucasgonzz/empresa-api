<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una modificación de los ARTÍCULOS de un movimiento de depósito ya creado (misión
 * movimientos-deposito-auditoria, 3/10/2026).
 *
 * Se crea una por cada PUT que agrega, quita o cambia la cantidad de algún renglón del movimiento
 * (lo decide `DepositMovementHelper::articulos_cambiaron()`). Guarda quién (`user_id`, el usuario
 * autenticado: empleado o dueño) y cuándo (`created_at`), y la foto completa de los artículos
 * antes y después en dos pivots, igual que `SaleModification` en las ventas.
 *
 * Crear el movimiento no genera ninguna; cambiar solo estado, depósitos o notas tampoco.
 */
class DepositMovementModification extends Model
{
    protected $guarded = [];

    /**
     * Todo lo que la SPA necesita para mostrar el historial: quién lo hizo y los artículos de
     * antes y de después, con sus variantes (para mostrar el nombre de la variante del renglón).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return void
     */
    function scopeWithAll($query) {
        $query->with(
            'user:id,name',
            'articulos_antes.article_variants',
            'articulos_despues.article_variants'
        );
    }

    /**
     * El movimiento de depósito modificado.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function deposit_movement() {
        return $this->belongsTo(DepositMovement::class);
    }

    /**
     * Quién hizo la modificación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function user() {
        return $this->belongsTo(User::class);
    }

    /**
     * Foto de los artículos del movimiento justo ANTES de la modificación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function articulos_antes() {
        return $this->belongsToMany(Article::class, 'article_deposit_movement_modification_antes')
                    ->withPivot('amount', 'article_variant_id')
                    ->withTimestamps();
    }

    /**
     * Foto de los artículos del movimiento justo DESPUÉS de la modificación.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function articulos_despues() {
        return $this->belongsToMany(Article::class, 'article_deposit_movement_modification_despues')
                    ->withPivot('amount', 'article_variant_id')
                    ->withTimestamps();
    }
}
