<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\Seeders\ArticleSeederHelper;
use App\Models\Article;
use App\Models\OrderProductionStatus;
use App\Models\Recipe;
use App\Models\RecipeRoute;
use App\Models\RecipeRouteType;
use Illuminate\Database\Seeder;

class HT5ProductionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->helper = new ArticleSeederHelper();

        $this->crear_articulos_para_producir();

        $this->crear_articulos_insumos();
        
        $this->crear_recipes();
    }

    function crear_articulos_para_producir() {
        $articles = [
            [
                'name'  => 'Corte Tela 40x50cm',
                'addresses' => [
                    [
                        'name'      => 'Tucuman',
                        'amount'    => 100,
                    ],
                    [
                        'name'      => 'Santa Fe',
                        'amount'    => 100,
                    ],
                ],
            ],
            [
                'name'  => 'Bolsa 40x50cm',
                'addresses' => [
                    [
                        'name'      => 'Tucuman',
                        'amount'    => 100,
                    ],
                    [
                        'name'      => 'Santa Fe',
                        'amount'    => 100,
                    ],
                ],
            ],
        ];
        $this->helper->crear_articles($articles);
    }

    function crear_articulos_insumos() {
        $articles = [
            [
                'name'  => 'Tela',
                'addresses' => [
                    [
                        'name'      => 'Tucuman',
                        'amount'    => 100,
                    ],
                    [
                        'name'      => 'Santa Fe',
                        'amount'    => 100,
                    ],
                ],
            ],
            [
                'name'  => 'Cinta',
                'addresses' => [
                    [
                        'name'      => 'Tucuman',
                        'amount'    => 100,
                    ],
                    [
                        'name'      => 'Santa Fe',
                        'amount'    => 100,
                    ],
                ],
            ],
        ];
        $this->helper->crear_articles($articles);
    }

    function crear_recipes() {
        $recipes = [

            [
                'article_name'  => 'Corte Tela 40x50cm',
                'rutas'     => [
                    'Interno'   => [

                        'insumos'       => [

                            [
                                'production_status'     => 'Corte',
                                'article_name'          => 'Tela',
                                'amount'                => 0.5,
                            ],
                        ],
                    ]
                ]
            ],

            [
                'article_name'  => 'Bolsa 40x50cm',
                'rutas'         => [

                    'Interno'   => [

                        'insumos'       => [
                            [
                                'production_status'     => 'Corte',
                                'article_name'          => 'Corte Tela 40x50cm',
                                'amount'                => 1,
                            ],
                            [
                                'production_status'     => 'Confeccion',
                                'article_name'          => 'Cinta',
                                'amount'                => 2,
                            ],
                        ],
                    ],

                    'Externo'   => [

                        'insumos'       => [
                            [
                                'production_status'     => 'Corte',
                                'article_name'          => 'Tela',
                                'amount'                => 0.5,
                            ],
                            [
                                'production_status'     => 'Corte',
                                'article_name'          => 'Cinta',
                                'amount'                => 2,
                            ],
                        ],

                    ],
                ],
            ],
        ];

        foreach ($recipes as $recipe) {

            $article = Article::where('name', $recipe['article_name'])
                                ->first();

            $recipe_model = Recipe::create([
                'name'              => $article->name,
                'article_id'        => $article->id,
                'user_id'           => config('app.USER_ID'),
            ]);
            
            foreach ($recipe['rutas'] as $route_name => $route_data) {

                $route_type = RecipeRouteType::where('name', $route_name)
                                        ->first();
                
                $model_route = RecipeRoute::create([
                    'recipe_id'                 => $recipe_model->id,
                    'recipe_route_type_id'      => $route_type->id,
                ]);


                foreach ($route_data['insumos'] as $insumo) {
                    
                    $insumo_model = Article::where('name', $insumo['article_name'])
                                        ->first();

                    $production_status = OrderProductionStatus::where('name', $insumo['production_status'])
                                                                ->first();

                    $model_route->articles()->attach($insumo_model->id, [
                        'amount'                        => $insumo['amount'],
                        'order_production_status_id'    => $production_status->id,
                        'address_id'                    => 0,
                    ]);
                }
            }
        }
    }
}
