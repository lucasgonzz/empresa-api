<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo de configuración fiscal AFIP por usuario.
 *
 * @property string|null $owner_name Nombre opcional del dueño para cabecera de factura.
 */
class AfipInformation extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        $q->with('iva_condition');
    }

    protected $dates = ['inicio_actividades'];

    public function iva_condition() {
        return $this->belongsTo('App\Models\IvaCondition');
    }

    /**
     * Sucursal (address) a la que pertenece este afip_information.
     * Relación inversa de Address->afip_informations().
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function address()
    {
        return $this->belongsTo('App\Models\Address', 'address_id');
    }
}
