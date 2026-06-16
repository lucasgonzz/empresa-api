<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Permiso para personalizar el nombre del artículo en el remito de Vender.
 * Ejecutar en producción: php artisan db:seed --class=PermissionArticleVenderChangeNameSeeder
 */
class PermissionArticleVenderChangeNameSeeder extends Seeder
{
    /**
     * Inserta el permiso article.vender.change_name si aún no existe.
     *
     * @return void
     */
    public function run()
    {
        // Datos del permiso de nombre personalizado en vender.
        $permission_data = [
            'name' => 'Personalizar nombre del articulo',
            'model_name' => 'Vender',
            'slug' => 'article.vender.change_name',
        ];

        PermissionEmpresa::firstOrCreate(
            ['slug' => $permission_data['slug']],
            $permission_data
        );
    }
}
