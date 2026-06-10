<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Lista empleados (users con owner_id) para sincronización hacia admin-api.
 */
class EmployeesController extends Controller
{
    /**
     * Devuelve id, name y phone de los empleados del comercio (instancia empresa-api).
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        /** Empleados: usuarios hijos del dueño del comercio (owner_id no nulo). */
        $employees = User::query()
            ->whereNotNull('owner_id')
            ->orderBy('name', 'ASC')
            ->get(['id', 'name', 'phone']);

        /** Payload mínimo para admin-api (sin permisos ni contraseñas). */
        $models = [];
        foreach ($employees as $employee) {
            $models[] = [
                'id'    => (int) $employee->id,
                'name'  => trim((string) ($employee->name ?? '')),
                'phone' => trim((string) ($employee->phone ?? '')),
            ];
        }

        return response()->json(['models' => $models], 200);
    }
}
