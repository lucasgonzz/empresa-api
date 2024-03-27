<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Models\ProviderOrder;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ProviderOrderSeeder extends Seeder
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
                'num'                                       => 1,
                'total_with_iva'                            => 0,
                'total_from_provider_order_afip_tickets'    => 0,
                'provider_id'                               => 1,
                'provider_order_status_id'                  => 1,
                'days_to_advise'                            => 2,
                'created_at'                                => Carbon::today()->subMonths(2),
                'user_id'                                   => 500,
            ],
            [
                'num'                                       => 1,
                'total_with_iva'                            => 0,
                'total_from_provider_order_afip_tickets'    => 0,
                'provider_id'                               => 1,
                'provider_order_status_id'                  => 1,
                'days_to_advise'                            => 2,
                'created_at'                                => Carbon::today()->subMonths(1),
                'user_id'                                   => 500,
            ],
            [
                'num'                                       => 1,
                'total_with_iva'                            => 0,
                'total_from_provider_order_afip_tickets'    => 0,
                'provider_id'                               => 1,
                'provider_order_status_id'                  => 1,
                'days_to_advise'                            => 2,
                'created_at'                                => Carbon::today()->subDays(1),
                'user_id'                                   => 500,
            ],
        ];
        foreach ($models as $model) {
            $order = ProviderOrder::create($model);
            $articles = [
                [
                    'id'        => rand(1,10),
                    'status'    => 'active',
                    'iva_id'    => null,
                    'pivot'     => [
                        'amount'    => rand(1,10),
                        'cost'      => rand(100, 500),
                        'received'  => rand(1,10),
                        'notes'             => null,
                        'received_cost'     => null,
                        'update_cost'       => null,
                        'update_provider'   => null,
                        'cost_in_dollars'   => null,
                        'add_to_articles'   => null,
                        'address_id'        => null,
                        'iva_id'            => null,
                    ],
                ],
                [
                    'id'        => rand(1,10),
                    'status'    => 'active',
                    'iva_id'    => null,
                    'pivot'     => [
                        'amount'    => rand(1,10),
                        'cost'      => rand(100, 500),
                        'received'  => rand(1,10),
                        'notes'             => null,
                        'received_cost'     => null,
                        'update_cost'       => null,
                        'update_provider'   => null,
                        'cost_in_dollars'   => null,
                        'add_to_articles'   => null,
                        'address_id'        => null,
                        'iva_id'            => null,
                    ],
                ],
            ];
            ProviderOrderHelper::attachArticles($articles, $order);
        }
    }
}
