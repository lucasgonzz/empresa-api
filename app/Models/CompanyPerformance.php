<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPerformance extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        return $q->with('ingresos_mostrador', 'ingresos_cuenta_corriente', 'expense_concepts');
    }

    function gastos() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class);
    }

    function ingresos_mostrador() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_ingresos_mostrador')->withPivot('amount');
    }

    function ingresos_cuenta_corriente() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_ingresos_cuenta_corriente')->withPivot('amount');
    }

    function expense_concepts() {
        return $this->belongsToMany(ExpenseConcept::class)->withPivot('amount');
    }

    // Aca tiene en cuenta lo que ingreso por mostrador y por cuenta corriente
    function ingresos_totales() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class);
    }

    function article_performances() {
        return $this->hasMany(ArticlePerformance::class);
    }
}
