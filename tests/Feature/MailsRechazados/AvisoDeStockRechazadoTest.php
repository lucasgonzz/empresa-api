<?php

namespace Tests\Feature\MailsRechazados;

use App\Jobs\ProcessSendAdviseMail;
use App\Models\Advise;
use App\Models\Article;
use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * El aviso por mail de "ingresó stock" a un comprador (`ProcessSendAdviseMail`, el job que despacha `ArticleHelper::checkAdvises`).
 *
 * Antes: el job manda el mail y recién después BORRA el aviso (`$this->advise->delete()`). Con un 550 en el RCPT TO, SwiftMailer no
 * tira, `send()` vuelve normal y el job borraba el aviso: el comprador perdía su "avisame cuando haya stock" sin haber recibido nada y
 * sin que nadie lo supiera.
 *
 * Ahora el rechazo es una excepción que atrapa el `catch (\Exception)` que el job ya tiene: el aviso NO se borra y queda pendiente
 * para el próximo ingreso de stock (lo mismo que ya pasa ante cualquier otro fallo del envío).
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class AvisoDeStockRechazadoTest extends MailsRechazadosTestCase
{
    /** @var User */
    private $dueno;

    /** @var Article */
    private $articulo;

    /** @var Advise */
    private $aviso;

    /**
     * El comercio con los avisos por mail encendidos, un artículo y un comprador que pidió que le avisen.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_un_dueno();

        // El gate por cliente (`MailNotificationConfigHelper::avisarIngresoStock`). Sin casilla propia cargada: el mail sale por el mailer por defecto.
        OnlineConfiguration::create(['user_id' => $this->dueno->id, 'avisar_ingreso_stock_por_mail' => 1]);

        $this->articulo = Article::create(['name' => 'zz-articulo-con-aviso-' . uniqid(), 'user_id' => $this->dueno->id]);

        $this->aviso = Advise::create(['article_id' => $this->articulo->id, 'email' => $this->una_casilla('comprador')]);
    }

    /**
     * Corre el job como lo corre la cola (`QUEUE_CONNECTION=sync` en los tests).
     *
     * @return void
     */
    private function correr_el_job(): void
    {
        (new ProcessSendAdviseMail($this->aviso, $this->articulo))->handle();
    }

    /**
     * El centro del cambio: contra un SMTP de verdad que contesta 550, el aviso NO se borra.
     *
     * @return void
     */
    public function test_si_el_servidor_rechaza_la_casilla_el_aviso_no_se_borra(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $this->correr_el_job();

        $this->assertNotNull(
            Advise::find($this->aviso->id),
            'El servidor rechazó la casilla: el comprador NO recibió el aviso y no se puede borrar su pedido.'
        );
    }

    /**
     * El job sigue sin tirar: con `QUEUE_CONNECTION=sync` corre dentro del request que ingresa el stock, y una excepción acá rompería
     * esa operación (es el bug raíz que el propio job documenta). Y deja el motivo en el log, que es lo que se busca cuando un
     * comprador dice "nunca me avisaron".
     *
     * @return void
     */
    public function test_el_rechazo_no_rompe_la_operacion_de_stock_y_queda_en_el_log(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        Log::spy();

        $this->correr_el_job();

        Log::shouldHaveReceived('error')->withArgs(function ($mensaje, $contexto = []) {
            return strpos((string) $mensaje, 'error enviando mail, advise queda pendiente') !== false
                && isset($contexto['error'])
                && strpos((string) $contexto['error'], 'rechazó la casilla') !== false;
        });
    }

    /**
     * Control: contra un servidor que ACEPTA todo, el aviso se manda y se borra, como siempre. Sin este control, un arreglo que dejara
     * todos los avisos pendientes para siempre pasaría el test del rechazo.
     *
     * @return void
     */
    public function test_con_un_smtp_que_acepta_el_aviso_se_manda_y_se_borra_como_siempre(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);

        $this->correr_el_job();

        $this->assertNull(Advise::find($this->aviso->id), 'El aviso salió: se borra.');
    }

    /**
     * Lo que se manda y a quién NO cambia: UN mailable `Advise`, a la casilla del comprador. (Con `Mail::fake()`.)
     *
     * @return void
     */
    public function test_lo_que_se_manda_y_a_quien_no_cambia(): void
    {
        Mail::fake();

        $this->correr_el_job();

        Mail::assertSent(\App\Mail\Advise::class, 1);
        Mail::assertSent(\App\Mail\Advise::class, function ($mail) {
            return $mail->hasTo($this->aviso->email);
        });
        $this->assertNull(Advise::find($this->aviso->id));
    }

    /**
     * Lo que no cambia ante un aviso con la casilla mal escrita: se borra sin intentar mandar nada (la regla vieja de `filter_var`),
     * y no se toca ningún servidor.
     *
     * @return void
     */
    public function test_un_aviso_con_la_casilla_mal_escrita_sigue_borrandose_sin_mandar_nada(): void
    {
        Mail::fake();

        $this->aviso->update(['email' => 'esto-no-es-un-mail']);

        $this->correr_el_job();

        Mail::assertNothingSent();
        $this->assertNull(Advise::find($this->aviso->id));
    }
}
