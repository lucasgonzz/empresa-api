<?php

namespace Tests\Feature\MailsRechazados;

use App\Console\Commands\check_stock_movements;
use App\Models\Article;
use App\Models\Error;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Fakes\ServidorSmtpFake;

/**
 * Los mails que mandan los comandos de mantenimiento y el aviso de errores del SPA.
 *
 * El criterio: un punto se arregla si AFIRMA un éxito que el rechazo desmiente; si no afirma nada, alcanza con que el
 * rechazo quede anotado.
 *
 *  - `check_current_acounts_integrity` imprimía "Mail enviado a …" y devolvía 0, y `check_stocks` imprimía "Se envio mail" y devolvía 0, aunque
 *    el servidor rechazara la casilla: quien corre el comando lee un éxito falso. Ahora dicen que NO salió y devuelven 1.
 *  - `ErrorController::store` (el aviso de un error del SPA a Lucas) y `check_stock_movements::enviar_notificaciones()` (código muerto: la única
 *    llamada está comentada) no afirman nada: no se les agrega un chequeo, y el rechazo queda en el log por el listener de `MessageSent`.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ComandosRechazadosTest extends MailsRechazadosTestCase
{
    /** @var User */
    private $dueno;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_un_dueno(['email' => $this->una_casilla('dueno')]);

        // Los comandos y `ErrorController` leen el dueño de la instancia de `config('app.USER_ID')`.
        config(['app.USER_ID' => $this->dueno->id]);
    }

    /**
     * Un artículo cuyo stock no coincide con el de su último movimiento: lo que `check_stocks` denuncia por mail.
     *
     * @return void
     */
    private function sembrar_un_stock_mal(): void
    {
        $articulo = Article::create(['name' => 'zz-stock-mal-' . uniqid(), 'user_id' => $this->dueno->id, 'stock' => 10]);

        StockMovement::create(['article_id' => $articulo->id, 'stock_resultante' => 5]);
    }

    /**
     * Una inconsistencia de cuenta corriente (un débito "pagándose" con el campo en cero): lo que
     * `check_current_acounts_integrity` denuncia por mail al dueño del comercio.
     *
     * @return void
     */
    private function sembrar_una_inconsistencia_de_cuenta_corriente(): void
    {
        DB::table('current_acounts')->insert([
            'user_id'       => $this->dueno->id,
            'client_id'     => 987654,
            'status'        => 'pagandose',
            'is_provisorio' => 0,
            'pagandose'     => 0,
            'debe'          => 100,
            'detalle'       => 'zz-inconsistencia-de-prueba',
            'created_at'    => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------------
    //  check_stocks
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * El centro del cambio para `check_stocks`: con un 550, dice que el mail NO salió y devuelve 1 (antes: "Se envio mail" y 0).
     *
     * @return void
     */
    public function test_check_stocks_con_un_servidor_que_rechaza_dice_que_no_salio_y_devuelve_uno(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
        $this->sembrar_un_stock_mal();

        $codigo = Artisan::call('check_stocks');
        $salida = Artisan::output();

        $this->assertStringNotContainsString('Se envio mail', $salida, 'El servidor rechazó la casilla: el mail NO salió.');
        $this->assertStringContainsString('NO salió', $salida);
        $this->assertStringContainsString('rechazó la casilla', $salida);
        $this->assertSame(1, $codigo, 'Quien corre el comando (o el cron) tiene que ver un código de salida de fallo.');
    }

    /**
     * Control: contra un servidor que ACEPTA todo, `check_stocks` dice "Se envio mail" y devuelve 0, como siempre.
     *
     * @return void
     */
    public function test_check_stocks_con_un_servidor_que_acepta_dice_se_envio_mail_como_siempre(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $this->sembrar_un_stock_mal();

        $codigo = Artisan::call('check_stocks');
        $salida = Artisan::output();

        $this->assertStringContainsString('Se envio mail', $salida);
        $this->assertStringNotContainsString('NO salió', $salida);
        $this->assertSame(0, $codigo);
    }

    /**
     * Sin artículos con el stock mal no se manda ningún mail y el comando termina bien (la rama de antes del envío no cambia).
     *
     * @return void
     */
    public function test_check_stocks_sin_stocks_mal_no_manda_nada_y_devuelve_cero(): void
    {
        Mail::fake();

        $codigo = Artisan::call('check_stocks');

        $this->assertSame(0, $codigo);
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------------------------------------------------------------------
    //  check_current_acounts_integrity
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * El centro del cambio para `check_current_acounts_integrity`: con un 550, dice que el mail NO salió, lo deja en el log y devuelve 1,
     * igual que su rama de "email inválido" (antes: "Mail enviado a …" y 0). Le escribe al DUEÑO del comercio, no a Lucas.
     *
     * @return void
     */
    public function test_la_auditoria_de_cuentas_con_un_servidor_que_rechaza_dice_que_no_salio_y_devuelve_uno(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
        $this->sembrar_una_inconsistencia_de_cuenta_corriente();

        Log::spy();

        $codigo = Artisan::call('check_current_acounts_integrity', ['user_id' => $this->dueno->id]);
        $salida = Artisan::output();

        $this->assertStringNotContainsString('Mail enviado a', $salida, 'El servidor rechazó la casilla del dueño: el mail NO salió.');
        $this->assertStringContainsString('NO salió', $salida);
        $this->assertStringContainsString('rechazó la casilla', $salida);
        $this->assertSame(1, $codigo);

        // Y tampoco queda en el log como enviado. 🔴 El `Log::info` del comando lleva UN solo argumento (el texto): la lista de argumentos a vigilar tiene
        // que tener UN matcher. Con dos (texto + contexto) nunca coincidiría con ninguna llamada y esta aserción pasaría siempre, aunque el comando
        // volviera a loguear "Mail enviado a …" sobre un mail rechazado (una versión anterior de esta aserción vigilaba dos argumentos y nunca podía fallar).
        Log::shouldNotHaveReceived('info', [\Mockery::pattern('/Mail enviado a/')]);
        Log::shouldHaveReceived('error')->withArgs(function ($mensaje) {
            return strpos((string) $mensaje, '[CheckCurrentAcountsIntegrity]') !== false && strpos((string) $mensaje, 'rechaz') !== false;
        });
    }

    /**
     * Control: contra un servidor que ACEPTA todo, la auditoría dice "Mail enviado a …" y devuelve 0, como siempre.
     *
     * @return void
     */
    public function test_la_auditoria_de_cuentas_con_un_servidor_que_acepta_dice_mail_enviado_como_siempre(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $this->sembrar_una_inconsistencia_de_cuenta_corriente();

        $codigo = Artisan::call('check_current_acounts_integrity', ['user_id' => $this->dueno->id]);
        $salida = Artisan::output();

        $this->assertStringContainsString('Mail enviado a ' . $this->dueno->email, $salida);
        $this->assertStringNotContainsString('NO salió', $salida);
        $this->assertSame(0, $codigo);
    }

    /**
     * Lo que se manda y a quién NO cambia: UN `ComercioCityMail`, a la casilla del dueño. (Con `Mail::fake()`.)
     *
     * @return void
     */
    public function test_la_auditoria_sigue_mandando_un_mail_al_dueno(): void
    {
        Mail::fake();
        $this->sembrar_una_inconsistencia_de_cuenta_corriente();

        $codigo = Artisan::call('check_current_acounts_integrity', ['user_id' => $this->dueno->id]);

        $this->assertSame(0, $codigo);
        Mail::assertSent(\App\Mail\ComercioCityMail::class, 1);
        Mail::assertSent(\App\Mail\ComercioCityMail::class, function ($mail) {
            return $mail->hasTo($this->dueno->email);
        });
    }

    // ---------------------------------------------------------------------------------------------------------------------
    //  Los que no afirman nada: no se rompen, y el rechazo queda anotado (el listener de MessageSent)
    // ---------------------------------------------------------------------------------------------------------------------

    /**
     * `ErrorController::store`: el aviso del error a Lucas es un mail interno sin ninguna marca de "enviado". Con un 550 el request sigue
     * contestando bien (el SPA no se entera de un fallo del correo), el error queda guardado, y el rechazo queda en el log.
     *
     * @return void
     */
    public function test_el_aviso_de_un_error_del_spa_no_rompe_y_el_rechazo_queda_en_el_log(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);
        $this->actingAs($this->dueno, 'web');

        Log::spy();

        $mensaje   = 'zz-error-de-prueba-' . uniqid();
        $respuesta = $this->postJson('api/error', ['message' => $mensaje, 'file' => 'prueba.js', 'line' => 10]);

        $respuesta->assertStatus(200);
        $this->assertTrue(Error::where('message', $mensaje)->exists(), 'El error se guarda antes de avisar por mail, como siempre.');

        Log::shouldHaveReceived('warning')->withArgs(function ($texto) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false;
        });
    }

    /**
     * Control: contra un servidor que ACEPTA todo, el aviso sale sin dejar ningún warning.
     *
     * @return void
     */
    public function test_el_aviso_de_un_error_del_spa_con_un_servidor_que_acepta_no_deja_warning(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_ACEPTA);
        $this->actingAs($this->dueno, 'web');

        Log::spy();

        $respuesta = $this->postJson('api/error', ['message' => 'zz-error-de-prueba-' . uniqid()]);

        $respuesta->assertStatus(200);
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * `check_stock_movements::enviar_notificaciones()` es CÓDIGO MUERTO (la única llamada está comentada en `handle()`): se ejercita directo.
     * Con un 550 no tira, y el rechazo queda en el log.
     *
     * @return void
     */
    public function test_el_envio_muerto_de_check_stock_movements_no_tira_y_el_rechazo_queda_en_el_log(): void
    {
        $this->levantar_un_smtp_por_defecto(ServidorSmtpFake::MODO_RECHAZA);

        $comando                = new check_stock_movements();
        $comando->user_id       = $this->dueno->id;
        $comando->notificaciones = ['zz-articulo: el stock no coincide con sus movimientos'];

        Log::spy();

        $comando->enviar_notificaciones();

        Log::shouldHaveReceived('warning')->withArgs(function ($texto) {
            return strpos((string) $texto, 'Mail rechazado por el servidor de correo') !== false;
        });
    }
}
