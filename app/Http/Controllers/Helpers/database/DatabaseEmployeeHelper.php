<?php

namespace App\Http\Controllers\Helpers\database;

use App\Http\Controllers\Helpers\DatabaseHelper;
use App\Models\User;

class DatabaseEmployeeHelper {

    static function copiar_employees($user, $bbdd_destino) {
        $employees = User::where('owner_id', $user->id)
                        ->with('permissions')
                        ->get();

        DatabaseHelper::set_user_conecction($bbdd_destino);

        foreach ($employees as $employee) {
            $created_employee = User::create([
                'id'                                              => $employee->id,
                'name'                                            => $employee->name,
                'visible_password'                                => $employee->visible_password,
                'admin_access'                                    => $employee->admin_access,
                'dias_alertar_empleados_ventas_no_cobradas'       => $employee->dias_alertar_empleados_ventas_no_cobradas,
                'ver_alertas_de_todos_los_empleados'              => $employee->ver_alertas_de_todos_los_empleados,
                'password'                                        => $employee->password,
                'doc_number'                                      => $employee->doc_number,
                'owner_id'                                        => $user->id,
            ]);

            echo 'Se creo empleado '.$employee->id.' </br>';

            foreach ($employee->permissions as $permission) {
                $created_employee->permissions()->attach($permission->id);
                echo 'Se le agrego permiso '.$permission->name.' </br>';
            }

            echo '------ </br>';
        }
    }
}