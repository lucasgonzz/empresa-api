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
        $this->user = User::where('company_name', 'Autopartes Boxes')->first();

        if ($for_user == 'pack_descartables') {
            $this->pack_descartables();
        } else if ($for_user == 'colman') {
            $this->colman();
        }

    }

    function pack_descartables() {

        $models = [
            [
                'num'           => 1,
                'name'          => 'PB cerrado',
                'percentage'    => 20,
                'position'      => 1,
                'user_id'       => $this->user->id,
            ],
            [
                'num'           => 2,
                'name'          => 'Lista 3',
                'percentage'    => 30,
                'position'      => 2,
                'user_id'       => $this->user->id,
            ],
            [
                'num'           => 3,
                'name'          => 'Lista 2',
                'percentage'    => 40,
                'position'      => 3,
                'user_id'       => $this->user->id,
            ],
            [
                'num'           => 3,
                'name'          => 'Lista 1',
                'percentage'    => 50,
                'position'      => 3,
                'user_id'       => $this->user->id,
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
                'user_id'       => $this->user->id,
            ],
            [
                'num'           => 2,
                'name'          => 'Comercio',
                'percentage'    => 100,
                'position'      => 2,
                'user_id'       => $this->user->id,
            ],
            [
                'num'           => 3,
                'name'          => 'Consumidor final',
                'percentage'    => 100,
                'position'      => 3,
                'user_id'       => $this->user->id,
            ],
        ];
        foreach ($models as $model) {

            PriceType::create($model);
        }
    }
}
