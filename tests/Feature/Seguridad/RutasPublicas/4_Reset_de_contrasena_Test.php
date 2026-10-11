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

        // Que el segundo salga distinto (900.000 códigos posibles: casi nunca coinciden).
        do {
            $segundo = $this->pedir_codigo($this->usuario->email);
        } while ($segundo === $primero);

        $this->assertFalse($this->verificar($this->usuario->email, $primero));
        $this->assertTrue($this->verificar($this->usuario->email, $segundo));
    }

    /**
     * Pide `$cantidad` códigos seguidos (uno por minuto) y devuelve el último.
     *
     * @param  string  $email
     * @param  int  $cantidad
     * @return string
     */
    protected function pedir_codigos($email, $cantidad)
    {
        $ultimo = null;

        for ($i = 0; $i < $cantidad; $i++) {
            $ultimo = $this->pedir_codigo($email);
            $this->travel(1)->minutes();
        }

        return $ultimo;
    }

    /**
     * El 422 del tope de pedidos, con la forma de validación que la SPA convierte en toast.
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_tope_de_pedidos($respuesta)
    {
        $respuesta->assertStatus(422);
        $respuesta->assertJson([
            'email_send' => false,
            'message'    => 'Demasiados pedidos de código',
            'errors'     => ['email' => ['Pediste demasiados códigos de recuperación. Esperá una hora y volvé a intentar.']],
        ]);

        $cuerpo = json_decode($respuesta->getContent());
        $this->assertIsObject($cuerpo->errors, '`errors` tiene que ser un mapa campo → mensajes: si no, la SPA no muestra el toast.');
    }

    /**
     * El agujero que quedaba: cada código nuevo arranca con 0 intentos, así que pidiendo otro se
     * reiniciaba el tope de intentos sin límite. El 6.º pedido de la hora da 422, no genera código, no
     * manda mail y no toca el pendiente: el del 5.º sigue sirviendo.
     *
     * @test
     */
    public function al_sexto_pedido_en_la_hora_da_422_sin_mail_y_el_codigo_del_quinto_sigue_sirviendo()
    {
        $quinto = $this->pedir_codigos($this->usuario->email, 5);

        Mail::assertSent(PasswordReset::class, 5);

        $this->assert_tope_de_pedidos($this->postJson(self::SEND, ['email' => $this->usuario->email]));

        Mail::assertSent(PasswordReset::class, 5);

        // El pendiente sigue siendo el del 5.º: verifica y cambia la contraseña.
        $this->assertTrue($this->verificar($this->usuario->email, $quinto));
        $this->actualizar($this->usuario->email, $quinto)->assertStatus(200)->assertExactJson(['password_updated' => true]);
    }

    /**
     * Pasada la hora se puede volver a pedir, y el código nuevo sirve.
     *
     * @test
     */
    public function pasada_la_hora_se_puede_volver_a_pedir()
    {
        $this->pedir_codigos($this->usuario->email, 5);

        $this->assert_tope_de_pedidos($this->postJson(self::SEND, ['email' => $this->usuario->email]));

        $this->travel(61)->minutes();

        $nuevo = $this->pedir_codigo($this->usuario->email);

        Mail::assertSent(PasswordReset::class, 6);
        $this->assertTrue($this->verificar($this->usuario->email, $nuevo));
    }

    /**
     * La ventana es FIJA desde el primer pedido: los pedidos siguientes no la corren. Si se corriera,
     * pedir uno cada tanto la estiraría para siempre.
     *
     * @test
     */
    public function la_ventana_es_fija_desde_el_primer_pedido()
    {
        // El primero a los 0 minutos; los otros cuatro a los 50.
        $this->pedir_codigo($this->usuario->email);
        $this->travel(50)->minutes();
        $this->pedir_codigos($this->usuario->email, 4);

        // Minuto 54: todavía dentro de la ventana del primero.
        $this->assert_tope_de_pedidos($this->postJson(self::SEND, ['email' => $this->usuario->email]));

        // Minuto 61 desde el primero (y solo 11 desde el último): la ventana ya venció.
        $this->travel(7)->minutes();
        $this->pedir_codigo($this->usuario->email);
    }

    /**
     * El tope es por usuario: que uno lo haya alcanzado no frena a otro.
     *
     * @test
     */
    public function el_tope_es_por_usuario()
    {
        $this->pedir_codigos($this->usuario->email, 5);
        $this->assert_tope_de_pedidos($this->postJson(self::SEND, ['email' => $this->usuario->email]));

        $otro = $this->crear_un_dueno();

        $this->pedir_codigo($otro->email);
    }

    /**
     * Un email que no existe no cuenta (no hay usuario): sigue siendo 200 `email_send: false` todas las
     * veces, sin mandar nada.
     *
     * @test
     */
    public function un_email_inexistente_no_cuenta_para_el_tope()
    {
        $email = 'nadie-' . uniqid() . '@ejemplo.test';

        for ($i = 0; $i < 7; $i++) {
            $this->postJson(self::SEND, ['email' => $email])->assertStatus(200)->assertExactJson(['email_send' => false]);
        }

        Mail::assertNothingSent();
    }

    /**
     * Update con una contraseña nueva dada (y el email del usuario del test).
     *
     * @param  mixed  $codigo
     * @param  mixed  $contrasena
     * @param  string|null  $email
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_con_contrasena($codigo, $contrasena, $email = null)
    {
        return $this->postJson(self::UPDATE, [
            'email'             => is_null($email) ? $this->usuario->email : $email,
            'verification_code' => $codigo,
            'password'          => $contrasena,
            'password_confirm'  => $contrasena,
        ]);
    }

    /**
     * El 422 de la contraseña nueva inválida, con la forma de validación (`errors.password`).
     *
     * @param  \Illuminate\Testing\TestResponse  $respuesta
     * @return void
     */
    protected function assert_contrasena_rechazada($respuesta)
    {
        $respuesta->assertStatus(422);
        $respuesta->assertJsonStructure(['message', 'errors' => ['password']]);
        $respuesta->assertJson(['password_updated' => false]);
        $this->assertNull($respuesta->json('errors.verification_code'), 'Con la contraseña mala ni se llega a mirar el código.');

        $cuerpo = json_decode($respuesta->getContent());
        $this->assertIsObject($cuerpo->errors);
        $this->assertNotSame('', trim((string) $cuerpo->errors->password[0]));
    }

    /**
     * La contraseña nueva se valida del lado del servidor (texto, no vacía, mínimo 6 caracteres), ANTES
     * que el código: una contraseña mala no cambia nada, no consume el código ni le suma intentos (son más
     * de 5 rechazos y el código sigue sirviendo).
     *
     * @test
     */
    public function una_contrasena_nueva_invalida_da_422_sin_consumir_el_codigo_ni_sumar_intentos()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        foreach (['', null, '12345', '      ', ['una-lista'], 'abc', 'x'] as $contrasena) {
            $this->assert_contrasena_rechazada($this->actualizar_con_contrasena($codigo, $contrasena));
        }

        // Sin el campo `password`.
        $this->assert_contrasena_rechazada($this->postJson(self::UPDATE, [
            'email'             => $this->usuario->email,
            'verification_code' => $codigo,
        ]));

        // Con un código EQUIVOCADO y la contraseña mala, tampoco suma intentos: la contraseña se mira
        // antes. Son 5 (los que matarían el código si contaran).
        for ($i = 0; $i < 5; $i++) {
            $this->assert_contrasena_rechazada($this->actualizar_con_contrasena($this->otro_codigo($codigo), '123'));
        }

        $this->assert_nada_cambio();

        // Trece rechazos después, el código sigue vivo: no se consumió ni sumó intentos.
        $this->assertTrue($this->verificar($this->usuario->email, $codigo));
        $this->actualizar($this->usuario->email, $codigo)->assertStatus(200)->assertExactJson(['password_updated' => true]);
    }

    /**
     * La contraseña inválida da la MISMA respuesta con un email que no existe (no se lo usa de oráculo).
     *
     * @test
     */
    public function una_contrasena_invalida_da_lo_mismo_con_un_email_inexistente()
    {
        $existe = $this->actualizar_con_contrasena('123456', '123');
        $no_existe = $this->actualizar_con_contrasena('123456', '123', 'nadie-' . uniqid() . '@ejemplo.test');

        $this->assert_contrasena_rechazada($existe);
        $this->assertSame($existe->json(), $no_existe->json());
    }

    /**
     * El borde: 6 caracteres alcanzan.
     *
     * @test
     */
    public function una_contrasena_de_seis_caracteres_alcanza()
    {
        $codigo = $this->pedir_codigo($this->usuario->email);

        $this->actualizar_con_contrasena($codigo, 'abc123')->assertStatus(200)->assertExactJson(['password_updated' => true]);

        $this->assertTrue(Hash::check('abc123', (string) $this->usuario->fresh()->password));
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
