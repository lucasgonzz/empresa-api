<?php

namespace App\Http\Controllers;

use App\Models\StockSuggestionArticle;
use Illuminate\Http\Request;

class StockSuggestionArticleController extends Controller
{

    /**
     * Devuelve las líneas de sugerencia filtradas por depósito según el modo de agrupación.
     *
     * @param Request $request Datos del filtro (stock_suggestion_id, modo_agrupacion y address_id).
     * @return \Illuminate\Http\JsonResponse Listado de líneas preparadas para la vista.
     */
    public function ver_por_deposito(Request $request)
    {

        // Query base de líneas de sugerencia con relaciones necesarias para la vista.
        $query = StockSuggestionArticle::with(['article', 'from_address', 'to_address'])
            ->where('stock_suggestion_id', $request->stock_suggestion_id);

        // Filtrar según agrupación seleccionada
        if ($request->modo_agrupacion === 'origen') {
            $query->where('from_address_id', $request->address_id);
        } else {
            $query->where('to_address_id', $request->address_id);
        }

        // Colección cruda obtenida desde base.
        $sugerencias = $query->get();

        // Se eliminan filas inválidas y posibles duplicados para no romper la selección en frontend.
        $sugerencias = $sugerencias
            ->filter(function ($item) {
                return !empty($item->id);
            })
            ->unique('id')
            ->values();

        // Cada fila es una línea de sugerencia (origen->destino), no solo el artículo.
        // Se protege el acceso a relaciones para evitar errores cuando falten datos relacionados.
        $models = $sugerencias->map(function ($item) {
            // Relación article (puede no existir si el registro fue eliminado o está inconsistente).
            $article = $item->article;

            // Relación from_address (origen de stock).
            $from_address = $item->from_address;

            // Relación to_address (destino de stock).
            $to_address = $item->to_address;

            return [
                'stock_suggestion_article_id' => $item->id,
                'article_id' => !empty($item->article_id) ? $item->article_id : 0,
                'provider_code' => $article && !empty($article->provider_code) ? $article->provider_code : '',
                'bar_code' => $article && !empty($article->bar_code) ? $article->bar_code : '',
                'article' => $article && !empty($article->name) ? $article->name : '',
                'cantidad' => !empty($item->suggested_amount) ? $item->suggested_amount : 0,
                'from_address_id' => !empty($item->from_address_id) ? $item->from_address_id : 0,
                'to_address_id' => !empty($item->to_address_id) ? $item->to_address_id : 0,
                'from_address' => $from_address && !empty($from_address->street) ? $from_address->street : '',
                'to_address' => $to_address && !empty($to_address->street) ? $to_address->street : '',
            ];
        });

        return response()->json(['models'   => $models], 200);
    }
}
