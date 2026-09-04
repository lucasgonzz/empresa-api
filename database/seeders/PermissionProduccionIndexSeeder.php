<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Registra el permiso de empleado `produccion.index` (tanda correctivos 24/8, item 17), en el
 * mismo espíritu que `PermissionEmpresaRecordatorioCobroSeeder`.
 *
 * Es el permiso que gatea el módulo de Producción V2: la ruta `/produccionV2` de empresa-spa lo
 * exige con `can: 'produccion.index'` (src/router/routes.js), pero hasta esta tanda ningún seeder
 * lo creaba — así que no se le podía otorgar a ningún empleado y el módulo quedaba invisible
 * para todos menos el dueño.
 *
 * Existe además de la entrada en `PermissionSeeder` porque los dos seeders sirven a casos
 * distintos, como manda la regla del repo: `PermissionSeeder` usa `create()` y arma el
 * catálogo de una base nueva; este usa `firstOrCreate()` y es el que se corre suelto sobre
 * una base de producción que ya existe, sin duplicar filas:
 *   php artisan db:seed --class=PermissionProduccionIndexSeeder
 *
 * 🔴 POR ESO MISMO NO ESTÁ REGISTRADO EN `DatabaseSeeder`, y no es un olvido: no lo agregues.
 * `permission_empresas.slug` no tiene índice único y ningún seeder trunca la tabla, así que si
 * este corriera además de `PermissionSeeder` —que ya siembra `produccion.index`— toda base
 * nueva nacería con el permiso DUPLICADO y el empleado lo vería dos veces en pantalla. Mismo
 * criterio documentado en `PermissionEmpresaRecordatorioCobroSeeder`.
 */
class PermissionProduccionIndexSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Permiso para entrar al módulo de Producción V2 (/produccionV2 en la SPA).
        PermissionEmpresa::firstOrCreate(
            ['slug' => 'produccion.index'],
            [
                'name' => 'Usar modulo de Produccion',
                'model_name' => 'Produccion',
            ]
        );
    }
}
