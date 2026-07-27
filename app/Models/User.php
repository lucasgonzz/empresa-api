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

    /**
     * Valor de condicion_iva_precios para Responsable Inscripto.
     * Comportamiento actual del sistema: costo neto, IVA sumado al vender.
     * (Grupo 231, prompt 01: movida desde UserConfiguration).
     */
    const CONDICION_RRII = 'RRII';

    /**
     * Valor de condicion_iva_precios para Monotributista.
     * El monotributista no recupera IVA: se asume que los precios ya lo incluyen.
     * (Grupo 231, prompt 01: movida desde UserConfiguration).
     */
    const CONDICION_MT = 'MT';

    protected $guarded = [];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        // Si es true, descuentos/recargos de la venta se aplican al costo en cálculos de SaleHelper.
        // 'aplicar_descuentos_de_venta_a_costos' => 'boolean',
    ];
    
    protected $dates = ['expired_at', 'payment_expired_at', 'last_activity'];

    function scopeWithAll($query) {
        $query->with('afip_information.iva_condition', 'permissions', 'plan', 'addresses', 'extencions', 'addresses', 'configuration', 'online_configuration', 'owner', 'inputs_size');
    }

    /**
     * Indica si la cuenta calcula precios/costos como Monotributista.
     * (No recupera IVA: se asume que los precios de compra ya lo incluyen).
     * (Grupo 231, prompt 01: movido desde UserConfiguration).
     *
     * @return bool
     */
    public function es_monotributista() {
        return $this->condicion_iva_precios == self::CONDICION_MT;
    }

    /**
     * Indica si la cuenta calcula precios/costos como Responsable Inscripto.
     * (El usuario indica por compra si el precio ya incluye IVA; el costo base queda neto).
     * (Grupo 231, prompt 01: movido desde UserConfiguration).
     *
     * @return bool
     */
    public function es_responsable_inscripto() {
        return $this->condicion_iva_precios == self::CONDICION_RRII;
    }

    public function inputs_size() {
        return $this->belongsTo(InputsSize::class);
    }

    public function article_ticket_info() {
        return $this->belongsTo(ArticleTicketInfo::class);
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
        return $this->belongsToMany(PermissionEmpresa::class);
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

    public function whatsapp_bot_config() {
        return $this->hasOne(WhatsappBotConfig::class);
    }

    /**
     * Chats de WhatsApp de los clientes de esta empresa (módulo grupo 137).
     */
    public function whatsapp_chats() {
        return $this->hasMany(WhatsappChat::class);
    }
}
