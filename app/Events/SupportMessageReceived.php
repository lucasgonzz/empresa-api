<?php

namespace App\Events;

use App\Models\SupportMessage;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * Hasta cuántos bytes puede ocupar la carga (como la serializa Pusher: json_encode sin flags) antes de
     * mandarse la versión liviana. Pusher rechaza más de 10.240 bytes por evento; el margen cubre el nombre
     * del evento, el canal y lo que agregue la librería.
     */
    const LIMITE_DE_BYTES = 9000;

    /** La marca de la versión liviana: el cuerpo viaja recortado y el mensaje entero está en el ticket. */
    const MARCA_RECORTADO = 'recortado';

    /** Lo que se agrega al final del cuerpo recortado, para que se vea que sigue. */
    const PUNTOS_SUSPENSIVOS = '…';

    /**
     * Mensaje completo a entregar al frontend.
     *
     * @var SupportMessage|null
     */
    public $message;

    /**
     * Canal histórico por usuario.
     *
     * @var string
     */
    public $channel_name;

    /**
     * Construye evento cargando el mensaje con sus relaciones.
     *
     * @param int $support_message_id
     * @param int $user_id
     */
    public function __construct(int $support_message_id, int $user_id)
    {
        // Recupera mensaje con relaciones completas para render inmediato en SPA (ticket para bandeja vía Pusher).
        $this->message = SupportMessage::where('id', $support_message_id)->withAll()->first();
        if (!is_null($this->message)) {
            self::prepareMessageAndTicketForClient($this->message);
        }
        // Define canal de soporte por usuario.
        $this->channel_name = 'support.user.' . $user_id;
    }

    /**
     * Carga ticket y contador de no leídos (mensajes admin sin read_at) para badge en empresa-spa.
     */
    private static function prepareMessageAndTicketForClient(SupportMessage $message): void
    {
        $message->loadMissing('ticket');
        if ($message->ticket) {
            $message->ticket->loadCount([
                'messages as unread_messages_count' => function ($sub) {
                    $sub->where('sender_type', 'admin')->whereNull('read_at');
                },
            ]);
        }
    }

    /**
     * Define el canal de broadcast.
     */
    public function broadcastOn()
    {
        if ($this->message && $this->message->ticket && $this->message->ticket->requiere_canal_privado()) {
            return new PrivateChannel('agentes.support.user.' . $this->message->ticket->user_id);
        }

        return new Channel($this->channel_name);
    }

    /**
     * Nombre explícito del evento para escuchar desde Echo.
     */
    public function broadcastAs()
    {
        return 'SupportMessageReceived';
    }

    /**
     * Payload enviado a Pusher: array (no modelo suelto) para que vayan anidadas
     * message.ticket.unread_messages_count (el badge de empresa-spa depende de ello).
     *
     * Si el mensaje completo pasa LIMITE_DE_BYTES viaja la versión liviana (ver carga_liviana()).
     */
    public function broadcastWith()
    {
        if (is_null($this->message)) {
            return [
                'message' => null,
            ];
        }

        return [
            'message' => self::carga_para_pusher($this->message->toArray()),
        ];
    }

    /**
     * El mensaje tal cual si entra en LIMITE_DE_BYTES; si no, su versión liviana.
     *
     * 🔴 POR QUÉ (misión asistente-diario-20261117, M2). Log de producción de Truvari, 16/11/2026 14:47:27:
     * «PedidoDeAyudaASoporteIaHelper: no se pudo avisar… exceeds the allowed maximum (10240 bytes)». El pedido
     * a soporte que arma el asistente lleva la charla entera en el cuerpo, y con el ticket, los adjuntos y el
     * usuario la carga pasaba lo que acepta Pusher: el aviso en vivo no salía (el mensaje sí quedaba guardado).
     *
     * @param  array  $mensaje  El mensaje como lo devuelve toArray() (con ticket, attachments y sender_user).
     * @return array
     */
    public static function carga_para_pusher(array $mensaje): array
    {
        if (self::bytes($mensaje) <= self::LIMITE_DE_BYTES) {
            return $mensaje;
        }

        return self::carga_liviana($mensaje);
    }

    /**
     * La versión liviana del mensaje, COMPATIBLE con el empresa-spa de antes y de después:
     *  - los mismos nombres de campo: todos los escalares del mensaje (id, uuid, support_ticket_id, sender_type,
     *    kind, body, fechas…), el `ticket` con sus escalares (id, status, name, unread_messages_count…), los
     *    `attachments` con los escalares de cada uno y el `sender_user` solo con id y name;
     *  - el `body` recortado hasta que la carga entre, terminado en «…»;
     *  - la marca `recortado: true`, que el SPA nuevo usa para pedir el mensaje entero con la ruta del ticket
     *    (GET api/support-ticket/{id}); el SPA viejo la ignora y muestra el cuerpo recortado, y el mensaje
     *    completo aparece al abrir el ticket.
     * No se renombra ni se saca ningún campo que el SPA lea para pintar la burbuja o el badge.
     *
     * @param  array  $mensaje
     * @return array
     */
    protected static function carga_liviana(array $mensaje): array
    {
        $liviano = self::escalares($mensaje);

        if (isset($mensaje['ticket']) && is_array($mensaje['ticket'])) {
            $liviano['ticket'] = self::escalares($mensaje['ticket']);
        } elseif (array_key_exists('ticket', $mensaje)) {
            $liviano['ticket'] = null;
        }

        if (isset($mensaje['attachments']) && is_array($mensaje['attachments'])) {
            $adjuntos = [];
            foreach ($mensaje['attachments'] as $adjunto) {
                $adjuntos[] = is_array($adjunto) ? self::escalares($adjunto) : $adjunto;
            }
            $liviano['attachments'] = $adjuntos;
        }

        if (isset($mensaje['sender_user']) && is_array($mensaje['sender_user'])) {
            $liviano['sender_user'] = [
                'id'   => isset($mensaje['sender_user']['id']) ? $mensaje['sender_user']['id'] : null,
                'name' => isset($mensaje['sender_user']['name']) ? $mensaje['sender_user']['name'] : null,
            ];
        } elseif (array_key_exists('sender_user', $mensaje)) {
            $liviano['sender_user'] = null;
        }

        $liviano[self::MARCA_RECORTADO] = true;

        $cuerpo = isset($liviano['body']) && is_string($liviano['body']) ? $liviano['body'] : '';
        $liviano['body'] = $cuerpo;

        if (self::bytes($liviano) <= self::LIMITE_DE_BYTES) {
            return $liviano;
        }

        /*
         * El cuerpo se acorta hasta que la carga entre. Se mide con json_encode sin flags (como Pusher), donde
         * una «ñ» o un salto de línea ocupan más de un byte: por eso se mide de nuevo en cada vuelta en vez
         * de calcular una sola vez. Cada vuelta deja el 80 % del largo anterior; veinte vueltas bajan
         * cualquier cuerpo a casi nada.
         */
        $largo = mb_strlen($cuerpo);
        $vueltas = 0;
        do {
            $largo = (int) floor($largo * 0.8);
            $liviano['body'] = rtrim(mb_substr($cuerpo, 0, $largo)) . self::PUNTOS_SUSPENSIVOS;
            $vueltas++;
        } while ($largo > 0 && $vueltas < 20 && self::bytes($liviano) > self::LIMITE_DE_BYTES);

        if (self::bytes($liviano) > self::LIMITE_DE_BYTES) {
            $liviano['body'] = self::PUNTOS_SUSPENSIVOS;
        }

        return $liviano;
    }

    /**
     * Solo las claves escalares (null, booleano, número o texto) de un array: los objetos y las listas
     * anidadas se dejan afuera.
     *
     * @param  array  $datos
     * @return array
     */
    protected static function escalares(array $datos): array
    {
        $escalares = [];
        foreach ($datos as $clave => $valor) {
            if (is_null($valor) || is_scalar($valor)) {
                $escalares[$clave] = $valor;
            }
        }
        return $escalares;
    }

    /**
     * Los bytes que ocupa `{message: ...}` serializado como lo serializa el cliente de Pusher.
     *
     * @param  array  $mensaje
     * @return int
     */
    protected static function bytes(array $mensaje): int
    {
        $json = json_encode(['message' => $mensaje], JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? PHP_INT_MAX : strlen($json);
    }
}

