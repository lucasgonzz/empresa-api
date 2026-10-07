<?php

namespace Tests\Feature\MailsRechazados;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El código de recuperación de contraseña (`POST /password-reset/send-verification-code`).
 *
 * Antes: si el servidor SMTP rechazaba la casilla del usuario (550), el endpoint contestaba `{email_send: true}`, el SPA
 * avanzaba a "ingresá el código" y el usuario esperaba un mail que nunca iba a llegar.
 *
 * Contrato (compatible hacia atrás): un 422 con FORMA DE VALIDACIÓN DE LARAVEL
 * (`message` + un mapa `errors`), más `email_send: false` y `mail_rechazado: true` en el cuerpo. El `.catch` de `Step1.vue` solo hace console.log, pero el
 * interceptor global de axios del SPA (`main.js`: `global_api_error_interceptor` → `show_laravel_validation_toast`, presente desde la v1.1.1 del 26/3/2026) convierte un 422
 * con un mapa `errors` en un toast con el motivo, sin mirar si hay sesión, y el `.then` de `Step1.vue` no corre: no avanza al paso del código. Con un 200
 * `email_send: false` el SPA diría "El correo no corresponde a un usuario registrado", que es FALSO (el usuario existe, el que rechazó fue el buzón); con un
 * 401/403/500 quedaría mudo. La forma de error de siempre para "el correo no es de ningún usuario" (200 `email_send: false`) NO cambia.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ReseteoDeContrasenaRechazadoTest extends MailsRechazadosTestCase
{
    const URL = '/password-reset/send-verification-code';

    /** @var User */
    private $usuario;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = $this->crear_un_dueno(['email' => $this->una_casilla('reseteo')]);

        $this->no_depender_de_la_columna_verification_code();
    }

    /**
     * 🔴 La tabla `users` de una base creada con las migraciones del repo NO TIENE `verification_code`: la línea está
     * comentada en `2014_10_12_000000_create_users_table.php` y solo `buyers` la tiene. Medido el 7/10/2026 en las bases de
     * testing de varios slots y en las de desarrollo locales: ninguna la tiene. Sin la columna, el `$user->save()` de
     * `sendVerificationCode` revienta con un 500 ANTES de llegar al mail, y esto no se podría probar.
     *
     * Lo que se prueba acá es qué pasa con el mail, que no depende de dónde se guarde el código. Mientras la columna no exista se le
     * saca el atributo al usuario antes de persistirlo (el `UPDATE` sigue corriendo, solo sin esa columna). Si algún día existe
     * (una base vieja, o una migración nueva) no se toca nada y el test corre contra la columna de verdad.
     *
     * Aparte: en una base armada con las migraciones el endpoint revienta antes de mandar el mail (esa columna no existe); este test no lo arregla,
     * solo ejerce el camino del mail.
     *
     * @return void
     */
    private function no_depender_de_la_columna_verification_code(): void
    {
        if (Schema::hasColumn('users', 'verification_code')) {
            return;
        }

        User::saving(function (User $usuario) {
            unset($usuario->verification_code);
        });
    }

    /**
     * El centro del cambio: contra un SMTP de verdad que contesta 550, la respuesta es un error claro y NO "enviado".
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_la_respuesta_es_un_error_y_no_enviado(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $respuesta = $this->postJson(self::URL, ['email' => $this->usuario->email]);

        $respuesta->assertStatus(422);
        $this->assertFalse($respuesta->json('email_send'), 'El servidor rechazó la casilla: el código NO se mandó y no puede decir "enviado".');
        $this->assertTrue($respuesta->json('mail_rechazado'), 'Tiene que decir que fue el servidor de correo quien rechazó la casilla.');
        $this->assertSame('No se pudo enviar el código de recuperación', $respuesta->json('message'));

        // El motivo sale de la excepción (una sola fuente) y le dice a la persona qué hacer.
        $motivo = (string) $respuesta->json('errors.email.0');
        $this->assertStringContainsString(ucfirst(MailRechazadoPorElServidorException::MOTIVO), $motivo);
        $this->assertStringContainsString('pedile a un administrador que revise tu correo', $motivo);
    }

    /**
     * 🔴 La FORMA de la respuesta es el contrato con el SPA que hoy está en producción: `is_laravel_validation_payload()` (`laravel_validation_toast.js`) exige
     * un 422 y un `errors` que sea un OBJETO (un mapa campo → mensajes), no una lista. Si `errors` saliera como lista (`['…']`), el SPA no mostraría ningún toast
     * y el usuario se quedaría mudo.
     *
     * @return void
     */
    public function test_la_respuesta_tiene_la_forma_de_validacion_que_el_spa_convierte_en_toast(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $respuesta = $this->postJson(self::URL, ['email' => $this->usuario->email]);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['message', 'errors' => ['email']]);

        $cuerpo = json_decode($respuesta->getContent());

        $this->assertIsObject($cuerpo->errors, '`errors` tiene que ser un objeto (campo → mensajes), no una lista: si no, el SPA no muestra el toast.');
        $this->assertIsArray($cuerpo->errors->email);
        $this->assertNotEmpty($cuerpo->errors->email);
        $this->assertIsString($cuerpo->errors->email[0]);
        // El SPA descarta los textos vacíos al armar el toast: con `['']` la respuesta tendría la forma pero el usuario no vería NADA.
        $this->assertNotSame('', trim($cuerpo->errors->email[0]), 'El motivo no puede ir vacío: el SPA descarta los textos vacíos y el usuario se quedaría sin ningún aviso.');
    }

    /**
     * El mensaje no repite la casilla: la dirección entera ya la conoce quien la tipeó, y repetirla en cada respuesta
     * de la API es regar los datos de una persona.
     *
     * @return void
     */
    public function test_la_respuesta_de_error_no_repite_la_casilla(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $respuesta = $this->postJson(self::URL, ['email' => $this->usuario->email]);

        // Precondición: tiene que ser la respuesta del rechazo (sin ella, esta aserción pasaría también con un 500).
        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('mail_rechazado'));

        $this->assertStringNotContainsString($this->usuario->email, $respuesta->getContent());
    }

    /**
     * Control: contra un servidor que ACEPTA todo, la respuesta es la de siempre. Sin este control, un arreglo que
     * contestara siempre "rechazado" pasaría el test del rechazo.
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_la_respuesta_es_enviado_como_siempre(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $respuesta = $this->postJson(self::URL, ['email' => $this->usuario->email]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('email_send'));
        $this->assertArrayNotHasKey('mail_rechazado', $respuesta->json(), 'Un envío aceptado no lleva la marca de rechazo.');
    }

    /**
     * Lo que se manda y a quién NO cambia: sigue saliendo UN mailable `PasswordReset`, a la casilla del usuario y con un
     * código de 6 dígitos. (Con `Mail::fake()`: acá no se mira el servidor sino el mail.)
     *
     * @return void
     */
    public function test_lo_que_se_manda_y_a_quien_no_cambia(): void
    {
        Mail::fake();

        $respuesta = $this->postJson(self::URL, ['email' => $this->usuario->email]);

        $this->assertTrue($respuesta->json('email_send'));

        Mail::assertSent(PasswordReset::class, 1);
        Mail::assertSent(PasswordReset::class, function ($mail) {
            return $mail->hasTo($this->usuario->email) && preg_match('/^\d{6}$/', (string) $mail->code) === 1;
        });
    }

    /**
     * La forma de error de siempre sigue igual: un correo que no corresponde a ningún usuario da `email_send: false`
     * SIN la marca de rechazo (no es lo mismo: ahí ni siquiera se intenta mandar nada).
     *
     * @return void
     */
    public function test_un_correo_que_no_es_de_ningun_usuario_sigue_dando_email_send_false_sin_marca_de_rechazo(): void
    {
        Mail::fake();

        $respuesta = $this->postJson(self::URL, ['email' => $this->una_casilla('nadie')]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('email_send'));
        $this->assertArrayNotHasKey('mail_rechazado', $respuesta->json());
        Mail::assertNothingSent();
    }
}
