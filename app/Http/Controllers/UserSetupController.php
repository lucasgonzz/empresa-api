<?php

namespace App\Http\Controllers;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Helpers\BorradoTotalDeBaseHelper;
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
     * y acá se vuelve al formulario con el motivo. Este formulario NO tiene forma de forzar el
     * borrado total, y no por omisión sino a propósito: el run() recibe el request SIN
     * `forzar_borrado_total` ni `confirmar_base_de_datos`, así que aunque alguien los mande a
     * mano en el POST se ignoran. El que de verdad necesite re-correr el setup sobre una base
     * con datos lo hace por admin-sync/user-setup, con esos dos campos.
     */
    public function setup(Request $request)
    {
        $request->validate([
            'business_type' => 'required|string',
            'use_deposits'  => 'nullable|boolean',
            'use_price_lists' => 'nullable|boolean',
        ]);

        /*
         * 🔴 Los dos campos que autorizan el borrado total se SACAN del request antes de llegar a
         * run(). Esta ruta es pública (el formulario es un GET abierto y el token CSRF lo saca
         * cualquiera): si se le pasara `$request->all()` tal cual, un POST con el flag y el nombre
         * de la base —que suele coincidir con el subdominio del cliente— vaciaría una base de
         * producción, que es justo lo que la guarda existe para impedir. Confirmado en vivo en la
         * verificación de la misión blindar-user-setup. Si te tienta volver a `$request->all()`
         * "porque es lo mismo que hace la API", no lo es: la API es la puerta que sí puede forzar.
         */
        $datos = $request->except([BorradoTotalDeBaseHelper::FLAG, BorradoTotalDeBaseHelper::CONFIRMACION]);

        try {
            UserSetupHelper::run($datos);
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
