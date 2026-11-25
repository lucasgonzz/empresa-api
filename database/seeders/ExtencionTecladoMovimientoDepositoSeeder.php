<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtencionTecladoMovimientoDepositoSeeder extends Seeder
{
    public function run()
    {
        $slug = 'teclado_nuevo_movimiento_deposito';

        if (ExtencionEmpresa::where('slug', $slug)->exists()) {
            return;
        }

        $extension = new ExtencionEmpresa();
        $extension->slug = $slug;
        $extension->name = 'Carga de movimientos de depósito con teclado';
        $extension->modulo = 'Produccion y depositos';
        $extension->description = 'Usar Enter para cargar cantidades y volver al buscador al crear movimientos de depósito.';
        $extension->save();
    }
}
