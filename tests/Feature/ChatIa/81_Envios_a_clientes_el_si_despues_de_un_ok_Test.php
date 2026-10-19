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
 *  - el «sí» que llega con el bloque fuera de la vista (hubo una pregunta, una corrección o un «no» en
 *    el medio) va por el camino de develop: no manda, NO se vuelve a pegar el bloque (las dos revisiones
 *    independientes del 19/10 mostraron que eso traía de vuelta bloques viejos) y el «sí» siguiente sin
 *    una propuesta nueva tampoco manda; los escenarios de esas revisiones, uno por uno;
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
     * @param  AiConversation|null  $conversation  Para proponerla en una conversación que ya tiene historia.
     * @return array{0: AiConversation, 1: AiMessage, 2: int}  La conversación, el mensaje que propuso y el tarjeta_id.
     */
    protected function promo_a_la_vista($canal = AiMessage::CANAL_WHATSAPP, $conversation = null)
    {
        $this->cliente('Ana Gomez ' . uniqid(), '5493444' . rand(100000, 999999));

        list($conversation, $propuso) = $this->vuelta('mandales una promo del 20%', $conversation, $canal);

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
        // El texto, palabra por palabra (revisión del 19/10: «solo», para que a un «sí, gracias» no le suene raro).
        $this->assertStringStartsWith("Para mandarlo necesito que me contestes solo «sí». Te lo dejo de nuevo:\n\n", $al_ok->contenido);
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

        // El 👍 dejó de estar en esta lista el 19/10 (pedido de la raíz): ver el test de los gestos.
        foreach (['sí', 'si, mandalo', 'dale', 'ok mandalo', 'confirmo', '¿ok?', 'ok pero sacá a Pérez', 'no',
                  'gracias', 'bueno', '', 'listo, ya lo mandé yo', 'esperá', 'ok ' . str_repeat('listo ', 10)] as $no) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($no), $no);
        }
    }

    /**
     * 🟡 Segunda revisión independiente (19/10): UN 👍 VOLVÍA AL CÍRCULO DE A8. Normalizado queda vacío y
     * no era ni sí ni casi sí: decidía el modelo, que pedía el sí sin el bloque. Ahora 👍, 👌 o ✅ solos o
     * repetidos (con el tono de piel o el selector de variante que agregan los teclados) son un casi sí:
     * la respuesta fija, que no manda nada. Un emoji con texto se mira por el texto, y cualquier otro
     * emoji solo sigue sin ser nada.
     *
     * @test
     */
    public function un_pulgar_arriba_es_un_casi_si_y_lo_contesta_el_sistema()
    {
        foreach (['👍', '👍👍', '👍 👍', '👌', '✅', '✅✅✅', '👍🏻', '✔️👍'] as $gesto) {

            $esperado = $gesto !== '✔️👍';

            $this->assertSame($esperado, ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($gesto), $gesto);
        }

        foreach (['❤️', '🙂', '👎', '🙏', '👍 no', '👍?'] as $no) {
            $this->assertFalse(ConfirmacionDeterministaIaHelper::es_casi_si_para_envio($no), $no);
        }

        // Para MANDAR sigue sin alcanzar (decisión de B1, test 78).
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_si_para_envio('👍'));

        // De punta a punta por el job: la respuesta fija, sin el modelo y sin mandar nada.
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        list($conversation, $al_pulgar) = $this->turno($conversation, '👍');

        $this->assertSame(PedidoDelSiParaEnvioIaHelper::RESPUESTA_AL_CASI_SI . "\n\n" . $this->bloque_de($tarjeta_id), $al_pulgar->contenido);
        $this->assertSame(0, $this->pedidos_al_modelo());
        $this->assertSame([(int) $al_pulgar->id], $this->mostrado_en($tarjeta_id));
        $this->assertCount(0, $this->promociones_enviadas());
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
     * 🟡 Revisión independiente (19/10): UNA TARJETA OLVIDADA NO APAGA EL ARREGLO. En WhatsApp es probable
     * que quede una tarjeta de la mañana sin contestar en el mismo chat; si contara como «otra tarjeta
     * esperando», el «ok» volvía al modelo y al círculo de A8. Solo cuentan las de menos de
     * ConfirmacionDeterministaIaHelper::MINUTOS_MAXIMOS.
     *
     * @test
     */
    public function una_tarjeta_olvidada_de_hace_tres_horas_no_apaga_el_casi_si()
    {
        $conversation = AiConversation::create(['user_id' => $this->dueno->id, 'auth_user_id' => $this->dueno->id]);

        $de_la_manana = $this->otra_charla($conversation, 'agendame llamar a Marcos', 'Te dejo la tarea para llamar a Marcos. ¿La agendo?');

        $olvidada = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $de_la_manana->id,
            'user_id'            => $this->dueno->id,
            'auth_user_id'       => $this->dueno->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => ['detalle' => 'Llamar a Marcos P81'],
            'presentacion'       => ['titulo' => 'Tarea en la agenda', 'renglones' => [], 'aviso' => null],
        ]);

        AiMessageAction::where('id', $olvidada->id)->update(['created_at' => Carbon::now()->subHours(3)]);

        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista(AiMessage::CANAL_WHATSAPP, $conversation);

        list($conversation, $al_ok) = $this->turno($conversation, 'ok');

        $this->assertSame(PedidoDelSiParaEnvioIaHelper::RESPUESTA_AL_CASI_SI . "\n\n" . $this->bloque_de($tarjeta_id), $al_ok->contenido);
        $this->assertSame(0, $this->pedidos_al_modelo());
        $this->assertSame([(int) $al_ok->id], $this->mostrado_en($tarjeta_id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $olvidada->fresh()->estado_guardado(), 'La olvidada sigue como estaba.');
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
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
    }

    // ---------------------------------------------------------------------
    // El sí con el bloque fuera de la vista: el camino de develop
    // ---------------------------------------------------------------------

    /**
     * 🔴 Decisión de la raíz tras la segunda revisión (19/10): un «sí» que llega después de una pregunta
     * del medio NO manda y el sistema NO vuelve a pegar el bloque (eso fue lo que trajo de vuelta bloques
     * viejos). El rechazo es el de B1, sin nota; el mensaje es el del modelo tal cual; `mostrado_en` no
     * cambia; y el «sí» siguiente, sin una propuesta nueva en el medio, tampoco manda.
     *
     * @test
     */
    public function el_si_despues_de_una_pregunta_en_el_medio_rebota_no_se_repega_y_sin_propuesta_nueva_no_manda()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Querés el detalle por artículo?');

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Para mandarla primero te la tengo que mostrar de nuevo.')
        );

        list($conversation, $primero) = $this->turno($conversation, 'sí');

        $rechazos = array_values(array_filter($this->resultados_que_vio_el_modelo(), function ($resultado) {
            return is_array($resultado) && isset($resultado['error']) && $resultado['error'] === ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO;
        }));

        $this->assertNotEmpty($rechazos, 'El sí del medio rebota con el mensaje de B1.');
        $this->assertArrayNotHasKey('nota', $rechazos[0]);
        $this->assertSame('Para mandarla primero te la tengo que mostrar de nuevo.', $primero->contenido, 'Nada se le pega al texto del modelo.');
        $this->assertStringNotContainsString($bloque, $primero->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        // El «sí» siguiente, sin propuesta nueva: tampoco manda.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Te la armo de nuevo.')
        );

        $this->turno($conversation, 'sí');

        $this->assertCount(0, $this->promociones_enviadas());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Un bloque que el modelo copia por su cuenta no se toca (no se saca ni se re-pega: el mensaje sale
     * como lo escribió) y no cuenta como visto: el «dale» siguiente no manda.
     *
     * @test
     */
    public function un_bloque_copiado_por_el_modelo_no_se_toca_y_el_dale_siguiente_no_manda()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();
        $bloque = $this->bloque_de($tarjeta_id);

        $this->otra_charla($conversation, '¿y cuánto vendí ayer?', 'Ayer vendiste $ 80.000. ¿Te paso el detalle?');

        $this->el_modelo_contesta($this->end_turn("Acá te lo dejo de nuevo:\n\n" . $bloque));

        list($conversation, $primero) = $this->turno($conversation, 'sí');

        $this->assertSame("Acá te lo dejo de nuevo:\n\n" . $bloque, $primero->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo.')
        );

        $this->turno($conversation, 'dale');

        $this->assertCount(0, $this->promociones_enviadas());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Un «ok» con el bloque fuera de la vista no es el caso fijo: contesta el modelo y no se pega nada.
     *
     * @test
     */
    public function un_ok_con_el_bloque_fuera_de_la_vista_lo_contesta_el_modelo_sin_repegar()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000.');

        $this->el_modelo_contesta($this->end_turn('Si querés mandarla, te la muestro de nuevo.'));

        list($conversation, $respuesta) = $this->turno($conversation, 'ok');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame('Si querés mandarla, te la muestro de nuevo.', $respuesta->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * 🔴 Primera revisión independiente (19/10), que sigue valiendo en el camino de develop: promo a Ana y
     * a Juan → «sacale a Juan Perez» → el modelo dice «Listo, saqué a Juan Perez de la promo.» SIN volver
     * a proponer → «dale» → rebota sin nota y no se re-pega nada → «sí» → no sale a Juan.
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

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Te la armo de nuevo sin Juan.')
        );

        list($conversation, $al_dale) = $this->turno($conversation, 'dale');

        $rechazos = array_values(array_filter($this->resultados_que_vio_el_modelo(), function ($resultado) {
            return is_array($resultado) && isset($resultado['error']) && $resultado['error'] === ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO;
        }));

        $this->assertNotEmpty($rechazos);
        $this->assertArrayNotHasKey('nota', $rechazos[0]);
        $this->assertSame('Te la armo de nuevo sin Juan.', $al_dale->contenido, 'El bloque viejo no se vuelve a pegar.');
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Necesito armarla de nuevo.')
        );

        $this->turno($conversation, 'sí');

        $this->assertCount(0, $this->promociones_enviadas(), 'No sale a Juan, que el dueño sacó.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Primera revisión independiente: un «sí» a una respuesta que termina con OTRA pregunta no lleva el
     * bloque, y el «dale» a esa otra pregunta no manda la promo.
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

        $this->assertSame('Vendiste 3 cajas de tornillos y 2 martillos. ¿Querés que te lo mande también por mail?', $al_detalle->contenido);
        $this->assertStringNotContainsString($bloque, $al_detalle->contenido);
        $this->assertSame([], $this->mostrado_en($tarjeta_id));

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo, te lo mando por mail.')
        );

        $this->turno($conversation, 'dale');

        $this->assertCount(0, $this->promociones_enviadas(), 'El «dale» contestaba lo del mail, no la promo.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Variante de la primera revisión: un «no, dejalo» que el modelo no canceló, seguido de un «sí» a otra
     * pregunta, no hace reaparecer el envío que el dueño rechazó.
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
        $this->assertCount(0, $this->promociones_enviadas());
    }

    /**
     * 🔴 Segunda revisión independiente (19/10), reproducido con el job real sobre el re-pegado general:
     * UNA CORRECCIÓN ESCRITA COMO PREGUNTA. Promo a Ana y a Juan → «¿le sacás a Juan?» → el modelo dice
     * «Listo, saqué a Juan.» sin volver a proponer → «dale» → «sí». Con el re-pegado volvía el bloque viejo
     * y el «sí» le mandaba a Juan; en el camino de develop los dos rebotan y no sale nada.
     *
     * @test
     */
    public function una_correccion_escrita_como_pregunta_no_le_manda_a_juan()
    {
        $this->cliente('Juan Perez', '5493444777777');

        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->el_modelo_contesta($this->end_turn('Listo, saqué a Juan.'));

        $this->turno($conversation, '¿le sacás a Juan?');

        foreach (['dale', 'sí'] as $si) {

            $this->el_modelo_contesta(
                $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
                $this->end_turn('Necesito mostrártela de nuevo sin Juan.')
            );

            list($conversation, $respuesta) = $this->turno($conversation, $si);

            $this->assertStringNotContainsString($this->bloque_de($tarjeta_id), $respuesta->contenido, $si);
        }

        $this->assertCount(0, $this->promociones_enviadas(), 'No sale a Juan.');
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Segunda revisión: «¿la mandás a las 18?» → el modelo dice que sí sin volver a proponer → «ok». El
     * «ok» ya no contesta un bloque a la vista (no es el caso fijo: decide el modelo) y no es un sí para
     * mandar: no sale nada en el momento.
     *
     * @test
     */
    public function la_mandas_a_las_18_y_un_ok_no_la_manda_en_el_momento()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->el_modelo_contesta($this->end_turn('Dale, la mando a las 18.'));

        $this->turno($conversation, '¿la mandás a las 18?');

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Perfecto.')
        );

        list($conversation, $al_ok) = $this->turno($conversation, 'ok');

        $this->assertSame('Perfecto.', $al_ok->contenido, 'No es la respuesta fija: el bloque no estaba en el último mensaje.');
        $this->assertSame(3, $this->pedidos_al_modelo());
        $this->assertCount(0, $this->promociones_enviadas());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * Segunda revisión: UN OFRECIMIENTO SIN SIGNO DE PREGUNTA. Promo → «¿cuánto vendí hoy?» → «Vendiste
     * $ 120.000. Si querés, también te lo mando por mail.» → «dale» (a lo del mail). No manda la promo.
     *
     * @test
     */
    public function un_ofrecimiento_sin_signo_de_pregunta_y_un_dale_no_mandan_la_promo()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. Si querés, también te lo mando por mail.');

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]),
            $this->end_turn('Listo, te lo mando por mail.')
        );

        list($conversation, $al_dale) = $this->turno($conversation, 'dale');

        $this->assertStringNotContainsString($this->bloque_de($tarjeta_id), $al_dale->contenido);
        $this->assertCount(0, $this->promociones_enviadas());
        $this->assertSame([], $this->mostrado_en($tarjeta_id));
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, AiMessageAction::find($tarjeta_id)->estado_guardado());
    }

    /**
     * 🔴 QUE EL REBOTE NO TERMINE EN CÍRCULO (pedido de la raíz tras la segunda revisión, 19/10). Sin el
     * re-pegado, lo que corta el círculo es que el modelo vuelva a PROPONER en el mismo turno: el texto
     * de MENSAJE_ENVIO_NO_VISTO se lo pide (con los mismos datos más lo corregido) y le avisa que un
     * bloque escrito por él no vale. Por el job real: «¿cuánto vendí hoy?» en el medio → «sí» → rebota →
     * el modelo vuelve a proponer → el mensaje trae el bloque nuevo → «sí» → sale.
     *
     * @test
     */
    public function el_rebote_le_pide_al_modelo_volver_a_proponer_en_el_mismo_turno_y_el_si_siguiente_manda()
    {
        list($conversation, $propuso, $vieja) = $this->promo_a_la_vista();

        $this->otra_charla($conversation, '¿cuánto vendí hoy?', 'Vendiste $ 120.000. ¿Querés el detalle?');

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $vieja]),
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']),
            $this->end_turn('Te la dejo de nuevo para que la veas antes de mandarla.')
        );

        list($conversation, $repropuso) = $this->turno($conversation, 'sí');

        $rechazos = array_values(array_filter($this->resultados_que_vio_el_modelo(), function ($resultado) {
            return is_array($resultado) && isset($resultado['error']) && $resultado['error'] === ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO;
        }));

        $this->assertNotEmpty($rechazos);
        $this->assertStringContainsString('el dueño no tenía este envío a la vista justo antes de decir que sí', $rechazos[0]['error']);
        $this->assertStringContainsString('En ESTE mismo turno volvé a llamar a la herramienta que lo propuso', $rechazos[0]['error']);
        $this->assertStringContainsString('Un bloque escrito por vos no vale como visto', $rechazos[0]['error']);

        // La propuesta nueva reemplazó a la vieja y su bloque vino en el mensaje del turno.
        $nueva = AiMessageAction::where('ai_message_id', $repropuso->id)
            ->where('tipo', AiMessageAction::TIPO_PROMOCION_WHATSAPP)
            ->where('estado', AiMessageAction::ESTADO_PROPUESTA)
            ->firstOrFail();

        $this->assertSame(AiMessageAction::ESTADO_REEMPLAZADA, AiMessageAction::find($vieja)->estado_guardado());
        $this->assertStringContainsString($this->bloque_de($nueva->id), $repropuso->contenido);
        $this->assertCount(0, $this->promociones_enviadas());

        // El «sí» siguiente contesta el bloque nuevo: sale.
        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $nueva->id]),
            $this->end_turn('Listo, salió la promo.')
        );

        $this->turno($conversation, 'sí');

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $nueva->fresh()->estado_guardado());
        $this->assertCount(1, $this->promociones_enviadas());
    }

    /**
     * Un mensaje del MODELO con el bloque copiado no cuenta como «a la vista» (`mostrado_en` lo escribe
     * solo el sistema): el «sí» a ese mensaje no manda.
     *
     * @test
     */
    public function un_bloque_que_copio_el_modelo_no_cuenta_como_visto()
    {
        list($conversation, $propuso, $tarjeta_id) = $this->promo_a_la_vista();

        $this->otra_charla($conversation, '¿me la mostrás de nuevo?', "Acá está:\n\n" . $this->bloque_de($tarjeta_id));

        list($conversation, $siguiente) = $this->vuelta('sí', $conversation);

        $intento = $this->herramienta($conversation, $siguiente, 'confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta_id]);

        $this->assertTrue(empty($intento['ok']));
        $this->assertSame(ConfirmacionPorTextoIaHelper::MENSAJE_ENVIO_NO_VISTO, $intento['error']);
        $this->assertArrayNotHasKey('nota', $intento, 'El rechazo es el de B1, sin nota.');
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
