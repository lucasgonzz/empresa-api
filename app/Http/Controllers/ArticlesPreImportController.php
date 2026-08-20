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
         * Misión `costo-bruto-por-condicion-fiscal` (20/8/2026): el `costo_nuevo` del pre-import es
         * un costo tipeado por una persona, igual que el del ABM o el de una compra, así que sigue
         * el mismo criterio que las otras tres vías. Antes entraba literal a `articles.cost`, que
         * es NETO por convención: para un Monotributista eso significaba escribir un bruto en una
         * columna neta, y que aplicar_iva() le sumara el IVA una segunda vez.
         *
         * El usuario se resuelve una sola vez, fuera del loop: es el mismo para todas las filas.
         */
        $user = UserHelper::user();
        $es_bruto = ArticlePricesHelper::costo_tipeado_es_bruto($user);

        foreach ($request->articles_id as $article_id) {

            $article = Article::find($article_id);
            $pivot = ArticleArticlesPreImport::where('article_id', $article_id)
                                                ->where('articles_pre_import_id', $request->articles_pre_import_id)
                                                ->first();

            if ($es_bruto) {
                $article->cost_bruto = $pivot->costo_nuevo;
                $article->cost = ArticlePricesHelper::back_out_iva($article, $pivot->costo_nuevo, $user);
            } else {
                $article->cost = $pivot->costo_nuevo;
                $article->cost_bruto = null;
            }

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
