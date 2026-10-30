<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\AccesoSoporteHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint PÚBLICO que canjea un token de acceso de soporte por una sesión del dueño — misión
 * acceso-soporte-firmado (30/10/2026), contrato C2. Molde: `DemoIngresoController`.
 *
 *   POST api/acceso-soporte/canjear   body {t: "<token>"}  (acepta también `token`)      throttle 10/min
 *     200 {ok: true}  + cookie de sesión + sesión Laravel regenerada con `acceso_soporte_token_id`
 *     401 {error: token_invalido}
 *
 * Es pública porque quien la llama (el navegador del agente) todavía no tiene sesión: lo único que lo
 * autoriza es el token, que se emitió con la clave compartida del admin, vive 2 minutos y se usa UNA vez.
 *
 * 🔴 El 401 es SIEMPRE el mismo cuerpo: token vacío, mal formado, inexistente, vencido, ya usado, usuario
 * que ya no es dueño o acceso apagado en este cliente. Quien prueba tokens no aprende nada del motivo.
 *
 * Qué NO hace, a propósito (y por qué):
 *   - NO llama a `procesar_login()`: eso escribe `login_at` del dueño real. Entrar a mirar no es «que el
 *     dueño inició sesión».
 *   - NO llama a `checkUserLastActivity()`: eso toma el candado de sesión única del dueño (`session_id` /
 *     `last_activity`) y lo dejaría afuera de su propio sistema. La exención está en
 *     `AuthController::debe_omitir_candado_de_actividad()`, que reconoce el marcador de abajo.
 *   - NO pone la clave de sesión del login maestro: la sesión de soporte NO es `es_acceso_maestro` (mínimo
 *     privilegio: lo exclusivo del maestro, como «Buscar imágenes para todo el catálogo», sigue siendo suyo).
 *
 * La lógica de validar y consumir el token vive en `AccesoSoporteHelper::canjear()`.
 */
class AccesoSoporteController extends Controller
{
    /**
     * Valida el token, lo consume e inicia la sesión del dueño.
     *
     * @param  Request $request  Body `{t}` (o `{token}`).
     * @return \Illuminate\Http\JsonResponse 200 `{ok: true}` · 401 `{error: token_invalido}`
     */
    public function canjear(Request $request)
    {
        /**
         * Sin sesión posible no hay nada que canjear: Sanctum solo arranca la sesión cuando el pedido viene del
         * SPA (su Origin/Referer está entre los dominios stateful). Un pedido que no viene de ahí (un curl, un
         * script, otro sitio) no puede recibir una sesión, y NO tiene que quemar el token en el intento: se
         * contesta el mismo 401 SIN consumirlo, así el navegador legítimo que lo use después todavía puede.
         * (Sin esta guarda, `$request->session()` tiraría una excepción DESPUÉS de haber consumido el token.)
         */
        if (!$request->hasSession()) {
            Log::info('Acceso de soporte: canje rechazado, el pedido no trae sesion (ip: ' . $request->ip() . ').');

            return response()->json(['error' => 'token_invalido'], 401);
        }

        /**
         * Token en claro recibido. Solo se acepta un string: un array (`t[]=...`) no es un token, y castearlo
         * a string daría «Array» con un aviso de PHP.
         */
        $crudo = $request->input('t', $request->input('token'));
        $token = is_string($crudo) ? trim($crudo) : '';

        /** El dueño y el id de la fila, o null si el token no vale (por el motivo que sea). */
        $acceso = AccesoSoporteHelper::canjear($token, $request->ip(), $request->userAgent());

        if (!$acceso) {
            // Ni el token ni el motivo del rechazo van al log: solo desde dónde se intentó.
            Log::info('Acceso de soporte: canje rechazado (ip: ' . $request->ip() . ').');

            return response()->json(['error' => 'token_invalido'], 401);
        }

        Auth::login($acceso['user'], false);

        /** Regenerar la sesión después del login es obligatorio contra fijación de sesión. */
        $request->session()->regenerate();

        /**
         * `regenerate()` conserva los datos de la sesión anterior. Si ese navegador traía una sesión maestra o
         * de demo, esos marcadores se colarían en la de soporte: el maestro la volvería `es_acceso_maestro`
         * (más privilegio del que se quiere dar) y el de demo la volvería `es_sesion_demo`. Se borran
         * explícitamente; lo que queda marcado es solo «soporte».
         */
        $request->session()->forget('master_login_bypass_user_last_activity');
        $request->session()->forget('demo_ingreso_token_id');

        /** Marcador que lee `AuthController::es_sesion_de_soporte()`: exime del candado y blinda el logout. */
        $request->session()->put('acceso_soporte_token_id', $acceso['token_id']);

        /** Como el login maestro básico: no bajar todo el catálogo al IndexedDB del navegador del agente. */
        $request->session()->put('skip_offline_articles_sync', true);

        Log::info('Acceso de soporte: sesion iniciada (token_id: ' . $acceso['token_id']
            . ', user_id: ' . $acceso['user']->id . ', ip: ' . $request->ip() . ').');

        return response()->json(['ok' => true], 200);
    }
}
