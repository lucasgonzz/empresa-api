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
        $user = User::where('company_name', 'Autopartes Boxes')
                    ->first();
        $categories = Category::where('user_id', $user->id)
                                ->get();

        $auto_partes_categories = [
            [
                'category_name'     => 'Accesorios',
                'category_id'       => 1,
                'sub_categories'    => [
                    'Herramientas',
                    'Instrumental',
                    'Amortiguadores de Baul',
                ],
            ],
            [
                'category_name'     => 'Encendido',
                'category_id'       => 2,
                'sub_categories'    => [
                    'Alternadores',
                    'Baterias',
                    'Bobinas de Encendido',
                    'Bujias',
                    'Kit de encendido',
                ],
            ],
            [
                'category_name'     => 'Iluminacion',
                'category_id'       => 3,
                'sub_categories'    => [
                    'Faros LED',
                    'Faros para carroceria',
                    'Kit de trailer',
                    'Lamparas LED',
                ],
            ],
            [
                'category_name'     => 'Motor',
                'category_id'       => 4,
                'sub_categories'    => [
                    'Kit de distribucion',
                    'Lubricantes',
                ],
            ],
            [
                'category_name'     => 'Suspencion y frenos',
                'category_id'       => 5,
                'sub_categories'    => [
                    'Amortiguadores',
                    'Bieletas',
                    'Cazoletas',
                    'Espirales',
                    'Espirales GNC',
                    'Extremos de direccion',
                    'Kit tren delantero',
                ],
            ],
        ];

        foreach ($auto_partes_categories as $category) {
            $num = 1;
            foreach ($category['sub_categories'] as $sub_category) {
                $sub_category = SubCategory::create([
                    'num'           => $num,
                    'name'          => $sub_category,
                    'category_id'   => $category['category_id'],
                    'user_id'       => $user->id,
                ]);         
                $num++;
            }
        }

        return;




        foreach ($categories as $category) {
            $names = [];
            if ($category->name == 'Herramientas') {
                
                $names = ['Martillos', 'Pinzas'];

            } else if ($category->name == 'Utensilios') {
                
                $names = ['Cuchillos', 'Cucharas'];

            }  else if ($category->name == 'Muebles') {
                
                $names = ['Comedor', 'Dormitorio'];

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
 