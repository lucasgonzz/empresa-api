<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Sugerencias de compra a proveedores" en
 * la tabla extencion_empresas.
 *
 * Esta extensión habilita, por empresa, el motor de sugerencia de compra: la
 * pantalla y el botón "Generar orden de compra" en la SPA, las rutas REST en
 * la API, el comando automático compras:generar y el módulo "IA" del menú
 * (junto con sugerencias_inteligentes, que gatea el hermano de stock). Con la
 * extensión apagada no aparecen ni el módulo ni las rutas (403) y nada del
 * resto del sistema cambia de comportamiento.
 *
 * Extensión propia, no reusar sugerencias_inteligentes: es funcionalidad
 * vendible aparte, mismo precedente que asistente_ia.
 *
 * En producción se corre SOLO este seeder (nunca ExtencionSeeder, que hace
 * create() en un foreach y correrlo dos veces duplica todo el catálogo):
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionSugerenciasComprasSeeder
 */
class ExtencionSugerenciasComprasSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * firstOrCreate para que sea idempotente: si el slug ya existe (por ejemplo
         * porque ExtencionSeeder ya lo sembró en una instancia nueva), no genera un
         * duplicado al ejecutar el seeder más de una vez.
         */
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'sugerencias_compras'],
            ['name' => 'Sugerencias de compra a proveedores']
        );
    }
}
