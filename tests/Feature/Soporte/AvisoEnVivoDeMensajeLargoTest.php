<?php

namespace Tests\Feature\Soporte;

use App\Events\SupportMessageReceived;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261117, M2 (log de producción de Truvari, 16/11/2026 14:47:27): el aviso en vivo del
 * pedido a soporte no salió porque la carga del evento SupportMessageReceived pasaba los 10.240 bytes que acepta
 * Pusher («exceeds the allowed maximum»). El pedido lleva la charla con el asistente y el cuerpo es largo.
 *
 * Qué cubre:
 *  - un mensaje largo viaja en una versión liviana por debajo del límite, con los mismos nombres de campo, los
 *    ids del mensaje y del ticket, el contador de no leídos del ticket, el cuerpo recortado con «…» y la marca
 *    `recortado: true`;
 *  - un mensaje corto viaja igual que siempre (sin la marca), para que nada cambie donde no hacía falta;
 *  - el cuerpo con acentos y saltos de línea (que en JSON ocupan más de un byte) también queda por debajo.
 */
class AvisoEnVivoDeMensajeLargoTest extends EmpresaTestCase
{
    /**
     * Crea un ticket y un mensaje del usuario logueado con el cuerpo dado.
     *
     * @param  string  $cuerpo
     * @return \App\Models\SupportMessage
     */
    protected function mensaje(string $cuerpo)
    {
        $usuario = Auth::user();
        $ticket = SupportTicket::create(['user_id' => $usuario->id, 'origen' => 'sistema', 'desde_agentes' => false, 'name' => 'Pedido del asistente']);

        return SupportMessage::create([
            'support_ticket_id' => $ticket->id, 'sender_type' => 'user', 'sender_user_id' => $usuario->id,
            'kind' => 'text', 'body' => $cuerpo, 'delivered_at' => now(),
        ]);
    }

    /**
     * Los bytes que ocupa la carga como la serializa el cliente de Pusher (json_encode sin flags).
     *
     * @param  array  $carga
     * @return int
     */
    protected function bytes(array $carga): int
    {
        return strlen(json_encode($carga));
    }

    public function test_mensaje_largo_viaja_liviano_por_debajo_del_limite_y_conserva_los_ids()
    {
        $mensaje = $this->mensaje(str_repeat("Charla con el asistente: ¿facturaste la venta? Sí, la facturé.\n", 400));
        $evento = new SupportMessageReceived($mensaje->id, Auth::id());

        // Precondición: sin la versión liviana la carga pasaba el límite de Pusher.
        $this->assertGreaterThan(10240, $this->bytes(['message' => $evento->message->toArray()]));

        $carga = $evento->broadcastWith();
        $this->assertLessThan(10240, $this->bytes($carga));
        $this->assertLessThanOrEqual(SupportMessageReceived::LIMITE_DE_BYTES, $this->bytes($carga));

        $liviano = $carga['message'];
        $this->assertSame((int) $mensaje->id, (int) $liviano['id']);
        $this->assertSame($mensaje->uuid, $liviano['uuid']);
        $this->assertSame((int) $mensaje->support_ticket_id, (int) $liviano['support_ticket_id']);
        $this->assertSame('user', $liviano['sender_type']);
        $this->assertSame('text', $liviano['kind']);
        $this->assertNotEmpty($liviano['created_at']);
        $this->assertTrue($liviano['recortado']);
        $this->assertStringEndsWith('…', $liviano['body']);
        $this->assertStringStartsWith('Charla con el asistente', $liviano['body']);
        $this->assertGreaterThan(1000, mb_strlen($liviano['body']), 'El recorte tiene que dejar texto útil, no un renglón.');

        // El ticket va con sus campos y el contador del badge (empresa-spa lo lee de message.ticket).
        $this->assertSame((int) $mensaje->support_ticket_id, (int) $liviano['ticket']['id']);
        $this->assertArrayHasKey('unread_messages_count', $liviano['ticket']);
        $this->assertArrayHasKey('status', $liviano['ticket']);
        $this->assertIsArray($liviano['attachments']);
    }

    public function test_mensaje_corto_viaja_igual_que_siempre()
    {
        $mensaje = $this->mensaje('Necesito ayuda con una factura');
        $evento = new SupportMessageReceived($mensaje->id, Auth::id());

        $carga = $evento->broadcastWith();

        $this->assertSame($evento->message->toArray(), $carga['message']);
        $this->assertArrayNotHasKey('recortado', $carga['message']);
        $this->assertSame('Necesito ayuda con una factura', $carga['message']['body']);
    }

    public function test_cuerpo_con_acentos_y_saltos_tambien_entra()
    {
        $mensaje = $this->mensaje(str_repeat("ñandú áéíóú «comillas» \"dobles\"\n\t", 900));
        $carga = (new SupportMessageReceived($mensaje->id, Auth::id()))->broadcastWith();

        $this->assertLessThanOrEqual(SupportMessageReceived::LIMITE_DE_BYTES, $this->bytes($carga));
        $this->assertTrue($carga['message']['recortado']);
        // El recorte no parte un carácter: el cuerpo sigue siendo UTF-8 válido.
        $this->assertTrue(mb_check_encoding($carga['message']['body'], 'UTF-8'));
    }
}
