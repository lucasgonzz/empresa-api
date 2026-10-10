<?php

namespace Tests\Feature\Seguridad\RutasPublicas;

use App\Mail\PasswordReset;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * La recuperación de contraseña (`POST /password-reset/*`, CommonLaravel\PasswordResetController) exige
 * el código del mail (misión cerrar-rutas-web-publicas, 10/10/2026).
 *
 * EL CASO: `update-password` cambiaba la contraseña de cualquier usuario con solo su email, sin mirar el
 * código, y lo dejaba logueado. `check-verification-code` daba `verified: true` con `null == null` y 500
 * con un email inexistente. Y en una base armada con las migraciones `users` no tiene `verification_code`,
 * así que `send-verification-code` daba 500 antes de mandar el mail: el código ahora vive en caché.
 *
 * Contrato con la SPA de hoy (`Step2.vue` / `Step3.vue`): el check sigue siendo 200 `verified` true/false;
 * el update sin código válido es un 422 con forma de validación (`message` + `errors.verification_code`),
 * que el interceptor global de axios muestra como toast; con código válido, `password_updated: true` y la
 * sesión abierta, como siempre.
 *
 * Sin `actingAs()`: es el pedido de alguien que no puede entrar. El mail con `Mail::fake()` (el código se
 * saca del mailable).
 */
class Reset_de_contrasena_Test extends RutasPublicasTestCase
{
    const SEND = '/password-reset/send-verification-code';
    const CHECK = '/password-reset/check-verification-code';
    const UPDATE = '/password-reset/update-password';

    const CLAVE_VIEJA = 'clave-vieja-1234';
    const CLAVE_NUEVA = 'clave-nueva-5678';

    /** @var User */
    protected $usuario;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->usuario = $this->crear_un_dueno();

        $this->travelTo(Carbon::parse('2026-10-10 12:00:00'));
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
     * Un dueño propio del test, con un mail que no se repite.
     *
     * @return User
     */
    protected function crear_un_dueno()
    {
        return User::create([
            'name'         => 'Dueño del reset',
            'company_name' => 'Comercio del reset ' . uniqid(),
            'email'        => 'reset-' . uniqid() . '@ejemplo.test',
            'password'     => Hash::make(self::CLAVE_VIEJA),
        ]);
    }

    /**
     * Pide el código para un email y lo devuelve (el del último mail mandado).
     *
     * @param  string  $email
     * @return string
     */
    protected function pedir_codigo($email)
    {
        $this->postJson(self::SEND, ['email' => $email])->assertStatus(200)->assertJson(['email_send' => true]);

        $codigo = null;

        Mail::assertSent(PasswordReset::class, function ($mail) use (&$codigo) {
            $codigo = (string) $mail->code;

            return true;
        });

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $codigo);

        return $codigo;
    }

    /**
     * Un código de 6 dígitos que no es el dado.
     *
     * @param  string  $codigo
     * @return string
     */
    protected function otro_codigo($codigo)
    {
        return $codigo === '111111' ? '222222' : '111111';
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
     * La contraseña sigue siendo la vieja y nadie quedó logueado.
     *
     * @return void
     */
    protected function assert_nada_cambio()
    {
        $this->assertTrue(Hash::check(self::CLAVE_VIEJA, (string) $this->usuario->fresh()->password), 'La contraseña cambió.');
        $this->assertGuest('web');
    }

    /**
     * El 422 con forma de validación que la SPA convierte en toast.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_rechazo($respuesta)
    {
        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['message', 'errors' => ['verification_code']]);
        $respuesta->assertJson(['password_updated' => false]);

        $cuerpo = json_decode($respuesta->getContent());
        $this->assertIsObject($cuerpo->errors, '`errors` tiene que ser un mapa campo → mensajes: si no, la SPA no muestra el toast.');
        $this->assertNotSame('', trim((string) $cuerpo->errors->verification_code[0]));
    }

    /**
     * El agujero: sin pedir ningún código, el update no cambia nada.
     *
     * @test
     */
    public function sin_codigo_el_update_da_422_y_no_cambia_nada()
    {
        $this->assert_rechazo($this->actualizar($this->usuario->email, null));
        $this->assert_rechazo($this->actualizar($this->usuario->email, ''));

        $this->assert_nada_cambio();
    }

    /** @test */
    public function con_un_codigo_equivocado_el_update_da_422_y_no_cambia_nada()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        $this->assert_rechazo($this->actualizar($this->usuario->email, $this->otro_codigo($codigo)));

        $this->assert_nada_cambio();
    }

    /**
     * Un email que no existe da EXACTAMENTE la misma respuesta que un código malo: el endpoint no dice qué
     * emails tienen cuenta.
     *
     * @test
     */
    public function un_email_inexistente_da_el_mismo_422_que_un_codigo_malo()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        $malo = $this->actualizar($this->usuario->email, $this->otro_codigo($codigo));
        $inexistente = $this->actualizar('nadie-' . uniqid() . '@ejemplo.test', $codigo);

        $this->assert_rechazo($inexistente);
        $this->assertSame($malo->json(), $inexistente->json());

        // Y sin email, tampoco: no se resuelve a un usuario sin email (where email IS NULL).
        $this->assert_rechazo($this->postJson(self::UPDATE, ['verification_code' => $codigo, 'password' => self::CLAVE_NUEVA]));

        $this->assert_nada_cambio();
    }

    /** @test */
    public function el_check_con_un_email_inexistente_da_false_y_no_500()
    {
        $this->assertFalse($this->verificar('nadie-' . uniqid() . '@ejemplo.test', '123456'));
        $this->assertFalse($this->verificar(null, null));
    }

    /**
     * Antes daba `true` con `null == null`: un usuario que nunca pidió código y un pedido sin código.
     *
     * @test
     */
    public function el_check_sin_haber_pedido_codigo_da_false()
    {
        $this->assertFalse($this->verificar($this->usuario->email, null));
        $this->assertFalse($this->verificar($this->usuario->email, ''));
        $this->assertFalse($this->verificar($this->usuario->email, '123456'));
    }

    /**
     * El flujo de la SPA: pedir → verificar (paso 2) → cambiar (paso 3, mismo código). Cambia la
     * contraseña y deja la sesión abierta, como siempre.
     *
     * @test
     */
    public function el_flujo_completo_cambia_la_contrasena_y_loguea()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        // El check no consume el código: se puede verificar más de una vez.
        $this->assertTrue($this->verificar($this->usuario->email, $codigo));
        $this->assertTrue($this->verificar($this->usuario->email, $codigo));

        $this->actualizar($this->usuario->email, $codigo)->assertStatus(200)->assertExactJson(['password_updated' => true]);

        $this->assertTrue(Hash::check(self::CLAVE_NUEVA, (string) $this->usuario->fresh()->password));
        $this->assertAuthenticatedAs($this->usuario->fresh(), 'web');
    }

    /** @test */
    public function el_mismo_codigo_no_sirve_dos_veces()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        $this->actualizar($this->usuario->email, $codigo)->assertStatus(200);

        $this->assertFalse($this->verificar($this->usuario->email, $codigo));
        $this->assert_rechazo($this->actualizar($this->usuario->email, $codigo));
    }

    /**
     * El código de un usuario no sirve para cambiar la contraseña de otro.
     *
     * @test
     */
    public function el_codigo_de_otro_usuario_no_sirve()
    {
        $otro = $this->crear_un_dueno();

        $codigo_del_otro = $this->pedir_codigo($otro->email);

        $this->assert_rechazo($this->actualizar($this->usuario->email, $codigo_del_otro));

        $this->assert_nada_cambio();
    }

    /**
     * Cuatro intentos fallidos no matan el código; el quinto sí, y después ni el correcto sirve.
     *
     * @test
     */
    public function al_quinto_intento_fallido_el_codigo_muere()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);
        $malo = $this->otro_codigo($codigo);

        // Cuatro fallos, mezclando check y update (cuentan los dos).
        $this->assertFalse($this->verificar($this->usuario->email, $malo));
        $this->assertFalse($this->verificar($this->usuario->email, $malo));
        $this->assert_rechazo($this->actualizar($this->usuario->email, $malo));
        $this->assertFalse($this->verificar($this->usuario->email, $malo));

        $this->assertTrue($this->verificar($this->usuario->email, $codigo), 'Con cuatro fallos el código todavía tiene que servir.');

        // El quinto fallo lo mata.
        $this->assertFalse($this->verificar($this->usuario->email, $malo));

        $this->assertFalse($this->verificar($this->usuario->email, $codigo));
        $this->assert_rechazo($this->actualizar($this->usuario->email, $codigo));

        $this->assert_nada_cambio();
    }

    /**
     * Vive 30 minutos: a los 29 sirve, a los 31 no.
     *
     * @test
     */
    public function un_codigo_vencido_no_sirve()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        $this->travel(29)->minutes();
        $this->assertTrue($this->verificar($this->usuario->email, $codigo));

        $this->travel(2)->minutes();
        $this->assertFalse($this->verificar($this->usuario->email, $codigo));
        $this->assert_rechazo($this->actualizar($this->usuario->email, $codigo));

        $this->assert_nada_cambio();
    }

    /**
     * Pedir un código nuevo invalida el anterior.
     *
     * @test
     */
    public function pedir_otro_codigo_invalida_el_anterior()
    {
        $primero = $this->pedir_codigo($this->usuario->email);

        // Que el segundo salga distinto (6 dígitos al azar: una vez en un millón coinciden).
        do {
            $segundo = $this->pedir_codigo($this->usuario->email);
        } while ($segundo === $primero);

        $this->assertFalse($this->verificar($this->usuario->email, $primero));
        $this->assertTrue($this->verificar($this->usuario->email, $segundo));
    }

    /**
     * Lo que ya andaba no cambia: un correo que no es de nadie sigue siendo 200 `email_send: false`, sin
     * mandar nada.
     *
     * @test
     */
    public function el_send_con_un_correo_que_no_es_de_nadie_sigue_igual()
    {
        $this->postJson(self::SEND, ['email' => 'nadie-' . uniqid() . '@ejemplo.test'])
            ->assertStatus(200)
            ->assertExactJson(['email_send' => false]);

        Mail::assertNothingSent();
    }
}
