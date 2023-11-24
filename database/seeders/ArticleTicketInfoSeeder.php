<?php

namespace Database\Seeders;

use App\Models\ArticleTicketInfo;
use Illuminate\Database\Seeder;

class ArticleTicketInfoSeeder extends Seeder
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
                'name'  => 'Codigo de barras',
            ],
            [
                'name'  => 'Codigo de proveedor',
            ],
        ];

        foreach ($models as $model) {
            ArticleTicketInfo::create($model);
        }
    }
}
