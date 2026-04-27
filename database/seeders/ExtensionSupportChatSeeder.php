<?php

namespace Database\Seeders;

use App\Models\ExtencionEmpresa;
use Illuminate\Database\Seeder;

class ExtensionSupportChatSeeder extends Seeder
{
    /**
     * Crea extensión para habilitar módulo de chat de soporte.
     */
    public function run()
    {
        // Definición de la extensión que habilita soporte en empresa-spa.
        $extension_data = [
            'name' => 'Chat de soporte',
            'slug' => 'support_chat',
        ];

        ExtencionEmpresa::create($extension_data);
    }
}

