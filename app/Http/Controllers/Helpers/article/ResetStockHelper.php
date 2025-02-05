<?php

namespace App\Http\Controllers\Helpers\article;

use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Article;
use Carbon\Carbon;

class ResetStockHelper {


    function reset_stock($article_id) {

        $this->article_id = $article_id;
        
        $this->article = Article::find($article_id);

        $this->ct = new StockMovementController();

        if (count($this->article->article_variants) >= 1) {

            $this->reset_variants();

        } else if (count($this->article->addresses) >= 1) {

            $this->reset_addresses();

        } else {

            $this->reset_global_stock();
        }
    }

    function reset_global_stock() {

        if (!is_null($this->article->stock)) {
            $new_stock = 0 - $this->article->stock;
        } else {
            $new_stock = 0;
        }

        $data['model_id'] = $this->article_id;
        $data['amount'] = $new_stock;
        $data['concepto_stock_movement_name'] = 'Reseteo de stock';
        $this->ct->crear($data, false);
    }

    function reset_addresses() {

        foreach ($this->article->addresses as $address) {
                
            if (!is_null($address->pivot->amount)) {

                $new_stock = 0 - $address->pivot->amount;
            } else {

                $new_stock = 0;
            }

            $data['model_id'] = $this->article_id;
            $data['amount'] = $new_stock;
            $data['from_address_id'] = $address->id;
            $data['concepto_stock_movement_name'] = 'Reseteo de stock';
            $this->ct->crear($data, false);
        }
    }

    function reset_variants() {

        foreach ($this->article->article_variants as $variant) {
                
            if (count($variant->addresses) >= 1) {

                foreach ($variant->addresses as $address) {
                    
                    if (!is_null($address->pivot->amount)) {

                        $new_stock = 0 - $address->pivot->amount;
                    } else {

                        $new_stock = 0;
                    }
                   
                    $data['model_id'] = $this->article_id;
                    $data['article_variant_id'] = $variant->id;
                    $data['amount'] = $new_stock;
                    $data['from_address_id'] = $address->id;
                    $data['concepto_stock_movement_name'] = 'Reseteo de stock';

                    $this->ct->crear($data, false);
                }

            } else {

                if (!is_null($variant->stock)) {
                    $new_stock = 0 - $variant->stock;
                } else {
                    $new_stock = 0;
                }

                $data['model_id'] = $this->article_id;
                $data['article_variant_id'] = $variant->id;
                $data['amount'] = $new_stock;
                $data['concepto_stock_movement_name'] = 'Reseteo de stock';

                $this->ct->crear($data, false);

            }
        }
    }
}