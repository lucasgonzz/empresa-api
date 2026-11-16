<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\AnotacionPrometidaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDelDuenoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\CargaSinPropuestaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionDeterministaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\SiQueRearmaLaCargaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TextoFinalIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-mejoras-4-dias (16/11/2026), P4 — una sola confirmación y texto limpio.
 *
 * 🔴 LOS CASOS REALES (Truvari Bebidas, 13/11/2026, por WhatsApp, ya en 4.3.59). Los textos son los que leyó el
 * dueño, tal cual:
 *   - V5: «Voy a prender también el aviso de artículos sin stock… ¿Lo hago?» (227) sin propuesta; «Si» (228) →
 *     «Confirmame para activarlo.» (229); «Si  te confirmo» (230) → «No tengo armada la propuesta» (231); el
 *     reclamo por audio (232) → recién la tarjeta, y otra vez «¿Lo hago?» (233). Cuatro sí para un aviso.
 *   - V6: «<system>El usuario aclara el criterio… Confirmo.</system>» llegó a WhatsApp (259).
 *   - V22: «decime cuál es el precio por botella del gran enemigo» (300) → «Me lo anoto.» (301), y nada más.
 *   - W2: la carga ya quedó hecha en el turno y el texto igual pregunta «¿Lo hago?».
 *
 * 🔴 Nada sale a la red: el modelo es un Http::fake con las respuestas en una secuencia que, agotada, contesta un
 * 500 (una llamada de más queda grabada y el conteo la ve).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class P140_Una_sola_confirmacion_y_texto_limpio_Test extends TestCase
{
    use DatabaseTransactions;

    /** Mensaje 227 de Truvari, tal cual. */
    const LO_HAGO = 'Voy a prender también el aviso de artículos sin stock: te llega un mensaje cuando un artículo queda en cero o menos, uno por artículo y por día. ¿Lo hago?';

    /** Mensaje 259 de Truvari, tal cual. */
    const CON_SYSTEM = "<system>El usuario aclara el criterio: el precio cargado es por caja y hay que dividirlo por la presentación. En el caso del Rutini, 89.000 / 6 = 14.833,33 por botella. Confirmo.</system>\n\nConfirmado: \$89.000 dividido 6 da *\$14.833,33 por botella*.\n\nEntonces el criterio es al revés de lo que yo dije antes.";

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => 'clave-anthropic-de-prueba-p113',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p113',
            'services.deepseek.model'          => 'deepseek-general-p113',
            'services.deepseek.model_agil'     => 'deepseek-flash-p113',
            'services.deepseek.model_profundo' => 'deepseek-pro-p113',
            'services.deepseek.model_vision'   => 'deepseek-vision-p113',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio una confirmación P113',
            'company_name' => 'Bebidas P113',
            'email'        => 'una-confirmacion-p113-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* Truvari es de antes del 17/9/2026: quedó con el default de entonces, "resuelto". */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * @param  array<int, array{0: string, 1: string}>  $antes  [pedido, respuesta]
     * @param  string  $pedido
     * @param  string  $canal
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion(array $antes, $pedido, $canal = AiMessage::CANAL_WHATSAPP)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        foreach ($antes as $par) {
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $par[0], 'estado' => 'listo', 'canal' => $canal]);
            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $par[1], 'estado' => 'listo', 'acciones_habilitadas' => true, 'canal' => $canal]);
        }

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo', 'canal' => $canal]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
            'canal'                => $canal,
        ]);

        return [$conversation, $assistant];
    }

    protected function respuesta($stop_reason, array $bloques)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    protected function end_turn($texto)
    {
        return $this->respuesta('end_turn', [['type' => 'text', 'text' => $texto]]);
    }

    protected function tool_use($nombre, array $input)
    {
        return $this->respuesta('tool_use', [[
            'type'  => 'tool_use',
            'id'    => 'toolu_p113_' . uniqid(),
            'name'  => $nombre,
            'input' => $input,
        ]]);
    }

    protected function el_modelo_contesta(array $respuestas)
    {
        $secuencia = Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P113)']], 500));

        foreach ($respuestas as $respuesta) {
            $secuencia->push($respuesta, 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);
    }

    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $bodies[] = json_decode($par[0]->body(), true);
            }
        }

        return $bodies;
    }

    /** El texto del primer turno 'user' de abajo para arriba (donde va la nota pegada al sí). */
    protected function ultimo_user_como_texto(array $body)
    {
        for ($i = count($body['messages']) - 1; $i >= 0; $i--) {

            $turno = $body['messages'][$i];

            if ($turno['role'] !== 'user') {
                continue;
            }

            if (is_string($turno['content'])) {
                return $turno['content'];
            }

            $textos = [];

            foreach ($turno['content'] as $bloque) {
                if (($bloque['type'] ?? '') === 'text') {
                    $textos[] = $bloque['text'];
                }
            }

            return implode("\n", $textos);
        }

        return '';
    }

    protected function acciones(AiMessage $assistant)
    {
        return AiMessageAction::where('ai_message_id', $assistant->id)->get();
    }

    // ---------------------------------------------------------------------
    // V6 — los bloques internos no le llegan a nadie
    // ---------------------------------------------------------------------

    /**
     * El saneo saca el bloque <system> del caso real y deja la respuesta; un texto sin bloques vuelve byte por byte.
     *
     * @test
     */
    public function el_saneo_saca_los_bloques_internos_y_deja_la_respuesta()
    {
        $limpio = TextoFinalIaHelper::limpiar(self::CON_SYSTEM, ['confirmar_carga_pendiente']);

        $this->assertStringNotContainsString('<system>', $limpio);
        $this->assertStringNotContainsString('El usuario aclara el criterio', $limpio);
        $this->assertStringStartsWith('Confirmado: $89.000 dividido 6', $limpio);
        $this->assertStringContainsString('Entonces el criterio es al revés', $limpio);

        /* Otras etiquetas, mayúsculas, varios renglones, una que no cierra y un cierre huérfano. */
        $this->assertSame('Hay 3 unidades.', TextoFinalIaHelper::sin_bloques_internos("<thinking>\nel stock es 3\nlo digo\n</thinking>\nHay 3 unidades."));
        $this->assertSame('Hay 3 unidades.', TextoFinalIaHelper::sin_bloques_internos("<SYSTEM>nota interna</SYSTEM> Hay 3 unidades."));
        /*
         * Una etiqueta que abre y no cierra: se saca SOLO la etiqueta (revisión independiente de la rama B; la primera
         * versión se llevaba el párrafo, y con él podía irse la respuesta).
         */
        $this->assertSame('Hay 3 unidades.', TextoFinalIaHelper::sin_bloques_internos('<think>Hay 3 unidades.'));
        $this->assertSame("Hay 3 unidades.\n\nY 2 en el depósito.", TextoFinalIaHelper::sin_bloques_internos("<system>Hay 3 unidades.\n\nY 2 en el depósito."));

        /* Sin el doble espacio donde estaba el bloque. */
        $this->assertSame('Listo, quedó así.', TextoFinalIaHelper::sin_bloques_internos('Listo, <system>nota</system> quedó así.'));
        $this->assertSame('Hay 3 unidades.', TextoFinalIaHelper::sin_bloques_internos("Hay 3 unidades.</system>"));

        /* Lo que no es una etiqueta interna no se toca. */
        foreach (['Hay 3 unidades.', 'El precio es < $5.000 y > $3.000.', 'Vino <Reserva> 750 ml: 3 u.'] as $texto) {
            $this->assertSame($texto, TextoFinalIaHelper::sin_bloques_internos($texto));
            $this->assertSame($texto, TextoFinalIaHelper::limpiar($texto, []));
        }
    }

    /**
     * De punta a punta, por WhatsApp y por el panel: el dueño no lee el bloque <system>. Y si el modelo escribió
     * SOLO el bloque, el respaldo de «el texto original» tampoco lo manda.
     *
     * @test
     */
    public function el_bloque_system_no_llega_por_ningun_canal()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::CON_SYSTEM),
            $this->end_turn(self::CON_SYSTEM),
        ]);

        foreach ([AiMessage::CANAL_WHATSAPP, AiMessage::CANAL_SISTEMA] as $canal) {

            list($conversation, $assistant) = $this->conversacion([], 'Que es exactamente los 14.833,33 que me envió', $canal);

            $texto = (new AsistenteIaService())->responder($conversation, $assistant);

            $this->assertStringNotContainsString('<system>', $texto, $canal);
            $this->assertStringNotContainsString('El usuario aclara el criterio', $texto, $canal);
            $this->assertStringContainsString('14.833,33 por botella', $texto, $canal);
        }
    }

    // ---------------------------------------------------------------------
    // V5 — el pedido de confirmación sin propuesta, también sin números
    // ---------------------------------------------------------------------

    /**
     * La guarda de la carga sin propuesta ve el «¿Lo hago?» de 227 (sin un solo número, pero anunciado con «Voy a
     * prender») y el «Confirmame para activarlo.» de 229 (sin signo de pregunta). Un pedido de dato no.
     *
     * @test
     */
    public function la_guarda_ve_el_lo_hago_sin_numeros_y_el_confirmame()
    {
        $this->assertTrue(CargaSinPropuestaIaHelper::pide_confirmar_una_carga(self::LO_HAGO));
        $this->assertTrue(CargaSinPropuestaIaHelper::pide_confirmar_una_carga('Voy a prender el aviso de artículos sin stock. Confirmame para activarlo.'));
        $this->assertTrue(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('Voy a prender el aviso de artículos sin stock. Confirmame para activarlo.'));

        $this->assertFalse(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('Confirmame el nombre del cliente.'));
        $this->assertFalse(CargaSinPropuestaIaHelper::pide_confirmar_una_carga('Es un aceite. ¿Lo cargo?'), 'Sin datos ni anuncio sigue siendo una oferta.');

        /* «Si  te confirmo» (230) es un sí; «Encendelo» (226) no: puede pedir otra cosa que la tarjeta. */
        $this->assertTrue(ConfirmacionDeterministaIaHelper::es_afirmacion('Si  te confirmo'));
        $this->assertFalse(ConfirmacionDeterministaIaHelper::es_afirmacion('Encendelo'));
    }

    // ---------------------------------------------------------------------
    // V5 — un solo sí: arma la carga y, si se puede, la deja hecha
    // ---------------------------------------------------------------------

    /**
     * 🔴 EL CASO REAL, como tendría que haber sido: al «Si» del 228 el sistema le pega la nota al sí, el modelo
     * arma los avisos y quedan PRENDIDOS con ese sí (un aviso del dueño se deshace con otro igual), sin otra
     * pregunta. Y si el texto igual cierra con «¿Lo hago?», esa pregunta no llega (W2).
     *
     * @test
     */
    public function el_si_a_una_pregunta_sin_propuesta_arma_el_aviso_y_lo_deja_prendido()
    {
        $this->el_modelo_contesta([
            $this->tool_use('proponer_avisos_por_whatsapp', ['stock_cero' => true]),
            $this->end_turn('Listo: quedó prendido el aviso de artículos sin stock. ¿Lo hago?'),
        ]);

        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si');

        $this->assertTrue(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant));

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $acciones = $this->acciones($assistant);
        $this->assertCount(1, $acciones);
        $this->assertSame(AiMessageAction::TIPO_AVISOS_DEL_DUENO, $acciones[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $acciones[0]->estado_guardado(), 'Con un solo sí queda hecho.');
        $this->assertTrue(AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['stock_cero']);

        $this->assertSame('Listo: quedó prendido el aviso de artículos sin stock.', $texto, 'Lo hecho no se vuelve a preguntar.');

        $bodies = $this->bodies_enviados();
        $this->assertStringContainsString('te contestó que SÍ a lo que le preguntaste', $this->ultimo_user_como_texto($bodies[0]));
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[0]['model'], 'Rearmar una carga es decidir una carga: arranca escalado.');
    }

    /**
     * En "cauteloso" el sí a un texto no ejecuta nada: el modelo arma la propuesta (sin volver a pedir datos) y
     * queda esperando el sí sobre lo que se va a registrar, como eligió ese dueño.
     *
     * @test
     */
    public function en_cauteloso_el_si_arma_la_propuesta_pero_no_la_ejecuta()
    {
        DB::table('users')->where('id', $this->comercio->id)->update(['agente_confianza' => 'cauteloso']);

        $this->el_modelo_contesta([
            $this->tool_use('proponer_avisos_por_whatsapp', ['stock_cero' => true]),
            $this->end_turn('Dejé listo el aviso de artículos sin stock. ¿Lo prendo?'),
        ]);

        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $acciones = $this->acciones($assistant);
        $this->assertCount(1, $acciones);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $acciones[0]->estado_guardado());
        $this->assertFalse(AvisosDelDuenoIaHelper::preferencias($this->comercio->fresh())['stock_cero']);
        $this->assertStringContainsString('¿Lo prendo?', $texto, 'Con la tarjeta esperando, la pregunta queda.');
    }

    /**
     * 🔴 Lo que toca PLATA no se registra con un sí a un texto: un gasto en "resuelto" queda esperando el sí
     * sobre los datos exactos, aunque la persona ya haya dicho que sí.
     *
     * @test
     */
    public function un_gasto_no_se_registra_con_el_si_a_un_texto()
    {
        $alquiler = ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->comercio->id)->max('num') + 1,
            'name'    => 'Alquiler P113',
            'user_id' => $this->comercio->id,
        ]);

        $efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->whereNull('c_a_payment_method_type_id')->where('id', '!=', 1)->first();
        $efectivo = is_null($efectivo) ? CurrentAcountPaymentMethod::create(['name' => 'Efectivo']) : $efectivo;

        $this->el_modelo_contesta([
            $this->tool_use('proponer_gasto', [
                'subcategoria_id' => $alquiler->id,
                'monto'           => 400000,
                'pagos'           => [['metodo_de_pago_id' => $efectivo->id]],
            ]),
            $this->end_turn('Gasto de alquiler por $400.000 en efectivo. ¿Lo registro?'),
        ]);

        list($conversation, $assistant) = $this->conversacion([['Cargá el alquiler', 'Voy a cargar el gasto de alquiler por $400.000 en efectivo. ¿Lo hago?']], 'Si');

        (new AsistenteIaService())->responder($conversation, $assistant);

        $acciones = $this->acciones($assistant);
        $this->assertCount(1, $acciones);
        $this->assertSame(AiMessageAction::TIPO_GASTO, $acciones[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $acciones[0]->estado_guardado(), 'La plata espera el sí sobre los datos exactos.');
    }

    /**
     * Cuándo NO aplica: si la respuesta anterior ya dejó la carga HECHA (volver a armarla la duplicaría), si el sí
     * no es un sí, por el panel (ahí está el botón) o si la pregunta es de hace más de media hora.
     *
     * @test
     */
    public function el_si_no_rearma_lo_ya_hecho_ni_fuera_de_whatsapp_ni_tarde()
    {
        /* La anterior dejó una carga confirmada. */
        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si');
        $anterior = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->where('id', '<', $assistant->id)->orderBy('id', 'DESC')->first();
        AiMessageAction::create([
            'ai_message_id'      => $anterior->id,
            'ai_conversation_id' => $conversation->id,
            'tipo'               => AiMessageAction::TIPO_AVISOS_DEL_DUENO,
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'clave'              => 'p113-' . uniqid(),
            'datos'              => [],
            'presentacion'       => [],
        ]);
        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant), 'Ya está hecha.');

        /* No es un sí. */
        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si la presentación es 6');
        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant));

        /* Por el panel. */
        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si', AiMessage::CANAL_SISTEMA);
        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant));

        /* La pregunta es de hace una hora. */
        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si');
        DB::table('ai_messages')->where('ai_conversation_id', $conversation->id)->where('id', '<', $assistant->id - 1)->update(['created_at' => now()->subHour()]);
        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant));

        /* Y un envío a clientes tiene su propio circuito. */
        list($conversation, $assistant) = $this->conversacion([['Mandale el comprobante', 'Le mando el comprobante de la venta 3101 a Osvaldo por WhatsApp. ¿Se lo mando?']], 'Si');
        $this->assertFalse(SiQueRearmaLaCargaIaHelper::aplica($conversation, $assistant));
    }

    /**
     * El sí ejecuta UNA carga: si en el turno se arma una segunda propuesta, ésa espera.
     *
     * @test
     */
    public function el_si_ejecuta_una_sola_carga()
    {
        list($conversation, $assistant) = $this->conversacion([['Encendelo', self::LO_HAGO]], 'Si');

        AiMessageAction::create([
            'ai_message_id'      => $assistant->id,
            'ai_conversation_id' => $conversation->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'clave'              => 'p113-' . uniqid(),
            'datos'              => [],
            'presentacion'       => [],
        ]);
        AiMessageAction::create([
            'ai_message_id'      => $assistant->id,
            'ai_conversation_id' => $conversation->id,
            'tipo'               => AiMessageAction::TIPO_AVISOS_DEL_DUENO,
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'clave'              => 'p113-' . uniqid(),
            'datos'              => [],
            'presentacion'       => [],
        ]);

        $this->assertFalse(SiQueRearmaLaCargaIaHelper::ejecuta($conversation, $assistant, AiMessageAction::TIPO_AVISOS_DEL_DUENO, 'resuelto'));
    }

    // ---------------------------------------------------------------------
    // V22 — «Me lo anoto.» y nada más
    // ---------------------------------------------------------------------

    /**
     * La respuesta que es solo la anotación, cuando la persona pidió o preguntó algo, dispara su guarda; si contestó
     * algo más, o si la persona solo contó algo, no.
     *
     * @test
     */
    public function me_lo_anoto_solo_no_alcanza_si_la_persona_pidio_algo()
    {
        $this->assertTrue(AnotacionPrometidaIaHelper::es_solo_la_anotacion('Me lo anoto.'));
        $this->assertFalse(AnotacionPrometidaIaHelper::es_solo_la_anotacion('Me lo anoto: la cepa va solo en los vinos.'));
        $this->assertFalse(AnotacionPrometidaIaHelper::es_solo_la_anotacion("Gran Enemigo Gualtallary: la botella sale \$45.666,67.\n\nMe lo anoto."));

        $this->assertTrue(AnotacionPrometidaIaHelper::pide_o_pregunta_algo('Bueno, entonces si ya lo anotaste y ya lo comprendiste, decime cuál es el precio por botella del gran enemigo'));
        $this->assertTrue(AnotacionPrometidaIaHelper::pide_o_pregunta_algo('Te pido que le borres la imagen porque no es correcto que tenga esa imagen'));
        $this->assertFalse(AnotacionPrometidaIaHelper::pide_o_pregunta_algo('Te aclaro que viene en 2 presentaciones: una en caja de cartón y otra en caja de madera'));

        /* Una PREFERENCIA de cómo trabajar no es un pedido sin contestar (V25): ahí «Me lo anoto.» alcanza. */
        foreach ([
            'Te pido que cada vez que cargues una venta me consultes si la quiero facturar',
            'Quiero que siempre me muestres la foto antes de cargarla',
            'Necesito que no me preguntes tres veces lo mismo',
            'Quiero que desde ahora uses la presentación para el precio por botella',
        ] as $preferencia) {
            $this->assertFalse(AnotacionPrometidaIaHelper::pide_o_pregunta_algo($preferencia), $preferencia);
        }

        $this->assertTrue(AnotacionPrometidaIaHelper::pide_o_pregunta_algo('Quiero que me borres la imagen del Killka'));

        list($conversation, $assistant) = $this->conversacion([], 'Bueno, entonces si ya lo anotaste y ya lo comprendiste, decime cuál es el precio por botella del gran enemigo');

        $dispara = new \ReflectionMethod(AsistenteIaService::class, 'dispara_la_guarda_de_veracidad');
        $dispara->setAccessible(true);

        $this->assertTrue($dispara->invoke(new AsistenteIaService(), 'solo_anotacion', 'Me lo anoto.', [], $conversation, $assistant, [], true));
        $this->assertFalse($dispara->invoke(new AsistenteIaService(), 'solo_anotacion', 'El Gran Enemigo está a $274.000 la caja de 6. Me lo anoto.', [], $conversation, $assistant, [], true));
    }

    /**
     * De punta a punta: el reintento le pide que conteste lo pedido, y la persona lee la respuesta.
     *
     * @test
     */
    public function la_respuesta_que_es_solo_me_lo_anoto_se_reintenta()
    {
        $this->el_modelo_contesta([
            $this->end_turn('Me lo anoto.'),
            $this->end_turn('Gran Enemigo Gualtallary: la caja de 6 está a $274.000, así que la botella sale $45.666,67.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Bueno, entonces si ya lo anotaste y ya lo comprendiste, decime cuál es el precio por botella del gran enemigo');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringContainsString('$45.666,67', $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    // ---------------------------------------------------------------------
    // W2 — solo se saca la pregunta pelada (revisión independiente de la rama B)
    // ---------------------------------------------------------------------

    /**
     * Con la carga ya hecha en el turno, se saca «¿Lo hago?» pero NUNCA una pregunta que ofrece otra carga.
     *
     * @test
     */
    public function con_la_carga_hecha_se_saca_solo_la_pregunta_pelada()
    {
        $hecho = 'Listo, quedó cargada la venta 3100.';

        foreach (['¿Registro el pago de Pedro?', '¿Lo agendo para el viernes?', '¿Lo cargo a nombre de Juan?'] as $oferta) {
            $this->assertSame($hecho . ' ' . $oferta, CargaSinPropuestaIaHelper::sin_la_pregunta_de_confirmar($hecho . ' ' . $oferta), $oferta);
        }

        foreach (['¿Lo hago?', '¿Lo cargo?', '¿Confirmás?', '¿Lo dejo así?', '¿Me lo confirmás?'] as $pelada) {
            $this->assertSame($hecho, CargaSinPropuestaIaHelper::sin_la_pregunta_de_confirmar($hecho . ' ' . $pelada), $pelada);
        }
    }
}
