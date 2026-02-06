<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategoryForrajeriaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $models = [
            'Perro joven',
            'Perro adulto',
            'Gato joven',
            'Gato adulto',
        ];


        foreach ($models as $model) {
            Category::create([
                'name'  => $model,
                'user_id'   => config('app.USER_ID'),
            ]);
        }
    }
}
