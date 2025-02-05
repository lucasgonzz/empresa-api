<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        // $categories = ['Lava ropas', 'Aires acondicionados', 'Computacion', 'Tanques de oxigeno', 'cosas para la casa', 'Repuestos de lavarropas', 'repuestos de aires acondicionados', 'repuestos de muchas cosas'];
        $models = [
            'Herramientas',
            'Utensilios',
            'Muebles',
        ];

        $supermercado = [
            'Almacen',
            'Gaseosas',
        ];

        $auto_partes = [
            'Accesorios',
            'Encendido',
            'Iluminacion',
            'Motor',
            'Suspencion y frenos',
        ];

        $categories = [];

        $num = 1;
        foreach ($supermercado as $category) {
            $categories[] = Category::create([
                'num'     => $num,
                'name'    => $category,
                'user_id' => $user->id,
            ]);
            $num++;
        }

        if (env('FOR_USER') == 'golo_norte') {
            $this->adjuntar_price_types($categories, $user);
        }
    }

    function adjuntar_price_types($categories, $user) {

        $price_types = PriceType::where('user_id', $user->id)
                                ->orderBy('position', 'ASC')
                                ->get();


        $percetage = 5;
        
        foreach ($categories as $category) {
            
            
            foreach ($price_types as $price_type) {
                
                $category->price_types()->attach($price_type->id, [
                    'percentage' => $percetage,
                ]);

                $percetage += 5;
            }
        }
    }
}
