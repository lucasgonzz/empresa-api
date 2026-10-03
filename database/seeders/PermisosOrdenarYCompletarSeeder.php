<?php

namespace Database\Seeders;

use App\Http\Controllers\Helpers\PermisosCatalogoHelper;
use Illuminate\Database\Seeder;

/**
 * Pone al día los permisos de los empleados en una base de producción que ya existe (misión
 * empleados-duplicar-y-permisos, 29/9/2026).
 *
 *   php artisan db:seed --class=PermisosOrdenarYCompletarSeeder
 *
 * Hace dos cosas, las dos por `slug` y sin borrar ni reinsertar ninguna fila (los pivots de los
 * empleados van por `id`):
 *   1. Crea los permisos del catálogo que la base no tiene. Las bases viejas están incompletas
 *      (medido en copias de producción: entre 8 y 31 permisos menos), y sin la fila el dueño no
 *      puede dárselo a un empleado aunque la pantalla lo consulte.
 *   2. Les pone el grupo y el nombre del catálogo (`PermisosCatalogoHelper`), para que la pantalla
 *      de Empleados los muestre agrupados por módulo y con nombres que se entienden.
 *
 * Es idempotente: correrlo de nuevo no cambia nada. Los empleados conservan los permisos que tenían.
 */
class PermisosOrdenarYCompletarSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run()
    {
        $resultado = PermisosCatalogoHelper::aplicar();

        if (isset($this->command)) {
            $this->command->info('Permisos creados: '.$resultado['creados'].' — actualizados (grupo/nombre): '.$resultado['actualizados']);
        }
    }
}
