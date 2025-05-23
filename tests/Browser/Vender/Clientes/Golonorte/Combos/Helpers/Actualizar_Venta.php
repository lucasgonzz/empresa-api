<?php

namespace Tests\Browser\Vender\Golonorte\Combos\Helpers;

use App\Models\Combo;
use Tests\Browser\Helpers\SaleHelper;

class Actualizar_Venta
{

    function __construct($browser) {
        $this->browser = $browser;
    }

    function actualizar_venta($new_amount) {
        
        $this->browser->visit('/ventas/todas/todos');
            
        $this->browser->pause(2000);

        $this->browser->click('td');
        
        $this->browser->pause(1000);

        $this->browser->click('@btn_actualizar_venta');

        $this->browser->pause(4000);

        $this->browser = SaleHelper::cambiar_amount($this->browser, 0, $new_amount);
        
        $this->browser->pause(1000);


        /*
            * 1.000 cada combo, se actualiza cantidad a 5 = $5.000
        */
        $this->browser = SaleHelper::check_total($this->browser, '$5.000'); 

        $this->browser = SaleHelper::btn_guardar($this->browser);
    }

}
