<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\EntradaIninteligibleIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TextoFinalIaHelper;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-diario-20261116 (rama C, 16/11/2026), ítems B2 y 30/10 #14 — el idioma de entrada y de salida.
 *
 * LOS CASOS REALES: un audio transcripto como «吉博好» se tomó como la respuesta a una carga pendiente (Pesca,
 * 14/11); un audio salió en télugu (Ferre Total, 29/10); y una respuesta del agente dijo «$228 milhões» (Ferre
 * Total, 30/10). Lo que protege este archivo:
 *
 *  - un mensaje ininteligible (otra escritura o «[Audio sin transcripción]») no confirma ninguna tarjeta por
 *    texto, y le llega al modelo con la nota de pedir que lo repita; un «sí» normal sigue confirmando;
 *  - una respuesta en portugués o en inglés se reintenta UNA vez en español, también después de herramientas;
 *  - un nombre de producto en otro idioma en una lista no dispara nada.
 *
 * 🔴 Ningún test sale a la red: Http::fake con claves e ids de modelo inventados.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class T174_Entrada_ininteligible_y_salida_en_otro_idioma_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio();

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-c174',
            'services.deepseek.model'          => 'deepseek-general-c174',
            'services.deepseek.model_agil'     => 'deepseek-flash-c174',
            'services.deepseek.model_profundo' => 'deepseek-pro-c174',
            'services.deepseek.model_vision'   => 'deepseek-vision-c174',
        ]);

        $this->dueno->agente_proveedor   = 'deepseek';
        $this->dueno->agente_pensamiento = 'agil';
        $this->dueno->agente_confianza   = 'resuelto';
        $this->dueno->save();
    }

    /**
     * Una tarjeta de alta de cliente propuesta por WhatsApp, ya leída por el dueño.
     *
     * @return array{0: \App\Models\AiConversation, 1: int}
     */
    protected function tarjeta_leida()
    {
        list($conversation, $assistant) = $this->vuelta('dá de alta al cliente Juan C174');

        $r = $this->herramienta($conversation, $assistant, 'proponer_alta', [
            'entidad' => 'client',
            'datos'   => ['name' => 'ZZC174 Juan'],
        ]);

        $this->assertTrue($r['ok'], json_encode($r));

        $assistant->estado = 'listo';
        $assistant->contenido = 'Doy de alta a ZZC174 Juan. ¿Lo registro?';
        $assistant->save();

        return [$conversation, (int) $r['tarjeta_id']];
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return [
            'model'       => 'deepseek-flash-c174',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'text', 'text' => $texto]],
            'usage'       => ['input_tokens' => 10, 'output_tokens' => 5],
        ];
    }

    /**
     * @return array<int, array>
     */
    protected function bodies()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * 🔴 Pesca: «吉博好» no confirma la tarjeta pendiente; un «sí» sí.
     *
     * @test
     */
    public function un_mensaje_ininteligible_no_confirma_y_un_si_si()
    {
        list($conversation, $tarjeta_id) = $this->tarjeta_leida();

        list($conversation, $siguiente) = $this->vuelta('吉博好', $conversation);

        $r = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertFalse($r['ok']);
        $this->assertSame(EntradaIninteligibleIaHelper::MENSAJE_NO_CONFIRMA, $r['error']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado);

        $siguiente->estado = 'listo';
        $siguiente->contenido = 'No te entendí, ¿me lo repetís?';
        $siguiente->save();

        list($conversation, $otra) = $this->vuelta('[Audio sin transcripción]', $conversation);

        $r = $this->herramienta($conversation, $otra, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertFalse($r['ok'], 'Un audio sin transcribir tampoco es un sí.');

        $otra->estado = 'listo';
        $otra->contenido = 'No me llegó el audio.';
        $otra->save();

        list($conversation, $ultima) = $this->vuelta('sí', $conversation);

        $r = $this->herramienta($conversation, $ultima, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertTrue($r['ok'], json_encode($r));
    }

    /**
     * El mensaje ininteligible le llega al modelo con la nota de pedir que lo repita.
     *
     * @test
     */
    public function el_mensaje_ininteligible_viaja_con_la_nota()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()->push($this->end_turn('No te entendí el audio, ¿me lo repetís?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('See, check చేయాలి');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('No te entendí el audio, ¿me lo repetís?', $texto);

        $body = $this->bodies()[0];
        $ultimo = $body['messages'][count($body['messages']) - 1];
        $contenido = is_string($ultimo['content']) ? $ultimo['content'] : json_encode($ultimo['content'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('este mensaje no se entiende', $contenido);
    }

    /**
     * 🔴 «$228 milhões»: la respuesta en portugués se reintenta una vez y queda la de español.
     *
     * @test
     */
    public function la_respuesta_en_portugues_se_reintenta_en_espanol()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->end_turn('Hoje você vendeu $228 milhões.'), 200)
                ->push($this->end_turn('Hoy vendiste $228 millones.'), 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('¿cuánto vendí hoy?');

        $this->assertSame('Hoy vendiste $228 millones.', (new AsistenteIaService())->responder($conversation, $assistant));

        $bodies = $this->bodies();
        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('portugués o en inglés', json_encode($bodies[1]['messages'], JSON_UNESCAPED_UNICODE));

        // Revisión M2: la vuelta del reintento sale sin poder pedir herramientas (no duplica tarjetas).
        $this->assertSame(['type' => 'none'], $bodies[1]['tool_choice'] ?? null);
        $this->assertArrayNotHasKey('tool_choice', $bodies[0]);
    }

    /**
     * 🔴 Revisión M2: si además dispara una guarda de veracidad (el texto afirma una tarjeta que no existe), el
     * reintento del turno es el de esa guarda, no el del idioma.
     *
     * @test
     */
    public function una_guarda_de_veracidad_le_gana_al_reintento_por_idioma()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->end_turn('Dejé la tarjeta para cargar el gasto de alquiler por 400.000, você confirma.'), 200)
                ->push($this->end_turn('No dejé ninguna tarjeta todavía: ¿me confirmás el método de pago?'), 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('El alquiler de 400000 se paga el 15', null, null);

        (new AsistenteIaService())->responder($conversation, $assistant);

        $bodies = $this->bodies();
        $this->assertCount(2, $bodies);

        $segundo = json_encode($bodies[1]['messages'], JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('portugués o en inglés', $segundo, 'El reintento fue el de la tarjeta.');
        $this->assertArrayNotHasKey('tool_choice', $bodies[1], 'El reintento de la tarjeta puede llamar herramientas.');
    }

    /**
     * Una respuesta en inglés también; y si el reintento vuelve igual, queda ese texto (no se descarta).
     *
     * @test
     */
    public function la_respuesta_en_ingles_se_reintenta_una_sola_vez()
    {
        $ingles = 'Your sales today are 70 and the total is 1.202.047, here is the summary.';

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push($this->end_turn($ingles), 200)
                ->push($this->end_turn($ingles), 200)
                ->push($this->end_turn('no debería llamarse'), 200),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('¿cómo vengo hoy?');

        $this->assertSame($ingles, (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(2, $this->bodies(), 'Un solo reintento.');
    }

    /**
     * Un producto con nombre en otro idioma, en una lista, no dispara el reintento.
     *
     * @test
     */
    public function un_producto_en_otro_idioma_en_una_lista_no_reintenta()
    {
        $texto = "Tenés stock de:\n- Pão de queijo congelado: 3 u.\n- Let's Go Naranja 1L: 12 u.";

        Http::fake([
            'api.deepseek.com/*' => Http::sequence()->push($this->end_turn($texto), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->vuelta('¿qué tengo de stock?');

        $this->assertSame($texto, (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(1, $this->bodies());
    }

    /**
     * Los dos detectores, caso por caso.
     *
     * @test
     */
    public function los_detectores()
    {
        $this->assertTrue(EntradaIninteligibleIaHelper::es_ininteligible('吉博好'));
        $this->assertTrue(EntradaIninteligibleIaHelper::es_ininteligible('See, check చేయాలి'));
        $this->assertTrue(EntradaIninteligibleIaHelper::es_ininteligible('[Audio sin transcripción]'));
        $this->assertFalse(EntradaIninteligibleIaHelper::es_ininteligible('sí'));
        $this->assertFalse(EntradaIninteligibleIaHelper::es_ininteligible('Cargame 3 Coca 吉 de 500'));
        $this->assertFalse(EntradaIninteligibleIaHelper::es_ininteligible('👍'));
        $this->assertFalse(EntradaIninteligibleIaHelper::es_ininteligible(''));

        $this->assertTrue(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Vendiste $228 milhões en el mes.'));
        $this->assertTrue(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('A sua informação está pronta.'));
        $this->assertTrue(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('You sold 70 items today and this is the total.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Hoy vendiste $1.202.047 en 88 ventas.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Stock de Let\'s Go Naranja 1L: 12 u.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('El Sabão Ypê quedó a $1.500.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles("Encontré:\n1. I Will Survive (DVD): 3 u."));
        // Revisión B1: nombres entre comillas, marcas con mayúscula y productos largos en inglés, adentro de español.
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Te queda 1 caja del Cabernet "Até o fim" a $12.000.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Las Tenis Não Pare quedaron a $45.000.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('El proveedor es la marca Isso Store.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Te recomiendo el Monitor LG 24" for gaming with HDMI and the stand, a $300.000.'));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles("Encontré esto:\nMonitor LG 24\" for gaming with HDMI and the stand: $300.000"));
        $this->assertFalse(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('El vino «Você e eu» quedó a $9.000.'));
        $this->assertTrue(TextoFinalIaHelper::contiene_prosa_en_portugues_o_ingles('Você vendeu muito hoje, não tem estoque.'));
    }
}
