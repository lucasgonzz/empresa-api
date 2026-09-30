<?php

namespace Tests\Feature\Configuracion;

use App\Models\OnlineConfiguration;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión config-solo-administrador (30/9/2026): la configuración general y la configuración online
 * solo las ESCRIBE el dueño o un empleado con admin_access.
 *
 * Pedido de Lucas: "que solo los usuarios con acceso administrador puedan acceder a la
 * configuración general o a la configuración online". La SPA ya escondía el menú, pero el API no
 * chequeaba nada: un empleado con la sesión abierta podía mandar el PUT a mano. Este test protege
 * el lado que no se ve: los cuatro endpoints de escritura, y que el GET de online-configuration
 * siga abierto (la SPA lo carga en el arranque para TODOS los usuarios).
 *
 * El comercio se crea acá mismo (dueño + dos empleados) para no depender del fixture sembrado, y
 * todo corre dentro de DatabaseTransactions.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class ConfiguracionSoloAdministradorTest extends TestCase
{
    use DatabaseTransactions;

    /** El 403 del middleware, palabra por palabra: la SPA lo muestra tal cual. */
    const MENSAJE = 'Solo el dueño o un administrador puede cambiar la configuración.';

    /** @var User */
    protected $dueno;

    /** @var User Empleado SIN admin_access. */
    protected $empleado;

    /** @var User Empleado CON admin_access. */
    protected $empleado_admin;

    /** @var OnlineConfiguration */
    protected $config;

    protected function setUp(): void
    {
        parent::setUp();

        // Nada de esto debe salir a la red: ni IA ni correo ni colas.
        config(['services.anthropic.api_key' => null]);
        Mail::fake();
        Queue::fake();

        $this->dueno = User::create([
            'name'         => 'Dueño config admin',
            'company_name' => 'Ferreteria config admin',
            'email'        => 'config-admin-dueno-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'         => 'Empleado raso config',
            'email'        => 'config-admin-empleado-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 0,
        ]);

        $this->empleado_admin = User::create([
            'name'         => 'Empleado administrador config',
            'email'        => 'config-admin-admin-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $this->dueno->id,
            'admin_access' => 1,
        ]);

        $this->config = OnlineConfiguration::create(['user_id' => $this->dueno->id]);
    }

    /**
     * Los cuatro endpoints de escritura, como [método, url, payload]. Los payloads son los mínimos
     * que hacen que, si el middleware deja pasar, el controlador falle rápido y sin efectos
     * externos (test-mail sin destino da 422 antes de enviar; generate-palette sin logo da 422
     * antes de llamar a la IA).
     *
     * @return array<string, array>
     */
    protected function endpoints_de_escritura()
    {
        return [
            'PUT online-configuration/{id}' => ['putJson', 'api/online-configuration/' . $this->config->id, [
                'instagram' => 'cambiado-por-config',
            ]],
            'POST online-configuration/test-mail' => ['postJson', 'api/online-configuration/test-mail', []],
            'POST online-configuration/generate-palette' => ['postJson', 'api/online-configuration/generate-palette', []],
            'PUT user/{id}' => ['putJson', 'api/user/' . $this->dueno->id, [
                'name' => 'Nombre cambiado por config',
            ]],
        ];
    }

    /**
     * @test
     * @group configuracion-solo-administrador
     */
    public function un_empleado_sin_admin_recibe_403_en_los_cuatro_endpoints_y_no_cambia_nada()
    {
        $this->actingAs($this->empleado, 'web');

        $instagram_antes = $this->config->fresh()->instagram;
        $nombre_antes = $this->dueno->fresh()->name;

        foreach ($this->endpoints_de_escritura() as $etiqueta => $endpoint) {
            list($metodo, $url, $payload) = $endpoint;

            $response = $this->{$metodo}($url, $payload);

            $response->assertStatus(403);
            $response->assertJson(['message' => self::MENSAJE]);
        }

        // El 403 tiene que frenar ANTES de escribir: ninguna fila cambió.
        $this->assertSame($instagram_antes, $this->config->fresh()->instagram);
        $this->assertSame($nombre_antes, $this->dueno->fresh()->name);
    }

    /**
     * @test
     * @group configuracion-solo-administrador
     */
    public function un_empleado_con_admin_access_pasa_el_gate_en_los_cuatro_endpoints()
    {
        $this->actingAs($this->empleado_admin, 'web');

        foreach ($this->endpoints_de_escritura() as $etiqueta => $endpoint) {
            list($metodo, $url, $payload) = $endpoint;

            $response = $this->{$metodo}($url, $payload);

            $this->assertNotEquals(
                403,
                $response->getStatusCode(),
                $etiqueta . ': un empleado con admin_access no debería recibir 403.'
            );
        }
    }

    /**
     * @test
     * @group configuracion-solo-administrador
     */
    public function el_dueno_pasa_el_gate_en_los_cuatro_endpoints()
    {
        $this->actingAs($this->dueno, 'web');

        foreach ($this->endpoints_de_escritura() as $etiqueta => $endpoint) {
            list($metodo, $url, $payload) = $endpoint;

            $response = $this->{$metodo}($url, $payload);

            $this->assertNotEquals(
                403,
                $response->getStatusCode(),
                $etiqueta . ': el dueño no debería recibir 403.'
            );
        }
    }

    /**
     * El GET de la configuración online queda abierto: la SPA lo carga en el arranque para todos.
     *
     * @test
     * @group configuracion-solo-administrador
     */
    public function el_get_de_online_configuration_sigue_abierto_para_el_empleado_sin_admin()
    {
        $this->actingAs($this->empleado, 'web');

        $response = $this->getJson('api/online-configuration');

        $response->assertStatus(200);
    }

    /**
     * Las preferencias por persona NO pasan por el gate: cualquier empleado las tiene que poder
     * usar (mismo endpoint que ya cubre Preferencias/1_Modo_oscuro_Test).
     *
     * @test
     * @group configuracion-solo-administrador
     */
    public function las_preferencias_por_persona_siguen_abiertas_para_el_empleado_sin_admin()
    {
        $this->actingAs($this->empleado, 'web');

        $response = $this->putJson('api/user/set-dark-mode/1');

        $response->assertStatus(200);
        $this->assertEquals(1, $this->empleado->fresh()->dark_mode);
    }
}
