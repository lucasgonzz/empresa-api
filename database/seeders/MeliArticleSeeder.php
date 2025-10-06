<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Image;
use Illuminate\Database\Seeder;

class MeliArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            [
                'name'  => 'Mesa De Luz Flotante Home Colecction Con Cajón Y Desayunador Acabado Mate Color Olmo Everest',
                'cost'  => 10000,
                'mercado_libre' => 1,
            ]
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = env('USER_ID');

            $article = Article::create($model);

            $this->set_images($article);
        }


    }

    function set_images($article) {
        Image::create([
            'imageable_id'      => $article->id,
            'imageable_type'    => 'article',
            'hosting_url'       => 'https://http2.mlstatic.com/D_NQ_NP_708401-MLU72748492705_112023-O.webp'
        ]);
    }
}
