<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('images', 'iva', 'sizes', 'colors', 'condition', 'descriptions', 'category', 'sub_category', 'tags', 'brand', 'article_discounts', 'provider_price_list', 'deposits');
    }

    function views() {
        return $this->morphMany('App\View', 'viewable');
    }

    function deposits() {
        return $this->belongsToMany('App\Models\Deposit')->withPivot('value');
    }

    function prices_lists() {
        return $this->belongsToMany('App\Models\PricesList');
    }

    function provider_price_list() {
        return $this->belongsTo('App\Models\ProviderPriceList');
    }

    function recipe() {
        return $this->hasOne('App\Models\Recipe');
    }

    function article_discounts() {
        return $this->hasMany('App\Models\ArticleDiscount');
    }

    function combos() {
        return $this->belongsToMany('App\Models\Article');
    }

    function brand() {
        return $this->belongsTo('App\Models\Brand');
    }

    function iva() {
        return $this->belongsTo('App\Models\Iva');
    }

    function descriptions() {
        return $this->hasMany('App\Models\Description');
    }

    function tags() {
        return $this->belongsToMany('App\Models\Tag');
    }

    function sizes() {
        return $this->belongsToMany('App\Models\Size');
    }

    function colors() {
        return $this->belongsToMany('App\Models\Color');
        // return $this->belongsToMany('App\Models\Color')->withPivot('amount');
    }

    function condition() {
        return $this->belongsTo('App\Models\Condition');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }

    function category() {
        return $this->belongsTo('App\Models\Category');
    }

    function sub_category() {
        return $this->belongsTo('App\Models\SubCategory');
    }

    function marker() {
        return $this->hasOne('App\Models\Marker');
    }

    function images() {
        return $this->morphMany('App\Models\Image', 'imageable');
    }

    function sub_user() {
        return $this->belongsTo('App\Models\User', 'sub_user_id');
    }
    
    function updated_by() {
        return $this->belongsTo('App\Models\User', 'updated_by', 'id');
    }

    function sales() {
        return $this->belongsToMany('App\Models\Sale')->latest();
    }

    function budgets() {
        return $this->belongsToMany('App\Models\Budget')->latest();
    }

    function order_productions() {
        return $this->belongsToMany('App\Models\OrderProduction')->latest();
    }

    function provider_orders() {
        return $this->belongsToMany('App\Models\ProviderOrder')->latest();
    }

    function recipes() {
        return $this->belongsToMany('App\Models\Recipe')->latest();
    }
    
    function providers(){
        return $this->belongsToMany('App\Models\Provider')->withPivot('amount', 'cost', 'price')
                                                    ->withTimestamps();
    }
    
    function provider(){
        return $this->belongsTo('App\Models\Provider');
    }

    function questions() {
        return $this->hasMany('App\Models\Question');
    }
}
