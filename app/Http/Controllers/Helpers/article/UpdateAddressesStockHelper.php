<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateAddressesStockHelper {

    function __construct($article_id, $addresses) {

        $this->article = Article::find($article_id);

        $this->addresses = $addresses;
    }

    function update_addresses() {
        
        $segundos = 0;

        foreach ($this->addresses as $address) {

            $this->address = $address;
            
            $article_address = $this->get_article_address();

            if (!is_null($article_address)) {

                $diferencia = (float)$address['pivot']['amount'] - $article_address->pivot->amount;
            } else {
                $diferencia = (float)$address['pivot']['amount'];
            }

            if (
                $diferencia != ''
                && $diferencia != 0
            ) {
                
                $this->guardar_stock_movement($diferencia, $segundos);

                $segundos += 5;
            }
        }
    }

    function guardar_stock_movement($amount, $segundos) {

        $ct_stock_movement = new StockMovementController();

        $data = [];

        $data['model_id'] = $this->article->id;

        $data['amount'] = $amount;

        $data['to_address_id'] = $this->address['id'];

        // $data['employee_id'] = UserHelper::user(false)->id;

        $article_address = $this->get_article_address();
        
        if (is_null($article_address)) {

            $data['concepto_stock_movement_name'] = 'Creacion de deposito';
        }  else {

            $data['concepto_stock_movement_name'] = 'Actualizacion de deposito';
        }

        
        $ct_stock_movement->crear($data, false, null, null, $segundos);
    }

    function get_article_address() {

        $article_address = null;

        foreach ($this->article->addresses as $address) {
            
            if ($address->id == $this->address['id']) {

                $article_address = $address;
            }
        }

        return $article_address;
    }
}