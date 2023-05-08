<?php

namespace Database\Seeders;

use App\Http\Controllers\CommonLaravel\Helpers\GeneralHelper;
use App\Models\Commission;
use App\Models\User;
use Illuminate\Database\Seeder;

class CommissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::where('company_name', 'lucas')->first();
        $models = [
            // Juguetes
            [
                'num'               => 1,
                'from'              => 0,
                'until'             => 5,
                'percentage'        => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 2,
                'from'              => 5,
                'until'             => 10,
                'percentage'        => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 3,
                'from'              => 10,
                'until'             => 20,
                'percentage'        => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 4,
                'from'              => 20,
                'until'             => 25,
                'percentage'        => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 5,
                'from'              => 25,
                'until'             => 100,
                'percentage'        => 5,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
            ],

            // Varios
            [
                'num'               => 6,
                'from'              => 0,
                'until'             => 5,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 7,
                'from'              => 5,
                'until'             => 10,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 8,
                'from'              => 10,
                'until'             => 15,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],
            [
                'num'               => 9,
                'from'              => 15,
                'until'             => 100,
                'percentage'        => 3,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
            ],

            // Oscar fede papi
            [
                'num'               => 10,
                'user_id'           => $user->id,
                'sellers'           => [
                    [
                        'id'     => 4,
                        'pivot' => [
                            'percentage'    => 2,
                        ],
                    ],
                    [
                        'id'     => 5,
                        'pivot' => [
                            'percentage'    => 2,
                        ],
                    ],
                    [
                        'id'     => 6,
                        'pivot' => [
                            'percentage'    => 1,
                        ],
                    ],
                ]
            ],
        ];

        foreach ($models as $model) {
            $commission = Commission::create([
                'num'               => isset($model['num']) ? $model['num'] : null,
                'from'              => isset($model['from']) ? $model['from'] : null,
                'until'             => isset($model['until']) ? $model['until'] : null,
                'percentage'        => isset($model['percentage']) ? $model['percentage'] : null,
                'sale_type_id'      => isset($model['sale_type_id']) ? $model['sale_type_id'] : null,
                'user_id'           => $model['user_id'],
            ]);
            if (isset($model['sellers'])) {
                GeneralHelper::attachModels($commission, 'sellers', $model['sellers'], ['percentage']);
            }
        }
    }
}
