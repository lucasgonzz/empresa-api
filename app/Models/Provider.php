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
        $query->with('iva_condition', 'comercio_city_user', 'provider_price_lists', 'location')
            ->withCount('current_acounts');
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
}
