<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $this->lucas();
    }


    function lucas() {
        $user = User::where('company_name', 'Jugueteria Rosario')->first();
        // $categories = ['Lava ropas', 'Aires acondicionados', 'Computacion', 'Tanques de oxigeno', 'cosas para la casa', 'Repuestos de lavarropas', 'repuestos de aires acondicionados', 'repuestos de muchas cosas'];
        $models = [
            'Herramientas',
            'Utensilios',
            'Muebles',
        ];
        $num = 1;
        foreach ($models as $category) {
            Category::create([
                'num'     => $num,
                'name'    => $category,
                'user_id' => $user->id,
            ]);
            $num++;
        }
    }
}
