<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use Illuminate\Http\Request;

/**
 * Formulario web de setup de demo. La lógica de creación vive en
 * App\Http\Controllers\Helpers\DemoSetupHelper para ser reutilizada por el
 * endpoint admin-sync/demo-setup que dispara admin-api al dar de alta un Lead.
 */
class DemoSetupController extends Controller
{
    /**
     * Muestra el formulario original que usa el técnico manualmente.
     */
    public function form()
    {
        return view('demo.setup');
    }

    /**
     * Recibe el POST del formulario, valida los mínimos indispensables y
     * delega la ejecución al helper.
     */
    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits' => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);

        // Pasamos el input crudo al helper; internamente interpreta cada flag
        DemoSetupHelper::run($request->all());

        return redirect()->route('demo.form')->with('status', 'Demo creada correctamente.');
    }
}
