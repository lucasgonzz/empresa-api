<?php

namespace Tests\Feature\MailsRechazados;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Models\OnlineConfiguration;
use App\Models\User;
use App\Services\Mail\PruebaDeCorreoService;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El botón "Enviar correo de prueba" de la configuración online (`POST /api/online-configuration/test-mail`).
 *
 * Existe para que el dueño valide su remitente efectivo. Antes: si el servidor rechazaba la casilla de destino (550),
 * el endpoint contestaba 200 "Mail de prueba enviado correctamente" y el SPA mostraba "Correo de prueba enviado
 * correctamente" sobre un mail que nunca salió: el botón decía que todo andaba justo cuando no.
 *
 * Contrato (compatible hacia atrás): el endpoint YA tiene una forma de error, 422 con `{message}`, y `TestMailButton.vue` la
 * muestra tal cual (`err.response.data.message`). El rechazo usa esa misma forma: un SPA viejo anda igual.
 *
 * El destino de la prueba lo elige el dueño y la casilla SMTP puede ser la suya (`online_configurations`, no el `.env`): este test arma
 * esa configuración apuntando al servidor de prueba. El servicio construye el remitente efectivo en un contenedor privado:
 * prueba la propia si está habilitada y completa, o el respaldo capturado al arrancar la aplicación.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ProbarSmtpRechazadoTest extends MailsRechazadosTestCase
{
    const URL = 'api/online-configuration/test-mail';

    /** @var User */
    private $dueno;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_un_dueno();

        $this->actingAs($this->dueno, 'web');
    }

    /**
     * Deja la casilla propia del comercio (la que el dueño carga en "Configuración online") apuntando al servidor de prueba, y le
     * devuelve a la aplicación el transporte `smtp` de verdad: la aplicación misma arma el mailer, a partir de esta fila.
     *
     * @param ServidorSmtpFake $smtp
     *
     * @return void
     */
    private function configurar_la_casilla_del_comercio(ServidorSmtpFake $smtp): void
    {
        ServidorSmtpFake::restaurar_el_transporte_smtp();

        OnlineConfiguration::create([
            'user_id'         => $this->dueno->id,
            'mail_enabled'    => true,
            'mail_host'       => '127.0.0.1',
            'mail_port'       => $smtp->puerto,
            'mail_encryption' => null,
            'mail_username'   => 'casilla@ejemplo.test',
            'mail_password'   => 'clave-de-prueba',
        ]);
    }

    /**
     * El centro del cambio: contra un SMTP de verdad que contesta 550, la respuesta es el error de siempre (422 con `message`),
     * con el motivo, y NO "enviado correctamente".
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_de_destino_la_respuesta_es_un_error_y_no_enviado(): void
    {
        $this->configurar_la_casilla_del_comercio($this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA));

        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);

        $respuesta->assertStatus(422);

        $mensaje = (string) $respuesta->json('message');

        $this->assertStringNotContainsString('enviado correctamente', $mensaje, 'El servidor rechazó la casilla: el mail de prueba NO salió.');
        $this->assertStringContainsString('Error al enviar el mail de prueba', $mensaje);
        // El motivo sale de la excepción (una sola fuente: si cambia el texto, cambia en los dos lados).
        $this->assertStringContainsString(MailRechazadoPorElServidorException::MOTIVO, $mensaje);
        $this->assertStringContainsString('revisá la configuración', $mensaje, 'Es un botón para validar la configuración: tiene que decir qué mirar.');
    }

    /**
     * El mensaje no repite la casilla de destino (la acaba de tipear quien lo lee).
     *
     * @return void
     */
    public function test_el_mensaje_de_error_no_repite_la_casilla_de_destino(): void
    {
        $this->configurar_la_casilla_del_comercio($this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA));

        $destino   = $this->una_casilla('prueba');
        $respuesta = $this->postJson(self::URL, ['email_destino' => $destino]);

        // Precondición: tiene que ser la respuesta del rechazo (sin ella, esta aserción pasaría también con un 500 o con "enviado correctamente").
        $respuesta->assertStatus(422);
        $this->assertStringContainsString(MailRechazadoPorElServidorException::MOTIVO, (string) $respuesta->json('message'));

        $this->assertStringNotContainsString($destino, $respuesta->getContent());
    }

    /**
     * Control: contra un servidor que ACEPTA todo, el botón contesta lo de siempre. Sin este control, un arreglo que
     * contestara siempre 422 pasaría el test del rechazo.
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_el_mail_de_prueba_sale_como_siempre(): void
    {
        $this->configurar_la_casilla_del_comercio($this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA));

        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['message' => 'Mail de prueba enviado correctamente']);
    }

    private function configurar_respaldo(ServidorSmtpFake $smtp): array
    {
        $smtp->apuntar_el_mailer('smtp');
        $base = config('mail');
        $base['default'] = 'smtp';
        $base['from'] = ['address' => 'predeterminado@ejemplo.test', 'name' => 'ComercioCity'];
        app()->instance(PruebaDeCorreoService::class, new PruebaDeCorreoService($base));
        return $base;
    }

    public function alternativas_sin_casilla_propia(): array
    {
        return [
            'ausente' => [null],
            'desactivada' => [['mail_enabled' => false, 'mail_host' => 'ajeno.test', 'mail_username' => 'ajeno@ejemplo.test', 'mail_password' => 'secreto-ajeno']],
            'sin host' => [['mail_enabled' => true, 'mail_username' => 'ajeno@ejemplo.test', 'mail_password' => 'secreto-ajeno']],
            'sin usuario' => [['mail_enabled' => true, 'mail_host' => 'ajeno.test', 'mail_password' => 'secreto-ajeno']],
            'sin clave' => [['mail_enabled' => true, 'mail_host' => 'ajeno.test', 'mail_username' => 'ajeno@ejemplo.test']],
        ];
    }

    /** @dataProvider alternativas_sin_casilla_propia */
    public function test_el_respaldo_envia_sin_heredar_la_configuracion_runtime_ni_el_mailer_cacheado($atributos): void
    {
        $this->configurar_respaldo($this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA));
        if ($atributos !== null) {
            OnlineConfiguration::create(array_merge(['user_id' => $this->dueno->id], $atributos));
        }
        config(['mail.default' => 'array', 'mail.from.address' => 'otro-negocio@ejemplo.test']);
        $cacheado = Mail::mailer('array');
        $mensajes = [];
        app('events')->listen(MessageSent::class, function ($evento) use (&$mensajes) {
            $mensajes[] = $evento->message;
        });
        $antes = config('mail');
        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);
        $respuesta->assertStatus(200)->assertJson([
            'message' => 'Mail de prueba enviado correctamente',
            'origen' => 'comerciocity',
            'remitente' => 'predeterminado@ejemplo.test',
        ]);
        $this->assertCount(1, $mensajes);
        $this->assertSame(['predeterminado@ejemplo.test' => 'ComercioCity'], $mensajes[0]->getFrom());
        $this->assertSame($antes, config('mail'));
        $this->assertSame($cacheado, Mail::mailer('array'));
        $this->assertStringNotContainsString('secreto-ajeno', $respuesta->getContent());
    }

    public function test_la_casilla_propia_tiene_prioridad_y_no_acepta_otro_negocio_por_request(): void
    {
        $this->configurar_respaldo($this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA));
        $this->configurar_la_casilla_del_comercio($this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA));
        $otro = $this->crear_un_dueno();
        OnlineConfiguration::create(['user_id' => $otro->id, 'mail_enabled' => true,
            'mail_host' => 'ajeno.test', 'mail_username' => 'otro@ejemplo.test', 'mail_password' => 'clave-ajena']);
        $mensajes = [];
        app('events')->listen(MessageSent::class, function ($evento) use (&$mensajes) { $mensajes[] = $evento->message; });
        $antes = config('mail');
        $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba'), 'user_id' => $otro->id])
            ->assertStatus(200)->assertJson(['origen' => 'propia', 'remitente' => 'casilla@ejemplo.test']);
        $this->assertCount(1, $mensajes);
        $this->assertSame(['casilla@ejemplo.test' => $this->dueno->company_name], $mensajes[0]->getFrom());
        $this->assertSame($antes, config('mail'));
    }

    public function test_el_rechazo_del_respaldo_es_error_y_no_cambia_el_correo_runtime(): void
    {
        $this->configurar_respaldo($this->levantar_un_smtp(ServidorSmtpFake::MODO_RECHAZA));
        config(['mail.from.address' => 'runtime@ejemplo.test']);
        $antes = config('mail');
        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);
        $respuesta->assertStatus(422)->assertJson(['origen' => 'comerciocity']);
        $this->assertStringContainsString(MailRechazadoPorElServidorException::MOTIVO, $respuesta->json('message'));
        $this->assertStringContainsString('contactá a ComercioCity', $respuesta->json('message'));
        $this->assertSame($antes, config('mail'));
    }

    public function test_un_transporte_inaccesible_falla_sin_revelar_datos_internos(): void
    {
        $smtp = $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);
        $this->configurar_respaldo($smtp);
        $smtp->bajar();
        $antes = config('mail');
        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);
        $respuesta->assertStatus(422)->assertJson(['origen' => 'comerciocity']);
        $this->assertStringContainsString('Error al enviar el mail de prueba', $respuesta->json('message'));
        $this->assertStringNotContainsString('127.0.0.1', $respuesta->getContent());
        $this->assertStringNotContainsString('clave-de-prueba', $respuesta->getContent());
        $this->assertSame($antes, config('mail'));
    }

    public function test_un_transporte_sin_envio_no_afirma_que_se_envio_el_correo(): void
    {
        $base = config('mail');
        $base['default'] = 'array';
        app()->instance(PruebaDeCorreoService::class, new PruebaDeCorreoService($base));
        $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')])
            ->assertStatus(422)->assertJson(['origen' => 'comerciocity',
                'message' => 'El correo de prueba no se envió: el envío de correo está deshabilitado en este entorno.']);
    }

    public function test_el_provider_captura_la_base_antes_de_cualquier_cambio_runtime(): void
    {
        $servicio = app(PruebaDeCorreoService::class);
        $base = $servicio->configuracion($this->dueno->id);
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'otro-negocio.test',
            'mail.from.address' => 'ajeno@ejemplo.test']);
        $this->assertSame($base, $servicio->configuracion($this->dueno->id));
        $this->assertNotSame('otro-negocio.test', $base['mail']['mailers']['smtp']['host']);
    }

    public function test_un_listener_que_cancela_el_envio_no_se_informa_como_enviado(): void
    {
        $this->configurar_respaldo($this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA));
        app('events')->listen(MessageSending::class, function () { return false; });
        $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')])
            ->assertStatus(422)->assertJson(['origen' => 'comerciocity']);
    }

    public function test_informa_el_remitente_efectivo_del_mailer_y_no_resuelve_el_default_global(): void
    {
        $base = $this->configurar_respaldo($this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA));
        $base['mailers']['smtp']['from'] = ['address' => 'mailer@ejemplo.test', 'name' => 'Remitente efectivo'];
        app()->instance(PruebaDeCorreoService::class, new PruebaDeCorreoService($base));
        // Si el listener leyera la fachada global, resolvería este mailer inexistente y anotaría el fallo.
        config(['mail.default' => 'no_definido']);
        Log::spy();
        $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')])
            ->assertStatus(200)->assertJson(['remitente' => 'mailer@ejemplo.test']);
        Log::shouldNotHaveReceived('warning');
        $this->assertSame('no_definido', config('mail.default'));
    }

    public function test_un_failover_que_termina_en_log_no_informa_un_envio(): void
    {
        $smtp = $this->levantar_un_smtp(ServidorSmtpFake::MODO_ACEPTA);
        $base = $this->configurar_respaldo($smtp);
        $smtp->bajar();
        $base['default'] = 'failover';
        $base['mailers']['failover'] = ['transport' => 'failover', 'mailers' => ['smtp', 'log']];
        app()->instance(PruebaDeCorreoService::class, new PruebaDeCorreoService($base));
        $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')])
            ->assertStatus(422)->assertJson(['origen' => 'comerciocity']);
    }

    /**
     * Y la validación del formato del destino, que también está antes del envío.
     *
     * @return void
     */
    public function test_un_destino_mal_escrito_sigue_dando_422_de_validacion(): void
    {
        $respuesta = $this->postJson(self::URL, ['email_destino' => 'esto-no-es-un-mail']);

        $respuesta->assertStatus(422);
        $this->assertNotEmpty($respuesta->json('message'));
    }
}
