<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $guarded = [];

    protected $dates = ['start_at', 'finish_at'];


    function scopeWithAll($query) {
        /*
            `combos.articles` y no `combos` a secas, igual que `Sale::scopeWithAll()`: al confirmar
            el presupuesto, `BudgetHelper::attachSaleCombos()` tiene que descontar el stock de CADA
            articulo componente del combo. Sin los articulos cargados, ese descuento saldria a
            buscarlos de a uno (N+1) o —peor— no encontraria nada segun por donde entre el modelo.
        */
        $query->with('client.iva_condition', 'client.price_type', 'client.credit_accounts.moneda', 'articles.article_variants', 'budget_status', 'discounts', 'surchages', 'price_type', 'sale_status', 'services', 'promocion_vinotecas', 'combos.articles');
        // $query->with('client.iva_condition', 'client.price_type', 'articles', 'budget_status', 'optional_order_production_statuses');
    }

    public function services() {
        return $this->belongsToMany('App\Models\Service')->withPivot('discount', 'amount', 'price', 'returned_amount');
    }

    public function promocion_vinotecas() {
        return $this->belongsToMany(PromocionVinoteca::class)->withPivot('amount', 'price')->withTrashed();
    }

    /**
     * Combos del presupuesto (mision combos-y-rangos-de-precio, 16/9/2026).
     *
     * `withTrashed()` como en `promocion_vinotecas()` y en `articles()`: un combo borrado del ABM
     * despues de presupuestarlo tiene que seguir apareciendo en el presupuesto viejo, en su PDF y
     * en su total. Sin esto la linea desaparece del listado pero sigue contando en `total`, y el
     * presupuesto queda descuadrado sin que nada lo explique.
     */
    public function combos() {
        return $this->belongsToMany(Combo::class)->withPivot('amount', 'price')->withTrashed();
    }

    function discounts() {
        return $this->belongsToMany('App\Models\Discount')->withTrashed()->withPivot('percentage');
    }

    function surchages() {
        return $this->belongsToMany('App\Models\Surchage')->withTrashed()->withPivot('percentage');
    }

    function sale() {
        return $this->hasOne('App\Models\Sale');
    }

    function client() {
        return $this->belongsTo('App\Models\Client');
    }

    function price_type() {
        return $this->belongsTo(PriceType::class);
    }

    /**
     * Estado de venta asociado; se replica en la Sale al confirmar el presupuesto.
     */
    function sale_status() {
        return $this->belongsTo(SaleStatus::class);
    }

    function budget_status() {
        return $this->belongsTo('App\Models\BudgetStatus');
    }

    function products() {
        return $this->hasMany('App\Models\BudgetProduct');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'bonus', 'location', 'price', 'price_type_personalizado_id', 'cost', 'name');
    }

    function optional_order_production_statuses() {
        return $this->belongsToMany('App\Models\OrderProductionStatus');
    }

    function address() {
        return $this->belongsTo(Address::class);
    }

    function employee() {
        return $this->belongsTo(User::class, 'employee_id');
    }

}
