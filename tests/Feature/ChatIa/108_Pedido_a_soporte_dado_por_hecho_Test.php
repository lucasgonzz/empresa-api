<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidoDeAyudaASoporteIaHelper;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (A7, 13/11/2026) — el pedido a soporte dado por hecho.
 *
 * 🔴 EL CASO REAL. Truvari, 12/11/2026 por WhatsApp: a las 20:55 el asistente dijo que ya había usado los
 * 3 pedidos a soporte del día; a las 21:52, a «Rápido», contestó «Listo el pedido a soporte para que
 * habiliten la consulta del link de la tienda online por producto. Te contestan por el chat de Ayuda.» sin
 * crear nada. El error del tope le pedía «Decile a la persona que ya está pedido», y el modelo lo usó para
 * un pedido NUEVO que no salió.
 *
 * Lo que fija este archivo:
 *   - el error del tope dice que NO se mandó y que no diga que quedó pedido;
 *   - el texto que afirma un pedido sin un pedir_ayuda_a_soporte ok reintenta una vez y, si sigue, se
 *     cambia esa oración (y lo que promete que «te contestan») por el aviso honesto (el del tope, si fue el
 *     tope);
 *   - con el pedido hecho de verdad, el texto queda y no hay reintento;
 *   - el detector, frase por frase.
 *
 * 🔴 Nada sale a la red: el modelo y el admin son un Http::fake.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types.
 *
 * @group chat-ia
 */
class Pedido_a_soporte_dado_por_hecho_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    const LISTO_EL_PEDIDO = 'Listo el pedido a soporte para que habiliten la consulta del link de la tienda online por producto. Te contestan por el chat de Ayuda.';

    /** @var array<int, array> */
    protected $respuestas_del_modelo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio([
            'agente_confianza'   => ConfianzaDelAgenteIaHelper::RESUELTO,
            'agente_proveedor'   => 'deepseek',
            'agente_pensamiento' => 'agil',
        ]);

        config([
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p108',
            'services.deepseek.base_url'       => 'https://api.deepseek.com/anthropic',
            'services.deepseek.model'          => 'deepseek-general-p108',
            'services.deepseek.model_agil'     => 'deepseek-agil-p108',
            'services.deepseek.model_profundo' => 'deepseek-profundo-p108',
        ]);

        $this->respuestas_del_modelo = [];

        Http::fake(function ($request) {

            if (strpos($request->url(), 'api.deepseek.com') === false) {
                /* El admin del chat de soporte: contesta bien. */
                return Http::response(['ok' => true], 200);
            }

            $siguiente = array_shift($this->respuestas_del_modelo);

            return is_null($siguiente)
                ? Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'el test no preparó esta respuesta']], 400)
                : Http::response($siguiente, 200);
        });
    }

    protected function el_modelo_contesta(...$respuestas)
    {
        foreach ($respuestas as $respuesta) {
            $this->respuestas_del_modelo[] = $respuesta;
        }
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

    protected function pedir_a_soporte($detalle)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p108_' . uniqid(),
                'name'  => 'pedir_ayuda_a_soporte',
                'input' => ['detalle' => $detalle],
            ]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Los pedidos al modelo, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_al_modelo()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $bodies[] = json_decode($par[0]->body(), true);
            }
        }

        return $bodies;
    }

    /**
     * Los tres pedidos del día ya hechos desde el agente.
     *
     * @return void
     */
    protected function tope_del_dia_usado()
    {
        $ticket = SupportTicket::create(['user_id' => $this->dueno->id, 'status' => 'open']);

        for ($i = 1; $i <= PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA; $i++) {
            SupportMessage::create([
                'support_ticket_id' => $ticket->id,
                'sender_type'       => 'user',
                'sender_user_id'    => $this->dueno->id,
                'kind'              => 'text',
                'body'              => PedidoDeAyudaASoporteIaHelper::PREFIJO . ' por WhatsApp] pedido ' . $i,
            ]);
        }
    }

    /**
     * 🔴 21:52: el tope del día ya está usado, la herramienta contesta que NO se mandó, el modelo igual dice
     * «Listo el pedido a soporte». Un reintento con la nota del tope; si insiste, la persona lee el aviso del
     * tope, sin el pedido inventado ni el «te contestan».
     *
     * @test
     */
    public function con_el_tope_usado_el_listo_el_pedido_no_llega_y_va_el_aviso_del_tope()
    {
        $this->tope_del_dia_usado();

        $this->el_modelo_contesta(
            $this->pedir_a_soporte('Que habiliten la consulta del link de la tienda online por producto.'),
            $this->end_turn(self::LISTO_EL_PEDIDO . "\n\nEl stock quedó en 9."),
            $this->end_turn(self::LISTO_EL_PEDIDO . "\n\nEl stock quedó en 9.")
        );

        list($conversation, $assistant) = $this->vuelta('Rápido');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('Listo el pedido', $texto);
        $this->assertStringNotContainsString('Te contestan', $texto);
        $this->assertStringContainsString(PedidoDeAyudaASoporteIaHelper::AVISO_TOPE, $texto);
        $this->assertStringContainsString('El stock quedó en 9.', $texto, 'Lo demás que dijo el modelo queda.');

        $bodies = $this->bodies_al_modelo();

        $this->assertCount(3, $bodies);

        /* El tool_result del tope ya no le pide que diga que está pedido. */
        $resultado = json_encode($bodies[1]['messages'][count($bodies[1]['messages']) - 1], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('NO se mandó este pedido', $resultado);
        $this->assertStringNotContainsString('que ya está pedido', $resultado);

        /* La nota del reintento nombra el tope. */
        $nota = json_encode($bodies[2]['messages'][count($bodies[2]['messages']) - 1], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('NO se creó ningún pedido', $nota);
        $this->assertStringContainsString('máximo de pedidos', $nota);

        $this->assertSame(PedidoDeAyudaASoporteIaHelper::TOPE_POR_DIA, PedidoDeAyudaASoporteIaHelper::pedidos_de_hoy($this->dueno->id), 'No se mandó ninguno de más.');
    }

    /**
     * Sin haber llamado a la herramienta («Ya lo sumé al pedido de soporte»): reintento y, si el reintento
     * llama de verdad a la herramienta, la persona lee lo que contesta el reintento.
     *
     * @test
     */
    public function sin_la_herramienta_reintenta_y_si_el_reintento_la_llama_el_pedido_sale()
    {
        $this->el_modelo_contesta(
            $this->end_turn('Ya lo sumé al pedido: cepa elegida del listado de ABM Vinoteca, solo cuando el artículo es vino.'),
            $this->pedir_a_soporte('Que el asistente pueda elegir la cepa del listado de ABM Vinoteca al dar de alta un vino.'),
            $this->end_turn('Quedó pedido a soporte: que puedas elegir la cepa al dar de alta un vino. Te contestan por el chat de Ayuda.')
        );

        list($conversation, $assistant) = $this->vuelta('Te faltó la cepa cuando es un vino, sumalo al pedido');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Quedó pedido a soporte: que puedas elegir la cepa al dar de alta un vino. Te contestan por el chat de Ayuda.', $texto);
        $this->assertCount(3, $this->bodies_al_modelo());
        $this->assertSame(1, PedidoDeAyudaASoporteIaHelper::pedidos_de_hoy($this->dueno->id));
    }

    /**
     * Si el reintento tampoco lo pide, el aviso sin pedido.
     *
     * @test
     */
    public function si_el_reintento_tampoco_lo_pide_va_el_aviso_sin_pedido()
    {
        $this->el_modelo_contesta(
            $this->end_turn('Lo sumo al pedido de soporte junto con el resto de los campos del alta.'),
            $this->end_turn('Lo sumo al pedido de soporte junto con el resto de los campos del alta.')
        );

        list($conversation, $assistant) = $this->vuelta('Hay otros artículos que no tienen cepa');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(PedidoDeAyudaASoporteIaHelper::AVISO_SIN_PEDIDO, $texto);
        $this->assertSame(0, PedidoDeAyudaASoporteIaHelper::pedidos_de_hoy($this->dueno->id));
    }

    /**
     * Con el pedido hecho de verdad en el turno, el texto queda tal cual y no hay reintento.
     *
     * @test
     */
    public function con_el_pedido_hecho_el_texto_queda_y_no_hay_reintento()
    {
        $this->el_modelo_contesta(
            $this->pedir_a_soporte('Que el asistente pueda cargar un artículo nuevo con nombre, proveedor, costo, margen y bodega.'),
            $this->end_turn('Quedó pedido a soporte: que el asistente pueda cargar un artículo nuevo con la bodega. Te contestan por el chat de Ayuda.')
        );

        list($conversation, $assistant) = $this->vuelta('Sí, por favor, pedile a soporte que pueda cargar artículos como los cargamos');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Quedó pedido a soporte: que el asistente pueda cargar un artículo nuevo con la bodega. Te contestan por el chat de Ayuda.', $texto);
        $this->assertCount(2, $this->bodies_al_modelo());
    }

    /**
     * El detector.
     *
     * @test
     */
    public function el_detector_del_pedido_a_soporte_dado_por_hecho()
    {
        $afirman = [
            self::LISTO_EL_PEDIDO,
            'Quedó pedido a soporte: que el asistente pueda cargar un artículo nuevo.',
            'Ya lo sumé al pedido: cepa elegida del listado.',
            'Lo sumo al pedido de soporte junto con el resto de los campos del alta.',
            'Le pedí a soporte que lo revise.',
        ];

        foreach ($afirman as $texto) {
            $this->assertTrue(PedidoDeAyudaASoporteIaHelper::afirma_un_pedido($texto), 'Tendría que afirmar: ' . $texto);
        }

        $no_afirman = [
            'Si querés, dejo pedido a soporte que también me lo manden a este chat.',
            'Te ofrezco pedirle a soporte que los elimine.',
            '¿Le pido a soporte que borre el 25 y el 26?',
            'No pude sumarlo: ya hay 3 pedidos a soporte enviados hoy desde acá.',
            'Lo anterior quedó pedido a soporte hace un rato.',
            PedidoDeAyudaASoporteIaHelper::AVISO_TOPE,
            PedidoDeAyudaASoporteIaHelper::AVISO_SIN_PEDIDO,
        ];

        foreach ($no_afirman as $texto) {
            $this->assertFalse(PedidoDeAyudaASoporteIaHelper::afirma_un_pedido($texto), 'No tendría que afirmar: ' . $texto);
        }
    }
}
