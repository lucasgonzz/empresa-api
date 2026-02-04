<?php

namespace App\Http\Controllers\Helpers\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\RequestHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Sale;
use Illuminate\Support\Facades\Log;

class SaleSeederHelper {
	

    static function create_sales($sales) {

        foreach ($sales as $sale) {

            $data = [
                'num'               => $sale['num'],
                'total'             => $sale['total'],
                'address_id'        => $sale['address_id'],
                'employee_id'       => $sale['employee_id'],
                'client_id'         => $sale['client_id'],
                'created_at'        => $sale['created_at'],
                'moneda_id'         => 1,
                'fecha_entrega'     => RequestHelper::isset_array($sale, 'fecha_entrega'),
                'terminada'     	=> RequestHelper::isset_array($sale, 'terminada', 1),
                'confirmed'     	=> RequestHelper::isset_array($sale, 'confirmed', 1),
                'user_id'           => config('app.USER_ID'),
                'save_current_acount'=> 1,
                'terminada_at'      => RequestHelper::isset_array($sale, 'terminada', 1) ? $sale['created_at'] : null,
            ];

            Log::info('Se va a crear venta con terminada: '.$data['terminada']);
            
            $created_sale = Sale::create($data);
            SaleHelper::attachProperies($created_sale, Self::setRequest($sale));
        }
    }

    static function setRequest($sale) {
        $request = new \stdClass();
        $request->items = [];
        $request->discounts = [];
        $request->surchages = [];
        $request->selected_payment_methods = RequestHelper::isset_array($sale, 'payment_methods');
        $request->current_acount_payment_method_id = null;
        $request->discount_amount = null;
        $request->discount_percentage = null;
        $request->client_id = $sale['client_id'];

        foreach ($sale['articles'] as $article) {
            $_article = [
                'id'            => $article['id'],
                'is_article'    => true,
                'name'          => null,
                'num'           => null,
                'amount'        => $article['amount'],
                'article_variant_id'        => null,
                'cost'          => $article['cost'],
                'price_vender'  => $article['price_vender'],
            ];
            $request->items[] = $_article; 
        }

        return $request;
    }
}