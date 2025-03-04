<?php

namespace Tests\Browser\Listado\Helpers;

use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\SearchHelper;


class CrearArticuloHelper {


    static function crear_articulo($browser, $article) {

        $browser->click('@btn_create_article');
        $browser->pause(1000);

        foreach ($article['props'] as $prop) {

            if (!isset($prop['type'])) {

                $browser->type('#article-'.$prop['key'], $prop['value']);

                if (
                    $prop['key'] == 'bar_code'
                    || $prop['key'] == 'provider_code'
                ) {

                    $browser->keys('#article-'.$prop['key'], ['{ENTER}']);
                }

            } else if ($prop['type'] == 'search') {

                $data = [
                    'input'         => '#article-'.$prop['key'],
                    'search'        => $prop['value'],
                    'model_name'    => substr($prop['key'], 0, strlen($prop['key']) - 3),
                ];

                SearchHelper::search($browser, $data);
            } 

            $browser->pause(1000);
        }


        $browser->click('@btn_guardar_article');
        $browser->pause(1000);

        dump('Articulo creado');

        if (isset($article['props_to_check'])) {

            Self::check($browser, $article['props_to_check']);
        }

        $browser->pause(500);

    }

    static function check($browser, $props_to_check) {

        foreach ($props_to_check as $prop_to_check) {
            
            FormHelper::check_prop_value($browser, [
                'model_name'    => 'article',
                'fila'          => 1,
                'key'           => $prop_to_check['key'],
                'value'         => $prop_to_check['value'],
            ]);
        }
        // $browser->click('#article .close');
    }

}
