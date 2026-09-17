<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrentAcount extends Model
{
    protected $guarded = [];

    // protected $appends = ['moneda_id'];

    /**
     * Baja en cascada de los certificados de retención del cobro (misión
     * compras-factura-manual-alicuotas, 17/9/2026, parte C).
     *
     * 🔴 VA ACÁ Y NO EN CurrentAcountController::delete(). Un certificado de
     * `retenciones_sufridas` es el papel de una retención que se practicó AL COBRAR: si el cobro
     * se borra, la retención no existió. Dejándolo en el controller, cualquier otro camino que
     * borre un `CurrentAcount` (los comandos de integridad, la limpieza de pagos provisorios, un
     * borrado en cascada de una venta) dejaría el certificado huérfano — y un certificado huérfano
     * no se ve en ninguna pantalla pero SÍ lo suma la Posición Fiscal, o sea que le baja el IVA a
     * pagar del período por un cobro que ya no está.
     *
     * ⚠️ Un `CurrentAcount::where(...)->delete()` por query builder NO dispara este evento. Hoy no
     * hay ninguno en el código que borre cobros así; si mañana aparece, tiene que limpiar los
     * certificados a mano.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($current_acount) {

            RetencionSufrida::where('current_acount_id', $current_acount->id)->delete();
        });
    }

    /**
     * Los certificados de retención que se cargaron en este cobro.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function retenciones_sufridas() {
        return $this->hasMany(RetencionSufrida::class);
    }

    function user() {
        return $this->belongsTo(User::class);
    }

    // Accesor para obtener el moneda_id desde la relación credit_account
    public function getMonedaIdAttribute()
    {
        if (!is_null($this->attributes['moneda_id'])) {
            return $this->attributes['moneda_id'];
        } 
        return $this->credit_account ? $this->credit_account->moneda_id : null;
    }

    function credit_account() {
        return $this->belongsTo(CreditAccount::class);
    }

    function cheques() {
        return $this->hasMany(Cheque::class);
    }

    function surchages() {
        return $this->belongsToMany(Surchage::class)->withPivot('percentage');
    }

    function discounts() {
        return $this->belongsToMany(Discount::class)->withPivot('percentage');
    }

    public function pagado_por() {
        return $this->belongsToMany('App\Models\CurrentAcount', 'pagado_por', 'debe_id', 'haber_id')->withPivot('pagado', 'total_pago', 'a_cubrir', 'fondos_iniciales', 'nuevos_fondos', 'remantente')->orderBy('created_at', 'ASC');
    }

    public function pagando_a() {
        return $this->belongsToMany('App\Models\CurrentAcount', 'pagado_por', 'haber_id', 'debe_id')->withPivot('pagado', 'total_pago', 'a_cubrir', 'fondos_iniciales', 'nuevos_fondos', 'remantente')->orderBy('created_at', 'ASC');
        
    }

    public function pagando_las_comisiones() {
        return $this->belongsToMany('App\Models\SellerCommission');
    }

    public function sale() {
        return $this->belongsTo('App\Models\Sale')->withTrashed();
    }

    public function afip_ticket() {
        return $this->hasOne(AfipTicket::class, 'nota_credito_id');
    }

    public function to_pay() {
        return $this->belongsTo(CurrentAcount::class, 'to_pay_id');
    }

    public function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'price', 'discount', 'cost', 'iva_percentage');
    }

    public function services() {
        return $this->belongsToMany(Service::class)->withPivot('amount', 'price', 'discount');
    }

    public function budget() {
        return $this->belongsTo('App\Models\Budget');
    }

    public function order_production() {
        return $this->belongsTo('App\Models\OrderProduction');
    }

    public function provider_order() {
        return $this->belongsTo('App\Models\ProviderOrder');
    }

    public function checks() {
        return $this->hasMany('App\Models\Check');
    }

    public function current_acount_payment_methods() {
        return $this->belongsToMany('App\Models\CurrentAcountPaymentMethod')->withPivot('amount', 'bank', 'num', 'fecha_emision', 'fecha_pago', 'cobrado_at', 'check_status_id', 'credit_card_id', 'credit_card_payment_plan_id', 'amount_cotizado', 'cotizacion', 'moneda_id', 'caja_id');
    }

    public function client() {
        return $this->belongsTo('App\Models\Client');
    }

    public function provider() {
        return $this->belongsTo('App\Models\Provider');
    }

    public function seller() {
        return $this->belongsTo('App\Models\Seller');
    }

    public function nota_credito_descriptions() {
        return $this->hasMany(NotaCreditoDescription::class);
    }
}
