<?php

namespace App\Http\Controllers;

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
     */
    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits'  => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);

        UserSetupHelper::run($request->all());

        return redirect()->route('user.form')->with('status', 'Usuario creado correctamente.');
    }
}
