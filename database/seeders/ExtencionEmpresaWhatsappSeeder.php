<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "WhatsApp" en la tabla extenciones_empresa: habilita
 * el módulo de chats de WhatsApp con clientes (grupo 137), distinto de `whatsapp_ia`
 * (que solo habilita la generación de embeddings del catálogo para el bot).
 *
 * Idempotente (`firstOrCreate`): se llama desde el seeder general (`common_seeders()` en
 * `DatabaseSeeder`) y también puede correrse de forma standalone en bases de producción
 * existentes sin duplicar la fila:
 *   php artisan db:seed --class=ExtencionEmpresaWhatsappSeeder
 */
class ExtencionEmpresaWhatsappSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'whatsapp'],
            ['name' => 'WhatsApp']
        );
    }
}
