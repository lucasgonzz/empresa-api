<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Catálogo de extensión: incluir códigos de barra como criterio en el buscador por nombre de VENDER.
 * Idempotente: no duplica filas si el slug ya existe.
 */
class ExtencionBarCodeEnVenderSeeder extends Seeder
{
    /**
     * Inserta la extensión (mismo slug que hasExtencion('search_bar_code_en_vender') en VenderController).
     *
     * @return void
     */
    public function run()
    {
        /** Clave estable compartida con UserHelper::hasExtencion y el backend. */
        $slug = 'search_bar_code_en_vender';

        /** Nombre mostrado al asignar la extensión al comercio. */
        $name = 'Buscar por codigo de barra en VENDER';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
