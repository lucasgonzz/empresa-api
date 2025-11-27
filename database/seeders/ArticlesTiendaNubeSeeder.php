<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use Illuminate\Database\Seeder;

class ArticlesTiendaNubeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $articles = [

            [
                'featured'          => 1,
                'bar_code'          => '001',
                'provider_code'     => 'p-001',
                'name'              => 'Mesa Tienda Nube',
                'stock'             => 100,
                'cost'              => 1,
                'sub_category_name' => 'Yerbas',
                'provider_id'       => 2,
                // 'tiendanube_product_id'       => 290274661,
                // 'images'            => [
                //     [
                //         'url'       => get_image('/storage/supermercado/yerba.webp'),
                //     ],
                // ],
            ],
        ];


        $num = 0;

        foreach ($articles as $article) {

            $num++;

            $art = Article::create([
                'bar_code'              => '00'.$num,
                'provider_code'         => 'p/'.$num,
                'name'                  => $article['name'],
                'slug'                  => ArticleHelper::slug($article['name'], env('USER_ID')),
                'cost'                  => $article['cost'],
                'price'                 => isset($article['price']) ? $article['price'] : null,
                'costo_mano_de_obra'    => isset($article['costo_mano_de_obra']) ? $article['costo_mano_de_obra'] : null,
                'status'                => isset($article['status']) ? $article['status'] : 'active',
                'featured'              => isset($article['featured']) ? $article['featured'] : null,
                'provider_id'           => isset($article['provider_id']) ? $article['provider_id'] : null,
                'percentage_gain'       => 100,
                'iva_id'                => isset($article['iva_id']) ? $article['iva_id'] : 2,
                'featured'              => isset($article['featured']) ? $article['featured'] : null,
                
                'presentacion'          => isset($article['presentacion']) ? $article['presentacion'] : null,
                'tiendanube_product_id'          => isset($article['tiendanube_product_id']) ? $article['tiendanube_product_id'] : null,
                
                'apply_provider_percentage_gain'    => 0,
                'default_in_vender'     => isset($article['default_in_vender']) && $this->for_user == 'hipermax' ? $article['default_in_vender'] : null,
                // 'category_id'           => $this->getCategoryId($user, $article),
                // 'sub_category_id'       => $this->getSubcategoryId($user, $article),
                // 'created_at'            => Carbon::now()->subDays($days),
                // 'updated_at'            => Carbon::now()->subDays($days),
                'user_id'               => env('USER_ID'),
            ]);    
            
            ArticleHelper::setFinalPrice($art, env('USER_ID'));
        }


    }
}
