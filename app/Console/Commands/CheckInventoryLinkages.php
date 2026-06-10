<?php

namespace App\Console\Commands;

use App\Http\Controllers\CommonLaravel\Helpers\Numbers;
use App\Http\Controllers\Helpers\InventoryLinkageHelper;
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
    protected $description = 'Reconcilia artículos del usuario proveedor con los clientes de inventory linkages';

    /**
     * ID fijo del usuario dueño/proveedor con inventory linkages (instancia Autopartes/Fenix).
     *
     * @var int
     */
    protected $provider_user_id = 2;

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
     * Reconcilia creación, actualización (precio + código de barras) y eliminación de artículos
     * entre el usuario proveedor y los usuarios cliente de cada inventory linkage.
     *
     * @return int
     */
    public function handle()
    {
        // Usuario dueño del inventario fuente (proveedor de los clientes vinculados).
        $user = User::where('id', $this->provider_user_id)->first();

        if (is_null($user)) {
            Log::warning('check_inventory_linkages: user not found for id: '.$this->provider_user_id);
            $this->error('User not found for id: '.$this->provider_user_id);
            return 1;
        }

        Log::info('Se llamo comando check_inventory_linkages');

        $inventory_linkages = InventoryLinkage::with(['client.comercio_city_user'])
            ->where('user_id', $user->id)
            ->get();

        // Artículos activos del proveedor, con imágenes para crear copias completas en clientes.
        $articles = Article::with('images')
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->get();

        Log::info(count($articles).' articulos');
        $this->info(count($articles).' articulos');

        $source_article_ids = $articles->pluck('id')->all();

        // IDs de artículos soft-deleted del proveedor; se propagan como delete en clientes.
        $deleted_article_ids = Article::where('user_id', $user->id)
            ->onlyTrashed()
            ->pluck('id')
            ->all();

        Log::info(count($deleted_article_ids).' articulos eliminados en el source user');
        $this->info(count($deleted_article_ids).' articulos eliminados en el source user');

        $inventory_linkage_helper = new InventoryLinkageHelper(null, $user->id);
        $client_articles_by_provider_id_cache = [];
        $deleted_client_user_ids_handled = [];
        $total_creados = 0;
        $total_actualizados = 0;
        $total_eliminados = 0;

        foreach ($inventory_linkages as $inventory_linkage) {

            $this->info('inventory_linkage de: '.$inventory_linkage->client->comercio_city_user->name);

            $client_comerciocity_user = $inventory_linkage->client->comercio_city_user;
            $client_user_id = $inventory_linkage->client->comercio_city_user_id;

            // Delete batch por cliente (una sola pasada aunque haya varios linkages al mismo comercio_city_user).
            if (!isset($deleted_client_user_ids_handled[$client_user_id])) {
                $deleted_count = $inventory_linkage_helper->delete_client_articles_for_deleted_provider_article_ids(
                    $client_user_id,
                    $deleted_article_ids
                );
                $total_eliminados += $deleted_count;
                if ($deleted_count > 0) {
                    $this->info('Se eliminaron '.$deleted_count.' articulos cliente (reconciliacion delete)');
                }
                $deleted_client_user_ids_handled[$client_user_id] = true;
            }

            $this->info('client_comerciocity_user id: '.$client_comerciocity_user->id);

            if (!isset($client_articles_by_provider_id_cache[$client_user_id])) {
                // Evita N+1: traer en batch los client_articles mapeados por provider_article_id.
                $client_articles_by_provider_id_cache[$client_user_id] = $this->get_client_articles_by_provider_article_ids(
                    $client_user_id,
                    $source_article_ids
                );
            }

            $client_articles_by_provider_id = $client_articles_by_provider_id_cache[$client_user_id];
            $actualizados_linkage = 0;
            $creados_linkage = 0;

            foreach ($articles as $article) {
                $client_article = $client_articles_by_provider_id[$article->id] ?? null;

                if (is_null($client_article)) {
                    $this->comment($inventory_linkage->client->name.' no tiene '.$article->name.' — creando');

                    $client_article = $inventory_linkage_helper->ensure_client_article_for_linkage($inventory_linkage, $article);

                    if (!is_null($client_article)) {
                        $client_articles_by_provider_id[$article->id] = $client_article;
                        $client_articles_by_provider_id_cache[$client_user_id][$article->id] = $client_article;
                        $creados_linkage++;
                        $this->info('Se creo articulo cliente: '.$client_article->name);
                    }

                    continue;
                }

                $previus_cost = $client_article->cost;
                $was_updated = $inventory_linkage_helper->sync_client_article_price_and_bar_code(
                    $inventory_linkage,
                    $article,
                    $client_article
                );

                if ($was_updated) {
                    $actualizados_linkage++;
                    $this->info('Se actualizo '.$client_article->name.' (precio y/o codigo de barras)');

                    if ($client_article->cost != $previus_cost) {
                        Log::info(
                            'Se actualizo el precio de '.$client_article->name.' paso de $'
                            .Numbers::price($previus_cost).' a '.Numbers::price($client_article->cost)
                        );
                    }
                }
            }

            $total_creados += $creados_linkage;
            $total_actualizados += $actualizados_linkage;

            Log::info('------------------------------------------------------- ');
            Log::info('Linkage '.$inventory_linkage->id.': creados '.$creados_linkage.', actualizados '.$actualizados_linkage);
            $this->info('Linkage: creados '.$creados_linkage.', actualizados '.$actualizados_linkage);
        }

        $this->info('Total creados: '.$total_creados.', actualizados: '.$total_actualizados.', eliminados: '.$total_eliminados);
        Log::info('check_inventory_linkages total creados: '.$total_creados.', actualizados: '.$total_actualizados.', eliminados: '.$total_eliminados);

        return 0;
    }

    /**
     * Obtiene artículos del cliente indexados por provider_article_id.
     *
     * @param int $client_user_id ID del usuario cliente.
     * @param array $provider_article_ids IDs de artículos del proveedor a buscar.
     * @param int $chunk_size Tamaño de lote para whereIn.
     * @return array<int, Article>
     */
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
}
