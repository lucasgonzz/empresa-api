<?php

namespace Database\Seeders;

use App\Models\PriceType;
use App\Models\User;
use Illuminate\Database\Seeder;

class PriceTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $for_user = env('FOR_USER');

        if ($for_user == 'pack_descartables') {
            $this->pack_descartables();
        } else if ($for_user == 'colman' || $for_user == 'ferretodo') {
            $this->colman();
        } else if ($for_user == 'golo_norte') {
            $this->golo_norte();
        } else if ($for_user == 'electro_lacarra') {
            $this->electro_lacarra();
        }

    }

    function electro_lacarra() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista 1',
                'position'      => 1,
                'percentage'    => 15,
                'incluir_en_lista_de_precios_de_excel'      => 0,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 1,
                'name'          => 'Mayorista 2',
                'position'      => 1,
                'percentage'    => 15,
                'incluir_en_lista_de_precios_de_excel'      => 0,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Minorista',
                'percentage'    => 50,
                'position'      => 2,
                'incluir_en_lista_de_precios_de_excel'    => 1,
                'user_id'       => env('USER_ID'),
            ],
            
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function golo_norte() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Distribuidor',
                'percentage'    => 5,
                'position'      => 1,
                'ocultar_al_publico'    => 1,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Mayorista',
                'percentage'    => 10,
                'position'      => 2,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Minorista',
                'percentage'    => 15,
                'position'      => 3,
                'user_id'       => env('USER_ID'),
            ],
            
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function pack_descartables() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'PB cerrado',
                'percentage'    => 20,
                'position'      => 1,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Lista 3',
                'percentage'    => 30,
                'position'      => 2,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Lista 2',
                'percentage'    => 40,
                'position'      => 3,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Lista 1',
                'percentage'    => 50,
                'position'      => 3,
                'user_id'       => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }

    function colman() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'Mayorista',
                'percentage'    => 100,
                'position'      => 1,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 2,
                'name'          => 'Comercio',
                'percentage'    => 100,
                'position'      => 2,
                'user_id'       => env('USER_ID'),
            ],
            [
                'num'           => 3,
                'name'          => 'Consumidor final',
                'percentage'    => 100,
                'position'      => 3,
                'user_id'       => env('USER_ID'),
            ],
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }
}
