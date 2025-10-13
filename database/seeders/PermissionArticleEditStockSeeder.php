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
            'singular'      => 'Modificar stock',
            'plural'        => 'articulos',
            'en'            => 'article.edit_stock',
        ]);
    }
}
