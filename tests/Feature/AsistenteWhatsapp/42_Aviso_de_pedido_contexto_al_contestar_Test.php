<?php

namespace Tests\Feature\AsistenteWhatsapp;

use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiMessage;
use App\Models\Article;
use App\Models\Buyer;
use App\Models\OnlineConfiguration;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\PaymentMethod;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Misión whatsapp-aviso-pedido (25/11/2026) — TESTS DE ACEPTACIÓN, parte 2: CA12, «si el dueño contesta, el
 * asistente sabe de qué pedido se habla».
 *
 * El aviso del pedido N° 78 (el del CA1: con entrega, pago y artículos) se sella como mandado con `wamid.PEDIDO78`
 * y queda en la conversación del dueño como un mensaje del asistente. Cuando el dueño contesta «¿qué compró?»
 * citando esa conversación, el turno REAL (ResponderMensajeChatIaJob) le pasa al modelo —doblado con Http::fake—
 * el historial, y en ese historial tiene que estar el aviso completo.
 *
 * 🔴 Hoy está en ROJO a propósito: el aviso que queda en la conversación es el viejo (sólo número, comprador y
 * total) y la casilla que lo prende por defecto no existe.
 *
 * Reutiliza la escena de TareasDelAsistenteConModeloTestCase (job real del turno, modelo doblado, `pedidos_al_modelo()`).
 * Ningún mensaje sale a la red: todo host sin stub contesta 500.
 *
 * PHP 7.4: sin match, sin ?->, sin str_contains, sin argumentos nombrados, sin union types.
 */
class Aviso_de_pedido_contexto_al_contestar_Test extends TareasDelAsistenteConModeloTestCase
{
    /**
     * El texto exacto del aviso del pedido N° 78 del CA1 (el mismo que fija el test 41, en el formato nuevo de la
     * misión asistente-diario-20261128). Pasó de constante a método porque lleva el email del comprador del test.
     *
     * @param  string  $email  El email del comprador del pedido.
     * @return string
     */
    protected function texto_del_pedido_78($email)
    {
        return "🛒 Entró un pedido nuevo en la tienda: *N° 78*\n"
            . "Comprador: Gabriel Domínguez · Tel. sin cargar · " . $email . "\n"
            . "Entrega: Retiro por local\n"
            . "Artículos:\n"
            . "• Malbec Reserva x2 · \$94.000 c/u = \$188.000\n"
            . "Total: *\$188.000*\n"
            . "Pago: Mercado Pago";
    }

    /**
     * 🔴 CA12 — El aviso sellado queda en la conversación con su texto completo y, al contestar, el modelo lo ve.
     *
     * @group asistente-whatsapp
     * @test
     */
    public function al_contestar_el_modelo_ve_el_aviso_del_pedido()
    {
        // El dueño tiene Agentes de IA (RecordatoriosTestCase) y la configuración de la tienda recién creada.
        OnlineConfiguration::create(['user_id' => $this->comercio->id]);

        DB::table('users')->where('id', $this->comercio->id)->update([
            'agente_aviso_pedidos_cursor' => AvisosDelDuenoIaHelper::ultimo_id('orders'),
            'agente_aviso_pedidos_desde'  => '2026-11-21 09:00:00',
        ]);

        $conversacion = $this->conversacion_whatsapp(now()->subMinutes(30));

        $comprador = Buyer::create([
            'name'    => 'Gabriel',
            'surname' => 'Domínguez',
            'email'   => 'comprador-ctx-' . uniqid() . '@testing.local',
            'user_id' => $this->comercio->id,
        ]);
        $metodo = PaymentMethod::create(['name' => 'Mercado Pago', 'user_id' => $this->comercio->id]);
        $estado = OrderStatus::first();

        // El pedido entró hace 10 minutos (el reloj de esta suite es el sábado 21/11/2026 a las 10:00).
        $this->ahora('2026-11-21 09:50:00');
        $pedido = Order::create([
            'num'               => 78,
            'status'            => 'unconfirmed',
            'deliver'           => 0,
            'buyer_id'          => $comprador->id,
            'order_status_id'   => is_null($estado) ? 1 : $estado->id,
            'user_id'           => $this->comercio->id,
            'payment_method_id' => $metodo->id,
            'total'             => 0,
        ]);
        $articulo = Article::create(['name' => 'Malbec Reserva', 'user_id' => $this->comercio->id, 'status' => 'active']);
        $pedido->articles()->attach($articulo->id, ['price' => 94000, 'amount' => 2]);
        $this->ahora(self::SABADO);

        // El admin consulta y el ítem trae el aviso completo, atado a la conversación del dueño.
        $respuesta = $this->pendientes();
        $respuesta->assertStatus(200);

        $items = array_values(array_filter($respuesta->json('recordatorios'), function ($item) {
            return strpos($item['id'], AvisosDelDuenoIaHelper::PREFIJO_PEDIDO) === 0;
        }));

        $this->assertCount(1, $items, 'El pedido nuevo se ofrece una sola vez.');
        $this->assertSame($this->texto_del_pedido_78($comprador->email), $items[0]['texto']);
        $this->assertSame((int) $conversacion->id, $items[0]['ai_conversation_id']);

        // El admin lo manda y lo sella: queda como mensaje del asistente en el canal de WhatsApp, con el texto entero.
        $this->avisado($items[0]['id'], 'wamid.PEDIDO78')->assertStatus(200);

        $guardado = AiMessage::where('ai_conversation_id', $conversacion->id)->where('whatsapp_message_id', 'wamid.PEDIDO78')->get();

        $this->assertCount(1, $guardado);
        $this->assertSame('assistant', $guardado[0]->rol);
        $this->assertSame(AiMessage::CANAL_WHATSAPP, $guardado[0]->canal);
        $this->assertSame($this->texto_del_pedido_78($comprador->email), $guardado[0]->contenido, 'Queda lo que salió, entero.');

        // El dueño contesta citando ese aviso: el turno real le pasa al modelo la conversación con el aviso adentro.
        $this->modelo_contesta('Compró 2 Malbec Reserva por $188.000.');

        $entrada = $this->mandar([
            'texto'               => '¿qué compró?',
            'ai_conversation_id'  => $conversacion->id,
            'whatsapp_message_id' => 'wamid.DUENO-PREGUNTA',
        ]);
        $entrada->assertStatus(202);
        $this->assertSame((int) $conversacion->id, (int) $entrada->json('ai_conversation_id'), 'La respuesta citada va a la conversación del aviso.');

        (new ResponderMensajeChatIaJob((int) $entrada->json('ai_message_id')))->handle();

        $pedidos = $this->pedidos_al_modelo();
        $this->assertNotEmpty($pedidos, 'El turno le tiene que haber hablado al modelo.');

        $visto = implode("\n", $this->textos($pedidos[0]['messages']));

        $this->assertStringContainsString('*N° 78*', $visto);
        $this->assertStringContainsString('Entrega: Retiro por local', $visto);
        $this->assertStringContainsString('Pago: Mercado Pago', $visto);
        $this->assertStringContainsString('• Malbec Reserva x2 · $94.000 c/u = $188.000', $visto);
        $this->assertStringContainsString('¿qué compró?', $visto);
    }
}
