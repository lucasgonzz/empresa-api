<?php

namespace App\Http\Controllers\AdminSync;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\SetupErrorHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api cuando desde el panel de Leads se ejecuta
 * user setup.
 *
 * Equivale al POST del formulario legacy user/setup pero recibe JSON y
 * responde JSON para que admin-api registre el resultado en el Lead.
 *
 * Autenticación: la ruta lleva el middleware `admin.setup.key` (ClaveDeAdminEnSetup), que
 * exige el header X-Admin-Api-Key SOLO si ADMIN_SYNC_SETUP_REQUIRE_API_KEY (o la global
 * ADMIN_SYNC_REQUIRE_API_KEY) está en true. Hoy está apagada por defecto porque admin-api
 * todavía no manda ese header a esta ruta: exigirla ya mismo rompería el alta de clientes.
 * Mientras esté apagada, la ruta sigue siendo pública, y por eso lo que la protege de verdad
 * es la guarda de datos que vive dentro de UserSetupHelper::run() (ver BorradoTotalDeBaseHelper).
 */
class UserSetupController extends Controller
{
    /**
     * Ejecuta UserSetupHelper::run con el payload recibido.
     *
     * Requiere como mínimo `business_type` y `user_id`. El resto de campos
     * son opcionales y se interpretan como flags o datos complementarios.
     *
     * El 409 es una respuesta NUEVA de este endpoint (25/8/2026), compatible hacia atrás:
     * un admin-api viejo la lee como no exitosa y registra el error en el Lead, que es lo
     * correcto. Lo importante es que en ese caso la base NO se toca.
     *
     * Hay DOS 409 distintos, y se distinguen por el cuerpo (no por el código):
     * - `en_curso: true`        → ya hay otro setup corriendo (el candado).
     * - `base_con_datos: true`  → la base ya tiene datos de negocio y el payload no trae
     *   `forzar_borrado_total` + `confirmar_base_de_datos` (misión blindar-user-setup, 5/10/2026).
     *   Con `en_curso: false` y `con_datos` (qué tablas), sin el nombre de la base ni conteos.
     *
     * @param Request $request
     */
    public function store(Request $request)
    {
        // Precondiciones mínimas requeridas por UserSetupHelper::create_user
        // if (empty($request->input('business_type'))) {
        //     return response()->json(['error' => 'business_type is required'], 422);
        // }
        if (empty($request->input('user_id'))) {
            return response()->json(['error' => 'user_id is required'], 422);
        }

        /**
         * Tercera puerta al mismo `migrate:fresh`, mismo candado que las dos de demo (ver
         * DemoSetupLockHelper, que las lista por nombre). El caso realista no es "dos
         * user-setup a la vez": es la conversión de Lead a Cliente disparada mientras la demo
         * de ese mismo lead todavía está sembrando. Ahí el `migrate:fresh` de acá le vacía la
         * base a la corrida de demo y sale el mismo SQLSTATE[42S02] que motivó todo esto.
         */
        $candado = DemoSetupLockHelper::tomar();

        if ($candado === false) {
            return response()->json([
                'error' => 'Ya hay un setup corriendo en esta instancia. Esperá a que termine.',
                'en_curso' => true,
            ], 409);
        }

        try {
            $user = UserSetupHelper::run($request->all());
        } catch (BaseConDatosException $e) {
            /*
             * La guarda de UserSetupHelper::run() se negó ANTES del `migrate:fresh`: no se tocó nada.
             * Va ANTES del catch (\Throwable) a propósito: un rechazo esperado no es un error de
             * servidor y no tiene que salir como 500 `internal error` (admin-api lo guardaría en el
             * lead como una falla). El candado se suelta igual, por el `finally`.
             *
             * 🔴 El cuerpo NO lleva el nombre de la base ni cuántas filas tiene cada tabla: esta ruta
             * es pública hasta que se prenda la clave, y esa respuesta le llegaría a cualquiera que
             * le pegue. Solo se dice QUÉ familias de tablas tienen datos. El detalle con conteos ya
             * quedó en el log de la instancia (lo escribe el helper). `en_curso: false` es para
             * que un admin-api que lee ese campo no confunda este 409 con el del candado.
             */
            return response()->json([
                'error'          => $e->getMessage(),
                'base_con_datos' => true,
                'en_curso'       => false,
                'con_datos'      => $e->con_datos(),
            ], 409);
        } catch (\Throwable $e) {
            /*
             * 🔴 Sin secretos ANTES de loguear y de responder (misión serper-en-user-setup, revisión
             * independiente del 28/9/2026). Un QueryException de Laravel 8 trae el SQL del INSERT del
             * dueño con los valores interpolados —serper_api_key y google_custom_search_api_key
             * incluidas— y este texto va al log de la instancia y, por la respuesta, a
             * leads.user_setup_last_error del admin. Ver SetupErrorHelper.
             */
            $datos   = $request->all();
            $mensaje = SetupErrorHelper::sin_secretos($e->getMessage(), $datos);

            Log::error('AdminSync user-setup: ' . $mensaje, [
                'trace' => SetupErrorHelper::sin_secretos($e->getTraceAsString(), $datos),
            ]);

            return response()->json(['error' => 'internal error: ' . $mensaje], 500);
        } finally {
            DemoSetupLockHelper::soltar($candado);
        }

        return response()->json([
            'ok' => true,
            'user' => [
                'id'           => $user->id,
                'name'         => $user->name,
                'company_name' => $user->company_name,
            ],
        ], 200);
    }
}
