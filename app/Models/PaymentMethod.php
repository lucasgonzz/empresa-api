<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $guarded = [];

    /**
     * 🔴 `access_token` NUNCA viaja en una respuesta JSON.
     *
     * `tienda-api` ya ocultaba esta misma columna en su propio modelo (`App\PaymentMethod`);
     * acá faltaba, así que `GET /api/payment-method` devolvía el token de cobro del comercio en
     * claro al navegador. Es exactamente el agujero que vino a cerrar la misión de
     * ABM -> Integraciones, en el ABM que esa misión toca.
     *
     * ⚠️ Este `$hidden` y la asignación condicional de `PaymentMethodController@update` son UN
     * PAR y no se pueden separar: sin el `$hidden`, el token viajaba al navegador y volvía en el
     * PUT (la SPA hace `{...this.model}` sobre el modelo crudo de la API), así que la asignación
     * incondicional funcionaba de casualidad. Con el `$hidden` puesto, el request ya no trae el
     * campo y una asignación incondicional NULEARÍA el token en la primera edición.
     *
     * `public_key` NO se oculta: no es secreta, viaja al navegador del comprador igual y la
     * tienda la necesita para tokenizar la tarjeta.
     *
     * @var array<int, string>
     */
    protected $hidden = ['access_token'];

    function scopeWithAll($q) {
        $q->with('payment_method_type', 'payment_method_installments');        
    }

    function payment_method_type() {
        return $this->belongsTo('App\Models\PaymentMethodType');
    }

    function payment_method_installments() {
        return $this->hasMany('App\Models\PaymentMethodInstallment');
    }

    function credential() {
        return $this->hasOne('App\Models\Credential');
    }
}
