<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\DemoIngresoTokenHelper;
use App\Models\User;
use App\Services\DemoEventoEmitter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Endpoint público de ingreso a una demo con la sesión ya iniciada.
 *
 * Recibe el token de ingreso emitido por admin-api (RunDemoSetupService) y,
 * si es válido, inicia la sesión normal del sistema para el usuario demo
 * (sin pasar por la pantalla de login). Análogo a
 * AuthController::login_from_version_session_token(), pero el token no es
 * de un solo uso: vale durante toda la ventana del turno de demo.
 */
class DemoIngresoController extends Controller
{
    /**
     * Valida el token de ingreso e inicia sesión para el usuario demo asociado.
     *
     * Sin validación por Form Request a propósito (se agrega en el prompt 03):
     * el único input es el token, y la respuesta ante cualquier motivo de
     * rechazo (inexistente, expirado o revocado) es siempre el mismo 401
     * genérico, para no filtrar información al cliente externo.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        /** Token en claro recibido desde el lead (acepta 't' o 'token'). */
        $plain_token = trim((string) $request->input('t', $request->input('token', '')));

        /** Registro de token vigente (hash coincide, no expiró, no fue revocado) o null. */
        $registro = DemoIngresoTokenHelper::resolver($plain_token);

        if (!$registro) {
            Log::info('Demo ingreso: token invalido, expirado o revocado.');
            return response()->json(['error' => 'token_invalido'], 401);
        }

        /** Usuario demo al que este token da acceso. */
        $user = User::find($registro->user_id);

        if (!$user) {
            Log::info('Demo ingreso: token vigente pero usuario asociado no existe (user_id: '.$registro->user_id.').');
            return response()->json(['error' => 'token_invalido'], 401);
        }

        Auth::login($user, false);

        /** Regenerar la sesión después del login es obligatorio contra fijación de sesión. */
        $request->session()->regenerate();

        /** Marcador que lee el middleware del prompt 03 para identificar sesiones de demo por token. */
        $request->session()->put('demo_ingreso_token_id', $registro->id);

        Log::info('Demo ingreso: sesion iniciada para user_id: '.$user->id);

        /**
         * Primer evento del roadmap del lead (mision 50). Va DESPUES de poner el marcador
         * en la sesion, no antes: la primera guarda del emisor es justamente ese marcador,
         * y emitir antes lo haria salir sin registrar nada.
         *
         * Del lado del admin este evento es el que cierra en `completo` el hito de ingreso,
         * que pulsar el boton habia dejado en `parcial`.
         */
        DemoEventoEmitter::emitir('demo.ingreso', null, ['user_id' => $user->id]);

        return response()->json(['ok' => true], 200);
    }
}
