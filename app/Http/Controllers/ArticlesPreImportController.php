<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\ArticleArticlesPreImport;
use App\Models\ArticlesPreImport;
use Illuminate\Http\Request;

class ArticlesPreImportController extends Controller
{

    public function index($from_date = null, $until_date = null) {
        $models = ArticlesPreImport::where('user_id', $this->userId())
                                ->orderBy('created_at', 'DESC')
                                ->withAll();
        if (!is_null($from_date)) {
            if (!is_null($until_date)) {
                $models = $models->whereDate('created_at', '>=', $from_date)
                                ->whereDate('created_at', '<=', $until_date);
            } else {
                $models = $models->whereDate('created_at', $from_date);
            }
        }

        $models = $models->get();
        return response()->json(['models' => $models], 200);
    }

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticlesPreImport', $id)], 200);
    }

    function updateArticles(Request $request) {
        $articulos_actualizados = 0;

        /*
         * 🔴 Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): el pre-import NO descompone, y
         * es a propósito.
         *
         * `costo_nuevo` sale de una fila que ya pasó por ProcessRow, que dejó el costo en NETO si la
         * planilla declaró que venía con IVA. Sacarle el IVA otra vez acá sería un segundo back-out
         * sobre el mismo número, y el costo caería un 21% dos veces.
         *
         * Una versión anterior de esta misión sí descomponía acá, y quedó declarada en el informe
         * como "bomba de tiempo": no explotaba sólo porque el único llamador de add_article() es
         * código muerto. Esta versión la cierra por diseño — el que declara si un número es bruto o
         * neto es el que lo carga, y el pre-import no carga nada: recibe algo ya resuelto.
         */

        foreach ($request->articles_id as $article_id) {

            $article = Article::find($article_id);
            $pivot = ArticleArticlesPreImport::where('article_id', $article_id)
                                                ->where('articles_pre_import_id', $request->articles_pre_import_id)
                                                ->first();

            $article->cost = $pivot->costo_nuevo;

            $article->save();
            ArticleHelper::setFinalPrice($article);

            $pivot->actualizado = 1;
            $pivot->save();
            
            $articulos_actualizados++;
        }
        return response()->json(['articulos_actualizados' => $articulos_actualizados], 200);
    }

    public function destroy($id) {
        $model = ArticlesPreImport::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticlesPreImport', $model->id);
        return response(null);
    }
}
