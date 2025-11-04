<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Pdf\AcopioArticleDeliveryPdf;
use App\Models\AcopioArticleDelivery;
use Illuminate\Http\Request;

class AcopioArticleDeliveryController extends Controller
{
    function from_sale($sale_id) {
        $models = AcopioArticleDelivery::where('sale_id', $sale_id)
                                    ->orderBy('id', 'DESC')
                                    ->get();

        return response()->json(['models'   => $models], 200);
    }

    function pdf($id) {
        $model = AcopioArticleDelivery::find($id);

        new AcopioArticleDeliveryPdf($model);
    }
}
