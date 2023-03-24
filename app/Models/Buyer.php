<?php

namespace App\Models;

// use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Model;
use ChristianKuri\LaravelFavorite\Traits\Favoriteability;

class Buyer extends Model
{
    use Notifiable;
    // use Favoriteability;
    
    protected $guarded = [];

    public function scopeWithAll($query){
        $query->with('addresses', 'comercio_city_client')
               ->with(['messages' => function($q) {
                    $q->orderBy('id', 'ASC')
                    ->with('article.images');
                }]);
    }

    public function comercio_city_client() {
        return $this->belongsTo('App\Models\Client', 'comercio_city_client_id');
    }

    public function messages() {
        return $this->hasMany('App\Models\Message');
    }

    function addresses() {
        return $this->hasMany('App\Models\Address');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }
}
