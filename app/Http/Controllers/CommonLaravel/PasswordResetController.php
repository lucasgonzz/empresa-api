<?php

namespace App\Http\Controllers\CommonLaravel;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\TwilioHelper;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
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
 *   - `send-verification-code` genera un código con `random_int` y lo guarda en CACHÉ, como sha256 y con
 *     un contador de intentos, por 30 minutos. Cada pedido nuevo reemplaza al anterior. El largo del código
 *     es OPT-IN del cliente: si el pedido trae `longitud_del_codigo = 4` el código es de 4 dígitos
 *     (1000–9999); sin ese campo (la SPA vieja) sigue siendo de 6 dígitos como siempre.
 *   - SOLO EL DUEÑO (`owner_id` null) puede recuperar su contraseña por acá (misión
 *     recuperar-clave-codigo-4-digitos, 20/10/2026): la de un empleado la cambia el dueño desde el módulo
 *     de empleados. Un correo de empleado en `send-verification-code` responde `email_send: false` +
 *     `es_empleado: true` + `message`, sin emitir código ni mandar mail; en `check-verification-code` y
 *     `update-password` un empleado es un usuario inexistente (las mismas respuestas genéricas).
 *   - `check-verification-code` lo compara SIN consumirlo (la SPA lo vuelve a mandar en el paso 3).
 *   - `update-password` exige el código: sin uno válido, 422 con forma de validación y no toca nada; con
 *     uno válido cambia la contraseña, BORRA el código (no se reusa) y loguea como siempre.
 *   - Cada código equivocado (en check o en update) suma un intento; al 5.º el código muere y hay que
 *     pedir otro. Con códigos de 6 dígitos (900.000 combinaciones) y, más todavía, con los de 4 (9.000),
 *     sin esto se probaban todas sin límite.
 *   - Y pedir otro tiene tope: CODIGOS_POR_HORA_MAXIMOS por usuario en una ventana fija de 60 minutos
 *     (ver puede_emitir_otro_codigo()). Sin ese tope, el de intentos no sirve de nada. Con 4 dígitos son
 *     5 códigos × 5 intentos = 25 intentos por hora contra 9.000 combinaciones: estos dos topes son lo
 *     ÚNICO que protege la cuenta, y NO se aflojan.
 *   - Los dos topes se leen y se escriben bajo un CANDADO por usuario (con_candado_del_usuario()): sin él,
 *     pedidos en paralelo leen el mismo contador y se prueban muchos más de 5 códigos por emisión.
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

    /**
     * Códigos que se le pueden emitir a un usuario por ventana de VENTANA_DE_PEDIDOS_EN_MINUTOS.
     *
     * 🔴 SIN ESTE TOPE, EL DE INTENTOS NO SIRVE: cada código nuevo arranca con 0 intentos, así que pidiendo
     * otro código cada 5 intentos fallidos se probaban combinaciones sin ningún límite (y cada pedido le
     * manda un mail al dueño de la cuenta). Con 5 códigos × 5 intentos son 25 intentos por hora contra
     * 900.000 combinaciones posibles; y de paso no se le puede llenar la casilla al dueño con pedidos.
     */
    const CODIGOS_POR_HORA_MAXIMOS = 5;

    /** Ventana FIJA (desde el primer pedido, no se corre con cada uno) del tope de códigos emitidos. */
    const VENTANA_DE_PEDIDOS_EN_MINUTOS = 60;

    /** Largo del código que se genera si el cliente NO lo pide (la SPA vieja, que nunca manda el campo). */
    const LONGITUD_DEL_CODIGO_POR_DEFECTO = 6;

    /** Único largo alternativo que el cliente puede pedir con `longitud_del_codigo` (la SPA nueva). */
    const LONGITUD_DEL_CODIGO_CORTO = 4;

    /** Segundos que vive el candado por usuario que serializa los pedidos y los intentos. */
    const SEGUNDOS_DEL_CANDADO = 10;

    /** Segundos que espera un pedido para conseguir el candado antes de rendirse. */
    const SEGUNDOS_DE_ESPERA_DEL_CANDADO = 5;

    /** Largo mínimo de la contraseña nueva (la SPA exige más de 6: lo que manda siempre pasa). */
    const MINIMO_DE_LA_CONTRASENA = 6;

    function sendVerificationCode(Request $request) {
        $user = $this->usuario_del_pedido($request);
        if (is_null($user)) {
            /*
             * Si el correo es de un EMPLEADO no hay código ni mail: la recuperación es solo del dueño. 200 con
             * `email_send: false` (la forma de siempre para "no se mandó nada": la SPA vieja muestra su toast
             * fijo y no avanza) + `es_empleado` + `message` (la SPA nueva muestra el motivo verdadero).
             */
            if ($this->el_correo_es_de_un_empleado($request)) {
                return response()->json([
                    'email_send'  => false,
                    'es_empleado' => true,
                    'message'     => 'Este correo es de un empleado. Solo el dueño de la cuenta puede recuperar la contraseña desde acá: pedile al dueño que te la cambie desde el módulo de empleados.',
                ], 200);
            }

            return response()->json(['email_send' => false], 200);
        }

        /*
         * Tope de códigos por hora (ver CODIGOS_POR_HORA_MAXIMOS) y emisión del código, TODO bajo el candado
         * del usuario: leer el contador, decidir y escribirlo no puede intercalarse con otro pedido. Pasado el
         * tope NO se genera código, NO se manda mail y NO se toca el código pendiente (el último que se mandó
         * sigue sirviendo). 422 con FORMA DE VALIDACIÓN (`message` + `errors`): el interceptor global de axios
         * de la SPA (`show_laravel_validation_toast`) lo muestra como toast con el motivo, igual que el mail
         * rechazado.
         */
        $code = $this->con_candado_del_usuario($user, function () use ($user, $request) {
            if (!$this->puede_emitir_otro_codigo($user)) {
                return null;
            }

            // random_int y no rand(): es un secreto, tiene que salir de un generador criptográfico.
            $nuevo_codigo = $this->generar_codigo($this->longitud_pedida($request));
            $this->guardar_codigo($user, $nuevo_codigo);
            $this->contar_codigo_emitido($user);

            return $nuevo_codigo;
        }, null);

        if (is_null($code)) {
            return response()->json([
                'email_send' => false,
                'message'    => 'Demasiados pedidos de código',
                'errors'     => [
                    'email' => ['Pediste demasiados códigos de recuperación. Esperá una hora y volvé a intentar.'],
                ],
            ], 422);
        }

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
        /*
         * La contraseña nueva se valida ANTES que el código, a propósito: una contraseña mala no consume el
         * código ni le suma un intento (el usuario la corrige y reintenta con el mismo código), y como no
         * mira el email tampoco dice si la cuenta existe. Mínimo MINIMO_DE_LA_CONTRASENA caracteres: la SPA
         * ya exige más de 6 (Step3.vue), así que lo que manda la SPA de hoy siempre pasa. Antes de esto un
         * pedido con el código bueno y sin `password` dejaba la contraseña en vacío.
         */
        if (!$this->contrasena_nueva_valida($request->input('password'))) {
            return response()->json([
                'password_updated' => false,
                'message'          => 'La contraseña nueva no es válida',
                'errors'           => [
                    'password' => ['La contraseña nueva tiene que tener al menos ' . self::MINIMO_DE_LA_CONTRASENA . ' caracteres.'],
                ],
            ], 422);
        }

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

        /*
         * Loguea al dueño con la contraseña recién guardada (la SPA, al terminar, pide `auth/me` y entra al
         * sistema). `owner_id => null` para que, si otro usuario (un empleado) compartiera el correo, el que
         * se loguea sea el dueño al que se le cambió la contraseña. La contraseña YA está guardada: si por lo
         * que fuera el login no prospera, igual se responde que se actualizó (antes la respuesta quedaba
         * vacía) y la persona entra por el login con su clave nueva.
         */
        Auth::attempt(['email' => $user->email, 'password' => $request->password, 'owner_id' => null], false);

        return response()->json(['password_updated' => true], 200);
    }

    /**
     * El email del pedido como texto, o null si no vino o está vacío.
     *
     * Solo con un email de texto no vacío: con `email` ausente, `where('email', null)` se convierte en
     * `whereNull('email')` y devolvería cualquier usuario sin email (un empleado, por ejemplo).
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function email_del_pedido(Request $request)
    {
        $email = $request->input('email');

        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return $email;
    }

    /**
     * El DUEÑO del email del pedido, o null.
     *
     * Solo dueños (`owner_id` null): la contraseña de un empleado la cambia el dueño desde el módulo de
     * empleados, nunca se recupera por mail. Un empleado es, para todo el flujo, un usuario que no existe.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\User|null
     */
    protected function usuario_del_pedido(Request $request)
    {
        $email = $this->email_del_pedido($request);

        if (is_null($email)) {
            return null;
        }

        return User::where('email', $email)->whereNull('owner_id')->first();
    }

    /**
     * ¿El email del pedido es de un empleado (y de ningún dueño)? Solo lo usa `send-verification-code`
     * para decirle a la persona POR QUÉ no se le mandó nada; no se llama si el correo es de un dueño.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function el_correo_es_de_un_empleado(Request $request)
    {
        $email = $this->email_del_pedido($request);

        if (is_null($email)) {
            return false;
        }

        return User::where('email', $email)->whereNotNull('owner_id')->exists();
    }

    /**
     * Cuántos dígitos tiene que tener el código de este pedido.
     *
     * OPT-IN: solo si el cliente manda `longitud_del_codigo` = 4 (la SPA nueva) el código es de 4 dígitos.
     * Sin el campo, o con cualquier otro valor, es de LONGITUD_DEL_CODIGO_POR_DEFECTO (6): la SPA vieja
     * sigue andando como siempre. Se compara como texto para que `4`, `"4"` y `"4 "` valgan lo mismo y
     * `"4abc"` o un arreglo no.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int
     */
    protected function longitud_pedida(Request $request)
    {
        $pedida = $request->input('longitud_del_codigo');

        if (is_scalar($pedida) && trim((string) $pedida) === (string) self::LONGITUD_DEL_CODIGO_CORTO) {
            return self::LONGITUD_DEL_CODIGO_CORTO;
        }

        return self::LONGITUD_DEL_CODIGO_POR_DEFECTO;
    }

    /**
     * Genera un código numérico de `$longitud` dígitos que NO empieza en 0 (4 dígitos: 1000–9999; 6
     * dígitos: 100000–999999), con un generador criptográfico.
     *
     * @param  int  $longitud
     * @return string
     */
    protected function generar_codigo($longitud)
    {
        return (string) random_int(10 ** ($longitud - 1), (10 ** $longitud) - 1);
    }

    /**
     * Corre `$accion` con un candado de caché exclusivo de este usuario y devuelve lo que ella devuelva.
     *
     * 🔴 POR QUÉ: los topes (5 intentos por código, 5 códigos por hora) se leen, se deciden y se
     * reescriben en la caché. Sin candado, pedidos en paralelo leen el mismo contador y todos lo ven en 0:
     * se probarían cientos de códigos por emisión, y con 4 dígitos (9.000 combinaciones) eso es adivinar la
     * clave. Con el candado cada pedido ve el contador que dejó el anterior.
     *
     * Si no consigue el candado en SEGUNDOS_DE_ESPERA_DEL_CANDADO devuelve `$si_no_hay_candado` (falla
     * cerrado: el pedido no se da por bueno). Si la caché del entorno no sabe de candados, corre sin él.
     *
     * @param  \App\Models\User  $user
     * @param  callable  $accion
     * @param  mixed  $si_no_hay_candado
     * @return mixed
     */
    protected function con_candado_del_usuario(User $user, callable $accion, $si_no_hay_candado)
    {
        if (!(Cache::getStore() instanceof LockProvider)) {
            return $accion();
        }

        try {
            return Cache::lock('password_reset_candado:' . $user->id, self::SEGUNDOS_DEL_CANDADO)
                ->block(self::SEGUNDOS_DE_ESPERA_DEL_CANDADO, $accion);
        } catch (LockTimeoutException $e) {
            return $si_no_hay_candado;
        }
    }

    /**
     * ¿La contraseña nueva es texto, no está vacía (ni es solo espacios) y tiene al menos
     * MINIMO_DE_LA_CONTRASENA caracteres? Se cuentan caracteres, no bytes. `password` no pasa por
     * TrimStrings (está en su lista de excepciones): los espacios cuentan como parte de la contraseña.
     *
     * @param  mixed  $contrasena
     * @return bool
     */
    protected function contrasena_nueva_valida($contrasena)
    {
        if (!is_string($contrasena) || trim($contrasena) === '') {
            return false;
        }

        return mb_strlen($contrasena) >= self::MINIMO_DE_LA_CONTRASENA;
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
     * Clave de caché del contador de códigos emitidos de un usuario. Es APARTE del código pendiente a
     * propósito: emitir un código nuevo pisa el pendiente, pero este contador no se reinicia.
     *
     * @param  \App\Models\User  $user
     * @return string
     */
    protected function clave_de_pedidos(User $user)
    {
        return 'password_reset_pedidos:' . $user->id;
    }

    /**
     * Los pedidos de la ventana vigente del usuario: `desde` (timestamp del primero) y `cantidad`. Una
     * ventana vencida, o nada guardado, cuenta como ventana nueva (null).
     *
     * @param  \App\Models\User  $user
     * @return array|null
     */
    protected function pedidos_de_la_ventana(User $user)
    {
        $pedidos = Cache::get($this->clave_de_pedidos($user));

        if (!is_array($pedidos) || !isset($pedidos['desde'], $pedidos['cantidad'])) {
            return null;
        }

        // now() y no time(): el reloj de Carbon es el que mueven los tests.
        if ((int) $pedidos['desde'] + self::VENTANA_DE_PEDIDOS_EN_MINUTOS * 60 <= now()->getTimestamp()) {
            return null;
        }

        return $pedidos;
    }

    /**
     * ¿Se le puede emitir otro código a este usuario en la ventana vigente?
     *
     * @param  \App\Models\User  $user
     * @return bool
     */
    protected function puede_emitir_otro_codigo(User $user)
    {
        $pedidos = $this->pedidos_de_la_ventana($user);

        return is_null($pedidos) || (int) $pedidos['cantidad'] < self::CODIGOS_POR_HORA_MAXIMOS;
    }

    /**
     * Suma un código emitido. La ventana es FIJA: arranca con el primer pedido y no se corre con los
     * siguientes (si se corriera, pedir uno cada 59 minutos la estiraría para siempre); la caché vive
     * hasta que la ventana vence.
     *
     * @param  \App\Models\User  $user
     * @return void
     */
    protected function contar_codigo_emitido(User $user)
    {
        $pedidos = $this->pedidos_de_la_ventana($user);

        if (is_null($pedidos)) {
            $pedidos = ['desde' => now()->getTimestamp(), 'cantidad' => 0];
        }

        $pedidos['cantidad'] = (int) $pedidos['cantidad'] + 1;

        $segundos_que_le_quedan = (int) $pedidos['desde'] + self::VENTANA_DE_PEDIDOS_EN_MINUTOS * 60 - now()->getTimestamp();

        Cache::put($this->clave_de_pedidos($user), $pedidos, max(1, $segundos_que_le_quedan));
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
        return $this->con_candado_del_usuario($user, function () use ($user, $codigo) {
            return $this->comparar_codigo_pendiente($user, $codigo);
        }, false);
    }

    /**
     * El cuerpo de codigo_valido(), que corre bajo el candado del usuario (ver con_candado_del_usuario()):
     * lee el código pendiente, lo compara y, si no coincide, suma el intento o lo borra.
     *
     * @param  \App\Models\User  $user
     * @param  mixed  $codigo
     * @return bool
     */
    protected function comparar_codigo_pendiente(User $user, $codigo)
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
