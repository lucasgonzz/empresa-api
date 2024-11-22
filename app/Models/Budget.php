<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $guarded = [];

    protected $dates = ['start_at', 'finish_at'];

    function scopeWithAll($query) {
        $query->with('client.iva_condition', 'client.price_type', 'articles.article_variants', 'budget_status', 'discounts', 'surchages');
        // $query->with('client.iva_condition', 'client.price_type', 'articles', 'budget_status', 'optional_order_production_statuses');
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

    function budget_status() {
        return $this->belongsTo('App\Models\BudgetStatus');
    }

    function products() {
        return $this->hasMany('App\Models\BudgetProduct');
    }

    function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'bonus', 'location', 'price');
    }

    function optional_order_production_statuses() {
        return $this->belongsToMany('App\Models\OrderProductionStatus');
    }

}
