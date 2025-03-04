<?php

namespace Tests\Browser\Listado\Helpers;

use Tests\Browser\Helpers\FormHelper;
use Tests\Browser\Helpers\SearchHelper;


class ActualizarProviderHelper {


    static function actualizar_provider($browser) {


        $data = [
            'fila'      => 1,
            'key'       => 'percentage_gain',
            'value'     => 50,
            'check'     => [
                'key'       => 'final_price',
                'value'     => '$1.500',
            ],
        ];


        $table_name = "#table-article tbody tr:nth-child({$data['fila']})";
        $browser->click($table_name);

        $browser->pause(2000);

        $btn = "#form-group-provider_id .edit-btn";
        $browser->waitFor($btn);
        $browser->click($btn);

        $_data = [
            'model_name'    => 'provider',
            'key'           => $data['key'],
            'value'         => $data['value'],
        ];

        FormHelper::update_model($browser, $_data);

        // $browser->click('#article-provider_id-search-modal .close');

        $browser->pause(2000);

        $browser->click('#article .close');

        FormHelper::check_prop_value($browser, [
            'model_name'    => 'article',
            'fila'          => 1,
            'key'           => $data['check']['key'],
            'value'         => $data['check']['value'],
        ]);

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
    }

}
