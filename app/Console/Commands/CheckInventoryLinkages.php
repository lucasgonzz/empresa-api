<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\ArticleHelper;
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
     * inventory_linkage es para linkear los articles de un usuario "$inventory_linkage->client->comercio_city_user"
     * (que a su vez inventory_linkage->client puede pertenecer a un usuario de comerciocity)
     * hacia los los articles del usuario al que pertenece $inventory_linkage->user
     * 
     * Si user crea un article, se le crea a client->comercio_city_user seteando provider_article_id con el id del articulo padre
     * La idea es que si user elimina un articulo, y client->comercio_city_user 
     *
     */
    public function handle()
    {
        // $company_name = 'Autopartes Boxes';
        
        // if (config('app.APP_ENV') == 'production') {
        //     $company_name = 'Fenix';
        // } 
        $company_name = 'Fenix';

        $user = User::where('company_name', $company_name)
                        ->first();

        if (is_null($user)) {
            Log::warning('check_inventory_linkages: user not found for company_name: '.$company_name);
            $this->error('User not found for company_name: '.$company_name);
            return 1;
        }

        Log::info('Se llamo comando check_inventory_linkages');

        $inventory_linkages = InventoryLinkage::with(['client.comercio_city_user'])
            ->where('user_id', $user->id)
            ->get();

        $articles = Article::where('user_id', $user->id)
                            ->where('status', 'active')
                            ->get();

        Log::info(count($articles).' articulos');
        $this->info(count($articles).' articulos');
        
        $source_article_ids = $articles->pluck('id')->all();

        $deleted_article_ids = Article::where('user_id', $user->id)
            ->onlyTrashed()
            ->pluck('id')
            ->all();

        Log::info(count($deleted_article_ids).' articulos eliminados en el source user');
        $this->info(count($deleted_article_ids).' articulos eliminados en el source user');

        $actualizados = 0;
        $client_articles_by_provider_id_cache = [];
        $deleted_client_user_ids_handled = [];

        foreach ($inventory_linkages as $inventory_linkage) {

            $this->info('inventory_linkage de: '.$inventory_linkage->client->comercio_city_user->name);

            $client_comerciocity_user = $inventory_linkage->client->comercio_city_user;
            $client_user_id = $inventory_linkage->client->comercio_city_user_id;

            if (!isset($deleted_client_user_ids_handled[$client_user_id])) {
                $this->check_deleted_articles($inventory_linkage, $deleted_article_ids);
                $deleted_client_user_ids_handled[$client_user_id] = true;
            }

            // Log::info('client_comerciocity_user id: ');
            // Log::info($client_comerciocity_user->id);

            $this->info('client_comerciocity_user id: '.$client_comerciocity_user->id);

            if (!isset($client_articles_by_provider_id_cache[$client_user_id])) {
                // Evita N+1: traer en batch los client_articles mapeados por provider_article_id.
                $client_articles_by_provider_id_cache[$client_user_id] = $this->get_client_articles_by_provider_article_ids(
                    $client_user_id,
                    $source_article_ids
                );
            }

            $client_articles_by_provider_id = $client_articles_by_provider_id_cache[$client_user_id];

            foreach ($articles as $article) {
                $client_article = $client_articles_by_provider_id[$article->id] ?? null;

                if (is_null($client_article)) {
                    $this->comment($inventory_linkage->client->name.' no tiene '.$article->name);
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


    protected function get_client_articles_by_provider_article_ids($client_user_id, array $provider_article_ids, $chunk_size = 1000)
    {
        $articles_by_provider_id = [];

        if (empty($provider_article_ids)) {
            return $articles_by_provider_id;
        }

        foreach (array_chunk($provider_article_ids, $chunk_size) as $provider_article_ids_chunk) {
            $client_articles = Article::where('user_id', $client_user_id)
                ->whereIn('provider_article_id', $provider_article_ids_chunk)
                ->get();

            foreach ($client_articles as $client_article) {
                if (!is_null($client_article->provider_article_id)) {
                    $articles_by_provider_id[$client_article->provider_article_id] = $client_article;
                }
            }
        }

        return $articles_by_provider_id;
    }

    protected function check_deleted_articles($inventory_linkage, array $deleted_article_ids)
    {
        if (empty($deleted_article_ids)) {
            return;
        }

        // Log::info('check_deleted_articles');
        $this->info('check_deleted_articles');

        $client_comerciocity_user = $inventory_linkage->client->comercio_city_user;
        $client_user_id = $client_comerciocity_user->id;

        foreach (array_chunk($deleted_article_ids, 1000) as $deleted_article_ids_chunk) {
            $client_articles_to_delete = Article::where('user_id', $client_user_id)
                ->whereIn('provider_article_id', $deleted_article_ids_chunk)
                ->get();

            foreach ($client_articles_to_delete as $client_article) {
                $this->info('Eliminando el articulo '.$client_article->name);
                $client_article->delete();
            }
        }
    }
}
