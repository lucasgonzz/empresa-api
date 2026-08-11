<?php

namespace Database\Seeders;

use App\Models\OnlineTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la plantilla de diseño "ComercioCity" en la tabla online_templates:
 * es la que hace que la opción aparezca en el select de Configuración online → Diseño →
 * Plantilla y que el slug viaje al ecommerce dentro de
 * commerce.online_configuration.online_template.slug (grupo 345).
 *
 * Idempotente (`firstOrCreate` por slug): existe para las bases de producción que ya tienen
 * las dos plantillas viejas y a las que no se les vuelve a correr OnlineTemplateSeeder
 * completo. Se corre por cliente en la actualización de versión:
 *   php artisan db:seed --class=OnlineTemplateComercioCitySeeder
 *
 * Scope: una sola fila por base. `online_templates` es un catálogo global de la base, no
 * tiene `user_id`, así que acá NO va el loop por owners que sí corresponde en los recursos
 * por negocio.
 */
class OnlineTemplateComercioCitySeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /**
         * El slug tiene que ser exactamente 'comerciocity', en minúsculas y sin separadores:
         * la tienda concatena 'plantilla-' + slug y contra esa clase están escritos todos los
         * selectores de la plantilla. Un slug distinto deja la plantilla elegible pero sin un
         * solo estilo aplicado, y no da error en ningún lado.
         */
        OnlineTemplate::firstOrCreate(
            ['slug' => 'comerciocity'],
            ['name' => 'ComercioCity']
        );
    }
}
