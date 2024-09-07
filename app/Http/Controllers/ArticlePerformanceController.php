<?php

namespace App\Http\Controllers;

use App\Models\ArticlePerformance;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ArticlePerformanceController extends Controller
{

    function index($article_id) {
        $models = ArticlePerformance::where('article_id', $article_id)
                                    ->orderBy('performance_date', 'ASC')
                                    ->get();

        $models = $this->set_fechas($models);

        return response()->json(['models' => $models], 200);
    }

    function set_fechas($models) {

        foreach ($models as $model) {
            
            $model->fecha = Carbon::create($model->performance_date)->isoFormat('MMMM').' '.$model->performance_date->year;
        }

        return $models;
    }

    function setArticlesPerformance($company_name, $meses_atras) {
        set_time_limit(0);
        Log::info('setArticlesPerformance');
        $user = User::where('company_name', $company_name)->first();
        $start_date = Carbon::today()->subMonth($meses_atras)->startOfMonth();
        $end_date = Carbon::today()->subMonth($meses_atras-1)->startOfMonth();
        echo 'Fecha inicio: '.$start_date->format('d/m/y').' </br>';
        echo 'Fecha fin: '.$end_date->format('d/m/y').' </br>';


        $this->articulos_vendidos = [];

        Sale::where('user_id', $user->id)
                        ->whereDate('created_at', '>=', $start_date)
                        ->whereDate('created_at', '<=', $end_date)
                        ->chunk(100, function($sales) {
                            echo 'Entro a chunk con '.count($sales).' </br>';
                            Log::info('Entro a chunk con '.count($sales));
                            foreach ($sales as $sale) {
                                echo 'Entro en sale id: '.$sale->id.' </br>';
                                foreach ($sale->articles as $article) {
                                    echo 'Entro en article_id '.$article->id.' </br>';
                                    $this->add_to_articles($article, $sale);
                                }
                                echo '-------------------------------- </br>';
                            }
                        });

        foreach ($this->articulos_vendidos as $article) {
            $article_performance = ArticlePerformance::create([
                'article_id'    => $article['id'],
                'article_name'  => $article['name'],
                'cost'          => $article['cost'],
                'price'         => $article['price'],
                'amount'        => $article['amount'],
                'provider_id'   => $article['provider_id'],
                'category_id'   => $article['category_id'],

                // Este $article['created_at'] es la fecha de la venta, ver cuando se agregar a $this->articulos_vendidos
                'created_at'    => $article['created_at'],
                'user_id'       => $user->id,
            ]);
            echo 'Se creo performance de '.$article_performance->article_name.' con '.$article_performance->amount.' ventas para el mes '.$article_performance->created_at->format('F').' </br>';

        }
        echo 'termino';
    }

    function add_to_articles($article, $sale) {
        $index = array_search($article->id, array_column($this->articulos_vendidos, 'id'));
        if (!$index) {
            $this->articulos_vendidos[] = [
                'id'            => $article->id,
                'name'          => $article->name,
                'amount'        => (float)$article->pivot->amount,
                'cost'          => (float)$article->pivot->cost,
                'price'         => (float)$article->pivot->price,
                'provider_id'   => $article->provider_id,
                'category_id'   => $article->category_id,
                'created_at'    => $sale->created_at,
            ]; 
        } else {
            $this->articulos_vendidos[$index]['amount']   += (float)$article->pivot->amount;
            $this->articulos_vendidos[$index]['cost']     += (float)$article->pivot->cost;
            $this->articulos_vendidos[$index]['price']    += (float)$article->pivot->price;
        }
    }
}
