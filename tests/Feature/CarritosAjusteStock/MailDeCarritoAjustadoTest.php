<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Models\Article;
use App\Models\ArticleVariant;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\MailsRechazados\MailsRechazadosTestCase;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Misión `carritos-ajuste-stock` — CA10: el mail que recibe el comprador cuando le ajustan el carrito.
 *
 * Lo manda el job `App\Jobs\ProcessSendCartAdjustedMail` (calcado de `ProcessSendAdviseMail`) con el mailable
 * `App\Mail\CartAdjusted`. Acá se prueba el job llamando `->handle()` con `Mail::fake()` (con `afterCommit` el job
 * no corre dentro de la transacción del test), y el rechazo del servidor contra un SMTP de verdad que contesta 550
 * (patrón de `AvisoDeStockRechazadoTest`).
 *
 * CONTRATO DEL JOB (el mismo que fija `AjusteDeCarritosPorStockTest`):
 *   new ProcessSendCartAdjustedMail($owner_id, $buyer_email, $buyer_name, $article_id, $variant_id, $type,
 *                                   $requested_amount, $new_amount)
 * `$type` = 'recortado' | 'restaurado'; X = `$requested_amount`; Y = `$new_amount`.
 *
 * El flujo "el ajuste despacha o no el job" (opción apagada, comprador sin mail, invitado) está en
 * `AjusteDeCarritosPorStockTest`. El detector `TodoEnvioDeMailMiraLosRechazosTest` se corre sin editarlo.
 *
 * Falla hoy porque ni el job ni el mailable existen todavía (rojo por clase inexistente en cada test, son tests
 * de una clase nueva y no hay otro comportamiento observable que comprobar antes de que exista).
 *
 * PHP 7.4 estricto: nada de sintaxis de PHP 8.
 *
 * @group tienda
 */
class MailDeCarritoAjustadoTest extends MailsRechazadosTestCase
{
    /** Clase del job (como string: todavía no existe antes de la construcción). */
    const JOB = 'App\Jobs\ProcessSendCartAdjustedMail';

    /** Clase del mailable. */
    const MAILABLE = 'App\Mail\CartAdjusted';

    /** @var User Dueño del comercio de prueba. */
    private $dueno;

    /** @var Article Artículo del carrito. */
    private $articulo;

    /**
     * Un comercio con su configuración online (columnas nuevas en su default) y un artículo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_un_dueno();

        OnlineConfiguration::create(['user_id' => $this->dueno->id]);

        $this->articulo = Article::create(['name' => 'zz-Shampoo-reparador-' . uniqid(), 'user_id' => $this->dueno->id]);
    }

    /**
     * Arma el job con los datos planos del contrato.
     *
     * @param string $tipo 'recortado' | 'restaurado'.
     * @param float $pedida X.
     * @param float $nueva Y.
     * @param string|null $email Mail del comprador (null = una casilla válida generada).
     * @param int|null $variant_id Variante del renglón.
     * @return object El job.
     */
    private function armar_job($tipo, $pedida, $nueva, $email = null, $variant_id = null)
    {
        $this->assertTrue(class_exists(self::JOB), 'Falta la clase ' . self::JOB . ' (la crea la construcción).');

        $clase = self::JOB;

        return new $clase(
            $this->dueno->id,
            is_null($email) ? $this->una_casilla('comprador') : $email,
            'Lucía Compradora',
            $this->articulo->id,
            $variant_id,
            $tipo,
            $pedida,
            $nueva
        );
    }


    /**
     * Corre el job con el mailer `array` (guarda en memoria lo que "envía", como los tests de Advise) y devuelve
     * el mensaje que salió. Con `Mail::fake()` no se puede leer el cuerpo: el mailable falso no renderiza.
     *
     * @param object $job El job armado con `armar_job()`.
     * @return \Swift_Message El mensaje enviado (el último del transporte).
     */
    private function correr_y_leer_el_mensaje($job)
    {
        /** Transporte en memoria del mailer `array`. */
        $transporte = Mail::mailer('array')->getSwiftMailer()->getTransport();

        $antes = $transporte->messages()->count();

        $job->handle();

        $this->assertEquals($antes + 1, $transporte->messages()->count(), 'El job tenía que mandar exactamente un mail.');

        return $transporte->messages()->last();
    }

    /**
     * Recorta: el mail llega a la casilla del comprador, con el asunto y el texto del recorte.
     *
     * @test
     */
    public function test_mail_de_carrito_recortado()
    {
        $mensaje = $this->correr_y_leer_el_mensaje($this->armar_job('recortado', 5, 2, 'lucia@ejemplo.test'));

        $this->assertArrayHasKey('lucia@ejemplo.test', $mensaje->getTo(), 'El mail va a la casilla del comprador.');
        $this->assertStringContainsString('Actualizamos tu carrito', (string) $mensaje->getSubject());

        $html = $mensaje->getBody();

        $this->assertStringContainsString('Agregaste 5 unidades', $html);
        $this->assertStringContainsString('ahora tu carrito tiene 2', $html);
        $this->assertStringContainsString($this->articulo->name, $html);
        $this->assertStringContainsString('Completar mi carrito cuando vuelva a haber stock', $html);
    }

    /**
     * Restaura: asunto "Volvió el stock de {artículo}" y el texto "vuelve a tener 5 unidades".
     *
     * @test
     */
    public function test_mail_de_carrito_restaurado()
    {
        $mensaje = $this->correr_y_leer_el_mensaje($this->armar_job('restaurado', 5, 5, 'lucia@ejemplo.test'));

        $this->assertArrayHasKey('lucia@ejemplo.test', $mensaje->getTo());
        $this->assertStringContainsString('Volvió el stock de ' . $this->articulo->name, (string) $mensaje->getSubject());
        $this->assertStringContainsString('vuelve a tener 5 unidades', $mensaje->getBody());
    }

    /**
     * El mail nombra la variante del renglón (el comprador tiene que saber de cuál se trata).
     *
     * @test
     */
    public function test_el_mail_nombra_la_variante()
    {
        $variante = ArticleVariant::create(['article_id' => $this->articulo->id, 'variant_description' => 'Tamaño 500 ml', 'stock' => 1, 'oculta' => false]);

        $mensaje = $this->correr_y_leer_el_mensaje($this->armar_job('recortado', 3, 1, null, $variante->id));

        $this->assertStringContainsString('Tamaño 500 ml', $mensaje->getBody());
    }

    /**
     * El job revalida el gate por cliente: con "Avisar al comprador por mail" apagado no manda nada.
     *
     * @test
     */
    public function test_con_el_aviso_apagado_el_job_no_manda_nada()
    {
        Mail::fake();

        $job = $this->armar_job('recortado', 5, 2);

        OnlineConfiguration::where('user_id', $this->dueno->id)->update(['avisar_ajuste_carrito_por_mail' => 0]);

        $job->handle();

        Mail::assertNothingSent();
    }

    /**
     * El job revalida el mail: una casilla inválida no manda nada ni tira.
     *
     * @test
     */
    public function test_con_un_email_invalido_el_job_no_manda_nada()
    {
        Mail::fake();

        $this->armar_job('recortado', 5, 2, 'esto-no-es-un-mail')->handle();

        Mail::assertNothingSent();
    }

    /**
     * Un servidor que rechaza la casilla (550 en el RCPT TO) no rompe la operación de stock: `handle()` no lanza
     * (con `QUEUE_CONNECTION=sync` correría dentro del request que mueve el stock) y el motivo queda en el log.
     *
     * @test
     */
    public function test_el_rechazo_del_servidor_no_rompe_y_queda_en_el_log()
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        Log::spy();

        $this->armar_job('recortado', 5, 2)->handle();

        Log::shouldHaveReceived('error')->withArgs(function ($mensaje, $contexto = []) {
            return strpos((string) $mensaje, 'ProcessSendCartAdjustedMail') !== false
                && isset($contexto['error'])
                && strpos((string) $contexto['error'], 'rechazó la casilla') !== false;
        });
    }

    /**
     * Control: contra un servidor que acepta todo el job termina sin error en el log.
     *
     * @test
     */
    public function test_con_un_smtp_que_acepta_no_se_registra_ningun_error()
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        Log::spy();

        $this->armar_job('restaurado', 5, 5)->handle();

        Log::shouldNotHaveReceived('error');
    }
}
