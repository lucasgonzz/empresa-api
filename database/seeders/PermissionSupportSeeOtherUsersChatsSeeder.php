<?php

namespace Database\Seeders;

use App\Models\PermissionEmpresa;
use Illuminate\Database\Seeder;

class PermissionSupportSeeOtherUsersChatsSeeder extends Seeder
{
    /**
     * Crea permiso para visualizar chats de otros usuarios.
     */
    public function run()
    {
        // Datos del permiso nuevo para el módulo Soporte.
        $permission_data = [
            'name' => 'Ver chats de otros usuarios',
            'model_name' => 'Soporte',
            'slug' => 'support.see_other_users_chats',
        ];

        PermissionEmpresa::create($permission_data);
    }
}

