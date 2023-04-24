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
                'name' => 'Presupuestos',
                'slug' => 'budgets',
            ],
            [
                'name' => 'Ordenes de Produccion',
                'slug' => 'production',
            ],
            [
                'name' => 'Combos',
                'slug' => 'combos',
            ],
            [
                'name' => 'Esconder ventas',
                'slug' => 'sales.hide',
            ],
            [
                'name' => 'Acopios',
                'slug' => 'acopios',
            ],
            [
                'name' => 'Online',
                'slug' => 'online',
            ],
            [
                'name' => 'Costo real',
                'slug' => 'article.costo_real',
            ],
            [
                'name' => 'Escanear Codigos de Barra',
                'slug' => 'bar_code_scanner',
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
