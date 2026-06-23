<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Catálogo de extensión: permitir crear artículos al buscar en VENDER si no existen.
 * Idempotente: no duplica filas si el slug ya existe.
 */
class ExtencionCrearArticulosDesdeVenderSeeder extends Seeder
{
    /**
     * Inserta la extensión (mismo slug que hasExtencion('crear_articulos_desde_vender') en la SPA).
     *
     * @return void
     */
    public function run()
    {
        /** Clave estable compartida con UserHelper::hasExtencion y con el front. */
        $slug = 'crear_articulos_desde_vender';

        /** Nombre mostrado al asignar la extensión al comercio. */
        $name = 'Crear artículos desde vender';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
