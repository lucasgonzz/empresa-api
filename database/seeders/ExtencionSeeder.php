<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $extencions = [
            [
                // 1
                'name' => 'Presupuestos',
                'slug' => 'budgets',
            ],
            [
                // 2
                'name' => 'Ordenes de Produccion',
                'slug' => 'production',
            ],
            [
                // 3
                'name' => 'Combos',
                'slug' => 'combos',
            ],
            [
                // 4
                'name' => 'Esconder ventas',
                'slug' => 'sales.hide',
            ],
            [
                // 5
                'name' => 'Acopios',
                'slug' => 'acopios',
            ],
            [
                // 6
                'name' => 'Online',
                'slug' => 'online',
            ],
            [
                // 7
                'name' => 'Costo real',
                'slug' => 'article.costo_real',
            ],
            [
                // 8
                'name' => 'Escanear Codigos de Barra',
                'slug' => 'bar_code_scanner',
            ],
            [
                // 9
                'name' => 'Usar sistema de administracion',
                'slug' => 'comerciocity_interno',
            ],
            [
                // 10
                'name' => 'Articulos por defecto en VENDER',
                'slug' => 'articles_default_in_vender',
            ],
            [
                // 11
                'name' => 'Mostrar Codigo interno en e-commerce',
                'slug' => 'article_num_in_online',
            ],
            [
                // 12
                'name' => 'Chequear ventas',
                'slug' => 'check_sales',
            ],
        ];
        foreach ($extencions as $extencion) {
            ExtencionEmpresa::create([
                'name' => $extencion['name'],
                'slug' => $extencion['slug'],
            ]);
        }
    }
}
