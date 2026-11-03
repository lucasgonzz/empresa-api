<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\sale\CotizacionPorArticuloHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\Article;
use Illuminate\Http\Request;

class CotizacionPorArticuloController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['ids' => 'nullable|array|max:1000', 'ids.*' => 'integer|min:1']);
        $habilitada = CotizacionPorArticuloHelper::disponible() && CotizacionPorArticuloHelper::disponible('article_budget');
        $filas = [];
        if ($habilitada && $request->ids) {
            $articulos = Article::with('provider')->where('user_id', UserHelper::userId())->whereIn('id', $request->ids)->get();
            foreach ($articulos as $articulo) {
                $dolar = $articulo->provider ? $articulo->provider->dolar : null;
                $filas[$articulo->id] = ['proveedor' => CotizacionPorArticuloHelper::positiva($dolar) ? (float) $dolar : null];
            }
        }
        return response()->json(['supported' => $habilitada, 'articulos' => $filas]);
    }
}
