<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\ProviderOrderHelper;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
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

        $num = 1;

        for ($mes=12; $mes >= 0 ; $mes--) { 
            
            $model = [
                'num'                                       => $num,
                'total_with_iva'                            => 0,
                'total_from_provider_order_afip_tickets'    => 0,
                'provider_id'                               => 1,
                'provider_order_status_id'                  => 1,
                'days_to_advise'                            => 2,
                'created_at'                                => Carbon::today()->subMonths($mes),
                'user_id'                                   => 500,
            ];

            $order = ProviderOrder::create($model);

            $num++;

            $this->agregar_articulos($order, $mes);

            $this->agregar_afip_ticket($order, $mes);
        }

    }

    function agregar_afip_ticket($order, $mes) {

        $total = 800 + (12 - $mes) * 100;

        $models = [
            [
                'total' => $total / 2,
            ],
            [
                'total' => $total / 2,
            ],
        ];

        foreach ($models as $model) {
            
            ProviderOrderAfipTicket::create([
                'total'                 => $model['total'],
                'provider_order_id'     => $order->id,
            ]);
        }

    }

    function agregar_articulos($order, $mes) {

        $cost = 1000 + (12 - $mes) * 100;

        $articles = [
            [
                'id'        => 1,
                'status'    => 'active',
                'iva_id'    => null,
                'pivot'     => [
                    'amount'    => 1,
                    'cost'      => $cost,
                    'received'  => 1,
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
