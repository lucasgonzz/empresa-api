<?php

namespace Database\Seeders;

use App\Models\OnlineTemplate;
use Illuminate\Database\Seeder;

class OnlineTemplateSeeder extends Seeder
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
                'name'  => 'Moderno',
                'slug'  => 'moderno',
            ],
            [
                'name'  => 'Clasico',
                'slug'  => 'clasico',
            ],
            [
                'name'  => 'ComercioCity',
                'slug'  => 'comerciocity',
            ],
        ];

        foreach ($models as $model) {

            /**
             * Va firstOrCreate y NO create a secas, aunque parezca de más en un seeder:
             * a este seeder no lo llama solo DatabaseSeeder sobre una base nueva, también lo
             * llaman UserSetupHelper::base_seeders() y DemoSetupHelper::base_seeders() sobre
             * bases que ya pueden tener las filas. Con create() cada corrida extra duplica las
             * plantillas en silencio y el select de Configuración online muestra "Moderno" dos
             * veces. La clave de búsqueda es el slug porque es lo que consume la tienda
             * (App.vue arma la clase 'plantilla-' + slug); el name es solo la etiqueta.
             */
            OnlineTemplate::firstOrCreate(
                ['slug' => $model['slug']],
                ['name' => $model['name']]
            );
        }
    }
}
