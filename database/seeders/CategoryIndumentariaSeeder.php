<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoryIndumentariaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = ['Remera', 'Pantalon', 'Camisa', 'Buzo', 'Short', 'Chaqueta', 'Pollera', 'Vestido', 'Chaleco', 'Campera'];


        foreach ($models as $model) {
            Category::create([
                'name'  => $model,
                'user_id'   => env('USER_ID'),
            ]);
        }
    }
}
