<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\CargaSinPropuestaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\FormatoDeWhatsappIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\PromesaDeAccionFuturaIaHelper;
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
use Illuminate\Support\Facades\Log;
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
 *   - A4: «Busco la publicación del Nieto Senetiner Brut Nature en la tienda y te paso el link.», «Reviso y
 *     te confirmo en un momento.», «Voy a revisar la consulta de ese artículo. Dame un momento.»: nunca
 *     volvió, porque no hay un después;
 *   - A6: «el loop terminó sin texto final en la iteración 3 (después de 2 rondas de herramientas)» → «No
 *     llegué a terminar…» con vueltas de sobra;
 *   - A8: «los asteriscos se ven crudos»: en WhatsApp *x* es negrita.
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

    const BUSCO_Y_TE_PASO = 'Busco la publicación del Nieto Senetiner Brut Nature en la tienda y te paso el link.';

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
     * confirmación por texto y, si el modelo insiste, la mentira no llega: se cambia esa oración por el aviso
     * (revisión independiente, D1: fuera de los pagos no se reemplaza toda la respuesta).
     *
     * @test
     */
    public function por_whatsapp_un_si_y_quedo_en_9_unidades_sin_carga_reintenta_y_la_mentira_no_llega()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::QUEDO_EN_9),
            $this->end_turn("En la tienda figuran 3.\n" . self::QUEDO_EN_9),
        ]);

        list($conversation, $assistant) = $this->conversacion([
            ['Pone que hay 9', self::LO_CARGO],
        ], 'Si');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("En la tienda figuran 3.\n\n" . TarjetaPrometidaIaHelper::AVISO_POR_TEXTO, $texto, 'Solo la oración que miente se cambia; lo demás queda.');

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
     * 🔴 D1 de la revisión independiente: un «Si» a una OFERTA de datos no es la confirmación de una carga.
     * Las consultas que contestan con números («quedaron en $3.200.000», «quedó en 0 después de la venta»)
     * llegan tal cual, sin reintento; y aunque la respuesta anterior sí hubiera pedido confirmar, la oración
     * de reporte no se toma por una carga.
     *
     * @test
     */
    public function por_whatsapp_un_si_a_una_oferta_de_datos_no_dispara_con_las_consultas()
    {
        $consultas = [
            ['¿Te paso las ventas de octubre?', 'Las ventas de octubre quedaron en $3.200.000, un 12% más que septiembre.'],
            ['¿Querés que te diga el total del presupuesto?', 'El total quedó en $45.000.'],
            ['¿Te cuento cómo quedó el stock?', 'El stock del Malbec quedó en 0 después de la venta de hoy.'],
            ['¿Te digo cuántas quedan?', 'Ya está en 6 unidades.'],
            /* Con la respuesta anterior pidiendo confirmar una carga, una oración de reporte tampoco cuenta. */
            ['Dejo el Malbec en 9 unidades. ¿Lo cargo?', 'Las ventas de octubre quedaron en $3.200.000, un 12% más que septiembre.'],
        ];

        $respuestas = [];

        foreach ($consultas as $par) {
            $respuestas[] = $this->end_turn($par[1]);
        }

        $this->el_modelo_contesta($respuestas);

        foreach ($consultas as $par) {

            list($conversation, $assistant) = $this->conversacion([['Contame', $par[0]]], 'Si');

            $this->assertSame($par[1], (new AsistenteIaService())->responder($conversation, $assistant), 'Llega tal cual: ' . $par[1]);
        }

        $this->assertCount(count($consultas), $this->bodies_enviados(), 'Ningún reintento.');
    }

    /**
     * Un pago dado por hecho por texto sigue como desde 9ea73bbc (lo cubre también 63): lo hecho, sin el modelo.
     *
     * @test
     */
    public function por_whatsapp_un_pago_dado_por_hecho_sigue_contestando_con_lo_hecho()
    {
        $this->el_modelo_contesta([
            $this->end_turn('Quedó registrado el pago al proveedor por $50.000 en efectivo.'),
            $this->end_turn('Quedó registrado el pago al proveedor por $50.000 en efectivo.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Sí');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertStringNotContainsString('Quedó registrado', $texto);
        $this->assertStringContainsString('No llegué a terminar', $texto);
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
            /* «Ya está en 6…» dejó de contar (D1 de la revisión: ante la duda, no dispara); el precio sí. */
            'El precio del Malbec quedó en $13.200.',
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
            /* D1 de la revisión independiente: reportes y cálculos con números no son cargas. */
            'El Malbec quedó en $12.000 después del aumento de ayer.',
            'El stock del Malbec quedó en 0 después de la venta de hoy.',
            'Pasa a $13.200.',
            'Con un 10% de aumento, el Malbec pasa a $13.200.',
            'Si le subís 10%, pasa a $13.200.',
            'Ya está en 6 unidades.',
            'La venta quedó en $45.000 con el descuento del 10%.',
            'Te dejé el resumen en 2 partes.',
            'El total quedó en $45.000.',
            'Hoy el dólar está en 1500.',
            'Las ventas de octubre quedaron en $3.200.000, un 12% más que septiembre.',
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
            /* D5 de la revisión independiente: ofertas de información o de un trabajo, y preguntas por un dato. */
            'Hoy vendiste $45.000 en 12 ventas. ¿Te paso el detalle?',
            'El Malbec tiene 3 unidades a $12.000. ¿Te armo un presupuesto?',
            'Para el alta del cliente con CUIT 20-1234. ¿Me confirmás el nombre?',
            'Tenés 3 vencimientos esta semana. ¿Te hago un resumen?',
            'Encontré 4 artículos. ¿Te bajo el Excel?',
            'Son 2 cajas. ¿Lo cargo en efectivo o con tarjeta?',
            'Tenés 2 presupuestos abiertos. ¿Cambio algo?',
            /* El dato tiene que estar en la pregunta o en la oración de antes, no en cualquier parte. */
            "Hoy vendiste $45.000.\nEl Malbec es de Aguma.\n¿Lo cargo?",
        ];

        foreach ($no_piden as $texto) {
            $this->assertFalse(CargaSinPropuestaIaHelper::pide_confirmar_una_carga($texto), 'No tendría que pedir confirmar: ' . $texto);
        }

        /* La respuesta anterior que pidió confirmar (D1), con o sin datos. */
        $this->assertTrue(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('¿La registro?'));
        $this->assertTrue(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar(self::LO_CARGO));
        $this->assertFalse(CargaSinPropuestaIaHelper::termina_pidiendo_confirmar('¿Te paso las ventas de octubre?'));
    }

    // ---------------------------------------------------------------------
    // A4 — no existe un «después»
    // ---------------------------------------------------------------------

    /**
     * 🔴 Mensaje 172: «Busco la publicación… y te paso el link.» y el turno terminó. Un reintento con la
     * nota («este mensaje es tu ÚNICA respuesta»), en cualquier canal y también sin acciones.
     *
     * @test
     */
    public function la_promesa_de_buscar_y_pasar_despues_reintenta_y_la_persona_lee_el_reintento()
    {
        $this->el_modelo_contesta([
            $this->end_turn(self::BUSCO_Y_TE_PASO),
            $this->end_turn('No tengo una consulta que me devuelva el link de la tienda de ese artículo.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Pasame el link de la tienda del Nieto Senetiner Brut Nature', AiMessage::CANAL_WHATSAPP, false);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('No tengo una consulta que me devuelva el link de la tienda de ese artículo.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertStringContainsString('este mensaje es tu ÚNICA respuesta', $this->ultimo_turno_como_texto($bodies[1]));
        $this->assertSame(self::BUSCO_Y_TE_PASO, $bodies[1]['messages'][count($bodies[1]['messages']) - 2]['content']);
    }

    /**
     * Si el reintento vuelve a prometer, la persona no lee la promesa: lo demás queda y va el aviso.
     * Corre también en el panel del sistema.
     *
     * @test
     */
    public function si_sigue_prometiendo_se_cambia_la_oracion_por_el_aviso_tambien_en_el_panel()
    {
        $prometido = "Hay 3 unidades del Nieto Senetiner Brut Nature.\nReviso cómo está publicado el artículo en la tienda y te confirmo si hay algo que lo esté frenando.";

        $this->el_modelo_contesta([
            $this->end_turn($prometido),
            $this->end_turn("Hay 3 unidades del Nieto Senetiner Brut Nature.\nReviso y te confirmo en un momento."),
        ]);

        list($conversation, $assistant) = $this->conversacion([], '¿Y por qué en la tienda figura otra cosa?', null);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Hay 3 unidades del Nieto Senetiner Brut Nature.\n\n" . PromesaDeAccionFuturaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * D2 de la revisión independiente: si el turno dejó algo que sí corre después (una tarea con su
     * recordatorio), «Dale, te aviso en un rato» no es una promesa vacía: sin reintento y tal cual. Sin la
     * tarea, la misma frase sí reintenta.
     *
     * @test
     */
    public function te_aviso_en_un_rato_con_una_tarea_del_turno_no_es_una_promesa_vacia()
    {
        $this->el_modelo_contesta([
            $this->tool_use('proponer_tarea', ['detalle' => 'ZZP107 revisar los pedidos de la tienda', 'fecha' => now()->addDay()->format('Y-m-d')]),
            $this->end_turn('Dale, te aviso en un rato.'),
            $this->end_turn('Dale, te aviso en un rato.'),
            $this->end_turn('Desde acá no te puedo escribir después: contestame cuando quieras y lo reviso en el momento.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Avisame cuando entren pedidos', AiMessage::CANAL_WHATSAPP, true);
        $this->assertSame('Dale, te aviso en un rato.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(2, $this->bodies_enviados(), 'Con la tarea del turno, sin reintento.');

        list($conversation, $assistant) = $this->conversacion([], 'Avisame cuando entren pedidos', AiMessage::CANAL_WHATSAPP, false);
        $this->assertSame('Desde acá no te puedo escribir después: contestame cuando quieras y lo reviso en el momento.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(4, $this->bodies_enviados(), 'Sin la tarea, el reintento.');
    }

    /**
     * La regla fija del prompt, en todos los canales: no existe un después.
     *
     * @test
     */
    public function el_prompt_dice_que_no_existe_un_despues_en_todos_los_canales()
    {
        $service = new AsistenteIaService();

        foreach ([[false, false], [true, false], [false, true]] as $caso) {

            list($conversation) = $this->conversacion([], 'Hola');

            $prompt = $service->build_system_prompt($conversation, $this->comercio, true, $caso[0], $caso[1]);

            $this->assertStringContainsString('No existe un «después»', $prompt);
            $this->assertStringContainsString('«reviso y te confirmo»', $prompt);
        }
    }

    /**
     * El detector, con los seis mensajes de Truvari y lo que no tiene que tocar.
     *
     * @test
     */
    public function el_detector_de_la_promesa_de_una_accion_futura()
    {
        $prometen = [
            "La tienda puede tardar en reflejar el cambio.\nReviso cómo está publicado el artículo en la tienda y te confirmo si hay algo que lo esté frenando.",
            self::BUSCO_Y_TE_PASO,
            "Para proponerlo necesito pasar por la consulta del stock del artículo.\nReviso y te confirmo en un momento.",
            'Tenés razón en la diferencia, y la reviso en la conversación anterior antes de contestarte cualquier cosa. Dame un momento.',
            'Reviso esa conversación para ver de dónde salió el link del Aceite Coratina; si lo dio una herramienta, la uso para el Nieto Senetiner.',
            'Lo busco tal como lo nombrás, Rutini Cabernet Malbec, y te paso lo que devuelva.',
            'Voy a revisar la consulta de ese artículo. Dame un momento.',
        ];

        foreach ($prometen as $texto) {
            $this->assertTrue(PromesaDeAccionFuturaIaHelper::promete_una_accion_futura($texto), 'Tendría que prometer: ' . $texto);
        }

        $no_prometen = [
            'No tengo el link a mano. Decime el nombre exacto como figura en la tienda o pedímelo de nuevo en un rato y lo busco.',
            'Eso se hace por la acción de pantalla, así que lo verifico la próxima vez que me lo pidas.',
            'Si querés, lo reviso.',
            '¿Querés que lo revise?',
            'Revisé la tienda: está publicado.',
            'Te paso el link: https://truvaribebidas.com.ar/articulos/nieto-senetiner-brut-nature/1000',
            'Veo que el artículo tiene 3 unidades.',
            'No puedo revisar eso desde acá.',
            'Subí el Excel y lo reviso.',
            PromesaDeAccionFuturaIaHelper::TEXTO_HONESTO,
            /* D2 de la revisión independiente: lo que entrega no promete, y con datos nunca se borra. */
            'Busco el link y te lo paso: https://truvaribebidas.com.ar/articulos/nieto-senetiner-brut-nature/1000',
            "Reviso el stock y te paso lo que hay:\nMalbec: 3\nCabernet: 5",
            'Consulto el stock y te digo: tenés 12 unidades.',
            'Miro el historial: vendiste 3 el lunes.',
            'Chequeo rápido: tenés 5 unidades del Malbec.',
            'Listo, te aviso más tarde a las 18 por acá.',
            'Hoy vendiste $45.000. Cualquier cosa, me fijo.',
            'La imagen se está generando, dame un momento.',
        ];

        foreach ($no_prometen as $texto) {
            $this->assertFalse(PromesaDeAccionFuturaIaHelper::promete_una_accion_futura($texto), 'No tendría que prometer: ' . $texto);
        }
    }

    // ---------------------------------------------------------------------
    // A6 — una vuelta sin texto después de herramientas va a la llamada de cierre
    // ---------------------------------------------------------------------

    protected function consulta_de_stock()
    {
        return $this->tool_use('consultar_stock_de_articulos', ['busqueda' => '']);
    }

    /**
     * 🔴 Mensaje 64: «el loop terminó sin texto final en la iteración 3». Con vueltas de sobra, la vuelta
     * siguiente es la llamada de cierre (tool_choice none + la nota de cierre) y la persona lee lo que el
     * modelo cuenta, no el «No llegué a terminar». Y el log dice con qué volvió la vuelta vacía.
     *
     * @test
     */
    public function una_vuelta_sin_texto_despues_de_herramientas_va_a_la_llamada_de_cierre()
    {
        Log::spy();

        $this->el_modelo_contesta([
            $this->consulta_de_stock(),
            $this->respuesta('end_turn', [['type' => 'thinking', 'thinking' => 'pienso', 'signature' => 'firma-p107']]),
            $this->end_turn('No veo la bodega en el listado que tengo a mano; decime cuál es y la cargo.'),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Tenés que ir a ABM y en Vinoteca ahí te figura el nombre de la bodega');

        $service = new AsistenteIaService();
        $texto = $service->responder($conversation, $assistant);

        $this->assertSame('No veo la bodega en el listado que tengo a mano; decime cuál es y la cargo.', $texto);
        $this->assertNull($service->detalle_tecnico(), 'No terminó en respuesta_sin_terminar().');

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertArrayNotHasKey('tool_choice', $bodies[1]);
        $this->assertSame(['type' => 'none'], $bodies[2]['tool_choice'], 'La tercera es la llamada de cierre.');
        $this->assertStringContainsString('[El sistema: se terminó el tiempo de esta respuesta y ya no podés llamar herramientas.', $this->ultimo_turno_como_texto($bodies[2]));

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'volvió sin texto después de herramientas') !== false
                && ($contexto['stop_reason'] ?? null) === 'end_turn'
                && ($contexto['tipos_de_bloque'] ?? null) === ['thinking'];
        })->once();
    }

    /**
     * Si la llamada de cierre también vuelve vacía, recién ahí lo hecho armado sin el modelo.
     *
     * @test
     */
    public function si_el_cierre_tambien_vuelve_vacio_va_lo_hecho_sin_el_modelo()
    {
        $this->el_modelo_contesta([
            $this->consulta_de_stock(),
            $this->respuesta('end_turn', []),
            $this->respuesta('end_turn', []),
        ]);

        list($conversation, $assistant) = $this->conversacion([], 'Tenés que ir a ABM y en Vinoteca ahí te figura el nombre de la bodega');

        $service = new AsistenteIaService();
        $texto = $service->responder($conversation, $assistant);

        $this->assertStringContainsString('No llegué a terminar', $texto);
        $this->assertStringContainsString('la llamada de cierre (sin_texto) volvió sin texto', (string) $service->detalle_tecnico());
        $this->assertCount(3, $this->bodies_enviados(), 'Una sola llamada de cierre, nada más.');
    }

    // ---------------------------------------------------------------------
    // A8 — el formato de WhatsApp
    // ---------------------------------------------------------------------

    /**
     * 🔴 Conversación 5: «los asteriscos se ven crudos». Por WhatsApp la regla es la de WhatsApp (*negrita*,
     * sin ** ni # ni tablas); el panel y la app, sin cambios.
     *
     * @test
     */
    public function por_whatsapp_la_regla_de_formato_es_la_de_whatsapp_y_en_el_panel_la_de_siempre()
    {
        $service = new AsistenteIaService();

        list($conversation) = $this->conversacion([], 'Hola');

        $whatsapp = $service->build_system_prompt($conversation, $this->comercio, true, true, false);

        $this->assertStringContainsString('*negrita* con UN asterisco de cada lado', $whatsapp);
        $this->assertStringContainsString('Nunca uses doble asterisco (**)', $whatsapp);
        $this->assertStringNotContainsString('Está prohibido el markdown', $whatsapp);
        $this->assertStringNotContainsString('los símbolos se ven crudos', $whatsapp);

        $sistema = $service->build_system_prompt($conversation, $this->comercio, true, false, false);

        $this->assertStringContainsString('Está prohibido el markdown', $sistema);
        $this->assertStringNotContainsString('*negrita* con UN asterisco', $sistema);
    }

    /**
     * El saneo de la salida, solo por WhatsApp: **x** → *x* y los títulos sin #. El panel, tal cual.
     *
     * @test
     */
    public function por_whatsapp_se_sanea_el_markdown_y_en_el_panel_no()
    {
        $con_markdown = "**Total:** $5.000 en 3 ventas.\n## Detalle\nMartes: $2.000";

        $this->el_modelo_contesta([
            $this->end_turn($con_markdown),
            $this->end_turn($con_markdown),
        ]);

        list($conversation, $assistant) = $this->conversacion([], '¿Cuánto vendí?', AiMessage::CANAL_WHATSAPP, false);
        $this->assertSame("*Total:* $5.000 en 3 ventas.\n*Detalle*\nMartes: $2.000", (new AsistenteIaService())->responder($conversation, $assistant));

        list($conversation, $assistant) = $this->conversacion([], '¿Cuánto vendí?', null, false);
        $this->assertSame($con_markdown, (new AsistenteIaService())->responder($conversation, $assistant));
    }

    /**
     * El saneo, caso por caso.
     *
     * @test
     */
    public function el_saneo_de_whatsapp_caso_por_caso()
    {
        $casos = [
            '**Nieto Senetiner** quedó en 9.'         => '*Nieto Senetiner* quedó en 9.',
            '*Ya* estaba bien.'                       => '*Ya* estaba bien.',
            "### Resumen\nTodo ok."                   => "*Resumen*\nTodo ok.",
            '__nota__ al pie'                         => '_nota_ al pie',
            "```\ncodigo\n```"                        => 'codigo',
            /* Revisión independiente: ante la duda el texto no se toca (antes un ** suelto se borraba). */
            'Precio: $5.000 ** sin pareja'            => 'Precio: $5.000 ** sin pareja',
            'precio 10 ** 2'                          => 'precio 10 ** 2',
            'Código **AB*12**'                        => 'Código **AB*12**',
            'Total: **$45.000**'                      => 'Total: *$45.000*',
            'snake_case_de_un_campo queda'            => 'snake_case_de_un_campo queda',
        ];

        foreach ($casos as $entrada => $salida) {
            $this->assertSame($salida, FormatoDeWhatsappIaHelper::sanear($entrada), 'Entrada: ' . $entrada);
        }
    }
}
