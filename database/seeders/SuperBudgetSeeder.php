<?php

namespace Database\Seeders;

use App\Models\SuperBudget;
use App\Models\SuperBudgetFeature;
use App\Models\SuperBudgetFeatureItem;
use App\Models\SuperBudgetTitle;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class SuperBudgetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        require(database_path().'\super-budgets\angelo_mapa_de_mesas.php');
        $_model = SuperBudget::create([
            'client'            => $model['client'],
            'offer_validity'    => $model['offer_validity'],
            'hour_price'        => $model['hour_price'],
            'delivery_time'     => $model['delivery_time'],
        ]);
        foreach ($model['titles'] as $title) {
            SuperBudgetTitle::create([
                'text'             => $title['text'],
                'super_budget_id'   => $_model->id,
            ]);
        }
        foreach ($model['features'] as $feature) {
            $_feature = SuperBudgetFeature::create([
                'title'             => $feature['title'],
                'description'       => isset($feature['description']) ? $feature['description'] : null,
                'development_time'  => $feature['development_time'],
                'super_budget_id'   => $_model->id,
            ]);
            if (isset($feature['items'])) {
                foreach ($feature['items'] as $item) {
                    SuperBudgetFeatureItem::create([
                        'text'                      => $item,
                        'super_budget_feature_id'   => $_feature->id,
                    ]);
                }
            }
        }
    }
}