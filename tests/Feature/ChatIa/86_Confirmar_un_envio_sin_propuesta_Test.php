<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\DestinatariosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\EnvioSinPropuestaIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use Illuminate\Support\Facades\Http;
use Tests\EmpresaTestCase;

/**
 * Misión asistente-marca-de-foto-y-envio-sin-bloque (20/10/2026) — «¿Confirmás el envío?» sin propuesta.
 *
 * El caso real (repetición de la segunda prueba contra demo3, 20/10/2026, mensaje 150): después de un
 * presupuesto, «mandaselo por mail y por whatsapp asi lo ve» → el modelo contestó en 3 s «Lo mando por
 * WhatsApp y por mail a Marcos Perez con el PDF del presupuesto 900006. ¿Confirmás el envío?» SIN llamar
 * a proponer_envio_de_comprobante: no se creó ninguna acción ni había bloque. Recién con el «ok»
 * siguiente propuso y mostró el bloque.
 *
 * Lo que fija este archivo (por el job real y con el modelo en un Http::fake):
 *   - Por WhatsApp, si el turno termina pidiendo confirmar un envío y no hay propuesta, UN reintento con
 *     la nota del sistema; si en el reintento el modelo llama a la herramienta, la persona ve el bloque
 *     a la primera (el bloque lo pega el sistema).
 *   - Si el reintento tampoco arma la propuesta, el texto se cambia por TEXTO_HONESTO (nada de una
 *     pregunta de confirmación vacía) y no queda ninguna tarjeta.
 *   - No molesta: con la propuesta en el turno, con una pregunta por un dato («¿a quién se lo mando?»),
 *     en el panel del sistema, y con un envío que ya estaba esperando en la respuesta anterior.
 *   - El detector, frase por frase.
 *
 * 🔴 Nada sale a la red: el modelo es un Http::fake con las respuestas en una cola, y el WhatsApp va en
 * envío simulado (EnviosAClientesFixtures).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Confirmar_un_envio_sin_propuesta_Test extends EmpresaTestCase
{
    use EnviosAClientesFixtures;

    /** @var array<int, array> Las respuestas que va a dar el modelo, en orden. */
    protected $respuestas_del_modelo = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->armar_negocio([
            'agente_confianza'   => ConfianzaDelAgenteIaHelper::DIRECTO,
            'agente_proveedor'   => 'anthropic',
            'agente_pensamiento' => 'agil',
        ]);

        $this->dar_extension('asistente_ia');

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p86',
            'services.anthropic.base_url'       => 'https://api.anthropic.com',
            'services.anthropic.model'          => 'claude-general-p86',
            'services.anthropic.model_agil'     => 'claude-agil-p86',
            'services.anthropic.model_profundo' => 'claude-profundo-p86',
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

        $this->cliente('Ana Gomez ' . uniqid(), '5493444' . rand(100000, 999999));
    }

    // ---------------------------------------------------------------------
    // Ayudas
    // ---------------------------------------------------------------------

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
                'id'    => 'toolu_p86_' . uniqid(),
                'name'  => $herramienta,
                'input' => $input,
            ]],
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * Un turno entero por el camino real (el job del chat).
     *
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function turno($conversation, $pedido, $canal = AiMessage::CANAL_WHATSAPP)
    {
        list($conversation, $assistant) = $this->vuelta($pedido, $conversation, $canal);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, 'El turno terminó en error: ' . $assistant->contenido . ' / ' . $assistant->error_mensaje);

        return [$conversation, $assistant];
    }

    protected function pedidos_al_modelo()
    {
        return Http::recorded(function ($request) {
            return strpos($request->url(), 'api.anthropic.com') !== false;
        })->count();
    }

    /**
     * El texto de todos los mensajes `user` que le llegaron al modelo, en el último pedido.
     *
     * @return string
     */
    protected function lo_que_leyo_el_modelo_en_el_ultimo_pedido()
    {
        $texto = '';

        foreach (Http::recorded() as $par) {

            if (strpos($par[0]->url(), 'api.anthropic.com') === false) {
                continue;
            }

            $texto = json_encode($par[0]->data()['messages'], JSON_UNESCAPED_UNICODE);
        }

        return $texto;
    }

    const PREGUNTA_VACIA = 'Lo mando por WhatsApp y por mail a Marcos Perez con el PDF del presupuesto 900006. ¿Confirmás el envío?';

    // ---------------------------------------------------------------------
    // El reintento
    // ---------------------------------------------------------------------

    /**
     * 🔴 EL CASO DEL MENSAJE 150: el modelo pregunta «¿Confirmás el envío?» sin proponer → un reintento →
     * llama a la herramienta → el dueño ve el bloque a la primera.
     *
     * @test
     */
    public function el_confirmas_el_envio_sin_propuesta_se_reintenta_y_el_duenio_ve_el_bloque_a_la_primera()
    {
        $this->el_modelo_contesta(
            $this->end_turn(self::PREGUNTA_VACIA),
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']),
            $this->end_turn('Te lo dejo armado.')
        );

        list($conversation, $asistente) = $this->turno(null, 'mandaselo por mail y por whatsapp asi lo ve');

        $this->assertSame(3, $this->pedidos_al_modelo(), 'Un solo reintento.');

        $tarjeta = AiMessageAction::where('ai_message_id', $asistente->id)->firstOrFail();

        $this->assertSame(AiMessageAction::TIPO_PROMOCION_WHATSAPP, $tarjeta->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado());

        $bloque = (string) $tarjeta->datos['bloque'];

        $this->assertNotSame('', $bloque);
        $this->assertStringContainsString($bloque, $asistente->contenido, 'El bloque va pegado al mensaje.');
        $this->assertStringContainsString('Te lo dejo armado.', $asistente->contenido);
        $this->assertStringNotContainsString(self::PREGUNTA_VACIA, $asistente->contenido, 'La pregunta vacía no le llega al dueño.');
        $this->assertStringEndsWith(DestinatariosIaHelper::PREGUNTA, $asistente->contenido);

        $this->assertStringContainsString('NO armaste ninguna propuesta de envío', $this->lo_que_leyo_el_modelo_en_el_ultimo_pedido(), 'El reintento lleva la nota del sistema.');
    }

    /**
     * Si el reintento tampoco arma la propuesta, la pregunta vacía NO le llega al dueño: va el texto
     * honesto, y no queda ninguna tarjeta.
     *
     * @test
     */
    public function si_el_reintento_tampoco_propone_el_texto_se_cambia_por_el_honesto()
    {
        $this->el_modelo_contesta(
            $this->end_turn(self::PREGUNTA_VACIA),
            $this->end_turn('Dale, lo mando por WhatsApp y por mail. ¿Confirmás?')
        );

        list($conversation, $asistente) = $this->turno(null, 'mandaselo por mail y por whatsapp asi lo ve');

        $this->assertSame(2, $this->pedidos_al_modelo());
        $this->assertSame(EnvioSinPropuestaIaHelper::TEXTO_HONESTO, $asistente->contenido);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
    }

    /**
     * Si el reintento falla por red o por el proveedor, el turno no se rompe: se sigue con el texto de
     * antes y la guarda de después del bucle lo cambia por el honesto.
     *
     * @test
     */
    public function si_el_reintento_falla_el_turno_no_se_rompe()
    {
        // El segundo pedido no tiene respuesta preparada: el fake contesta 400.
        $this->el_modelo_contesta($this->end_turn(self::PREGUNTA_VACIA));

        list($conversation, $asistente) = $this->turno(null, 'mandaselo por mail y por whatsapp asi lo ve');

        $this->assertSame(2, $this->pedidos_al_modelo());
        $this->assertSame(EnvioSinPropuestaIaHelper::TEXTO_HONESTO, $asistente->contenido);
    }

    // ---------------------------------------------------------------------
    // Lo que no se toca
    // ---------------------------------------------------------------------

    /**
     * Con la propuesta en el turno no hay reintento: el modelo la armó y el bloque lo pega el sistema.
     *
     * @test
     */
    public function con_la_propuesta_en_el_turno_no_hay_reintento()
    {
        $this->el_modelo_contesta(
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']),
            $this->end_turn('Armé la promo. ¿Confirmás el envío?')
        );

        list($conversation, $asistente) = $this->turno(null, 'mandales una promo del 20%');

        $this->assertSame(2, $this->pedidos_al_modelo());
        $this->assertSame(1, AiMessageAction::where('ai_message_id', $asistente->id)->count());
        $this->assertStringContainsString('Armé la promo.', $asistente->contenido);
    }

    /**
     * Una pregunta por un dato es legítima antes de proponer: ni reintento ni texto honesto.
     *
     * @test
     */
    public function una_pregunta_por_un_dato_no_se_toca()
    {
        $this->el_modelo_contesta($this->end_turn('Decime a quién se lo mando: ¿a quién se lo mando?'));

        list($conversation, $asistente) = $this->turno(null, 'mandaselo');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame('Decime a quién se lo mando: ¿a quién se lo mando?', $asistente->contenido);
    }

    /**
     * Un texto que cuenta algo hecho no es una pregunta de confirmación.
     *
     * @test
     */
    public function un_texto_que_no_pide_confirmar_nada_no_se_toca()
    {
        $this->el_modelo_contesta($this->end_turn('Listo, el presupuesto quedó cargado. El envío lo hacemos cuando quieras.'));

        list($conversation, $asistente) = $this->turno(null, 'armame un presupuesto');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertStringContainsString('quedó cargado', $asistente->contenido);
    }

    /**
     * En el panel del sistema la guarda no corre: el bloque es la tarjeta con su botón, y ahí vale la
     * guarda de la tarjeta prometida de siempre.
     *
     * @test
     */
    public function en_el_panel_no_corre_esta_guarda()
    {
        $this->el_modelo_contesta($this->end_turn(self::PREGUNTA_VACIA));

        list($conversation, $asistente) = $this->turno(null, 'mandaselo', null);

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertSame(self::PREGUNTA_VACIA, $asistente->contenido);
    }

    /**
     * Un envío que ya estaba esperando el sí en la respuesta anterior: el texto puede estar hablando de
     * ése, y no se reintenta (el «ok» al bloque ya tiene su camino, test 81).
     *
     * @test
     */
    public function con_un_envio_esperando_de_la_respuesta_anterior_no_se_reintenta()
    {
        $this->el_modelo_contesta(
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']),
            $this->end_turn('Te lo dejo armado.')
        );

        list($conversation, $primero) = $this->turno(null, 'mandales una promo del 20%');

        $this->assertSame(1, AiMessageAction::where('ai_message_id', $primero->id)->count());

        $antes = $this->pedidos_al_modelo();

        $this->el_modelo_contesta($this->end_turn('Está arriba, listo para salir. ¿Confirmás el envío?'));

        list($conversation, $segundo) = $this->turno($conversation, 'mandalo');

        $this->assertSame($antes + 1, $this->pedidos_al_modelo(), 'Sin reintento.');
        $this->assertStringContainsString('¿Confirmás el envío?', $segundo->contenido);
    }

    // ---------------------------------------------------------------------
    /**
     * 🔴 Revisión independiente (20/10/2026): «envío» como flete de una venta que SÍ tiene su tarjeta, y un
     * dueño que no pidió mandar nada: ni reintento ni texto honesto.
     *
     * @test
     */
    public function un_envio_que_es_un_flete_o_un_dueno_que_no_pidio_mandar_no_se_toca()
    {
        $this->el_modelo_contesta($this->end_turn('Armé la venta a Marcos con envío a domicilio de $2.000. ¿Confirmás?'));

        list($conversation, $venta) = $this->turno(null, 'anotame una venta a marcos con flete');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertStringContainsString('envío a domicilio', $venta->contenido);

        $this->el_modelo_contesta($this->end_turn('Presupuesto N° 900006 por $130.436,74. ¿Se lo mando a Marcos?'));

        list($conversation, $presupuesto) = $this->turno($conversation, 'armame un presupuesto de 3 duchas');

        $this->assertSame(2, $this->pedidos_al_modelo(), 'El dueño no pidió mandar: es una oferta.');
        $this->assertStringContainsString('Presupuesto N° 900006', $presupuesto->contenido);
        $this->assertStringContainsString('¿Se lo mando a Marcos?', $presupuesto->contenido);
    }

    /**
     * Un ofrecimiento de mandarle algo AL DUEÑO («te lo mande») no es un envío a clientes, aunque el dueño
     * haya hablado de mandar.
     *
     * @test
     */
    public function un_ofrecimiento_al_duenio_no_se_toca()
    {
        $this->el_modelo_contesta($this->end_turn('Vendiste 3 cajas. ¿Querés que te lo mande también por mail?'));

        list($conversation, $asistente) = $this->turno(null, 'mandame el resumen de ayer');

        $this->assertSame(1, $this->pedidos_al_modelo());
        $this->assertStringContainsString('¿Querés que te lo mande también por mail?', $asistente->contenido);
    }

    /**
     * El turno del «sí»: el modelo confirma el envío y después ofrece otro canal. Ni reintento ni el texto
     * honesto («todavía no armé el envío») después de un envío que SALIÓ.
     *
     * @test
     */
    public function el_turno_del_si_con_un_ofrecimiento_despues_de_confirmar_no_se_toca()
    {
        $this->el_modelo_contesta(
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '20% en todo el local']),
            $this->end_turn('Te lo dejo armado.')
        );

        list($conversation, $propuso) = $this->turno(null, 'mandales una promo del 20%');

        $tarjeta = AiMessageAction::where('ai_message_id', $propuso->id)->firstOrFail();

        $antes = $this->pedidos_al_modelo();

        $this->el_modelo_contesta(
            $this->tool_use('confirmar_carga_pendiente', ['tarjeta_id' => $tarjeta->id]),
            $this->end_turn('Listo, salió por WhatsApp. ¿Querés que se lo mande también por mail?')
        );

        list($conversation, $al_si) = $this->turno($conversation, 'sí, mandalo');

        $this->assertSame($antes + 2, $this->pedidos_al_modelo(), 'Sin reintento.');
        $this->assertSame('Listo, salió por WhatsApp. ¿Querés que se lo mande también por mail?', $al_si->contenido);
    }

    /**
     * Si el reintento tampoco propone, se conserva lo que el modelo dijo de lo que SÍ hizo (el número y el
     * total del presupuesto) y se le agrega el texto honesto.
     *
     * @test
     */
    public function el_texto_honesto_conserva_lo_que_si_se_hizo()
    {
        $this->el_modelo_contesta(
            $this->end_turn('Presupuesto N° 900006 por $130.436,74. Lo mando por WhatsApp y por mail a Marcos. ¿Confirmás el envío?'),
            $this->end_turn('Presupuesto N° 900006 por $130.436,74. Lo mando por WhatsApp y por mail a Marcos. ¿Confirmás el envío?')
        );

        list($conversation, $asistente) = $this->turno(null, 'mandaselo por mail y por whatsapp');

        $this->assertStringStartsWith('Presupuesto N° 900006 por $130.436,74.', $asistente->contenido);
        $this->assertStringEndsWith(EnvioSinPropuestaIaHelper::TEXTO_HONESTO, $asistente->contenido);
        $this->assertStringNotContainsString('Confirmás el envío', $asistente->contenido);
        $this->assertStringNotContainsString('Lo mando por WhatsApp', $asistente->contenido);
    }

    /**
     * Si el modelo llamó a una herramienta de envío y ésta contestó que faltan datos, el texto del modelo
     * (que cuenta por qué) no se toca.
     *
     * @test
     */
    public function una_propuesta_que_fallo_deja_el_texto_del_modelo()
    {
        $this->el_modelo_contesta(
            $this->tool_use('proponer_promocion_por_whatsapp', ['texto' => '']),
            $this->end_turn('Me falta el texto de la promo. ¿Se la mando con el texto «20% en todo»?')
        );

        list($conversation, $asistente) = $this->turno(null, 'mandales una promo');

        $this->assertStringContainsString('Me falta el texto de la promo.', $asistente->contenido);
        $this->assertStringNotContainsString(EnvioSinPropuestaIaHelper::TEXTO_HONESTO, $asistente->contenido);
    }

    // El detector
    // ---------------------------------------------------------------------

    /**
     * @test
     */
    public function el_detector_pide_confirmar_estas_frases()
    {
        $si = [
            self::PREGUNTA_VACIA,
            '¿Se lo mando?',
            'Armé todo. ¿Lo mando por mail?',
            '¿Querés que se lo mande?',
            'Contestame «sí» para mandarlo.',
            'Voy a mandar el presupuesto a Marcos por mail y WhatsApp. ¿Dale?',
            '¿Confirmás que se lo mande?',
            'Confirmame el envío y lo mando.',
            "Te armo el envío del presupuesto 900006.\n\n¿Confirmás el envío?",
            '¿Le mando el presupuesto a Marcos?',
            '¿Les mando el recordatorio a los 12?',
            '¿Querés que le mande el presupuesto a Marcos por mail y por WhatsApp?',
            'Lo dejo listo para mandar. ¿Confirmo?',
            'Dale, se lo mando. Confirmame y sale.',
            'Escribí sí para enviarlo.',
        ];

        foreach ($si as $texto) {
            $this->assertTrue(EnvioSinPropuestaIaHelper::pide_confirmar_un_envio($texto), $texto);
        }
    }

    /**
     * @test
     */
    public function el_detector_deja_pasar_estas_frases()
    {
        $no = [
            'Ya mandé el presupuesto por WhatsApp. El mail queda saliendo.',
            '¿A quién se lo mando?',
            '¿Por mail o por WhatsApp lo mando?',
            'Te confirmo que el envío salió.',
            'Presupuesto N° 900006 por $130.436,74. ¿Querés que arme otro?',
            'El envío quedó confirmado.',
            '¿Algo más?',
            'Avisame si querés que lo mande.',
            'Si querés, se lo mando por WhatsApp.',
            '',
        ];

        foreach ($no as $texto) {
            $this->assertFalse(EnvioSinPropuestaIaHelper::pide_confirmar_un_envio($texto), $texto);
        }
    }
}
