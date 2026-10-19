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

    /**
     * Método de pago de CUENTA CORRIENTE con el que se vincula este método de la tienda (misión
     * tienda-cuotas-mercado-pago, 15/10/2026).
     *
     * Solo tiene sentido en un método de tipo Mercado Pago: las filas de `cuotas` cargadas para ese
     * método de cuenta corriente son las cuotas con recargo que la tienda le ofrece al comprador.
     * `null` = sin vínculo = la tienda se comporta como siempre. `tienda-api` lo resuelve por su
     * cuenta con el id plano; esta relación es para el ERP.
     *
     * 🔴 La columna puede no existir todavía (un deploy sube los archivos antes de migrar): no usar
     * esta relación en un `with()`/`whereHas()` sin preguntar antes
     * `CuotasTiendaEsquemaHelper::hay_columna()`. Leer el atributo suelto es seguro (da null).
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    function current_acount_payment_method() {
        return $this->belongsTo('App\Models\CurrentAcountPaymentMethod', 'current_acount_payment_method_id');
    }
}
