<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\IaAppHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Lo que el admin necesita de este cliente para la app «ComercioCity IA» (misión app-ia-y-memoria,
 * 29/10/2026, contratos C1.1 y C1.2 del plan).
 *
 *   POST admin-sync/ia-app/token  → valida usuario y contraseña del dueño y emite el token de la app
 *   GET  admin-sync/ia-app/dueno  → el documento y el nombre del dueño, para el directorio del admin
 *
 * El admin es el que atiende el login de la app (POST api/ia/login, C4): resuelve a qué cliente
 * pertenece el usuario y le pega acá. La app nunca le habla a este endpoint.
 *
 * 🔴 LA CLAVE SE EXIGE SIEMPRE (trait ClaveEstrictaDeAdmin): esta ruta EMITE un token que abre el chat
 * del dueño, y "no hay clave cargada" no puede ser un pase libre aunque ADMIN_SYNC_REQUIRE_API_KEY esté
 * apagado. Y el usuario sale SOLO del dueño de este frente: sin dueño resoluble (base compartida sin
 * USER_ID) es un 409 `dueno_no_resoluble` y no se busca a nadie (ver IaAppHelper::persona_por_credenciales()).
 *
 * 🔴 Y NO TOCA EL CANDADO DE SESIÓN ÚNICA. Nada de `Auth::attempt`, de `loginLucas()` ni de
 * `session_id`/`last_activity`: emitir el token no le cierra la sesión al dueño en la caja.
 */
class IaAppController extends Controller
{
    use ClaveEstrictaDeAdmin;

    /**
     * POST admin-sync/ia-app/token
     *
     * Body: `{doc_number (req, ≤64), password (req, ≤255), dispositivo (opc, ≤100)}`.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  200 · 401 clave_invalida | credenciales_invalidas · 403 sin_extension | no_es_dueno · 409 dueno_no_resoluble · 422
     */
    public function token(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_de_la_clave($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        /*
         * El dueño de ESTE frente, con el mismo criterio que el resto de los admin-sync del asistente
         * (USER_ID o el único dueño de la base). Si no se puede decidir, no se busca a nadie: en una base
         * compartida, cualquier otra cosa puede darle el token a un usuario de otro negocio.
         */
        $dueno_del_frente = AsistenteCanalHelper::dueno();

        if (is_null($dueno_del_frente)) {

            return response()->json([
                'code'    => 'dueno_no_resoluble',
                'message' => 'No se pudo resolver el dueño de esta instancia. '
                           . 'Si la base la comparten varios comercios, falta USER_ID en el .env de este frente.',
            ], 409, [], JSON_UNESCAPED_UNICODE);
        }

        $validador = Validator::make($request->all(), [
            'doc_number'  => 'required|string|max:64',
            'password'    => 'required|string|max:255',
            'dispositivo' => 'nullable|string|max:' . IaAppHelper::MAX_DISPOSITIVO,
        ]);

        if ($validador->fails()) {

            return response()->json([
                'code'    => 'validacion',
                'message' => 'Faltan el usuario o la contraseña.',
                'errors'  => $validador->errors(),
            ], 422, [], JSON_UNESCAPED_UNICODE);
        }

        $persona = IaAppHelper::persona_por_credenciales($dueno_del_frente, $request->input('doc_number'), $request->input('password'));

        if (is_null($persona)) {

            return response()->json([
                'code'    => 'credenciales_invalidas',
                'message' => 'El usuario o la contraseña no son correctos.',
            ], 401, [], JSON_UNESCAPED_UNICODE);
        }

        $dueno = IaAppHelper::dueno_de($persona);

        if (!AsistenteCanalHelper::tiene_extension($dueno)) {

            return response()->json([
                'code'    => 'sin_extension',
                'message' => 'Este sistema no tiene los Agentes de IA habilitados.',
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        if (!IaAppHelper::puede_usar_el_asistente($persona)) {

            return response()->json([
                'code'    => 'no_es_dueno',
                'message' => 'La app de IA es solo para el dueño del negocio.',
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        $token = IaAppHelper::emitir_token($persona, $request->input('dispositivo'));

        return response()->json([
            'token'       => $token,
            'usuario'     => IaAppHelper::usuario($persona),
            'negocio'     => IaAppHelper::negocio($dueno),
            'capacidades' => IaAppHelper::capacidades(),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * GET admin-sync/ia-app/dueno
     *
     * El documento del dueño de esta instancia (el admin lo guarda hasheado en su directorio para saber
     * a qué cliente mandar un login que llega sin negocio), su nombre, el del negocio y si tiene la
     * extensión. Sin dueño resoluble (base compartida sin USER_ID) va todo en null: el admin no lo suma
     * al directorio y el login de ese cliente pide el negocio.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse  200 · 401
     */
    public function dueno(Request $request): JsonResponse
    {
        $rechazo = $this->rechazo_de_la_clave($request);

        if (!is_null($rechazo)) {

            return $rechazo;
        }

        $dueno = AsistenteCanalHelper::dueno();

        if (is_null($dueno)) {

            return response()->json([
                'doc_number'       => null,
                'nombre'           => null,
                'negocio'          => null,
                'extension_activa' => false,
            ], 200);
        }

        $doc_number = trim((string) $dueno->doc_number);
        $nombre     = trim((string) $dueno->name);
        $negocio    = trim((string) $dueno->company_name);

        return response()->json([
            'doc_number'       => $doc_number === '' ? null : $doc_number,
            'nombre'           => $nombre === '' ? null : $nombre,
            'negocio'          => $negocio === '' ? null : $negocio,
            'extension_activa' => AsistenteCanalHelper::tiene_extension($dueno),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * 401 si la clave no vale; null si puede seguir. Es la MISMA comprobación del trait
     * (clave_valida(): sin clave de este lado o sin header nunca pasa) con el `code` que pide la
     * convención del plan, para que el admin distinga "la clave del cliente está mal" de "las
     * credenciales del usuario están mal" (los dos son 401). Mantiene `error: unauthorized`, que es lo
     * que el resto de los admin-sync estrictos contestan.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse|null
     */
    protected function rechazo_de_la_clave(Request $request)
    {
        if ($this->clave_valida($request)) {

            return null;
        }

        return response()->json([
            'error'   => 'unauthorized',
            'code'    => 'clave_invalida',
            'message' => 'Clave de admin inválida.',
        ], 401, [], JSON_UNESCAPED_UNICODE);
    }
}
