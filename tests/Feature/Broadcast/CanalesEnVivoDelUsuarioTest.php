<?php

namespace Tests\Feature\Broadcast;

use App\Broadcasting\EspacioDeCanales;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * El contrato API → SPA (`canales_en_vivo` en el usuario) y el comando `canales:espacio` (misión
 * pusher-canales-por-sistema, 19/10/2026).
 *
 * El SPA arma el nombre físico de cada canal con lo que recibe acá; sin esta clave escucharía los
 * nombres lógicos, que desde esta misión no emite nadie de este sistema. Las claves (`espacio`,
 * `canales_viejos_de_tienda`) son las mismas que `Buyer::$appends` de tienda-api.
 *
 * PHP 7.4.
 */
class CanalesEnVivoDelUsuarioTest extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $dueno;

    /** @var User */
    protected $empleado;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.espacio'                   => '',
            'broadcasting.canales_viejos_de_tienda'  => true,
            'broadcasting.connections.pusher.secret' => 'secreto-que-no-se-imprime',
        ]);

        $this->dueno = User::create([
            'name'         => 'Dueño canales en vivo',
            'company_name' => 'Comercio canales en vivo',
            'email'        => 'canales-en-vivo-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado canales en vivo',
            'email'    => 'canales-en-vivo-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->dueno->id,
        ]);
    }

    /**
     * Espacio esperado, calculado a mano con las entradas reales.
     *
     * @return string
     */
    protected function espacio_esperado()
    {
        return EspacioDeCanales::calcular(
            DB::connection()->getDatabaseName(),
            config('app.env'),
            'secreto-que-no-se-imprime'
        );
    }

    /**
     * @test
     */
    public function get_api_user_trae_el_espacio_y_el_modo()
    {
        $this->actingAs($this->empleado, 'web');

        $respuesta = $this->getJson('api/user');

        $respuesta->assertStatus(200);

        $this->assertSame($this->espacio_esperado(), $respuesta->json('user.canales_en_vivo.espacio'));
        $this->assertTrue($respuesta->json('user.canales_en_vivo.canales_viejos_de_tienda'));
    }

    /**
     * Cualquier serialización del usuario la trae (el SPA reemplaza `state.user` desde varios
     * endpoints), y el modo estricto viaja como false.
     *
     * @test
     */
    public function cualquier_serializacion_del_usuario_la_trae_con_el_modo_vigente()
    {
        config(['broadcasting.canales_viejos_de_tienda' => false]);

        $datos = $this->dueno->fresh()->toArray();

        $this->assertArrayHasKey('canales_en_vivo', $datos);
        $this->assertSame(
            ['espacio' => $this->espacio_esperado(), 'canales_viejos_de_tienda' => false],
            $datos['canales_en_vivo']
        );

        // El atributo que ya estaba sigue.
        $this->assertArrayHasKey('modo_redondeo', $datos);
    }

    /**
     * Una sola línea JSON, con las cinco claves en el orden del contrato con tienda-api, y SIN el secret.
     *
     * @test
     */
    public function el_comando_muestra_el_espacio_y_nunca_el_secret()
    {
        $codigo = Artisan::call('canales:espacio');

        /** Salida cruda del comando. */
        $salida = trim(Artisan::output());

        $this->assertSame(0, $codigo);
        $this->assertStringNotContainsString("\n", $salida, 'Tiene que ser UNA línea.');
        $this->assertStringNotContainsString('secreto-que-no-se-imprime', $salida, '🔴 El comando imprimió el secret de Pusher.');

        $datos = json_decode($salida, true);

        $this->assertIsArray($datos, 'La salida no es JSON: ' . $salida);
        $this->assertSame(['base', 'entorno', 'espacio', 'canales_viejos_de_tienda', 'driver'], array_keys($datos));
        $this->assertSame(DB::connection()->getDatabaseName(), $datos['base']);
        $this->assertSame(config('app.env'), $datos['entorno']);
        $this->assertSame($this->espacio_esperado(), $datos['espacio']);
        $this->assertTrue($datos['canales_viejos_de_tienda']);
        $this->assertSame(config('broadcasting.default'), $datos['driver']);
    }
}
