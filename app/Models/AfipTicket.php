<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfipTicket extends Model
{
    protected $guarded = [];
    // protected $dates = ['cae_expired_at'];

    // Venta a la que pertenece la factura
    function sale() {
        return $this->belongsTo(Sale::class);
    }

    // Movimiento de la N.C de la C/C a la que pertenece la factura 
    function nota_credito() {
        return $this->belongsTo(CurrentAcount::class, 'nota_credito_id');
    }



    /* 
        Notas de credito facturadas (afip_ticket) que tiene una Factura de venta (afip_ticket) 
        Una Factura de venta puede tener muchas notas de credito facturadas

        Cuando creo una nota de credito desde DevolucionesController con AfipNotaCreditoHelper, creo un afip_tikcet
        que pertenece a una factura de venta (una afip_tikcet). 
        Entonces esa factura de venta pasa a tener una nueva nota de credito dentro de sus @nota_credito_afip  

    */
    function nota_credito_afip() {
        return $this->hasMany(AfipTicket::class, 'sale_afip_ticket_id');
    }



    /* 
        Factura (afip_tikcet) a la que pertenece una Nota de credito facturada (afip_ticket)
        Me devuelve la Factura de venta a la que pertenece una Nota de credito facturada
        Es la inversa del metodo nota_credito_afip
    */  
    function sale_afip() {
        return $this->belongsTo(AfipTicket::class, 'sale_afip_ticket_id');
    }


    /*
        Venta (sale) a la que pertenece una nota de credito.
        Puede que varias notas de credito facturadas (afip_ticket) pertenezcan a la misma Sale, 
        pero cada una pertenece a su propia factura de venta (afip_ticket), o a la misma factura de venta 
    */
    function sale_nota_credito() {
        return $this->belongsTo(Sale::class, 'sale_nota_credito_id');
    }

    function afip_information() {
        return $this->belongsTo(AfipInformation::class);
    }

    function afip_tipo_comprobante() {
        return $this->belongsTo(AfipTipoComprobante::class);
    }

    function afip_errors() {
        return $this->hasMany(AfipError::class);
    }

    function afip_observations() {
        return $this->hasMany(AfipObservation::class);
    }
}
