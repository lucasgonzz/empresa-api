<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderDiscount extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function provider() {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Los `article_discounts` que salieron de ESTE descuento puntual del proveedor (mision
     * sincronizar-descuentos-proveedor, 17/9/2026).
     *
     * 🔴 No se eager-loadea desde ningun lado, y menos desde `scopeWithAll()`: un descuento de un
     * proveedor grande tiene miles de filas de articulo colgando. Existe para poder consultarlas
     * puntualmente, no para arrastrarlas.
     *
     * ⚠️ Y NO es la fuente de verdad de "que articulos tiene sincronizados este descuento": todas
     * las filas anteriores a la migracion 2026_09_17_110000 tienen `provider_discount_id` en NULL
     * aunque esten tagueadas. Quien necesite saber si un `article_discount` es de proveedor mira
     * `origen`, nunca esta relacion.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    function article_discounts() {
        return $this->hasMany('App\Models\ArticleDiscount');
    }
}
