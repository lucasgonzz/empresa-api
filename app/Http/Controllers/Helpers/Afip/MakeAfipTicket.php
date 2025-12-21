<?php

namespace App\Http\Controllers\Helpers\Afip;

use App\Http\Controllers\AfipWsController;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class MakeAfipTicket {
	
	function make_afip_ticket($data) {

        $sale 								= Sale::find($data['sale_id']);
        $afip_information 					= AfipInformation::find($data['afip_information_id']);
        $afip_tipo_comprobante 				= AfipTipoComprobante::find($data['afip_tipo_comprobante_id']);
        $afip_fecha_emision 				= isset($data['afip_fecha_emision']) && $data['afip_fecha_emision'] != '' && !is_null($data['afip_fecha_emision']) ? $data['afip_fecha_emision'] : date('Y-m-d');
        $facturar_importe_personalizado 	= isset($data['facturar_importe_personalizado']) && $data['facturar_importe_personalizado'] > 0 ? $data['facturar_importe_personalizado'] : null;

		$afip_ticket = AfipTicket::create([
            'cuit_negocio'      				=> $afip_information->cuit,
            'iva_negocio'       				=> $afip_information->iva_condition->name,
            'punto_venta'       				=> $afip_information->punto_venta,

            'iva_cliente'       				=> !is_null($sale->client) && !is_null($sale->client->iva_condition) ? $sale->client->iva_condition->name : '',
            'sale_id'           				=> $sale->id,
            'afip_information_id'       		=> $afip_information->id,
            'afip_tipo_comprobante_id'  		=> $afip_tipo_comprobante->id,
            'afip_fecha_emision'        		=> $afip_fecha_emision,
            'facturar_importe_personalizado'    => $facturar_importe_personalizado,
        ]);

            
        $ct = new AfipWsController($afip_ticket);
        $result = $ct->init();
	}	
}
