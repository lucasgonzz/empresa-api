<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyPerformanceInfoFacturacion extends Model
{
    protected $guarded = [];

    function scopeWithAll($q) {
        
    }

    function afip_information() {
        return $this->belongsTo(AfipInformation::class);
    }

    function afip_tipo_comprobante() {
        return $this->belongsTo(AfipTipoComprobante::class);
    }
}
