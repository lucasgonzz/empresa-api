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
        // AuthController@get_user (UserHelper::getFullModel) y UserController@update
        // devuelven el modelo User entero sin seleccionar columnas: sin ocultarla aca,
        // la key publica del export de articulos (grupo 211) quedaria filtrada en
        // cualquier respuesta de API que incluya al usuario.
        'articles_export_key',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        // Si es true, descuentos/recargos de la venta se aplican al costo en cálculos de SaleHelper.
        // 'aplicar_descuentos_de_venta_a_costos' => 'boolean',
    ];
    
    protected $dates = ['expired_at', 'payment_expired_at', 'last_activity'];

    /**
     * Tarea 4 — `modo_redondeo` es una FACHADA de solo lectura sobre las cinco columnas booleanas
     * de redondeo. No existe como columna y no debe existir: colapsar las combinaciones a un valor
     * unico le moveria los precios de todo el catalogo a los clientes que hoy tienen dos flags
     * prendidos, sin que lo hayan pedido. La fuente de verdad del calculo sigue siendo
     * `ArticleHelper::redondear()`, que lee las cinco columnas y no se toco.
     *
     * @var array<int, string>
     */
    protected $appends = ['modo_redondeo'];

    /**
     * Columnas de redondeo, en el orden en que las aplica `ArticleHelper::redondear()`, con el
     * valor de `modo_redondeo` que le corresponde a cada una.
     *
     * Se declara aca y no en el controller porque la lectura (este accessor) y la escritura
     * (`UserController@update`) tienen que traducir con la MISMA tabla: si se separan, un valor
     * que se escribe con una clave y se lee con otra deja al select mostrando cualquier cosa.
     *
     * @var array<string, string>
     */
    const COLUMNAS_MODO_REDONDEO = [
        'redondear_miles_en_vender'      => 'miles',
        'redondear_centenas_en_vender'   => 'centenas',
        'redondear_precios_en_decenas'   => 'decenas',
        'redondear_de_a_50'              => 'cincuenta',
        'redondear_precios_en_centavos'  => 'centavos',
    ];

    /**
     * Deriva el modo de redondeo a partir de las cinco columnas booleanas.
     *
     * - exactamente una prendida  -> el valor de esa opcion
     * - ninguna prendida          -> 'sin_redondeo'
     * - dos o mas prendidas       -> 'personalizado'
     *
     * El caso de dos o mas es real y no es un borde defensivo: las cinco columnas se ENCADENAN en
     * `ArticleHelper::redondear()` (no hay ningun return temprano), asi que la combinacion da un
     * resultado propio que ningun valor unico del select puede representar. Con precio 104,
     * `centenas` + `cincuenta` da 100, mientras que `cincuenta` solo da 150. Por eso
     * 'personalizado' es un estado legitimo que se muestra y no se puede elegir, en vez de un
     * valor que se normaliza.
     *
     * @return string
     */
    public function getModoRedondeoAttribute() {

        $prendidas = [];

        foreach (Self::COLUMNAS_MODO_REDONDEO as $columna => $modo) {
            if ((int) $this->getAttribute($columna) === 1) {
                $prendidas[] = $modo;
            }
        }

        if (count($prendidas) === 0) {
            return 'sin_redondeo';
        }

        if (count($prendidas) > 1) {
            return 'personalizado';
        }

        return $prendidas[0];
    }

    function scopeWithAll($query) {
        $query->with('afip_information.iva_condition', 'permissions', 'plan', 'addresses', 'extencions', 'addresses', 'configuration', 'online_configuration', 'owner', 'inputs_size');
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
