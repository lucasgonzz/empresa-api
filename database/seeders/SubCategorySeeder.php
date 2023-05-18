<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Category;
use App\Models\SubCategory;

class SubCategorySeeder extends Seeder
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
        $user = User::where('company_name', 'lucas')
                    ->first();
        $categories = Category::where('user_id', $user->id)
                                ->get();
        foreach ($categories as $category) {
            $names = [];
            if ($category->name == 'Herramientas') {
                
                $names = ['Martillos', 'Pinzas', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola1', 'Hola12', 'Hola123'];

            } else if ($category->name == 'Utensilios') {
                
                $names = ['Cuchillos', 'Cucharas'];

            }  else if ($category->name == 'Muebles') {
                
                $names = ['Comedor', 'Dormitorio', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola', 'Hola1', 'Hola12', 'Hola123'];

            } 
            $num = 1;
            for ($i=0; $i < count($names); $i++) {
                $sub_category = SubCategory::create([
                    'num'           => $num,
                    'name'          => $names[$i],
                    'category_id'   => $category->id,
                    'user_id'       => $user->id,
                ]);         
                $num++;
            }
        }
    }
}
 