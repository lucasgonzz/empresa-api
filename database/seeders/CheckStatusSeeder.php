<?php

namespace Database\Seeders;

use App\Models\CheckStatus;
use Illuminate\Database\Seeder;

class CheckStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //
        $models = [
            [
                'name'  => 'Pendiente',
            ],
            [
                'name'  => 'Disponible para cobrar',
            ],
            [
                'name'  => 'Pronto a vencerse',
            ],
            [
                'name'  => 'Vencido',
            ],
            [
                'name'  => 'Cobrado',
            ],
            [
                'name'  => 'Rechazado',
            ],
        ];

        foreach ($models as $model) {
            
            CheckStatus::create($model);
        }
    }
}
