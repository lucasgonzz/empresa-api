<?php

namespace App\Models;

use App\Http\Controllers\Helpers\AjustesDeClienteEsquemaHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notifiable;

class Client extends Model
{
    use Notifiable;
    use SoftDeletes;
    
    protected $guarded = [];

    /**
     * `discounts` y `surchages` van en el withAll porque la SPA los necesita en TODOS los lugares
     * donde llega un cliente: el listado genérico hace `model[prop.key].length` sin guarda para
     * las props belongs_to_many, y Vender los activa solos al elegir el cliente del buscador.
     * Entran por `AjustesDeClienteEsquemaHelper` y no como dos cadenas más de la lista: sin las
     * tablas (ventana del deploy) el listado de clientes no puede dejar de abrir.
     */
    function scopeWithAll($query) {
        $query->with(array_merge(
            ['iva_condition', 'price_type', 'location', 'comercio_city_user', 'buyer', 'credit_accounts.moneda'],
            AjustesDeClienteEsquemaHelper::relaciones_de_cliente()
        ));
        // $query->with('iva_condition', 'price_type', 'location', 'comercio_city_user', 'buyer')->withCount('current_acounts');
    }

    /**
     * Descuentos de venta vinculados al cliente (misión descuentos-recargos-por-cliente,
     * 23/9/2026), tabla `client_discount`. Vender los prende solos al elegir el cliente y la
     * tienda ajusta con ellos los precios del comprador vinculado.
     *
     * SIN `withTrashed()` a propósito: un descuento borrado deja de aplicarse, en Vender y en la
     * tienda. Lo que ya se vendió o se pidió conserva su porcentaje en su propio pivot
     * (`discount_sale`, `discount_order`), así que no hace falta arrastrar el borrado acá.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function discounts() {
        return $this->belongsToMany(Discount::class)->withTimestamps();
    }

    /**
     * Recargos de venta vinculados al cliente, tabla `client_surchage`. Mismo criterio que
     * `discounts()`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function surchages() {
        return $this->belongsToMany(Surchage::class)->withTimestamps();
    }

    public function provincia() {
        return $this->belongsTo(Provincia::class);
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

    /**
     * Sucursal a la que pertenece el cliente (clients.address_id -> addresses).
     *
     * OJO con el nombre: esto NO es el domicilio del cliente. El domicilio es la
     * columna `address` de la propia tabla clients, que es texto libre (calle y
     * numero). Esta relacion apunta a la sucursal del negocio, cuyo nombre vive en
     * addresses.street.
     */
    public function address() {
        return $this->belongsTo('App\Models\Address');
    }

    /**
     * Chat de WhatsApp vinculado a este cliente (módulo grupo 137). Un chat puede existir
     * sin cliente todavía (client_id null en whatsapp_chats), pero un cliente tiene a lo
     * sumo un chat.
     */
    public function whatsapp_chat() {
        return $this->hasOne('App\Models\WhatsappChat');
    }

    // public function errors() {
    //     return $this->hasMany('App\Models\Hola');
    // }
}
