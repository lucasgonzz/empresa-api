<?php

namespace Tests\Browser\Helpers;

use Tests\Browser\Helpers\TableHelper;


class FormHelper
{
    
    static function check_prop_value($browser, $data, $scroll = false) {

        TableHelper::click_fila($browser, $data);

        $field_name = '#form-group-'.$data['key'];

        $browser->waitFor($field_name);

        if ($scroll) {
            $browser->pause(600);
            $browser->scrollIntoView($field_name);
            $browser->pause(500);
        }

        // if (isset($data['is_input'])) {
        //     $value = $browser->value("$field_name input");
        //     dump('valor input: '.$value);
        //     $browser->assertSeeIn("$field_name input", $data['value']);
        // } else {
        //     $browser->assertSeeIn($field_name, $data['value']);
        // }
        $browser->assertSeeIn($field_name, $data['value']);


        dump($data['key'].' ok');

        $browser->pause(1000);
        
        $browser->click("#{$data['model_name']} .close");
        dump("Se cerro {$data['model_name']}");


    }

    static function update_model($browser, $data, $abrir_modal = true, $cerrar_modal = true) {

        if ($abrir_modal) {

            TableHelper::click_fila($browser, $data);
        }

        $browser->pause(500);

        foreach ($data['props'] as $prop) {

            $input_name = "#{$data['model_name']}-{$prop['key']}";

            $browser->waitFor($input_name);
            $browser->waitUntilEnabled($input_name);

            $browser->type($input_name, $prop['value']);

            dump('se escribio '.$prop['value']);
            $browser->pause(500);
        }

        if ($cerrar_modal) {
            $browser->click("@btn_guardar_{$data['model_name']}");
        }

        $browser->pause(500);

    }
}
