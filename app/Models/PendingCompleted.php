<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Una ocurrencia de la Agenda marcada como hecha. Para una tarea puntual hay a lo sumo una fila;
 * para una recurrente hay una por cada ocurrencia que se marcó, identificada por
 * `pending_id` + la fecha de `fecha_realizacion` (la ocurrencia que se hizo, NO el día en que se
 * marcó: eso es `fecha_realizada`).
 *
 * `expense_id` / `expense_amount` quedan cargados solo si al marcarla se registró el gasto (ver
 * AgendaCompletarHelper::completar()).
 */
class PendingCompleted extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('pending.expense_concept', 'expense');
    }

    function pending() {
        return $this->belongsTo(Pending::class);
    }

    function expense() {
        return $this->belongsTo(Expense::class);
    }
}
