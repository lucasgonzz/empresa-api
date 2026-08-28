<?php

namespace Database\Seeders;

use App\Models\Cuota;
use Illuminate\Database\Seeder;

class CuotaSeeder extends Seeder
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
                'cantidad_cuotas'   => 1,
                'recargo'         => 5,
            ],
            [
                'cantidad_cuotas'   => 3,
                'recargo'         => 10,
            ],
            [
                'cantidad_cuotas'   => 6,
                'recargo'         => 20,
            ],
            [
                'cantidad_cuotas'   => 9,
            ],
            [
                'cantidad_cuotas'   => 12,
            ],
        ];

        foreach ($models as $model) {
            /*
                `config('app.USER_ID')` y no `env('USER_ID', 500)` (misión 63,
                siembra-local-igual-a-demo). Los dos dan lo mismo en local, pero `DemoSetupHelper`
                pisa la config con `config(['app.USER_ID' => $user->id])` antes de correr los
                seeders, y `env()` no se entera de esa sobreescritura: leyéndola de ahí, una
                instancia de demo cuyo `.env` no tuviera `USER_ID` sembraba las cinco cuotas para
                el usuario 500, que ahí no existe. Es además el mismo `config()` que usan todos
                los demás seeders de la lista.
            */
            $model['user_id'] = config('app.USER_ID');

            Cuota::create($model);
        }
    }
}
