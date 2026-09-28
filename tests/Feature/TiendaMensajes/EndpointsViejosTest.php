<?php

namespace Tests\Feature\TiendaMensajes;

use App\Http\Controllers\CommonLaravel\Helpers\StringHelper;
use App\Models\Message;
use Tests\EmpresaTestCase;

/**
 * Los endpoints viejos de mensajes, que siguen vivos para la SPA de los clientes sin actualizar
 * (misión mensajes-tienda-online, 28/9/2026, contrato C4): `GET message/{buyer_id}`,
 * `GET message/set-read/{buyer_id}` y `POST message`.
 *
 * Hasta esta misión no verificaban que el comprador fuera del comercio: en las bases compartidas
 * (`u767360347_empresa`, 51 comercios adentro) se leían y se marcaban chats ajenos por id. Ahora
 * un comprador ajeno da la lista vacía, el `set-read` no toca nada y el `POST` da 404. Un pedido
 * legítimo sigue dando lo mismo que antes.
 *
 * PHP 7.4.
 */
class EndpointsViejosTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dueno = $this->crear_dueno('viejos');

        $this->actuar_como($this->dueno);
    }

    /**
     * @test
     */
    public function la_conversacion_vieja_de_un_comprador_ajeno_viene_vacia()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('viejos ajeno'));
        $this->mensaje_del_comprador($ajeno, ['text' => 'Esto es de otro comercio']);

        $this->getJson('api/message/'.$ajeno->id)
             ->assertStatus(200)
             ->assertExactJson(['models' => []]);
    }

    /**
     * @test
     */
    public function la_conversacion_vieja_de_un_comprador_propio_sigue_igual()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $primero = $this->mensaje_del_comprador($comprador, ['text' => 'uno']);
        $segundo = $this->mensaje_del_comercio($comprador, ['text' => 'dos']);

        $models = $this->getJson('api/message/'.$comprador->id)->assertStatus(200)->json('models');

        $this->assertSame([$primero->id, $segundo->id], array_map(function ($m) {
            return $m['id'];
        }, $models));
        // Sigue trayendo el `article` completo del withAll() (null acá: sin artículo).
        $this->assertArrayHasKey('article', $models[0]);
    }

    /**
     * @test
     */
    public function el_set_read_viejo_no_toca_a_un_comprador_ajeno()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('viejos ajeno set-read'));
        $mensaje = $this->mensaje_del_comprador($ajeno);

        $this->getJson('api/message/set-read/'.$ajeno->id)->assertStatus(200);

        $this->assertSame(0, (int) $mensaje->fresh()->read);
    }

    /**
     * @test
     */
    public function el_set_read_viejo_marca_los_del_comprador_propio_con_un_solo_update()
    {
        $comprador = $this->crear_comprador_de($this->dueno);
        $pendientes = [
            $this->mensaje_del_comprador($comprador),
            $this->mensaje_del_comprador($comprador),
            $this->mensaje_del_comprador($comprador),
        ];
        $del_comercio = $this->mensaje_del_comercio($comprador, ['read' => 0]);

        $self = $this;

        $consultas = $this->consultas_de(function () use ($self, $comprador) {
            $self->getJson('api/message/set-read/'.$comprador->id)->assertStatus(200);
        });

        foreach ($pendientes as $mensaje) {
            $this->assertSame(1, (int) $mensaje->fresh()->read);
        }
        $this->assertSame(0, (int) $del_comercio->fresh()->read);

        $updates = array_filter($consultas, function ($consulta) {
            return preg_match('/^update `messages`/i', $consulta['query']) === 1;
        });

        $this->assertCount(1, $updates);
    }

    /**
     * @test
     */
    public function el_post_viejo_a_un_comprador_ajeno_da_404_y_no_guarda_nada()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('viejos ajeno post'));

        $this->postJson('api/message', ['buyer_id' => $ajeno->id, 'text' => 'Te escribo desde otro comercio'])
             ->assertStatus(404)
             ->assertExactJson(['message' => 'Comprador no encontrado.']);

        $this->assertSame(0, Message::where('buyer_id', $ajeno->id)->count());
    }

    /**
     * Para un comprador propio, la respuesta de siempre: 201 `{ model }` con el mensaje completo del
     * `withAll()` y el texto pasado por `onlyFirstWordUpperCase` (el endpoint viejo lo reescribe;
     * el nuevo no).
     *
     * @test
     */
    public function el_post_viejo_a_un_comprador_propio_responde_igual_que_antes()
    {
        $comprador = $this->crear_comprador_de($this->dueno);

        $json = $this->postJson('api/message', ['buyer_id' => $comprador->id, 'text' => 'HOLA, te ESCRIBO'])
                     ->assertStatus(201)
                     ->json();

        $this->assertSame(['model'], array_keys($json));

        $model = $json['model'];

        $claves = array_keys($model);
        sort($claves);

        $esperadas = ['article', 'article_id', 'buyer_id', 'created_at', 'from_buyer', 'id', 'order_id', 'read', 'text', 'type', 'updated_at', 'user_id'];

        $this->assertSame($esperadas, $claves);
        $this->assertSame(StringHelper::onlyFirstWordUpperCase('HOLA, te ESCRIBO'), $model['text']);
        $this->assertSame('Hola, te escribo', $model['text']);
        $this->assertSame($comprador->id, (int) $model['buyer_id']);
        $this->assertSame($this->dueno->id, (int) $model['user_id']);
        $this->assertSame(0, (int) $model['from_buyer']);
        $this->assertNull($model['article']);
        $this->assertSame(1, Message::where('buyer_id', $comprador->id)->count());
    }
}
