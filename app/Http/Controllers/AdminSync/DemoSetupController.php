<?php

namespace App\Http\Controllers\AdminSync;

use App\Exceptions\BaseConDatosException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\DemoSetupLockHelper;
use App\Http\Controllers\Helpers\SetupErrorHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint llamado por admin-api cuando desde el panel de Leads se pulsa
 * "Disparar setup demo" (sin autenticación por API key en esta ruta).
 *
 * Replica el mismo comportamiento del form legacy en /demo/setup pero
 * entregando el input como JSON y respondiendo status JSON para que el
 * admin-api pueda reflejar éxito/error en el Lead.
 *
 * Prueba gratuita de 7 días (C1, misión prueba-experiencia-empresa, 3/11/2026): tres campos opcionales
 * más en el payload, `password` (string de 8 o más: queda como contraseña del dueño), `prueba`
 * (`{dias, vence_at, video_url, whatsapp_soporte}`: fija `users.expired_at` y prende el modo prueba) y
 * el rubro, que sigue llamándose `demo_industria`. Un `password` corto o un `prueba` que no es objeto
 * contesta 422 ANTES del `migrate:fresh`. La respuesta no cambia.
 */
class DemoSetupController extends Controller
{
    /**
     * Ejecuta DemoSetupHelper::run con el payload recibido.
     *
     * Requiere como mínimo `business_type`; el resto de campos son opcionales
     * y se interpretan como flags o datos complementarios.
     *
     * El payload se pasa entero con $request->all(), así que las claves nuevas de la
     * misión 50 (`demo_eventos_token`, `demo_eventos_url`, `demo_plan`,
     * `demo_media_urls`) llegan al helper sin necesitar ninguna lista acá. Se dejan
     * nombradas igual porque este docblock es lo único que documenta el contrato del
     * endpoint: las cuatro son opcionales y las persiste DemoSetupHelper::run() al final.
     *
     * Credenciales de prueba de las demos (misión credenciales-demo-zipnova-mp, 14/10/2026): cinco
     * campos opcionales más, planos, que admin-api manda solo si Lucas los cargó en Configuración →
     * Implementación: `zipnova_api_token` + `zipnova_api_secret` (y `zipnova_account_id`, opcional),
     * `mercadopago_access_token` + `mercadopago_public_key`. DemoSetupHelper::run() conecta Zipnova
     * y Mercado Pago de la demo con cada par SOLO si viaja completo, y le gana a la conexión
     * restaurada y al .env (ZIPNOVA_DEMO_* / MERCADOPAGO_DEMO_*, que quedan de respaldo). Sin esos
     * campos el comportamiento es el de siempre. Son secretos: este controlador no los guarda ni los
     * loguea (el 500 pasa por SetupErrorHelper, que los tacha), y el endpoint de clientes reales
     * (`UserSetupController`) no los lee.
     *
     * El 409 es una respuesta NUEVA de este endpoint (25/8/2026) y es compatible hacia
     * atrás: un admin-api viejo lo lee como `!successful()` y marca el Lead como fallido
     * con el mensaje, que es exactamente lo que queremos — lo importante es que la base
     * NO se toca. 200 y 500 quedan igual que siempre, mismo body.
     *
     * @param Request $request
     */
    public function store(Request $request)
    {
        // business_type es el único valor obligatorio del flujo original
        // if (empty($request->input('business_type'))) {
        //     return response()->json(['error' => 'business_type is required'], 422);
        // }

        /**
         * El candado va ANTES de cualquier cosa que toque la base, porque lo primero que
         * hace run() es `migrate:fresh`: si dejamos entrar una segunda corrida, le vacía
         * la base a la primera a mitad de siembra. Ver DemoSetupLockHelper para el porqué
         * del archivo (y por qué no Cache::lock).
         */
        $candado = DemoSetupLockHelper::tomar();

        if ($candado === false) {
            return response()->json([
                'error' => 'Ya hay un demo setup corriendo en esta instancia. Esperá a que termine.',
                'en_curso' => true,
            ], 409);
        }

        try {
            $datos = $request->all();

            /*
             * Prueba gratuita (misión prueba-experiencia-empresa, 3/11/2026): si el POST trae la
             * X-Admin-Api-Key de esta instancia, run() puede rearmar una instancia de prueba que ya
             * tiene datos; sin ella, con datos, se niega (409). Se escribe SIEMPRE desde el header y se
             * pisa lo que venga en el body: un `con_clave_de_admin: true` en el JSON no es una clave.
             */
            $datos['con_clave_de_admin'] = $this->trae_la_clave_de_la_instancia($request);

            $user = DemoSetupHelper::run($datos);
        } catch (BaseConDatosException $e) {
            /*
             * La guarda de instancia de DemoSetupHelper::run() (10/10/2026) se negó ANTES del
             * `migrate:fresh`: esta no es una instancia de demo y su base tiene datos. No se tocó
             * nada. Mismo cuerpo que el 409 de admin-sync/user-setup, y por los mismos motivos: va
             * antes del catch (\Throwable) porque un rechazo esperado no es un error de servidor;
             * no lleva el nombre de la base ni conteos porque esta ruta es pública; y `en_curso:
             * false` lo distingue del 409 del candado. Un admin-api viejo lo lee como
             * `!successful()` y marca el lead como fallido con el mensaje.
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
             * leads.demo_setup_last_error del admin. Ver SetupErrorHelper.
             */
            $datos   = $request->all();
            $mensaje = SetupErrorHelper::sin_secretos($e->getMessage(), $datos);

            Log::error('AdminSync demo-setup: ' . $mensaje, [
                'trace' => SetupErrorHelper::sin_secretos($e->getTraceAsString(), $datos),
            ]);

            return response()->json(['error' => 'internal error: ' . $mensaje], 500);
        } finally {
            DemoSetupLockHelper::soltar($candado);
        }

        return response()->json([
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'company_name' => $user->company_name,
            ],
        ], 200);
    }

    /**
     * ¿El POST trae la clave de admin de esta instancia (`ADMIN_API_INBOUND_KEY`)? Mismo criterio que el
     * trait ClaveEstrictaDeAdmin: sin clave cargada o sin header, NO (nunca es pase libre).
     *
     * @param Request $request
     * @return bool
     */
    private function trae_la_clave_de_la_instancia(Request $request)
    {
        $recibida = (string) $request->header('X-Admin-Api-Key');
        $esperada = (string) config('services.admin_api.api_key');

        if ($esperada === '' || $recibida === '') {
            return false;
        }

        return hash_equals($esperada, $recibida);
    }
}
