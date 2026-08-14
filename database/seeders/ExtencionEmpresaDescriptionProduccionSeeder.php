<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Misión 54 — el seeder que se corre a mano en las bases de producción que ya existen.
 *
 *   php artisan db:seed --class=ExtencionEmpresaDescriptionProduccionSeeder
 *
 * Hace lo mismo que `ExtencionEmpresaDescriptionSeeder` —del que sale el padrón, así que hay un
 * solo lugar donde viven los textos— y agrega lo único que a una base de cliente le importa y a
 * una base nueva no: **decir en qué se diferencia ese catálogo del padrón**, en las dos
 * direcciones. Extensiones del padrón que la base no tiene, y extensiones de la base que el
 * padrón no describe (esas quedan con `description` en null, a propósito).
 *
 * 🔴 No agrega ni borra filas de `extencion_empresas`, y no toca `extencion_empresa_user`:
 * actualiza por `slug`. Las extensiones que cada cliente tiene asignadas quedan intactas, que es
 * la razón por la que este seeder se puede correr sobre una base con clientes adentro.
 */
class ExtencionEmpresaDescriptionProduccionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $seeder    = new ExtencionEmpresaDescriptionSeeder();
        $resultado = $seeder->aplicar();

        $sin_describir = ExtencionEmpresaDescriptionSeeder::slugs_sin_descripcion();

        $lineas = [
            'Extensiones actualizadas: ' . $resultado['actualizadas'] . ' de ' . count(ExtencionEmpresaDescriptionSeeder::padron()),
            'Del padron, no estan en esta base: ' . (count($resultado['faltantes']) === 0 ? 'ninguna' : implode(', ', $resultado['faltantes'])),
            'En esta base, sin descripcion en el padron: ' . (count($sin_describir) === 0 ? 'ninguna' : implode(', ', $sin_describir)),
        ];

        foreach ($lineas as $linea) {
            Log::info('ExtencionEmpresaDescriptionProduccionSeeder: ' . $linea);

            /*
             * `command` es null si alguien instancia el seeder a mano en vez de correrlo por
             * artisan (los tests lo hacen), así que no se asume que exista.
             */
            if (!is_null($this->command)) {
                $this->command->info($linea);
            }
        }
    }
}
