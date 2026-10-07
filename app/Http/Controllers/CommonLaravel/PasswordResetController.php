<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class PasswordResetController extends Controller
{

    function sendVerificationCode(Request $request) {
        $user = User::where('email', $request->email)
                    ->first();
        if (is_null($user)) {
            return response()->json(['email_send' => false], 200);
        }
        $code = rand(100000, 999999);
        $user->verification_code = $code;
        $user->save();

        try {
            Mail::to($user)->send(new PasswordReset($code));

            // 🔴 Que send() no haya tirado NO quiere decir que el mail haya salido: si el servidor SMTP rechaza la casilla del usuario (un 550 en el RCPT TO),
            // SwiftMailer no tira nada y el código nunca llega. Sin esta línea el usuario ve "enviado", el SPA lo manda a "ingresá el código" y se queda
            // esperando un mail que no va a llegar. No se reemplaza por method_exists(Mail::getFacadeRoot(), 'failures'): da siempre false.
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
        } catch (MailRechazadoPorElServidorException $e) {
            // 🔴 422 CON FORMA DE VALIDACIÓN DE LARAVEL (`message` + `errors`), y no un 200 `email_send: false` como el del "correo que no corresponde a un usuario".
            // Medido en `empresa-spa`: el `.catch` de `Step1.vue` solo hace console.log, pero el interceptor global de axios (`main.js`:
            // `global_api_error_interceptor` → `show_laravel_validation_toast`, presente desde la v1.1.1 del 26/3/2026) convierte un 422 con un mapa `errors` en
            // un toast de 14 segundos con el motivo, SIN mirar si hay sesión. El SPA de hoy muestra entonces el
            // motivo verdadero y NO avanza al paso del código (el `.then` no corre). Con un 200 `email_send: false` mostraría "El correo no corresponde a un usuario
            // registrado", que es FALSO: el usuario existe, el que rechazó fue el buzón. Un 401/403/500 sí lo dejaría mudo: solo sirve el 422 con `errors`.
            //
            // `email_send: false` y `mail_rechazado: true` se mantienen en el cuerpo: son la forma de error que este endpoint ya tenía y la marca para quien
            // quiera distinguir este caso. El motivo no lleva la casilla (la acaba de tipear quien lo lee).
            return response()->json([
                'email_send'     => false,
                'mail_rechazado' => true,
                'message'        => 'No se pudo enviar el código de recuperación',
                'errors'         => [
                    'email' => [ucfirst($e->getMessage()).'. Probá de nuevo más tarde o pedile a un administrador que revise tu correo.'],
                ],
            ], 422);
        }

        return response()->json(['email_send' => true], 200);
    }

    function checkVerificationCode(Request $request) {
        $user = User::where('email', $request->email)
                        ->first();
        if ($user->verification_code == $request->verification_code) {
            return response()->json(['verified' => true], 200);
        }
        return response()->json(['verified' => false], 200);
    }

    function updatePassword(Request $request) {
        $user = User::where('email', $request->email)
                        ->first();
        $user->password = bcrypt($request->password);
        $user->save();

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password], false)) {
            return response()->json(['password_updated' => true], 200);
        }
    }

}
