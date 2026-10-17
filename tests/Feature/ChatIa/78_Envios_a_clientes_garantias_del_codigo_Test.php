<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-envios-a-clientes — correcciones 1, 2 y 3 del chequeo del 16/10/2026.
 *
 * La condición de Lucas es «lista de destinatarios, cantidad y texto exacto ANTES del sí, y siempre con
 * un sí explícito», y tiene que quedar garantizada por el CÓDIGO, no por lo que decida escribir el
 * modelo. Lo que protege este archivo:
 *  - por WhatsApp, aunque el modelo cierre con una frase que no menciona nada, el mensaje final trae el
 *    bloque de cada envío (a quiénes, cuántos, a quiénes no y por qué, el texto exacto y la pregunta),
 *    uno por tarjeta; en el panel, no (ahí está la tarjeta);
 *  - el sí lo verifica el código: «sí», «dale mandalo», «si, mandá» confirman; «gracias», «esperá cambiá
 *    el texto», «no» y «¿y a cuántos?» no, y la tarjeta sigue esperando;
 *  - un envío propuesto por MCP no se confirma por MCP.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_a_clientes_garantias_del_codigo_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio([
            'agente_confianza'   => ConfianzaDelAgenteIaHelper::DIRECTO,
            'agente_proveedor'   => 'anthropic',
            'agente_pensamiento' => 'agil',
        ]);

        $this->dar_extension('asistente_ia');

        // 🔴 Nunca las claves reales: este archivo no sale a la red (todo pasa por Http::fake).
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p78',
            'services.anthropic.model'          => 'claude-general-p78',
            'services.anthropic.model_agil'     => 'claude-agil-p78',
            'services.anthropic.model_profundo' => 'claude-profundo-p78',
        ]);
    }

    protected function end_turn($texto)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    protected function tool_use($herramienta, array $input)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p78_' . uniqid(),
                'name'  => $herramienta,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    protected function clientes_que_deben()
    {
        $ana = $this->cliente('Ana Gomez', '5493444101010');
        $this->venta_impaga($ana, 40, 300000);

        $this->cliente('Bruno Diaz', '5493444202020');
        $this->cliente('Sin Telefono', null);
    }

    /**
     * 🔴 CORRECCIÓN 1: el modelo cierra con una frase que no dice nada, y el mensaje igual trae el bloque
     * (destinatarios, cantidad, texto y la pregunta). Desde el segundo chequeo (B1), un envío nuevo
     * reemplaza al que estaba esperando: con dos en el mismo turno queda el último, y un solo bloque.
     *
     * @test
     */
    public function por_whatsapp_el_mensaje_trae_el_bloque_del_envio_aunque_el_modelo_no_lo_diga()
    {
        $this->clientes_que_deben();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']), 200)
                ->push($this->tool_use('proponer_recordatorios_de_cobro', ['monto_minimo' => 100000]), 200)
                ->push($this->end_turn('Te dejo preparadas las dos cosas.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo y un recordatorio a los que deben');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringStartsWith('Te dejo preparadas las dos cosas.', $texto);

        // El del recordatorio (el último): a quién y el texto real del primero.
        $this->assertStringContainsString("Recordatorio de cobro por WhatsApp\nA quiénes (1): Ana Gomez", $texto);
        $this->assertStringContainsString('El texto que sale (el de Ana Gomez):', $texto);

        // La promo quedó reemplazada: su bloque no va, y la pregunta aparece una sola vez.
        $this->assertStringNotContainsString("Promoción por WhatsApp\nA quiénes", $texto);
        $this->assertSame(1, substr_count($texto, DestinatariosIaHelper::PREGUNTA));

        $tarjetas = AiMessageAction::where('ai_message_id', $assistant->id)->orderBy('id')->get();

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, $tarjetas[0]->estado_guardado());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[1]->estado_guardado());
    }

    /**
     * En el panel del sistema el bloque no va: ahí está la tarjeta con su botón.
     *
     * @test
     */
    public function en_el_panel_no_se_agrega_el_bloque()
    {
        $this->clientes_que_deben();

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']), 200)
                ->push($this->end_turn('Te dejé la tarjeta de la promo.'), 200),
            '*'                   => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('mandales una promo', null, null);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString(DestinatariosIaHelper::PREGUNTA, $texto);
        $this->assertSame(1, AiMessageAction::where('ai_message_id', $assistant->id)->count());
    }

    /**
     * 🔴 CORRECCIÓN 2: qué cuenta como sí explícito para un envío.
     *
     * @test
     */
    public function el_si_para_un_envio_es_explicito()
    {
        // I1 del segundo chequeo: cobertura TOTAL con vocabulario cerrado.
        foreach ([
            'sí', 'Si', 'dale mandalo', 'si, mandá', 'ok', 'Dale!', 'mandale nomás', 'de una', 'listo, enviá',
            'mandáselo', 'Mandaselos', 'de acuerdo', 'sisi', 'sí, mandales a todos', 'ok, enviá porfa',
        ] as $si) {
            $this->assertTrue(ConfirmacionDeterministaIaHelper::es_si_para_envio($si), $si);
        }

        foreach ([
            'gracias', 'esperá cambiá el texto', 'no', '¿y a cuántos?', 'sí, pero sacá a Pérez', 'dale, antes cambiale el precio',
            'no, mejor mañana', '',
            // Las correcciones que empiezan con un sí (las del segundo chequeo).
            'mandale solo a Pérez', 'mandales a los de Rosario nomás', 'sí, sacá a Pérez', 'si, menos a Juan', 'si, sin el link',
            'sí, mañana', 'sí, después', 'dale, mandalo después de las 18', 'si querés mandalo el lunes',
            'si lo tenés listo, avisame', 'listo, ya lo mandé yo', 'perfecto, después lo vemos', 'dale ni en pedo',
            // Decisión: "gracias" cierra la charla, no autoriza un envío; y un emoji solo no es un sí.
            'listo, gracias', '👍',
        ] as $no) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_para_envio($no), $no);
        }

        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_para_envio('sí ' . str_repeat('dale ', 15)), 'Más de 60 caracteres ya es otra cosa.');
    }

    /**
     * 🔴 CORRECCIÓN 2, de punta a punta: con «gracias» o «esperá cambiá el texto» el modelo no puede
     * confirmar el envío aunque llame a confirmar_carga_pendiente; con «sí» sí.
     *
     * @test
     */
    public function sin_un_si_explicito_el_envio_no_sale()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        Http::fake(['*' => Http::response([], 200)]);

        // Una conversación por respuesta: cada "sí" tiene que contestar al último mensaje (B1).
        foreach (['gracias', 'esperá cambiá el texto', 'no', '¿y a cuántos?', 'sí, sacá a Pérez'] as $respuesta_del_dueno) {

            list($conversation, $assistant) = $this->vuelta('mandales una promo');

            $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

            $intento = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], $respuesta_del_dueno);

            $this->assertTrue(empty($intento['ok']), $respuesta_del_dueno);
            $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_SIN_SI, $intento['error'], $respuesta_del_dueno);
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());
        }

        list($conversation, $assistant) = $this->vuelta('mandales una promo');

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $confirmada = $this->confirmar_en_la_vuelta_siguiente($conversation, $assistant, $propuesta['tarjeta_id'], 'sí');

        $this->assertSame('confirmada', $confirmada['estado'], json_encode($confirmada));

        Http::assertNothingSent();
    }

    /**
     * 🔴 CORRECCIÓN 3 (cinturón): un envío propuesto por el canal MCP no se confirma por MCP.
     *
     * @test
     */
    public function un_envio_propuesto_por_mcp_no_se_confirma_por_mcp()
    {
        $this->cliente('Ana Gomez', '5493444101010');

        list($conversation, $assistant) = $this->vuelta('mandales una promo', null, AiMessage::CANAL_MCP);

        $propuesta = $this->herramienta($conversation, $assistant, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($propuesta['ok']), json_encode($propuesta));

        $assistant->estado = 'listo';
        $assistant->save();

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation, AiMessage::CANAL_MCP);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $propuesta['tarjeta_id']]);

        $this->assertTrue(empty($intento['ok']));
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_POR_MCP, $intento['error']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($propuesta['tarjeta_id'])->estado_guardado());
    }
}
