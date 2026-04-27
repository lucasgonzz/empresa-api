<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionSupportSeeOwnerChatsSeeder extends Seeder
{
    /**
     * Crea permiso para visualizar chats del dueño.
     */
    public function run()
    {
        // Datos del permiso nuevo para el módulo Soporte.
        $permission_data = [
            'name' => 'Ver chats del dueño',
            'model_name' => 'Soporte',
            'slug' => 'support.see_owner_chats',
        ];

        PermissionEmpresa::create($permission_data);
    }
}

