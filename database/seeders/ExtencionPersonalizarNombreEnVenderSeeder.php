<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Catálogo de extensión: personalizar el nombre del artículo en VENDER.
 * Idempotente: no duplica filas si el slug ya existe.
 *
 * Ejecutar en producción: php artisan db:seed --class=ExtencionPersonalizarNombreEnVenderSeeder
 *
 * Existe aparte de ExtencionSeeder porque ese hace ExtencionEmpresa::create() en un foreach, sin
 * firstOrCreate: correrlo contra una base con datos duplica el catálogo entero. Éste es el que se
 * corre en las bases de los clientes que ya existen, que son todas.
 */
class ExtencionPersonalizarNombreEnVenderSeeder extends Seeder
{
    /**
     * Inserta la extensión (mismo slug que hasExtencion('personalizar_nombre_en_vender') en la SPA).
     *
     * @return void
     */
    public function run()
    {
        /** Clave estable compartida con UserHelper::hasExtencion y con el front. */
        $slug = 'personalizar_nombre_en_vender';

        /** Nombre mostrado al asignar la extensión al comercio. */
        $name = 'Personalizar nombre del articulo en VENDER';

        ExtencionEmpresa::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name]
        );
    }
}
