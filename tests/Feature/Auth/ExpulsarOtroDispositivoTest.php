<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SessionForcedLogoutNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lucas pidió que el cartel de "tu cuenta está siendo utilizada en otro dispositivo" tenga una
 * opción para expulsar a ese otro dispositivo y entrar en el nuevo, sin que nadie tenga que ir a
 * cerrar sesión a mano ahí. El mecanismo: POST /login-forzado vuelve a validar credenciales,
 * avisa por broadcast (canal privado App.Models.User.{id}, ya autorizado en routes/channels.php)
 * ANTES de liberar el candado, y recién ahí loguea -el dispositivo viejo reacciona al broadcast
 * con un location.reload(), y si el broadcast no llega, su próximo /api/user igual lo rechaza
 * porque el candado ya es del dispositivo nuevo (AuthController::get_user()).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de antes.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ExpulsarOtroDispositivoTest extends TestCase
{
    use DatabaseTransactions;

    /** Valor de `session_id` con el que se simula que OTRO dispositivo tiene el candado tomado. */
    const CANDADO_DE_OTRO_DISPOSITIVO = 'candado-tomado-por-otro-dispositivo';

    /** Contraseña conocida para poder ejercitar /login-forzado (viene hasheada en la base sembrada). */
    const PASSWORD_DE_PRUEBA = 'zz-password-testing';

    /** @var \App\Models\User */
    protected $user;

    /** @var string Password hasheada original del usuario 500, restaurada en tearDown(). */
    protected $password_original;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        /** Sin este header no hay sesion en el request (ver BusDeEventosTest). */
        $this->withHeader('Referer', rtrim((string) config('app.url'), '/') . '/');

        $this->password_original = $this->user->password;
        $this->user->password = bcrypt(self::PASSWORD_DE_PRUEBA);
        $this->user->save();
    }

    protected function tearDown(): void
    {
        $this->user->password = $this->password_original;
        $this->user->save();

        parent::tearDown();
    }

    /**
     * Deja el candado tomado, simulando que otro dispositivo ya tiene una sesión activa.
     *
     * 🔴 `activity_minutes` se fuerza a 60 explícitamente. El usuario 500 sembrado en esta base
     * lo tiene en 0, y con 0 minutos de ventana `ya_paso_el_tiempo()` da `true` SIEMPRE -el
     * candado se consideraría vencido de entrada y el test no ejercitaría el camino real. Mismo
     * hallazgo que ya documentan CandadoLibreAlCambiarDeVersionTest y
     * CandadoEntrePestanasDelMismoNavegadorTest para este mismo usuario sembrado.
     *
     * @return void
     */
    protected function el_candado_esta_tomado_por_otro_dispositivo()
    {
        $this->user->activity_minutes = 60;
        $this->user->session_id = self::CANDADO_DE_OTRO_DISPOSITIVO;
        $this->user->last_activity = Carbon::now();
        $this->user->save();
    }

    /**
     * 🔴 EL CASO DEL PEDIDO: candado tomado por otro dispositivo, credenciales correctas →
     * login-forzado tiene que liberar el candado, loguear, y avisarle al dispositivo que lo
     * tenía tomado (la notificación, hacia el user_id correcto).
     *
     * @return void
     */
    public function test_login_forzado_libera_el_candado_loguea_y_avisa_al_otro_dispositivo()
    {
        Notification::fake();

        $this->el_candado_esta_tomado_por_otro_dispositivo();

        $respuesta = $this->postJson('/login-forzado', [
            'doc_number' => $this->user->doc_number,
            'password' => self::PASSWORD_DE_PRUEBA,
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('login'), 'Con credenciales correctas tiene que entrar, aunque el candado estuviera tomado.');
        $this->assertFalse($respuesta->json('user_last_activity'));
        $this->assertSame($this->user->id, $respuesta->json('user.id'));

        $usuario_actualizado = $this->user->fresh();
        $this->assertNotEquals(
            self::CANDADO_DE_OTRO_DISPOSITIVO,
            $usuario_actualizado->session_id,
            'El candado del dispositivo viejo tiene que quedar reemplazado por el del que forzó el ingreso.'
        );

        Notification::assertSentTo(
            $this->user,
            SessionForcedLogoutNotification::class
        );
    }

    /**
     * 🔴 LA GUARDA DE SEGURIDAD: nunca confiar en que el cliente ya validó las credenciales antes
     * de mostrar el botón. Con la contraseña mal, login-forzado tiene que rechazar igual que un
     * /login común -y, sobre todo, el candado del dispositivo que sí está trabajando NO se toca
     * ni se avisa nada por su canal.
     *
     * @return void
     */
    public function test_login_forzado_con_credenciales_incorrectas_no_toca_el_candado_ajeno()
    {
        Notification::fake();

        $this->el_candado_esta_tomado_por_otro_dispositivo();

        $respuesta = $this->postJson('/login-forzado', [
            'doc_number' => $this->user->doc_number,
            'password' => 'contraseña-equivocada',
        ]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('login'));

        $usuario_actualizado = $this->user->fresh();
        $this->assertSame(
            self::CANDADO_DE_OTRO_DISPOSITIVO,
            $usuario_actualizado->session_id,
            'Con credenciales incorrectas el candado del dispositivo real tiene que quedar intacto.'
        );

        Notification::assertNothingSent();
    }

    /**
     * No regresión: el candado tiene que seguir protegiendo el login NORMAL (sin forzar). Este
     * cambio agrega una vía para expulsar a propósito; no puede debilitar la que ya existe.
     *
     * @return void
     */
    public function test_login_normal_sigue_rechazado_con_el_candado_tomado()
    {
        $this->el_candado_esta_tomado_por_otro_dispositivo();

        $respuesta = $this->postJson('/login', [
            'doc_number' => $this->user->doc_number,
            'password' => self::PASSWORD_DE_PRUEBA,
        ]);

        $respuesta->assertStatus(200);
        $this->assertFalse($respuesta->json('login'));
        $this->assertTrue($respuesta->json('user_last_activity'));

        $usuario_actualizado = $this->user->fresh();
        $this->assertSame(
            self::CANDADO_DE_OTRO_DISPOSITIVO,
            $usuario_actualizado->session_id,
            'Un /login sin forzar jamás tiene que tocar el candado de otro dispositivo.'
        );
    }
}
