<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\PedidoDeAyudaASoporteIaHelper;
use App\Models\AiMessage;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\AsistenteIa\Mcp\McpHerramientasHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión agente-mejoras-uso-real (29/10/2026, §4 E3.3 del plan) — pedir_ayuda_a_soporte.
 *
 * EL CASO REAL (CONV 24 de Ferre Total, 29/10/2026): *"Le pedis a lucas que vea este tema"* → el agente
 * contestó *"No puedo escribirle a Lucas: solo puedo mandar mensajes a clientes del negocio"*. Lo que
 * protege este archivo:
 *
 *  - el pedido queda como un mensaje de la PERSONA en su ticket abierto del chat de soporte (el mismo de
 *    Ayuda del sistema), con «[Pedido desde el agente personal por <canal>] <detalle>» y las últimas 6
 *    vueltas, y se sincroniza al admin (Http::fake: nada sale a la red);
 *  - si el admin no contesta, el mensaje queda guardado y marcado para que support:retry-pending-syncs lo
 *    reintente, y la respuesta lo dice;
 *  - sin tarjeta, tope de 3 por persona y por día, sin detalle no manda nada;
 *  - está en el sistema y en WhatsApp y NO en el MCP.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Pedir_ayuda_a_soporte_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** El detalle que redactaría el modelo en la CONV 24. */
    const DETALLE = 'Pablo quiere mandar promos por WhatsApp a sus clientes y el sistema dice que el negocio no tiene el WhatsApp con clientes conectado. Él cree que ya lo cargó: necesita que lo revisen.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();
    }

    /**
     * El admin que contesta el inbound de soporte con ese status. Va en cada test y no en el setUp: el
     * primer Http::fake que matchea gana, así que uno del setUp taparía al de "el admin está caído".
     *
     * @param  int  $status
     * @return void
     */
    protected function admin_que_contesta($status = 200)
    {
        Http::fake(['*' => Http::response(['ok' => $status === 200], $status)]);
    }

    /**
     * Las últimas vueltas de la CONV 24, ya contestadas, y la vuelta del pedido.
     *
     * @return array{0: \App\Models\AiConversation, 1: AiMessage}
     */
    protected function conversacion_de_la_promo()
    {
        list($conversation, $assistant) = $this->vuelta('Mandalo');
        $assistant->update(['estado' => 'listo', 'contenido' => 'No se pudo mandar: el negocio no tiene WhatsApp conectado.']);

        list($conversation, $assistant) = $this->vuelta('Si lo tiene ya lo cargue en el sistema', $conversation);
        $assistant->update(['estado' => 'listo', 'contenido' => 'Puede que esté cargado, pero el sistema me respondió que no hay WhatsApp conectado.']);

        return $this->vuelta('Le pedis a lucas que vea este tema', $conversation);
    }

    /**
     * Corre la herramienta por el despacho real de las cargas.
     *
     * @param  \App\Models\AiConversation  $conversation
     * @param  AiMessage  $assistant
     * @param  string  $detalle
     * @return array
     */
    protected function pedir($conversation, $assistant, $detalle = self::DETALLE)
    {
        return $this->herramienta($conversation, $assistant, 'pedir_ayuda_a_soporte', ['detalle' => $detalle]);
    }

    /**
     * 🔴 CONV 24: «le pedís a Lucas que vea este tema» deja el pedido en soporte, con el contexto, y llega al admin.
     *
     * @test
     */
    public function le_pedis_a_lucas_que_vea_este_tema_deja_el_pedido_en_soporte()
    {
        $this->admin_que_contesta();

        list($conversation, $assistant) = $this->conversacion_de_la_promo();

        $respuesta = $this->pedir($conversation, $assistant);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertTrue($respuesta['llego_a_soporte']);
        $this->assertArrayNotHasKey('tarjeta_id', $respuesta, 'Sin tarjeta: lo pidió la persona.');
        $this->assertStringContainsString('chat de soporte', $respuesta['nota']);

        $ticket = SupportTicket::find($respuesta['ticket']['id']);

        $this->assertSame($this->dueno->id, (int) $ticket->user_id, 'El ticket es de la persona que habla con el agente.');
        $this->assertSame('open', $ticket->status);

        $mensaje = SupportMessage::where('support_ticket_id', $ticket->id)->orderBy('id', 'DESC')->first();

        $this->assertSame('user', $mensaje->sender_type);
        $this->assertSame($this->dueno->id, (int) $mensaje->sender_user_id);
        $this->assertSame('text', $mensaje->kind);
        $this->assertStringStartsWith('[Pedido desde el agente personal por WhatsApp] ' . self::DETALLE, $mensaje->body);
        $this->assertStringContainsString("Últimas vueltas de la conversación con el agente:\n", $mensaje->body);
        $this->assertStringContainsString('ZZE Ferreteria Envios', $mensaje->body, 'El nombre de la persona en sus mensajes.');
        $this->assertStringContainsString('Si lo tiene ya lo cargue en el sistema', $mensaje->body);
        $this->assertStringContainsString('Agente', $mensaje->body);
        $this->assertStringContainsString('Le pedis a lucas que vea este tema', $mensaje->body, 'El pedido de la persona va, aunque todavía no tenga respuesta.');
        $this->assertNotNull($mensaje->synced_to_admin_at);

        // Llegó al admin por el mismo endpoint que el chat de soporte, con el cuerpo y su uuid.
        Http::assertSent(function ($request) use ($mensaje) {
            return strpos($request->url(), '/api/inbound/support/messages') !== false
                && $request['message_uuid'] === $mensaje->uuid
                && $request['body'] === $mensaje->body
                && $request['sender_type'] === 'user';
        });
    }

    /**
     * Va al ticket ABIERTO que la persona ya tenía (el mismo hilo que ve en soporte), no a uno nuevo.
     *
     * @test
     */
    public function el_pedido_va_al_ticket_abierto_que_ya_tenia()
    {
        $this->admin_que_contesta();

        $abierto = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open']);

        list($conversation, $assistant) = $this->conversacion_de_la_promo();

        $respuesta = $this->pedir($conversation, $assistant);

        $this->assertSame($abierto->id, $respuesta['ticket']['id']);
        $this->assertSame(1, SupportTicket::where('user_id', $this->dueno->id)->count());
    }

    /**
     * Si el admin no contesta, el pedido queda guardado y marcado para el reintento, y la respuesta lo dice.
     *
     * @test
     */
    public function si_el_admin_no_contesta_el_pedido_queda_guardado_para_el_reintento()
    {
        $this->admin_que_contesta(500);

        list($conversation, $assistant) = $this->conversacion_de_la_promo();

        $respuesta = $this->pedir($conversation, $assistant);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertFalse($respuesta['llego_a_soporte']);
        $this->assertStringContainsString('se reenvía solo', $respuesta['nota']);

        $mensaje = SupportMessage::where('support_ticket_id', $respuesta['ticket']['id'])->orderBy('id', 'DESC')->first();

        $this->assertNull($mensaje->synced_to_admin_at, 'Así lo levanta support:retry-pending-syncs.');
        $this->assertSame('not_received', $mensaje->remote_delivery_status);
    }

    /**
     * Tope de 3 por persona y por día; los de ayer no cuentan.
     *
     * @test
     */
    public function hay_un_tope_de_tres_pedidos_por_dia()
    {
        $this->admin_que_contesta();

        list($conversation, $assistant) = $this->conversacion_de_la_promo();

        // Uno de ayer no cuenta para el tope de hoy.
        $ticket = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open']);
        $de_ayer = SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'sender_type'       => 'user',
            'sender_user_id'    => $this->dueno->id,
            'kind'              => 'text',
            'body'              => '[Pedido desde el agente personal por WhatsApp] uno de ayer',
        ]);
        SupportMessage::where('id', $de_ayer->id)->update(['created_at' => Carbon::yesterday()->setTime(12, 0)]);

        for ($i = 1; $i <= PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA; $i++) {

            $respuesta = $this->pedir($conversation, $assistant, 'Pedido número ' . $i);

            $this->assertTrue($respuesta['ok'], json_encode($respuesta));
            $this->assertSame($i, $respuesta['pedidos_de_hoy']);
        }

        $cuarto = $this->pedir($conversation, $assistant, 'Pedido número 4');

        $this->assertFalse($cuarto['ok']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::CODIGO_TOPE, $cuarto['codigo']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA, PedidoDeAyudaASoporteIaHelper::pedidos_de_hoy($this->dueno->id));
        $this->assertSame(0, SupportMessage::where('body', 'like', '%Pedido número 4%')->count(), 'El cuarto no se mandó.');
    }

    /**
     * Sin detalle no manda nada y le pide al MODELO que lo redacte.
     *
     * @test
     */
    public function sin_detalle_no_manda_nada()
    {
        $this->admin_que_contesta();

        list($conversation, $assistant) = $this->conversacion_de_la_promo();

        $respuesta = $this->pedir($conversation, $assistant, '   ');

        $this->assertFalse($respuesta['ok']);
        $this->assertSame(PedidoDeAyudaASoporteIaHelper::CODIGO_SIN_DETALLE, $respuesta['codigo']);
        $this->assertStringContainsString('Escribilo VOS', $respuesta['error']);
        $this->assertSame(0, SupportMessage::whereIn('support_ticket_id', SupportTicket::where('user_id', $this->dueno->id)->select('id'))->count());
        Http::assertNothingSent();
    }

    /**
     * Viajan el pedido y las 6 vueltas anteriores (13 mensajes), de la más vieja a la más nueva.
     *
     * @test
     */
    public function viajan_solo_las_ultimas_seis_vueltas()
    {
        $this->admin_que_contesta();

        $conversation = null;

        for ($i = 1; $i <= 8; $i++) {
            list($conversation, $assistant) = $this->vuelta('pregunta ' . $i . ' del dueño', $conversation);
            $assistant->update(['estado' => 'listo', 'contenido' => 'respuesta ' . $i . ' del agente']);
        }

        list($conversation, $assistant) = $this->vuelta('pasale esto a soporte', $conversation, AiMessage::CANAL_SISTEMA);

        $respuesta = $this->pedir($conversation, $assistant);

        $cuerpo = SupportMessage::where('support_ticket_id', $respuesta['ticket']['id'])->orderBy('id', 'DESC')->value('body');

        $this->assertStringStartsWith('[Pedido desde el agente personal por el chat del sistema]', $cuerpo);
        $this->assertStringNotContainsString('pregunta 2 del dueño', $cuerpo);
        $this->assertStringNotContainsString('respuesta 2 del agente', $cuerpo);
        $this->assertStringContainsString('pregunta 3 del dueño', $cuerpo);
        $this->assertStringContainsString('respuesta 8 del agente', $cuerpo);
        $this->assertStringContainsString('pasale esto a soporte', $cuerpo);
        $this->assertLessThan(strpos($cuerpo, 'respuesta 8 del agente'), strpos($cuerpo, 'pregunta 3 del dueño'), 'De la más vieja a la más nueva.');
    }

    /**
     * 🔴 Está en el sistema y en WhatsApp, y NO en el MCP: un agente externo no le escribe a soporte en
     * nombre del dueño. La descripción dice cuándo usarla.
     *
     * @test
     */
    public function esta_en_el_sistema_y_en_whatsapp_pero_no_en_el_mcp()
    {
        $servicio = new AsistenteIaService();

        $this->assertContains('pedir_ayuda_a_soporte', array_column($servicio->build_tools(true, false, $this->dueno), 'name'));
        $this->assertContains('pedir_ayuda_a_soporte', array_column($servicio->build_tools(true, true, $this->dueno), 'name'));
        $this->assertNotContains('pedir_ayuda_a_soporte', array_column($servicio->build_tools(false, false), 'name'), 'Sin acciones no escribe nada.');

        $this->assertContains('pedir_ayuda_a_soporte', McpHerramientasHelper::EXCLUIDAS);
        $this->assertFalse(McpHerramientasHelper::existe('pedir_ayuda_a_soporte'));

        // Al final de las de carga comunes (el prefijo del caché): después de los envíos a clientes.
        $nombres = HerramientasDeCarga::nombres();
        $this->assertSame('pedir_ayuda_a_soporte', $nombres[count($nombres) - 1]);

        $descripcion = '';
        foreach (HerramientasDeCarga::definiciones() as $definicion) {
            if ($definicion['name'] === 'pedir_ayuda_a_soporte') {
                $descripcion = $definicion['description'];
                $this->assertSame(['detalle'], $definicion['input_schema']['required']);
            }
        }

        $this->assertStringContainsString('Lucas', $descripcion);
        $this->assertStringContainsString('Nunca digas que no podés', $descripcion);
        $this->assertStringContainsString('pide o acepta', $descripcion);
    }
}
