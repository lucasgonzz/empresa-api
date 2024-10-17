<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPerformance extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        return $q->with('ingresos_mostrador', 'ingresos_cuenta_corriente', 'expense_concepts', 'gastos', 'users_payment_methods');
    }

    function users_total_vendido() {
        return $this->belongsToMany(User::class)->withPivot('total_vendido');
    }

    function users_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_user_payment_method')->withPivot('amount', 'user_id');
    }

    function addresses_payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_address_payment_method')->withPivot('amount', 'address_id');
    }

    function gastos() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_gasto')->withPivot('amount');
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
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'company_performance_ingresos_total')->withPivot('amount');
    }

    function article_performances() {
        return $this->hasMany(ArticlePerformance::class);
    }
}
