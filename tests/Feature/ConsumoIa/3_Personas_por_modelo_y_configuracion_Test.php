<?php

namespace Tests\Feature\ConsumoIa;

use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión proveedores-ia-deepseek (22/9/2026) — los dos bloques ADITIVOS del endpoint que el admin
 * consume (`GET api/admin-sync/consumo-ia`): `personas_modelos[]` y `configuracion`.
 *
 * 🔴 EL TEST DE LAS CLAVES SIGUE SIN SER DECORACIÓN. `personas_modelos[]` es el corte por persona
 * abierto además por proveedor y modelo, y sus diez claves son el contrato con `admin-api`, que lo
 * espeja con clave única de cinco dimensiones `(client_id, fecha, auth_user_id, proveedor, modelo)`.
 * Y `dias[]` y `personas[]` tienen que seguir EXACTAMENTE iguales: un admin viejo los lee con su
 * clave de siempre y, si `personas[]` se abriera por modelo, pisaría el total de cada persona con
 * la última fila y perdería consumo en silencio. Por eso acá se afirma sobre `array_keys()` enteras
 * de los tres cortes.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Personas_por_modelo_y_configuracion_Test extends TestCase
{
    use DatabaseTransactions;

    /** La clave que el admin manda en el header. */
    const CLAVE = 'clave-del-admin-para-los-tokens';

    /** La ruta bajo prueba. */
    const RUTA = 'api/admin-sync/consumo-ia';

    /** Las claves de `dias[]`, tal como estaban antes de esta misión. */
    const CLAVES_DE_DIAS = [
        'fecha', 'proceso', 'proveedor', 'modelo', 'llamadas',
        'input_tokens', 'output_tokens',
        'cache_creation_input_tokens', 'cache_read_input_tokens',
    ];

    /** Las claves de `personas[]`, tal como estaban antes de esta misión. */
    const CLAVES_DE_PERSONAS = [
        'fecha', 'auth_user_id', 'nombre', 'llamadas',
        'input_tokens', 'output_tokens',
        'cache_creation_input_tokens', 'cache_read_input_tokens',
    ];

    /** Las claves de `personas_modelos[]`: las de personas más proveedor y modelo antes de llamadas. */
    const CLAVES_DE_PERSONAS_MODELOS = [
        'fecha', 'auth_user_id', 'nombre', 'proveedor', 'modelo', 'llamadas',
        'input_tokens', 'output_tokens',
        'cache_creation_input_tokens', 'cache_read_input_tokens',
    ];

    /** @var User El dueño de la instancia. */
    protected $comercio;

    /** @var User Un empleado del mismo comercio: la "persona" del corte. */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.api_key' => self::CLAVE]);
        config(['services.admin_api.require_api_key' => false]);

        /* Las dos claves y modelos inventados: `configuracion` tiene que devolver los ids EFECTIVOS. */
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba',
            'services.anthropic.model'          => 'claude-general-test',
            'services.anthropic.model_agil'     => 'claude-agil-test',
            'services.anthropic.model_profundo' => 'claude-profundo-test',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba',
            'services.deepseek.model'           => 'deepseek-general-test',
            'services.deepseek.model_agil'      => 'deepseek-flash-test',
            'services.deepseek.model_profundo'  => 'deepseek-pro-test',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio de modelos',
            'company_name' => 'Ferretería de los modelos',
            'email'        => 'modelos-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Vendedora de los modelos',
            'email'    => 'modelos-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        /* La base de testing tiene varios dueños: sin esto, el dueño no se resuelve y todo daría 409. */
        config(['app.USER_ID' => $this->comercio->id]);
    }

    /**
     * @return array
     */
    protected function headers()
    {
        return ['X-Admin-Api-Key' => self::CLAVE];
    }

    /**
     * Graba una fila de consumo con fecha puesta a mano.
     *
     * @param  array   $datos
     * @param  string  $created_at
     * @return AiTokenUsage
     */
    protected function consumo(array $datos, $created_at)
    {
        return AiTokenUsage::create(array_merge([
            'user_id'                     => $this->comercio->id,
            'auth_user_id'                => null,
            'proceso'                     => 'chat_mensaje',
            'proveedor'                   => 'anthropic',
            'modelo'                      => 'claude-modelo-a',
            'input_tokens'                => 0,
            'output_tokens'               => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
            'created_at'                  => $created_at,
            'updated_at'                  => $created_at,
        ], $datos));
    }

    /**
     * El escenario: el MISMO día y la MISMA persona con dos modelos de dos proveedores (dos llamadas
     * con uno, una con el otro), más una fila automática y una de otro día.
     *
     * @return void
     */
    protected function sembrar()
    {
        /* 10/9, la empleada, Claude: dos llamadas → una fila sumada en personas_modelos. */
        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 100, 'output_tokens' => 10, 'cache_read_input_tokens' => 5,
        ], '2026-09-10 10:00:00');

        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 200, 'output_tokens' => 20, 'cache_read_input_tokens' => 7,
        ], '2026-09-10 18:30:00');

        /* 10/9, la MISMA empleada, DeepSeek: otra fila en personas_modelos, la MISMA en personas. */
        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'proveedor'    => 'deepseek',
            'modelo'       => 'deepseek-flash',
            'input_tokens' => 1000, 'output_tokens' => 100,
        ], '2026-09-10 12:00:00');

        /* 10/9, sin persona (automático), OpenAI. */
        $this->consumo([
            'proceso'   => 'embeddings_busqueda',
            'proveedor' => 'openai',
            'modelo'    => 'text-embedding-3-small',
            'input_tokens' => 50,
        ], '2026-09-10 11:15:00');

        /* 11/9, la empleada otra vez con Claude: otro día es otra fila. */
        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 7, 'output_tokens' => 1,
        ], '2026-09-11 09:00:00');

        /* Fuera del rango: no aparece en ningún corte. */
        $this->consumo(['input_tokens' => 999999], '2026-08-01 09:00:00');
    }

    /**
     * `personas_modelos[]` agrupa por las cuatro dimensiones (día, persona, proveedor, modelo) con
     * las claves exactas del contrato y los nombres resueltos.
     *
     * @group consumo-ia
     * @test
     */
    public function personas_modelos_agrupa_por_dia_persona_proveedor_y_modelo()
    {
        $this->sembrar();

        $respuesta = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                          ->assertStatus(200)
                          ->json();

        $this->assertArrayHasKey('personas_modelos', $respuesta);

        $filas = $respuesta['personas_modelos'];

        /* 🔴 El contrato, clave por clave y en orden. */
        $this->assertEquals(self::CLAVES_DE_PERSONAS_MODELOS, array_keys($filas[0]));

        /*
         * Cuatro filas: la empleada el 10 con Claude, la empleada el 10 con DeepSeek, los
         * automáticos el 10 con OpenAI, y la empleada el 11 con Claude. La misma persona el mismo
         * día con dos modelos son DOS filas: es exactamente lo que este bloque viene a abrir.
         */
        $this->assertCount(4, $filas);

        $claude_del_10 = $this->buscar($filas, [
            'fecha'        => '2026-09-10',
            'auth_user_id' => (int) $this->empleado->id,
            'proveedor'    => 'anthropic',
            'modelo'       => 'claude-modelo-a',
        ]);

        $this->assertNotNull($claude_del_10, 'Falta la fila de la empleada con Claude el 10/9.');
        $this->assertEquals('Vendedora de los modelos', $claude_del_10['nombre']);
        $this->assertEquals(2, $claude_del_10['llamadas'], 'Las dos llamadas del mismo día, persona y modelo se suman.');
        $this->assertEquals(300, $claude_del_10['input_tokens']);
        $this->assertEquals(30, $claude_del_10['output_tokens']);
        $this->assertEquals(12, $claude_del_10['cache_read_input_tokens']);

        $deepseek_del_10 = $this->buscar($filas, [
            'fecha'        => '2026-09-10',
            'auth_user_id' => (int) $this->empleado->id,
            'proveedor'    => 'deepseek',
            'modelo'       => 'deepseek-flash',
        ]);

        $this->assertNotNull($deepseek_del_10, 'La misma persona el mismo día con OTRO modelo es otra fila.');
        $this->assertEquals('Vendedora de los modelos', $deepseek_del_10['nombre']);
        $this->assertEquals(1, $deepseek_del_10['llamadas']);
        $this->assertEquals(1000, $deepseek_del_10['input_tokens']);
        $this->assertEquals(100, $deepseek_del_10['output_tokens']);

        $automaticos = $this->buscar($filas, ['fecha' => '2026-09-10', 'auth_user_id' => null]);

        $this->assertNotNull($automaticos, 'Los automáticos salen con auth_user_id null, como en personas[].');
        $this->assertNull($automaticos['nombre']);
        $this->assertEquals('openai', $automaticos['proveedor']);
        $this->assertEquals('text-embedding-3-small', $automaticos['modelo']);
        $this->assertEquals(50, $automaticos['input_tokens']);

        $this->assertNotNull($this->buscar($filas, ['fecha' => '2026-09-11', 'proveedor' => 'anthropic']), 'Otro día es otra fila.');
        $this->assertNull($this->buscar($filas, ['fecha' => '2026-08-01']), 'Se coló consumo de fuera del rango.');
    }

    /**
     * `dias[]` y `personas[]` siguen EXACTAMENTE iguales: mismas claves, y la persona con dos
     * modelos el mismo día sigue siendo UNA fila en `personas[]` (con todo sumado).
     *
     * @group consumo-ia
     * @test
     */
    public function dias_y_personas_no_cambian_de_forma()
    {
        $this->sembrar();

        $respuesta = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                          ->assertStatus(200)
                          ->json();

        $this->assertEquals(self::CLAVES_DE_DIAS, array_keys($respuesta['dias'][0]));
        $this->assertEquals(self::CLAVES_DE_PERSONAS, array_keys($respuesta['personas'][0]));

        /*
         * 🔴 Tres filas en personas[], no cuatro: la empleada el 10 es UNA fila aunque haya usado
         * dos modelos. Si esto diera cuatro, un admin viejo pisaría su total con la última fila.
         */
        $this->assertCount(3, $respuesta['personas']);

        $empleada_el_10 = $this->buscar($respuesta['personas'], [
            'fecha'        => '2026-09-10',
            'auth_user_id' => (int) $this->empleado->id,
        ]);

        $this->assertNotNull($empleada_el_10);
        $this->assertEquals(3, $empleada_el_10['llamadas'], 'Las tres llamadas (dos con Claude, una con DeepSeek) se suman en la misma fila.');
        $this->assertEquals(1300, $empleada_el_10['input_tokens']);
        $this->assertEquals(130, $empleada_el_10['output_tokens']);

        /* Y las claves de arriba del payload, en orden: las cuatro de siempre más las dos nuevas. */
        $this->assertEquals(
            ['user_id', 'desde', 'hasta', 'dias', 'personas', 'personas_modelos', 'configuracion'],
            array_keys($respuesta)
        );

        /* `dias[]` sigue abriendo por proceso, proveedor y modelo: cuatro filas. */
        $this->assertCount(4, $respuesta['dias']);
    }

    /**
     * `configuracion` refleja al dueño con los ids EFECTIVOS: proveedor, pensamiento, el modelo del
     * asistente (según su pensamiento) y el general (bot de WhatsApp y título).
     *
     * @group consumo-ia
     * @test
     */
    public function configuracion_refleja_al_dueno_con_los_modelos_efectivos()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'profundo';
        $this->comercio->save();

        $configuracion = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                              ->assertStatus(200)
                              ->json('configuracion');

        $this->assertEquals(['proveedor', 'pensamiento', 'modelo_asistente', 'modelo_general'], array_keys($configuracion));
        $this->assertEquals([
            'proveedor'        => 'deepseek',
            'pensamiento'      => 'profundo',
            'modelo_asistente' => 'deepseek-pro-test',
            'modelo_general'   => 'deepseek-general-test',
        ], $configuracion);

        /* El default del sistema: un dueño que nunca tocó la config es Claude / agil. */
        $this->comercio->agente_proveedor = 'anthropic';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->save();

        $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
             ->assertStatus(200)
             ->assertJson(['configuracion' => [
                 'proveedor'        => 'anthropic',
                 'pensamiento'      => 'agil',
                 'modelo_asistente' => 'claude-agil-test',
                 'modelo_general'   => 'claude-general-test',
             ]]);
    }

    /**
     * Si el dueño eligió DeepSeek y la instalación no tiene su clave, `configuracion` dice
     * Anthropic: es lo que efectivamente corre y lo que aparece en las filas de consumo. Y un
     * `equilibrado` guardado con el asistente corriendo en DeepSeek se informa como `agil`.
     *
     * @group consumo-ia
     * @test
     */
    public function configuracion_informa_lo_que_efectivamente_corre()
    {
        $this->comercio->agente_proveedor = 'deepseek';
        $this->comercio->agente_pensamiento = 'profundo';
        $this->comercio->save();

        config(['services.deepseek.api_key' => null]);

        $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
             ->assertStatus(200)
             ->assertJson(['configuracion' => [
                 'proveedor'        => 'anthropic',
                 'pensamiento'      => 'profundo',
                 'modelo_asistente' => 'claude-profundo-test',
                 'modelo_general'   => 'claude-general-test',
             ]]);

        config(['services.deepseek.api_key' => 'clave-deepseek-de-prueba']);
        $this->comercio->agente_pensamiento = 'equilibrado';
        $this->comercio->save();

        $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
             ->assertStatus(200)
             ->assertJson(['configuracion' => [
                 'proveedor'        => 'deepseek',
                 'pensamiento'      => 'agil',
                 'modelo_asistente' => 'deepseek-flash-test',
             ]]);
    }

    /**
     * Sin consumo en el rango, los tres cortes vienen vacíos y `configuracion` viene igual: el
     * admin puede saber qué eligió el dueño aunque todavía no haya gastado nada.
     *
     * @group consumo-ia
     * @test
     */
    public function sin_consumo_los_cortes_vienen_vacios_y_la_configuracion_igual()
    {
        $respuesta = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                          ->assertStatus(200)
                          ->json();

        $this->assertEquals([], $respuesta['dias']);
        $this->assertEquals([], $respuesta['personas']);
        $this->assertEquals([], $respuesta['personas_modelos']);
        $this->assertEquals('anthropic', $respuesta['configuracion']['proveedor']);
    }

    /**
     * La primera fila de $filas que coincide con todos los pares de $criterios, o null.
     *
     * @param  array  $filas
     * @param  array  $criterios
     * @return array|null
     */
    protected function buscar(array $filas, array $criterios)
    {
        foreach ($filas as $fila) {

            $coincide = true;

            foreach ($criterios as $clave => $valor) {

                if (! array_key_exists($clave, $fila) || $fila[$clave] !== $valor) {

                    $coincide = false;
                    break;
                }
            }

            if ($coincide) {

                return $fila;
            }
        }

        return null;
    }
}
