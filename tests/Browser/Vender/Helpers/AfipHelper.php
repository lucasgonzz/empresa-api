<?php

namespace Tests\Browser\Vender\Helpers;

use App\Models\AfipTipoComprobante;

class AfipHelper {

    function __construct($browser) {

        $this->browser = $browser;

    }

    static function set_punto_de_venta($browser, $data) {
        $browser->pause(500);
        
        $select = "#afip_information_id";

        $browser->select($select, $data['afip_information_id']);
        $browser->pause(500);

        $afip_tipo_comprobante_model = AfipTipoComprobante::where('name', $data['afip_tipo_comprobante_name'])->first();

        $select = "#afip_tipo_comprobante_id";
        $browser->waitFor($select);
        $browser->select($select, $afip_tipo_comprobante_model->id);
        $browser->pause(500);
        

    } 
    
}
