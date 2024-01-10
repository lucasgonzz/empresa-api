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
        $user = User::where('company_name', 'Autopartes Boxes')->first();
        // $categories = ['Lava ropas', 'Aires acondicionados', 'Computacion', 'Tanques de oxigeno', 'cosas para la casa', 'Repuestos de lavarropas', 'repuestos de aires acondicionados', 'repuestos de muchas cosas'];
        $models = [
            'Herramientas',
            'Utensilios',
            'Muebles',
        ];

        $auto_partes = [
            'Accesorios',
            'Encendido',
            'Iluminacion',
            'Motor',
            'Suspencion y frenos',
        ];
        $num = 1;
        foreach ($auto_partes as $category) {
            Category::create([
                'num'     => $num,
                'name'    => $category,
                'user_id' => $user->id,
            ]);
            $num++;
        }
    }
}
