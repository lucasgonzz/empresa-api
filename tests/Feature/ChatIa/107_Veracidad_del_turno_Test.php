<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CargaSinPropuestaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\ExpenseConcept;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-truvari-pedidos-fallidos (13/11/2026) — la veracidad del turno.
 *
 * 🔴 EL CASO REAL. Truvari Bebidas (Fernando, user 1000), conversación 7 del 12/11/2026, por WhatsApp,
 * con DeepSeek Flash. Los textos de los tests son los que leyó el dueño, tal cual:
 *   - A1 y A3: «Si» → «Quedó en 9 unidades el Nieto Senetiner Brut Nature.» y «Dejé el stock del Aceite de
 *     Oliva Familia Zuccardi Coratina 500 ml en 6 unidades.» sin ninguna carga hecha;
 *   - A2: «El aceite … pasa a 6 unidades de stock. ¿Lo cargo?» y «Dejo el Nieto Senetiner Brut Nature en 9
 *     unidades. ¿Lo cargo?» sin ninguna propuesta: el «Si» no tenía nada que registrar;
 *
 * 🔴 Nada sale a la red: el modelo es un Http::fake con las respuestas en una secuencia que, agotada,
 * contesta un 500 (así una llamada de más queda grabada y el conteo la ve).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Veracidad_del_turno_Test extends TestCase
{
    use DatabaseTransactions;

    /** Los textos reales de Truvari. */
    const QUEDO_EN_9 = 'Quedó en 9 unidades el Nieto Senetiner Brut Nature.';

    const DEJE_EL_STOCK = 'Dejé el stock del Aceite de Oliva Familia Zuccardi Coratina 500 ml en 6 unidades. Puede ser que se cargaran con el alta o por una reposición posterior a la consulta; ahora coincide con lo que ves.';

    const LO_CARGO = 'Dejo el Nieto Senetiner Brut Nature en 9 unidades. ¿Lo cargo?';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => 'clave-anthropic-de-prueba-p107',
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p107',
            'services.deepseek.model'          => 'deepseek-general-p107',
            'services.deepseek.model_agil'     => 'deepseek-flash-p107',
            'services.deepseek.model_profundo' => 'deepseek-pro-p107',
            'services.deepseek.model_vision'   => 'deepseek-vision-p107',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio veracidad P107',
            'company_name' => 'Bebidas P107',
            'email'        => 'veracidad-p107-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La configuración del caso real: DeepSeek ágil y el dueño en "resuelto" (las cargas esperan el sí). */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Conversación con las vueltas de antes ya contestadas, el pedido nuevo y el assistant pendiente.
     *
     * @param  array<int, array{0: string, 1: string}>  $antes  [pedido, respuesta]
     * @param  string  $pedido
     * @param  string|null  $canal  null = el panel del sistema.
     * @param  bool  $con_acciones
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion(array $antes, $pedido, $canal = AiMessage::CANAL_WHATSAPP, $con_acciones = true)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $extra = is_null($canal) ? [] : ['canal' => $canal];

        foreach ($antes as $par) {
            AiMessage::create(array_merge(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $par[0], 'estado' => 'listo'], $extra));
            AiMessage::create(array_merge(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $par[1], 'estado' => 'listo', 'acciones_habilitadas' => $con_acciones], $extra));
        }

        AiMessage::create(array_merge(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido, 'estado' => 'listo'], $extra));

        $assistant = AiMessage::create(array_merge([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => $con_acciones,
        ], $extra));

        return [$conversation, $assistant];
    }

    protected function subcategoria()
    {
        return ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->comercio->id)->max('num') + 1,
            'name'    => 'Alquiler P107',
            'user_id' => $this->comercio->id,
        ]);
    }

    protected function efectivo()
    {
        $metodo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->whereNull('c_a_payment_method_type_id')->where('id', '!=', 1)->first();

        return is_null($metodo) ? CurrentAcountPaymentMethod::create(['name' => 'Efectivo']) : $metodo;
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

    protected function tool_use($nombre, array $input, $id = null)
    {
        return $this->respuesta('tool_use', [[
            'type'  => 'tool_use',
            'id'    => is_null($id) ? 'toolu_p107_' . uniqid() : $id,
            'name'  => $nombre,
            'input' => $input,
        ]]);
    }

    protected function secuencia()
    {
        return Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P107)']], 500));
    }

    protected function el_modelo_contesta(array $respuestas)
    {
        $secuencia = $this->secuencia();

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

    protected function ultimo_turno_como_texto(array $body)
    {
        $ultimo = $body['messages'][count($body['messages']) - 1];

        if (is_string($ultimo['content'])) {
            return $ultimo['content'];
        }

        $textos = [];

        foreach ($ultimo['content'] as $bloque) {
            if (($bloque['type'] ?? '') === 'text') {
                $textos[] = $bloque['text'];
            }

            if (($bloque['type'] ?? '') === 'tool_result') {
                $textos[] = is_string($bloque['content']) ? $bloque['content'] : json_encode($bloque['content']);
            }
        }

        return implode("\n", $textos);
    }

    // ---------------------------------------------------------------------
    // A1 — la carga dada por hecha, por texto y para TODA carga
    // ---------------------------------------------------------------------

    /**
     * 🔴 Mensajes 162-164: «¿Lo cargo?» sin propuesta, el dueño dice «Si» y el modelo inventa «Quedó en 9
     * unidades». Antes, por WhatsApp, la guarda solo miraba los pagos. Ahora reintenta con la nota de la
     * confirmación por texto y, si el modelo insiste, la mentira no llega: va lo hecho (nada) y el «seguí».
     *
     * @test
     */
    public function por_whatsapp_un_si_y_quedo_en_9_unidades_sin_carga_reintenta_y_la_mentira_no_llega()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::QUEDO_EN_9),
            $this->end_turn(self::QUEDO_EN_9),
        ]);

        list($conversation, $assistant) = $this->conversacion([
            ['Pone que hay 9', self::LO_CARGO],
        ], 'Si');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('Quedó en 9', $texto);
        $this->assertStringContainsString('No llegué a terminar', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'La respuesta y un solo reintento.');

        $nota = $this->ultimo_turno_como_texto($bodies[1]);

        $this->assertStringContainsString('[El sistema revisó tu respuesta: dice que la carga ya quedó hecha', $nota);
        $this->assertStringContainsString('esa propuesta no existía', $nota, 'Por texto, la nota dice que el sí era a una propuesta que no existía.');
        $this->assertStringNotContainsString('tarjeta para que la persona la confirme', $nota);
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[1]['model'], 'El reintento de una carga va al Profundo.');
    }

    /**
     * 🔴 Mensajes 157-158: el sí no viene solo («Sí, tal cual. Eh, sí, de todas maneras me fijé…»). Empieza
     * con un sí y alcanza. Si el reintento ya no afirma la carga, la persona lee el reintento.
     *
     * @test
     */
    public function por_whatsapp_un_si_al_empezar_el_mensaje_tambien_cuenta_y_el_reintento_queda()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::DEJE_EL_STOCK),
            $this->end_turn('Todavía no cargué el stock del aceite: me falta confirmar el depósito.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([
            ['Ponele 6 unidades al aceite Coratina', 'Entendido: todo cambio de stock va al único depósito. ¿Confirmás?'],
        ], 'Sí, tal cual. Eh, sí, de todas maneras me fijé y hay seis unidades de stock de ese aceite');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Todavía no cargué el stock del aceite: me falta confirmar el depósito.', $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * Lo que no es un sí no prende la guarda de la confirmación por texto: «Si la presentación es 6» es un
     * condicional. Y en el panel la guarda de siempre (la del sistema) sigue igual.
     *
     * @test
     */
    public function un_condicional_que_empieza_con_si_no_es_un_si()
    {
        $this->el_modelo_contesta([
            $this->end_turn('Perfecto, con 6 botellas por caja.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Si la presentación es 6');

        $this->assertSame('Perfecto, con 6 botellas por caja.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(1, $this->bodies_enviados());
    }

    /**
     * A3: el detector reconoce las formas de Truvari («quedó en N», «dejé … en N», «pasa a N», «corregí /
     * corrijo») con las excepciones de siempre (la pregunta, el repaso, la negación, el condicional).
     *
     * @test
     */
    public function el_detector_reconoce_quedo_en_n_deje_en_n_pasa_a_n_y_corregi()
    {
        $afirman = [
            self::QUEDO_EN_9,
            'Quedó el NIETO SENETINER BRUT NATURE en 9 unidades.',
            self::DEJE_EL_STOCK,
            'Corregí el presupuesto 25 con 1 unidad en cada renglón, salvo Ricardo Santos Malbec con 2. Los precios bajan y el total queda en $331.000. ¿Lo registro?',
            'El aceite de oliva Familia Zuccardi Coratina 500 ml pasa a 6 unidades de stock.',
            'Listo, lo corregí.',
            'Ya está en 6 el aceite Coratina.',
            'Le puse el stock en 6.',
            'Puse el precio en $17.000.',
        ];

        foreach ($afirman as $texto) {
            $this->assertNotSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto), 'Tendría que afirmar: ' . $texto);
        }

        $no_afirman = [
            /* Con la pregunta al final no prometen: eso es de la guarda de la carga sin propuesta (A2). */
            'El aceite de oliva Familia Zuccardi Coratina 500 ml pasa a 6 unidades de stock. ¿Lo cargo?',
            self::LO_CARGO,
            'Corrijo el presupuesto 25 con cantidad 1 en los siete renglones, y dos en Ricardo Santos Malbec. ¿Lo dejo así?',
            'Hay 3 unidades de Nieto Senetiner Brut Nature, a $70.000.',
            'Tenés razón: el artículo sigue en 3 unidades. La carga de las 9 no quedó hecha.',
            'Si vendés 3 más, el stock queda en 0.',
            'No quedó en 9: sigue en 3.',
            '¿Querés que lo deje en 9?',
            'Ayer quedó en 9 unidades.',
            'Te dejé el detalle abajo.',
        ];

        foreach ($no_afirman as $texto) {
            $this->assertSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto), 'No tendría que afirmar: ' . $texto);
        }
    }

    // ---------------------------------------------------------------------
    // A2 — la carga que se pide confirmar tiene que estar propuesta
    // ---------------------------------------------------------------------

    /**
     * 🔴 «¿Lo cargo?» sin propuesta: un reintento con la nota, el reintento llama a la proponer_ y la
     * persona lee la pregunta con la carga esperando el sí de verdad.
     *
     * @test
     */
    public function por_whatsapp_lo_cargo_sin_propuesta_reintenta_y_la_propuesta_queda()
    {
        $alquiler = $this->subcategoria();

        $this->el_modelo_contesta([
            $this->end_turn('El alquiler de $400.000 en efectivo. ¿Lo cargo?'),
            $this->tool_use('proponer_gasto', [
                'subcategoria_id' => $alquiler->id,
                'monto'           => 400000,
                'pagos'           => [['metodo_de_pago_id' => $this->efectivo()->id]],
            ]),
            $this->end_turn('Gasto de alquiler por $400.000 en efectivo. ¿Lo registro?'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'El alquiler de 400000 se paga hoy en efectivo');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Gasto de alquiler por $400.000 en efectivo. ¿Lo registro?', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertStringContainsString('NO armaste ninguna propuesta', $this->ultimo_turno_como_texto($bodies[1]));
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[1]['model']);

        $acciones = AiMessageAction::where('ai_message_id', $assistant->id)->get();

        $this->assertCount(1, $acciones);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $acciones[0]->estado_guardado(), 'Queda esperando el sí: en "resuelto" no se ejecuta sola.');
    }

    /**
     * Si el reintento tampoco la propone, la pregunta vacía no llega: se saca y va el aviso honesto.
     *
     * @test
     */
    public function si_el_reintento_tampoco_propone_se_saca_la_pregunta_y_va_el_aviso()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::LO_CARGO),
            $this->end_turn(self::LO_CARGO),
        ]);

        list($conversation, $assistant) = $this->conversacion([
            ['¿Cuántas unidades hay del Nieto Senetiner Brut Nature?', 'Hay 3 unidades de Nieto Senetiner Brut Nature, a $70.000.'],
        ], 'Pone que hay 9');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('¿Lo cargo?', $texto);
        $this->assertStringContainsString(CargaSinPropuestaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertStringStartsWith('Dejo el Nieto Senetiner Brut Nature en 9 unidades.', $texto, 'Lo demás que dijo el modelo queda.');
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * No molesta: en el panel (ahí está la guarda de la tarjeta), con una pregunta por un dato, y con una
     * carga esperando de la respuesta anterior (la pregunta puede ser de ésa).
     *
     * @test
     */
    public function no_dispara_en_el_panel_con_una_pregunta_por_un_dato_ni_con_una_carga_esperando_de_antes()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::LO_CARGO),
            $this->end_turn('¿Con qué fecha lo cargo?'),
            $this->end_turn(self::LO_CARGO),
        ]);

        /* El panel del sistema. */
        list($conversation, $assistant) = $this->conversacion([], 'Pone que hay 9', null);
        $this->assertSame(self::LO_CARGO, (new AsistenteIaService())->responder($conversation, $assistant));

        /* Una pregunta por un dato. */
        list($conversation, $assistant) = $this->conversacion([], 'Cargame el alquiler');
        $this->assertSame('¿Con qué fecha lo cargo?', (new AsistenteIaService())->responder($conversation, $assistant));

        /* Una carga esperando de la respuesta anterior. */
        list($conversation, $assistant) = $this->conversacion([['Pone que hay 9', 'Te lo preparo.']], 'Bueno');

        $anterior = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'assistant')->where('estado', 'listo')->firstOrFail();

        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $anterior->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => 'gasto',
            'clave'              => 'gasto:p107',
            'estado'             => 'propuesta',
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
        ]);

        $this->assertSame(self::LO_CARGO, (new AsistenteIaService())->responder($conversation, $assistant));

        $this->assertCount(3, $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    /**
     * El detector de la pregunta de confirmación, con los textos de Truvari.
     *
     * @test
     */
    public function el_detector_de_la_pregunta_de_confirmacion()
    {
        $piden = [
            "Anoto la corrección: responder más rápido.\nEl aceite de oliva Familia Zuccardi Coratina 500 ml pasa a 6 unidades de stock. ¿Lo cargo?",
            'Me lo anoto, y ahora cargo las 6 unidades del aceite Coratina. ¿Confirmás?',
            self::LO_CARGO,
            "Con eso queda: una caja de Perro Callejero Malbec, para Adriana Orietta Bonzano, guardado como presupuesto y sin facturar.\n¿Lo preparo así?",
            "Borro los presupuestos N° 25 y N° 26 de Adriana Orietta Bonzano.\n¿Los elimino?",
            'Gasto de $15.000 en efectivo. ¿Querés que lo registre así?',
        ];

        foreach ($piden as $texto) {
            $this->assertTrue(CargaSinPropuestaIaHelper::pide_confirmar_una_carga($texto), 'Tendría que pedir confirmar: ' . $texto);
        }

        $no_piden = [
            '¿Con qué fecha lo cargo?',
            '¿La dejo así, con esos cuatro horarios, o preferís cuatro tareas separadas?',
            '¿Me confirmás que el producto en la tienda figura exactamente como Nieto Senetiner Brut Nature?',
            '¿Querés que deje pedida a soporte la habilitación de estos campos?',
            'Hay 3 unidades. ¿Querés ver el detalle?',
            '¿Se lo mando por WhatsApp?',
            'Lo cargo apenas me digas el depósito.',
            /* Sin ningún dato de la carga es ofrecerla, no confirmarla (AsistenteWhatsapp/13). */
            'Es un aceite. ¿Lo cargo?',
            '¿Querés que lo registre así?',
        ];

        foreach ($no_piden as $texto) {
            $this->assertFalse(CargaSinPropuestaIaHelper::pide_confirmar_una_carga($texto), 'No tendría que pedir confirmar: ' . $texto);
        }
    }
}
