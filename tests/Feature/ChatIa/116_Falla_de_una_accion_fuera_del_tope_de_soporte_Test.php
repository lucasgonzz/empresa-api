<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\PedidoDeAyudaASoporteIaHelper;
use App\Models\AiMessageAction;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P10 — el pedido a soporte por la falla técnica de una acción del
 * asistente no cuenta para el tope del día (decisión de Lucas: el tope NO sube).
 *
 * 🔴 EL CASO REAL (Truvari, 13/11/2026). La facturación de las ventas 3102-3104 desde el asistente falló («No se
 * pudo registrar») y, con los 3 pedidos del día usados, el asistente le dijo al dueño que escribiera él la falla en
 * el chat de Ayuda (V10).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class Falla_de_una_accion_fuera_del_tope_de_soporte_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        Http::fake(['*' => Http::response(['ok' => true], 200)]);
    }

    /** Los pedidos de hoy ya usados, hasta el tope. */
    protected function tope_usado()
    {
        $ticket = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open']);

        for ($i = 1; $i <= PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA; $i++) {
            SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type'       => 'user',
                'sender_user_id'    => $this->dueno->id,
                'kind'              => 'text',
                'body'              => '[Pedido desde el agente personal por WhatsApp] pedido ' . $i,
            ]);
        }
    }

    /** Una tarjeta de la conversación cuya ejecución falló (sigue propuesta, con su error_mensaje). */
    protected function tarjeta_que_fallo($conversation, $assistant, $error = 'No se pudo registrar.')
    {
        return AiMessageAction::create([
            'ai_message_id'      => $assistant->id,
            'ai_conversation_id' => $conversation->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_ACCION_PANTALLA,
            'clave'              => 'p116-' . uniqid(),
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [],
            'presentacion'       => [],
            'error_mensaje'      => $error,
        ]);
    }

    protected function pedir($conversation, $assistant, $detalle)
    {
        return $this->herramienta($conversation, $assistant, 'pedir_ayuda_a_soporte', ['detalle' => $detalle]);
    }

    /**
     * Con el tope usado, el pedido por la falla de la facturación sale igual, marcado con la tarjeta y su error, y no
     * suma al tope. Un segundo pedido por la MISMA falla ya no es exento: vuelve el tope.
     *
     * @test
     */
    public function la_falla_de_una_accion_sale_aunque_el_tope_este_usado_y_una_sola_vez()
    {
        $this->tope_usado();

        list($conversation, $facturar) = $this->vuelta('Si facturala');
        $facturar->update(['estado' => 'listo', 'contenido' => 'No se pudo registrar la factura de la venta 3102.']);
        $tarjeta = $this->tarjeta_que_fallo($conversation, $facturar);

        list($conversation, $assistant) = $this->vuelta('pedile a soporte que revean el tema de la factura', $conversation);

        $respuesta = $this->pedir($conversation, $assistant, 'La facturación de la venta 3102 desde el asistente falló con «No se pudo registrar».');

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA, $respuesta['pedidos_de_hoy'], 'No suma al tope.');

        $mensaje = SupportMessage::where('support_ticket_id', $respuesta['ticket']['id'])->orderBy('id', 'DESC')->first();
        $this->assertStringStartsWith('[Pedido desde el agente personal', $mensaje->body);
        $this->assertStringContainsString(PedidoDeAyudaASoporteIaHelper::MARCA_DE_FALLA . $tarjeta->id . ', accion_pantalla): No se pudo registrar.', $mensaje->body);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA, PedidoDeAyudaASoporteIaHelper::pedidos_de_hoy($this->dueno->id));

        /* La misma falla otra vez: ya está reportada, así que manda el tope (y el texto sigue honesto). */
        list($conversation, $otra) = $this->vuelta('pedile de nuevo', $conversation);
        $segunda = $this->pedir($conversation, $otra, 'Otra vez lo de la factura.');

        $this->assertFalse($segunda['ok']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::CODIGO_TOPE, $segunda['codigo']);
        $this->assertStringContainsString('NO se mandó este pedido', $segunda['error']);
    }

    /**
     * Sin una falla reciente, el tope de siempre; una falla de hace más de HORAS_DE_LA_FALLA tampoco libera el tope.
     *
     * @test
     */
    public function sin_una_falla_reciente_manda_el_tope_de_siempre()
    {
        $this->tope_usado();

        list($conversation, $assistant) = $this->vuelta('pedile a soporte que vea el tema del WhatsApp');
        $sin_falla = $this->pedir($conversation, $assistant, 'Quiere que revisen el WhatsApp.');
        $this->assertFalse($sin_falla['ok']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::CODIGO_TOPE, $sin_falla['codigo']);

        $vieja = $this->tarjeta_que_fallo($conversation, $assistant);
        AiMessageAction::where('id', $vieja->id)->update(['updated_at' => Carbon::now()->subHours(PedidoDeAyudaASoporteIaHelper::HORAS_DE_LA_FALLA + 1)]);

        list($conversation, $otra) = $this->vuelta('pedile a soporte por la factura', $conversation);
        $con_falla_vieja = $this->pedir($conversation, $otra, 'La factura de ayer.');
        $this->assertFalse($con_falla_vieja['ok']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::CODIGO_TOPE, $con_falla_vieja['codigo']);
    }
}
