<?php

namespace Tests\Feature\MailsRechazados;

use App\Http\Controllers\Helpers\ComercioCityMailHelper;
use App\Mail\ComercioCityMail;
use App\Models\Article;
use App\Models\Client;
use App\Models\ClientOffer;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Los dos `->queue()` de `ComercioCityMailHelper`: el mail de una venta (`new_sale`) y el aviso de una oferta (`nueva_oferta`).
 *
 * Un mail ENCOLADO se manda en un worker: el rechazo del servidor ocurre ahí y NO se puede leer en el punto de llamada. La decisión
 * es dejar el `->queue()` exactamente como está y NO tirar en el worker, por dos motivos medidos:
 *
 *  - con `QUEUE_CONNECTION=sync` (estos tests y cualquier instalación con cola `sync`) una excepción del job VUELVE al request que encoló: un 500 sobre una
 *    venta que ya hizo commit (`SaleController:582`);
 *  - un job que tira pasa por `Handler::report()` y de ahí a `GitHubErrorReporterService`: cada casilla mal tipeada de un cliente sería
 *    un "error" más en el triaje de `errores/`.
 *
 * Lo que sí queda: el listener de `MessageSent` deja un `Log::warning` verdadero. Hasta ahora el único rastro era `Log::info('Se mando mail a …')`,
 * que se escribe al ENCOLAR, no dice si salió y, con el piso `warning` de `config/logging.php`, ni siquiera se escribe en las instalaciones sin `LOG_LEVEL`.
 *
 * Con `QUEUE_CONNECTION=sync` el worker corre en línea: estos tests ejercitan el camino real del mail encolado contra el servidor de prueba.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class MailsEncoladosRechazadosTest extends MailsRechazadosTestCase
{
    /** @var User */
    private $dueno;

    /** @var Client */
    private $cliente;

    /**
     * Un comercio con un cliente que tiene mail.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_un_dueno();

        $this->cliente = Client::create([
            'name'    => 'Cliente de prueba ' . uniqid(),
            'user_id' => $this->dueno->id,
            'email'   => $this->una_casilla('cliente'),
        ]);
    }

    /**
     * Una venta con el envío de mail pedido, de ese cliente.
     *
     * @return Sale
     */
    private function una_venta(): Sale
    {
        return Sale::create([
            'user_id'   => $this->dueno->id,
            'client_id' => $this->cliente->id,
            'send_mail' => 1,
            'total'     => 1000,
        ]);
    }

    /**
     * Una oferta activa para ese cliente.
     *
     * @return ClientOffer
     */
    private function una_oferta(): ClientOffer
    {
        $articulo = Article::create(['name' => 'zz-articulo-de-oferta-' . uniqid(), 'user_id' => $this->dueno->id]);

        return ClientOffer::create([
            'user_id'        => $this->dueno->id,
            'client_id'      => $this->cliente->id,
            'article_id'     => $articulo->id,
            'tipo_descuento' => 'unidad',
            'porcentaje'     => 10,
            'estado'         => 'activa',
            'desde'          => Carbon::today()->toDateString(),
            'hasta'          => Carbon::today()->addDays(10)->toDateString(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------------
    //  new_sale
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Con un 550 en el worker: el llamador NO se entera (no tira, la venta ya está guardada) y el rechazo queda en el log.
     *
     * @return void
     */
    public function test_el_mail_de_una_venta_rechazado_en_el_worker_no_tira_y_queda_en_el_log(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
        $venta = $this->una_venta();

        Log::spy();

        ComercioCityMailHelper::new_sale($venta);

        Log::shouldHaveReceived('warning')->withArgs(function ($texto) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false;
        });
    }

    /**
     * Control: contra un servidor que ACEPTA todo, el mail de la venta sale sin dejar ningún warning.
     *
     * @return void
     */
    public function test_el_mail_de_una_venta_aceptado_no_deja_warning(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $venta = $this->una_venta();

        Log::spy();

        ComercioCityMailHelper::new_sale($venta);

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Lo que se manda y a quién NO cambia: sigue ENCOLÁNDOSE (no pasa a sincrónico) un `ComercioCityMail` a la casilla del cliente.
     * (Con `Mail::fake()`.)
     *
     * @return void
     */
    public function test_el_mail_de_una_venta_sigue_encolandose_al_cliente(): void
    {
        Mail::fake();
        $venta = $this->una_venta();

        ComercioCityMailHelper::new_sale($venta);

        Mail::assertQueued(ComercioCityMail::class, 1);
        Mail::assertQueued(ComercioCityMail::class, function ($mail) {
            return $mail->hasTo($this->cliente->email);
        });
        Mail::assertNotSent(ComercioCityMail::class);
    }

    // ---------------------------------------------------------------------------------------------------------------------
    //  nueva_oferta
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * Con un 550 en el worker: `nueva_oferta()` sigue devolviendo la casilla a la que encoló (su contrato: no tira, no convierte nada en un
     * 422) y el rechazo queda en el log.
     *
     * @return void
     */
    public function test_el_aviso_de_una_oferta_rechazado_en_el_worker_no_tira_y_queda_en_el_log(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
        $oferta = $this->una_oferta();

        Log::spy();

        $casilla = ComercioCityMailHelper::nueva_oferta($oferta->fresh());

        $this->assertSame($this->cliente->email, $casilla, 'El contrato de nueva_oferta() no cambia: devuelve la casilla a la que encoló.');

        Log::shouldHaveReceived('warning')->withArgs(function ($texto) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false;
        });
    }

    /**
     * Control: contra un servidor que ACEPTA todo, el aviso de la oferta sale sin dejar ningún warning.
     *
     * @return void
     */
    public function test_el_aviso_de_una_oferta_aceptado_no_deja_warning(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $oferta = $this->una_oferta();

        Log::spy();

        $casilla = ComercioCityMailHelper::nueva_oferta($oferta->fresh());

        $this->assertSame($this->cliente->email, $casilla);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * Lo que se manda y a quién NO cambia: sigue ENCOLÁNDOSE un `ComercioCityMail` a la casilla del cliente. (Con `Mail::fake()`.)
     *
     * @return void
     */
    public function test_el_aviso_de_una_oferta_sigue_encolandose_al_cliente(): void
    {
        Mail::fake();
        $oferta = $this->una_oferta();

        $casilla = ComercioCityMailHelper::nueva_oferta($oferta->fresh());

        $this->assertSame($this->cliente->email, $casilla);
        Mail::assertQueued(ComercioCityMail::class, 1);
        Mail::assertNotSent(ComercioCityMail::class);
    }
}
