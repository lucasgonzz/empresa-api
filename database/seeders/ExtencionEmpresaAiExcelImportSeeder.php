<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "Importación Excel con IA" en la tabla extenciones_empresa.
 *
 * Esta extensión habilita el flujo asistido por Claude para analizar y mapear
 * columnas de un Excel antes de lanzar la importación de artículos.
 */
class ExtencionEmpresaAiExcelImportSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        /*
         * Usamos firstOrCreate para que sea idempotente: si el slug ya existe
         * no genera un duplicado al ejecutar el seeder más de una vez.
         */
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'ai_excel_import'],
            ['name' => 'Importación Excel con IA']
        );
    }
}
