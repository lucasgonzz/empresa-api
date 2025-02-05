<?php

namespace Database\Seeders;

use App\Models\Combo;
use Illuminate\Database\Seeder;

class ComboSeeder extends Seeder
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
                'name'          => 'Combo 1',
                'user_id'       => env('USER_ID'),
                'price'         => 1000,
                'articles'      => [
                    [
                        'id'        => 64,
                        'amount'    => 1
                    ],
                    [
                        'id'        => 63,
                        'amount'    => 2
                    ],
                ],
            ]
        ];

        $num = 1;
        foreach ($models as $model) {
            
            $combo = Combo::create([
                'num'           => $num,
                'name'          => $model['name'],
                'price'         => $model['price'],
                'user_id'       => $model['user_id'],
            ]);

            $num++;

            foreach ($model['articles'] as $article) {
                
                $combo->articles()->attach($article['id'], [
                    'amount'    => $article['amount'],
                ]);
            }
        }
    }
}
