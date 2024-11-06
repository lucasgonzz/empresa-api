<?php

namespace App\Http\Controllers\Helpers\sale;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\ArticlePurchase;

class SaleNotaCreditoAfipHelper {

    function crear_nota_de_credito_afip($sale) {
        $haber = $sale->total;

        $articles = [];

        foreach ($sale->articles as $article) {
            $article_to_add = [
                'is_article'        => true,
                'id'                => $article->id,
                'returned_amount'   => $article->pivot->amount,
                'price_vender'      => $article->pivot->price,
                'discount'          => $article->pivot->discount,
            ];
            
            $articles[] = $article_to_add;
        }

        $description = 'Venta N°'.$sale->num.' eliminada';

        $model_name = 'client';

        if ($sale->omitir_en_cuenta_corriente) {
            // Si entra aca se pone null, para que la nota de credito no se la asigne a ningun cliente
            $model_name = null;
        }

        $nota_credito = CurrentAcountHelper::notaCredito($haber, $description, $model_name, $sale->client_id, $sale->id, $articles);

        if (!is_null($sale->client) && !$sale->omitir_en_cuenta_corriente) {
            CurrentAcountHelper::checkSaldos('client', $sale->client_id);
        }

        $afip_helper = new AfipNotaCreditoHelper($sale, $nota_credito);
        $afip_helper->init();
    }

}