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
        $this->truvari();

        $this->default();
    }


    function truvari() {
        if (env('FOR_USER') != 'truvari') {
            return;
        }

        $categories = [
            'Vinos',
            'Espumantes',
            'Bebidas varias',
            'Whiskies Importados',
        ];

        $num = 1;
        foreach ($categories as $category) {
            Category::create([
                'num'     => $num,
                'name'    => $category,
                'user_id' => env('USER_ID'),
            ]);
            $num++;
        }
    }


    function default() {
        if (env('FOR_USER') == 'truvari') {
            return;
        }
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
                'user_id' => env('USER_ID'),
            ]);
            $num++;
        }

        if (env('FOR_USER') == 'golo_norte') {
            $this->adjuntar_price_types($categories);
        }
    }

    function adjuntar_price_types($categories) {

        $price_types = PriceType::where('user_id', env('USER_ID'))
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
