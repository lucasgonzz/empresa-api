<?php

namespace Tests\Feature\Buyer;

use App\Models\Address;
use App\Models\Buyer;
use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión crear-usuario-tienda-sin-duplicados (8/10/2026): `POST api/buyer` desde el botón
 * "Crear usuario para la tienda" de la ficha de un cliente.
 *
 * Un doble clic en ese botón creaba DOS compradores para el mismo cliente, con el mismo correo y el
 * mismo `comercio_city_client_id` (medido en demo2: ids 20 y 21 para el cliente 29). La SPA le manda
 * al endpoint el cliente entero, así que el `id` del cuerpo es el del cliente. Lo que estos tests
 * cuidan:
 *
 *  - QUE NO NAZCA EL DUPLICADO: un cliente del sistema tiene un solo comprador por dueño. Se
 *    verifica contando filas de `buyers` en la base, no solo mirando la respuesta.
 *  - EL CONTRATO CON LA SPA: 201 cuando crea (como siempre) y 200 con `ya_existia: true` cuando ya
 *    existía. 200 y no 422 porque la SPA 4.3.8 de producción, ante un error, mostraría "Error al
 *    crear usuario" sobre un usuario que sí existe.
 *  - QUE LA GUARDA SEA POR DUEÑO: un empleado repite el POST de su dueño sin duplicar, y un
 *    comprador de OTRO comercio con el mismo `comercio_city_client_id` no bloquea el alta.
 *  - QUE EL ALTA DEL ABM SIGA IGUAL: sin `id` en el cuerpo no hay cliente de por medio y cada POST
 *    crea un comprador.
 *  - QUE LA RESPUESTA DEL EXISTENTE NO ARRASTRE LA HISTORIA DE MENSAJES NI EL HASH DE LA CLAVE
 *    (un `fullModel('Buyer')` tumbó el VPS el 9/9/2026).
 *
 * Cada test crea sus dueños (ver `ComercioDePrueba`). PHP 7.4.
 */
class CrearUsuarioParaLaTiendaTest extends EmpresaTestCase
{
    use ComercioDePrueba;

    /** @var \App\Models\User Dueño de la empresa, el que opera en los tests. */
    protected $dueno;

    /** @var \App\Models\User Otro comercio: nada suyo cuenta para la guarda del dueño. */
    protected $otro_dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('crear usuario tienda');
        $this->otro_dueno = $this->crear_dueno('otro comercio');

        $this->actuar_como($this->dueno);
    }

    // -------------------------------------------------------------------------------------------
    //  Ayudas
    // -------------------------------------------------------------------------------------------

    /**
     * Pega al endpoint con los campos que manda la SPA desde la ficha del cliente: el cliente
     * entero (su `id` es el que termina en `comercio_city_client_id`; su `num` forma la clave
     * inicial `1234` + num).
     *
     * @param  \App\Models\Client  $cliente
     * @param  array  $cuerpo  Lo que se quiera pisar o agregar.
     * @return \Illuminate\Testing\TestResponse
     */
    protected function crear_usuario_para($cliente, $cuerpo = [])
    {
        return $this->postJson('api/buyer', array_merge([
            'id'      => $cliente->id,
            'num'     => 7,
            'name'    => $cliente->name,
            'email'   => 'cliente-'.$cliente->id.'@test.local',
            'phone'   => '1122334455',
            'user_id' => $cliente->user_id,
        ], $cuerpo));
    }

    /**
     * Cuántos compradores tiene un dueño para un cliente, leído de la base.
     *
     * @param  \App\Models\User  $dueno
     * @param  \App\Models\Client  $cliente
     * @return int
     */
    protected function compradores_de($dueno, $cliente)
    {
        return Buyer::where('user_id', $dueno->id)
                    ->where('comercio_city_client_id', $cliente->id)
                    ->count();
    }

    // -------------------------------------------------------------------------------------------
    //  Lo que crea
    // -------------------------------------------------------------------------------------------

    /**
     * La primera vez crea el comprador, como siempre: 201, una fila, vinculada al cliente y al
     * dueño, con la clave inicial `1234` + el `num` que manda la SPA.
     *
     * @test
     * @return void
     */
    public function la_primera_vez_crea_el_comprador_del_cliente()
    {
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente nuevo']);

        $respuesta = $this->crear_usuario_para($cliente);

        $respuesta->assertStatus(201);

        $json = $respuesta->json();

        $this->assertArrayNotHasKey('ya_existia', $json, 'Cuando crea, la respuesta es la de siempre: sin marca de "ya existía".');
        $this->assertSame($cliente->id, $json['model']['comercio_city_client_id']);
        $this->assertSame($this->dueno->id, $json['model']['user_id']);
        $this->assertSame('12347', $json['model']['visible_password']);
        $this->assertSame('Cliente nuevo', $json['model']['name']);

        $this->assertSame(1, $this->compradores_de($this->dueno, $cliente), 'Tiene que haber exactamente un comprador para el cliente.');

        $fila = Buyer::find($json['model']['id']);
        $this->assertSame($cliente->id, (int) $fila->comercio_city_client_id);
        $this->assertSame($this->dueno->id, (int) $fila->user_id);
        $this->assertSame('12347', $fila->visible_password);
        $this->assertNotSame('12347', $fila->password, 'La clave se guarda hasheada.');
    }

    // -------------------------------------------------------------------------------------------
    //  El doble clic
    // -------------------------------------------------------------------------------------------

    /**
     * El defecto del 8/10/2026: el segundo POST para el mismo cliente NO crea otro comprador.
     * Responde 200 con el primero y `ya_existia: true`, y en la base sigue habiendo una sola fila.
     * La respuesta no arrastra la conversación ni el hash de la clave, aunque el comprador ya
     * tenga mensajes.
     *
     * @test
     * @return void
     */
    public function el_segundo_post_para_el_mismo_cliente_no_duplica_y_devuelve_el_primero()
    {
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente del doble clic']);

        $primera = $this->crear_usuario_para($cliente);
        $primera->assertStatus(201);
        $id_del_primero = $primera->json()['model']['id'];

        // El comprador ya tiene historia: la respuesta del segundo POST no la puede cargar.
        Address::create(['street' => 'Calle 123', 'buyer_id' => $id_del_primero, 'user_id' => $this->dueno->id]);
        Message::create([
            'text'       => 'Hola, ¿tienen stock?',
            'buyer_id'   => $id_del_primero,
            'from_buyer' => 1,
            'read'       => 0,
        ]);

        $segunda = $this->crear_usuario_para($cliente);

        // Lo primero que se mira es la base: es donde se ve el duplicado, con cualquier respuesta.
        $this->assertSame(1, $this->compradores_de($this->dueno, $cliente), 'El segundo POST creó un comprador duplicado.');
        $this->assertSame(1, Buyer::where('user_id', $this->dueno->id)->count(), 'No tiene que haber ningún comprador de más en el comercio.');

        $segunda->assertStatus(200);

        $json = $segunda->json();

        $this->assertTrue($json['ya_existia'], 'La respuesta tiene que avisar que el comprador ya existía.');
        $this->assertSame($id_del_primero, $json['model']['id'], 'Tiene que devolver el comprador que ya estaba.');
        $this->assertSame($cliente->id, $json['model']['comercio_city_client_id']);

        // La SPA hace `buyer.messages.length` y `buyer/add` reemplaza la fila entera: el comprador
        // sale con sus direcciones y su cliente, y `messages` como array vacío.
        $this->assertSame([], $json['model']['messages'], 'La respuesta no puede arrastrar la historia de mensajes.');
        $this->assertCount(1, $json['model']['addresses']);
        $this->assertSame($cliente->id, $json['model']['comercio_city_client']['id']);

        foreach (['password', 'remember_token', 'verification_code'] as $campo) {
            $this->assertArrayNotHasKey($campo, $json['model'], "El campo '".$campo."' del comprador no puede viajar en la respuesta.");
        }

        // `visible_password` sí viaja: es lo que ya devolvía el 201 y alimenta `buyer/add`.
        $this->assertSame('12347', $json['model']['visible_password']);

        // Y el mensaje sigue en la base: la respuesta lo omitió, no lo borró.
        $this->assertSame(1, Message::where('buyer_id', $id_del_primero)->count());
    }

    /**
     * Cualquier cantidad de POST seguidos deja una sola fila: el primero crea, el resto devuelve
     * ese mismo comprador.
     *
     * @test
     * @return void
     */
    public function muchos_post_seguidos_dejan_una_sola_fila()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        $this->crear_usuario_para($cliente)->assertStatus(201);

        for ($i = 0; $i < 4; $i++) {
            $repetida = $this->crear_usuario_para($cliente);

            $this->assertSame(1, $this->compradores_de($this->dueno, $cliente), 'El POST número '.($i + 2).' creó un comprador duplicado.');

            $repetida->assertStatus(200);
        }
    }

    /**
     * Un cliente distinto del mismo dueño sí recibe su propio comprador: la guarda es por cliente,
     * no por dueño a secas.
     *
     * @test
     * @return void
     */
    public function otro_cliente_del_mismo_dueno_recibe_su_propio_comprador()
    {
        $uno = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente uno']);
        $otro = $this->crear_cliente_de($this->dueno, ['name' => 'Cliente otro']);

        $this->crear_usuario_para($uno)->assertStatus(201);
        $this->crear_usuario_para($otro)->assertStatus(201);

        $this->assertSame(1, $this->compradores_de($this->dueno, $uno));
        $this->assertSame(1, $this->compradores_de($this->dueno, $otro));
        $this->assertSame(2, Buyer::where('user_id', $this->dueno->id)->count());
    }

    // -------------------------------------------------------------------------------------------
    //  Por dueño
    // -------------------------------------------------------------------------------------------

    /**
     * Un empleado que repite el POST de su dueño tampoco duplica: la guarda busca por el dueño real
     * (`userId()`), no por el usuario que opera.
     *
     * @test
     * @return void
     */
    public function un_empleado_que_repite_el_post_de_su_dueno_no_duplica()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        $primera = $this->crear_usuario_para($cliente);
        $primera->assertStatus(201);

        $empleado = $this->crear_empleado($this->dueno);

        $this->actuar_como($empleado);

        // Si el cambio de usuario fallara, el test seguiría operando como el dueño y pasaría por
        // la razón equivocada: se afirma que de verdad opera el empleado de este dueño.
        $this->assertSame($empleado->id, (int) Auth::id(), 'Tendría que estar autenticado el empleado, no el dueño.');
        $this->assertSame($this->dueno->id, (int) Auth::user()->owner_id, 'El usuario autenticado tendría que ser un empleado del dueño.');

        $segunda = $this->crear_usuario_para($cliente);

        $segunda->assertStatus(200);
        $this->assertTrue($segunda->json()['ya_existia']);
        $this->assertSame($primera->json()['model']['id'], $segunda->json()['model']['id']);
        $this->assertSame(1, $this->compradores_de($this->dueno, $cliente));
    }

    /**
     * Si ya hay un comprador con ese `comercio_city_client_id` pero de OTRO comercio, no cuenta: la
     * guarda es por `user_id`, así que el alta se hace igual y cada comercio queda con el suyo.
     *
     * @test
     * @return void
     */
    public function un_comprador_de_otro_comercio_con_el_mismo_cliente_id_no_bloquea_el_alta()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        $ajeno = $this->crear_comprador_de($this->otro_dueno, [
            'name'                    => 'Comprador de otro comercio',
            'comercio_city_client_id' => $cliente->id,
        ]);

        $respuesta = $this->crear_usuario_para($cliente);

        $respuesta->assertStatus(201);
        $this->assertNotSame($ajeno->id, $respuesta->json()['model']['id']);
        $this->assertSame($this->dueno->id, $respuesta->json()['model']['user_id']);

        $this->assertSame(1, $this->compradores_de($this->dueno, $cliente));
        $this->assertSame(1, $this->compradores_de($this->otro_dueno, $cliente), 'El comprador del otro comercio no se toca.');
    }

    // -------------------------------------------------------------------------------------------
    //  Sin cliente de por medio
    // -------------------------------------------------------------------------------------------

    /**
     * El alta desde el ABM de Tienda online → Clientes no trae `id` (o trae `null`): no hay cliente
     * del sistema que duplicar, así que cada POST crea un comprador, como siempre.
     *
     * @test
     * @return void
     */
    public function sin_id_cada_post_crea_un_comprador_como_siempre()
    {
        $primera = $this->postJson('api/buyer', ['name' => 'Comprador del ABM', 'email' => 'abm@test.local']);
        $segunda = $this->postJson('api/buyer', ['id' => null, 'name' => 'Comprador del ABM', 'email' => 'abm@test.local']);

        $primera->assertStatus(201);
        $segunda->assertStatus(201);

        $this->assertNotSame($primera->json()['model']['id'], $segunda->json()['model']['id']);
        $this->assertArrayNotHasKey('ya_existia', $segunda->json());

        $this->assertSame(2, Buyer::where('user_id', $this->dueno->id)->count());
        $this->assertSame(2, Buyer::where('user_id', $this->dueno->id)->whereNull('comercio_city_client_id')->count());
    }

    // -------------------------------------------------------------------------------------------
    //  Duplicados que ya existen
    // -------------------------------------------------------------------------------------------

    /**
     * Si el cliente ya tiene dos compradores (los duplicados que dejó el defecto antes del arreglo),
     * el POST devuelve siempre el de menor id y no suma un tercero. Los viejos no se tocan.
     *
     * @test
     * @return void
     */
    public function con_duplicados_viejos_devuelve_el_de_menor_id_y_no_crea_un_tercero()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        $primero = $this->crear_comprador_de($this->dueno, ['name' => 'Duplicado A', 'comercio_city_client_id' => $cliente->id]);
        $segundo = $this->crear_comprador_de($this->dueno, ['name' => 'Duplicado B', 'comercio_city_client_id' => $cliente->id]);

        $this->assertLessThan($segundo->id, $primero->id);

        // Ojo: este test NO discrimina el `orderBy('id')` del controller. Sin él, MySQL devuelve
        // igual el de menor id (recorre por clave primaria), así que pasaría también sin la
        // cláusula. Lo que sí garantiza es lo importante: con duplicados viejos no se crea un
        // tercero y la respuesta es siempre el mismo comprador, el primero. El `orderBy` queda
        // como seguro explícito para no depender del orden con el que MySQL elija recorrer.
        $respuesta = $this->crear_usuario_para($cliente);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json()['ya_existia']);
        $this->assertSame($primero->id, $respuesta->json()['model']['id'], 'Tiene que devolver el de menor id.');
        $this->assertSame('Duplicado A', $respuesta->json()['model']['name']);

        $this->assertSame(2, $this->compradores_de($this->dueno, $cliente), 'No se crea un tercero ni se borra ninguno de los viejos.');
    }
}
