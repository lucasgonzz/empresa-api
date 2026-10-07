<?php

namespace Tests\Feature\MailsRechazados;

use App\Exceptions\MailRechazadoPorElServidorException;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El botón "Enviar correo de prueba" de la configuración online (`POST /api/online-configuration/test-mail`).
 *
 * Existe para que el dueño valide su casilla SMTP propia. Antes: si el servidor rechazaba la casilla de destino (550),
 * el endpoint contestaba 200 "Mail de prueba enviado correctamente" y el SPA mostraba "Correo de prueba enviado
 * correctamente" sobre un mail que nunca salió: el botón decía que todo andaba justo cuando no.
 *
 * Contrato (compatible hacia atrás): el endpoint YA tiene una forma de error, 422 con `{message}`, y `TestMailButton.vue` la
 * muestra tal cual (`err.response.data.message`). El rechazo usa esa misma forma: un SPA viejo anda igual.
 *
 * El destino de la prueba lo elige el dueño y la casilla SMTP es la suya (`online_configurations`, no el `.env`): este test arma
 * esa configuración apuntando al servidor de prueba, y la aplicación la aplica en caliente con `ClientMailConfigHelper::apply()`
 * como en producción.
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

    /**
     * Lo de antes del envío no cambia: sin una configuración activa y completa no hay nada que probar (422, y ni se abre un socket).
     *
     * @return void
     */
    public function test_sin_configuracion_de_correo_sigue_diciendo_que_no_hay_nada_que_probar(): void
    {
        $respuesta = $this->postJson(self::URL, ['email_destino' => $this->una_casilla('prueba')]);

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['message' => 'No hay una configuración de correo activa y completa para probar']);
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
