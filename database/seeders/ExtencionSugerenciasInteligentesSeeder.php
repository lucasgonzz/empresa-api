<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Sugerencias inteligentes de stock" en la tabla
 * extencion_empresas.
 *
 * Esta extensión habilita, por empresa, la v2 del módulo de sugerencias de traslado
 * entre sucursales: depósitos de origen designables, objetivo máximo, priorización
 * por días hasta quiebre, generación automática periódica, resumen por IA y la vista
 * propia en la SPA. Con la extensión apagada, el flujo viejo de modales sigue tal cual.
 *
 * En producción se corre SOLO este seeder (nunca ExtencionSeeder, que hace create()
 * en un foreach y correrlo dos veces duplica todo el catálogo):
 *
 *   php artisan db:seed --class=Database\Seeders\ExtencionSugerenciasInteligentesSeeder
 */
class ExtencionSugerenciasInteligentesSeeder extends Seeder
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
            ['slug' => 'sugerencias_inteligentes'],
            ['name' => 'Sugerencias inteligentes de stock']
        );
    }
}
