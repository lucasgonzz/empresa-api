<?php

namespace Tests\Feature\Auth;

use App\Mail\ComercioCityMail;
use App\Mail\ComercioCityMailPayload;
use App\Mail\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Recuperar la contraseña con un código de 4 dígitos, solo para el dueño (misión
 * recuperar-clave-codigo-4-digitos, 20/10/2026).
 *
 * Lo que pidió Lucas: la persona escribe el correo del DUEÑO, le llega un mail con diseño de ComercioCity
 * con un código de 4 DÍGITOS, lo escribe en la página y, si está bien, elige la clave nueva y se actualiza
 * en la base. Los empleados no recuperan por acá: el dueño les cambia la clave desde el módulo de empleados.
 *
 * COMPATIBILIDAD HACIA ATRÁS (regla de Lucas): el código de 4 dígitos es OPT-IN con `longitud_del_codigo = 4`;
 * sin ese campo (la SPA vieja) sigue siendo de 6 dígitos (eso lo cubre además `Reset_de_contrasena_Test`, que
 * no manda el campo y exige 6). Los topes de seguridad NO se aflojaron: con 4 dígitos son lo único que
 * protege la cuenta, por eso hay tests que cuentan cuántos códigos se llegan a probar.
 *
 * Mail con `Mail::fake()` (el código se saca del mailable). PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class RecuperarClaveConCodigoDe4DigitosTest extends TestCase
{
    use DatabaseTransactions;

    const SEND = '/password-reset/send-verification-code';
    const CHECK = '/password-reset/check-verification-code';
    const UPDATE = '/password-reset/update-password';

    const CLAVE_VIEJA = 'clave-vieja-1234';
    const CLAVE_NUEVA = 'clave-nueva-5678';

    /** @var User El dueño que recupera su clave. */
    protected $dueno;

    /** @var User Un empleado de ese dueño (no puede recuperar por acá). */
    protected $empleado;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->dueno = $this->crear_usuario();
        $this->empleado = $this->crear_usuario($this->dueno->id);

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
     * Un usuario propio del test, con un correo que no se repite. Dueño si no se le da `owner_id`.
     *
     * @param  int|null  $owner_id
     * @return User
     */
    protected function crear_usuario($owner_id = null)
    {
        return User::create([
            'name'         => $owner_id ? 'Empleado del reset' : 'Dueño del reset',
            'company_name' => 'Comercio del reset ' . uniqid(),
            'email'        => 'reset4-' . uniqid() . '@ejemplo.test',
            'password'     => Hash::make(self::CLAVE_VIEJA),
            'owner_id'     => $owner_id,
        ]);
    }

    /**
     * Pide el código como lo hace la SPA nueva (`longitud_del_codigo: 4`) y lo devuelve (el del último mail).
     *
     * @param  string  $email
     * @param  array  $extra  Campos extra del pedido (para pisar `longitud_del_codigo`).
     * @return string
     */
    protected function pedir_codigo($email, array $extra = ['longitud_del_codigo' => 4])
    {
        $this->postJson(self::SEND, array_merge(['email' => $email], $extra))
            ->assertStatus(200)
            ->assertJson(['email_send' => true]);

        $codigo = null;

        Mail::assertSent(PasswordReset::class, function ($mail) use (&$codigo) {
            $codigo = (string) $mail->code;

            return true;
        });

        return $codigo;
    }

    /**
     * Un código de 4 dígitos que no es el dado (y no empieza en 0, como los que se emiten).
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
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar($email, $codigo)
    {
        return $this->postJson(self::UPDATE, [
            'email'             => $email,
            'verification_code' => $codigo,
            'password'          => self::CLAVE_NUEVA,
            'password_confirm'  => self::CLAVE_NUEVA,
        ]);
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

    /**
     * La contraseña del usuario sigue siendo la vieja y nadie quedó logueado.
     *
     * @param  User  $usuario
     * @return void
     */
    protected function assert_clave_intacta(User $usuario)
    {
        $this->assertTrue(Hash::check(self::CLAVE_VIEJA, (string) $usuario->fresh()->password), 'La contraseña cambió.');
        $this->assertGuest('web');
    }

    // ---------------------------------------------------------------------------------------------
    // El largo del código: opt-in de 4 dígitos, 6 por defecto
    // ---------------------------------------------------------------------------------------------

    /**
     * @test
     */
    public function con_longitud_del_codigo_4_el_codigo_es_de_cuatro_digitos_y_no_empieza_en_cero()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $codigo);
    }

    /**
     * @test
     */
    public function la_longitud_puede_venir_como_texto()
    {
        $codigo = $this->pedir_codigo($this->dueno->email, ['longitud_del_codigo' => '4']);

        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $codigo);
    }

    /**
     * Compatibilidad con la SPA vieja: sin el campo, 6 dígitos, y la respuesta es la de siempre.
     *
     * @test
     */
    public function sin_longitud_del_codigo_el_codigo_sigue_siendo_de_seis_digitos()
    {
        $respuesta = $this->postJson(self::SEND, ['email' => $this->dueno->email]);

        $respuesta->assertStatus(200);
        $this->assertSame(['email_send' => true], $respuesta->json());

        $codigo = null;
        Mail::assertSent(PasswordReset::class, function ($mail) use (&$codigo) {
            $codigo = (string) $mail->code;

            return true;
        });

        $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $codigo);
    }

    /**
     * Solo el 4 pide 4 dígitos: cualquier otro valor (incluida una SPA que mande basura) cae al default de 6.
     *
     * @test
     */
    public function cualquier_longitud_que_no_sea_4_da_seis_digitos()
    {
        $valores = [5, '6', 'abc', '4abc', 40, 0, null, [4], ['longitud' => 4], true];

        foreach ($valores as $indice => $valor) {
            // Cada vuelta es de otra hora para no chocar con el tope de 5 códigos por hora.
            $this->travelTo(Carbon::parse('2026-10-20 12:00:00')->addHours($indice * 2));

            $codigo = $this->pedir_codigo($this->dueno->email, ['longitud_del_codigo' => $valor]);

            $this->assertMatchesRegularExpression('/^[1-9]\d{5}$/', $codigo, 'Con longitud_del_codigo = ' . json_encode($valor));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Solo el dueño
    // ---------------------------------------------------------------------------------------------

    /**
     * El correo de un empleado no recibe código ni mail, y la respuesta dice por qué (la SPA nueva la
     * muestra; la vieja ve `email_send: false` y muestra su toast de siempre).
     *
     * @test
     */
    public function el_correo_de_un_empleado_no_recibe_ni_codigo_ni_mail_y_dice_por_que()
    {
        $respuesta = $this->postJson(self::SEND, ['email' => $this->empleado->email, 'longitud_del_codigo' => 4]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['email_send' => false, 'es_empleado' => true]);
        $this->assertNotSame('', trim((string) $respuesta->json('message')));

        Mail::assertNothingSent();
        $this->assertNull(Cache::get('password_reset:' . $this->empleado->id), 'No se tenía que emitir un código.');
        $this->assertNull(Cache::get('password_reset_pedidos:' . $this->empleado->id), 'Un empleado no cuenta para el tope.');
    }

    /**
     * @test
     */
    public function el_correo_de_un_dueno_no_trae_la_marca_de_empleado()
    {
        $respuesta = $this->postJson(self::SEND, ['email' => $this->dueno->email, 'longitud_del_codigo' => 4]);

        $respuesta->assertStatus(200);
        $this->assertSame(['email_send' => true], $respuesta->json());
    }

    /**
     * Un correo que no es de nadie sigue dando la respuesta de siempre (sin `es_empleado`).
     *
     * @test
     */
    public function un_correo_inexistente_sigue_dando_email_send_false_sin_marca_de_empleado()
    {
        $respuesta = $this->postJson(self::SEND, ['email' => 'nadie-' . uniqid() . '@ejemplo.test', 'longitud_del_codigo' => 4]);

        $respuesta->assertStatus(200);
        $this->assertSame(['email_send' => false], $respuesta->json());
        Mail::assertNothingSent();
    }

    /**
     * Aunque hubiera un código pendiente a nombre del empleado (no lo emite este endpoint, pero la caché es
     * compartida), el empleado es "inexistente" para el check y para el update: la clave no se cambia.
     *
     * @test
     */
    public function un_empleado_no_puede_cambiar_su_clave_ni_con_un_codigo_pendiente()
    {
        Cache::put('password_reset:' . $this->empleado->id, [
            'codigo_sha256' => hash('sha256', '4321'),
            'intentos'      => 0,
            'vence'         => now()->getTimestamp() + 1800,
        ], 1800);

        $this->assertFalse($this->verificar($this->empleado->email, '4321'));

        $this->actualizar($this->empleado->email, '4321')->assertStatus(422)->assertJson(['password_updated' => false]);

        $this->assert_clave_intacta($this->empleado);
    }

    /**
     * El código del dueño no sirve para el correo del empleado.
     *
     * @test
     */
    public function el_codigo_del_dueno_no_sirve_con_el_correo_de_un_empleado()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->assertFalse($this->verificar($this->empleado->email, $codigo));
        $this->actualizar($this->empleado->email, $codigo)->assertStatus(422);

        $this->assert_clave_intacta($this->empleado);
    }

    // ---------------------------------------------------------------------------------------------
    // El camino feliz y el código equivocado, con 4 dígitos
    // ---------------------------------------------------------------------------------------------

    /**
     * De punta a punta: pide el código, lo verifica, cambia la clave. La base queda con la clave nueva, la
     * vieja deja de servir y el código no se puede reusar.
     *
     * @test
     */
    public function el_flujo_completo_con_cuatro_digitos_cambia_la_clave_en_la_base()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->assertTrue($this->verificar($this->dueno->email, $codigo));

        $this->actualizar($this->dueno->email, $codigo)->assertStatus(200)->assertJson(['password_updated' => true]);

        $guardada = (string) $this->dueno->fresh()->password;
        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, $guardada), 'La clave nueva no quedó en la base.');
        $this->assertFalse(Hash::check(self::CLAVE_VIEJA, $guardada), 'La clave vieja tiene que dejar de servir.');
        $this->assertAuthenticatedAs($this->dueno->fresh(), 'web');

        // Un código sirve una sola vez.
        $this->assertFalse($this->verificar($this->dueno->email, $codigo));
    }

    /**
     * @test
     */
    public function un_codigo_equivocado_de_cuatro_digitos_no_cambia_nada()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->assertFalse($this->verificar($this->dueno->email, $this->otro_codigo($codigo)));
        $this->actualizar($this->dueno->email, $this->otro_codigo($codigo))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['verification_code']]);

        $this->assert_clave_intacta($this->dueno);
    }

    /**
     * La SPA manda el código como texto; con un número también tiene que andar (el cliente que lo mande así).
     *
     * @test
     */
    public function el_codigo_vale_como_numero_o_como_texto()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->assertTrue($this->verificar($this->dueno->email, (int) $codigo));
        $this->assertTrue($this->verificar($this->dueno->email, $codigo));
    }

    // ---------------------------------------------------------------------------------------------
    // Los topes con 4 dígitos: son lo único que protege la cuenta
    // ---------------------------------------------------------------------------------------------

    /**
     * 5 intentos por emisión: el 6.º no se prueba ni con el código correcto, porque el código murió.
     *
     * @test
     */
    public function con_cuatro_digitos_no_se_pueden_probar_mas_de_5_codigos_por_emision()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        // Los 4 primeros fallan pero el código sigue vivo.
        for ($i = 1; $i <= 4; $i++) {
            $this->assertFalse($this->verificar($this->dueno->email, $this->otro_codigo($codigo)), "Intento $i");
        }

        // El 5.º fallido mata el código.
        $this->assertFalse($this->verificar($this->dueno->email, $this->otro_codigo($codigo)), 'Intento 5');

        // Ya muerto, ni el correcto sirve (ni en el check ni en el update).
        $this->assertFalse($this->verificar($this->dueno->email, $codigo), 'El código correcto no puede servir después de 5 fallidos.');
        $this->actualizar($this->dueno->email, $codigo)->assertStatus(422);

        $this->assert_clave_intacta($this->dueno);
    }

    /**
     * 5 códigos por hora × 5 intentos = 25 como mucho. Se agotan las 5 emisiones con 5 fallidos cada una y la
     * 6.ª es rechazada: no hay mail, no hay código nuevo y no hay cómo seguir probando.
     *
     * @test
     */
    public function con_cuatro_digitos_no_se_pueden_probar_mas_de_25_codigos_por_hora()
    {
        // Este test hace 35 pedidos desde la misma IP: se apaga el throttle por IP para seguir probando la
        // capa POR CUENTA. La capa por IP se prueba en ThrottlePorIpEnRecuperarClaveTest.
        $this->withoutMiddleware(ThrottleRequests::class);

        $intentos_probados = 0;

        for ($emision = 1; $emision <= 5; $emision++) {
            $codigo = $this->pedir_codigo($this->dueno->email);

            for ($i = 1; $i <= 5; $i++) {
                $this->assertFalse($this->verificar($this->dueno->email, $this->otro_codigo($codigo)));
                $intentos_probados++;
            }

            $this->assertFalse($this->verificar($this->dueno->email, $codigo), 'Emisión ' . $emision . ': el código ya murió.');
        }

        $this->assertSame(25, $intentos_probados);

        // La 6.ª emisión en la hora: 422, sin mail nuevo.
        Mail::fake();

        $this->postJson(self::SEND, ['email' => $this->dueno->email, 'longitud_del_codigo' => 4])
            ->assertStatus(422)
            ->assertJson(['email_send' => false])
            ->assertJsonStructure(['message', 'errors' => ['email']]);

        Mail::assertNothingSent();

        // Y sin código vigente: no queda nada contra qué seguir probando.
        $this->assertNull(Cache::get('password_reset:' . $this->dueno->id));
    }

    /**
     * Pasada la hora se puede pedir de nuevo (la ventana no es para siempre).
     *
     * @test
     */
    public function pasada_la_hora_se_puede_volver_a_pedir_un_codigo_de_cuatro_digitos()
    {
        for ($emision = 1; $emision <= 5; $emision++) {
            $this->pedir_codigo($this->dueno->email);
        }

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00')->addMinutes(61));

        $codigo = $this->pedir_codigo($this->dueno->email);
        $this->assertMatchesRegularExpression('/^[1-9]\d{3}$/', $codigo);
    }

    /**
     * El código vence a los 30 minutos.
     *
     * @test
     */
    public function el_codigo_de_cuatro_digitos_vence_a_los_30_minutos()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00')->addMinutes(29));
        $this->assertTrue($this->verificar($this->dueno->email, $codigo));

        $this->travelTo(Carbon::parse('2026-10-20 12:00:00')->addMinutes(31));
        $this->assertFalse($this->verificar($this->dueno->email, $codigo));
        $this->actualizar($this->dueno->email, $codigo)->assertStatus(422);
    }

    /**
     * 🔴 El candado: si otro pedido del mismo usuario tiene tomado el candado, el intento NO se da por
     * bueno (falla cerrado). Es lo que impide que pedidos en paralelo lean el mismo contador y prueben
     * cientos de códigos por emisión. Tarda lo que espera el candado (5 s).
     *
     * @test
     */
    public function si_no_se_consigue_el_candado_del_usuario_el_codigo_no_se_da_por_bueno()
    {
        // Reloj real: `Lock::block()` mide su espera con Carbon::now(); con el tiempo congelado por travelTo()
        // nunca vence y el test se colgaría para siempre. El código se pide DESPUÉS, con el reloj real.
        $this->travelBack();

        $codigo = $this->pedir_codigo($this->dueno->email);

        $candado = Cache::lock('password_reset_candado:' . $this->dueno->id, 60);
        $this->assertTrue($candado->get());

        try {
            $this->assertFalse($this->verificar($this->dueno->email, $codigo), 'Sin candado no se puede validar ni el código correcto.');
        } finally {
            $candado->release();
        }

        // Soltado el candado, el código correcto sigue sirviendo: el intento sin candado no gastó nada.
        $this->assertTrue($this->verificar($this->dueno->email, $codigo));
    }

    // ---------------------------------------------------------------------------------------------
    // El mail
    // ---------------------------------------------------------------------------------------------

    /**
     * El HTML que armaría un mailable. No usa `Mailable::render()` porque con `Mail::fake()` el mailer que
     * resuelve es el falso y no sabe renderizar: se arma la vista con los mismos datos que usa el envío.
     *
     * @param  \Illuminate\Mail\Mailable  $mail
     * @return string
     */
    protected function html_del_mail($mail)
    {
        $mail->build();

        return view($mail->view, $mail->buildViewData())->render();
    }

    /**
     * El mail que sale con un pedido de 4 dígitos tiene el código, el diseño de ComercioCity, el
     * vencimiento y la aclaración de "si no lo pediste".
     *
     * @test
     */
    public function el_mail_muestra_el_codigo_con_el_diseno_de_comerciocity()
    {
        $codigo = $this->pedir_codigo($this->dueno->email);

        Mail::assertSent(PasswordReset::class, function ($mail) use ($codigo) {
            $html = $this->html_del_mail($mail);

            $this->assertStringContainsString('>' . $codigo . '</p>', $html, 'El código tiene que estar en la caja destacada.');
            $this->assertStringContainsString(config('commerciocity.header_background'), $html, 'Usa el color de ComercioCity.');
            $this->assertStringContainsString('<!DOCTYPE html>', $html);
            $this->assertStringContainsString('Tu código de verificación', $html);
            $this->assertStringContainsString('30 minutos', $html);
            $this->assertStringContainsString('ignorá este mail', $html);
            // Sin el logo fijo de antes ni estilos de markdown de Laravel.
            $this->assertStringNotContainsString('public/storage/logo.png', $html);
            $this->assertStringNotContainsString('class="logo"', $html);
            $this->assertSame('Tu código para recuperar la contraseña', $mail->subject);

            return true;
        });

        Mail::assertSent(PasswordReset::class, function ($mail) {
            return $mail->hasTo($this->dueno->email);
        });
    }

    /**
     * El "vence en N minutos" del mail es el vencimiento real del código.
     *
     * @test
     */
    public function el_vencimiento_que_dice_el_mail_es_el_del_codigo()
    {
        $this->assertSame(
            \App\Http\Controllers\CommonLaravel\PasswordResetController::MINUTOS_DE_VIDA_DEL_CODIGO,
            PasswordReset::MINUTOS_DE_VIDA_DEL_CODIGO
        );
    }

    /**
     * La caja del código es opcional: los demás mails de ComercioCity (sin `highlight`) no la llevan.
     *
     * @test
     */
    public function los_demas_mails_de_comerciocity_no_llevan_la_caja_del_codigo()
    {
        $html = $this->html_del_mail(new ComercioCityMail(new ComercioCityMailPayload([
            'subject'    => 'Un mail cualquiera',
            'title'      => 'Hola',
            'paragraphs' => ['Un párrafo.'],
        ])));

        $this->assertStringContainsString('Un párrafo.', $html);
        $this->assertStringNotContainsString('letter-spacing:0.28em', $html);
    }
}
