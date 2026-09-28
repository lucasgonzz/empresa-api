<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\TiendaChatActualizado;
use App\Models\Message;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * `POST tienda-chats/{buyer_id}/leer` (misión mensajes-tienda-online, 28/9/2026, contrato C3).
 *
 * - UN solo `UPDATE` sobre `messages`, sin importar cuántos haya sin leer (nunca un loop de
 *   `save()`), y solo sobre los mensajes DEL COMPRADOR sin leer de esa conversación.
 * - Evento C1 con `message: null` y `unread_count: 0`.
 * - 404 con un comprador ajeno, sin tocar sus mensajes.
 *
 * PHP 7.4.
 */
class LeerConversacionTest extends EmpresaTestCase
{
    use ConversacionesDePrueba;

    /** @var \App\Models\User */
    protected $dueno;

    /** @var \App\Models\Buyer */
    protected $comprador;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([TiendaChatActualizado::class]);

        $this->dueno = $this->crear_dueno('leer');
        $this->comprador = $this->crear_comprador_de($this->dueno, [
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => 'ana-leer-'.uniqid().'@test.local',
        ]);

        $this->actuar_como($this->dueno);
    }

    /**
     * @param  int|string|null  $buyer_id
     * @return string
     */
    protected function ruta($buyer_id = null)
    {
        return 'api/tienda-chats/'.(is_null($buyer_id) ? $this->comprador->id : $buyer_id).'/leer';
    }

    /**
     * @test
     */
    public function marca_leidos_los_del_comprador_con_un_solo_update_y_avisa_con_message_null()
    {
        $sin_leer = [];
        for ($i = 1; $i <= 7; $i++) {
            $sin_leer[] = $this->mensaje_del_comprador($this->comprador, ['text' => 'Pendiente '.$i]);
        }
        // Un mensaje del comercio que el comprador todavía no leyó: NO se toca (su `read` es del comprador).
        $del_comercio = $this->mensaje_del_comercio($this->comprador, ['read' => 0]);

        // Otro comprador del mismo comercio: su conversación no se toca.
        $otro = $this->crear_comprador_de($this->dueno);
        $del_otro = $this->mensaje_del_comprador($otro);

        $self = $this;
        $respuesta = null;

        $consultas = $this->consultas_de(function () use ($self, &$respuesta) {
            $respuesta = $self->postJson($self->ruta());
        });

        $respuesta->assertStatus(200);
        $this->assertSame(['unread_count' => 0], $respuesta->json());

        $updates = array_values(array_filter($consultas, function ($consulta) {
            return preg_match('/^update `messages`/i', $consulta['query']) === 1;
        }));

        $this->assertCount(1, $updates, 'Marcar leída una conversación tiene que ser UN solo UPDATE, no uno por mensaje.');

        foreach ($sin_leer as $mensaje) {
            $this->assertSame(1, (int) $mensaje->fresh()->read);
        }
        $this->assertSame(0, (int) $del_comercio->fresh()->read, 'Se marcó leído un mensaje del comercio.');
        $this->assertSame(0, (int) $del_otro->fresh()->read, 'Se marcó leído un mensaje de otro comprador.');

        $eventos = $this->eventos_emitidos();

        $this->assertCount(1, $eventos);
        $this->assertSame('private-tienda-mensajes.'.$this->dueno->id, $eventos[0]->broadcastOn()->name);
        $this->assertSame('TiendaChatActualizado', $eventos[0]->broadcastAs());

        $payload = $eventos[0]->broadcastWith();

        $this->assertSame(['buyer_id', 'chat', 'buyer', 'message'], array_keys($payload));
        $this->assertSame($this->comprador->id, $payload['buyer_id']);
        $this->assertNull($payload['message']);
        $this->assertSame([
            'buyer_id'        => $this->comprador->id,
            'unread_count'    => 0,
            // La hora de la fila no se pierde: es la del último mensaje de la conversación.
            'last_message_at' => $del_comercio->fresh()->created_at->toJSON(),
        ], $payload['chat']);
        // El contrato permite `buyer: null` en este evento; empresa-api lo manda igual (ya lo tiene).
        $this->assertSame([
            'id'      => $this->comprador->id,
            'name'    => 'Ana',
            'surname' => 'Pérez',
            'email'   => $this->comprador->email,
            'phone'   => null,
        ], $payload['buyer']);
    }

    /**
     * La cantidad de UPDATE no depende de cuántos mensajes haya sin leer.
     *
     * @test
     */
    public function con_un_solo_pendiente_tambien_es_un_solo_update()
    {
        $this->mensaje_del_comprador($this->comprador);

        $self = $this;

        $consultas = $this->consultas_de(function () use ($self) {
            $self->postJson($self->ruta())->assertStatus(200);
        });

        $updates = array_filter($consultas, function ($consulta) {
            return preg_match('/^update `messages`/i', $consulta['query']) === 1;
        });

        $this->assertCount(1, $updates);
    }

    /**
     * @test
     */
    public function un_comprador_ajeno_da_404_y_no_se_toca()
    {
        $ajeno = $this->crear_comprador_de($this->crear_dueno('leer ajeno'));
        $mensaje = $this->mensaje_del_comprador($ajeno);

        $this->postJson($this->ruta($ajeno->id))->assertStatus(404);

        $this->assertSame(0, (int) $mensaje->fresh()->read);
        $this->assertCount(0, $this->eventos_emitidos());
    }

    /**
     * @test
     */
    public function el_empleado_marca_leido_y_el_evento_va_al_canal_del_dueno()
    {
        $mensaje = $this->mensaje_del_comprador($this->comprador);

        $this->actuar_como($this->crear_empleado($this->dueno));

        $this->postJson($this->ruta())->assertStatus(200);

        $this->assertSame(1, (int) $mensaje->fresh()->read);
        $this->assertSame('private-tienda-mensajes.'.$this->dueno->id, $this->eventos_emitidos()[0]->broadcastOn()->name);
    }
}
