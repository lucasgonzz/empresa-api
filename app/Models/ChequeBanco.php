<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un banco del catálogo de cheques del comercio (misión cheques-endoso-y-bancos, 21/9/2026).
 *
 * Reemplaza al texto libre `cheques.banco` como dato unificado: el cheque lo referencia por
 * `cheque_banco_id` y el texto sigue viajando al lado, para la SPA anterior y para los lugares que
 * leen `banco` (Excel, mostrador). Es por dueño (`user_id`), como ExpenseCategory.
 */
class ChequeBanco extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {

    }
}
