<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

/**
 * Registra los permisos de empleado asociados al módulo de WhatsApp (grupo 137), en el
 * mismo espíritu que los permisos ya existentes del Chat de soporte
 * (`support.see_owner_chats` / `support.see_other_users_chats`): por defecto un empleado
 * ve solo los chats que le corresponden; estos permisos habilitan ver más.
 *
 * Idempotente (`firstOrCreate`): se llama desde el seeder general (`common_seeders()` en
 * `DatabaseSeeder`) y también puede correrse de forma standalone en bases de producción
 * existentes sin duplicar filas:
 *   php artisan db:seed --class=PermissionEmpresaWhatsappSeeder
 */
class PermissionEmpresaWhatsappSeeder extends Seeder
{
    /**
     * Ejecuta el seeder.
     *
     * @return void
     */
    public function run()
    {
        // Permiso para ver los chats de WhatsApp del dueño (además de los propios).
        PermissionEmpresa::firstOrCreate(
            ['slug' => 'whatsapp.see_owner_chats'],
            [
                'name' => 'Ver chats de WhatsApp del dueño',
                'model_name' => 'WhatsApp',
            ]
        );

        // Permiso para ver los chats de WhatsApp de otros empleados (además de los propios).
        PermissionEmpresa::firstOrCreate(
            ['slug' => 'whatsapp.see_other_users_chats'],
            [
                'name' => 'Ver chats de WhatsApp de otros usuarios',
                'model_name' => 'WhatsApp',
            ]
        );
    }
}
