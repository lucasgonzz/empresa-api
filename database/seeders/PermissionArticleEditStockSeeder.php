<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionArticleEditStockSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermissionEmpresa::create([
            'name'          => 'Modificar stock',
            'model_name'    => 'articulos',
            'slug'          => 'article.edit_stock',
        ]);
    }
}
