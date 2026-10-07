<?php

namespace Tests\Feature\MailsRechazados;

use App\Mail\ClientePotencial;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El mail a un cliente potencial (`GET /cliente-potencial/{nombre_negocio}/{email}`).
 *
 * Antes: con un 550 en el RCPT TO el endpoint igual escribía "Correo enviado". Es una ruta que se usa a mano (desde el navegador): quien la
 * abre lee ese texto y da el mail por mandado.
 *
 * Contrato: el éxito NO cambia: el controlador hace `echo 'Correo enviado'` y devuelve una respuesta vacía (200), así que el texto sale por la
 * salida del proceso y NO por el cuerpo de la respuesta: por eso los tests del éxito lo miran con `expectOutputString()` y no con `getContent()`.
 * El rechazo es una respuesta nueva (422 con un texto que dice que no salió): no hay
 * ningún consumidor en `empresa-spa` ni en `tienda-*` (`git grep` sobre `origin/develop` y `origin/master`: 0 archivos), así que no hay una
 * forma vieja que mantener.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ClientePotencialRechazadoTest extends MailsRechazadosTestCase
{
    /**
     * @param string $casilla
     *
     * @return string
     */
    private function url(string $casilla): string
    {
        return '/cliente-potencial/' . rawurlencode('Ferreteria de prueba') . '/' . $casilla;
    }

    /**
     * El centro del cambio: contra un SMTP de verdad que contesta 550, la respuesta dice que NO salió, y no "Correo enviado".
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_la_respuesta_dice_que_no_salio(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        // Lo que sale por la salida del proceso (el `echo` del éxito) tiene que estar vacío.
        $this->expectOutputString('');

        $respuesta = $this->get($this->url($this->una_casilla('potencial')));

        $respuesta->assertStatus(422);
        $this->assertStringNotContainsString('Correo enviado', $respuesta->getContent());
        $this->assertStringContainsString('No se pudo enviar el correo', $respuesta->getContent());
        $this->assertStringContainsString('rechazó la casilla', $respuesta->getContent());
    }

    /**
     * La respuesta no repite la casilla que se pasó por la URL.
     *
     * @return void
     */
    public function test_la_respuesta_de_error_no_repite_la_casilla(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->expectOutputString('');

        $casilla   = $this->una_casilla('potencial');
        $respuesta = $this->get($this->url($casilla));

        // Precondición: tiene que ser la respuesta del rechazo (sin ella, esta aserción pasaría también con un 500 o con "Correo enviado").
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('No se pudo enviar el correo', $respuesta->getContent());

        $this->assertStringNotContainsString($casilla, $respuesta->getContent());
    }

    /**
     * Control: contra un servidor que ACEPTA todo, la respuesta es la de siempre.
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_dice_correo_enviado_como_siempre(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->expectOutputString('Correo enviado');

        $respuesta = $this->get($this->url($this->una_casilla('potencial')));

        $respuesta->assertStatus(200);
    }

    /**
     * Lo que se manda y a quién NO cambia: UN mailable `ClientePotencial`, a la casilla de la URL. (Con `Mail::fake()`.)
     *
     * @return void
     */
    public function test_lo_que_se_manda_y_a_quien_no_cambia(): void
    {
        Mail::fake();

        $this->expectOutputString('Correo enviado');

        $casilla   = $this->una_casilla('potencial');
        $respuesta = $this->get($this->url($casilla));

        $respuesta->assertStatus(200);

        Mail::assertSent(ClientePotencial::class, 1);
        Mail::assertSent(ClientePotencial::class, function ($mail) use ($casilla) {
            return $mail->hasTo($casilla) && $mail->nombre_negocio === 'Ferreteria de prueba';
        });
    }
}
