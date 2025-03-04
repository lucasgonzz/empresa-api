<?php

namespace Tests\Browser\Listado\Helpers;

use App\Models\Address;
use Tests\Browser\Helpers\FiltroHelper;
use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\ScrollHelper;
use Tests\Browser\Helpers\SeleccionHelper;


class FiltrarArticleHelper {

    static function filtrar($browser) {

        ScrollHelper::scroll($browser, -2000);

        $browser->pause(500);

        // Filtro y aumento un 10% el costo

        FiltroHelper::filtrar($browser, [
            'prop_key'      => 'num',
            'mayor_que'     => 4,
        ]);

        SeleccionHelper::seleccionar($browser, [
            'model_name'    => 'article',
            'filas'         => [1],
        ]);

        SeleccionHelper::seleccionar_opcion($browser, [
            'btn'               => '#btn_actualizar',
            'increment'     => [
                'prop_key'  => 'cost',
                'value'     => 10
            ],
        ]);

        SeleccionHelper::desactivar_seleccion($browser);

        FormHelper::check_prop_value($browser, [
            'model_name'    => 'article',
            'fila'          => 1,
            'key'           => 'final_price',
            'value'         => '$4.400',
        ]);



        // Filtro y seteo en 4000 el costo

        FiltroHelper::filtrar($browser, [
            'prop_key'      => 'num',
            'mayor_que'     => 4,
        ]);

        SeleccionHelper::seleccionar($browser, [
            'model_name'    => 'article',
            'filas'         => [1],
        ]);

        SeleccionHelper::seleccionar_opcion($browser, [
            'btn'               => '#btn_actualizar',
            'set'           => [
                'prop_key'  => 'cost',
                'value'     => 2000
            ],
        ]);

        SeleccionHelper::desactivar_seleccion($browser);

        FormHelper::check_prop_value($browser, [
            'model_name'    => 'article',
            'fila'          => 1,
            'key'           => 'final_price',
            'value'         => '$4.000',
        ]);

    }
}
