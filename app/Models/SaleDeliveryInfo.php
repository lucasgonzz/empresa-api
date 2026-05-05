<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Overrides opcionales de datos de envío para una venta (etiqueta / PDF).
 * Si los campos están vacíos en persistencia, la UI y el PDF usan los datos del Client.
 */
class SaleDeliveryInfo extends Model
{
    protected $guarded = [];

    /**
     * Scope requerido por fullModel() del Controller base.
     */
    public function scopeWithAll($query)
    {
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
