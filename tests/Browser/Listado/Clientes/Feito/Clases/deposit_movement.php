<?php

namespace Tests\Browser\Listado\Clientes\Feito\Clases;

use Tests\Browser\Listado\Helpers\DepositMovementHelper;

class deposit_movement {

    public function __construct($browser) {
        
        $this->browser = $browser;

        $this->deposit_movement();

    }
 
    function deposit_movement() {
        dump('deposit_movement');
        $this->browser->pause(500);

        DepositMovementHelper::crear_movimiento($this->browser, [
            'deposito_origen'   => 'Tucuman',
            'deposito_destino'  => 'Mar del Plata',
            'estado'            => 'Recibido',
            'articles'          => [
                [
                    'name'      => 'Mate Torpedo',
                    'variant'   => 'Amarillo XL',
                    'amount'    => 5,   
                ],
                [
                    'name'      => 'Mate Torpedo',
                    'variant'   => 'Azul XL',
                    'amount'    => 10,
                ],
            ],
        ]);
        
        $this->browser->pause(500);

    }

}
