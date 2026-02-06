<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\ArticleUbication;
use Illuminate\Database\Seeder;

class ArticleUbicationSeeder extends Seeder
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
                'name'  => 'Pasillo',
            ],
            [
                'name'  => 'Estante',
            ],
            [
                'name'  => 'Columna',
            ],
        ];

        $addresses = Address::all();

        foreach ($addresses as $address) {

            foreach ($models as $model) {

                $model['user_id'] = config('app.USER_ID');
                $model['address_id'] = $address->id;

                ArticleUbication::create($model);
            }
        }

    }
}
