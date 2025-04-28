<?php

namespace Tests\Browser\Listado\Helpers;

use App\Models\Address;
use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\DepositMovementStatus;
use App\Models\User;

class DepositMovementHelper {



    function crear_movimiento($browser, $data) {

        $browser->click('@btn_deposit_movements');


        // Boton modal crear 
        $browser->click('@btn_create_deposit_movement');
        $browser->pause(1000);


        // Deposito origen
        $from_address = Address::where('street', $data['deposito_origen'])->first();

        $browser->select('#deposit_movement-from_address_id', $from_address->id);
        $browser->pause(500);


        // Deposito destino
        $to_address = Address::where('street', $data['deposito_destino'])->first();

        $browser->select('#deposit_movement-to_address_id', $to_address->id);
        $browser->pause(500);


        // Articulos
        foreach ($data['articles'] as $article) {

            Self::agregar_articulo($browser, $article);
        }


        // Estado
        if (isset($data['estado'])) {

            $estado = DepositMovementStatus::where('name', $data['estado'])->first();

            $browser->select('#deposit_movement-deposit_movement_status_id', $estado->id);
            $browser->pause(500);
        }

        // Empleado
        if (isset($data['employee_name'])) {

            $employee = User::where('name', $data['employee_name'])->first();

            $browser->select('#deposit_movement-employee_id', $employee->id);
            $browser->pause(500);
        }



        $browser->pause(500);
        $browser->click('@btn_guardar_deposit_movement');
        $browser->pause(500);

        $browser->waitFor('#deposit-movements .close');
        $browser->pause(500);
        $browser->click('#deposit-movements .close');
        $browser->pause(500);

    }

    function agregar_articulo($browser, $article) {

        $browser->click('#deposit_movement-articles');
        $browser->pause(1000);

        $browser->type('#deposit_movement-articles-search-modal-input', $article['name']);
        $browser->keys('#deposit_movement-articles-search-modal-input', ['{ENTER}']);
        $browser->pause(1000);
        
        $browser->waitFor('@table-results-article');
        $browser->pause(1000);
        
        $browser->keys('#deposit_movement-articles-search-modal-input', ['{ENTER}']);

        $browser->pause(1000);

        $article_model = Article::where('name', $article['name'])->first();

        $input_amount = '.article-amount-'.$article_model->id;

        $browser->waitFor($input_amount);
        $browser->type($input_amount, $article['amount']);

        if (isset($article['variant'])) {
            Self::set_variant_value($browser, $article, $article_model);
        }

        $browser->pause(500);
    }

    function set_variant_value($browser, $article, $article_model) {

        $browser->pause(500);
        
        $select_varaint = '.article-article_variant_id-'.$article_model->id;
        $browser->waitFor($select_varaint);

        $varaint_model = ArticleVariant::where('variant_description', $article['variant'])->first();
        dump('variant: '.$varaint_model->variant_description);
        $browser->select($select_varaint, $varaint_model->id);
    }

}
