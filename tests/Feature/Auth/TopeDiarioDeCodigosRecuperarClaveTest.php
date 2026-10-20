<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Testing\Fakes\MailFake;
use Tests\Feature\MailsRechazados\MailsRechazadosTestCase;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Tope DIARIO de códigos de recuperación de clave por cuenta (misión tope-diario-de-codigos-recuperar-clave,
 * 20/10/2026), además del tope de 5 por hora que ya existía.
 *
 * Por qué: con 4 dígitos (9.000 combinaciones) y solo el tope por hora, un ataque sostenido probaba
 * 5 códigos × 5 intentos × 24 h = 600 combinaciones por día (≈ 6,5 % de acertar). Con 10 códigos por día son
 * 10 × 5 = 50 intentos por día (≈ 0,55 %). Los números de este archivo (10 por día, 5 por hora, 5 intentos)
 * están ESCRITOS A PROPÓSITO en vez de leídos de las constantes del controlador: son la decisión de producto, y
 * si alguien las afloja estos tests tienen que fallar.
 *
 * Qué se prueba:
 *   (a) el 11.º código del día se rechaza con 422 y el mensaje del día, sin mail y sin tocar el código pendiente;
 *   (b) 10 códigos repartidos en muchas horas (respetando el tope por hora) y el 11.º rechazado;
 *   (c) pasadas 24 h desde el primer pedido vuelve a andar y el contador arranca de cero;
 *   (d) la ventana es FIJA: los pedidos siguientes no la estiran;
 *   (e) un mail que no sale (rechazo del SMTP real, o un error al mandarlo) devuelve el pedido de la HORA pero SÍ
 *       gasta el del DÍA (el día nunca se devuelve: ver el comentario de cabecera de PasswordResetController);
 *   (i2) con el mail fallando siempre, como máximo 10 pedidos por día; (m) el descarte del código que no salió no
 *       borra un código más nuevo;
 *   (f) el tope es por cuenta;
 *   (g) con la hora y el día llenos, el mensaje es el del día;
 *   (h) el código pendiente sigue sirviendo con el tope diario lleno;
 *   (i) de punta a punta: el máximo de intentos por día es 10 × 5 = 50;
 *   (j) el tope vale con y sin `longitud_del_codigo`;
 *   y que la respuesta tiene la MISMA FORMA que la del tope por hora (compatibilidad con la SPA vieja).
 *
 * Mail con `Mail::fake()` salvo en (e), que habla SMTP de verdad con un servidor de mentira en 127.0.0.1 que
 * contesta 550 (es lo único que muestra el rechazo real: ver `MailsRechazadosTestCase`).
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class TopeDiarioDeCodigosRecuperarClaveTest extends MailsRechazadosTestCase
{
    const SEND = '/password-reset/send-verification-code';
    const CHECK = '/password-reset/check-verification-code';
    const UPDATE = '/password-reset/update-password';

    const CLAVE_NUEVA = 'clave-nueva-5678';

    /** El texto que el usuario lee cuando llega al tope del día (y lo que tiene que decir qué hacer). */
    const MENSAJE_DEL_DIA = 'Llegaste al máximo de códigos de recuperación por día. Probá de nuevo en 24 horas o pedile ayuda a soporte.';

    /** El texto del tope por hora, que NO se tocó. */
    const MENSAJE_DE_LA_HORA = 'Pediste demasiados códigos de recuperación. Esperá una hora y volvé a intentar.';

    /** @var User El dueño que pide los códigos. */
    protected $dueno;

    /** @var Carbon El "ahora" con el que arranca cada test (el primer pedido sale a esta hora). */
    protected $inicio;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Estos tests hacen decenas de pedidos desde la misma IP: se apaga el throttle por IP para probar la
        // capa POR CUENTA. La capa por IP se prueba en ThrottlePorIpEnRecuperarClaveTest.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->dueno = $this->crear_un_dueno();

        $this->inicio = Carbon::parse('2026-10-20 12:00:00');
        $this->travelTo($this->inicio->copy());
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------------------------------

    /**
     * Mueve el reloj a `$minutos` minutos después del primer pedido del test.
     *
     * @param  int  $minutos
     * @return void
     */
    protected function ir_al_minuto($minutos)
    {
        $this->travelTo($this->inicio->copy()->addMinutes($minutos));
    }

    /**
     * Un pedido de código, tal cual lo manda la SPA nueva (4 dígitos) salvo que se pise `$extra`.
     *
     * @param  string|null  $email  Por defecto, el del dueño del test.
     * @param  array  $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir($email = null, array $extra = ['longitud_del_codigo' => 4])
    {
        return $this->postJson(self::SEND, array_merge(['email' => $email ?: $this->dueno->email], $extra));
    }

    /**
     * Pide un código que TIENE que salir (200 + mail) y lo devuelve (el del último mail mandado).
     *
     * @param  string|null  $email
     * @param  array  $extra
     * @return string
     */
    protected function pedir_codigo($email = null, array $extra = ['longitud_del_codigo' => 4])
    {
        $this->pedir($email, $extra)->assertStatus(200)->assertJson(['email_send' => true]);

        $codigo = null;

        Mail::assertSent(PasswordReset::class, function ($mail) use (&$codigo) {
            $codigo = (string) $mail->code;

            return true;
        });

        return $codigo;
    }

    /**
     * Emite `$cantidad` códigos, uno cada `$separacion` minutos desde el minuto `$desde`, y devuelve el último.
     * Con la separación de 20 minutos nunca caen más de 3 en la misma hora: el tope por hora no estorba.
     *
     * @param  int  $cantidad
     * @param  int  $separacion  Minutos entre un pedido y el siguiente.
     * @param  int  $desde  Minuto (desde el inicio del test) del primero.
     * @param  string|null  $email
     * @param  array  $extra
     * @return string
     */
    protected function emitir_codigos($cantidad, $separacion = 20, $desde = 0, $email = null, array $extra = ['longitud_del_codigo' => 4])
    {
        $ultimo = null;

        for ($i = 0; $i < $cantidad; $i++) {
            $this->ir_al_minuto($desde + $i * $separacion);
            $ultimo = $this->pedir_codigo($email, $extra);
        }

        return $ultimo;
    }

    /**
     * La respuesta es el 422 del tope del día: forma de validación, `email_send: false` y el texto del día en
     * `message` y en `errors.email[0]`.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_tope_del_dia($respuesta)
    {
        $respuesta->assertStatus(422);
        $respuesta->assertJson([
            'email_send' => false,
            'message'    => self::MENSAJE_DEL_DIA,
            'errors'     => ['email' => [self::MENSAJE_DEL_DIA]],
        ]);
    }

    /**
     * La respuesta es el 422 del tope de la hora (el de siempre).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_tope_de_la_hora($respuesta)
    {
        $respuesta->assertStatus(422);
        $respuesta->assertJson([
            'email_send' => false,
            'message'    => 'Demasiados pedidos de código',
            'errors'     => ['email' => [self::MENSAJE_DE_LA_HORA]],
        ]);
    }

    /**
     * El código pendiente en caché del usuario (el que se guarda al emitir), o null.
     *
     * @param  User|null  $usuario
     * @return array|null
     */
    protected function pendiente_de(User $usuario = null)
    {
        return Cache::get('password_reset:' . ($usuario ?: $this->dueno)->id);
    }

    /**
     * Cuántos pedidos lleva contados la ventana del día de un usuario (0 si no hay ventana).
     *
     * @param  User|null  $usuario
     * @return int
     */
    protected function cantidad_del_dia(User $usuario = null)
    {
        $pedidos = Cache::get('password_reset_pedidos_dia:' . ($usuario ?: $this->dueno)->id);

        return is_array($pedidos) ? (int) $pedidos['cantidad'] : 0;
    }

    /**
     * Cuántos pedidos lleva contados la ventana de la hora de un usuario (0 si no hay ventana).
     *
     * @param  User|null  $usuario
     * @return int
     */
    protected function cantidad_de_la_hora(User $usuario = null)
    {
        $pedidos = Cache::get('password_reset_pedidos:' . ($usuario ?: $this->dueno)->id);

        return is_array($pedidos) ? (int) $pedidos['cantidad'] : 0;
    }

    /**
     * Un código de 4 dígitos que no es el dado.
     *
     * @param  string  $codigo
     * @return string
     */
    protected function otro_codigo($codigo)
    {
        return $codigo === '1111' ? '2222' : '1111';
    }

    /**
     * @param  string  $email
     * @param  mixed  $codigo
     * @return bool
     */
    protected function verificar($email, $codigo)
    {
        $respuesta = $this->postJson(self::CHECK, ['email' => $email, 'verification_code' => $codigo]);

        $respuesta->assertStatus(200);

        return (bool) $respuesta->json('verified');
    }

    // ---------------------------------------------------------------------------------------------
    // (a) El 11.º código del día
    // ---------------------------------------------------------------------------------------------

    /**
     * 10 códigos en 3 horas y el 11.º: 422 con el mensaje del día, sin mail, sin código nuevo y sin tocar el
     * pendiente ni los contadores.
     *
     * @test
     */
    public function el_codigo_11_del_dia_se_rechaza_con_el_mensaje_del_dia_sin_mail_y_sin_tocar_el_pendiente()
    {
        Mail::fake();

        // 10 códigos, uno cada 20 minutos (3 por hora como mucho): el décimo sale a los 180 minutos.
        $decimo = $this->emitir_codigos(10);

        Mail::assertSent(PasswordReset::class, 10);
        $this->assertSame(10, $this->cantidad_del_dia());

        $pendiente_antes = $this->pendiente_de();
        $this->assertNotNull($pendiente_antes);

        // El 11.º, a los 200 minutos: la hora tiene lugar (3 de 5), el día no.
        $this->ir_al_minuto(200);
        $this->assertLessThan(5, $this->cantidad_de_la_hora(), 'Precondición: la hora todavía tiene lugar, el que corta es el día.');

        $this->assert_tope_del_dia($this->pedir());

        // Ni un mail más, ni un código nuevo: el pendiente es EXACTAMENTE el del décimo.
        Mail::assertSent(PasswordReset::class, 10);
        $this->assertSame($pendiente_antes, $this->pendiente_de(), 'El pedido rechazado no puede tocar el código pendiente.');
        $this->assertSame(10, $this->cantidad_del_dia(), 'Un pedido rechazado no suma al contador.');
        $this->assertTrue($this->verificar($this->dueno->email, $decimo), 'El décimo código sigue sirviendo.');
    }

    /**
     * El rechazo no depende de que sea el 11.º justo: insistir tampoco lo mueve.
     *
     * @test
     */
    public function insistir_despues_del_tope_diario_sigue_dando_el_mismo_rechazo_sin_mail()
    {
        Mail::fake();

        $this->emitir_codigos(10);

        foreach ([200, 201, 230, 300, 600] as $minuto) {
            $this->ir_al_minuto($minuto);
            $this->assert_tope_del_dia($this->pedir());
        }

        Mail::assertSent(PasswordReset::class, 10);
    }

    // ---------------------------------------------------------------------------------------------
    // (b) Repartidos en muchas horas
    // ---------------------------------------------------------------------------------------------

    /**
     * 10 códigos repartidos en 18 horas (uno cada 2 h: el tope por hora nunca se acerca) y el 11.º, a las 20 h,
     * sigue estando dentro de las 24 h del primero: rechazado. Es el caso que el tope por hora, solo, dejaba pasar.
     *
     * @test
     */
    public function diez_codigos_repartidos_en_muchas_horas_tambien_agotan_el_dia()
    {
        Mail::fake();

        $this->emitir_codigos(10, 120);

        Mail::assertSent(PasswordReset::class, 10);

        // A las 20 h del primero: la ventana de la hora está vacía (el último fue hace 2 h), pero el día no.
        $this->ir_al_minuto(20 * 60);
        $this->assertSame(0, $this->cantidad_de_la_hora(), 'Precondición: el tope por hora no tiene nada que ver.');
        $this->assert_tope_del_dia($this->pedir());

        // Y a las 23 h 59 min del primero, también.
        $this->ir_al_minuto(24 * 60 - 1);
        $this->assert_tope_del_dia($this->pedir());

        Mail::assertSent(PasswordReset::class, 10);
    }

    // ---------------------------------------------------------------------------------------------
    // (c) Pasadas las 24 h
    // ---------------------------------------------------------------------------------------------

    /**
     * Pasadas 24 h desde el primer pedido vuelve a andar y el contador arranca de cero: otros 10 códigos y el
     * 11.º de esa ventana nueva se rechaza otra vez.
     *
     * @test
     */
    public function pasadas_24_horas_del_primer_pedido_vuelve_a_andar_y_el_contador_arranca_de_cero()
    {
        Mail::fake();

        $this->emitir_codigos(10);

        $this->ir_al_minuto(24 * 60 - 1);
        $this->assert_tope_del_dia($this->pedir());

        // Justo a las 24 h la ventana venció.
        $this->ir_al_minuto(24 * 60);
        $this->pedir_codigo();
        $this->assertSame(1, $this->cantidad_del_dia(), 'La ventana nueva arranca de cero: este es el primer pedido.');

        // Los otros 9 de la ventana nueva (cada 20 minutos) y el 11.º vuelve a rechazarse.
        $this->emitir_codigos(9, 20, 24 * 60 + 20);
        Mail::assertSent(PasswordReset::class, 20);
        $this->assertSame(10, $this->cantidad_del_dia());

        $this->ir_al_minuto(24 * 60 + 20 + 9 * 20);
        $this->assert_tope_del_dia($this->pedir());
    }

    // ---------------------------------------------------------------------------------------------
    // (d) Ventana fija
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 La ventana es FIJA desde el primer pedido: los siguientes no la corren. Si se corriera con cada pedido,
     * pedir uno cada 23 h la estiraría para siempre. Primer pedido a las 0 h, cinco a las 22 h, cuatro a las
     * 23 h 05 (10 en total): a las 23 h 30 está lleno, y a las 24 h 01 del PRIMERO tiene que haber vencido
     * aunque el último pedido tenga 56 minutos.
     *
     * @test
     */
    public function la_ventana_diaria_es_fija_los_pedidos_siguientes_no_la_estiran()
    {
        Mail::fake();

        $this->pedir_codigo();                                 // minuto 0: abre la ventana
        $this->emitir_codigos(5, 1, 22 * 60);                  // 22:00 a 22:04
        $this->emitir_codigos(4, 1, 23 * 60 + 5);              // 23:05 a 23:08
        Mail::assertSent(PasswordReset::class, 10);

        $this->ir_al_minuto(23 * 60 + 30);
        $this->assert_tope_del_dia($this->pedir());

        // 24 h 01 min desde el primero (y solo 53 min desde el último): la ventana venció.
        $this->ir_al_minuto(24 * 60 + 1);
        $this->pedir_codigo();

        $this->assertSame(1, $this->cantidad_del_dia(), 'El contador tiene que ser de la ventana nueva, no el acumulado.');
        $ventana = Cache::get('password_reset_pedidos_dia:' . $this->dueno->id);
        $this->assertSame($this->inicio->copy()->addMinutes(24 * 60 + 1)->getTimestamp(), (int) $ventana['desde'], 'La ventana nueva arranca en el pedido que la abrió.');
    }

    /**
     * Un pedido más en la ventana no le corre el vencimiento: la caché guarda el contador hasta el mismo
     * instante que desde el primer pedido.
     *
     * @test
     */
    public function el_contador_del_dia_conserva_el_inicio_de_la_ventana_al_sumar_pedidos()
    {
        Mail::fake();

        $this->pedir_codigo();
        $inicio_de_la_ventana = Cache::get('password_reset_pedidos_dia:' . $this->dueno->id)['desde'];

        $this->ir_al_minuto(300);
        $this->pedir_codigo();

        $ventana = Cache::get('password_reset_pedidos_dia:' . $this->dueno->id);
        $this->assertSame(2, (int) $ventana['cantidad']);
        $this->assertSame($inicio_de_la_ventana, $ventana['desde']);
        $this->assertSame($this->inicio->getTimestamp(), (int) $ventana['desde']);
    }

    // ---------------------------------------------------------------------------------------------
    // (e) Un mail que no sale: devuelve el pedido de la HORA pero SÍ gasta el del DÍA
    //
    // 🔴 El código se guarda ANTES de mandar el mail y el envío corre fuera del candado: durante el envío hay un
    // código vivo con 5 intentos. Si el pedido fallido no gastara el cupo del día, con la casilla muerta cada
    // pedido abriría una mini-ventana de 5 intentos gratis y el tope diario no acotaría nada. Por eso el día no
    // se devuelve nunca, y la hora sí (el día ya la acota).
    // ---------------------------------------------------------------------------------------------

    /**
     * Un mail cuyo `send()` falla con un error cualquiera. Si se da `$al_mandar`, corre justo antes de fallar
     * (para simular lo que pasa en la caché mientras el mail se está mandando).
     *
     * @param  callable|null  $al_mandar
     * @return MailFake
     */
    protected function mail_que_falla(callable $al_mandar = null)
    {
        $falso = new class extends MailFake {
            /** @var callable|null Lo que pasa justo antes de que el envío falle. */
            public $al_mandar;

            /** Simula un error al mandar (una vista rota, el transporte caído...). */
            public function send($view, array $data = [], $callback = null)
            {
                if ($this->al_mandar) {
                    call_user_func($this->al_mandar);
                }

                throw new \RuntimeException('No se pudo mandar el mail de prueba');
            }
        };
        $falso->al_mandar = $al_mandar;

        Mail::swap($falso);

        return $falso;
    }

    /**
     * El pedido que el SMTP rechaza con 550: la respuesta es «mail rechazado», no un tope.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @param  string  $cuando  Para el mensaje de la aserción.
     * @return void
     */
    protected function assert_mail_rechazado($respuesta, $cuando = '')
    {
        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('mail_rechazado'), "$cuando: tenía que ser el rechazo del servidor de correo, no un tope.");
        $this->assertSame('No se pudo enviar el código de recuperación', $respuesta->json('message'), $cuando);
        $this->assertStringNotContainsString('máximo de códigos', $respuesta->getContent(), $cuando);
        $this->assertStringNotContainsString('demasiados códigos', $respuesta->getContent(), $cuando);
    }

    /**
     * 🔴 Contra un SMTP de verdad que contesta 550: cada pedido rechazado DEVUELVE su pedido de la hora (el
     * contador de la hora queda en 0) pero GASTA uno del día (el del día sube de a uno), y no queda un código
     * pendiente que nadie recibió.
     *
     * @test
     */
    public function un_mail_rechazado_por_el_smtp_devuelve_el_pedido_de_la_hora_pero_gasta_el_del_dia()
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        for ($i = 1; $i <= 3; $i++) {
            $this->assert_mail_rechazado($this->pedir(), "Pedido $i");

            $this->assertSame(0, $this->cantidad_de_la_hora(), "Pedido $i: el rechazo devuelve el pedido de la hora.");
            $this->assertSame($i, $this->cantidad_del_dia(), "Pedido $i: el rechazo NO devuelve el pedido del día.");
        }

        $this->assertNull(Cache::get('password_reset_pedidos:' . $this->dueno->id));
        $this->assertNull($this->pendiente_de(), 'El código que no llegó a nadie no puede quedar pendiente.');
    }

    /**
     * 🔴 (i2) Con el mail fallando SIEMPRE (casilla muerta, SMTP 550) se pueden hacer como máximo 10 pedidos en
     * el día aunque pasen horas entre uno y otro: cada uno gasta 1 del día y el 11.º da el 422 del día. Es lo que
     * mantiene firme el 10 × 5 = 50 intentos por día aun sin que el mail salga.
     *
     * @test
     */
    public function con_el_smtp_rechazando_siempre_se_pueden_hacer_como_maximo_10_pedidos_en_el_dia()
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        // Uno cada 2 horas: la hora nunca estorba.
        for ($i = 1; $i <= 10; $i++) {
            $this->ir_al_minuto(($i - 1) * 120);
            $this->assert_mail_rechazado($this->pedir(), "Pedido $i");
        }

        $this->assertSame(10, $this->cantidad_del_dia());

        // El 11.º (a las 20 h, dentro de las 24 h del primero) y el último minuto de la ventana: tope del día.
        $this->ir_al_minuto(20 * 60);
        $this->assert_tope_del_dia($this->pedir());
        $this->ir_al_minuto(24 * 60 - 1);
        $this->assert_tope_del_dia($this->pedir());
        $this->assertSame(10, $this->cantidad_del_dia(), 'Un pedido cortado por el tope no suma.');

        // Pasadas las 24 h del primero, la ventana nueva vuelve a admitir pedidos (y a gastarlos).
        $this->ir_al_minuto(24 * 60);
        $this->assert_mail_rechazado($this->pedir(), 'Ventana nueva');
        $this->assertSame(1, $this->cantidad_del_dia());
    }

    /**
     * (i2, ráfaga) Doce pedidos seguidos con el SMTP rechazando: los 10 primeros son «mail rechazado» (la hora
     * se devuelve, así que nunca llega a su tope de 5) y el 11.º y el 12.º ya son el tope del día.
     *
     * @test
     */
    public function una_rafaga_de_pedidos_con_el_smtp_rechazando_se_corta_en_el_decimo_por_el_dia()
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        for ($i = 1; $i <= 10; $i++) {
            $this->assert_mail_rechazado($this->pedir(), "Pedido $i");
        }

        $this->assert_tope_del_dia($this->pedir());
        $this->assert_tope_del_dia($this->pedir());

        $this->assertSame(0, $this->cantidad_de_la_hora(), 'La hora se devolvió en cada rechazo.');
        $this->assertSame(10, $this->cantidad_del_dia());
        $this->assertNull($this->pendiente_de());
    }

    /**
     * El rechazo devuelve solo SU pedido de la hora: los que sí salieron antes siguen contados, y el del día
     * queda gastado (se suma uno más).
     *
     * @test
     */
    public function el_rechazo_devuelve_solo_su_propio_pedido_de_la_hora()
    {
        // Dos pedidos que salen (SMTP que acepta)...
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $this->pedir()->assertStatus(200)->assertJson(['email_send' => true]);
        $this->ir_al_minuto(20);
        $this->pedir()->assertStatus(200)->assertJson(['email_send' => true]);
        $this->assertSame(2, $this->cantidad_del_dia());
        $this->assertSame(2, $this->cantidad_de_la_hora());

        // ...y el servidor pasa a rechazar: apunta el mismo mailer a otro que contesta 550.
        $servidor_que_rechaza = $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA);
        $servidor_que_rechaza->apuntar_el_mailer('smtp');
        $this->app->make('mail.manager')->forgetMailers();

        $this->ir_al_minuto(40);
        $this->assert_mail_rechazado($this->pedir());

        $this->assertSame(2, $this->cantidad_de_la_hora(), 'La hora: el rechazo devuelve su pedido y nada más.');
        $this->assertSame(3, $this->cantidad_del_dia(), 'El día: el pedido rechazado quedó gastado.');
    }

    /**
     * Un error al armar o mandar el mail (distinto del rechazo del SMTP) se trata igual: devuelve la hora,
     * gasta el día. 12 errores seguidos: los 10 primeros fallan con 500 y el 11.º y 12.º ya son el tope del día.
     *
     * @test
     */
    public function un_error_al_mandar_el_mail_devuelve_la_hora_pero_gasta_el_dia()
    {
        $this->mail_que_falla();

        for ($i = 1; $i <= 10; $i++) {
            $this->pedir()->assertStatus(500);

            $this->assertSame(0, $this->cantidad_de_la_hora(), "Error $i: la hora se devuelve.");
            $this->assertSame($i, $this->cantidad_del_dia(), "Error $i: el día queda gastado.");
        }

        $this->assert_tope_del_dia($this->pedir());
        $this->assert_tope_del_dia($this->pedir());
        $this->assertNull($this->pendiente_de(), 'Un código que no salió no puede quedar pendiente.');

        // Pasadas las 24 h y con el mail andando, el primer pedido es el primero de las dos ventanas.
        $this->ir_al_minuto(24 * 60);
        Mail::fake();
        $this->pedir_codigo();
        $this->assertSame(1, $this->cantidad_de_la_hora());
        $this->assertSame(1, $this->cantidad_del_dia());
    }

    /**
     * (m) El descarte del código que no salió NO borra un código más nuevo: si mientras se mandaba el mail otro
     * pedido emitió uno distinto, el descarte lo respeta (su sha256 no coincide). Sin carrera real: el mail de
     * prueba guarda a mano un código distinto en la caché justo antes de fallar, que es lo que dejaría el otro
     * pedido. Control: el pedido fallido sí devuelve su hora y gasta su día.
     *
     * @test
     */
    public function el_descarte_del_codigo_que_no_salio_no_borra_un_codigo_mas_nuevo()
    {
        $otro_codigo = [
            'codigo_sha256' => hash('sha256', '7777'),
            'intentos'      => 2,
            'vence'         => now()->getTimestamp() + 1800,
        ];

        $this->mail_que_falla(function () use ($otro_codigo) {
            Cache::put('password_reset:' . $this->dueno->id, $otro_codigo, 1800);
        });

        $this->pedir()->assertStatus(500);

        $this->assertSame($otro_codigo, $this->pendiente_de(), 'El código más nuevo no se toca.');
        $this->assertSame(0, $this->cantidad_de_la_hora());
        $this->assertSame(1, $this->cantidad_del_dia());
        $this->assertTrue($this->verificar($this->dueno->email, '7777'), 'El código más nuevo sigue sirviendo.');
    }

    // ---------------------------------------------------------------------------------------------
    // (f) Por cuenta
    // ---------------------------------------------------------------------------------------------

    /**
     * El tope es por cuenta: que un dueño lo haya agotado no frena a otro, y los contadores no se mezclan.
     *
     * @test
     */
    public function el_tope_diario_es_por_cuenta()
    {
        Mail::fake();

        $otro = $this->crear_un_dueno();

        $this->emitir_codigos(10);
        $this->ir_al_minuto(200);
        $this->assert_tope_del_dia($this->pedir());

        // El otro dueño pide sin problema, y es SU primer pedido.
        $codigo_del_otro = $this->pedir_codigo($otro->email);
        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $codigo_del_otro);
        $this->assertSame(1, $this->cantidad_del_dia($otro));
        $this->assertSame(10, $this->cantidad_del_dia($this->dueno));
    }

    // ---------------------------------------------------------------------------------------------
    // (g) Hora y día llenos a la vez: manda el del día
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Con los dos topes llenos el mensaje es el del día (el más restrictivo y el más verdadero: esperar una
     * hora no sirve de nada). Control: con solo la hora llena sigue saliendo el mensaje de la hora.
     *
     * @test
     */
    public function con_la_hora_y_el_dia_llenos_el_mensaje_es_el_del_dia()
    {
        Mail::fake();

        // Cinco códigos en los primeros 5 minutos: la hora se llena con el día en 5.
        $this->emitir_codigos(5, 1, 0);

        $this->ir_al_minuto(6);
        $this->assert_tope_de_la_hora($this->pedir());

        // Cinco más a las 2 h (otra ventana de la hora): el día llega a 10 y la hora vuelve a llenarse.
        $this->emitir_codigos(5, 1, 120);

        $this->ir_al_minuto(126);
        $this->assertSame(5, $this->cantidad_de_la_hora(), 'Precondición: la hora también está llena.');
        $this->assertSame(10, $this->cantidad_del_dia(), 'Precondición: el día también está lleno.');

        $respuesta = $this->pedir();
        $this->assert_tope_del_dia($respuesta);
        $this->assertStringNotContainsString('Esperá una hora', $respuesta->getContent());
        Mail::assertSent(PasswordReset::class, 10);
    }

    // ---------------------------------------------------------------------------------------------
    // (h) El pendiente sigue sirviendo
    // ---------------------------------------------------------------------------------------------

    /**
     * Con el tope diario lleno el último código sigue siendo válido (hasta que venza o gaste sus intentos): la
     * persona que lo recibió puede terminar de cambiar la clave.
     *
     * @test
     */
    public function con_el_tope_diario_lleno_el_codigo_pendiente_sigue_sirviendo()
    {
        Mail::fake();

        $decimo_codigo = $this->emitir_codigos(10);

        // A los 10 minutos del décimo (vive 30), con el tope lleno.
        $this->ir_al_minuto(9 * 20 + 10);
        $this->assert_tope_del_dia($this->pedir());

        $this->assertTrue($this->verificar($this->dueno->email, $decimo_codigo));

        $this->postJson(self::UPDATE, [
            'email'             => $this->dueno->email,
            'verification_code' => $decimo_codigo,
            'password'          => self::CLAVE_NUEVA,
            'password_confirm'  => self::CLAVE_NUEVA,
        ])->assertStatus(200)->assertJson(['password_updated' => true]);

        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, (string) $this->dueno->fresh()->password));
    }

    // ---------------------------------------------------------------------------------------------
    // (i) El máximo de intentos por día, de punta a punta
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Lo que justifica el tope: 10 códigos × 5 intentos fallidos = 50 intentos por día, ni uno más. Se agotan
     * las 10 emisiones con 5 fallidos cada una (cada código muere al 5.º), y el 11.º pedido se rechaza: no hay
     * código nuevo, no hay mail, no queda nada contra qué seguir probando. Con el tope solo por hora serían
     * ≈600 por día.
     *
     * @test
     */
    public function el_maximo_de_intentos_por_dia_es_10_codigos_por_5_intentos()
    {
        Mail::fake();

        $intentos_probados = 0;

        for ($emision = 0; $emision < 10; $emision++) {
            $this->ir_al_minuto($emision * 20);
            $codigo = $this->pedir_codigo();

            for ($i = 1; $i <= 5; $i++) {
                $this->assertFalse($this->verificar($this->dueno->email, $this->otro_codigo($codigo)));
                $intentos_probados++;
            }

            $this->assertFalse($this->verificar($this->dueno->email, $codigo), 'Emisión ' . ($emision + 1) . ': el código ya murió con el 5.º intento.');
        }

        $this->assertSame(50, $intentos_probados);

        $this->ir_al_minuto(10 * 20);
        $this->assert_tope_del_dia($this->pedir());

        Mail::assertSent(PasswordReset::class, 10);
        $this->assertNull($this->pendiente_de(), 'Sin código vigente: no queda nada contra qué seguir probando.');
    }

    // ---------------------------------------------------------------------------------------------
    // (j) Con y sin `longitud_del_codigo`
    // ---------------------------------------------------------------------------------------------

    /**
     * El tope vale para TODOS los pedidos: con códigos de 4 dígitos (SPA nueva), con códigos de 6 (SPA vieja,
     * que no manda el campo) y mezclados. Mezclar no es una vía para esquivarlo.
     *
     * @test
     */
    public function el_tope_diario_vale_con_y_sin_longitud_del_codigo()
    {
        Mail::fake();

        $con_cuatro = $this->dueno;
        $con_seis = $this->crear_un_dueno();
        $mezclado = $this->crear_un_dueno();

        // Diez de 4 dígitos: el 11.º, sin el campo, se rechaza.
        $this->emitir_codigos(10, 20, 0, $con_cuatro->email, ['longitud_del_codigo' => 4]);
        $this->ir_al_minuto(200);
        $this->assert_tope_del_dia($this->pedir($con_cuatro->email, []));
        $this->assert_tope_del_dia($this->pedir($con_cuatro->email, ['longitud_del_codigo' => 4]));

        // Diez de 6 dígitos (sin el campo): el 11.º, con el campo, se rechaza.
        $this->emitir_codigos(10, 20, 300, $con_seis->email, []);
        $this->ir_al_minuto(500);
        $this->assert_tope_del_dia($this->pedir($con_seis->email, ['longitud_del_codigo' => 4]));
        $this->assert_tope_del_dia($this->pedir($con_seis->email, []));

        // Cinco de cada clase: el 11.º, de la clase que sea, se rechaza.
        $this->emitir_codigos(5, 20, 600, $mezclado->email, ['longitud_del_codigo' => 4]);
        $this->emitir_codigos(5, 20, 720, $mezclado->email, []);
        $this->ir_al_minuto(840);
        $this->assert_tope_del_dia($this->pedir($mezclado->email, ['longitud_del_codigo' => 4]));
        $this->assert_tope_del_dia($this->pedir($mezclado->email, []));
    }

    // ---------------------------------------------------------------------------------------------
    // Compatibilidad: la misma forma que el tope por hora
    // ---------------------------------------------------------------------------------------------

    /**
     * 🔴 Compatibilidad con la SPA vieja: el 422 del día tiene EXACTAMENTE las mismas claves que el de la hora
     * (`email_send`, `message`, `errors`), `errors` es un MAPA campo → mensajes (si fuera una lista el toast no
     * se muestra) y el texto no está vacío. No se agregó ningún campo.
     *
     * @test
     */
    public function la_respuesta_del_tope_diario_tiene_la_misma_forma_que_la_del_tope_por_hora()
    {
        Mail::fake();

        // Tope por hora: 5 códigos seguidos y el 6.º.
        $this->emitir_codigos(5, 1, 0);
        $this->ir_al_minuto(6);
        $de_la_hora = $this->pedir();
        $this->assert_tope_de_la_hora($de_la_hora);

        // Tope del día: otros 5 a las 2 h y el 11.º.
        $this->emitir_codigos(5, 1, 120);
        $this->ir_al_minuto(130);
        $del_dia = $this->pedir();
        $this->assert_tope_del_dia($del_dia);

        $this->assertSame(array_keys($de_la_hora->json()), array_keys($del_dia->json()), 'El tope diario no puede agregar ni sacar campos.');
        $this->assertSame(['email_send', 'message', 'errors'], array_keys($del_dia->json()));
        $this->assertSame(['email'], array_keys($del_dia->json('errors')));
        $this->assertCount(1, $del_dia->json('errors.email'));

        $cuerpo = json_decode($del_dia->getContent());
        $this->assertIsObject($cuerpo->errors, '`errors` tiene que ser un mapa campo → mensajes: si no, la SPA no muestra el toast.');
        $this->assertNotSame('', trim($cuerpo->errors->email[0]));
        $this->assertFalse($cuerpo->email_send);
    }

    /**
     * El contador del día es APARTE del de la hora (clave propia) y emitir un código nuevo no lo reinicia.
     *
     * @test
     */
    public function el_contador_del_dia_es_aparte_del_de_la_hora_y_no_se_reinicia_al_emitir()
    {
        Mail::fake();

        $this->emitir_codigos(3, 5);

        $this->assertSame(3, $this->cantidad_del_dia());
        $this->assertSame(3, $this->cantidad_de_la_hora());
        $this->assertNotNull(Cache::get('password_reset_pedidos_dia:' . $this->dueno->id));
        $this->assertNotNull(Cache::get('password_reset_pedidos:' . $this->dueno->id));

        // Pasada la hora, la de la hora se vació pero la del día sigue acumulando.
        $this->ir_al_minuto(90);
        $this->pedir_codigo();
        $this->assertSame(1, $this->cantidad_de_la_hora());
        $this->assertSame(4, $this->cantidad_del_dia());
    }

    /**
     * El valor del tope es una decisión de producto: 10 por día en una ventana de 24 h.
     *
     * @test
     */
    public function el_tope_es_de_10_codigos_en_una_ventana_de_24_horas()
    {
        $this->assertSame(10, \App\Http\Controllers\CommonLaravel\PasswordResetController::CODIGOS_POR_DIA_MAXIMOS);
        $this->assertSame(1440, \App\Http\Controllers\CommonLaravel\PasswordResetController::VENTANA_DIARIA_EN_MINUTOS);
    }
}
