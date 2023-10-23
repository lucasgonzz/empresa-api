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
        $user = User::where('company_name', 'Jugueteria Rosario')->first();
        $models = [
            // Juguetes
            [
                'num'               => 1,
                'from'              => 0,
                'until'             => 9,
                'percentage'        => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 2,
                'from'              => 10,
                'until'             => 19,
                'percentage'        => 10,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 3,
                'from'              => 20,
                'until'             => 24,
                'percentage'        => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 4,
                'from'              => 25,
                'until'             => 10,
                'percentage'        => 7,
                'sale_type_id'      => 1,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],

            // Varios
            [
                'num'               => 5,
                'from'              => 0,
                'until'             => 9,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 6,
                'from'              => 10,
                'until'             => 14,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 7,
                'from'              => 15,
                'until'             => 100,
                'percentage'        => 5,
                'sale_type_id'      => 2,
                'user_id'           => $user->id,
                'except_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],

            // Perdidas
            [
                'num'               => 8,
                'from'              => 0,
                'until'             => 19,
                'percentage'        => 5,
                'sale_type_id'      => null,
                'user_id'           => $user->id,
                'for_only_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],
            [
                'num'               => 9,
                'from'              => 20,
                'until'             => 100,
                'percentage'        => 2,
                'sale_type_id'      => null,
                'user_id'           => $user->id,
                'for_only_sellers'    => [
                    [
                        'id'    => 3,
                    ]
                ],
            ],

            // Oscar fede papi
            [
                'num'               => 10,
                'user_id'           => $user->id,
                'for_all_sellers'           => [
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
            if (isset($model['for_all_sellers'])) {
                GeneralHelper::attachModels($commission, 'for_all_sellers', $model['for_all_sellers'], ['percentage']);
            }
            if (isset($model['for_only_sellers'])) {
                GeneralHelper::attachModels($commission, 'for_only_sellers', $model['for_only_sellers']);
            }
            if (isset($model['except_sellers'])) {
                GeneralHelper::attachModels($commission, 'except_sellers', $model['except_sellers']);
            }
        }
    }
}
