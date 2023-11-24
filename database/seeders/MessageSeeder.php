<?php

namespace Database\Seeders;

use App\Models\Message;
use Illuminate\Database\Seeder;

class MessageSeeder extends Seeder
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
                'buyer_id'      => 1,
                'user_id'       => 1,
                'text'          => 'Soy un mensaje sin leer',
                'from_buyer'    => 1,
            ],
            [
                'buyer_id'      => 1,
                'user_id'       => 1,
                'text'          => 'Soy un mensaje sin leer pero soy mucho mas largo como para ocupar mas espacio visteee',
                'from_buyer'    => 1,
            ],
            [
                'buyer_id'      => 2,
                'user_id'       => 1,
                'text'          => 'Soy un mensaje sin leer pero soy mucho mas largo como para ocupar mas espacio visteee',
                'from_buyer'    => 1,
            ],
        ];
        foreach ($models as $model) {
            Message::create($model);
        }
    }
}
