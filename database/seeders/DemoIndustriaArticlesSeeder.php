<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Description;
use App\Models\Provider;
use App\Models\SubCategory;
use App\Observers\ArticleObserver;
use App\Services\DemoIndustriaEmbeddings;
use App\Services\DemoIndustriaService;
use App\Services\DemoIndustriaAssets;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DemoIndustriaArticlesSeeder extends Seeder
{
    public function run()
    {
        $slug = config('demo_industria_actual');
        $perfil = DemoIndustriaService::resolver($slug);
        if (!$perfil) {
            throw new InvalidArgumentException('La industria de la demo no tiene un catálogo preparado.');
        }
        // El seeder también se invoca directo: no guardar URLs de imágenes ausentes.
        DemoIndustriaAssets::exigir($slug);

        // Cada artículo recibe su vector horneado después de tener marca y descripción.
        // Los observers no deben disparar varias generaciones pagas durante la siembra.
        ArticleObserver::empezar_semilla();
        try {
            // El rubro queda completo o no queda: además evita un fsync por cada relación.
            DB::transaction(function () use ($perfil, $slug) {
                $this->sembrar($perfil, $slug);
            });
        } finally {
            ArticleObserver::terminar_semilla();
        }
    }

    protected function sembrar(array $perfil, $slug)
    {
        $userId = config('app.USER_ID');
        $categorias = [];
        foreach ($perfil['categorias'] as $indice => $nombre) {
            $representante = collect($perfil['productos'])->firstWhere('category_name', $nombre);
            $categoria = Category::firstOrCreate(['user_id' => $userId, 'name' => $nombre], ['num' => $indice + 1]);
            if ($representante && !empty($representante['image_url'])) {
                $categoria->image_url = DemoIndustriaService::url($representante['image_url']);
                $categoria->save();
            }
            $categorias[$nombre] = $categoria;
        }

        $proveedores = Provider::where('user_id', $userId)->orderBy('id')->get();
        if ($proveedores->isEmpty()) {
            $proveedores->push(Provider::create(['user_id' => $userId, 'num' => 1, 'name' => 'Abastecimiento '.$perfil['nombre_demo']]));
        }

        $helper = new ArticleSeederHelper();
        foreach ($perfil['productos'] as $indice => $item) {
            $categoria = $categorias[$item['category_name']];
            SubCategory::firstOrCreate([
                'user_id' => $userId,
                'category_id' => $categoria->id,
                'name' => $item['sub_category_name'],
            ], ['num' => $indice + 1]);
            $marca = Brand::firstOrCreate(['user_id' => $userId, 'name' => $item['brand_name']], [
                'num' => (int) Brand::where('user_id', $userId)->max('num') + 1,
            ]);

            $payload = [
                'name' => $item['name'],
                'bar_code' => $item['bar_code'],
                'provider_code' => $item['provider_code'],
                'provider_id' => $proveedores[$indice % $proveedores->count()]->id,
                'category_name' => $item['category_name'],
                'sub_category_name' => $item['sub_category_name'],
                // Los precios de ejemplo se expresan brutos; articles.cost almacena neto.
                'cost' => round($item['cost'] / 1.21, 2),
                'stock' => 180,
                'percentage_gain' => 50,
                'iva_id' => 2,
                'apply_provider_percentage_gain' => 0,
                'presentacion' => 1,
                'featured' => $indice < 8 ? 1 : 0,
                'images' => [['url' => DemoIndustriaService::url($item['image_url'])]],
                'stock_por_sucursal' => FerreteriaArticlesSeeder::PLANES_DE_STOCK[['A', 'B', 'C'][$indice % 3]],
            ];
            $articulo = $helper->crear_article($helper->add_price_types($payload));
            $articulo->brand_id = $marca->id;
            $articulo->save();
            // create() no trae los defaults de la base (por ejemplo aplicar_iva).
            // El precio inicial debe usar los mismos datos que cualquier recálculo posterior.
            $articulo->refresh();
            ArticleHelper::setFinalPrice($articulo, $userId);
            Description::create([
                'article_id' => $articulo->id,
                'title' => 'Descripción',
                'content' => $item['descripcion'],
            ]);
            if (!DemoIndustriaEmbeddings::aplicar($articulo, $slug)) {
                throw new \RuntimeException('El catálogo de '.$slug.' necesita un vector vigente para '.$item['name'].'.');
            }
            $helper->setStockMovement($articulo, $payload);
        }
    }
}
