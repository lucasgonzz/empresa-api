<?php

namespace Tests\Browser\Listado\Helpers;

use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\SearchHelper;


class ActualizarRepetidoHelper {


    static function actualizar_repetido($browser, $data) {

        Self::ingresar_codigo($browser, $data['bar_code']);

        Self::actualizar_costo($browser, $data['cost']);

        CrearArticuloHelper::check($browser, [
            [
                'key'   => $data['check']['key'],
                'value' => $data['check']['value'],
            ],
        ]);

    }
    
    static function ingresar_codigo($browser, $bar_code) {

        $browser->click('@btn_create_article');
        $browser->pause(1000);

        $browser->type('#article-bar_code', $bar_code);
        $browser->keys('#article-bar_code', ['{ENTER}']);
        $browser->pause(2000);
    }

    static function actualizar_costo($browser, $cost) {

        $browser->type('#article-cost', $cost);
        $browser->pause(2000);

        $browser->click('@btn_guardar_article');
        $browser->pause(1000);
    }

}
