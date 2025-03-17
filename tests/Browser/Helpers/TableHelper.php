<?php

namespace Tests\Browser\Helpers;

class TableHelper {

    static function click_fila($browser, $data) {

        $browser->pause(500);

        $fila = $data['fila'];

        if (is_array($data['fila'])) {

            $table_name = "#table-".$data['model_name'];
            $fila_title = $data['fila']['text'];
            $fila_value = $data['fila']['value'];
            $fila = Self::get_fila_a_chequear($browser, $table_name, $fila_title, $fila_value);
            $fila++;
        } 

        $tr = "#table-{$data['model_name']} tbody tr:nth-child({$data['fila']})";

        $browser->waitFor($tr);
        $browser->click($tr);
        $browser->pause(500);
    }


    static function check_cell_value($browser, $data) {
        
        $table_name = '#table-'.$data['model_name'];

        $browser->waitFor($table_name);


        $fila_title = $data['fila']['text'];
        $fila_value = $data['fila']['value'];

        // Obtengo el indice de la fila a chequear (la posocion de la fila en la tabla)
        $fila_a_chequear = Self::get_fila_a_chequear($browser, $table_name, $fila_title, $fila_value);


        // Obtener el contenido de la celda en la fila indicada

        foreach ($data['celdas_para_chequear'] as $celda_titulo => $celda_value) {

            Self::chequear_values($browser, $table_name, $fila_a_chequear, $celda_titulo, $celda_value);

            $browser->pause(1000);
        }

    }

    static function chequear_values($browser, $table_name, $fila_a_chequear, $celda_titulo, $celda_value) {

        $column_index = Self::get_column_index($browser, $table_name, $celda_titulo);

        // dump("Chequeando la columna $celda_titulo en el index: $column_index. Que tenga el valor: $celda_value. Esto para la fila $fila_a_chequear");

        $fila_a_chequear++;
        $column_index++;

        $td_a_chequear = "$table_name tbody tr:nth-child($fila_a_chequear) td:nth-child($column_index) span";

        $browser->waitFor($td_a_chequear);
        $browser->scrollIntoView($td_a_chequear);

        // dump('td: '.$td_a_chequear);
        // dump('td text: '.$browser->text($td_a_chequear));

        $browser->assertSeeIn($td_a_chequear, $celda_value);

        dump('Se chequeo '.$celda_titulo.' con el valor de '.$celda_value);

    }

    static function get_fila_a_chequear($browser, $table_name, $fila_title, $fila_value) {

        $column_index = Self::get_column_index($browser, $table_name, $fila_title);

        $fila_index = Self::get_fila_index($browser, $table_name, $fila_value, $column_index);

        return $fila_index;
    }

    static function get_column_index($browser, $table_name, $fila_title) {

        $column_index = $browser->script("
            let table = document.querySelector('$table_name');
            let headers = Array.from(table.querySelectorAll('th'));
            return headers.findIndex(th => th.innerText.trim() === '$fila_title');
        ")[0];

        dump("column_index para $fila_title: $column_index");

        return $column_index;
    }

    static function get_fila_index($browser, $table_name, $fila_value, $column_index) {

        $fila_index = $browser->script("
            let table = document.querySelector('$table_name');
            let rows = Array.from(table.querySelectorAll('tbody tr'));

            return rows.findIndex(row => {
                let cell = row.children[$column_index];
                return cell && cell.innerText.trim() === '$fila_value';
            });
        ")[0];

        dump("fila_index para $fila_value: $fila_index");

        return $fila_index;
    }
    
}
