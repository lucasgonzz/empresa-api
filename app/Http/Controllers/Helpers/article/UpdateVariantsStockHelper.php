<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class UpdateVariantsStockHelper {

    function __construct($article_id, $variants_to_update) {

        $this->article = Article::find($article_id);

        $this->variants_to_update = $variants_to_update;
    }

    function update_variants() {

        foreach ($this->variants_to_update as $variant) {

            $this->variant = $variant;

            foreach ($variant['addresses'] as $address) {
                
                $this->address = $address;

                $variant_address = $this->get_variant_address();

                if (is_null($variant_address)) {

                    $this->attach_address();
                    
                    $variant_address = $this->get_variant_address();
                }

                Log::info('diferencia de '.$variant_address->street);
                Log::info('Antes habia '.$variant_address->pivot->amount);
                Log::info('Y ahora llego '.$address['amount']);

                $diferencia = (float)$address['amount'] - $variant_address->pivot->amount;
                Log::info('diferencia: '.$diferencia);

                $this->guardar_stock_movement($diferencia);

                sleep(1);
            }
            
        }
    }

    function guardar_stock_movement($amount) {

        if ($amount != 0) {

            $ct_stock_movement = new StockMovementController();

            $request = new \Illuminate\Http\Request();

            $request->model_id = $this->article->id;

            $request->article_variant_id = $this->variant['id'];

            $request->amount = $amount;

            $request->to_address_id = $this->address['id'];

            // $request->employee_id = UserHelper::user(false)->id;

            $request->concepto = 'Act de depositos';
            
            Log::info('*************');
            Log::info('Act de depositos para address '.$this->address['id'].' con '.$amount);

            $ct_stock_movement->store($request);
            Log::info('*************');
        }

    }

    function get_variant_address() {

        $result = null;

        foreach ($this->article->article_variants as $article_variant) {

            if ($article_variant->id == $this->variant['id']) {

                $this->article_variant = $article_variant;

                foreach ($article_variant->addresses as $variant_address) {

                    if ($variant_address->id == $this->address['id']) {

                        $result = $variant_address;
                    }
                }

            }
        }

        return $result;
    }

    function attach_address() {

        Log::info('No tenia stock en la direccion, se va a crear');

        $this->article_variant->addresses()->attach($this->address['id'], [
            'amount'    => 0
        ]);

        $this->article->load('article_variants');
    }
}