<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Buyer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Misión vincular-comprador-desde-pedidos (24/9/2026): `POST api/buyer/{id}/vincular-cliente`.
 *
 * El modal de la tabla de Pedidos vincula a un comprador de la tienda con el cliente del sistema
 * que la persona eligió. El vínculo es `buyers.comercio_city_client_id`, y de él depende la cuenta
 * corriente de los pedidos que se confirmen después (ver `VinculoEnPedidosTest`). Lo que estos
 * tests cuidan:
 *
 *  - EL CONTRATO de §3.2 del plan: 200 (también idempotente), 404, 409, 422, con sus mensajes
 *    exactos y en el orden de los chequeos.
 *  - QUE NO SE PISE UN VÍNCULO A CIEGAS: 409 si ya apunta a otro cliente vivo, salvo `reemplazar`.
 *    Pero un vínculo colgante (el cliente fue borrado) NO cuenta: el sistema ya lo trata como "sin
 *    cliente" y pedir confirmación para pisar algo que no existe sería absurdo.
 *  - EL AISLAMIENTO: ni un comprador ajeno (404) ni un cliente ajeno o borrado (422). Un 422 o un
 *    404 no escriben nada.
 *  - QUE NO ARRASTRE LA HISTORIA DE MENSAJES: la respuesta trae el comprador con `comercio_city_client`
 *    y `addresses`, sin `messages` (un `fullModel('Buyer')` tumbó el VPS el 9/9/2026).
 *
 * Cada test crea sus dueños (ver `ComercioDePrueba`). PHP 7.4.
 */
class VincularClienteTest extends EmpresaTestCase
{
    use ComercioDePrueba;

    /** @var \App\Models\User Dueño de la empresa, el que opera en los tests. */
    protected $dueno;

    /** @var \App\Models\User Otro comercio: nada suyo se puede tocar. */
    protected $otro_dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('vincular');
        $this->otro_dueno = $this->crear_dueno('otro comercio');

        $this->actuar_como($this->dueno);
    }

    // -------------------------------------------------------------------------------------------
    //  Ayudas
    // -------------------------------------------------------------------------------------------

    /**
     * Pega al endpoint.
     *
     * @param  \App\Models\Buyer|int|string  $comprador  El comprador o directamente el id de la URL.
     * @param  array  $cuerpo
     * @return \Illuminate\Testing\TestResponse
     */
    protected function vincular($comprador, $cuerpo)
    {
        $id = is_object($comprador) ? $comprador->id : $comprador;

        return $this->postJson('api/buyer/'.$id.'/vincular-cliente', $cuerpo);
    }

    /**
     * A qué cliente apunta HOY el comprador, leído de la base (no del objeto en memoria).
     *
     * @param  \App\Models\Buyer  $comprador
     * @return int|null
     */
    protected function vinculo_de($comprador)
    {
        $valor = Buyer::find($comprador->id)->comercio_city_client_id;

        return is_null($valor) ? null : (int) $valor;
    }

    // -------------------------------------------------------------------------------------------
    //  El camino feliz
    // -------------------------------------------------------------------------------------------

    /**
     * Vincula, y la respuesta trae el comprador con su cliente y sus direcciones pero SIN la
     * historia de mensajes.
     *
     * @test
     * @return void
     */
    public function vincula_y_devuelve_el_comprador_con_su_cliente_y_sus_direcciones()
    {
        $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Lucas', 'email' => 'x@y.com']);
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Lucas González']);

        Address::create(['street' => 'Calle 123', 'buyer_id' => $comprador->id, 'user_id' => $this->dueno->id]);

        $respuesta = $this->vincular($comprador, ['client_id' => $cliente->id]);

        $respuesta->assertStatus(200);

        $json = $respuesta->json();

        $this->assertArrayHasKey('model', $json);
        $this->assertSame($comprador->id, $json['model']['id']);
        $this->assertSame($cliente->id, $json['model']['comercio_city_client_id']);
        $this->assertSame($cliente->id, $json['model']['comercio_city_client']['id']);
        $this->assertSame('Lucas González', $json['model']['comercio_city_client']['name']);

        $this->assertCount(1, $json['model']['addresses']);
        $this->assertSame('Calle 123', $json['model']['addresses'][0]['street']);

        $this->assertArrayNotHasKey('messages', $json['model'], 'La respuesta no puede arrastrar la historia de mensajes del comprador.');

        $this->assertSame($cliente->id, $this->vinculo_de($comprador), 'El vínculo no quedó escrito en la base.');
    }

    /**
     * Vincular dos veces al mismo cliente da 200 las dos, y la segunda no escribe nada.
     *
     * @test
     * @return void
     */
    public function vincular_al_mismo_cliente_es_idempotente_y_no_escribe()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $cliente = $this->crear_cliente_de($this->dueno);

        $this->vincular($comprador, ['client_id' => $cliente->id])->assertStatus(200);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $segunda = $this->vincular($comprador, ['client_id' => $cliente->id]);

        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $segunda->assertStatus(200);
        $this->assertSame($cliente->id, $segunda->json()['model']['comercio_city_client']['id']);
        $this->assertSame($cliente->id, $this->vinculo_de($comprador));

        foreach ($consultas as $consulta) {
            $this->assertSame(
                0,
                preg_match('/^update `buyers`/i', $consulta['query']),
                'Vincular al mismo cliente no tiene que escribir: '.$consulta['query']
            );
        }

        // Y con `reemplazar` da igual: sigue siendo el mismo cliente.
        $this->vincular($comprador, ['client_id' => $cliente->id, 'reemplazar' => true])->assertStatus(200);
        $this->assertSame($cliente->id, $this->vinculo_de($comprador));
    }

    /**
     * Un cliente puede quedar con más de un comprador vinculado (no hay unique en la base ni se
     * agrega uno). `tiene_usuario_en_tienda` avisa, pero no bloquea.
     *
     * @test
     * @return void
     */
    public function un_cliente_puede_quedar_con_mas_de_un_comprador()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        $uno = $this->crear_comprador_de($this->dueno, ['name' => 'Comprador uno']);
        $dos = $this->crear_comprador_de($this->dueno, ['name' => 'Comprador dos']);

        $this->vincular($uno, ['client_id' => $cliente->id])->assertStatus(200);
        $this->vincular($dos, ['client_id' => $cliente->id])->assertStatus(200);

        $this->assertSame($cliente->id, $this->vinculo_de($uno));
        $this->assertSame($cliente->id, $this->vinculo_de($dos));
    }

    /**
     * Un empleado vincula igual que su dueño: todo se scopea por el dueño real (`userId()`).
     *
     * @test
     * @return void
     */
    public function un_empleado_puede_vincular()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $cliente = $this->crear_cliente_de($this->dueno);

        $this->actuar_como($this->crear_empleado($this->dueno));

        $this->vincular($comprador, ['client_id' => $cliente->id])->assertStatus(200);

        $this->assertSame($cliente->id, $this->vinculo_de($comprador));
    }

    // -------------------------------------------------------------------------------------------
    //  409 y `reemplazar`
    // -------------------------------------------------------------------------------------------

    /**
     * Un comprador ya vinculado a OTRO cliente vivo no se pisa sin `reemplazar`: 409 con el nombre
     * del cliente actual, y el vínculo queda como estaba.
     *
     * @test
     * @return void
     */
    public function un_comprador_vinculado_a_otro_cliente_da_409_sin_reemplazar()
    {
        $actual = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente actual']);
        $nuevo = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente nuevo']);

        $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $actual->id]);

        $this->vincular($comprador, ['client_id' => $nuevo->id])
             ->assertStatus(409)
             ->assertExactJson([
                 'message'        => 'Este comprador ya está vinculado a «Cliente actual».',
                 'cliente_actual' => ['id' => $actual->id, 'name' => 'Cliente actual'],
             ]);

        $this->assertSame($actual->id, $this->vinculo_de($comprador), 'El 409 no puede escribir.');
    }

    /**
     * Con `reemplazar` verdadero, en cualquiera de las formas en que puede llegar (booleano, "true",
     * 1, "1"), se pisa el vínculo. Con falso, "false", 0, "0" o null, sigue siendo 409.
     *
     * @test
     * @return void
     */
    public function reemplazar_pisa_el_vinculo_solo_si_es_verdadero()
    {
        $actual = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente actual']);
        $nuevo = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente nuevo']);

        foreach ([true, 'true', 1, '1'] as $valor) {

            $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $actual->id]);

            $respuesta = $this->vincular($comprador, ['client_id' => $nuevo->id, 'reemplazar' => $valor]);

            $this->assertSame(200, $respuesta->getStatusCode(), 'reemplazar = '.var_export($valor, true).' tiene que pisar el vínculo.');
            $this->assertSame($nuevo->id, $respuesta->json()['model']['comercio_city_client']['id']);
            $this->assertSame($nuevo->id, $this->vinculo_de($comprador));
        }

        foreach ([false, 'false', 0, '0', null] as $valor) {

            $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $actual->id]);

            $respuesta = $this->vincular($comprador, ['client_id' => $nuevo->id, 'reemplazar' => $valor]);

            $this->assertSame(409, $respuesta->getStatusCode(), 'reemplazar = '.var_export($valor, true).' no tiene que pisar el vínculo.');
            $this->assertSame($actual->id, $this->vinculo_de($comprador));
        }
    }

    /**
     * Un vínculo colgante —el cliente al que apuntaba fue borrado, o directamente no existe— no es
     * un vínculo: se pisa sin `reemplazar`. El sistema ya lo trata como "sin cliente" (la venta del
     * pedido nace con `client_id` null) y la tabla de Pedidos lo muestra como "Sin vincular".
     *
     * @test
     * @return void
     */
    public function un_vinculo_colgante_se_pisa_sin_reemplazar()
    {
        $borrado = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente borrado']);
        $nuevo = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente nuevo']);

        $con_cliente_borrado = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $borrado->id]);
        $borrado->delete();

        $this->vincular($con_cliente_borrado, ['client_id' => $nuevo->id])->assertStatus(200);
        $this->assertSame($nuevo->id, $this->vinculo_de($con_cliente_borrado));

        // Apuntando a un id que nunca existió (la tabla no tiene foreign key).
        $con_id_inexistente = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => 987654321]);

        $this->vincular($con_id_inexistente, ['client_id' => $nuevo->id])->assertStatus(200);
        $this->assertSame($nuevo->id, $this->vinculo_de($con_id_inexistente));
    }

    /**
     * Un comprador cuyo vínculo apunta a un cliente VIVO de OTRO comercio (un dato incoherente que
     * no debería existir) tampoco cuenta como vínculo: no se pide `reemplazar` y, sobre todo, el 409
     * no le mostraría a este comercio el nombre de un cliente ajeno.
     *
     * @test
     * @return void
     */
    public function un_vinculo_a_un_cliente_de_otro_comercio_no_cuenta_y_no_se_revela()
    {
        $ajeno = $this->crear_cliente_de($this->otro_dueno, ['name' => 'Cliente ajeno secreto']);
        $propio = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente propio']);

        $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $ajeno->id]);

        $respuesta = $this->vincular($comprador, ['client_id' => $propio->id]);

        $respuesta->assertStatus(200);
        $this->assertStringNotContainsString('secreto', $respuesta->getContent());
        $this->assertSame($propio->id, $this->vinculo_de($comprador));
    }

    // -------------------------------------------------------------------------------------------
    //  422
    // -------------------------------------------------------------------------------------------

    /**
     * Sin `client_id` (ausente, null o vacío): 422 con el mensaje del contrato, y no se escribe nada.
     *
     * @test
     * @return void
     */
    public function sin_client_id_da_422()
    {
        $comprador = $this->crear_comprador_de($this->dueno);

        foreach ([[], ['client_id' => null], ['client_id' => ''], ['reemplazar' => true]] as $cuerpo) {

            $this->vincular($comprador, $cuerpo)
                 ->assertStatus(422)
                 ->assertExactJson(['message' => 'Elegí un cliente para vincular.']);
        }

        $this->assertNull($this->vinculo_de($comprador));
    }

    /**
     * Un cliente que no existe, que es de otro comercio, que está borrado o que directamente no es un
     * id: 422 con el mismo mensaje para todos (no se revela que existe en otro comercio), sin
     * escribir nada.
     *
     * @test
     * @return void
     */
    public function un_cliente_inexistente_ajeno_o_borrado_da_422()
    {
        $comprador = $this->crear_comprador_de($this->dueno);

        $ajeno = $this->crear_cliente_de($this->otro_dueno, ['name' => 'Cliente ajeno']);
        $borrado = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente borrado']);
        $propio = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente propio']);

        $borrado->delete();

        $casos = [
            'inexistente'        => 999999999,
            'de otro comercio'   => $ajeno->id,
            'borrado'            => $borrado->id,
            'texto'              => 'abc',
            'entero con basura'  => $propio->id.'abc',
            'cero'               => 0,
            'negativo'           => -1,
            'decimal'            => 12.5,
            'lista'              => [$propio->id],
        ];

        foreach ($casos as $descripcion => $client_id) {

            $respuesta = $this->vincular($comprador, ['client_id' => $client_id]);

            $this->assertSame(422, $respuesta->getStatusCode(), 'client_id '.$descripcion.': tiene que ser 422.');
            $respuesta->assertExactJson(['message' => 'El cliente elegido no existe.']);
        }

        $this->assertNull($this->vinculo_de($comprador), 'Un 422 no puede escribir el vínculo.');
    }

    /**
     * `client_id` puede llegar como número o como texto de dígitos (un formulario, un cliente HTTP
     * que serializa distinto): los dos vinculan.
     *
     * @test
     * @return void
     */
    public function client_id_como_texto_de_digitos_tambien_vincula()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $cliente = $this->crear_cliente_de($this->dueno);

        $this->vincular($comprador, ['client_id' => (string) $cliente->id])->assertStatus(200);

        $this->assertSame($cliente->id, $this->vinculo_de($comprador));
    }

    // -------------------------------------------------------------------------------------------
    //  404 y sesión
    // -------------------------------------------------------------------------------------------

    /**
     * Un comprador de otro comercio, uno que no existe y un id que no es un número: 404 con el
     * mismo mensaje, y el comprador ajeno queda intacto. El 404 va ANTES que los 422: con un
     * comprador ajeno no se revela nada por los otros errores (ni con un `client_id` que falta ni
     * con uno inexistente).
     *
     * @test
     * @return void
     */
    public function un_comprador_ajeno_o_inexistente_da_404_antes_que_cualquier_422()
    {
        $ajeno = $this->crear_comprador_de($this->otro_dueno, ['name' => 'Comprador ajeno']);
        $propio = $this->crear_comprador_de($this->dueno, ['name' => 'Comprador propio']);
        $cliente = $this->crear_cliente_de($this->dueno);

        $compradores = [
            'ajeno'             => $ajeno->id,
            'inexistente'       => 999999999,
            'no numérico'       => 'abc',
            'entero con basura' => $propio->id.'abc',
        ];

        $cuerpos = [
            'con un cliente propio válido' => ['client_id' => $cliente->id],
            'sin client_id'                => [],
            'con un client_id inexistente' => ['client_id' => 999999999],
        ];

        foreach ($compradores as $descripcion_del_comprador => $id) {

            foreach ($cuerpos as $descripcion_del_cuerpo => $cuerpo) {

                $respuesta = $this->vincular($id, $cuerpo);

                $this->assertSame(
                    404,
                    $respuesta->getStatusCode(),
                    'Comprador '.$descripcion_del_comprador.' '.$descripcion_del_cuerpo.': tiene que ser 404.'
                );

                $respuesta->assertExactJson(['message' => 'Comprador no encontrado.']);
            }
        }

        $this->assertNull($this->vinculo_de($ajeno), 'El comprador de otro comercio no se puede tocar.');
        $this->assertNull($this->vinculo_de($propio));
    }

    /**
     * Las dos rutas nuevas exigen sesión: van dentro del grupo `auth:sanctum`, no sueltas al lado.
     *
     * @test
     * @return void
     */
    public function las_dos_rutas_exigen_sesion()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $cliente = $this->crear_cliente_de($this->dueno);

        // `forgetGuards()` tira el guard que `EmpresaTestCase::setUp()` ya dejó con el usuario del fixture.
        Auth::forgetGuards();

        $this->getJson('api/buyer/'.$comprador->id.'/clientes-para-vincular')->assertStatus(401);
        $this->postJson('api/buyer/'.$comprador->id.'/vincular-cliente', ['client_id' => $cliente->id])->assertStatus(401);

        $this->assertNull($this->vinculo_de($comprador), 'Sin sesión no se puede vincular.');
    }
}
