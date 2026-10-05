<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DepositMovement extends Model
{
    protected $guarded = [];

    /**
     * Lo que la SPA necesita de cada movimiento.
     *
     * Desde la misión movimientos-deposito-auditoria (3/10/2026) suma:
     *  - `stock_moved_user`: quién apretó "Mover stock" (solo id y nombre, para el aviso "Stock
     *    movido el ... por ..."). NULL si el stock no se movió o si es un movimiento viejo.
     *  - `deposit_movement_modifications_count`: cuántas veces se modificaron los artículos
     *    después de crearlo. La SPA muestra el botón de historial solo si es mayor que 0.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $q
     * @return void
     */
    function scopeWithAll($q) {
        $q->with('articles.article_variants', 'articles.addresses', 'stock_moved_user:id,name')
          ->withCount('deposit_movement_modifications');
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount', 'article_variant_id');
    }

    function deposit_movement_status() {
        return $this->belongsTo(DepositMovementStatus::class);
    }

    function from_address() {
        return $this->belongsTo(Address::class, 'from_address_id');
    }

    function to_address() {
        return $this->belongsTo(Address::class, 'to_address_id');
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }

    function stock_suggestion() {
        return $this->belongsTo(StockSuggestion::class);
    }

    /**
     * Historial de modificaciones de artículos del movimiento (una por cada PUT que agregó, quitó
     * o cambió la cantidad de algún renglón después de crearlo).
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function deposit_movement_modifications() {
        return $this->hasMany(DepositMovementModification::class);
    }

    /**
     * Quién apretó "Mover stock" (columna `stock_moved_user_id`). NULL en los movimientos viejos,
     * que quedaron marcados como movidos por la migración sin saber quién los recibió.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function stock_moved_user() {
        return $this->belongsTo(User::class, 'stock_moved_user_id');
    }
}
