<?php

namespace Database\Seeders;

use App\Models\Pending;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PendingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {


        $hace_un_mes = Carbon::now()->subMonth()->startOfMonth();

        $models = [
            [
                'detalle'                => 'Pagar impuestos',
                'fecha_realizacion'      => $hace_un_mes->addDays(3),
                'es_recurrente'          => 1,
                'unidad_frecuencia_id'   => 2,
                'cantidad_frecuencia'    => 2,
                'expense_concept_id'     => 2,
                'notas'                  => 'Mandar comporbante de pago',
                'created_at'             =>  $hace_un_mes,
            ],
        ];

        foreach ($models as $model) {
            
            $model['user_id'] = config('app.USER_ID');

            Pending::create($model);
        }
    }
}
