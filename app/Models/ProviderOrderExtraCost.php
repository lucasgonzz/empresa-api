<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderOrderExtraCost extends Model
{
    protected $guarded = [];

    // Prompt 513: `facturado` y `en_factura_compra` se guardan como tinyint; se castean a bool
    // para que el front reciba/consuma siempre true/false y no 0/1.
    protected $casts = [
        'facturado' => 'boolean',
        'en_factura_compra' => 'boolean',
    ];

    // Tipos válidos de 'tipo' (Prompt 264). Mismo enum que ArticleSurchage::TIPO_* (prompt 260):
    // el costo extra con uno de estos tipos se prorratea entre los artículos de la compra y se
    // materializa como un recargo de ese tipo en cada artículo. NULL o TIPO_OTRO no se materializan
    // (comportamiento retrocompatible: solo suman al total de la orden).
    const TIPO_TRANSPORTE = 'transporte';
    const TIPO_SEGURO = 'seguro';
    const TIPO_ARANCEL_IMPORTACION = 'arancel_importacion';
    const TIPO_OTRO = 'otro';

    function scopeWithAll($q) {

    }
}
