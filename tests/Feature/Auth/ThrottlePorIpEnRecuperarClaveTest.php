<?php

namespace Tests\Feature\Auth;

use App\Mail\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Throttle POR IP de las tres rutas públicas de recuperar clave (misión throttle-recuperar-clave-por-ip,
 * 20/10/2026). Los limiters están en RouteServiceProvider y se aplican en routes/web.php:
 *
 *   - send-verification-code  -> `recuperar-clave-pedir`     5 por minuto /  20 por hora
 *   - check-verification-code -> `recuperar-clave-verificar` 10 por minuto /  60 por hora
 *   - update-password         -> `recuperar-clave-cambiar`   10 por minuto /  30 por hora
 *
 * Estos tests corren con el middleware de throttle ACTIVO (los de la capa por cuenta, que hacen muchos
 * pedidos desde la misma IP, lo apagan con `withoutMiddleware`). Todos los pedidos salen de 127.0.0.1 salvo
 * donde se cambia `REMOTE_ADDR`. El reloj se mueve con `travelTo` (Carbon), que es el que usa el RateLimiter.
 *
 * Los pedidos de barrido usan un correo distinto e inexistente en cada uno, así la protección por cuenta
 * (5 códigos por hora) no entra en juego y lo único que corta es el límite por IP.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ThrottlePorIpEnRecuperarClaveTest extends TestCase
{
    use DatabaseTransactions;

    const SEND = '/password-reset/send-verification-code';
    const CHECK = '/password-reset/check-verification-code';
    const UPDATE = '/password-reset/update-password';

    const MENSAJE = 'Hiciste demasiados intentos. Esperá unos minutos y volvé a probar.';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00'));
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    /**
     * Los tres pasos con sus límites: ruta, por minuto y por hora.
     *
     * @return array
     */
    public function pasos()
    {
        return [
            'pedir el código'   => [self::SEND, 5, 20],
            'verificar el código' => [self::CHECK, 10, 60],
            'cambiar la clave'  => [self::UPDATE, 10, 30],
        ];
    }

    /**
     * Un pedido a un paso con un correo inexistente y distinto cada vez (no toca la capa por cuenta).
     *
     * @param  string  $ruta
     * @param  string|null  $email  Si no se da, uno nuevo que no existe.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function pedir_al_paso($ruta, $email = null)
    {
        $email = $email ?: 'barrido-' . uniqid() . '@ejemplo.test';

        if ($ruta === self::SEND) {
            return $this->postJson($ruta, ['email' => $email, 'longitud_del_codigo' => 4]);
        }

        if ($ruta === self::CHECK) {
            return $this->postJson($ruta, ['email' => $email, 'verification_code' => '1234']);
        }

        return $this->postJson($ruta, [
            'email'             => $email,
            'verification_code' => '1234',
            'password'          => 'clave-nueva-5678',
            'password_confirm'  => 'clave-nueva-5678',
        ]);
    }

    /**
     * Hace `$cantidad` pedidos y comprueba que NINGUNO sea 429.
     *
     * @param  string  $ruta
     * @param  int  $cantidad
     * @return void
     */
    protected function pedir_sin_que_corte($ruta, $cantidad)
    {
        for ($i = 1; $i <= $cantidad; $i++) {
            $respuesta = $this->pedir_al_paso($ruta);

            $this->assertNotSame(429, $respuesta->getStatusCode(), 'El pedido ' . $i . ' de ' . $cantidad . ' a ' . $ruta . ' no tendría que cortarse.');
        }
    }

    /**
     * Pasado el tope POR MINUTO de cada paso, el siguiente pedido es un 429.
     *
     * @test
     * @dataProvider pasos
     */
    public function pasado_el_tope_por_minuto_el_paso_responde_429($ruta, $por_minuto, $por_hora)
    {
        $this->pedir_sin_que_corte($ruta, $por_minuto);

        $this->pedir_al_paso($ruta)->assertStatus(429);
    }

    /**
     * Pasado el tope POR HORA de cada paso, el siguiente pedido es un 429 aunque la ventana del minuto esté
     * libre. Es la prueba de que la hora cuenta APARTE del minuto: se hacen tandas del tope por minuto, se
     * espera a que el minuto se libere (cada tanda pasa), y al llegar al tope de la hora se corta con el
     * límite de la hora. Si las dos ventanas compartieran contador (misma clave), el corte llegaría con el
     * tope por minuto en la primera tanda.
     *
     * @test
     * @dataProvider pasos
     */
    public function pasado_el_tope_por_hora_el_paso_responde_429_con_el_minuto_libre($ruta, $por_minuto, $por_hora)
    {
        $tandas = (int) ($por_hora / $por_minuto);
        $this->assertSame($por_hora, $tandas * $por_minuto, 'El tope por hora tiene que ser múltiplo del tope por minuto para este test.');

        for ($tanda = 1; $tanda <= $tandas; $tanda++) {
            // Cada tanda arranca con la ventana del minuto limpia.
            $this->travelTo(Carbon::now()->addSeconds(61));
            $this->pedir_sin_que_corte($ruta, $por_minuto);
        }

        // El minuto ya se liberó, pero la hora está llena: 429, con los headers de la ventana de la HORA.
        $this->travelTo(Carbon::now()->addSeconds(61));

        $respuesta = $this->pedir_al_paso($ruta);

        $respuesta->assertStatus(429);
        $this->assertSame((string) $por_hora, $respuesta->headers->get('X-RateLimit-Limit'), 'El corte tiene que ser el de la ventana de la hora.');
        $this->assertSame('0', $respuesta->headers->get('X-RateLimit-Remaining'));

        // Y lo que falta para liberarse es del orden de la hora, no del minuto.
        $this->assertGreaterThan(60, (int) $respuesta->headers->get('Retry-After'));
        $this->assertLessThanOrEqual(3600, (int) $respuesta->headers->get('Retry-After'));
    }

    /**
     * El 429 trae el mensaje en español (en `message` y en `errors.email`, como lo lee la SPA) y los headers
     * de Laravel: `Retry-After` y `X-RateLimit-*` de la ventana que se pasó.
     *
     * @test
     * @dataProvider pasos
     */
    public function el_429_trae_el_mensaje_y_los_headers($ruta, $por_minuto, $por_hora)
    {
        $this->pedir_sin_que_corte($ruta, $por_minuto);

        $respuesta = $this->pedir_al_paso($ruta);

        $respuesta->assertStatus(429)
            ->assertExactJson([
                'message' => self::MENSAJE,
                'errors'  => ['email' => [self::MENSAJE]],
            ]);

        $this->assertSame((string) $por_minuto, $respuesta->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $respuesta->headers->get('X-RateLimit-Remaining'));

        // Retry-After en segundos, dentro del minuto; X-RateLimit-Reset es la hora (timestamp) de liberación.
        $retry_after = (int) $respuesta->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retry_after);
        $this->assertLessThanOrEqual(60, $retry_after);
        $this->assertSame(Carbon::now()->getTimestamp() + $retry_after, (int) $respuesta->headers->get('X-RateLimit-Reset'));
    }

    /**
     * Cada paso tiene su cubo propio: agotar uno no corta a los otros dos.
     *
     * @test
     * @dataProvider pasos
     */
    public function los_tres_pasos_tienen_cubos_independientes($ruta_agotada, $por_minuto, $por_hora)
    {
        $this->pedir_sin_que_corte($ruta_agotada, $por_minuto);
        $this->pedir_al_paso($ruta_agotada)->assertStatus(429);

        foreach ([self::SEND, self::CHECK, self::UPDATE] as $otra_ruta) {
            if ($otra_ruta === $ruta_agotada) {
                continue;
            }

            $this->assertNotSame(429, $this->pedir_al_paso($otra_ruta)->getStatusCode(), $otra_ruta . ' no tendría que cortarse por haber agotado ' . $ruta_agotada);
        }
    }

    /**
     * Otra IP no se ve afectada por el corte de la primera.
     *
     * @test
     * @dataProvider pasos
     */
    public function otra_ip_no_se_ve_afectada($ruta, $por_minuto, $por_hora)
    {
        $this->pedir_sin_que_corte($ruta, $por_minuto);
        $this->pedir_al_paso($ruta)->assertStatus(429);

        // Otra IP: tiene su propio cupo completo.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);

        $this->pedir_sin_que_corte($ruta, $por_minuto);
        $this->pedir_al_paso($ruta)->assertStatus(429);
    }

    /**
     * Pasado el minuto el paso se libera (el reloj de Carbon es el del RateLimiter).
     *
     * @test
     * @dataProvider pasos
     */
    public function pasado_el_minuto_el_paso_se_libera($ruta, $por_minuto, $por_hora)
    {
        $this->pedir_sin_que_corte($ruta, $por_minuto);
        $this->pedir_al_paso($ruta)->assertStatus(429);

        // Todavía dentro del minuto sigue cortado.
        $this->travelTo(Carbon::now()->addSeconds(30));
        $this->pedir_al_paso($ruta)->assertStatus(429);

        $this->travelTo(Carbon::now()->addSeconds(31));
        $this->assertNotSame(429, $this->pedir_al_paso($ruta)->getStatusCode());
    }

    /**
     * Pasada la hora se libera aunque se haya llegado al tope de la hora.
     *
     * @test
     * @dataProvider pasos
     */
    public function pasada_la_hora_el_paso_se_libera($ruta, $por_minuto, $por_hora)
    {
        for ($tanda = 1; $tanda <= (int) ($por_hora / $por_minuto); $tanda++) {
            $this->travelTo(Carbon::now()->addSeconds(61));
            $this->pedir_sin_que_corte($ruta, $por_minuto);
        }

        $this->travelTo(Carbon::now()->addSeconds(61));
        $this->pedir_al_paso($ruta)->assertStatus(429);

        // Desde el primer pedido pasó más de una hora.
        $this->travelTo(Carbon::now()->addHour());
        $this->assertNotSame(429, $this->pedir_al_paso($ruta)->getStatusCode());
    }

    /**
     * El 429 no revela si el correo existe: para un correo de un dueño real y para uno inexistente la
     * respuesta es idéntica (cuerpo y headers que no dependen del correo), y el correo ni aparece en ella.
     * Y pasado el tope el controlador ni se ejecuta: no se manda ningún mail más.
     *
     * @test
     */
    public function el_429_no_revela_si_el_correo_existe()
    {
        $dueno = User::create([
            'name'         => 'Dueño del throttle',
            'company_name' => 'Comercio del throttle ' . uniqid(),
            'email'        => 'throttle-' . uniqid() . '@ejemplo.test',
            'password'     => Hash::make('clave-vieja-1234'),
            'owner_id'     => null,
        ]);
        $inexistente = 'nadie-' . uniqid() . '@ejemplo.test';

        // IP A: agota el paso y recibe el 429 con el correo REAL.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.1']);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::SEND, ['email' => $dueno->email, 'longitud_del_codigo' => 4])->assertStatus(200);
        }
        $con_correo_real = $this->postJson(self::SEND, ['email' => $dueno->email, 'longitud_del_codigo' => 4]);

        // IP B: agota el paso y recibe el 429 con un correo INEXISTENTE.
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.2']);
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson(self::SEND, ['email' => $inexistente, 'longitud_del_codigo' => 4])->assertStatus(200);
        }
        $con_correo_inexistente = $this->postJson(self::SEND, ['email' => $inexistente, 'longitud_del_codigo' => 4]);

        $con_correo_real->assertStatus(429);
        $con_correo_inexistente->assertStatus(429);

        $this->assertSame($con_correo_real->getContent(), $con_correo_inexistente->getContent());
        $this->assertSame(
            $con_correo_real->headers->get('X-RateLimit-Limit'),
            $con_correo_inexistente->headers->get('X-RateLimit-Limit')
        );
        $this->assertStringNotContainsString($dueno->email, $con_correo_real->getContent());
        $this->assertStringNotContainsString('inexistente', strtolower($con_correo_inexistente->getContent()));

        // El dueño real recibió sus 5 mails (los de la IP A) y ninguno más al pasar el tope.
        Mail::assertSent(PasswordReset::class, 5);
    }
}
