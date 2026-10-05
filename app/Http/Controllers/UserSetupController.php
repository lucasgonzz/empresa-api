<?php

namespace App\Http\Controllers;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Helpers\UserSetupHelper;
use Illuminate\Http\Request;

/**
 * Formulario web de setup del sistema real de un cliente.
 * La lógica de creación vive en UserSetupHelper para ser reutilizada
 * por el endpoint admin-sync/user-setup que dispara admin-api al promover
 * un Lead a Cliente.
 */
class UserSetupController extends Controller
{
    /**
     * Muestra el formulario que usa el técnico manualmente.
     */
    public function form()
    {
        return view('user.setup');
    }

    /**
     * Recibe el POST del formulario, valida los mínimos y delega al helper.
     *
     * Si la base ya tiene datos de negocio, UserSetupHelper::run() se niega antes de borrar nada
     * y acá se vuelve al formulario con el motivo. Este formulario NO tiene (ni tiene que tener)
     * forma de forzar el borrado total: la ruta es pública y es la cuarta puerta al mismo
     * `migrate:fresh`; el que de verdad necesite re-correr el setup sobre una base con datos lo
     * hace por admin-sync/user-setup, con `forzar_borrado_total` + `confirmar_base_de_datos`.
     */
    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits'  => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);

        try {
            UserSetupHelper::run($request->all());
        } catch (BaseConDatosException $e) {
            // Mismo texto genérico que el 409 de la API: sin el nombre de la base ni conteos.
            return redirect()->route('user.form')->with(
                'error',
                $e->getMessage() . ' Tablas con datos: ' . implode(', ', $e->con_datos()) . '.'
            );
        }

        return redirect()->route('user.form')->with('status', 'Usuario creado correctamente.');
    }
}
