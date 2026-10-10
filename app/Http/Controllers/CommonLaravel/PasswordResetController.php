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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Recuperación de contraseña por código al mail (`POST /password-reset/*` de routes/web.php, públicas a
 * propósito: las usa alguien que no puede iniciar sesión).
 *
 * 🔴 HASTA EL 10/10/2026 (misión cerrar-rutas-web-publicas) `update-password` cambiaba la contraseña de
 * CUALQUIER usuario con solo su email, sin mirar el código, y lo dejaba logueado. Además
 * `check-verification-code` daba `verified: true` con `null == null` (un usuario que nunca pidió código y
 * un pedido sin código) y 500 con un email inexistente.
 *
 * Cómo funciona ahora (compatible con la SPA de hoy, que en el paso 3 ya manda `email` +
 * `verification_code` + `password`, en `Step3.vue`, con todo `register_user`):
 *   - `send-verification-code` genera un código de 6 dígitos con `random_int` y lo guarda en CACHÉ, como
 *     sha256 y con un contador de intentos, por 30 minutos. Cada pedido nuevo reemplaza al anterior.
 *   - `check-verification-code` lo compara SIN consumirlo (la SPA lo vuelve a mandar en el paso 3).
 *   - `update-password` exige el código: sin uno válido, 422 con forma de validación y no toca nada; con
 *     uno válido cambia la contraseña, BORRA el código (no se reusa) y loguea como siempre.
 *   - Cada código equivocado (en check o en update) suma un intento; al 5.º el código muere y hay que
 *     pedir otro. Con 6 dígitos, sin esto se probaban las 1.000.000 combinaciones sin límite.
 *
 * POR QUÉ EN CACHÉ Y NO EN `users.verification_code`: esa columna NO EXISTE en una instalación nueva (está
 * comentada en `2014_10_12_000000_create_users_table.php` desde 2023; solo `buyers` la tiene), así que en
 * cualquier base armada con las migraciones `send-verification-code` daba 500 antes de mandar el mail. La
 * columna, donde existe, ya no se lee ni se escribe.
 */
class PasswordResetController extends Controller
{
    /** Minutos que vive un código desde que se pide. */
    const MINUTOS_DE_VIDA_DEL_CODIGO = 30;

    /** Al llegar a esta cantidad de intentos fallidos, el código se invalida. */
    const INTENTOS_FALLIDOS_MAXIMOS = 5;

    function sendVerificationCode(Request $request) {
        $user = $this->usuario_del_pedido($request);
        if (is_null($user)) {
            return response()->json(['email_send' => false], 200);
        }
        // random_int y no rand(): es un secreto, tiene que salir de un generador criptográfico.
        $code = random_int(100000, 999999);
        $this->guardar_codigo($user, $code);

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
        $user = $this->usuario_del_pedido($request);

        // Email inexistente, sin código pendiente, vencido o equivocado: la misma respuesta de "código
        // incorrecto" que la SPA ya sabe mostrar (200 `verified: false`), nunca un 500.
        if (is_null($user) || !$this->codigo_valido($user, $request->verification_code)) {
            return response()->json(['verified' => false], 200);
        }

        // No se consume acá: la SPA vuelve a mandar el mismo código en el paso 3 (update-password).
        return response()->json(['verified' => true], 200);
    }

    function updatePassword(Request $request) {
        $user = $this->usuario_del_pedido($request);

        /*
         * Sin un código válido no se toca la contraseña ni se loguea a nadie. 422 con FORMA DE VALIDACIÓN
         * (`message` + `errors`): el interceptor global de axios de la SPA lo convierte en un toast con el
         * motivo, y el `.then` de Step3.vue no corre (no navega ni pide `auth/me`). La MISMA respuesta para
         * un email que no existe: si fuera distinta, este endpoint diría qué emails tienen cuenta.
         */
        if (is_null($user) || !$this->codigo_valido($user, $request->verification_code)) {
            return response()->json([
                'password_updated' => false,
                'message'          => 'No se pudo cambiar la contraseña',
                'errors'           => [
                    'verification_code' => ['El código de verificación no es válido o venció. Pedí uno nuevo y volvé a intentar.'],
                ],
            ], 422);
        }

        // Un código sirve una sola vez: se borra antes de cambiar nada.
        Cache::forget($this->clave_de_cache($user));

        $user->password = bcrypt($request->password);
        $user->save();

        if (Auth::attempt(['email' => $request->email, 'password' => $request->password], false)) {
            return response()->json(['password_updated' => true], 200);
        }
    }

    /**
     * El usuario del email del pedido, o null.
     *
     * Solo con un email de texto no vacío: con `email` ausente, `where('email', null)` se convierte en
     * `whereNull('email')` y devolvería cualquier usuario sin email (un empleado, por ejemplo).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|null
     */
    protected function usuario_del_pedido(Request $request)
    {
        $email = $request->input('email');

        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return User::where('email', $email)->first();
    }

    /**
     * Clave de caché del código pendiente de un usuario (uno por usuario: pedir otro pisa el anterior).
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    protected function clave_de_cache(User $user)
    {
        return 'password_reset:' . $user->id;
    }

    /**
     * Guarda el código pendiente: su sha256 (el código en claro no queda en ningún lado), los intentos
     * fallidos y cuándo vence. El vencimiento va también adentro del valor porque el contador se reescribe
     * con el tiempo que le queda, no con 30 minutos nuevos.
     *
     * @param  \App\Models\User  $user
     * @param  int  $codigo
     * @return void
     */
    protected function guardar_codigo(User $user, $codigo)
    {
        $segundos = self::MINUTOS_DE_VIDA_DEL_CODIGO * 60;

        Cache::put($this->clave_de_cache($user), [
            'codigo_sha256' => hash('sha256', (string) $codigo),
            'intentos'      => 0,
            'vence'         => now()->getTimestamp() + $segundos,
        ], $segundos);
    }

    /**
     * ¿El código recibido es el pendiente del usuario y sigue vigente? NO lo consume.
     *
     * Un código equivocado (o vacío) suma un intento; al llegar a INTENTOS_FALLIDOS_MAXIMOS el código se
     * borra y ya no sirve ni el correcto. Uno vencido se borra. Se compara con hash_equals.
     *
     * @param  \App\Models\User  $user
     * @param  mixed  $codigo  Lo que mandó el cliente (la SPA lo manda como texto).
     * @return bool
     */
    protected function codigo_valido(User $user, $codigo)
    {
        $clave = $this->clave_de_cache($user);
        $pendiente = Cache::get($clave);

        if (!is_array($pendiente) || !isset($pendiente['codigo_sha256'], $pendiente['intentos'], $pendiente['vence'])) {
            return false;
        }

        // now() y no time(): el reloj de Carbon es el que mueven los tests.
        $ahora = now()->getTimestamp();

        if ((int) $pendiente['vence'] <= $ahora) {
            Cache::forget($clave);

            return false;
        }

        $codigo = is_scalar($codigo) ? trim((string) $codigo) : '';

        if ($codigo !== '' && hash_equals((string) $pendiente['codigo_sha256'], hash('sha256', $codigo))) {
            return true;
        }

        $pendiente['intentos'] = (int) $pendiente['intentos'] + 1;

        if ($pendiente['intentos'] >= self::INTENTOS_FALLIDOS_MAXIMOS) {
            Cache::forget($clave);
        } else {
            Cache::put($clave, $pendiente, (int) $pendiente['vence'] - $ahora);
        }

        return false;
    }

}
