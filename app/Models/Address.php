<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        // Carga la identidad fiscal por defecto de la sucursal (para remitos
        // en negro) y las afip_information disponibles (con su condicion IVA)
        // para el select del form de sucursal.
        $q->with(['default_afip_information.iva_condition', 'afip_informations.iva_condition']);
    }

    function articles() {
        return $this->belongsToMany(Article::class)->withPivot('amount');
    }

    /**
     * afip_information registrados para esta sucursal (afip_informations.address_id).
     * Se usan como opciones del select "facturacion por defecto" y para el filtrado en el modulo de vender.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function afip_informations()
    {
        return $this->hasMany('App\\Models\\AfipInformation', 'address_id');
    }

    /**
     * afip_information por defecto para ventas en negro desde esta sucursal.
     * Si es null, la resolución de identidad fiscal cae a user->afip_information.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function default_afip_information()
    {
        return $this->belongsTo('App\\Models\\AfipInformation', 'default_afip_information_id');
    }
}
