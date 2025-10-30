<?php

namespace App\Http\Controllers;

use App\Models\StockSuggestionArticle;
use Illuminate\Http\Request;

class StockSuggestionArticleController extends Controller
{

    public function ver_por_deposito(Request $request)
    {

        $query = StockSuggestionArticle::with(['article', 'from_address', 'to_address'])
            ->where('stock_suggestion_id', $request->stock_suggestion_id);

        // Filtrar según agrupación seleccionada
        if ($request->modo_agrupacion === 'origen') {
            $query->where('from_address_id', $request->address_id);
        } else {
            $query->where('to_address_id', $request->address_id);
        }

        $sugerencias = $query->get();

        // Estructura de models
        $models = $sugerencias->map(function ($item) {
            return [
                'id' => $item->article->id,
                'provider_code' => $item->article->provider_code,
                'bar_code' => $item->article->bar_code,
                'article' => $item->article->name,
                'cantidad' => $item->suggested_amount,
                'from_address' => $item->from_address->street,
                'to_address' => $item->to_address->street,
            ];
        });

        return response()->json(['models'   => $models], 200);
    }
}
