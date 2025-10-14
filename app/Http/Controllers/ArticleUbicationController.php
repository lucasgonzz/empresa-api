<?php

namespace App\Http\Controllers;

use App\Http\Controllers\CommonLaravel\ImageController;
use App\Jobs\AttachArticleUbications;
use App\Models\Article;
use App\Models\ArticleUbication;
use Illuminate\Http\Request;

class ArticleUbicationController extends Controller
{

    public function index() {
        $models = ArticleUbication::where('user_id', $this->userId())
                            ->orderBy('created_at', 'DESC')
                            ->withAll()
                            ->get();
        return response()->json(['models' => $models], 200);
    }

    function update_article(Request $request, $article_id) {
        
        $article = Article::find($article_id);

        foreach ($request->article_ubications as $ubication) {
            
            $article->article_ubications()->updateExistingPivot($ubication['id'], [
                'ubication'     => $ubication['pivot']['ubication'],
                'notes'         => $ubication['pivot']['notes'],
            ]);
        }

        return response()->json(['model' => $this->fullModel('Article', $article_id)], 200);
    }

    public function store(Request $request) {
        $model = ArticleUbication::create([
            'name'                  => $request->name,
            'address_id'             => $request->address_id,
            'user_id'               => $this->userId(),
        ]);

        dispatch(new AttachArticleUbications($model->id));

        return response()->json(['model' => $this->fullModel('ArticleUbication', $model->id)], 201);
    }  

    public function show($id) {
        return response()->json(['model' => $this->fullModel('ArticleUbication', $id)], 200);
    }

    public function update(Request $request, $id) {
        $model = ArticleUbication::find($id);
        $model->name                = $request->name;
        $model->address_id                = $request->address_id;
        $model->save();
        $this->sendAddModelNotification('ArticleUbication', $model->id);
        return response()->json(['model' => $this->fullModel('ArticleUbication', $model->id)], 200);
    }

    public function destroy($id) {
        $model = ArticleUbication::find($id);
        ImageController::deleteModelImages($model);
        $model->delete();
        $this->sendDeleteModelNotification('ArticleUbication', $model->id);
        return response(null);
    }
}

