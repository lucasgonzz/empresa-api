<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
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
     *
     * Toma el mismo candado que los endpoints de admin-sync: esta es la segunda de las tres
     * puertas al mismo `migrate:fresh` (ver DemoSetupLockHelper), y cerrar una sola dejaría
     * la carrera intacta — el técnico manda el form mientras el admin dispara el setup, y el
     * `migrate:fresh` de uno le vacía la base al otro. El mensaje sale por session('status'),
     * que es lo único que renderiza la vista.
     *
     * ⚠️ La vista `demo.setup` no tiene input de `doc_number`, así que por este camino la demo
     * queda siempre con `doc_number` null: una cuenta a la que no se puede entrar. No se valida
     * como requerido acá —sería romper el contrato con admin-api, que manda `doc_number` vacío
     * a propósito—; la cuenta inservible se arregla agregando el input a la vista, no rebotando
     * el request. Lo que sí está cubierto es que esa cuenta no quede ADIVINABLE: ver
     * `DemoSetupHelper::password_inicial()`.
     */
    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits' => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);

        $candado = DemoSetupLockHelper::tomar();

        if ($candado === false) {
            return redirect()->route('demo.form')
                ->with('status', 'Ya hay un demo setup corriendo en esta instancia. Esperá a que termine.');
        }

        try {
            // Pasamos el input crudo al helper; internamente interpreta cada flag
            DemoSetupHelper::run($request->all());
        } finally {
            DemoSetupLockHelper::soltar($candado);
        }

        return redirect()->route('demo.form')->with('status', 'Demo creada correctamente.');
    }
}
