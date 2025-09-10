<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Client extends Model
{
    use Notifiable;
    use SoftDeletes;
    
    protected $guarded = [];

    function scopeWithAll($query) {
        $query->with('iva_condition', 'price_type', 'location', 'comercio_city_user', 'buyer', 'credit_accounts.moneda');
        // $query->with('iva_condition', 'price_type', 'location', 'comercio_city_user', 'buyer')->withCount('current_acounts');
    }

    public function credit_accounts() {
        return $this->hasMany(CreditAccount::class, 'model_id')
                            ->where('model_name', 'client');
    }

    public function purchases() {
        return $this->hasMany(ArticlePurchase::class);
    }
    
    public function pais_exportacion() {
        return $this->belongsTo(PaisExportacion::class);
    }
    
    public function sales() {
        return $this->hasMany('App\Models\Sale');
    }
    
    public function buyer() {
        return $this->hasOne('App\Models\Buyer', 'comercio_city_client_id');
    }
    
    public function comercio_city_user() {
        return $this->belongsTo('App\Models\User', 'comercio_city_user_id');
    }
    
    public function seller() {
        return $this->belongsTo('App\Models\Seller');
    }
    
    public function iva_condition() {
        return $this->belongsTo('App\Models\IvaCondition');
    }
    
    public function current_acounts() {
        return $this->hasMany('App\Models\CurrentAcount');
    }
    
    public function price_type() {
        return $this->belongsTo('App\Models\PriceType');
    }
    
    public function location() {
        return $this->belongsTo('App\Models\Location');
    }
    
    // public function errors() {
    //     return $this->hasMany('App\Models\Hola');
    // }
}
