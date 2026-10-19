<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidoDelSiParaEnvioIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaPromocionWhatsappIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\RecordatorioCobroSenderService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-arreglos-segunda-prueba, N2 (19/10/2026) — «el sí después de un ok».
 *
 * El caso real (segunda prueba del agente personal contra demo3, A8): el dueño pidió los recordatorios
 * de cobro, vio el bloque y contestó «ok». Un «ok» no manda (B1, test 78) y eso queda igual; lo que
 * pasaba es que el modelo pedía el sí en un mensaje nuevo, SIN el bloque, y cada «sí» siguiente rebotaba
 * con MENSAJE_ENVIO_NO_VISTO porque el último mensaje ya no era el que propuso. Tres «sí» y unos cinco
 * minutos. Lo que protege este archivo:
 *  - el «casi sí» («ok», «listo, gracias», «perfecto»…) al bloque recién visto lo contesta el SISTEMA,
 *    sin llamar al modelo, con el bloque otra vez, y ese mensaje queda en `datos.mostrado_en`: el «sí»
 *    siguiente manda (de punta a punta, por el job real, como el registro del 19/10);
 *  - con otra tarjeta esperando, o con una tarjeta de 40 minutos, el «ok» lo sigue decidiendo el modelo;
 *  - el «sí» que llega con el bloque fuera de la vista (hubo una pregunta en el medio) no manda, pero el
 *    sistema le vuelve a pegar el bloque al final del mensaje y le deja una nota al modelo; el «sí»
 *    siguiente manda. Lo decide el código: también si el modelo no intentó confirmar, y sin duplicar el
 *    bloque si el modelo lo copió;
 *  - las garantías de B1 siguen: un «sí» a otra pregunta no manda, un bloque que copió el modelo no
 *    cuenta, nada del dueño después del sí, una tarjeta de más de 30 minutos no, un mensaje registrado
 *    que no está 'listo' o no tiene el bloque no, dos envíos esperando no; por MCP y en el panel nada
 *    cambia.
 *
 * 🔴 Nada sale a la red: el modelo es un Http::fake con las respuestas en una cola que arma cada test, y
 * el WhatsApp va en envío simulado (EnviosAClientesFixtures).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Envios_a_clientes_el_si_despues_de_un_ok_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /**
     * Las respuestas que va a dar el modelo, en orden. Cada pedido a la API de Anthropic se lleva la
     * primera; si no queda ninguna, el fake contesta un 500 (y el test lo ve como un error del turno).
     *
     * @var array<int, array>
     */
    protected $respuestas_del_modelo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio([
            'agente_confianza'   => ConfianzaDelAgenteIaHelper::DIRECTO,
            'agente_proveedor'   => 'anthropic',
            'agente_pensamiento' => 'agil',
        ]);

        // El job del chat corta sin la extensión del asistente.
        $this->dar_extension('asistente_ia');

        // 🔴 Nunca las claves reales: todo pasa por el fake de abajo.
        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p81',
            'services.anthropic.base_url'       => 'https://api.anthropic.com',
            'services.anthropic.model'          => 'claude-general-p81',
            'services.anthropic.model_agil'     => 'claude-agil-p81',
            'services.anthropic.model_profundo' => 'claude-profundo-p81',
        ]);

        $this->respuestas_del_modelo = [];

        Http::fake(function ($request) {

            if (strpos($request->url(), 'api.anthropic.com') === false) {
                return Http::response([], 200);
            }

            $siguiente = array_shift($this->respuestas_del_modelo);

            return is_null($siguiente)
                ? Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'el test no preparó esta respuesta']], 400)
                : Http::response($siguiente, 200);
        });
    }

    // ---------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------

    /**
     * Encola respuestas del modelo para los turnos que siguen.
     *
     * @param  array  ...$respuestas
     * @return void
     */
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

    protected function tool_use($herramienta, array $input)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => 'tool_use',
            'content'     => [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p81_' . uniqid(),
                'name'  => $herramienta,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Un turno entero por el camino real: el mensaje del dueño, el assistant 'pendiente' y el job del
     * chat, que llama a AsistenteIaService::responder() y guarda el texto como contenido del mensaje.
     *
     * @param  AiConversation|null  $conversation
     * @param  string  $pedido
     * @param  string|null  $canal
     * @return array{0: AiConversation, 1: AiMessage}  El assistant ya guardado.
     */
    protected function turno($conversation, $pedido, $canal = AiMessage::CANAL_WHATSAPP)
    {
        list($conversation, $assistant) = $this->vuelta($pedido, $conversation, $canal);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, 'El turno terminó en error: ' . $assistant->contenido . ' / ' . $assistant->error_mensaje);

        return [$conversation, $assistant];
    }

    /**
     * Cuántos pedidos le llegaron al modelo (a la API de Anthropic) en todo el test.
     *
     * @return int
     */
    protected function pedidos_al_modelo()
    {
        return Http::recorded(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        })->count();
    }

    /**
     * Los tool_result que vio el modelo (ya decodificados), de todos los pedidos.
     *
     * @return array<int, array>
     */
    protected function resultados_que_vio_el_modelo()
    {
        $resultados = [];

        foreach (Http::recorded() as $par) {

            $data = $par[0]->data();

            foreach (isset($data['messages']) ? $data['messages'] : [] as $mensaje) {

                if (!isset($mensaje['content']) || !is_array($mensaje['content'])) {
                    continue;
                }

                foreach ($mensaje['content'] as $bloque) {
                    if (is_array($bloque) && isset($bloque['type']) && $bloque['type'] === 'tool_result') {
                        $resultados[] = json_decode((string) $bloque['content'], true);
                    }
                }
            }
        }

        return $resultados;
    }

    /**
     * Una promo propuesta por WhatsApp y ya leída por el dueño: el mensaje que la propuso queda 'listo'
     * con el bloque adentro, como lo deja AsistenteIaService.
     *
     * @param  string|null  $canal
     * @return array{0: AiConversation, 1: AiMessage, 2: int}  La conversación, el mensaje que propuso y el tarjeta_id.
     */
    protected function promo_a_la_vista($canal = AiMessage::CANAL_WHATSAPP)
    {
        $this->cliente('Ana Gomez ' . uniqid(), '5493444' . rand(100000, 999999));

        list($conversation, $propuso) = $this->vuelta('mandales una promo del 20%', null, $canal);

        $respuesta = $this->herramienta($conversation, $propuso, 'proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']);

        $this->assertTrue(!empty($respuesta['ok']), json_encode($respuesta));

        // En el panel el bloque no va en el texto (está la tarjeta con su botón).
        $this->cerrar_la_vuelta($propuso, $canal !== null);

        return [$conversation, $propuso, (int) $respuesta['tarjeta_id']];
    }

    /**
     * Un mensaje del asistente ya leído por el dueño (la respuesta a otra cosa), después de su pedido.
     *
     * @param  AiConversation  $conversation
     * @param  string  $pedido
     * @param  string  $respuesta
     * @param  string|null  $canal
     * @return AiMessage
     */
    protected function otra_charla($conversation, $pedido, $respuesta, $canal = AiMessage::CANAL_WHATSAPP)
    {
        list($conversation, $assistant) = $this->vuelta($pedido, $conversation, $canal);

        $assistant->estado = 'listo';
        $assistant->contenido = $respuesta;
        $assistant->save();

        return $assistant;
    }

    protected function promociones_enviadas()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', PropuestaPromocionWhatsappIaHelper::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->get();
    }

    protected function recordatorios_enviados()
    {
        return WhatsappChatMessage::where('direction', 'out')
            ->where('source', RecordatorioCobroSenderService::SOURCE)
            ->whereIn('whatsapp_chat_id', WhatsappChat::where('user_id', $this->dueno->id)->select('id'))
            ->get();
    }

    protected function mostrado_en($tarjeta_id)
    {
        return PedidoDelSiParaEnvioIaHelper::mostrado_en(AiMessageAction::find($tarjeta_id));
    }

    protected function bloque_de($tarjeta_id)
    {
        return PedidoDelSiParaEnvioIaHelper::bloque(AiMessageAction::find($tarjeta_id));
    }

    // ---------------------------------------------------------------------
    // El «casi sí» lo contesta el sistema
    // ---------------------------------------------------------------------

    /**
     * 🔴 EL REGISTRO DEL 19/10, DE PUNTA A PUNTA Y POR EL JOB REAL: los recordatorios propuestos →
     * «ok» → la respuesta fija con el bloque, SIN pedido al modelo y registrada en `mostrado_en` →
     * «sí, mandalo» → el modelo confirma y el recordatorio se encola y sale (simulado).
     *
     * @test
     */
    public function el_ok_a_los_recordatorios_lo_contesta_el_sistema_y_el_si_siguiente_manda()
    {
        $perez = $this->cliente('Juan Perez', '5493444101010');
        $this->venta_impaga($perez, 40, 300000);

        // 1. La propuesta: el modelo llama a la herramienta y cierra con una frase; el sistema pega el bloque.
        $this->el_modelo_contesta(
            $this->tool_use('proponer_recordatorios_de_cobro', ['monto_minimo' => 200000, 'dias' => 20]),
            $this->end_turn('Te preparé el recordatorio para los que te deben.')
        );

        list($conversation, $propuso) = $this->turno(null, 'mandales un recordatorio de pago a todos los que me deben mas de 200 mil hace mas de 20 dias');

        $tarjeta = AiMessageAction::where('ai_message_id', $propuso->id)->firstOrFail();
        $bloque = $this->bloque_de($tarjeta->id);

        $this->assertNotSame('', $bloque);
        $this->assertStringContainsString($bloque, $propuso->contenido);
        $this->assertSame(2, $this->pedidos_al_modelo());

        // 2. «ok»: contesta el sistema, sin el modelo.
        list($conversation, $al_ok) = $this->turno($conversation, 'ok');

        $this->assertSame(PedidoDelSiParaEnvioIaHelper::RESPUESTA_AL_CASI_SI . "\n\n" . $bloque, $al_ok->contenido);
        $this->assertStringEndsWith(DestinatariosIaHelper::PREGUNTA, $al_ok->contenido);
        $this->assertSame(2, $this->pedidos_al_modelo(), 'El «ok» no le llega al modelo.');
        $this->assertSame([(int) $al_ok->id], $this->mostrado_en($tarjeta->id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado_guardado());
        $this->assertCount(0, $this->recordatorios_enviados(), 'Un «ok» no manda.');

        // 3. «sí, mandalo»: el modelo confirma, y ahora el sí vale.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta->id]),
            $this->end_turn('Listo, salió el recordatorio.')
        );

        list($conversation, $al_si) = $this->turno($conversation, 'sí, mandalo');

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjeta->fresh()->estado_guardado());
        $this->assertCount(1, $this->recordatorios_enviados());
        $this->assertSame(4, $this->pedidos_al_modelo());
        $this->assertStringNotContainsString(DestinatariosIaHelper::PREGUNTA, $al_si->contenido, 'Ya salió: no hay bloque que volver a mostrar.');

        $confirmaciones = array_values(array_filter($this->resultados_que_vio_el_modelo(), function ($resultado) {
            return is_array($resultado) && isset($resultado['tarjeta_id']) && isset($resultado['estado']);
        }));

        $this->assertNotEmpty($confirmaciones);
        $this->assertSame('confirmada', $confirmaciones[0]['estado']);
    }

    /**
     * «listo, gracias» (que el test 78 fijó como NO sí) y los otros casi sí: el mismo pedido del sistema,
     * sin el modelo.
     *
     * @test
     */
    public function listo_gracias_y_los_otros_casi_si_tambien_los_contesta_el_sistema()
    {
        foreach (['listo, gracias', 'perfecto', 'de acuerdo', 'joya', 'Okk!!', 'sí, gracias'] as $casi) {

            list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

            list($conversation, $respuesta) = $this->turno($conversation, $casi);

            $this->assertSame(PedidoDelSiParaEnvioIaHelper::RESPUESTA_AL_CASI_SI . "\n\n" . $this->bloque_de($tarjeta_id), $respuesta->contenido, $casi);
            $this->assertSame([(int) $respuesta->id], $this->mostrado_en($tarjeta_id), $casi);
        }

        $this->assertSame(0, $this->pedidos_al_modelo());
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Qué es un casi sí: una afirmación corta que no alcanza para mandar. El sí de verdad no es un casi
     * sí (ése va por confirmar), y una pregunta, una corrección o un "gracias" solo, tampoco.
     *
     * @test
     */
    public function el_vocabulario_del_casi_si()
    {
        foreach (['ok', 'Ok!', 'okey', 'listo', 'perfecto', 'va', 'joya', 'de acuerdo', 'hacelo', 'listo, gracias',
                  'sí, gracias', 'claro', 'dale gracias', 'bueno, listo', 'mandale gracias'] as $casi) {
            $this->assertTrue(ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($casi), $casi);
        }

        foreach (['sí', 'si, mandalo', 'dale', 'ok mandalo', 'confirmo', '¿ok?', 'ok pero sacá a Pérez', 'no',
                  'gracias', 'bueno', '', '👍', 'listo, ya lo mandé yo', 'esperá', 'ok ' . str_repeat('listo ', 10)] as $no) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($no), $no);
        }
    }

    /**
     * Con OTRA tarjeta esperando no se sabe a cuál contesta el «ok»: decide el modelo, y no se pega nada.
     *
     * @test
     */
    public function con_otra_tarjeta_esperando_el_ok_lo_decide_el_modelo()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $propuso->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['detalle' => 'Llamar a Marcos P81'],
            'presentacion'       => ['titulo' => 'Tarea en la agenda', 'renglones' => [], 'aviso' => null],
        ]);

        $this->el_modelo_contesta($this->end_turn('¿Cuál de las dos querés que haga?'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok');

        $this->assertSame('¿Cuál de las dos querés que haga?', $respuesta->contenido);
        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
    }

    /**
     * Un «ok» a una tarjeta de 40 minutos: decide el modelo (y el bloque no se vuelve a pegar).
     *
     * @test
     */
    public function un_ok_a_una_tarjeta_de_40_minutos_lo_decide_el_modelo()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        AiMessageAction::where('id', $tarjeta_id)->update(['created_at' => Carbon::now()->subMinutes(40)]);

        $this->el_modelo_contesta($this->end_turn('Esa promo ya tiene un rato: ¿te la armo de nuevo?'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok');

        $this->assertSame('Esa promo ya tiene un rato: ¿te la armo de nuevo?', $respuesta->contenido);
        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
    }

    /**
     * Un «ok» con foto puede ser el pedido de otra cosa: decide el modelo.
     *
     * @test
     */
    public function un_ok_con_foto_lo_decide_el_modelo()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        list($conversation, $assistant) = $this->vuelta('ok', $conversation);

        $pedido = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->orderBy('id', 'DESC')->first();

        \App\Models\AiMessageImagen::create([
            'ai_message_id' => $pedido->id,
            'user_id'       => $this->dueno->id,
            'orden'         => 1,
            'path'          => 'asistente_imagenes/no-existe-p81.webp',
            'mime'          => 'image/webp',
            'bytes'         => 10,
        ]);

        $this->assertNull(PedidoDelSiParaEnvioIaHelper::respuesta_al_casi_si($conversation, $assistant));
        $this->assertNull(PedidoDelSiParaEnvioIaHelper::envio_a_volver_a_mostrar($conversation, $assistant));
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
    }

    // ---------------------------------------------------------------------
    // El sí con el bloque fuera de la vista
    // ---------------------------------------------------------------------

    /**
     * 🔴 Un «sí» que llega después de una pregunta del medio NO manda (B1), pero el sistema le vuelve a
     * pegar el bloque al final del mensaje, lo registra y le deja la nota al modelo. El «sí» siguiente
     * contesta ESE bloque y manda.
     *
     * @test
     */
    public function el_si_despues_de_una_pregunta_en_el_medio_no_manda_pero_vuelve_a_mostrar_el_bloque_y_el_si_siguiente_manda()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Querés el detalle por artículo?');

        // El «sí» del medio: el modelo intenta confirmar, rebota, y pide el sí en una línea.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Para mandarlo necesito que me contestes «sí».')
        );

        list($conversation, $primero) = $this->turno($conversation, 'sí');

        $rechazo = null;

        foreach ($this->resultados_que_vio_el_modelo() as $resultado) {
            if (is_array($resultado) && isset($resultado['error']) && $resultado['error'] === ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO) {
                $rechazo = $resultado;
            }
        }

        $this->assertNotNull($rechazo, 'El sí del medio rebota con el mensaje de siempre.');
        $this->assertSame(PedidoDelSiParaEnvioIaHelper::NOTA_SE_VUELVE_A_MOSTRAR, $rechazo['nota']);

        $this->assertCount(0, $this->promociones_enviadas(), 'Un «sí» a otra pregunta no manda.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());

        $this->assertStringStartsWith('Para mandarlo necesito que me contestes «sí».', $primero->contenido);
        $this->assertStringContainsString(PedidoDelSiParaEnvioIaHelper::ENCABEZADO_DEL_REMOSTRADO . "\n\n" . $bloque, $primero->contenido);
        $this->assertStringEndsWith($bloque, $primero->contenido, 'El mensaje termina con el bloque y su pregunta.');
        $this->assertSame(1, substr_count($primero->contenido, DestinatariosIaHelper::PREGUNTA));
        $this->assertSame([(int) $primero->id], $this->mostrado_en($tarjeta_id));

        // El «sí» siguiente contesta el bloque que el sistema volvió a mostrar: manda.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo, salió la promo.')
        );

        $this->turno($conversation, 'sí');

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertCount(1, $this->promociones_enviadas());
        $this->assertStringContainsString('20% en todo el local', $this->promociones_enviadas()->first()->body);
    }

    /**
     * Lo decide el código, no el modelo: aunque el modelo no intente confirmar —y copie el bloque por su
     * cuenta, como el 19/10—, el bloque vuelve una sola vez, al final, registrado; y el «dale» siguiente
     * manda.
     *
     * @test
     */
    public function aunque_el_modelo_no_intente_confirmar_el_bloque_vuelve_una_sola_vez_y_el_si_siguiente_manda()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿y cuánto vendí ayer?', 'Ayer vendiste $ 80.000. ¿Te paso el detalle?');

        $this->el_modelo_contesta($this->end_turn("Acá te lo dejo de nuevo:\n\n" . $bloque));

        list($conversation, $primero) = $this->turno($conversation, 'sí');

        $this->assertSame(1, substr_count($primero->contenido, $bloque), 'La copia del modelo se va: queda la del sistema.');
        $this->assertStringEndsWith($bloque, $primero->contenido);
        $this->assertSame([(int) $primero->id], $this->mostrado_en($tarjeta_id));
        $this->assertCount(0, $this->promociones_enviadas());

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo.')
        );

        $this->turno($conversation, 'dale');

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado());
        $this->assertCount(1, $this->promociones_enviadas());
    }

    /**
     * Un «ok» que llega con el bloque fuera de la vista no es el caso fijo (el bloque no está delante):
     * contesta el modelo, pero el sistema igual le vuelve a pegar el bloque y lo registra.
     *
     * @test
     */
    public function un_ok_con_el_bloque_fuera_de_la_vista_lo_contesta_el_modelo_y_el_bloque_vuelve()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000.');

        $this->el_modelo_contesta($this->end_turn('Para mandarla necesito que me contestes «sí».'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertStringStartsWith('Para mandarla necesito que me contestes «sí».', $respuesta->contenido);
        $this->assertStringEndsWith($bloque, $respuesta->contenido);
        $this->assertSame([(int) $respuesta->id], $this->mostrado_en($tarjeta_id));
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Si el turno del sí deja OTRA tarjeta esperando (el modelo propuso una tarea), el envío viejo no se
     * le pega al lado: el dueño quedaría con dos cosas a las que decir que sí en el mismo mensaje.
     *
     * @test
     */
    public function si_el_turno_deja_otra_tarjeta_esperando_el_envio_viejo_no_se_vuelve_a_mostrar()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Te agendo llamar a Marcos?');

        list($conversation, $assistant) = $this->vuelta('sí', $conversation);

        $this->assertSame($tarjeta_id, (int) PedidoDelSiParaEnvioIaHelper::envio_a_volver_a_mostrar($conversation, $assistant)->id, 'Sin otra tarjeta, se vuelve a mostrar.');

        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['detalle' => 'Llamar a Marcos P81'],
            'presentacion'       => ['titulo' => 'Tarea en la agenda', 'renglones' => [], 'aviso' => null],
        ]);

        $this->assertNull(PedidoDelSiParaEnvioIaHelper::envio_a_volver_a_mostrar($conversation, $assistant));
    }

    /**
     * 🔴 Revisión independiente de la misión (19/10): UNA CORRECCIÓN QUE EL MODELO NO APLICÓ NO PUEDE TRAER
     * DE VUELTA EL BLOQUE VIEJO. Promo a Ana y a Juan → «sacale a Juan Perez» → el modelo dice «Listo,
     * saqué a Juan Perez de la promo.» SIN volver a proponer → «dale». Si el sistema re-pegara el bloque
     * (con Juan) y lo registrara, el «sí» siguiente lo mandaría a los dos. Con algo que no es una pregunta
     * ni un sí después del último bloque visto, no se re-muestra ni hay nota: vuelve B1 tal cual («armá
     * la propuesta de nuevo»).
     *
     * @test
     */
    public function una_correccion_que_el_modelo_no_aplico_no_trae_de_vuelta_el_bloque_viejo()
    {
        $this->cliente('Juan Perez', '5493444777777');

        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->assertStringContainsString('Juan Perez', $this->bloque_de($tarjeta_id));

        $this->el_modelo_contesta($this->end_turn('Listo, saqué a Juan Perez de la promo.'));

        $this->turno($conversation, 'sacale a Juan Perez');

        // El «dale»: el modelo intenta confirmar y rebota, sin la nota del re-mostrado.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Te la armo de nuevo sin Juan.')
        );

        list($conversation, $al_dale) = $this->turno($conversation, 'dale');

        $rechazos = array_values(array_filter($this->resultados_que_vio_el_modelo(), function ($resultado) {
            return is_array($resultado) && isset($resultado['error']) && $resultado['error'] === ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO;
        }));

        $this->assertNotEmpty($rechazos);
        $this->assertArrayNotHasKey('nota', $rechazos[0], 'Después de una corrección no se le dice al modelo que no vuelva a proponer.');
        $this->assertSame('Te la armo de nuevo sin Juan.', $al_dale->contenido, 'El bloque viejo no se vuelve a pegar.');
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        // Y el «sí» siguiente tampoco manda el bloque viejo.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Necesito armarla de nuevo.')
        );

        $this->turno($conversation, 'sí');

        $this->assertCount(0, $this->promociones_enviadas(), 'No sale a Juan, que el dueño sacó.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * 🟡 Revisión independiente (19/10): EL BLOQUE NO SE PEGA DEBAJO DE UNA RESPUESTA QUE TERMINA CON OTRA
     * PREGUNTA. Promo → «¿cuánto vendí hoy?» → «Vendiste $ 120.000. ¿Querés el detalle?» → «sí» (para el
     * detalle) → el modelo da el detalle y pregunta «¿Querés que te lo mande también por mail?». Si el
     * sistema pegara el bloque y lo registrara, el «dale» a lo del mail confirmaría la promo. Antes del
     * loop el modelo recibe la nota de que el sistema le va a volver a mostrar el envío si no pregunta
     * nada; si igual pregunta, no se pega ni se registra y decide B1.
     *
     * @test
     */
    public function una_respuesta_que_termina_con_otra_pregunta_no_lleva_el_bloque_y_el_dale_a_esa_pregunta_no_manda()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Querés el detalle?');

        $this->el_modelo_contesta($this->end_turn('Vendiste 3 cajas de tornillos y 2 martillos. ¿Querés que te lo mande también por mail?'));

        list($conversation, $al_detalle) = $this->turno($conversation, 'sí');

        // El modelo supo, antes de contestar, que el envío vuelve al final si no pregunta nada.
        $ultimo_pedido = Http::recorded(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        })->last()[0]->data();

        $this->assertStringContainsString(
            PedidoDelSiParaEnvioIaHelper::NOTA_PARA_EL_TURNO,
            json_encode(end($ultimo_pedido['messages']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        // Igual preguntó: no se pega ni se registra.
        $this->assertSame('Vendiste 3 cajas de tornillos y 2 martillos. ¿Querés que te lo mande también por mail?', $al_detalle->contenido);
        $this->assertStringNotContainsString($bloque, $al_detalle->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        // El «dale» (a lo del mail): aunque el modelo intente confirmar la promo, no sale.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo, te lo mando por mail.')
        );

        $this->turno($conversation, 'dale');

        $this->assertCount(0, $this->promociones_enviadas(), 'El «dale» contestaba lo del mail, no la promo.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Variante del revisor: un «no, dejalo» que el modelo no canceló, seguido de un «sí» a otra pregunta,
     * no hace reaparecer el envío que el dueño rechazó.
     *
     * @test
     */
    public function un_no_dejalo_que_el_modelo_no_cancelo_no_hace_reaparecer_el_envio()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->el_modelo_contesta($this->end_turn('Dale, no la mando.'));

        $this->turno($conversation, 'no, dejalo');

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Querés el detalle?');

        $this->el_modelo_contesta($this->end_turn('Vendiste 3 cajas de tornillos y 2 martillos.'));

        list($conversation, $respuesta) = $this->turno($conversation, 'sí');

        $this->assertSame('Vendiste 3 cajas de tornillos y 2 martillos.', $respuesta->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertNull(PedidoDelSiParaEnvioIaHelper::envio_a_volver_a_mostrar($conversation, $respuesta));
        $this->assertCount(0, $this->promociones_enviadas());
    }

    // ---------------------------------------------------------------------
    // Las garantías de B1 siguen
    // ---------------------------------------------------------------------

    /**
     * Un mensaje del MODELO con el bloque copiado no cuenta como «a la vista» (`mostrado_en` lo escribe
     * solo el sistema): el «sí» a ese mensaje no manda.
     *
     * @test
     */
    public function un_bloque_que_copio_el_modelo_no_cuenta_como_visto()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        // Una PREGUNTA en el medio: con un pedido («mostramela de nuevo») la regla del revisor apaga el re-mostrado y no habría nota.
        $this->otra_charla($conversation, '¿me la mostrás de nuevo?', "Acá está:\n\n" . $this->bloque_de($tarjeta_id));

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertTrue(empty($intento['ok']));
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertSame(PedidoDelSiParaEnvioIaHelper::NOTA_SE_VUELVE_A_MOSTRAR, $intento['nota'], 'El sistema se lo va a volver a mostrar.');
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Después de que el sistema volvió a mostrar el bloque, un «sí» seguido de un «esperá» no manda.
     *
     * @test
     */
    public function un_si_seguido_de_otro_mensaje_del_dueno_no_manda_aunque_el_bloque_se_haya_vuelto_a_mostrar()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        list($conversation, $al_ok) = $this->turno($conversation, 'ok');

        $this->assertSame([(int) $al_ok->id], $this->mostrado_en($tarjeta_id));

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'esperá', 'estado' => 'listo', 'canal' => AiMessage::CANAL_WHATSAPP]);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Los 30 minutos se cuentan desde la PROPUESTA: volver a mostrarla no los estira.
     *
     * @test
     */
    public function una_tarjeta_de_mas_de_30_minutos_no_se_manda_aunque_se_haya_vuelto_a_mostrar()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->turno($conversation, 'ok');

        AiMessageAction::where('id', $tarjeta_id)->update(['created_at' => Carbon::now()->subMinutes(31)]);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertArrayNotHasKey('nota', $intento, 'Vieja: no se vuelve a mostrar.');
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * El mensaje registrado tiene que estar 'listo' (si no, no salió por WhatsApp) y tener el bloque
     * adentro (si salió otro texto, el dueño no lo vio).
     *
     * @test
     */
    public function el_mensaje_registrado_tiene_que_estar_listo_y_con_el_bloque()
    {
        foreach (['pendiente', 'sin_el_bloque'] as $caso) {

            list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

            list($conversation, $al_ok) = $this->turno($conversation, 'ok');

            if ($caso === 'pendiente') {
                $al_ok->estado = 'pendiente';
            } else {
                $al_ok->contenido = 'Se me cortó la conexión con el servicio de IA.';
            }

            $al_ok->save();

            list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

            $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

            $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error'], $caso);
        }

        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * Si el sí contesta un mensaje de `mostrado_en`, se cuentan los envíos esperando de TODA la
     * conversación: con dos, no se manda ninguno (el cinturón de la regla 5).
     *
     * @test
     */
    public function dos_envios_esperando_despues_de_volver_a_mostrar_no_mandan()
    {
        $ana = $this->cliente('Ana Deudora', '5493444909090');
        $this->venta_impaga($ana, 40, 300000);

        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        list($conversation, $al_ok) = $this->turno($conversation, 'ok');

        // Un segundo envío colgado del mensaje que propuso la promo (el reemplazo la deja reemplazada: se la fuerza a seguir esperando).
        $recordatorio = $this->herramienta($conversation, $propuso, 'proponer_recordatorios_de_cobro', []);

        $this->assertTrue(!empty($recordatorio['ok']), json_encode($recordatorio));

        AiMessageAction::where('id', $tarjeta_id)->update(['estado' => AiMessageAction::ESTADO_PROPUESTA, 'resuelta_at' => null]);

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_VARIOS_ENVIOS, $intento['error']);
        $this->assertCount(0, $this->promociones_enviadas());
    }

    // ---------------------------------------------------------------------
    // MCP y el panel no cambian
    // ---------------------------------------------------------------------

    /**
     * En el panel (canal 'sistema') el «ok» lo decide el modelo como siempre y no se pega nada: ahí está
     * la tarjeta con su botón.
     *
     * @test
     */
    public function en_el_panel_nada_cambia()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista(null);

        $this->el_modelo_contesta($this->end_turn('Perfecto.'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok', null);

        $this->assertSame('Perfecto.', $respuesta->contenido);
        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        // Y un «sí» tipeado en el panel después de otra charla tampoco pega el bloque.
        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000.', null);

        $this->el_modelo_contesta($this->end_turn('Entendido, seguimos.'));

        list($conversation, $respuesta) = $this->turno($conversation, 'sí', null);

        $this->assertSame('Entendido, seguimos.', $respuesta->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Por MCP un envío no se confirma (MENSAJE_ENVIO_POR_MCP, sin nota) y el «ok» lo sigue contestando el
     * modelo, sin bloque que volver a pegar.
     *
     * @test
     */
    public function por_mcp_nada_cambia()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista(AiMessage::CANAL_MCP);

        $this->el_modelo_contesta($this->end_turn('Ese envío lo tiene que mandar una persona.'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok', AiMessage::CANAL_MCP);

        $this->assertSame('Ese envío lo tiene que mandar una persona.', $respuesta->contenido);
        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation, AiMessage::CANAL_MCP);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_POR_MCP, $intento['error']);
        $this->assertArrayNotHasKey('nota', $intento);
        $this->assertCount(0, $this->promociones_enviadas());
    }
}
