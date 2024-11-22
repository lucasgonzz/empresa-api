<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Jobs\ProcessCheckInventoryLinkages;
use App\Models\Article;
use App\Models\InventoryLinkage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckInventoryLinkages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check_inventory_linkages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $company_name = 'Autopartes Boxes';
        
        if (env('APP_ENV') == 'production') {
            $company_name = 'Fenix';
        } 

        $user = User::where('company_name', $company_name)
                        ->first();

        Log::info('Se llamo comando check_inventory_linkages');

        $inventory_linkages = InventoryLinkage::where('user_id', $user->id)
                                            ->get();

        $articles = Article::where('user_id', $user->id)
                            ->where('status', 'active')
                            ->get();

        Log::info(count($articles).' articulos');
        $this->info(count($articles).' articulos');
        
        $vuelta = 1;
        $actualizados = 0;

        foreach ($inventory_linkages as $inventory_linkage) {

            $this->info('inventory_linkage de: '.$inventory_linkage->client->comercio_city_user->name);

            $this->check_deleted_articles($inventory_linkage);

            $articulos_con_nombre_distintos = [];
            $articulos_con_precio_distintos = [];

            $client_comerciocity_user = $inventory_linkage->client->comercio_city_user;

            Log::info('client_comerciocity_user id: ');
            Log::info($client_comerciocity_user->id);

            $this->info('client_comerciocity_user id: '.$client_comerciocity_user->id);

            foreach ($articles as $article) {

                $vuelta++;

                $client_article = Article::where('provider_article_id', $article->id)
                                        ->where('user_id', $inventory_linkage->client->comercio_city_user_id)
                                        ->first();

                if (is_null($client_article)) {
                    Log::info($inventory_linkage->client->name.' no tiene '.$article->name);
                } else {

                    if ($client_article->name != $article->name || $client_article->cost != $article->final_price || $client_article->stock != $article->stock) {
                        
                        $actualizados++;

                        $this->info('Cambios en '.$article->name);

                        if ($client_article->stock != $article->stock) {
                            $client_article->stock = $article->stock;
                            $this->info('Se actualizo Stock');
                        }

                        if ($client_article->name != $article->name) {
                            $client_article->name = $article->name;
                            $this->info('Se actualizo Nombre');
                        }

                        if ($client_article->cost != $article->final_price) {
                            $articulos_con_precio_distintos[] = $article;

                            $previus_cost = $client_article->cost;

                            $client_article->cost = $article->final_price;


                            ArticleHelper::setFinalPrice($client_article, $inventory_linkage->client->comercio_city_user_id, $client_comerciocity_user, $client_comerciocity_user->id);

                            Log::info('Se actualizo el precio de '.$client_article->name.' paso de $'.Numbers::price($previus_cost).' a '.Numbers::price($client_article->cost));
                            $this->info('Se actualizo '.$client_article->name);
                            $this->info('Se actualizo Precio');

                        }
                        $client_article->save();
                    }

                }


            }

            Log::info('------------------------------------------------------- ');
            Log::info('Se actualizaron '.$actualizados.' articulos');
            $this->info('Se actualizaron '.$actualizados.' articulos');

        }

        return 0;
    }


    function check_deleted_articles($inventory_linkage) {

        Log::info('check_deleted_articles');
        $this->info('check_deleted_articles');

        $deleted_articles = Article::where('user_id', $inventory_linkage->user_id)
                                    ->whereNotNull('deleted_at')
                                    ->withTrashed()
                                    ->get();

        $this->info(count($deleted_articles).' articulos eliminados');

        $client_comerciocity_user = $inventory_linkage->client->comercio_city_user;

        foreach ($deleted_articles as $deleted_article) {
            
            $client_article = Article::where('provider_article_id', $deleted_article->id)
                                        ->where('user_id', $client_comerciocity_user->id)
                                        ->first();

            if (!is_null($client_article)) {

                Log::info('Eliminando el articulo '.$client_article->name);
                $this->info('Eliminando el articulo '.$client_article->name);

                $client_article->delete();
            }
        }
    }
}
