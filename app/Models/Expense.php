<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $guarded = [
        'current_acount_payment_method_id', // Removing this from guarded as it will be handled by the many-to-many relationship
    ];

    /**
     * La categoria de un gasto NO es un dato que cargue el usuario: se deriva del concepto.
     *
     * Va en un hook del modelo y no en el controller a proposito. Los gastos se escriben desde
     * varios lugares (ExpenseController, la actualizacion masiva encolada que corre por
     * MasiveUpdateHelper con $model->save(), seeders), y si la derivacion viviera en uno solo de
     * esos caminos, los otros dejarian la columna desalineada del concepto sin que nada avise.
     * Aca es imposible: cualquier save() pasa por este hook.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($expense) {

            // Solo se recalcula si cambio el concepto, si alguien intento tocar la categoria a mano,
            // o si el registro es nuevo. Un save() que no toca ninguno de los dos campos no paga la query.
            if (
                !$expense->exists
                || $expense->isDirty('expense_concept_id')
                || $expense->isDirty('expense_category_id')
            ) {

                $expense_category_id = null;

                if (!is_null($expense->expense_concept_id)) {
                    $expense_concept = ExpenseConcept::find($expense->expense_concept_id);
                    if (!is_null($expense_concept)) {
                        $expense_category_id = $expense_concept->expense_category_id;
                    }
                }

                $expense->expense_category_id = $expense_category_id;
            }
        });
    }

    function scopeWithAll($q) {
        $q->with('current_acount_payment_methods');
    }

    function expense_concept() {
        return $this->belongsTo(ExpenseConcept::class);
    }

    /**
     * Habilita que SearchController::apply_order_filter() ordene la columna Categoria por el
     * NOMBRE de la categoria (busca un metodo de relacion llamado igual que el <algo> de
     * <algo>_id y exige que sea BelongsTo) en vez de por el id numerico.
     */
    function expense_category() {
        return $this->belongsTo(ExpenseCategory::class);
    }

    function current_acount_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'expense_current_acount_payment_method')
            ->withPivot('amount', 'caja_id', 'amount_cotizado', 'cotizacion', 'moneda_id')
            ->withTimestamps();
    }
}
