<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\WhatsappBotConexionHelper;
use App\Models\WhatsappBotConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La puerta con la que el admin provisiona la conexión de WhatsApp (Kapso) de este cliente — misión
 * kapso-secretos-fuera-del-navegador (17/11/2026).
 *
 *   GET  api/admin-sync/whatsapp-bot/conexion → {ok, conexion}
 *   PUT  api/admin-sync/whatsapp-bot/conexion → {ok, conexion}
 *        body (todo opcional): phone_number_id, kapso_api_key, webhook_secret, is_active
 *
 * 🔴 Es el ÚNICO camino que escribe las credenciales: el dueño ya no las ve ni las puede cambiar desde su
 * navegador (`WhatsappBotController::update_config()` le contesta 403). Va con `admin.api.key:siempre`
 * (mismo molde que `agentes-ia` y `acceso-soporte`): exige `X-Admin-Api-Key` aunque la instancia no tenga
 * clave cargada, porque el PUT ESCRIBE secretos. Sin clave → 401, lo contesta el middleware antes de llegar acá.
 *
 * Un campo omitido o vacío NO se toca (nunca borra). La respuesta NUNCA incluye `kapso_api_key` ni
 * `webhook_secret`: solo si están cargados. El dueño sale de `AsistenteCanalHelper::dueno()`; sin dueño
 * resoluble → 409. Un cliente viejo sin esta ruta responde 404/405 y el admin lo muestra como «versión vieja».
 */
class WhatsappBotConexionController extends Controller
{
    /**
     * GET — el estado de la conexión, sin secretos.
     *
     * @return JsonResponse 200 `{ok, conexion}` · 409 sin dueño resoluble
     */
    public function show(): JsonResponse
    {
        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        $config = WhatsappBotConfig::where('user_id', $dueno->id)->first();

        return response()->json(['ok' => true, 'conexion' => WhatsappBotConexionHelper::estado($config)], 200);
    }

    /**
     * PUT — provisiona (alta o actualización parcial) la conexión del comercio.
     *
     * @param  Request  $request
     * @return JsonResponse 200 `{ok, conexion}` · 409 sin dueño · 422 validación o guard de coherencia
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number_id' => 'sometimes|nullable|string|max:191',
            'kapso_api_key'   => 'sometimes|nullable|string|max:191',
            'webhook_secret'  => 'sometimes|nullable|string|max:191',
            'is_active'       => 'sometimes|nullable|boolean',
        ]);

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return $this->sin_dueno();
        }

        // Solo entra lo que vino con valor: omitido, null o en blanco = no se toca (nunca se borra).
        $data = [];

        foreach (WhatsappBotConexionHelper::CAMPOS_TECNICOS as $campo_tecnico) {
            $valor = trim((string) $request->input($campo_tecnico));

            if ($valor !== '') {
                $data[$campo_tecnico] = $valor;
            }
        }

        if (! is_null($request->input('is_active'))) {
            $data['is_active'] = filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN);
        }

        $config = WhatsappBotConfig::where('user_id', $dueno->id)->first();

        $rechazo = WhatsappBotConexionHelper::error_de_activacion($config, $data);

        if (! is_null($rechazo)) {

            return response()->json(['message' => $rechazo], 422);
        }

        // Sin nada para escribir no se toca la base (y no se crea una fila vacía).
        if (! empty($data)) {
            $config = WhatsappBotConexionHelper::guardar((int) $dueno->id, $config, $data);
        }

        return response()->json(['ok' => true, 'conexion' => WhatsappBotConexionHelper::estado($config)], 200);
    }

    /**
     * La respuesta cuando no se puede decidir de qué comercio se habla.
     *
     * @return JsonResponse
     */
    protected function sin_dueno(): JsonResponse
    {
        return response()->json([
            'message' => 'No se pudo resolver el dueño de esta instancia. '
                       . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
        ], 409);
    }
}
