<?php

namespace Tests\Feature\ResetDatosNegocio;

use Illuminate\Support\Facades\DB;
use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * Freno de integraciones por credenciales de ENTORNO de Tienda Nube (misión reset-datos-cliente, endurecimiento).
 *
 * `BaseTiendaNubeService` usa TN_ACCESS_TOKEN / TN_USER_ID cuando no hay conector, y el scheduler sólo sincroniza si
 * el dueño tiene la extensión `usa_tienda_nube`. Con las dos cosas el reset se frena (409 integraciones_conectadas,
 * plataforma `tienda_nube`) aunque `platform_connectors` esté vacío; el flag explícito sigue permitiéndolo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class FrenoPorCredencialesDeTiendaNubeDelResetTest extends TestCase
{
    use BaseDescartableParaElReset;

    /** Valores originales de TN_ACCESS_TOKEN y TN_USER_ID (false si no estaban), para devolverlos en tearDown. */
    protected $tn_original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tn_original = ['TN_ACCESS_TOKEN' => getenv('TN_ACCESS_TOKEN'), 'TN_USER_ID' => getenv('TN_USER_ID')];
        $this->iniciar_entorno_del_reset();
        // Parte siempre sin credenciales de entorno y sin extensión, sea cual sea el .env de la máquina.
        $this->cargar_credenciales_de_entorno('', '');
        DB::table('extencion_empresa_user')->delete();
    }

    protected function tearDown(): void
    {
        // Se devuelven las variables de entorno como estaban para no contaminar otros tests del proceso.
        foreach ($this->tn_original as $nombre => $valor) {
            if ($valor === false) {
                putenv($nombre);
                unset($_ENV[$nombre], $_SERVER[$nombre]);
            } else {
                putenv($nombre . '=' . $valor);
                $_ENV[$nombre] = $valor;
                $_SERVER[$nombre] = $valor;
            }
        }
        $this->terminar_entorno_del_reset();
        parent::tearDown();
    }

    /**
     * Fija las credenciales de entorno de Tienda Nube (config y env, que es lo que miran el servicio y el freno).
     *
     * @param string $access_token Valor de TN_ACCESS_TOKEN.
     * @param string $user_id Valor de TN_USER_ID.
     * @return void
     */
    protected function cargar_credenciales_de_entorno($access_token, $user_id)
    {
        config(['tiendanube.access_token' => $access_token, 'tiendanube.store_id' => $user_id]);
        putenv('TN_ACCESS_TOKEN=' . $access_token);
        putenv('TN_USER_ID=' . $user_id);
        $_ENV['TN_ACCESS_TOKEN'] = $access_token;
        $_ENV['TN_USER_ID'] = $user_id;
        $_SERVER['TN_ACCESS_TOKEN'] = $access_token;
        $_SERVER['TN_USER_ID'] = $user_id;
    }

    /**
     * Asigna la extensión `usa_tienda_nube` a un usuario (la crea si no existe).
     *
     * @param int $id_de_usuario Dueño o empleado.
     * @return void
     */
    protected function darle_la_extension_de_tienda_nube($id_de_usuario)
    {
        $id = DB::table('extencion_empresas')->where('slug', 'usa_tienda_nube')->value('id');
        if ($id === null) {
            $id = DB::table('extencion_empresas')->insertGetId(['name' => 'Usa Tienda Nube', 'slug' => 'usa_tienda_nube']);
        }
        DB::table('extencion_empresa_user')->insert(['extencion_empresa_id' => $id, 'user_id' => $id_de_usuario]);
    }

    /** @test */
    public function test_credenciales_de_entorno_con_la_extension_frenan_y_el_flag_lo_permite()
    {
        $respuesta = $this->provocar_el_frenazo(function () {
            $this->cargar_credenciales_de_entorno('token-de-entorno', '12345');
            $this->darle_la_extension_de_tienda_nube($this->id_del_dueno);
        });
        $this->assertSame(['tienda_nube'], $respuesta->json('detalle.plataformas'));

        $simulacion = $this->simular_el_reset();
        $simulacion->assertJson(['ejecutable' => false]);
        $this->assertContains('integraciones_conectadas', array_column($simulacion->json('bloqueos'), 'codigo'));

        $this->simular_y_ejecutar_el_reset(['incluir_integraciones_conectadas' => true])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertSame(0, DB::table('articles')->count());
    }

    /** @test */
    public function test_con_conector_conectado_y_credenciales_la_plataforma_no_se_repite()
    {
        $this->agregar_el_conector_de_tienda_nube();
        $this->cargar_credenciales_de_entorno('token-de-entorno', '12345');
        $this->darle_la_extension_de_tienda_nube($this->id_del_dueno);

        $respuesta = $this->simular_el_reset();

        $bloqueo = collect($respuesta->json('bloqueos'))->firstWhere('codigo', 'integraciones_conectadas');
        $this->assertSame(['tienda_nube'], $bloqueo['detalle']['plataformas']);
    }

    /**
     * @test
     * @dataProvider combinaciones_que_no_frenan
     */
    public function test_sin_alguna_de_las_tres_condiciones_no_frena($access_token, $user_id, $con_extension)
    {
        $this->cargar_credenciales_de_entorno($access_token, $user_id);
        if ($con_extension) {
            $this->darle_la_extension_de_tienda_nube($this->id_del_dueno);
        }

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('bloqueos'));
    }

    /**
     * Combinaciones donde falta una de las condiciones (token, user id o extensión).
     *
     * @return array<string, array>
     */
    public function combinaciones_que_no_frenan()
    {
        return [
            'credenciales sin extensión' => ['token-de-entorno', '12345', false],
            'extensión sin credenciales' => ['', '', true],
            'extensión con token pero sin user id' => ['token-de-entorno', '', true],
            'extensión con user id pero sin token' => ['', '12345', true],
        ];
    }

    /** @test */
    public function test_la_extension_de_un_empleado_no_cuenta()
    {
        $this->cargar_credenciales_de_entorno('token-de-entorno', '12345');
        $this->darle_la_extension_de_tienda_nube($this->id_del_empleado);

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('bloqueos'));
    }

    /**
     * Obtiene un token con la base sana, prepara el problema, intenta ejecutar y exige el 409 y la base intacta.
     *
     * @param callable $preparar Crea el problema.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function provocar_el_frenazo(callable $preparar)
    {
        $token = $this->obtener_un_token_de_simulacion();
        $preparar();
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        $respuesta = $this->ejecutar_el_reset($token);

        $respuesta->assertStatus(409);
        $respuesta->assertJson(['ok' => false, 'error' => 'integraciones_conectadas']);
        $this->assertBaseIntacta($foto, 'Frenazo por credenciales de entorno de Tienda Nube');
        $this->assertCandadoLibre();

        return $respuesta;
    }

    /**
     * Conecta Tienda Nube por la tabla de conectores.
     *
     * @return void
     */
    protected function agregar_el_conector_de_tienda_nube()
    {
        $id_de_plataforma = DB::table('platforms')->where('slug', 'tienda_nube')->value('id');
        $this->assertNotNull($id_de_plataforma);
        DB::table('platform_connectors')->insert(['user_id' => $this->id_del_dueno, 'platform_id' => $id_de_plataforma, 'status' => 'conectado']);
    }
}
