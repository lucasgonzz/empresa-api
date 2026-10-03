<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use Illuminate\Database\Seeder;

/**
 * Siembra los permisos de los empleados (`permission_empresas`).
 *
 * Desde el 29/9/2026 (misión empleados-duplicar-y-permisos) la lista NO vive acá: vive en
 * `PermisosCatalogoHelper`, con el grupo, el nombre y el orden de cada permiso. Antes este archivo
 * tenía ~130 permisos escritos a mano, con 29 grupos distintos y sin orden.
 *
 * Es idempotente: sobre una base que ya tiene los permisos solo les pone el nombre y el grupo del
 * catálogo, no duplica filas (antes `create()` duplicaba si se corría dos veces).
 *
 * Los permisos que se agregaron después de la primera versión (produccion.index, alerts.*,
 * vender.prohibir_*, payment_plan.*, etc.) tienen todavía su seeder suelto para las bases de
 * producción viejas; ya no hacen falta para bases nuevas y siguen valiendo, pero el que pone al día
 * una base existente es `PermisosOrdenarYCompletarSeeder`.
 */
class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        PermisosCatalogoHelper::aplicar();
    }
}
