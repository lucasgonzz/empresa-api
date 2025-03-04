<?php

namespace Tests\Browser\Vender\Golonorte\Combos\Helpers;

use App\Models\Combo;
use Tests\Browser\Helpers\SaleHelper;
use Tests\Browser\Helpers\Sale\ComboHelper;

class CrearVenta
{

    function __construct($browser) {
        $this->browser = $browser;
    }

    function crear_venta($combo_name, $combo_amount) {
        /*
            * Agrego Combo 1
            * precio = $1.000
            * total = $2.000
        */
        $this->browser = ComboHelper::add_combo($this->browser, [
            'combo_name'            => $combo_name,
            'amount'                => $combo_amount,
            'total_a_chequear'      => '$2.000'
        ]);

        $this->browser = SaleHelper::set_address($this->browser, 'Santa Fe');
        
        $this->browser = SaleHelper::btn_guardar($this->browser);
    }

}
