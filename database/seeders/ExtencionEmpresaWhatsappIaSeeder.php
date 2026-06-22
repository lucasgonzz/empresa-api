<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Seeder que registra la extensión "WhatsApp con IA" en la tabla extenciones_empresa.
 *
 * Habilita la generación de embeddings vectoriales del catálogo de artículos
 * para que el bot de WhatsApp con IA pueda responder consultas semánticas
 * sobre el inventario del cliente.
 *
 * Ejecutar en producción para clientes que contraten esta funcionalidad:
 *   php artisan db:seed --class=ExtencionEmpresaWhatsappIaSeeder
 */
class ExtencionEmpresaWhatsappIaSeeder extends Seeder
{
    public function run()
    {
        ExtencionEmpresa::firstOrCreate(
            ['slug' => 'whatsapp_ia'],
            ['name' => 'WhatsApp con IA']
        );
    }
}
