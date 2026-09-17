<?php

namespace Tests\Feature\ConsumoIa;

use App\Models\AiTokenUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión tokens-por-cliente — §1.4, puntos 4 a 7: el endpoint que el admin consume
 * (`GET api/admin-sync/consumo-ia`).
 *
 * 🔴 EL TEST DE LAS CLAVES NO ES DECORACIÓN. Las nueve claves de `dias[]` y las ocho de
 * `personas[]` son el contrato con `admin-api`, que ya está construido del otro lado esperando
 * exactamente esos nombres. Este proyecto ya se quemó justo acá (`manual_tasks` vs `tareas`):
 * una punta renombra una clave, la otra lee null, y no hay ningún error en ningún lado —
 * simplemente la pantalla muestra cero. Por eso se afirma sobre `array_keys()` enteras y no
 * sobre "que exista el campo".
 *
 * 🔴 Y `personas[]` VA ABIERTO POR DÍA, con su `fecha` en cada fila. El admin lo espeja con
 * clave (client_id, fecha, auth_user_id): un bloque agregado por todo el rango lo obligaría a
 * inventarle una fecha, y entonces la recolección nocturna de 3 días pisaría con su total lo
 * que una consulta de 30 días dejó escrito, todas las noches y sin ruido.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Endpoint_Test extends TestCase
{
    use DatabaseTransactions;

    /** La clave que el admin manda en el header. */
    const CLAVE = 'clave-del-admin-para-los-tokens';

    /** La ruta bajo prueba. */
    const RUTA = 'api/admin-sync/consumo-ia';

    /** @var User El dueño de la instancia. */
    protected $comercio;

    /** @var User Un empleado del mismo comercio: la "persona" del corte. */
    protected $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.admin_api.api_key' => self::CLAVE]);

        /*
         * Apagado a propósito, que es como está en producción: la validación de la clave la
         * hace el controlador, no el middleware, y el test tiene que probar eso.
         */
        config(['services.admin_api.require_api_key' => false]);

        $this->comercio = User::create([
            'name'         => 'Comercio de tokens',
            'company_name' => 'Ferretería de los tokens',
            'email'        => 'tokens-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->empleado = User::create([
            'name'     => 'Vendedora del mostrador',
            'email'    => 'tokens-empleado-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        /*
         * 🔴 Es la configuración real de una instancia, no un atajo: la base de testing tiene
         * varios dueños —igual que una base compartida de producción—, así que sin esto el
         * dueño no se puede resolver y todo daría 409.
         */
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
            'modelo'                      => 'claude-sonnet-4-5-20260101',
            'input_tokens'                => 0,
            'output_tokens'               => 0,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens'     => 0,
            'created_at'                  => $created_at,
            'updated_at'                  => $created_at,
        ], $datos));
    }

    /**
     * El escenario de los dos días, con dos acciones, dos modelos y dos personas.
     *
     * @return void
     */
    protected function sembrar()
    {
        /* 10/9: dos llamadas del chat, misma acción y mismo modelo → una sola fila sumada. */
        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 100, 'output_tokens' => 10, 'cache_read_input_tokens' => 5,
        ], '2026-09-10 10:00:00');

        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 200, 'output_tokens' => 20, 'cache_read_input_tokens' => 7,
        ], '2026-09-10 18:30:00');

        /* 10/9: la búsqueda semántica, de OpenAI y sin persona → fila aparte. */
        $this->consumo([
            'proceso'   => 'embeddings_busqueda',
            'proveedor' => 'openai',
            'modelo'    => 'text-embedding-3-small',
            'input_tokens' => 50,
        ], '2026-09-10 11:15:00');

        /* 11/9: el mismo chat, otro día → otra fila. */
        $this->consumo([
            'auth_user_id' => $this->empleado->id,
            'input_tokens' => 7, 'output_tokens' => 1,
        ], '2026-09-11 09:00:00');

        /* Fuera del rango que se va a pedir: no tiene que aparecer en ningún corte. */
        $this->consumo(['input_tokens' => 999999], '2026-08-01 09:00:00');
    }

    /**
     * Punto 4: agrupa por día, acción, proveedor y modelo, y respeta el rango.
     *
     * @group consumo-ia
     * @test
     */
    public function agrupa_por_dia_accion_y_modelo_y_respeta_el_rango()
    {
        $this->sembrar();

        $respuesta = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                          ->assertStatus(200)
                          ->json();

        $this->assertEquals((int) $this->comercio->id, $respuesta['user_id']);
        $this->assertEquals('2026-09-10', $respuesta['desde']);
        $this->assertEquals('2026-09-11', $respuesta['hasta']);

        $dias = $respuesta['dias'];

        $this->assertCount(3, $dias, 'Dos acciones el 10 y una el 11: tres filas, no cinco.');

        /* 🔴 El contrato, clave por clave y en el orden en que el admin las lee. */
        $this->assertEquals(
            [
                'fecha', 'proceso', 'proveedor', 'modelo', 'llamadas',
                'input_tokens', 'output_tokens',
                'cache_creation_input_tokens', 'cache_read_input_tokens',
            ],
            array_keys($dias[0])
        );

        $chat_del_10 = $this->buscar($dias, ['fecha' => '2026-09-10', 'proceso' => 'chat_mensaje']);

        $this->assertNotNull($chat_del_10, 'Falta la fila del chat del 10/9.');
        $this->assertEquals(2, $chat_del_10['llamadas'], 'Las dos llamadas del mismo día, acción y modelo se suman en una fila.');
        $this->assertEquals(300, $chat_del_10['input_tokens']);
        $this->assertEquals(30, $chat_del_10['output_tokens']);
        $this->assertEquals(12, $chat_del_10['cache_read_input_tokens']);
        $this->assertEquals('anthropic', $chat_del_10['proveedor']);

        $embeddings = $this->buscar($dias, ['fecha' => '2026-09-10', 'proceso' => 'embeddings_busqueda']);

        $this->assertNotNull($embeddings, 'La búsqueda semántica del mismo día va en su propia fila.');
        $this->assertEquals('openai', $embeddings['proveedor']);
        $this->assertEquals('text-embedding-3-small', $embeddings['modelo']);
        $this->assertEquals(50, $embeddings['input_tokens']);

        $chat_del_11 = $this->buscar($dias, ['fecha' => '2026-09-11', 'proceso' => 'chat_mensaje']);

        $this->assertNotNull($chat_del_11, 'Otro día es otra fila, aunque sea la misma acción y el mismo modelo.');
        $this->assertEquals(1, $chat_del_11['llamadas']);

        /* El rango: la fila de agosto no se coló en ningún lado. */
        $this->assertNull($this->buscar($dias, ['fecha' => '2026-08-01']));

        foreach ($dias as $fila) {
            $this->assertNotEquals(999999, $fila['input_tokens'], 'Se coló consumo de fuera del rango pedido.');
        }
    }

    /**
     * Punto 4 (la otra mitad): `personas[]` viene abierto POR DÍA, con su fecha en cada fila.
     *
     * @group consumo-ia
     * @test
     */
    public function el_corte_por_persona_viene_abierto_por_dia_con_su_fecha()
    {
        $this->sembrar();

        $personas = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                         ->assertStatus(200)
                         ->json('personas');

        $this->assertEquals(
            [
                'fecha', 'auth_user_id', 'nombre', 'llamadas',
                'input_tokens', 'output_tokens',
                'cache_creation_input_tokens', 'cache_read_input_tokens',
            ],
            array_keys($personas[0])
        );

        /*
         * 🔴 Tres filas, no dos: la empleada aparece el 10 Y el 11 por separado. Si esto
         * agregara por rango darían dos (una por persona) y el admin no podría espejarlo.
         */
        $this->assertCount(3, $personas);

        $empleada_el_10 = $this->buscar($personas, [
            'fecha'        => '2026-09-10',
            'auth_user_id' => (int) $this->empleado->id,
        ]);

        $this->assertNotNull($empleada_el_10);
        $this->assertEquals('Vendedora del mostrador', $empleada_el_10['nombre']);
        $this->assertEquals(2, $empleada_el_10['llamadas']);
        $this->assertEquals(300, $empleada_el_10['input_tokens']);

        $empleada_el_11 = $this->buscar($personas, [
            'fecha'        => '2026-09-11',
            'auth_user_id' => (int) $this->empleado->id,
        ]);

        $this->assertNotNull($empleada_el_11, 'El mismo empleado en otro día tiene que ser otra fila.');
        $this->assertEquals(7, $empleada_el_11['input_tokens']);

        /* Los procesos automáticos: auth_user_id null, que es una categoría y no un dato faltante. */
        $automaticos = $this->buscar($personas, ['fecha' => '2026-09-10', 'auth_user_id' => null]);

        $this->assertNotNull($automaticos, 'El consumo sin persona tiene que salir igual, con auth_user_id null.');
        $this->assertNull($automaticos['nombre']);
        $this->assertEquals(50, $automaticos['input_tokens']);
    }

    /**
     * Punto 5: no cruza comercios.
     *
     * Hay bases con 51 comercios adentro: filtrar por `user_id` no es una precaución teórica,
     * es lo único que evita que el admin le facture a un negocio el gasto de otro.
     *
     * @group consumo-ia
     * @test
     */
    public function no_cruza_el_consumo_de_otro_comercio_de_la_misma_base()
    {
        $this->sembrar();

        $vecino = User::create([
            'name'     => 'Comercio vecino',
            'email'    => 'tokens-vecino-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        AiTokenUsage::create([
            'user_id'      => $vecino->id,
            'auth_user_id' => null,
            'proceso'      => 'proceso_del_vecino',
            'proveedor'    => 'anthropic',
            'modelo'       => 'modelo-del-vecino',
            'input_tokens' => 777777,
            'created_at'   => '2026-09-10 12:00:00',
            'updated_at'   => '2026-09-10 12:00:00',
        ]);

        $respuesta = $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
                          ->assertStatus(200)
                          ->json();

        $this->assertNull(
            $this->buscar($respuesta['dias'], ['proceso' => 'proceso_del_vecino']),
            'El consumo de otro comercio de la misma base no puede aparecer acá.'
        );

        foreach ($respuesta['dias'] as $fila) {
            $this->assertNotEquals(777777, $fila['input_tokens']);
        }

        foreach ($respuesta['personas'] as $fila) {
            $this->assertNotEquals(777777, $fila['input_tokens']);
        }
    }

    /**
     * Punto 6, primera mitad: sin dueño resoluble, 409.
     *
     * 409 y no 404 a propósito: para el admin un 404 significa "este cliente está en una
     * versión vieja" y con eso deja de reintentar. Un cliente ya actualizado, en una base
     * compartida a la que le falta USER_ID en el .env de su frente, no está viejo — está mal
     * configurado, y eso tiene que poder distinguirse.
     *
     * @group consumo-ia
     * @test
     */
    public function sin_dueno_resoluble_devuelve_409()
    {
        /* Un segundo dueño, para que la base sea ambigua sí o sí (como una base compartida). */
        User::create([
            'name'     => 'Otro dueño',
            'email'    => 'tokens-otro-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        config(['app.USER_ID' => null]);

        $this->getJson(self::RUTA . '?desde=2026-09-10&hasta=2026-09-11', $this->headers())
             ->assertStatus(409);
    }

    /**
     * Punto 6, segunda mitad: un rango de más de 62 días es 422.
     *
     * @group consumo-ia
     * @test
     */
    public function un_rango_de_mas_de_62_dias_devuelve_422()
    {
        $this->getJson(self::RUTA . '?desde=2026-01-01&hasta=2026-12-31', $this->headers())
             ->assertStatus(422);

        /* El borde: 62 días justos sí pasan. */
        $this->getJson(self::RUTA . '?desde=2026-07-01&hasta=2026-08-31', $this->headers())
             ->assertStatus(200);

        /* Y un rango invertido también es culpa del que llama. */
        $this->getJson(self::RUTA . '?desde=2026-09-11&hasta=2026-09-10', $this->headers())
             ->assertStatus(422);

        /* Igual que una fecha que no es una fecha. */
        $this->getJson(self::RUTA . '?desde=ayer&hasta=2026-09-10', $this->headers())
             ->assertStatus(422);
    }

    /**
     * Punto 7: la clave se exige solo si el cliente la tiene cargada.
     *
     * Es estrictamente más seguro que el status quo (el middleware del grupo hoy no exige
     * nada) y no deja la recolección rota en la mayoría de los clientes, que todavía no
     * tienen ADMIN_API_INBOUND_KEY en su .env.
     *
     * @group consumo-ia
     * @test
     */
    public function la_clave_se_exige_solo_si_el_cliente_la_tiene_cargada()
    {
        $query = '?desde=2026-09-10&hasta=2026-09-11';

        /* Con clave cargada: header equivocado y header ausente, los dos 401. */
        $this->getJson(self::RUTA . $query, ['X-Admin-Api-Key' => 'otra-clave'])->assertStatus(401);
        $this->getJson(self::RUTA . $query)->assertStatus(401);

        /* Con la clave correcta, pasa. */
        $this->getJson(self::RUTA . $query, $this->headers())->assertStatus(200);

        /* Sin clave cargada de este lado, se comporta como el resto del grupo admin-sync. */
        config(['services.admin_api.api_key' => null]);

        $this->getJson(self::RUTA . $query)->assertStatus(200);
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
