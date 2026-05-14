<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Caja registradora / arqueo del comercio.
 * `users`: empleados que pueden usar la caja en ventas y cobros.
 * `treasury_users`: empleados que ven la caja en el módulo de tesorería; si está vacío, el front usa `users`.
 */
class Caja extends Model
{
    protected $guarded = [];

    /**
     * Eager load estándar para respuestas API (fullModel / index).
     *
     * @param \Illuminate\Database\Eloquent\Builder $q
     * @return void
     */
    function scopeWithAll($q) {
        $q->with('current_acount_payment_methods', 'users', 'treasury_users', 'employee');
    }

    function current_acount_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class);
    }

    /**
     * Empleados con permiso de uso en vender y selectores de caja por método de pago.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function users() {
        return $this->belongsToMany(User::class);
    }

    /**
     * Empleados que pueden ver la caja en el listado de tesorería (tabla de cajas).
     * Tabla pivot: `caja_treasury_user`. Lista vacía en API implica fallback a `users` en el cliente.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    function treasury_users() {
        return $this->belongsToMany(User::class, 'caja_treasury_user');
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }
}
