<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SellerCommission extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('sale', 'moneda');
    }

    function sale() {
        return $this->belongsTo(Sale::class);
    }

    function seller() {
        return $this->belongsTo(Seller::class);
    }

    function pagada_por() {
        return $this->belongsToMany(CurrentAcount::class);
    }

    /**
     * Grupo 268 · Prompt 02.
     */
    function moneda() {
        return $this->belongsTo(Moneda::class);
    }

    /**
     * Grupo 268 · Prompt 02 — pivot de metodos de pago del pago a este vendedor (espejo de
     * current_acount_current_acount_payment_method). El armado del pago con varios metodos es
     * alcance del prompt 03; esta relacion solo deja el modelo listo para usarla.
     */
    function payment_methods() {
        return $this->belongsToMany(CurrentAcountPaymentMethod::class, 'seller_commission_current_acount_payment_method')
                    ->withPivot('amount', 'caja_id', 'amount_cotizado', 'cotizacion', 'moneda_id', 'cuota_id');
    }
}
