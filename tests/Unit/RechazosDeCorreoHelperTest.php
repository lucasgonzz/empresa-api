<?php

namespace Tests\Unit;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Mail\Helpers\RechazosDeCorreoHelper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ConServidorSmtpDePrueba;
use Tests\Fakes\ServidorSmtpFake;
use Tests\TestCase;

/**
 * `RechazosDeCorreoHelper`: leer qué casillas rechazó el servidor de correo en el último envío, y convertir ese rechazo en una excepción
 * para los envíos que no tienen dónde anotarlo de otra forma.
 *
 * El defecto de fondo (misión mails-rechazados-por-smtp-empresa, 7/10/2026; ya resuelto en admin-api el 6/10): cuando el servidor SMTP
 * contesta 550 en el RCPT TO, SwiftMailer NO tira excepción. `send()` vuelve normal y las casillas rechazadas quedan en `failures()` del
 * mailer. Quien solo atrapa la excepción da por enviado un mail que nunca salió.
 *
 * Dos clases de test, y las dos hacen falta:
 *   - con un servidor SMTP de verdad (`ServidorSmtpFake`, un proceso aparte que contesta 550): es lo único que prueba que SwiftMailer se porta
 *     así y que el helper lo ve en el mailer correcto. En `empresa-api` el transporte `smtp` de los tests es en memoria: acá se restaura el de
 *     verdad, pero solo hacia 127.0.0.1 (`MailManagerSoloLoopback`);
 *   - con un Mockery de la fachada o con `Mail::fake()`: rápido, y fija los bordes (sin repetidos, sin la casilla en el mensaje, qué pasa
 *     cuando el mailer no puede decir nada).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class RechazosDeCorreoHelperTest extends TestCase
{
    // El servidor SMTP de prueba y la regla de que, si no arranca y el entorno podía lanzarlo, el test FALLA (no se saltea en silencio).
    use ConServidorSmtpDePrueba;

    /**
     * Manda un mail mínimo, por el mailer por defecto (como `Mail::to()`) o por uno con nombre.
     *
     * @param string      $para   Casilla de destino.
     * @param string|null $mailer null = el mailer por defecto.
     *
     * @return void
     */
    private function mandar_un_mail_de_prueba(string $para, ?string $mailer = null): void
    {
        $armar = function ($mensaje) use ($para) {
            $mensaje->to($para)->subject('Mail de prueba');
        };

        if ($mailer === null) {
            Mail::raw('Cuerpo de prueba.', $armar);

            return;
        }

        Mail::mailer($mailer)->raw('Cuerpo de prueba.', $armar);
    }

    /**
     * La premisa de la que depende todo el diseño: `Mail::mailer(null)` es el MISMO objeto que usa `Mail::to()`, y el mailer por defecto
     * es el que dice `mail.default`. Si una actualización de Laravel rompe esto, leer `failures()` dejaría de mirar el mailer que mandó el
     * mail y el helper devolvería vacío sobre un rechazo real, sin ningún aviso: este test es el que lo grita.
     *
     * @return void
     */
    public function test_el_mailer_sin_nombre_es_el_mismo_objeto_que_usa_mail_to(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->assertSame(Mail::mailer(), Mail::mailer(null));
        $this->assertSame(Mail::mailer('smtp'), Mail::mailer(null));
    }

    /**
     * El centro del cambio: sin nombre, el helper lee el mailer POR DEFECTO. Contra un SMTP de verdad que contesta 550, `send()` vuelve
     * normal y la casilla queda en `failures()`.
     *
     * @return void
     */
    public function test_del_ultimo_envio_sin_nombre_lee_el_mailer_por_defecto_contra_un_smtp_real(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->mandar_un_mail_de_prueba('no-existe@ejemplo.test');

        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio(null));
    }

    /**
     * Un mailer con nombre se lee por su nombre.
     *
     * @return void
     */
    public function test_del_ultimo_envio_con_nombre_lee_ese_mailer(): void
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA)->apuntar_el_mailer('otro');

        $this->mandar_un_mail_de_prueba('no-existe@ejemplo.test', 'otro');

        $this->assertSame(['no-existe@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio('otro'));
    }

    /**
     * Cada mailer tiene SU lista: un rechazo de un mailer no se ve leyendo el otro. Es la razón de que la lectura reciba el nombre en
     * vez de usar `Mail::failures()` a secas (que va siempre al mailer por defecto).
     *
     * @return void
     */
    public function test_los_rechazos_de_un_mailer_no_se_ven_desde_el_otro(): void
    {
        $this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA)->apuntar_el_mailer('otro');
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->mandar_un_mail_de_prueba('del-otro@ejemplo.test', 'otro');
        $this->mandar_un_mail_de_prueba('del-defecto@ejemplo.test');

        $this->assertSame(['del-otro@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio('otro'));
        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio(), 'El mailer por defecto aceptó la suya.');
    }

    /**
     * Contra un SMTP que acepta, no hay nada rechazado y `fallar_si_hubo_rechazos()` deja pasar. Sin este control, un arreglo que tirara
     * siempre pasaría el test del rechazo.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_acepta_no_hay_rechazos_y_no_tira(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->mandar_un_mail_de_prueba('destino@ejemplo.test');

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Contra un SMTP de verdad que contesta 550, `fallar_si_hubo_rechazos()` tira la excepción propia, con el motivo fijo y sin repetir la
     * casilla en el mensaje.
     *
     * @return void
     */
    public function test_con_un_smtp_real_que_contesta_550_tira_la_excepcion_sin_la_casilla(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->mandar_un_mail_de_prueba('dueno.privado@ejemplo.test');

        try {
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $this->fail('El servidor dijo 550: tenía que tirar MailRechazadoPorElServidorException.');
        } catch (MailRechazadoPorElServidorException $excepcion) {
            $this->assertSame(MailRechazadoPorElServidorException::MOTIVO, $excepcion->getMessage());
            $this->assertStringNotContainsString('dueno.privado@ejemplo.test', $excepcion->getMessage());
            $this->assertSame(['dueno.privado@ejemplo.test'], $excepcion->rechazadas());
        }
    }

    /**
     * El helper lee el ÚLTIMO envío: el mailer reinicia la lista en cada `send()`. Un envío que sale bien después de uno rechazado, POR EL
     * MISMO MAILER, no arrastra el rechazo viejo.
     *
     * 🔴 Tiene que ser el mismo objeto mailer (en admin-api se midió, el 6/10/2026, que un `Mail::purge()` entre los dos envíos
     * dejaba pasar un mailer NUEVO, con la lista vacía de fábrica, y el test daba verde sin probar el reinicio). Acá se le cambia el puerto al
     * transporte del MISMO mailer para que el segundo envío vaya al servidor que acepta, y se comprueba que la instancia sea la misma.
     *
     * @return void
     */
    public function test_un_envio_que_sale_bien_despues_de_uno_rechazado_no_arrastra_el_rechazo(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $mailer = Mail::mailer('smtp');

        $this->mandar_un_mail_de_prueba('uno@ejemplo.test');
        $this->assertSame(['uno@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());

        $acepta = $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);
        $mailer->getSwiftMailer()->getTransport()->setPort($acepta->puerto);

        $this->mandar_un_mail_de_prueba('dos@ejemplo.test');

        $this->assertSame($mailer, Mail::mailer('smtp'), 'Tiene que ser la MISMA instancia: lo que se prueba es el reinicio de la lista.');
        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio(), 'El envío que salió bien no hereda el rechazo del anterior.');
    }

    /**
     * Si `failures()` no se puede leer, el helper no inventa un rechazo (devuelve vacío) pero TAMPOCO se calla: deja un aviso en el log. Sin él,
     * un upgrade de Laravel o un typo que rompa la lectura devolvería todos los envíos a darse por enviados en silencio, que es justo el
     * defecto que el helper existe para evitar.
     *
     * @return void
     */
    public function test_si_no_se_puede_leer_failures_no_inventa_un_rechazo_pero_lo_deja_en_el_log(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andThrow(new \RuntimeException('el mailer no responde failures()'));

        Log::shouldReceive('warning')->once()->with(
            \Mockery::on(function ($mensaje) {
                return is_string($mensaje) && strpos($mensaje, 'no se pudo leer failures()') !== false;
            }),
            \Mockery::on(function ($contexto) {
                return is_array($contexto)
                    && $contexto['mailer'] === '(por defecto)'
                    && $contexto['error'] === 'el mailer no responde failures()';
            })
        );

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * El aviso de "no se pudo leer failures()" dice de QUÉ mailer se habla: con nombre, el nombre (con `null` dice "(por defecto)", ver el test de arriba).
     *
     * @return void
     */
    public function test_el_aviso_de_failures_ilegible_nombra_el_mailer_con_nombre(): void
    {
        Mail::shouldReceive('mailer')->with('otro')->andThrow(new \RuntimeException('el mailer otro no responde'));

        Log::shouldReceive('warning')->once()->with(
            \Mockery::on(function ($mensaje) {
                return is_string($mensaje) && strpos($mensaje, 'no se pudo leer failures()') !== false;
            }),
            \Mockery::on(function ($contexto) {
                return is_array($contexto) && $contexto['mailer'] === 'otro';
            })
        );

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio('otro'));
    }

    /**
     * 🔴 El aviso de "no se pudo leer" tampoco rompe el envío: si el logger también falla (disco lleno), el helper devuelve vacío en vez de tirar. Sin esa
     * protección la excepción del log escaparía hacia el punto de envío: en `ProcessSendAdviseMail` caería en su `catch` y dejaría pendiente un aviso cuyo mail
     * SÍ salió (el comprador lo recibiría duplicado).
     *
     * @return void
     */
    public function test_si_el_log_tambien_falla_el_helper_no_tira(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andThrow(new \RuntimeException('el mailer no responde failures()'));
        Log::shouldReceive('warning')->once()->andThrow(new \RuntimeException('el log no escribe'));

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * Si `failures()` devuelve algo que no es una lista (null, un texto), el helper no inventa un rechazo: devuelve vacío. Es la guarda `is_array()`.
     *
     * @dataProvider respuestas_que_no_son_una_lista
     *
     * @param mixed $respuesta Lo que contesta `failures()`.
     *
     * @return void
     */
    public function test_si_failures_no_devuelve_una_lista_no_inventa_un_rechazo($respuesta): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn($respuesta);

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        // Y `fallar_si_hubo_rechazos()` tampoco tira con esa respuesta.
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn($respuesta);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function respuestas_que_no_son_una_lista(): array
    {
        return [
            'null'                       => [null],
            'un texto'                   => ['rechazada@ejemplo.test'],
            'un número'                  => [1],
            'un booleano'                => [true],
        ];
    }

    /**
     * Un rechazo TEMPORAL (451, un greylisting) entra a `failures()` igual que un 550: con 4xx la casilla puede estar bien. Por eso el motivo dice "no existe,
     * está llena o no acepta mensajes por ahora" y no "la casilla está mal". Medido contra un servidor de verdad que contesta 451.
     *
     * @return void
     */
    public function test_un_rechazo_temporal_451_tambien_cuenta_como_rechazo(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_TEMPORAL);

        $this->mandar_un_mail_de_prueba('destino@ejemplo.test');

        $this->assertSame(['destino@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());

        $this->expectException(MailRechazadoPorElServidorException::class);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
    }

    /**
     * Un mail con un destinatario ACEPTADO y otro RECHAZADO: `failures()` lista solo al rechazado, y el helper lo cuenta como un envío con rechazos (tira). Es el
     * límite que el docblock del helper dice ("lista CUALQUIER destinatario rechazado, no solo el principal"): hoy ningún mailable de `app/Mail` agrega `cc` ni
     * `bcc`, así que "hubo rechazos" significa "el destinatario no recibió el mail"; el día que uno agregue una copia, esto marcaría como fallido un mail que SÍ
     * llegó al principal.
     *
     * @return void
     */
    public function test_un_rechazo_parcial_lista_solo_al_rechazado_y_cuenta_como_rechazo(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_PARCIAL);

        Mail::raw('Cuerpo de prueba.', function ($mensaje) {
            $mensaje->to(['aceptada@ejemplo.test', 'rechazada@ejemplo.test'])->subject('Mail con dos destinatarios');
        });

        $this->assertSame(['rechazada@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());

        $this->expectException(MailRechazadoPorElServidorException::class);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
    }

    /**
     * El rechazo parcial se ve sin importar el lugar del destinatario rechazado: primero en el `to`, en `cc` o en `bcc`. SwiftMailer junta en `failures()` lo que el
     * servidor rechazó en CADA `RCPT TO`, sin mirar a qué cabecera pertenece la casilla (el test de arriba cubre solo al rechazado en segundo lugar).
     * Medido contra un servidor de verdad.
     *
     * @dataProvider armados_con_un_destinatario_rechazado
     *
     * @param callable $armar Arma el mensaje con un destinatario aceptado y uno rechazado.
     *
     * @return void
     */
    public function test_un_rechazo_parcial_se_ve_sin_importar_el_orden_ni_la_cabecera(callable $armar): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_PARCIAL);

        Mail::raw('Cuerpo de prueba.', $armar);

        $this->assertSame(['rechazada@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * @return array<string, array<int, callable>>
     */
    public static function armados_con_un_destinatario_rechazado(): array
    {
        return [
            'el rechazado primero en el to' => [function ($mensaje) {
                $mensaje->to(['rechazada@ejemplo.test', 'aceptada@ejemplo.test'])->subject('Mail con dos destinatarios');
            }],
            'el rechazado en cc'            => [function ($mensaje) {
                $mensaje->to('aceptada@ejemplo.test')->cc('rechazada@ejemplo.test')->subject('Mail con una copia');
            }],
            'el rechazado en bcc'           => [function ($mensaje) {
                $mensaje->to('aceptada@ejemplo.test')->bcc('rechazada@ejemplo.test')->subject('Mail con una copia oculta');
            }],
        ];
    }

    /**
     * Sin repetidos, recortadas y sin lo que no es una casilla (vacíos, arrays, null): lo que devuelve es una lista limpia, en el orden en
     * que llegaron.
     *
     * @return void
     */
    public function test_la_lista_sale_sin_repetidos_recortada_y_sin_basura(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn([
            '  uno@ejemplo.test ',
            'uno@ejemplo.test',
            '',
            '   ',
            ['no-es-escalar@ejemplo.test'],
            null,
            'dos@ejemplo.test',
        ]);

        $this->assertSame(['uno@ejemplo.test', 'dos@ejemplo.test'], RechazosDeCorreoHelper::del_ultimo_envio());
    }

    /**
     * El mensaje de la excepción es SIEMPRE el motivo fijo, aunque haya varias casillas rechazadas, y las casillas quedan en `rechazadas()`
     * para quien las quiera loguear.
     *
     * @return void
     */
    public function test_la_excepcion_lleva_el_motivo_y_guarda_las_casillas_aparte(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn(['uno@ejemplo.test', 'dos@ejemplo.test']);

        try {
            RechazosDeCorreoHelper::fallar_si_hubo_rechazos();
            $this->fail('Tenía que tirar.');
        } catch (MailRechazadoPorElServidorException $excepcion) {
            $this->assertInstanceOf(\RuntimeException::class, $excepcion);
            $this->assertSame(MailRechazadoPorElServidorException::MOTIVO, $excepcion->getMessage());
            $this->assertStringNotContainsString('ejemplo.test', $excepcion->getMessage());
            $this->assertSame(['uno@ejemplo.test', 'dos@ejemplo.test'], $excepcion->rechazadas());
        }
    }

    /**
     * El motivo dice qué pasó, sin jerga de SMTP, y NO afirma que la casilla "no existe": un 451 de greylisting o un 452 de buzón lleno también
     * entran a `failures()`. No habla de una "ficha" (eso es del admin): cada punto de envío le agrega su propio consejo.
     *
     * @return void
     */
    public function test_el_motivo_dice_que_paso_sin_afirmar_de_mas(): void
    {
        $this->assertStringContainsString('el servidor de correo rechazó la casilla', MailRechazadoPorElServidorException::MOTIVO);
        $this->assertStringContainsString('no existe, está llena o no acepta mensajes por ahora', MailRechazadoPorElServidorException::MOTIVO);
        $this->assertStringNotContainsString('ficha', MailRechazadoPorElServidorException::MOTIVO);
    }

    /**
     * Con `failures()` vacío no tira.
     *
     * @return void
     */
    public function test_no_tira_si_el_mailer_no_rechazo_nada(): void
    {
        Mail::shouldReceive('mailer')->with(null)->andReturnSelf();
        Mail::shouldReceive('failures')->once()->andReturn([]);

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * Bajo `Mail::fake()` el falso también responde `failures()` y devuelve vacío: los tests que verifican "qué mail sale y a quién" con el
     * fake no tienen que cambiar.
     *
     * @return void
     */
    public function test_no_tira_bajo_mail_fake(): void
    {
        Mail::fake();

        $this->mandar_un_mail_de_prueba('destino@ejemplo.test');

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());

        RechazosDeCorreoHelper::fallar_si_hubo_rechazos();

        $this->addToAssertionCount(1);
    }

    /**
     * 🔴 Con el transporte en memoria del freno de internet (`ArrayTransport`, el de TODOS los demás tests) tampoco hay rechazos: un mail "sale"
     * y `failures()` queda vacío. Documenta por qué los tests de esta misión tienen que restaurar el `smtp` de verdad: con el de memoria el
     * defecto no se puede ver.
     *
     * @return void
     */
    public function test_con_el_transporte_en_memoria_de_los_tests_el_servidor_nunca_rechaza(): void
    {
        $this->mandar_un_mail_de_prueba('destino@ejemplo.test');

        $this->assertSame([], RechazosDeCorreoHelper::del_ultimo_envio());
    }
}
