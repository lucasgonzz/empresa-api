<?php

namespace Tests\Feature\ResetDatosNegocio;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ResetDatosNegocio\Concerns\BaseDescartableParaElReset;
use Tests\TestCase;

/**
 * CA8 y CA9 — Los frenos del reset de datos de negocio y el registro de cada intento (misión reset-datos-cliente).
 *
 * El reset borra datos de producción, así que cada freno falla CERRADO y se prueba con la misma vara: el pedido se
 * rechaza con el código del contrato (C1) y la base queda EXACTAMENTE como estaba (mismas filas en todas las
 * tablas, mismo saldo de caja, mismos archivos). Los frenos de estado (varios dueños, tabla sin clasificar,
 * comprobantes con CAE, integraciones conectadas) se prueban de la forma realista: se simula con la base sana
 * (se obtiene el token), después aparece el problema, y recién ahí se intenta ejecutar.
 *
 * Corre sobre la base descartable y con el storage redirigido (ver BaseDescartableParaElReset).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados ni union types.
 */
class FrenosDelResetTest extends TestCase
{
    use BaseDescartableParaElReset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->iniciar_entorno_del_reset();
    }

    protected function tearDown(): void
    {
        $this->terminar_entorno_del_reset();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Ayudas de este archivo
    // ---------------------------------------------------------------------------------------------------------

    /**
     * Exige un 409 con ese código del contrato.
     *
     * @param \Illuminate\Testing\TestResponse $respuesta Respuesta recibida.
     * @param string $codigo Código esperado en `error`.
     * @return void
     */
    protected function assertRechazo409($respuesta, $codigo)
    {
        $respuesta->assertStatus(409);
        $respuesta->assertJson(['ok' => false, 'error' => $codigo]);
        $this->assertNotEmpty($respuesta->json('mensaje'), 'El rechazo trae un mensaje para mostrar.');
    }

    /**
     * Provoca un frenazo de estado: obtiene un token con la base sana, deja que `$preparar` rompa algo, intenta
     * ejecutar, exige el 409 con ese código y la base intacta, y deshace el problema con `$deshacer`.
     *
     * @param string $codigo Código esperado.
     * @param callable $preparar Crea el problema.
     * @param callable|null $deshacer Lo quita (para poder seguir en el mismo test).
     * @param array $extra Parámetros extra de la simulación y de la ejecución.
     * @return \Illuminate\Testing\TestResponse La respuesta del rechazo.
     */
    protected function provocar_un_frenazo($codigo, callable $preparar, $deshacer = null, array $extra = [])
    {
        $token = $this->obtener_un_token_de_simulacion($extra);

        $preparar();
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        $respuesta = $this->ejecutar_el_reset($token, $extra);

        $this->assertRechazo409($respuesta, $codigo);
        $this->assertBaseIntacta($foto, 'Frenazo ' . $codigo);
        $this->assertCandadoLibre();

        if ($deshacer !== null) {
            $deshacer();
        }

        return $respuesta;
    }

    /**
     * Agrega un segundo dueño (usuario sin owner_id).
     *
     * @return int Id del usuario.
     */
    protected function agregar_un_segundo_dueno()
    {
        return DB::table('users')->insertGetId([
            'name' => 'Segundo dueño', 'email' => 'segundo-dueno-' . getmypid() . '@ejemplo.test',
            'password' => 'no-importa', 'status' => 'commerce', 'owner_id' => null,
        ]);
    }

    /**
     * Crea una tabla que ningún catálogo del reset conoce (como una migración nueva que nadie clasificó).
     *
     * @return string Nombre de la tabla.
     */
    protected function crear_una_tabla_sin_clasificar()
    {
        $nombre = 'tabla_nueva_sin_clasificar_' . getmypid();
        DB::statement('CREATE TABLE `' . $nombre . '` (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY)');

        return $nombre;
    }

    /**
     * Agrega una factura con CAE.
     *
     * @param bool $de_produccion Si el punto de venta de la AFIP info es de producción (afip_ticket_production).
     * @param string|null $cae CAE de la factura (null = una factura que ARCA nunca autorizó).
     * @return int Id del comprobante.
     */
    protected function agregar_un_comprobante($de_produccion, $cae)
    {
        $id_afip_information = (int) DB::table('afip_information')->orderBy('id')->value('id');
        DB::table('afip_information')->where('id', $id_afip_information)->update(['afip_ticket_production' => $de_produccion ? 1 : 0]);

        return DB::table('afip_tickets')->insertGetId([
            'cae' => $cae, 'afip_information_id' => $id_afip_information, 'cbte_numero' => '1', 'punto_venta' => '1',
        ]);
    }

    /**
     * Conecta (o no) una plataforma al dueño.
     *
     * @param string $slug Slug de la tabla `platforms` (tienda_nube, mercado_libre, mercado_pago...).
     * @param string $estado Estado del conector (conectado, sin_conectar, error).
     * @return int Id del conector.
     */
    protected function agregar_un_conector($slug, $estado)
    {
        $id_de_plataforma = DB::table('platforms')->where('slug', $slug)->value('id');
        $this->assertNotNull($id_de_plataforma, 'La base del fixture tiene que traer la plataforma ' . $slug);

        return DB::table('platform_connectors')->insertGetId([
            'user_id' => $this->id_del_dueno, 'platform_id' => $id_de_plataforma, 'status' => $estado,
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------
    // La clave de admin (401)
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_sin_clave_401()
    {
        $foto = $this->foto_de_las_tablas();

        $respuesta = $this->pedir_el_reset([], false);
        $respuesta->assertStatus(401);
        $respuesta->assertJson(['error' => 'unauthorized']);

        // Tampoco una ejecución completa pasa sin la clave.
        $this->pedir_el_reset(['dry_run' => false, 'confirmar_base_de_datos' => $this->base_descartable, 'token_de_simulacion' => str_repeat('a', 40)], false)
            ->assertStatus(401);

        $this->assertBaseIntacta($foto);
    }

    /** @test */
    public function test_clave_errada_401()
    {
        $foto = $this->foto_de_las_tablas();

        $this->pedir_el_reset([], 'una-clave-que-no-es')->assertStatus(401)->assertJson(['error' => 'unauthorized']);
        // La clave correcta con otra capitalización o con basura al final no es la clave.
        $this->pedir_el_reset([], strtoupper($this->clave_de_admin_del_test))->assertStatus(401);
        $this->pedir_el_reset([], $this->clave_de_admin_del_test . 'x')->assertStatus(401);

        $this->assertBaseIntacta($foto);
    }

    /** @test */
    public function test_instancia_sin_clave_401()
    {
        $foto = $this->foto_de_las_tablas();

        // Sin ADMIN_API_INBOUND_KEY cargada la ruta no queda abierta: falla cerrado, mande lo que mande el pedido.
        config(['services.admin_api.api_key' => null]);
        $this->pedir_el_reset([], false)->assertStatus(401);
        $this->pedir_el_reset([], 'cualquier-cosa')->assertStatus(401);
        $this->pedir_el_reset([], '')->assertStatus(401);

        config(['services.admin_api.api_key' => '']);
        $this->pedir_el_reset([], 'cualquier-cosa')->assertStatus(401);

        $this->assertBaseIntacta($foto);
    }

    // ---------------------------------------------------------------------------------------------------------
    // La confirmación de la ejecución (409 confirmacion_invalida)
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_sin_token_409()
    {
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        // dry_run=false con el nombre de la base bien, pero sin token de una simulación.
        $this->assertRechazo409($this->ejecutar_el_reset(null), 'confirmacion_invalida');
        $this->assertRechazo409($this->ejecutar_el_reset(''), 'confirmacion_invalida');
        // Un token que parece uno (40 caracteres) pero nadie emitió.
        $this->assertRechazo409($this->ejecutar_el_reset(str_repeat('a', 40)), 'confirmacion_invalida');

        $this->assertBaseIntacta($foto);
        $this->assertCandadoLibre();
    }

    /** @test */
    public function test_nombre_de_base_errado_409()
    {
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        $variantes = [
            'otra_base' => 'empresa_de_otro_cliente',
            'mayusculas' => strtoupper($this->base_descartable),
            'con_un_caracter_de_mas' => $this->base_descartable . 'x',
            'con_espacios' => ' ' . $this->base_descartable . ' ',
            'la_base_del_slot' => $this->base_del_slot,
            'vacio' => '',
        ];

        foreach ($variantes as $descripcion => $nombre) {
            // Un token bueno y fresco para cada intento: lo único malo es el nombre.
            $token = $this->obtener_un_token_de_simulacion();
            $this->assertRechazo409($this->ejecutar_el_reset($token, [], $nombre), 'confirmacion_invalida');
            $this->assertBaseIntacta($foto, 'Variante ' . $descripcion);
        }

        // Sin el campo `confirmar_base_de_datos`.
        $token = $this->obtener_un_token_de_simulacion();
        $this->assertRechazo409($this->pedir_el_reset(['dry_run' => false, 'token_de_simulacion' => $token]), 'confirmacion_invalida');
        $this->assertBaseIntacta($foto, 'Sin el campo de confirmación');
    }

    /** @test */
    public function test_token_vencido_o_usado_409()
    {
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        // Vencido: pasaron 16 minutos desde la simulación.
        $token = $this->obtener_un_token_de_simulacion();
        Carbon::setTestNow(Carbon::now()->addMinutes(16));
        $this->assertRechazo409($this->ejecutar_el_reset($token), 'confirmacion_invalida');
        $this->assertBaseIntacta($foto, 'Token vencido');

        // Justo antes de vencer todavía sirve (14 minutos): el límite es el del plan, no uno más corto.
        Carbon::setTestNow();
        $token_vigente = $this->obtener_un_token_de_simulacion();
        Carbon::setTestNow(Carbon::now()->addMinutes(14));
        $this->ejecutar_el_reset($token_vigente)->assertStatus(200);
        Carbon::setTestNow();

        // Usado: el mismo token no sirve dos veces (la base ya está vacía; el segundo intento tiene que rebotar
        // por el token, no por otra cosa).
        $this->assertSame(0, DB::table('articles')->count());
        $this->assertRechazo409($this->ejecutar_el_reset($token_vigente), 'confirmacion_invalida');
        $this->assertSame(0, DB::table('articles')->count());
    }

    // ---------------------------------------------------------------------------------------------------------
    // El candado (409 en_curso)
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_candado_tomado_409_en_curso()
    {
        $token = $this->obtener_un_token_de_simulacion();
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        // Hay un user setup / demo setup / otro reset corriendo.
        $this->tomar_el_candado_de_los_setups();

        // Ni la simulación ni la ejecución pasan mientras tanto.
        $this->assertRechazo409($this->simular_el_reset(), 'en_curso');
        $this->assertRechazo409($this->ejecutar_el_reset($token), 'en_curso');
        $this->assertBaseIntacta($foto, 'Con el candado tomado');

        // Cuando la otra corrida termina, el reset vuelve a andar (y el rebote no dejó el candado tomado).
        \App\Http\Controllers\Helpers\DemoSetupLockHelper::soltar($this->candado_del_test);
        $this->candado_del_test = false;
        $this->simular_el_reset()->assertStatus(200)->assertJson(['ejecutable' => true]);
        $this->assertCandadoLibre();
    }

    // ---------------------------------------------------------------------------------------------------------
    // Los chequeos de estado
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_dos_duenos_409()
    {
        $id_del_segundo = 0;
        $this->provocar_un_frenazo('varios_duenos', function () use (&$id_del_segundo) {
            $id_del_segundo = $this->agregar_un_segundo_dueno();
        }, function () use (&$id_del_segundo) {
            DB::table('users')->where('id', $id_del_segundo)->delete();
        });

        // Un empleado (con owner_id) NO es un dueño: con un solo dueño y empleados, el reset se puede.
        $respuesta = $this->simular_el_reset();
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('ejecutable'));
    }

    /** @test */
    public function test_sin_ningun_dueno_tambien_se_niega()
    {
        // Cero dueños tampoco es "exactamente uno": el reset no adivina de quién es la base.
        DB::table('users')->where('id', $this->id_del_dueno)->update(['owner_id' => $this->id_del_empleado]);
        $foto = $this->foto_de_las_tablas();

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ejecutable' => false]);
        $this->assertContains('varios_duenos', array_column($respuesta->json('bloqueos'), 'codigo'));
        $this->assertNull($respuesta->json('token_de_simulacion'));
        $this->assertBaseIntacta($foto);
    }

    /** @test */
    public function test_tabla_sin_clasificar_409()
    {
        $nombre = '';
        $respuesta = $this->provocar_un_frenazo('tablas_sin_clasificar', function () use (&$nombre) {
            $nombre = $this->crear_una_tabla_sin_clasificar();
        });

        // El rechazo dice cuál es la tabla que falta clasificar.
        $this->assertContains($nombre, $respuesta->json('detalle.tablas'));

        // La simulación lo informa como bloqueo, sin token.
        $simulacion = $this->simular_el_reset();
        $simulacion->assertStatus(200);
        $simulacion->assertJson(['ejecutable' => false]);
        $this->assertNull($simulacion->json('token_de_simulacion'));
        $bloqueos = $simulacion->json('bloqueos');
        $indice = array_search('tablas_sin_clasificar', array_column($bloqueos, 'codigo'));
        $this->assertNotFalse($indice, 'La simulación tiene que traer el bloqueo tablas_sin_clasificar.');
        $this->assertContains($nombre, $bloqueos[$indice]['detalle']['tablas']);
    }

    /** @test */
    public function test_comprobante_con_cae_de_produccion_409_y_con_flag_200()
    {
        $id_del_comprobante = 0;
        $respuesta = $this->provocar_un_frenazo('comprobantes_con_cae', function () use (&$id_del_comprobante) {
            $id_del_comprobante = $this->agregar_un_comprobante(true, '70123456789012');
        });
        $this->assertSame(1, $respuesta->json('detalle.cantidad'));

        // La simulación lo informa y no entrega token.
        $simulacion = $this->simular_el_reset();
        $simulacion->assertStatus(200);
        $simulacion->assertJson(['ejecutable' => false]);
        $this->assertNull($simulacion->json('token_de_simulacion'));
        $this->assertContains('comprobantes_con_cae', array_column($simulacion->json('bloqueos'), 'codigo'));

        // Con el flag marcado a propósito, se puede: simulación ejecutable, ejecución 200, y el comprobante se vacía
        // (el volcado previo lo conserva).
        $extra = ['incluir_comprobantes_con_cae' => true];
        $this->simular_y_ejecutar_el_reset($extra)->assertStatus(200)->assertJson(['ok' => true, 'dry_run' => false]);
        $this->assertSame(0, DB::table('afip_tickets')->count());
        $this->assertSame(0, DB::table('articles')->count());
    }

    /** @test */
    public function test_los_comprobantes_de_homologacion_o_sin_cae_no_frenan()
    {
        // Factura con CAE pero de un punto de venta de HOMOLOGACIÓN (afip_ticket_production = 0).
        $this->agregar_un_comprobante(false, '70123456789012');
        $respuesta = $this->simular_el_reset();
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('ejecutable'), 'Un CAE de homologación no frena.');

        // Punto de venta de producción, pero un comprobante que ARCA nunca autorizó (sin CAE).
        DB::table('afip_tickets')->delete();
        $this->agregar_un_comprobante(true, null);
        $respuesta = $this->simular_el_reset();
        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('ejecutable'), 'Un comprobante sin CAE no frena.');
    }

    /**
     * Plataformas que frenan el reset cuando están conectadas.
     *
     * @return array
     */
    public function plataformas_que_frenan()
    {
        return [
            'tienda_nube' => ['tienda_nube'],
            'mercado_libre' => ['mercado_libre'],
        ];
    }

    /**
     * @test
     * @dataProvider plataformas_que_frenan
     */
    public function test_integracion_conectada_409_y_con_flag_200($slug)
    {
        $respuesta = $this->provocar_un_frenazo('integraciones_conectadas', function () use ($slug) {
            $this->agregar_un_conector($slug, 'conectado');
        });
        $this->assertContains($slug, $respuesta->json('detalle.plataformas'));

        // La simulación lo informa y no entrega token.
        $simulacion = $this->simular_el_reset();
        $simulacion->assertJson(['ejecutable' => false]);
        $this->assertNull($simulacion->json('token_de_simulacion'));
        $this->assertContains('integraciones_conectadas', array_column($simulacion->json('bloqueos'), 'codigo'));

        // Con el flag marcado a propósito se puede, y la conexión (configuración) sigue en pie.
        $this->simular_y_ejecutar_el_reset(['incluir_integraciones_conectadas' => true])->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertSame(0, DB::table('articles')->count());
        $this->assertSame(1, DB::table('platform_connectors')->where('status', 'conectado')->count());
    }

    /** @test */
    public function test_las_integraciones_no_conectadas_o_ajenas_no_frenan()
    {
        // Tienda Nube sin conectar (o con error) no frena; Mercado Pago conectado tampoco: sólo TN y ML publican
        // artículos afuera con el id como SKU.
        $this->agregar_un_conector('tienda_nube', 'sin_conectar');
        $this->agregar_un_conector('mercado_libre', 'error');
        $this->agregar_un_conector('mercado_pago', 'conectado');

        $respuesta = $this->simular_el_reset();

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('bloqueos'));
        $this->assertTrue($respuesta->json('ejecutable'));
    }

    // ---------------------------------------------------------------------------------------------------------
    // La forma del pedido (422) y el dry_run estricto
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_parametro_desconocido_422()
    {
        $foto = $this->foto_de_las_tablas();

        $respuesta = $this->pedir_el_reset(['parametro_inventado' => 'x']);
        $respuesta->assertStatus(422);
        $respuesta->assertJson(['ok' => false, 'error' => 'validacion']);
        $this->assertStringContainsString('parametro_inventado', json_encode($respuesta->json('detalle')), 'El detalle dice qué campo sobra.');

        // También en una ejecución completa con token válido: no se ejecuta con un pedido mal formado.
        $token = $this->obtener_un_token_de_simulacion();
        $this->ejecutar_el_reset($token, ['borrar_todo' => true])->assertStatus(422)->assertJson(['error' => 'validacion']);

        // Largos máximos del contrato: solicitante 60, origen 20, motivo 120.
        $this->pedir_el_reset(['solicitante' => str_repeat('s', 61)])->assertStatus(422)->assertJson(['error' => 'validacion']);
        $this->pedir_el_reset(['origen' => str_repeat('o', 21)])->assertStatus(422)->assertJson(['error' => 'validacion']);
        $this->pedir_el_reset(['motivo' => str_repeat('m', 121)])->assertStatus(422)->assertJson(['error' => 'validacion']);

        $this->assertBaseIntacta($foto);
        $this->assertCandadoLibre();
    }

    /**
     * Valores de `dry_run` que NO son un "false" estricto: todos son una simulación.
     *
     * @return array
     */
    public function valores_ambiguos_de_dry_run()
    {
        return [
            'si' => ['si'],
            'no' => ['no'],
            'ejecutar' => ['ejecutar'],
            'true_texto' => ['true'],
            'uno' => [1],
            'dos' => [2],
            'verdadero' => [true],
            'vacio' => [''],
        ];
    }

    /**
     * @test
     * @dataProvider valores_ambiguos_de_dry_run
     */
    public function test_dry_run_ambiguo_no_borra($valor)
    {
        // Es el caso peligroso: el pedido trae TODO lo necesario para ejecutar (nombre de base y un token bueno),
        // pero `dry_run` no es un false estricto. Tiene que ser una simulación y no borrar nada.
        $token = $this->obtener_un_token_de_simulacion();
        $foto = $this->foto_de_las_tablas(['reset_datos_negocio_registros']);

        $respuesta = $this->pedir_el_reset([
            'dry_run' => $valor,
            'confirmar_base_de_datos' => $this->base_descartable,
            'token_de_simulacion' => $token,
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ok' => true, 'dry_run' => true]);
        $this->assertBaseIntacta($foto, 'dry_run=' . json_encode($valor));
    }

    /**
     * Las cuatro formas de pedir la ejecución: sólo false, 0, '0' y 'false' ejecutan.
     *
     * @return array
     */
    public function formas_de_dry_run_false()
    {
        return [
            'false_booleano' => [false],
            'cero_numero' => [0],
            'cero_texto' => ['0'],
            'false_texto' => ['false'],
        ];
    }

    /**
     * @test
     * @dataProvider formas_de_dry_run_false
     */
    public function test_dry_run_false_en_sus_cuatro_formas_ejecuta($valor)
    {
        $token = $this->obtener_un_token_de_simulacion();

        $respuesta = $this->pedir_el_reset([
            'dry_run' => $valor,
            'confirmar_base_de_datos' => $this->base_descartable,
            'token_de_simulacion' => $token,
        ]);

        $respuesta->assertStatus(200);
        $respuesta->assertJson(['ok' => true, 'dry_run' => false, 'estado' => 'ok']);
        $this->assertSame(0, DB::table('articles')->count());
        $this->assertSame(0, DB::table('sales')->count());
    }

    // ---------------------------------------------------------------------------------------------------------
    // El registro de cada intento (CA9)
    // ---------------------------------------------------------------------------------------------------------

    /** @test */
    public function test_cada_intento_queda_registrado_sin_secretos()
    {
        // Un user agent larguísimo en todos los pedidos: el registro lo recorta (≤ 120).
        $agente = 'Mozilla/5.0 (reset-test) ' . str_repeat('x', 300);
        $this->withHeaders(['User-Agent' => $agente]);
        $respuestas = [];
        $esperadas = 0;

        // 1) Una simulación con identificación explícita.
        $r = $this->pedir_el_reset(['solicitante' => 'agente-test', 'origen' => 'claude', 'motivo' => 'prueba de aceptación']);
        $r->assertStatus(200);
        $respuestas[] = $r;
        $token = $r->json('token_de_simulacion');
        $id_de_la_simulacion = $r->json('registro_id');
        $esperadas++;

        // 2) Una ejecución rechazada por el nombre de la base.
        $r = $this->ejecutar_el_reset($token, ['solicitante' => 'agente-test', 'origen' => 'claude'], 'otra_base');
        $this->assertRechazo409($r, 'confirmacion_invalida');
        $respuestas[] = $r;
        $esperadas++;

        // 3) Una ejecución rechazada por el candado tomado.
        $token_2 = $this->obtener_un_token_de_simulacion();
        $esperadas++;
        $this->tomar_el_candado_de_los_setups();
        $r = $this->ejecutar_el_reset($token_2, ['solicitante' => 'agente-test', 'origen' => 'claude']);
        $this->assertRechazo409($r, 'en_curso');
        $respuestas[] = $r;
        $esperadas++;
        \App\Http\Controllers\Helpers\DemoSetupLockHelper::soltar($this->candado_del_test);
        $this->candado_del_test = false;

        // 4) Una ejecución rechazada por un segundo dueño.
        $token_3 = $this->obtener_un_token_de_simulacion();
        $esperadas++;
        $id_del_segundo = $this->agregar_un_segundo_dueno();
        $r = $this->ejecutar_el_reset($token_3, ['solicitante' => 'agente-test', 'origen' => 'claude']);
        $this->assertRechazo409($r, 'varios_duenos');
        $respuestas[] = $r;
        $esperadas++;
        DB::table('users')->where('id', $id_del_segundo)->delete();

        // 5) Una ejecución que sale bien.
        $token_4 = $this->obtener_un_token_de_simulacion();
        $esperadas++;
        $r = $this->ejecutar_el_reset($token_4, ['solicitante' => 'agente-test', 'origen' => 'claude', 'motivo' => 'reimportación'], null);
        $r->assertStatus(200);
        $respuestas[] = $r;
        $id_de_la_ejecucion = $r->json('registro_id');
        $esperadas++;

        // Una fila por cada simulación y por cada ejecución, rechazada o no.
        $filas = $this->filas_del_registro();
        $this->assertCount($esperadas, $filas, 'Una fila de registro por intento.');

        $codigos_vistos = [];
        foreach ($filas as $fila) {
            foreach (['modo', 'estado', 'codigo', 'conteos', 'ip', 'user_agent', 'solicitante', 'origen'] as $columna) {
                $this->assertTrue(property_exists($fila, $columna), 'Falta la columna ' . $columna);
            }
            $this->assertNotEmpty($fila->modo);
            $this->assertNotEmpty($fila->estado);
            $this->assertSame('127.0.0.1', $fila->ip);
            $this->assertNotEmpty($fila->user_agent);
            $this->assertLessThanOrEqual(120, strlen($fila->user_agent), 'El user agent se guarda recortado.');
            $this->assertNotEmpty($fila->solicitante);
            $this->assertNotEmpty($fila->origen);
            $codigos_vistos[] = $fila->codigo;
        }
        foreach (['confirmacion_invalida', 'en_curso', 'varios_duenos'] as $codigo) {
            $this->assertContains($codigo, $codigos_vistos, 'Falta la fila del rechazo ' . $codigo);
        }

        // La simulación con identificación explícita y la ejecución exitosa, con sus conteos.
        $por_id = [];
        foreach ($filas as $fila) {
            $por_id[$fila->id] = $fila;
        }
        $simulacion = $por_id[$id_de_la_simulacion];
        $this->assertSame('simulacion', $simulacion->modo);
        $this->assertSame('agente-test', $simulacion->solicitante);
        $this->assertSame('claude', $simulacion->origen);
        $this->assertSame(3, json_decode($simulacion->conteos, true)['articles']);
        $this->assertSame('Mozilla/5.0 (reset-test) ', substr($simulacion->user_agent, 0, 25));

        $ejecucion = $por_id[$id_de_la_ejecucion];
        $this->assertNotSame('simulacion', $ejecucion->modo);
        $this->assertSame('ok', $ejecucion->estado);
        $this->assertSame(3, json_decode($ejecucion->conteos, true)['articles']);

        // Sin solicitante ni origen el contrato pone 'admin' / 'admin'.
        $sin_identificar = $this->simular_el_reset();
        $sin_identificar->assertStatus(200);
        $fila_sin_identificar = DB::table('reset_datos_negocio_registros')->where('id', $sin_identificar->json('registro_id'))->first();
        $this->assertSame('admin', $fila_sin_identificar->solicitante);
        $this->assertSame('admin', $fila_sin_identificar->origen);

        // Nada secreto en ninguna fila, ni en el log, ni en las respuestas: ni la clave de admin ni un token en claro.
        $todo_lo_guardado = json_encode(DB::table('reset_datos_negocio_registros')->get()->all());
        $el_log = $this->texto_del_log_capturado();
        $respuestas[] = $this->getJson($this->ruta_del_reset . '/registros', ['X-Admin-Api-Key' => $this->clave_de_admin_del_test]);
        foreach ([$token, $token_2, $token_3, $token_4] as $secreto) {
            $this->assertStringNotContainsString($secreto, $todo_lo_guardado, 'Un token en claro quedó en el registro.');
            $this->assertStringNotContainsString($secreto, $el_log, 'Un token en claro quedó en el log.');
        }
        $this->assertStringNotContainsString($this->clave_de_admin_del_test, $todo_lo_guardado);
        $this->assertStringNotContainsString($this->clave_de_admin_del_test, $el_log);
        foreach ($respuestas as $respuesta) {
            $this->assertStringNotContainsString($this->clave_de_admin_del_test, $respuesta->getContent());
        }

        // El log lleva la misma información que el registro (como un warning), pero el user agent también recortado.
        $this->assertStringContainsString('WARNING', $el_log);
        $this->assertStringContainsString($this->base_descartable, $el_log);
        $this->assertStringContainsString('127.0.0.1', $el_log);
        $this->assertStringNotContainsString(str_repeat('x', 200), $el_log, 'El user agent entra recortado al log.');
        $this->assertStringNotContainsString('X-Admin-Api-Key', $el_log, 'Nunca se loguean headers.');
    }
}
