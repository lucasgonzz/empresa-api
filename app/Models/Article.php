<?php

namespace App\Models;

use App\Http\Controllers\Helpers\UserHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use SoftDeletes;
    
    protected $guarded = [];

    // protected $appends = ['costo_real'];

    function scopeWithAll($query) {
        $query->with('images', 'iva', 'sizes', 'colors', 'condition', 'descriptions', 'category', 'sub_category', 'tags', 'brand', 'article_discounts', 'provider_price_list', 'deposits', 'article_properties.article_property_values', 'article_variants.article_property_values', 'article_variants.addresses', 'addresses', 'price_types', 'article_discounts_blanco', 'article_surchages', 'article_surchages_blanco');
    }

    function price_changes() {
        return $this->hasMany(PriceChange::class);
    }

    function tipo_envase() {
        return $this->belongsTo(TipoEnvase::class);
    }

    // public function getCostoRealAttribute() {
    //     return $this->cost;
    //     if (!is_null(Auth()->user())) {
    //         $owner = UserHelper::user();
    //         $cost = $this->cost;
    //         if (!is_null($this->cost) && !is_null($owner)) {
    //             if ($this->cost_in_dollars) {
    //                 if (!is_null($this->provider) && !is_null($this->provider->dolar)) {
    //                     $cost = $cost * (float)$this->provider->dolar;
    //                 } else {
    //                     $cost = $cost * $owner->dollar;
    //                 }
    //             }
    //             foreach ($this->article_discounts as $discount) {
    //                 $cost -= $cost * (float)$discount->percentage / 100;
    //             }
    //             if (!is_null($this->iva) 
    //                 && !$owner->iva_included
    //                 && $this->iva->percentage != 0 
    //                 && $this->iva->percentage != 'Extento'
    //                 && $this->iva->percentage != 'No Gravado') {
    //                 $cost += $cost * (float)$this->iva->percentage / 100;
    //             }
    //         }
    //         return $cost;
    //     }
    // }

    function unidad_medida() {
        return $this->belongsTo(UnidadMedida::class);
    }

    function stock_movements() {
        return $this->hasMany(StockMovement::class);
    }

    function price_types() {
        return $this->belongsToMany(PriceType::class)->withPivot('percentage', 'price', 'final_price');
    }

    function cart() {
        return $this->belongsToMany(Cart::class)->using(ArticleCart::class);
    }

    function addresses() {
        return $this->belongsToMany(Address::class)->withPivot('amount');
    }

    function article_properties() {
        return $this->hasMany(ArticleProperty::class);
    }

    function article_variants() {
        return $this->hasMany(ArticleVariant::class);
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

    function article_discounts_blanco() {
        return $this->hasMany('App\Models\ArticleDiscountBlanco');
    }

    function article_surchages() {
        return $this->hasMany('App\Models\ArticleSurchage');
    }

    function article_surchages_blanco() {
        return $this->hasMany('App\Models\ArticleSurchageBlanco');
    }

    function combos() {
        return $this->belongsToMany('App\Models\Article')->withTrashed();
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
        return $this->belongsToMany('App\Models\Sale')->latest()->withPivot('amount', 'returned_amount');
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
        return $this->belongsToMany('App\Models\Provider')->withPivot('amount', 'cost', 'price', 'amount')
                                                    ->withTimestamps();
    }
    
    function provider(){
        return $this->belongsTo('App\Models\Provider');
    }

    function questions() {
        return $this->hasMany('App\Models\Question');
    }
}
