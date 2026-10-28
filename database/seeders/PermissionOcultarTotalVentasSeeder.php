<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use Illuminate\Database\Seeder;

/**
 * Ticket #28: agrega «Ocultar el total de las ventas» para bases existentes.
 *
 * Es prohibitivo: solo crea/completa el catálogo, sin asignarlo a ningún empleado.
 * Repetirlo conserva todos los ids y pivots, incluidos los tildes que eligió el dueño.
 * Las bases nuevas reciben la misma definición por PermissionSeeder, llamado desde
 * DatabaseSeeder, UserSetupHelper y DemoSetupHelper.
 *
 * php artisan db:seed --class=PermissionOcultarTotalVentasSeeder
 */
class PermissionOcultarTotalVentasSeeder extends Seeder
{
    const SLUG = 'sale.index.hide_total';

    public function run()
    {
        // También actualiza la etiqueta de Cuenta corriente/cantidad, que ya no
        // debe prometer que Total es visible para todo empleado sin excepción.
        PermisosCatalogoHelper::aplicar();
    }
}
