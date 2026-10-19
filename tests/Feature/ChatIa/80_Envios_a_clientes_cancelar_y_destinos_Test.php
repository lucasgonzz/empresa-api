<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\Article;
use App\Models\Budget;
use App\Models\Client;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes — revisión de merge (16/10/2026).
 *
 * Lo que protege este archivo:
 *  - M1: cancelar un envío por texto anda con un "no, dejalo" (cancelar no exige el sí), y después un
 *    "sí" no manda nada;
 *  - B4: un mensaje con foto no vale como sí a un envío;
 *  - B1/B2: lo que sale va a donde se mostró: si cambió el link de la promo, o el teléfono o el mail
 *    del cliente del comprobante, no se manda.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_a_clientes_cancelar_y_destinos_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();
    }

    protected function salientes()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->count();
    }

    /**
     * M1: "no, dejalo" cancela la promo, y un "sí" después no manda nada.
     *
     * @test
     */
    public function no_dejalo_cancela_y_el_si_de_despues_no_manda()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->cerrar_la_vuelta($assistant);

        list($conversation, $siguiente) = $this->vuelta('no, dejalo', $conversation);

        $cancelada = $this->herramienta($conversation, $siguiente, 'cancelar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertTrue(!empty($cancelada['ok']), json_encode($cancelada));
        $this->assertSame(AiMessageAction::ESTADO_CANCELADA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());

        $siguiente->estado = 'listo';
        $siguiente->contenido = 'Listo, no la mando.';
        $siguiente->save();

        list($conversation, $otra) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $otra, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertTrue(empty($intento['ok']), json_encode($intento));
        $this->assertSame(0, $this->salientes());
    }

    /**
     * B4: un "sí" que viene con una foto no confirma un envío.
     *
     * @test
     */
    public function un_si_con_foto_no_manda()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->cerrar_la_vuelta($assistant);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $pedido = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->orderBy('id', 'DESC')->first();

        AiMessageImagen::create([
            'ai_message_id' => $pedido->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => 'asistente_imagenes/' . $this->dueno->id . '/' . $pedido->id . '/1.webp',
            'mime'          => 'image/webp',
            'bytes'         => 10,
        ]);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_SIN_SI, $intento['error']);
        $this->assertSame(0, $this->salientes());
    }

    /**
     * B1: el link de la promo cambió entre la tarjeta y el sí (otro slug del artículo): no se manda.
     *
     * @test
     */
    public function si_cambio_el_link_de_la_promo_no_se_manda()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        $articulo = Article::create([
            'name' => 'ZZE Taladro', 'slug' => 'zze-taladro', 'online' => 1, 'user_id' => $this->dueno->id,
            'status' => 'active', 'final_price' => 10000, 'cost' => 5000, 'stock' => 10, 'iva_id' => 2,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo del taladro');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => 'Taladro a $ 9.000', 'article_id' => $articulo->id]);

        Article::where('id', $articulo->id)->update(['slug' => 'zze-taladro-nuevo']);

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertTrue(empty($intento['ok']));
        $this->assertStringContainsString('cambió el link de la promo', $intento['error']);
        $this->assertSame(0, WhatsappChatMessage::where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))->count());
    }

    /**
     * B2: el teléfono o el mail del cliente cambiaron entre la tarjeta y el sí: el comprobante no sale.
     *
     * @test
     */
    public function si_cambio_el_telefono_o_el_mail_del_cliente_no_se_manda()
    {
        $marcos = $this->cliente('Marcos Perez', '5493444001122', 'marcos@test.local');

        Budget::create(['user_id' => $this->dueno->id, 'client_id' => $marcos->id, 'num' => 900004, 'total' => 5000, 'moneda_id' => 1]);

        // El teléfono.
        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004 por WhatsApp');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004, 'canales' => ['whatsapp']]);

        Client::where('id', $marcos->id)->update(['phone' => '5493444999000']);

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertStringContainsString('cambió el teléfono del cliente', $intento['error']);
        $this->assertSame(0, $this->salientes());

        // El mail.
        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004 por mail');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004, 'canales' => ['mail']]);

        Client::where('id', $marcos->id)->update(['email' => 'otro@test.local']);

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertStringContainsString('cambió el mail del cliente', $intento['error']);
        Mail::assertNothingQueued();
    }
}
