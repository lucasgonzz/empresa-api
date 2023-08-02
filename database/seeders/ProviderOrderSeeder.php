<?php

namespace Database\Seeders;

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
                'created_at'                                => Carbon::today()->subDays(2),
                'user_id'                                   => 1,
            ],
        ];
        foreach ($models as $model) {
            ProviderOrder::create($model);
        }
    }
}
