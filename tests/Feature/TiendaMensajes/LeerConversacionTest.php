<?php

namespace Tests\Feature\TiendaMensajes;

use App\Events\TiendaChatActualizado;
use App\Models\Message;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * `POST tienda-chats/{buyer_id}/leer` (misión mensajes-tienda-online, 28/9/2026, contrato C3).
 *
 * - UN solo `UPDATE` sobre `messages`, sin importar cuántos haya sin leer (nunca un loop de
 *   `save()`), y solo sobre los mensajes DEL COMPRADOR sin leer de esa conversación.
 * - Evento C1 con `message: null` y `unread_count: 0`, SOLO si el `UPDATE` tocó alguna fila, y
 *   DESPUÉS de mandada la respuesta.
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
     * Abrir una conversación que no tiene nada sin leer no le avisa nada a nadie (la SPA marca leído
     * cada vez que abre una). La respuesta HTTP es la misma.
     *
     * @test
     */
    public function si_no_hay_nada_para_marcar_responde_igual_y_no_emite()
    {
        $this->mensaje_del_comprador($this->comprador, ['read' => 1]);
        // Un mensaje del comercio sin leer por el comprador no es "algo para marcar" del lado del comercio.
        $this->mensaje_del_comercio($this->comprador, ['read' => 0]);

        $respuesta = $this->postJson($this->ruta());

        $respuesta->assertStatus(200);
        $this->assertSame(['unread_count' => 0], $respuesta->json());
        $this->assertCount(0, $this->eventos_emitidos(), 'Se emitió C1 sin que el UPDATE tocara ninguna fila.');
    }

    /**
     * @test
     */
    public function una_conversacion_sin_mensajes_tampoco_emite()
    {
        $this->postJson($this->ruta())->assertStatus(200)->assertExactJson(['unread_count' => 0]);

        $this->assertCount(0, $this->eventos_emitidos());
    }

    /**
     * Con filas marcadas, C1 sale DESPUÉS de mandada la respuesta (ver
     * `EnviarMensajeTest::los_avisos_salen_despues_de_mandada_la_respuesta`).
     *
     * @test
     */
    public function con_filas_marcadas_emite_despues_de_mandada_la_respuesta()
    {
        $mensaje = $this->mensaje_del_comprador($this->comprador);

        $kernel = $this->app->make(HttpKernel::class);

        $request = Request::create('/'.$this->ruta(), 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(1, (int) $mensaje->fresh()->read);
        $this->assertCount(0, $this->eventos_emitidos(), 'C1 salió ANTES de mandada la respuesta.');

        $kernel->terminate($request, $response);

        $this->assertCount(1, $this->eventos_emitidos());
        $this->assertNull($this->eventos_emitidos()[0]->broadcastWith()['message']);
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
