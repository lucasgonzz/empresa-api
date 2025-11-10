<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Provider extends Model
{
    use Notifiable;
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('iva_condition', 'comercio_city_user', 'provider_price_lists', 'location', 'credit_accounts.moneda', 'provider_discounts');
        // $query->with('iva_condition', 'comercio_city_user', 'provider_price_lists', 'location')->withCount('current_acounts');
    }

    public function credit_accounts() {
        return $this->hasMany(CreditAccount::class, 'model_id')
                            ->where('model_name', 'provider');
    }

    function provider_discounts() {
        return $this->hasMany(ProviderDiscount::class);
    }

    function provider_price_lists() {
        return $this->hasMany('App\Models\ProviderPriceList');
    }

    function comercio_city_user() {
        return $this->belongsTo('App\Models\User', 'comercio_city_user_id');
    }

    public function iva_condition() {
        return $this->belongsTo('App\Models\IvaCondition');
    }

    public function current_acounts() {
        return $this->hasMany('App\Models\CurrentAcount');
    }

    public function location() {
        return $this->belongsTo('App\Models\Location');
    }

    public function articles() {
        return $this->belongsToMany('App\Models\Article')
                    ->withPivot('amount', 'cost', 'price', 'provider_code')
                    ->withTimestamps();
    }
}
