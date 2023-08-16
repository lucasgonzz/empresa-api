<?php

namespace App\Models;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
    ];
    
    protected $dates = ['expired_at', 'payment_expired_at', 'last_activity'];

    function scopeWithAll($query) {
        $query->with('afip_information.iva_condition', 'permissions', 'plan.features', 'addresses', 'extencions', 'addresses', 'configuration', 'owner');
    }

    public function user_payments() {
        return $this->hasMany('App\Models\UserPayment');
    }

    public function configuration() {
        return $this->hasOne('App\Models\UserConfiguration');
    }

    public function online_configuration() {
        return $this->hasOne('App\Models\OnlineConfiguration');
    }

    public function delivery_zones() {
        return $this->hasOne('App\Models\DeliveryZone');
    }

    public function extencions() {
        return $this->belongsToMany('App\Models\ExtencionEmpresa');
    }

    public function plan() {
        return $this->belongsTo('App\Models\Plan');
    }

    public function afip_information() {
        return $this->hasOne('App\Models\AfipInformation');
    }

    public function permissions() {
        return $this->belongsToMany(GeneralHelper::getModelName(env('PERMISSION_CLASS_NAME', 'Permission')));
    }

    public function articles() {
        return $this->hasMany('App\Models\Article');
    }

    function sale_types() {
        return $this->hasMany('App\Models\SaleType');
    }

    public function addresses() {
        return $this->hasMany('App\Models\Address');
    }

    // public function subscription() {
    //     return $this->hasOne('App\Models\Subscription');
    // }

    public function articles_sub_user() {
        return $this->hasMany('App\Models\Article', 'sub_user_id');
    }

    public function employees() {
        return $this->hasMany('App\Models\User', 'owner_id');
    }

    public function schedules() {
        return $this->hasMany('App\Models\Schedule');
    }

    public function collections() {
        $status = Auth()->user()->status;
        if ($status == 'admin' || $status == 'super') {
            return $this->hasMany('App\Models\Collection', 'admin_id');
        } else {
            return $this->hasMany('App\Models\Collection', 'commerce_id');
        }
    }

    public function owner() {
        return $this->belongsTo('App\Models\User', 'owner_id');  
    }

    public function admin() {
        return $this->belongsTo('App\Models\User', 'id');  
    }

    public function commerces() {
        return $this->hasMany('App\Models\User', 'admin_id');
    }

    public function questions() {
        return $this->hasMany('App\Models\Question');
    }

    public function workdays() {
        return $this->belongsToMany('App\Models\Workday');
    }
}
