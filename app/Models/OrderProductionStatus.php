<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderProductionStatus extends Model
{
    protected $guarded = [];

    /*
     * scopeWithAll queda VACIO a proposito: este modelo se serializa en el arranque de sesion de
     * todos los clientes, y meterle un with() del grupo agregaria una query por estado a un
     * payload que hoy no la paga. El nombre del grupo lo resuelve la SPA contra su propio store,
     * que ya baja el catalogo de grupos al arrancar.
     */
    function scopeWithAll($q) {
        
    }

    public function order_production_status_group()
    {
        return $this->belongsTo(OrderProductionStatusGroup::class);
    }
}
