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
            if ($category->name == 'Lava ropas') {
                
                $names = ['lavarropa nuevo', 'lavarropas usados'];

            } else if ($category->name == 'Aires acondicionados') {
                
                $names = ['aire nuevo', 'aires acondicionados usados'];

            }  else if ($category->name == 'Computacion') {
                
                $names = ['computacion 1', 'computacion 2'];

            }  else if ($category->name == 'Tanques de oxigeno') {
                
                $names = ['Tanques de oxigeno 1', 'Tanques de oxigeno 2'];

            }  else if ($category->name == 'cosas para la casa') {
                
                $names = ['cosas para la casa 1', 'cosas para la casa 2'];

            }   else if ($category->name == 'Repuestos de lavarropas') {
                
                $names = ['Repuestos de lavarropas 1', 'Repuestos de lavarropas 2'];

            }   else if ($category->name == 'repuestos de aires acondicionados') {
                
                $names = ['repuestos de aires acondicionados 1', 'repuestos de aires acondicionados 2'];

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
 