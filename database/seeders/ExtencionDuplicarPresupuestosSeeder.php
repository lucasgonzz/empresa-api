<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Registra la extensión de negocio para mostrar y permitir duplicar presupuestos desde el listado.
 * Idempotente: no duplica filas si el slug ya existe.
 */
class ExtencionDuplicarPresupuestosSeeder extends Seeder
{
    /**
     * Inserta la extensión en el catálogo (mismo slug que `hasExtencion('duplicar_presupuestos')` en la SPA).
     *
     * @return void
     */
    public function run()
    {
        /** Clave estable compartida con UserHelper::hasExtencion y con el front. */
        $slug = 'duplicar_presupuestos';

        /** Nombre mostrado al asignar la extensión al comercio. */
        $name = 'Duplicar presupuestos';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
