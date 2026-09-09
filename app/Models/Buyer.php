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

    /**
     * Los tres campos agregados que devuelve `BuyerController::index()` en lugar de la historia
     * de mensajes (misión api-buyer-sin-historia-de-mensajes, 9/9/2026). No son columnas de
     * `buyers`: vienen del GROUP BY sobre `messages` y del `last_message` cargado. Los casts
     * fijan el contrato con la SPA: enteros (no strings, que en JS "0" es verdadero) y una fecha
     * serializada igual que cualquier `created_at`.
     */
    protected $casts = [
        'messages_count'        => 'integer',
        'unread_messages_count' => 'integer',
        'last_message_at'       => 'datetime',
    ];

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

    /**
     * El último mensaje del comprador (por `id`), sin `article.images`. Es lo único de la
     * conversación que el listado de compradores necesita para ordenar los chats y mostrar el
     * último texto; la historia completa la trae `GET message/{buyer_id}`.
     *
     * `latestOfMany()` (Laravel >= 8.42, acá 8.83) resuelve el eager load en UNA consulta para
     * todos los compradores, sin importar cuántos mensajes tenga cada uno.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function last_message() {
        return $this->hasOne('App\Models\Message')->latestOfMany();
    }

    function addresses() {
        return $this->hasMany('App\Models\Address');
    }

    function user() {
        return $this->belongsTo('App\Models\User');
    }
}
