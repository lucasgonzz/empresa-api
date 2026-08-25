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
     * ⚠️ `doc_number` pasa a ser requerido y la vista `demo.setup` TODAVÍA NO TIENE ESE INPUT,
     * así que este form no puede completarse hasta que se lo agreguen. No es una regresión:
     * antes tampoco funcionaba —moría con `Undefined index: doc_number` adentro de
     * `create_demo_user()`—, solo que lo hacía DESPUÉS del `migrate:fresh`, dejando la base
     * vaciada. Ahora rebota antes de tocar nada.
     */
    public function setup(Request $request)
    {
        /**
         * La validación va antes del candado y antes del `migrate:fresh`: un payload
         * incompleto no puede vaciar una base. `doc_number` es el usuario del login y la
         * contraseña inicial de la demo, así que no lleva default (un default lo volvería
         * adivinable); se exige.
         */
        $request->validate([
            'business_type' => 'required|string',
            'doc_number' => 'required|string',
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
