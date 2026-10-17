<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Jobs\SendPromocionWhatsappJob;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Budget;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes — segundo chequeo independiente (16/10/2026): «lo que sale es lo
 * que el dueño vio, y su sí contesta a ESO».
 *
 * Lo que protege este archivo:
 *  - B1: el sí queda atado al bloque recién leído. No manda una tarjeta vieja que una corrección no
 *    pisó, ni una de ayer, ni con un "sí" a otra pregunta, ni con dos envíos esperando, ni con un "sí"
 *    seguido de un "esperá", ni si el mensaje que leyó el dueño no traía el bloque;
 *  - I2: si cambió cómo sale el comprobante (la ventana se abrió o se cerró), no se manda; la venta
 *    muestra el PDF de cada canal;
 *  - I3: las reservas de la promo son solo lo encolado y todavía pendiente (el cruce de dos promos);
 *  - I5: el control del "combo" no aplica a un artículo que se llama así;
 *  - M1 y M3: el bloque entra en un WhatsApp, con el formato "Motivo (N): nombres".
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_a_clientes_lo_visto_es_lo_que_sale_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio(['agente_confianza' => ConfianzaDelAgenteIaHelper::DIRECTO]);

        Http::fake(['*' => Http::response([], 200)]);
        Mail::fake();
    }

    protected function promociones_enviadas()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->get();
    }

    protected function proponer_promo($conversation, $assistant, $texto = '20% en todo el local', array $extra = [])
    {
        $respuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', array_merge(['texto' => $texto], $extra));

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        return $respuesta;
    }

    // ---------------------------------------------------------------------
    // B1 · El sí atado a lo que el dueño vio
    // ---------------------------------------------------------------------

    /**
     * Una corrección arma otra propuesta y la vieja queda reemplazada: el "sí" no manda la vieja (ni
     * aunque el modelo la nombre), sí la nueva.
     *
     * @test
     */
    public function el_si_despues_de_una_correccion_no_manda_la_tarjeta_vieja()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $primera_vuelta) = $this->vuelta('mandales una promo del 20%');

        $vieja = $this->proponer_promo($conversation, $primera_vuelta, '20% en todo el local');

        $this->cerrar_la_vuelta($primera_vuelta);

        list($conversation, $segunda_vuelta) = $this->vuelta('cambiá el texto: que sea 30%', $conversation);

        $nueva = $this->proponer_promo($conversation, $segunda_vuelta, '30% en todo el local');

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($vieja['tarjeta_id'])->estado_guardado());
        $this->assertContains($vieja['tarjeta_id'], $nueva['reemplazo']);

        $this->cerrar_la_vuelta($segunda_vuelta);

        list($conversation, $tercera_vuelta) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $tercera_vuelta, 'confirmar_carga_pendiente', ['tarjeta_id' => $vieja['tarjeta_id']]);

        $this->assertTrue(empty($intento['ok']));
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertCount(0, $this->promociones_enviadas());

        $confirmada = $this->herramienta($conversation, $tercera_vuelta, 'confirmar_carga_pendiente', ['tarjeta_id' => $nueva['tarjeta_id']]);

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));
        $this->assertStringContainsString('30% en todo el local', $this->promociones_enviadas()->first()->body);
    }

    /**
     * Una tarjeta de ayer, y un "sí" que contesta otra pregunta: ninguno manda.
     *
     * @test
     */
    public function una_tarjeta_vieja_o_un_si_a_otra_pregunta_no_mandan()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        // De ayer.
        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->proponer_promo($conversation, $assistant);

        AiMessageAction::where('id', $propuesta['tarjeta_id'])->update(['created_at' => now()->subDay()]);

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);

        // Un "sí" a otra pregunta del asistente.
        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->proponer_promo($conversation, $assistant);

        $this->cerrar_la_vuelta($assistant);

        list($conversation, $otra) = $this->vuelta('¿cuánto vendí hoy?', $conversation);

        $otra->estado = 'listo';
        $otra->contenido = 'Vendiste $ 120.000. ¿Querés el detalle por artículo?';
        $otra->save();

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Dos envíos esperando en el mismo mensaje (si el reemplazo no hubiera corrido) y un solo "sí": no
     * se manda ninguno.
     *
     * @test
     */
    public function dos_envios_esperando_y_un_solo_si_no_mandan_ninguno()
    {
        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->venta_impaga($ana, 40, 300000);

        list($conversation, $assistant) = $this->vuelta('mandales una promo y el recordatorio');

        $promo = $this->proponer_promo($conversation, $assistant);
        $recordatorio = $this->herramienta($conversation, $assistant, 'proponer_recordatorios_de_cobro', []);

        // El reemplazo dejó una sola; se fuerza la otra a seguir esperando para probar el cinturón.
        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($promo['tarjeta_id'])->estado_guardado());

        AiMessageAction::where('id', $promo['tarjeta_id'])->update(['estado' => AiMessageAction::ESTADO_PROPUESTA, 'resuelta_at' => null]);

        $this->cerrar_la_vuelta($assistant);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        foreach ([$promo['tarjeta_id'], $recordatorio['tarjeta_id']] as $tarjeta_id) {

            $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

            $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_VARIOS_ENVIOS, $intento['error']);
        }

        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Un "sí" seguido de un "esperá", y un mensaje que salió sin el bloque: no manda.
     *
     * @test
     */
    public function un_si_seguido_de_espera_o_sin_el_bloque_a_la_vista_no_manda()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->proponer_promo($conversation, $assistant);

        $this->cerrar_la_vuelta($assistant);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        // El dueño escribe otra cosa mientras el turno del "sí" todavía corre.
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'esperá', 'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);

        // Sin el bloque en lo que leyó el dueño (salió el texto de respaldo, por ejemplo).
        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->proponer_promo($conversation, $assistant);

        $this->cerrar_la_vuelta($assistant, false);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    // ---------------------------------------------------------------------
    // I2 · Lo que sale es lo que se mostró
    // ---------------------------------------------------------------------

    /**
     * El presupuesto se mostró como plantilla (sin chat); antes del sí el cliente escribió y ahora
     * saldría como mensaje: no se manda.
     *
     * @test
     */
    public function si_cambio_como_sale_el_comprobante_no_se_manda()
    {
        $marcos = $this->cliente('Marcos Perez', '5493444001122', null);

        Budget::create(['user_id' => $this->dueno->id, 'client_id' => $marcos->id, 'num' => 900004, 'total' => 5000, 'moneda_id' => 1]);

        list($conversation, $assistant) = $this->vuelta('mandale el presupuesto 900004');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'presupuesto', 'numero' => 900004]);

        $this->assertSame(['whatsapp' => 'plantilla'], AiMessageAction::find($propuesta['tarjeta_id'])->datos['caminos']);

        $this->chat_abierto($marcos);

        $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertTrue(empty($intento['ok']));
        $this->assertStringContainsString('cambió cómo sale por WhatsApp', $intento['error']);
        $this->assertSame(0, WhatsappChatMessage::where('direction', 'out')->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))->count());
    }

    /**
     * La venta por WhatsApp y por mail: cada canal muestra el PDF que de verdad lleva.
     *
     * @test
     */
    public function la_venta_muestra_el_pdf_de_cada_canal()
    {
        $ana = $this->cliente('Ana Gomez', '5493444002233', 'ana@test.local');
        $venta = $this->venta_impaga($ana, 0, 12500, 4890);

        list($conversation, $assistant) = $this->vuelta('mandale la venta 4890');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_envio_de_comprobante', ['tipo' => 'venta', 'numero' => 4890]);

        $etiquetas = array_column(AiMessageAction::find($propuesta['tarjeta_id'])->presentacion['renglones'], 'valor', 'etiqueta');

        $this->assertStringContainsString('/sale/pdf/' . $venta->id . '?t=', $etiquetas['PDF por WhatsApp']);
        $this->assertStringNotContainsString('pdf_column_profile_id', $etiquetas['PDF por WhatsApp']);
        $this->assertStringContainsString('pdf_column_profile_id=', $etiquetas['PDF por mail']);
        $this->assertStringContainsString('PDF por WhatsApp: ', $this->bloque($propuesta));
        $this->assertStringContainsString('PDF por mail: ', $this->bloque($propuesta));
    }

    // ---------------------------------------------------------------------
    // I3 · Las reservas son lo encolado y pendiente
    // ---------------------------------------------------------------------

    /**
     * El cruce: A y B propuestas antes de confirmar, comparten a X. Se confirma A (sin que corran los
     * jobs), después B: B saltea a X. Después corre el job de A para X: X recibe A.
     *
     * @test
     */
    public function dos_promos_cruzadas_no_se_bloquean_entre_si()
    {
        Queue::fake();

        $x = $this->cliente('Xavier Gomez', '5493444101010');
        $z = $this->cliente('Zoe Diaz', '5493444202020');

        list($conversacion_a, $vuelta_a) = $this->vuelta('promo A a Xavier');
        $a = $this->proponer_promo($conversacion_a, $vuelta_a, 'Promo A', ['client_ids' => [$x->id]]);

        list($conversacion_b, $vuelta_b) = $this->vuelta('promo B a Xavier y Zoe');
        $b = $this->proponer_promo($conversacion_b, $vuelta_b, 'Promo B', ['client_ids' => [$x->id, $z->id]]);

        $this->assertSame('confirmada', $this->confirmar_en_la_vuelta_siguiente($conversacion_a, $vuelta_a, $a['tarjeta_id'], 'sí')['estado']);

        $confirmada_b = $this->confirmar_en_la_vuelta_siguiente($conversacion_b, $vuelta_b, $b['tarjeta_id'], 'sí');

        $this->assertSame('confirmada', $confirmada_b['estado'], json_encode($confirmada_b));
        $this->assertSame(1, $confirmada_b['detalle']['encolados'], 'B saltea a X, que está reservado por A.');
        $this->assertSame([(int) $z->id], AiMessageAction::find($b['tarjeta_id'])->datos['client_ids_encolados']);

        // Corre el job de A para X: B no lo tiene reservado, y A no se bloquea a sí misma.
        (new SendPromocionWhatsappJob($this->dueno->id, $x->id, 'Promo A', AiMessageAction::find($a['tarjeta_id'])->datos['link'], '', $this->dueno->id, $a['tarjeta_id']))->handle();

        $de_x = WhatsappChatMessage::where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('client_id', $x->id)->select('id'))
            ->get();

        $this->assertCount(1, $de_x);
        $this->assertStringContainsString('Promo A', $de_x->first()->body);

        // Ya salió: X deja de ser una reserva (cuenta su fila).
        $this->assertSame([(int) $z->id], PropuestaPromocionWhatsappIaHelper::reservas($this->dueno->id));
    }

    // ---------------------------------------------------------------------
    // I5, M1, M3
    // ---------------------------------------------------------------------

    /**
     * Un ARTÍCULO que se llama "Combo desayuno": la promo no se corta por la palabra combo.
     *
     * @test
     */
    public function un_articulo_que_se_llama_combo_no_corta_la_promo()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        $articulo = Article::create([
            'name' => 'ZZE Combo desayuno', 'slug' => 'zze-combo-desayuno', 'online' => 1, 'user_id' => $this->dueno->id,
            'status' => 'active', 'final_price' => 3000, 'cost' => 1500, 'stock' => 10, 'iva_id' => 2,
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo del combo desayuno');

        $respuesta = $this->proponer_promo($conversation, $assistant, 'Combo desayuno a $ 3.000', ['article_id' => $articulo->id]);

        $this->assertStringContainsString('/articulos/zze-combo-desayuno/', AiMessageAction::find($respuesta['tarjeta_id'])->datos['link']);
    }

    /**
     * M1 y M3: con muchos destinatarios el bloque entra (se acortan las listas) y los salteados van
     * como "Motivo (N): nombres". Con un texto que no entra ni así, no se arma.
     *
     * @test
     */
    public function el_bloque_entra_en_un_whatsapp_y_los_salteados_van_con_su_cantidad()
    {
        $nombres = [];

        for ($i = 1; $i <= 300; $i++) {
            $nombres[] = 'Cliente con un nombre bastante largo número ' . $i;
        }

        $salteados = [];

        for ($i = 1; $i <= 40; $i++) {
            $salteados[] = ['nombre' => 'Sin Teléfono ' . $i, 'motivo' => 'sin_telefono', 'mensaje' => 'No tiene teléfono cargado.'];
        }

        $bloque = DestinatariosIaHelper::bloque_que_entra('Promoción por WhatsApp', $nombres, ['Cómo sale: …'], $salteados, 'El texto que sale', str_repeat('Texto de la promo. ', 80));

        $this->assertNotNull($bloque);
        $this->assertLessThanOrEqual(DestinatariosIaHelper::LARGO_MAXIMO_DEL_BLOQUE, mb_strlen($bloque));
        $this->assertStringContainsString('A quiénes (300): ', $bloque);
        $this->assertStringContainsString('No tiene teléfono cargado (40): Sin Teléfono 1', $bloque);

        $this->assertNull(DestinatariosIaHelper::bloque_que_entra('Promoción por WhatsApp', $nombres, [], $salteados, 'El texto que sale', str_repeat('Texto larguísimo. ', 200)));
    }

    /**
     * M4: al modelo le llega la cantidad y una línea, no el bloque ni sus partes.
     *
     * @test
     */
    public function al_modelo_le_llega_la_cantidad_y_una_linea()
    {
        $this->cliente('Ana Gomez', '5493444101010');
        $this->cliente('Bruno Diaz', '5493444202020');

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $respuesta = $this->proponer_promo($conversation, $assistant);

        $this->assertArrayNotHasKey('para_mostrar', $respuesta);
        $this->assertSame(2, $respuesta['cantidad']);
        $this->assertSame('Promoción por WhatsApp a 2 clientes.', $respuesta['linea']);
        $this->assertStringNotContainsString('Hola Ana Gomez', json_encode($respuesta, JSON_UNESCAPED_UNICODE));
    }
}
