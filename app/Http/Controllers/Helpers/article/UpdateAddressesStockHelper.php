<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateAddressesStockHelper {

    function __construct($article_id, $addresses) {

        $this->article = Article::find($article_id);

        $this->addresses = $addresses;
    }

    function update_addresses() {
        

        foreach ($this->addresses as $address) {

            $this->address = $address;
            
            $article_address = $this->get_article_address();

            if (!is_null($article_address)) {

                $diferencia = (float)$address['pivot']['amount'] - $article_address->pivot->amount;
            } else {
                $diferencia = (float)$address['pivot']['amount'];
            }


            $this->guardar_stock_movement($diferencia);

            sleep(1);
        }
    }

    function guardar_stock_movement($amount) {

        $ct_stock_movement = new StockMovementController();

        $request = new \Illuminate\Http\Request();

        $request->model_id = $this->article->id;

        $request->amount = $amount;

        $request->to_address_id = $this->address['id'];

        // $request->employee_id = UserHelper::user(false)->id;

        $request->concepto = 'Act de depositos';
        
        Log::info('*************');
        Log::info('Act de depositos para '.$this->address['street'].' con '.$amount);

        $ct_stock_movement->store($request);
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