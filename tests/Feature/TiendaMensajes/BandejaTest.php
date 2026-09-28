<?php

namespace Tests\Feature\TiendaMensajes;

use Carbon\Carbon;
use Tests\EmpresaTestCase;

/**
 * `GET tienda-chats` — la bandeja del submódulo "Mensajes" de Tienda Online (misión
 * mensajes-tienda-online, 28/9/2026, contrato C3).
 *
 * Lo que protege:
 * - Solo compradores DEL COMERCIO y solo los que tienen mensajes (en las bases compartidas viejas
 *   conviven decenas de comercios).
 * - La forma `Chat` exacta, con contadores enteros y booleanos de verdad: en JS un `"0"` es
 *   verdadero, y una clave mal escrita no explota, deja la bandeja vacía.
 * - Orden por el id del último mensaje, búsqueda, `solo_no_leidos`, páginas de 30.
 * - 🔴 Cantidad de consultas constante: con 2 y con 20 compradores cuesta lo mismo. Es la clase de
 *   error que tumbó el VPS el 9/9/2026 (un `withAll()` en el listado de compradores).
 *
 * PHP 7.4.
 */
class BandejaTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    const RUTA = 'api/tienda-chats';

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('bandeja');

        $this->actuar_como($this->dueno);
    }

    /**
     * @test
     */
    public function trae_solo_los_compradores_del_comercio_que_tienen_mensajes_con_la_forma_del_contrato()
    {
        $cliente = $this->crear_cliente_de($this->dueno, ['name' => 'Ana del ERP']);

        $ana = $this->crear_comprador_de($this->dueno, [
            'name'                    => 'Ana',
            'surname'                 => 'Pérez',
            'email'                   => 'ana-'.uniqid().'@test.local',
            'phone'                   => '1155550000',
            'comercio_city_client_id' => $cliente->id,
        ]);
        $beto = $this->crear_comprador_de($this->dueno, ['name' => 'Beto']);
        $sin_mensajes = $this->crear_comprador_de($this->dueno, ['name' => 'Sin mensajes']);

        $otro_dueno = $this->crear_dueno('bandeja otro');
        $ajeno = $this->crear_comprador_de($otro_dueno, ['name' => 'Ajeno']);
        $this->mensaje_del_comprador($ajeno);

        // Ana: 2 sin leer, 1 leído, 1 del comercio (4 en total). Beto: 1 leído.
        $this->mensaje_del_comprador($ana, ['text' => 'uno']);
        $this->mensaje_del_comprador($ana, ['text' => 'dos', 'read' => 1]);
        $this->mensaje_del_comprador($ana, ['text' => 'tres']);
        $this->mensaje_del_comprador($beto, ['text' => 'de beto', 'read' => 1]);
        $ultimo_de_ana = $this->mensaje_del_comercio($ana, ['text' => str_repeat('x', 250)]);

        $json = $this->getJson(self::RUTA)->assertStatus(200)->json();

        $this->assertSame(['data', 'current_page', 'last_page', 'per_page', 'total'], array_keys($json));
        $this->assertSame(1, $json['current_page']);
        $this->assertSame(1, $json['last_page']);
        $this->assertSame(30, $json['per_page']);
        $this->assertSame(2, $json['total']);

        $ids = array_map(function ($chat) {
            return $chat['buyer_id'];
        }, $json['data']);

        // Solo los dos con mensajes, del más reciente al más viejo (Ana tiene el último mensaje).
        $this->assertSame([$ana->id, $beto->id], $ids, 'La bandeja trajo compradores de más, de menos o en otro orden.');
        $this->assertNotContains($sin_mensajes->id, $ids);
        $this->assertNotContains($ajeno->id, $ids);

        $chat = $json['data'][0];

        $this->assertSame(
            ['buyer_id', 'buyer', 'unread_count', 'messages_count', 'last_message_at', 'last_message'],
            array_keys($chat)
        );
        $this->assertSame(2, $chat['unread_count']);
        $this->assertSame(4, $chat['messages_count']);

        $this->assertSame([
            'id'                      => $ana->id,
            'name'                    => 'Ana',
            'surname'                 => 'Pérez',
            'email'                   => $ana->email,
            'phone'                   => '1155550000',
            'comercio_city_client_id' => $cliente->id,
            'comercio_city_client'    => ['id' => $cliente->id, 'name' => 'Ana del ERP'],
        ], $chat['buyer']);

        $this->assertSame(['id', 'text', 'from_buyer', 'read', 'type', 'created_at'], array_keys($chat['last_message']));
        $this->assertSame($ultimo_de_ana->id, $chat['last_message']['id']);
        $this->assertSame(str_repeat('x', 200), $chat['last_message']['text'], 'El texto del último mensaje va recortado a 200.');
        $this->assertSame(false, $chat['last_message']['from_buyer']);
        $this->assertSame(false, $chat['last_message']['read']);
        $this->assertNull($chat['last_message']['type']);
        $this->assertSame($chat['last_message']['created_at'], $chat['last_message_at']);
        $this->assertSame($ultimo_de_ana->fresh()->created_at->toJSON(), $chat['last_message_at']);

        // Beto: sin cliente vinculado, nada sin leer.
        $this->assertSame(0, $json['data'][1]['unread_count']);
        $this->assertSame(1, $json['data'][1]['messages_count']);
        $this->assertNull($json['data'][1]['buyer']['comercio_city_client']);
        $this->assertSame(true, $json['data'][1]['last_message']['from_buyer']);
        $this->assertSame(true, $json['data'][1]['last_message']['read']);
    }

    /**
     * @test
     */
    public function ordena_por_el_ultimo_mensaje_y_no_por_la_fecha_del_comprador()
    {
        $viejo = $this->crear_comprador_de($this->dueno, ['name' => 'Viejo']);
        $nuevo = $this->crear_comprador_de($this->dueno, ['name' => 'Nuevo']);

        $this->mensaje_del_comprador($nuevo);
        // El comprador más viejo escribió último: tiene que ir arriba.
        $this->mensaje_del_comprador($viejo);

        $ids = array_map(function ($chat) {
            return $chat['buyer_id'];
        }, $this->getJson(self::RUTA)->assertStatus(200)->json('data'));

        $this->assertSame([$viejo->id, $nuevo->id], $ids);
    }

    /**
     * @test
     */
    public function busca_por_nombre_apellido_email_y_telefono()
    {
        $ana = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Anabela',
            'surname' => 'Quiroga',
            'email'   => 'ana.q-'.uniqid().'@correo.test',
            'phone'   => '3415551234',
        ]);
        $beto = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Roberto',
            'surname' => 'Sosa',
            'email'   => 'beto-'.uniqid().'@otro.test',
            'phone'   => '1149998877',
        ]);
        $this->mensaje_del_comprador($ana);
        $this->mensaje_del_comprador($beto);

        $casos = [
            'anabela'          => [$ana->id],
            'QUIROGA'          => [$ana->id],
            'Anabela Quiroga'  => [$ana->id],
            '@otro.test'       => [$beto->id],
            '9998'             => [$beto->id],
            'nadie-se-llama-asi' => [],
        ];

        foreach ($casos as $buscar => $esperados) {

            $json = $this->getJson(self::RUTA.'?buscar='.urlencode($buscar))->assertStatus(200)->json();

            $ids = array_map(function ($chat) {
                return $chat['buyer_id'];
            }, $json['data']);

            $this->assertSame($esperados, $ids, 'Buscar "'.$buscar.'" no trajo lo esperado.');
            $this->assertSame(count($esperados), $json['total']);
        }
    }

    /**
     * @test
     */
    public function solo_no_leidos_deja_los_que_tienen_algun_mensaje_del_comprador_sin_leer()
    {
        $con_pendientes = $this->crear_comprador_de($this->dueno, ['name' => 'Con pendientes']);
        $todo_leido = $this->crear_comprador_de($this->dueno, ['name' => 'Todo leído']);
        $solo_del_comercio = $this->crear_comprador_de($this->dueno, ['name' => 'Solo del comercio']);

        $this->mensaje_del_comprador($con_pendientes);
        $this->mensaje_del_comprador($todo_leido, ['read' => 1]);
        // Un mensaje del comercio sin leer por el comprador NO cuenta como pendiente del comercio.
        $this->mensaje_del_comercio($solo_del_comercio, ['read' => 0]);

        $ids = array_map(function ($chat) {
            return $chat['buyer_id'];
        }, $this->getJson(self::RUTA.'?solo_no_leidos=1')->assertStatus(200)->json('data'));

        $this->assertSame([$con_pendientes->id], $ids);

        $this->assertCount(3, $this->getJson(self::RUTA.'?solo_no_leidos=0')->assertStatus(200)->json('data'));
    }

    /**
     * @test
     */
    public function pagina_de_a_30()
    {
        $compradores = [];

        for ($i = 1; $i <= 31; $i++) {
            $comprador = $this->crear_comprador_de($this->dueno, ['name' => 'Comprador '.$i]);
            $this->mensaje_del_comprador($comprador);
            $compradores[] = $comprador;
        }

        $pagina_1 = $this->getJson(self::RUTA.'?page=1')->assertStatus(200)->json();

        $this->assertCount(30, $pagina_1['data']);
        $this->assertSame(1, $pagina_1['current_page']);
        $this->assertSame(2, $pagina_1['last_page']);
        $this->assertSame(30, $pagina_1['per_page']);
        $this->assertSame(31, $pagina_1['total']);

        $pagina_2 = $this->getJson(self::RUTA.'?page=2')->assertStatus(200)->json();

        $this->assertCount(1, $pagina_2['data']);
        $this->assertSame(2, $pagina_2['current_page']);

        // El que queda para la página 2 es el que escribió primero.
        $this->assertSame($compradores[0]->id, $pagina_2['data'][0]['buyer_id']);
    }

    /**
     * 🔴 La cantidad de consultas no crece con los compradores (ni con sus mensajes).
     *
     * @test
     */
    public function la_cantidad_de_consultas_no_crece_con_los_compradores()
    {
        $cliente = $this->crear_cliente_de($this->dueno);

        for ($i = 1; $i <= 2; $i++) {
            $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $cliente->id]);
            $this->mensaje_del_comprador($comprador);
        }

        $self = $this;

        $con_2 = count($this->consultas_de(function () use ($self) {
            $self->getJson(self::RUTA)->assertStatus(200)->assertJsonCount(2, 'data');
        }));

        for ($i = 1; $i <= 18; $i++) {
            $comprador = $this->crear_comprador_de($this->dueno, ['comercio_city_client_id' => $cliente->id]);
            // Cada uno con una historia distinta, para que tampoco dependa de los mensajes.
            for ($j = 0; $j <= $i; $j++) {
                $this->mensaje_del_comprador($comprador, ['read' => $j % 2]);
            }
        }

        $consultas_con_20 = $this->consultas_de(function () use ($self) {
            $self->getJson(self::RUTA)->assertStatus(200)->assertJsonCount(20, 'data');
        });

        $this->assertSame(
            $con_2,
            count($consultas_con_20),
            'La bandeja costó '.$con_2.' consultas con 2 compradores y '.count($consultas_con_20).' con 20: hay una consulta por comprador.'
        );

        foreach ($consultas_con_20 as $consulta) {
            $this->assertSame(
                0,
                preg_match('/^select \* from `messages`/i', $consulta['query']),
                'La bandeja cargó la historia de mensajes: '.$consulta['query']
            );
        }
    }

    /**
     * @test
     */
    public function el_empleado_ve_la_misma_bandeja_que_el_dueno()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $this->mensaje_del_comprador($comprador);

        $this->actuar_como($this->crear_empleado($this->dueno));

        $ids = array_map(function ($chat) {
            return $chat['buyer_id'];
        }, $this->getJson(self::RUTA)->assertStatus(200)->json('data'));

        $this->assertSame([$comprador->id], $ids);
    }

    /**
     * @test
     */
    public function un_comercio_sin_conversaciones_da_la_bandeja_vacia()
    {
        $this->crear_comprador_de($this->dueno);

        $json = $this->getJson(self::RUTA)->assertStatus(200)->json();

        $this->assertSame([], $json['data']);
        $this->assertSame(0, $json['total']);
        $this->assertSame(1, $json['last_page']);
    }
}
