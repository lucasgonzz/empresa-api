<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];
    
    public function current_acount_payment_methods(){
        return $this->belongsToMany(CurrentAcountPaymentMethod::class)->withPivot('amount', 'discount_percentage', 'discount_amount');
    }

    public function impressions() {
        return $this->hasMany('App\Models\Impression');
    }

    function scopeWithAll($query) {
        $query->with('client.iva_condition', 'client.price_type', 'buyer.comercio_city_client', 'articles', 'impressions', 'discounts', 'surchages', 'afip_ticket', 'combos', 'order.cupon', 'services', 'employee', 'budget.articles', 'budget.client', 'budget.discounts', 'budget.surchages', 'current_acount_payment_method', 'order_production.client', 'order_production.articles', 'afip_errors', 'current_acount', 'current_acount_payment_methods');
    }

    function sale_modifications() {
        return $this->hasMany(SaleModification::class);
    }

    public function address() {
        return $this->belongsTo('App\Models\Address');
    }

    function afip_errors() {
        return $this->hasMany(AfipError::class);
    }

    public function budget() {
        return $this->belongsTo('App\Models\Budget');
    }

    public function order_production() {
        return $this->belongsTo('App\Models\OrderProduction');
    }

    public function sale_type() {
        return $this->belongsTo('App\Models\SaleType');
    }

    public function afip_information() {
        return $this->belongsTo('App\Models\AfipInformation');
    }

    public function afip_ticket() {
        return $this->hasOne('App\Models\AfipTicket');
    }

    public function user() {
        return $this->belongsTo('App\Models\User', 'user_id');
    }

    public function employee() {
        return $this->belongsTo('App\Models\User', 'employee_id');
    }

    public function current_acount() {
        return $this->hasOne('App\Models\CurrentAcount');
    }

    public function current_acounts() {
        return $this->hasMany('App\Models\CurrentAcount');
    }

    public function order() {
        return $this->belongsTo('App\Models\Order');
    }

    public function discounts() {
        return $this->belongsToMany('App\Models\Discount')->withTrashed()->withPivot('percentage');
    }

    public function surchages() {
        return $this->belongsToMany('App\Models\Surchage')->withTrashed()->withPivot('percentage');
    }

    public function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'cost', 'price', 'returned_amount', 'delivered_amount', 'discount', 'with_dolar', 'checked_amount')->withTrashed();
    }

    public function combos() {
        return $this->belongsToMany('App\Models\Combo')->withPivot('amount', 'price',);
    }

    public function services() {
        return $this->belongsToMany('App\Models\Service')->withPivot('discount', 'amount', 'price', 'returned_amount');
    }

    public function client() {
        return $this->belongsTo('App\Models\Client');
    }

    public function buyer() {
        return $this->belongsTo('App\Models\Buyer');
    }

    public function current_acount_payment_method() {
        return $this->belongsTo('App\Models\CurrentAcountPaymentMethod');
    }

    public function seller_commissions() {
        return $this->hasMany('App\Models\SellerCommission');
    }

}
