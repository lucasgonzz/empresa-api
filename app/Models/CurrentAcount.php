<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CurrentAcount extends Model
{
    protected $guarded = [];

    protected $appends = ['moneda_id'];

    function user() {
        return $this->belongsTo(User::class);
    }

    // Accesor para obtener el moneda_id desde la relación credit_account
    public function getMonedaIdAttribute()
    {
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
        return $this->hasOne('App\Models\AfipTicket', 'nota_credito_id');
    }

    public function articles() {
        return $this->belongsToMany('App\Models\Article')->withTrashed()->withPivot('amount', 'price', 'discount');
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
        return $this->belongsToMany('App\Models\CurrentAcountPaymentMethod')->withPivot('amount', 'bank', 'num', 'fecha_emision', 'fecha_pago', 'cobrado_at', 'check_status_id', 'credit_card_id', 'credit_card_payment_plan_id');
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
}
