<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfirmacionPorTextoIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Expense;
use App\Models\ExpenseConcept;
use App\Models\Pending;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Misión asistente-carga-prometida-sin-accion (10/10/2026) — la carga que el texto da por hecha tiene
 * que estar hecha.
 *
 * 🔴 EL CASO REAL (medición real contra DeepSeek ágil, 10/10/2026). En el modo "directo" —el default
 * de un negocio nuevo desde el 28/9/2026— a "Ya lo llamé" el asistente contestó "Marqué la tarea como
 * hecha." en UNA vuelta `end_turn`, sin herramienta y con el mensaje en `acciones: []`; a "Cambiale el
 * teléfono a 3415559999", "El teléfono de Distribuidora Norte quedó cambiado a 3415559999."; y a otro
 * "Ya lo llamé", "La marco como hecha con fecha de hoy, sábado 10/10/2026.". La guarda de la tarjeta
 * (test 63) no ve ninguno: no nombran una tarjeta.
 *
 * Lo que protege este archivo, con un doble del modelo que devuelve esos textos SIN herramienta:
 *
 * - el caso literal de Lucas en "resuelto" (lo sigue atajando la guarda de la tarjeta, con su nota);
 * - que en "directo" el turno reintente UNA vez, con la nota de la CARGA y en el Profundo, y que si el
 *   reintento hace la carga la persona lea el texto del modelo con la carga ejecutada en la base;
 * - que si el reintento también miente, la persona lea el texto honesto que corresponde: sin nada en
 *   la conversación, "no pude"; con una carga de antes, "no se registró nada nuevo"; con una tarjeta
 *   esperando el botón, "todavía no quedó registrado";
 * - que la promesa ("La marco como hecha…") y la corrección de una tarjeta pendiente también reintenten;
 * - que el reintento sea UNO por turno aunque lo pidan las dos guardas, y que si falla no llegue como
 *   un error;
 * - que NO dispare cuando no corresponde: el repaso de lo recién hecho ("Gracias!"), la consulta, la
 *   pregunta, la carga ejecutada en el turno, la confirmación determinista, WhatsApp y sin acciones;
 * - y el detector, oración por oración, con los textos medidos.
 *
 * 🔴 Ningún test sale a la red: Http::fake en todos, con claves e ids de modelo inventados. Cada test
 * arma su propio comercio adentro de la transacción, así que el modo de confianza que mueve no se
 * escapa a otras suites.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 */
class Carga_prometida_sin_accion_Test extends TestCase
{
    use DatabaseTransactions;

    /** El texto del caso de Lucas (resuelto, "Pasala al miércoles"), tal cual. */
    const TEXTO_DE_LUCAS = 'Dejé la tarjeta para confirmar el cambio: la tarea pasa del martes 13/10 al miércoles 14/10/2026.';

    /** Medido en "directo": "Ya lo llamé", una vuelta `end_turn`, `acciones: []`. */
    const MARQUE_MEDIDO = 'Marqué la tarea como hecha.';

    /** Medido en "directo": "Cambiale el teléfono a 3415559999" después del alta, sin herramienta. */
    const CAMBIADO_MEDIDO = 'El teléfono de Distribuidora Norte quedó cambiado a 3415559999.';

    /** Medido en "directo": "Ya lo llamé", la promesa, sin herramienta. */
    const PROMESA_MEDIDA = 'La marco como hecha con fecha de hoy, sábado 10/10/2026.';

    /** Medido en "resuelto": "Gracias!" con la tarjeta del gasto ya confirmada con el botón. Es VERDAD. */
    const GRACIAS_MEDIDO = 'Quedó registrado el gasto de Comisiones bancarias por $15.000 en efectivo. Si necesitás algo más, avisame.';

    /** Medido en "resuelto", tras `consultar_tareas`: cuenta lo que leyó. */
    const CONSULTA_MEDIDA = 'No tenés tareas vencidas y para esta semana hay dos, las dos para el martes 13/10: llamar a Herramientas del Interior. Quedaron cargadas dos veces con el mismo detalle; si querés, borro una.';

    /** La frase que distingue la nota de la carga sin tarjeta. */
    const NOTA_SIN_CARGA = 'NO se ejecutó ninguna carga';

    /** La frase que distingue la nota de la carga con una tarjeta esperando el botón. */
    const NOTA_CON_TARJETA = 'quedó como tarjeta para que la persona la confirme';

    /** La frase que distingue la nota de la tarjeta (test 63). */
    const NOTA_DE_LA_TARJETA = 'NO se creó ninguna';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'        => 'clave-anthropic-de-prueba-p66',
            'services.deepseek.api_key'         => 'clave-deepseek-de-prueba-p66',
            'services.deepseek.model'           => 'deepseek-general-p66',
            'services.deepseek.model_agil'      => 'deepseek-flash-p66',
            'services.deepseek.model_profundo'  => 'deepseek-pro-p66',
            'services.deepseek.model_vision'    => 'deepseek-vision-p66',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio carga prometida P66',
            'company_name' => 'Ferreteria P66',
            'email'        => 'carga-p66-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La configuración de la medición: DeepSeek ágil. Arranca en "resuelto"; los de "directo" lo cambian. */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = ConfianzaDelAgenteIaHelper::RESUELTO;
        $this->comercio->save();
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Deja al comercio en un modo de confianza. El comercio es de este test y vive adentro de la
     * transacción: el rollback se lo lleva entero.
     *
     * @param  string  $modo
     * @return void
     */
    protected function en_modo($modo)
    {
        $this->comercio->agente_confianza = $modo;
        $this->comercio->save();
    }

    /**
     * Conversación con el pedido de la persona y el assistant pendiente.
     *
     * @param  string  $pedido
     * @param  bool  $con_acciones
     * @param  string|null  $canal  null = el panel del sistema.
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($pedido, $con_acciones = true, $canal = null)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $user = [
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => $pedido,
            'estado'             => 'listo',
        ];

        $assistant = [
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => $con_acciones,
        ];

        if (! is_null($canal)) {
            $user['canal']      = $canal;
            $assistant['canal'] = $canal;
        }

        AiMessage::create($user);

        return [$conversation, AiMessage::create($assistant)];
    }

    /**
     * Una conversación con pedidos previos: cada uno es un par (pedido, respuesta) y la respuesta puede
     * llevar una tarjeta en el estado que se pida. Al final, el pedido nuevo y el assistant pendiente.
     *
     * @param  array<int, array{0: string, 1: string, 2: string|null, 3: string}>  $pares  [pedido, respuesta, estado de la tarjeta o null, tipo]
     * @param  string  $pedido_nuevo
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion_con_historia(array $pares, $pedido_nuevo)
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        foreach ($pares as $indice => $par) {

            AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $par[0], 'estado' => 'listo']);

            $respuesta = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $par[1], 'estado' => 'listo', 'acciones_habilitadas' => true]);

            if (! is_null($par[2])) {
                AiMessageAction::create([
                    'ai_conversation_id' => $conversation->id,
                    'ai_message_id'      => $respuesta->id,
                    'user_id'            => $this->comercio->id,
                    'auth_user_id'       => $this->comercio->id,
                    'tipo'               => $par[3],
                    'clave'              => $par[3] . ':p66-' . $indice,
                    'estado'             => $par[2],
                    'datos'              => [],
                    'presentacion'       => ['titulo' => 'Carga P66', 'renglones' => [], 'aviso' => null],
                    'resuelta_at'        => $par[2] === AiMessageAction::ESTADO_CONFIRMADA ? Carbon::now() : null,
                ]);
            }
        }

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido_nuevo, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Una conversación donde la respuesta anterior agendó una tarea por el despacho REAL (proponer_tarea)
     * y la tarjeta quedó CONFIRMADA con el botón: la tarea existe en la agenda. Después, el pedido nuevo
     * y el assistant pendiente.
     *
     * @param  string  $pedido_nuevo
     * @param  Carbon  $fecha
     * @return array{0: AiConversation, 1: AiMessage, 2: Pending}
     */
    protected function tarea_confirmada_en_el_mensaje_anterior($pedido_nuevo, Carbon $fecha)
    {
        $detalle = 'Llamar a Herramientas del Interior P66';

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Recordame llamar a Herramientas del Interior', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Dejé la tarjeta para agendar la llamada.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        $propuesta = HerramientasDeCarga::ejecutar('proponer_tarea', ['detalle' => $detalle, 'fecha' => $fecha->format('Y-m-d')], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error'], 'La propuesta del fixture devolvió una falla técnica: ' . $propuesta['content']);

        $datos = json_decode($propuesta['content'], true);

        $this->assertTrue(! empty($datos['ok']), 'La propuesta del fixture no dejó la tarjeta: ' . $propuesta['content']);

        $tarjeta_id = (int) $datos['tarjeta_id'];

        if (AiMessageAction::find($tarjeta_id)->estado_guardado() !== AiMessageAction::ESTADO_CONFIRMADA) {
            ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $tarjeta_id);
        }

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::find($tarjeta_id)->estado_guardado(), 'La tarea del fixture tendría que haber quedado confirmada.');

        $tarea = Pending::where('user_id', $this->comercio->id)->where('detalle', $detalle)->firstOrFail();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido_nuevo, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $tarea];
    }

    /**
     * El martes que viene (la fecha de la tarea del caso medido).
     *
     * @return Carbon
     */
    protected function martes()
    {
        return Carbon::today()->next(Carbon::TUESDAY);
    }

    /**
     * La fecha de la tarea, como está en la base ahora.
     *
     * @param  Pending  $tarea
     * @return string  Y-m-d
     */
    protected function fecha_de($tarea)
    {
        return Carbon::parse(Pending::findOrFail($tarea->id)->fecha_realizacion)->format('Y-m-d');
    }

    /**
     * Subcategoría de gasto del comercio.
     *
     * @param  string  $nombre
     * @return ExpenseConcept
     */
    protected function subcategoria($nombre = 'Alquiler P66')
    {
        return ExpenseConcept::create([
            'num'     => (int) ExpenseConcept::where('user_id', $this->comercio->id)->max('num') + 1,
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * El método de pago "Efectivo" del catálogo global (lo siembra CurrentAcountPaymentMethodSeeder).
     *
     * @return CurrentAcountPaymentMethod
     */
    protected function efectivo()
    {
        $metodo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->whereNull('c_a_payment_method_type_id')->where('id', '!=', 1)->first();

        if (is_null($metodo)) {
            $metodo = CurrentAcountPaymentMethod::create(['name' => 'Efectivo']);
        }

        return $metodo;
    }

    /**
     * Bloque tool_use de proponer_gasto, con datos completos.
     *
     * @param  ExpenseConcept  $subcategoria
     * @param  string  $id
     * @param  float  $monto
     * @return array
     */
    protected function bloque_proponer_gasto($subcategoria, $id = 'toolu_p66_gasto', $monto = 400000)
    {
        return $this->tool_use('proponer_gasto', [
            'subcategoria_id' => $subcategoria->id,
            'monto'           => $monto,
            'pagos'           => [
                ['metodo_de_pago_id' => $this->efectivo()->id],
            ],
        ], $id);
    }

    /**
     * Un bloque tool_use.
     *
     * @param  string  $herramienta
     * @param  array  $input
     * @param  string  $id
     * @return array
     */
    protected function tool_use($herramienta, array $input, $id)
    {
        return [
            'type'  => 'tool_use',
            'id'    => $id,
            'name'  => $herramienta,
            'input' => $input,
        ];
    }

    /**
     * Una respuesta del proveedor con los bloques y el stop_reason que se le pidan.
     *
     * @param  string  $stop_reason
     * @param  array  $bloques
     * @return array
     */
    protected function respuesta($stop_reason, array $bloques)
    {
        return [
            'model'       => 'lo-que-diga-el-proveedor',
            'stop_reason' => $stop_reason,
            'content'     => $bloques,
            'usage'       => ['input_tokens' => 11, 'output_tokens' => 7],
        ];
    }

    /**
     * @param  string  $texto
     * @return array
     */
    protected function end_turn($texto)
    {
        return $this->respuesta('end_turn', [['type' => 'text', 'text' => $texto]]);
    }

    /**
     * Una secuencia de respuestas del proveedor que, al agotarse, contesta un 500 en vez de tirar.
     *
     * 🔴 POR QUÉ NO Http::sequence() A SECAS (chequeo adversarial del 10/10/2026, test 63). Agotada,
     * tira una excepción ANTES de que Laravel grabe el pedido, y el reintento la ataja como una falla de
     * red: una llamada de más no quedaba en Http::recorded() y "un solo reintento" o "sin reintento"
     * daban verde aunque no fuera cierto. Con el 500, la llamada de más queda grabada y el conteo la ve.
     *
     * @return \Illuminate\Http\Client\ResponseSequence
     */
    protected function secuencia()
    {
        return Http::sequence()->whenEmpty(Http::response(['error' => ['type' => 'api_error', 'message' => 'la secuencia se agotó (P66)']], 500));
    }

    /**
     * Los bodies de todos los requests que salieron, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            $bodies[] = json_decode($par[0]->body(), true);
        }

        return $bodies;
    }

    /**
     * El texto del último turno de un body (string o bloques `text`).
     *
     * @param  array  $body
     * @return string
     */
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
        }

        return implode("\n", $textos);
    }

    /**
     * El turno del asistente que viajó en el reintento (el anteúltimo del body), como texto.
     *
     * @param  array  $body
     * @return string
     */
    protected function turno_del_asistente_del_reintento(array $body)
    {
        $mensajes = $body['messages'];

        $this->assertSame('assistant', $mensajes[count($mensajes) - 2]['role']);
        $this->assertSame('user', $mensajes[count($mensajes) - 1]['role']);

        return (string) $mensajes[count($mensajes) - 2]['content'];
    }

    /**
     * Verifica que el body sea el de un reintento por la CARGA: la nota del sistema con la frase que
     * corresponde, y el modelo Profundo.
     *
     * @param  array  $body
     * @param  string  $frase  NOTA_SIN_CARGA o NOTA_CON_TARJETA.
     * @return void
     */
    protected function assert_reintento_de_la_carga(array $body, $frase)
    {
        $nota = $this->ultimo_turno_como_texto($body);

        $this->assertStringContainsString('[El sistema revisó tu respuesta', $nota);
        $this->assertStringContainsString($frase, $nota, 'La nota del reintento no es la de la carga que corresponde.');
        $this->assertStringNotContainsString(self::NOTA_DE_LA_TARJETA, $nota, 'Es la nota de la carga, no la de la tarjeta.');
        $this->assertSame(config('services.deepseek.model_profundo'), $body['model'], 'El reintento va al modelo Profundo.');
    }

    /**
     * @param  AiMessage  $assistant
     * @return \Illuminate\Support\Collection
     */
    protected function tarjetas_de($assistant)
    {
        return AiMessageAction::where('ai_message_id', $assistant->id)->orderBy('id')->get();
    }

    // ---------------------------------------------------------------------
    // 1 y 2 · "Pasala al miércoles"
    // ---------------------------------------------------------------------

    /**
     * 🔴 1 — EL CASO LITERAL DE LUCAS, en "resuelto": tarea agendada y confirmada en el mensaje
     * anterior, "Pasala al miércoles", y el modelo contesta "Dejé la tarjeta para confirmar el cambio…"
     * sin herramienta. Lo ataja la guarda de la TARJETA (con su nota, no con la de la carga): el
     * reintento llama a proponer_cambios_en_tarea y la tarjeta queda, sin mover la tarea.
     *
     * @group chat-ia
     * @test
     */
    public function el_caso_literal_de_lucas_en_resuelto_lo_ataja_la_guarda_de_la_tarjeta_y_la_tarjeta_queda()
    {
        $martes    = $this->martes();
        $miercoles = $martes->copy()->addDay();

        list($conversation, $assistant, $tarea) = $this->tarea_confirmada_en_el_mensaje_anterior('Pasala al miércoles', $martes);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::TEXTO_DE_LUCAS), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('proponer_cambios_en_tarea', ['tarea_id' => $tarea->id, 'fecha' => $miercoles->format('Y-m-d')], 'toolu_p66_cambio')]), 200)
                ->push($this->end_turn('Te dejé la tarjeta para pasar la tarea al miércoles.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta para pasar la tarea al miércoles.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Texto sin tarjeta, reintento con la herramienta y texto final: tres llamadas.');
        $this->assertSame(config('services.deepseek.model_agil'), $bodies[0]['model']);
        $this->assertSame(self::TEXTO_DE_LUCAS, $this->turno_del_asistente_del_reintento($bodies[1]));

        $nota = $this->ultimo_turno_como_texto($bodies[1]);

        $this->assertStringContainsString('[El sistema revisó tu respuesta', $nota);
        $this->assertStringContainsString(self::NOTA_DE_LA_TARJETA, $nota, 'El texto nombra una tarjeta: la nota es la de la tarjeta.');
        $this->assertStringNotContainsString(self::NOTA_SIN_CARGA, $nota);
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[1]['model']);

        /* En la base: la tarjeta del cambio, esperando el botón, y la tarea todavía el martes. */
        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::TIPO_TAREA_EDITAR, $tarjetas[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());
        $this->assertSame($martes->format('Y-m-d'), $this->fecha_de($tarea), 'En "resuelto" la tarea no se mueve hasta que la persona confirme.');
    }

    /**
     * 🔴 2 — El mismo pedido en "directo": "Listo, la pasé al miércoles." sin herramienta. No nombra una
     * tarjeta, así que el reintento es el de la CARGA; el reintento llama a proponer_cambios_en_tarea,
     * que en "directo" se ejecuta, y la persona lee el texto del modelo con la tarea ya movida.
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_la_pase_al_miercoles_sin_herramienta_reintenta_y_la_tarea_cambia_de_fecha()
    {
        $martes    = $this->martes();
        $miercoles = $martes->copy()->addDay();

        list($conversation, $assistant, $tarea) = $this->tarea_confirmada_en_el_mensaje_anterior('Pasala al miércoles', $martes);

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, la pasé al miércoles.'), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('proponer_cambios_en_tarea', ['tarea_id' => $tarea->id, 'fecha' => $miercoles->format('Y-m-d')], 'toolu_p66_cambio')]), 200)
                ->push($this->end_turn('Listo, la tarea quedó para el miércoles 14/10/2026.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, la tarea quedó para el miércoles 14/10/2026.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Texto sin carga, reintento con la herramienta y texto final: tres llamadas.');
        $this->assertSame(config('services.deepseek.model_agil'), $bodies[0]['model']);
        $this->assertSame('Listo, la pasé al miércoles.', $this->turno_del_asistente_del_reintento($bodies[1]), 'El turno del asistente va como texto.');
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        /* En la base: la carga EJECUTADA en este mensaje, y la tarea el miércoles. */
        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::TIPO_TAREA_EDITAR, $tarjetas[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjetas[0]->estado_guardado(), 'En "directo" el cambio se ejecuta en el acto.');
        $this->assertSame($miercoles->format('Y-m-d'), $this->fecha_de($tarea));
    }

    // ---------------------------------------------------------------------
    // 3 · El reintento que también miente
    // ---------------------------------------------------------------------

    /**
     * 🔴 3 — EL CASO MEDIDO, en "directo": "Ya lo llamé" → "Marqué la tarea como hecha." sin
     * herramienta. El reintento TAMBIÉN miente, y en la conversación no hay ninguna carga hecha: la
     * persona lee "No pude hacer esa carga…", con exactamente dos llamadas. Y queda el rastro.
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_marque_la_tarea_sin_herramienta_y_un_reintento_que_tambien_miente_da_el_texto_honesto()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Ya lo llamé');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::MARQUE_MEDIDO), 200)
                ->push($this->end_turn('Listo, marqué la tarea como hecha.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA, $texto);
        $this->assertCount(0, $this->tarjetas_de($assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Un solo reintento: nunca dos.');
        $this->assertSame(config('services.deepseek.model_agil'), $bodies[0]['model']);
        $this->assertSame(self::MARQUE_MEDIDO, $this->turno_del_asistente_del_reintento($bodies[1]));
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($assistant) {
            return strpos($mensaje, 'se reintenta una vez') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['tipo_de_afirmacion'] === 'hecho'
                && $contexto['tarjetas_propuestas'] === 0
                && $contexto['ai_message_id'] === $assistant->id
                && $contexto['herramientas_pedidas'] === []
                && $contexto['stop_reason'] === 'end_turn';
        })->once();

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se reemplaza por el texto honesto') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['tipo_de_afirmacion'] === 'hecho'
                && $contexto['hubo_reintento'] === true
                && $contexto['texto_honesto'] === TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA
                && array_key_exists('el_saneo_saco_algo', $contexto);
        })->once();
    }

    // ---------------------------------------------------------------------
    // 4 · La carga que quedó como tarjeta
    // ---------------------------------------------------------------------

    /**
     * 4 — En "resuelto", proponer_gasto deja la tarjeta y el modelo dice "Listo, quedó registrado el
     * gasto.": la tarjeta está esperando el botón. El reintento lleva la nota de "quedó como tarjeta" y
     * la persona lee el texto bueno del modelo.
     *
     * @group chat-ia
     * @test
     */
    public function en_resuelto_quedo_registrado_con_la_tarjeta_pendiente_reintenta_con_la_nota_de_la_tarjeta()
    {
        $alquiler = $this->subcategoria();

        list($conversation, $assistant) = $this->conversacion('Cargame el alquiler de 400000 en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200)
                ->push($this->end_turn('Te dejé la tarjeta del gasto para que la confirmes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta del gasto para que la confirmes.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'La herramienta, el texto que miente y el reintento: tres llamadas.');
        $this->assertSame('Listo, quedó registrado el gasto.', $this->turno_del_asistente_del_reintento($bodies[2]));
        $this->assert_reintento_de_la_carga($bodies[2], self::NOTA_CON_TARJETA);

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());
    }

    /**
     * 4 — La variante: el reintento INSISTE en que quedó registrado. La persona lee "Todavía no quedó
     * registrado: te dejé la tarjeta…", y en la base la tarjeta sigue esperando y no hay ningún gasto.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_insiste_con_la_tarjeta_pendiente_la_persona_lee_que_todavia_no_quedo_registrado()
    {
        $alquiler = $this->subcategoria();

        list($conversation, $assistant) = $this->conversacion('Cargame el alquiler de 400000 en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200)
                ->push($this->end_turn('Listo, el gasto quedó registrado.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA_CON_TARJETA, $texto);
        $this->assertCount(3, $this->bodies_enviados(), 'Un solo reintento: nunca dos.');

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado(), 'La tarjeta sigue esperando el botón.');
        $this->assertSame(0, Expense::where('user_id', $this->comercio->id)->count(), 'No se registró ningún gasto.');
    }

    // ---------------------------------------------------------------------
    // 5 · El seguimiento después de una carga hecha
    // ---------------------------------------------------------------------

    /**
     * El alta de "Distribuidora Norte" CONFIRMADA en el mensaje anterior, en "directo", y el pedido del
     * cambio de teléfono.
     *
     * @return array{0: AiConversation, 1: AiMessage, 2: Provider}
     */
    protected function proveedor_dado_de_alta_antes()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        $proveedor = Provider::create(['name' => 'Distribuidora Norte P66', 'user_id' => $this->comercio->id, 'num' => 1, 'phone' => '3415551234']);

        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Dá de alta al proveedor Distribuidora Norte, teléfono 3415551234', 'Proveedor Distribuidora Norte creado, teléfono 3415551234.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_ALTA],
        ], 'Cambiale el teléfono a 3415559999');

        return [$conversation, $assistant, $proveedor];
    }

    /**
     * 🔴 5 — EL CASO MEDIDO, en "directo": alta del proveedor ejecutada en el mensaje anterior,
     * "Cambiale el teléfono a 3415559999" → "El teléfono de Distribuidora Norte quedó cambiado a
     * 3415559999." sin herramienta. El reintento insiste: como en la conversación HAY una carga
     * confirmada, la persona lee "no se registró nada nuevo" (y no "no pude", que la invitaría a
     * repetir algo que puede estar hecho).
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_quedo_cambiado_tras_un_alta_y_un_reintento_que_insiste_da_el_texto_con_historia()
    {
        list($conversation, $assistant, $proveedor) = $this->proveedor_dado_de_alta_antes();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::CAMBIADO_MEDIDO), 200)
                ->push($this->end_turn('Listo, el teléfono de Distribuidora Norte quedó cambiado a 3415559999.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA_CON_HISTORIA, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Un solo reintento: nunca dos.');
        $this->assertSame(self::CAMBIADO_MEDIDO, $this->turno_del_asistente_del_reintento($bodies[1]));
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        $this->assertCount(0, $this->tarjetas_de($assistant));
        $this->assertSame('3415551234', Provider::findOrFail($proveedor->id)->phone, 'El teléfono no cambió: nadie lo cambió.');
    }

    /**
     * 5 — La variante: el reintento llama a proponer_edicion, que en "directo" se ejecuta. La persona lee
     * el texto del modelo y el teléfono cambió de verdad.
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_si_el_reintento_llama_a_proponer_edicion_el_cambio_se_ejecuta()
    {
        list($conversation, $assistant, $proveedor) = $this->proveedor_dado_de_alta_antes();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::CAMBIADO_MEDIDO), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('proponer_edicion', [
                    'entidad'  => 'provider',
                    'registro' => $proveedor->id,
                    'cambios'  => ['phone' => '3415559999'],
                ], 'toolu_p66_edicion')]), 200)
                ->push($this->end_turn('Listo, le cambié el teléfono a Distribuidora Norte: ahora es 3415559999.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, le cambié el teléfono a Distribuidora Norte: ahora es 3415559999.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::TIPO_EDICION, $tarjetas[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjetas[0]->estado_guardado(), 'En "directo" la edición se ejecuta en el acto.');
        $this->assertSame('3415559999', Provider::findOrFail($proveedor->id)->phone);
    }

    /**
     * 🔴 5b — EL CASO MEDIDO de la promesa, en "directo": "Ya lo llamé" → "La marco como hecha con fecha
     * de hoy, sábado 10/10/2026." sin herramienta. También reintenta; el reintento marca la tarea y en
     * la base queda hecha.
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_la_marco_como_hecha_es_una_promesa_y_tambien_reintenta()
    {
        list($conversation, $assistant, $tarea) = $this->tarea_confirmada_en_el_mensaje_anterior('Ya lo llamé', Carbon::today());

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::PROMESA_MEDIDA), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('proponer_marcar_tarea_hecha', ['tarea_id' => $tarea->id], 'toolu_p66_hecha')]), 200)
                ->push($this->end_turn('Listo, marqué la tarea como hecha.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, marqué la tarea como hecha.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertSame(self::PROMESA_MEDIDA, $this->turno_del_asistente_del_reintento($bodies[1]));
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::TIPO_TAREA_COMPLETAR, $tarjetas[0]->tipo);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjetas[0]->estado_guardado());
        $this->assertTrue((bool) Pending::findOrFail($tarea->id)->completado, 'La tarea quedó hecha de verdad.');
    }

    // ---------------------------------------------------------------------
    // 6 · La corrección de una tarjeta pendiente
    // ---------------------------------------------------------------------

    /**
     * 6 — La persona CORRIGE una tarjeta pendiente ("no, eran 6000") y el modelo contesta "Listo, lo
     * cambié a 6000." sin herramienta. Reintenta con la nota de la carga; el reintento rearma la tarjeta
     * con el dato nuevo. El turno arranca en el Profundo (la corrección ya escalaba) y el reintento
     * también.
     *
     * @group chat-ia
     * @test
     */
    public function la_correccion_de_una_tarjeta_pendiente_sin_herramienta_reintenta()
    {
        $alquiler = $this->subcategoria();

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Cargame el alquiler de 5000 en efectivo', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del alquiler para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($alquiler, 'toolu_p66_original', 5000)['input'], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error'], 'La propuesta del fixture tendría que haber dejado la tarjeta.');

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'no, eran 6000', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, lo cambié a 6000.'), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler, 'toolu_p66_correccion', 6000)]), 200)
                ->push($this->end_turn('Te dejé la tarjeta corregida con 6000 para que la confirmes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta corregida con 6000 para que la confirmes.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[0]['model'], 'La corrección de una tarjeta pendiente ya arranca escalada.');
        $this->assertSame('Listo, lo cambié a 6000.', $this->turno_del_asistente_del_reintento($bodies[1]));
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas, 'El reintento dejó la tarjeta corregida en este mensaje.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());
    }

    // ---------------------------------------------------------------------
    // El único reintento del turno, y el reintento que falla
    // ---------------------------------------------------------------------

    /**
     * Si el reintento ya lo usó la guarda de la TARJETA y el texto del reintento da por hecha una carga,
     * no hay un segundo reintento: la guarda de la carga de después del bucle cambia el texto por el
     * honesto. Dos llamadas exactas.
     *
     * @group chat-ia
     * @test
     */
    public function el_reintento_es_uno_solo_aunque_lo_pidan_las_dos_guardas()
    {
        list($conversation, $assistant) = $this->conversacion('El alquiler de 400000 se paga hoy');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Dejé la tarjeta para confirmar el gasto del alquiler.'), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto del alquiler.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Un solo reintento por turno, aunque lo pidan las dos guardas.');
        $this->assertStringContainsString(self::NOTA_DE_LA_TARJETA, $this->ultimo_turno_como_texto($bodies[1]), 'El primer texto nombraba una tarjeta: el reintento fue el de la tarjeta.');
    }

    /**
     * Un reintento de la carga que vuelve con error HTTP no le llega a la persona como un error: vuelve
     * el texto de antes y la guarda de después del bucle lo cambia por el honesto.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_de_la_carga_vuelve_con_error_la_persona_lee_el_texto_honesto_y_no_un_error()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Ya lo llamé');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::MARQUE_MEDIDO), 200)
                ->push(['error' => ['type' => 'api_error', 'message' => 'caído']], 500),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA, $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * 🔴 'hecho' NO tiene la excepción de la consulta: "Marqué la tarea como hecha" después de sólo un
     * consultar_tareas es mentira igual (la consulta no marca nada). Reintenta, y la nota no nombra
     * ninguna herramienta de carga porque no hubo.
     *
     * @group chat-ia
     * @test
     */
    public function marque_despues_de_una_consulta_tambien_reintenta()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Ya lo llamé');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->tool_use('consultar_tareas', [], 'toolu_p66_consulta')]), 200)
                ->push($this->end_turn(self::MARQUE_MEDIDO), 200)
                ->push($this->end_turn('No encontré ninguna tarea para hoy: ¿cuál es la que llamaste?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('No encontré ninguna tarea para hoy: ¿cuál es la que llamaste?', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assert_reintento_de_la_carga($bodies[2], self::NOTA_SIN_CARGA);
        $this->assertStringNotContainsString('Pediste', $this->ultimo_turno_como_texto($bodies[2]), 'Una consulta no es una herramienta de carga.');
    }

    // ---------------------------------------------------------------------
    // 7 · Cuándo NO dispara
    // ---------------------------------------------------------------------

    /**
     * 🔴 7 — E2, EL CASO MEDIDO: en "resuelto", con la tarjeta del gasto ya CONFIRMADA en la respuesta
     * anterior, "Gracias!" → "Quedó registrado el gasto de Comisiones bancarias por $15.000…" sin
     * herramientas. Es VERDAD: una sola llamada y el texto intacto. Y lo mismo con un 'hecho' ("ya te
     * cargué el gasto") ante otro acuse.
     *
     * @group chat-ia
     * @test
     */
    public function un_gracias_despues_de_una_carga_confirmada_no_dispara()
    {
        $textos = [
            ['Gracias!', self::GRACIAS_MEDIDO],
            ['Genial, mil gracias 👍', 'De nada: ya te cargué el gasto de $15.000.'],
        ];

        /* UNA secuencia para todos los pedidos: un segundo Http::fake no pisa al primero, se le suma. */
        $secuencia = $this->secuencia();

        foreach ($textos as $par) {
            $secuencia->push($this->end_turn($par[1]), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ($textos as $par) {

            list($conversation, $assistant) = $this->conversacion_con_historia([
                ['Gasté 15000 de comisiones bancarias, en efectivo', 'Dejé la tarjeta para confirmar: gasto de Comisiones bancarias por $15.000, en efectivo.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_GASTO],
            ], $par[0]);

            $texto = (new AsistenteIaService())->responder($conversation, $assistant);

            $this->assertSame($par[1], $texto);
        }

        $this->assertCount(count($textos), $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    /**
     * 7 — E3, EL CASO MEDIDO: tras `consultar_tareas`, "Quedaron cargadas dos veces con el mismo
     * detalle" cuenta lo que leyó. Sin reintento (la herramienta y el texto: dos llamadas).
     *
     * @group chat-ia
     * @test
     */
    public function una_consulta_que_cuenta_lo_que_leyo_no_dispara()
    {
        list($conversation, $assistant) = $this->conversacion('Mostrame las tareas de esta semana');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->tool_use('consultar_tareas', [], 'toolu_p66_consulta')]), 200)
                ->push($this->end_turn(self::CONSULTA_MEDIDA), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(self::CONSULTA_MEDIDA, $texto);
        $this->assertCount(2, $this->bodies_enviados(), 'La consulta y el texto: ningún reintento.');
    }

    /**
     * 7 — E1 y E2: a una pregunta, "Sí, quedó agendada para el martes." cuenta un estado. Con la tarea
     * confirmada en la respuesta anterior (E1 y E2 a la vez) y sin nada antes (E1 solo): una llamada
     * por pedido y el texto intacto.
     *
     * @group chat-ia
     * @test
     */
    public function una_pregunta_sobre_lo_que_quedo_no_dispara()
    {
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Sí, quedó agendada para el martes.'), 200)
                ->push($this->end_turn('Sí, quedó agendada para el martes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->tarea_confirmada_en_el_mensaje_anterior('¿Quedó agendada la llamada a Herramientas del Interior?', $this->martes());

        $this->assertSame('Sí, quedó agendada para el martes.', (new AsistenteIaService())->responder($conversation, $assistant));

        list($conversation, $assistant) = $this->conversacion('¿Quedó agendada la llamada?');

        $this->assertSame('Sí, quedó agendada para el martes.', (new AsistenteIaService())->responder($conversation, $assistant));

        $this->assertCount(2, $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    /**
     * 7 — En "directo", la carga se EJECUTÓ en el turno y el texto la cuenta ("Quedó registrado el Gasto
     * N° 1…", medido): no miente. La herramienta y el texto, dos llamadas, y la carga confirmada.
     *
     * @group chat-ia
     * @test
     */
    public function una_carga_ejecutada_en_el_turno_no_dispara()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        $comisiones = $this->subcategoria('Comisiones bancarias P66');

        list($conversation, $assistant) = $this->conversacion('Gasté 15000 de comisiones bancarias, en efectivo');

        $texto_medido = 'Quedó registrado el Gasto N° 1 por $15.000 de comisiones bancarias, en efectivo.';

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($comisiones, 'toolu_p66_gasto', 15000)]), 200)
                ->push($this->end_turn($texto_medido), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame($texto_medido, $texto);
        $this->assertCount(2, $this->bodies_enviados(), 'Ningún reintento: la carga está hecha.');

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjetas[0]->estado_guardado(), 'En "directo" el gasto se ejecuta en el acto.');
    }

    /**
     * 7 — Después de una confirmación determinista (el "dale" a la ÚNICA tarjeta pendiente se confirma
     * en código, antes del modelo) la guarda no corre: la carga es de una tarjeta de otro mensaje y "Listo,
     * quedó registrado el gasto." es verdad.
     *
     * @group chat-ia
     * @test
     */
    public function despues_de_una_confirmacion_determinista_la_guarda_de_la_carga_no_corre()
    {
        $alquiler = $this->subcategoria();

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Cargame el alquiler de 400000', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del alquiler para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        /* La tarjeta, por el despacho real: datos válidos, así la confirmación determinista sale bien. */
        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($alquiler)['input'], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error'], 'La propuesta del fixture tendría que haber dejado la tarjeta.');

        $tarjeta = AiMessageAction::where('ai_message_id', $anterior->id)->firstOrFail();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'dale', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, quedó registrado el gasto.', $texto, 'Con la confirmación determinista, el texto del modelo queda tal cual.');
        $this->assertCount(1, $this->bodies_enviados(), 'Sin reintento.');
        $this->assertStringContainsString('[El sistema ya confirmó la tarjeta #' . $tarjeta->id, $this->ultimo_turno_como_texto($this->bodies_enviados()[0]), 'La confirmación determinista tuvo que correr y salir bien (su nota viaja pegada al "dale").');
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjeta->fresh()->estado_guardado());
    }

    /**
     * 7 — Por WhatsApp (la confirmación es por texto, y "este mensaje no ejecutó nada" no alcanza para
     * decir que miente) y sin acciones (el asistente es de sólo lectura) la guarda no corre.
     *
     * @group chat-ia
     * @test
     */
    public function por_whatsapp_y_sin_acciones_la_guarda_de_la_carga_no_corre()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        /* UNA secuencia para los dos pedidos: un segundo Http::fake no pisa al primero, se le suma. */
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, la pasé al miércoles.'), 200)
                ->push($this->end_turn('Listo, la pasé al miércoles.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ([[true, AiMessage::CANAL_WHATSAPP], [false, null]] as $caso) {

            list($conversation, $assistant) = $this->conversacion('Pasala al miércoles', $caso[0], $caso[1]);

            $texto = (new AsistenteIaService())->responder($conversation, $assistant);

            $this->assertSame('Listo, la pasé al miércoles.', $texto);
        }

        $this->assertCount(2, $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    // ---------------------------------------------------------------------
    // 8 · El detector
    // ---------------------------------------------------------------------

    /**
     * 8 — Oración por oración: lo que da por hecha una carga (y con qué tipo) y lo que no, con los
     * textos medidos contra DeepSeek el 10/10/2026 marcados.
     *
     * @group chat-ia
     * @test
     */
    public function el_detector_reconoce_las_cargas_dadas_por_hechas_y_deja_pasar_el_resto()
    {
        $afirman = [
            /* hecho */
            [self::MARQUE_MEDIDO, 'hecho'],
            ['Listo, la pasé al miércoles.', 'hecho'],
            ['Listo, lo cambié a 6000.', 'hecho'],
            /* Medido, verdadero con la carga ejecutada: el detector la ve; el servicio la deja por la acción. */
            ['Agendé la tarea para pagar el alquiler el jueves 15/10, con el gasto de $400.000 asociado. El día que la marques como hecha te voy a preguntar cómo se paga y a qué caja.', 'hecho'],
            ['Te pasé la tarea para el 14/10.', 'hecho'],
            ['Di de alta al proveedor Distribuidora Norte.', 'hecho'],
            ['Te armé el combo de verano.', 'hecho'],
            ['Hice la venta por $12.000.', 'hecho'],
            ['Te dejé agendada la tarea para el martes.', 'hecho'],
            ['Registré el gasto. ¿Necesitás algo más?', 'hecho'],
            ['Moví la tarea al miércoles 14/10.', 'hecho'],
            ['Mandé a buscar las imágenes de los artículos.', 'hecho'],
            ['Sí, lo cargué.', 'hecho'],
            /* quedo */
            [self::CAMBIADO_MEDIDO, 'quedo'],
            [self::GRACIAS_MEDIDO, 'quedo'],
            [self::CONSULTA_MEDIDA, 'quedo'],
            ['Listo, quedó registrado el gasto.', 'quedo'],
            ['Quedó pasada al miércoles 14/10/2026.', 'quedo'],
            ['Listo, la tarea quedó para el miércoles 14/10/2026.', 'quedo'],
            ['Ya está cargado.', 'quedo'],
            ['Agendada la tarea de pagar el alquiler de $400.000 para el jueves 15/10, con el gasto asociado a la subcategoría Alquiler.', 'quedo'],
            ['Listo, agendada.', 'quedo'],
            ['Gasto N° 3 registrado: $15.000 de comisiones bancarias, pagado en efectivo.', 'quedo'],
            ['Proveedor Distribuidora Norte creado, teléfono 3415551234.', 'quedo'],
            ['Pago N° 1 registrado.', 'quedo'],
            ['Sí, quedó agendada para el martes.', 'quedo'],
            ['El proveedor quedó dado de alta.', 'quedo'],
            ['Agendado: llamar a Herramientas del Interior el martes 13/10.', 'quedo'],
            /* promesa */
            [self::PROMESA_MEDIDA, 'promesa'],
            ['Te cargo el gasto de $15.000 en efectivo.', 'promesa'],
            ['La paso al miércoles.', 'promesa'],
        ];

        foreach ($afirman as $caso) {
            $this->assertSame($caso[1], TarjetaPrometidaIaHelper::afirma_una_carga_hecha($caso[0]), 'Tendría que dar "' . $caso[1] . '": ' . $caso[0]);
        }

        $no_afirman = [
            '¿Querés que lo cargue?',
            'Si cargué mal el monto, avisame.',
            /* Medidos: un ofrecimiento, una pregunta al final, pedir cuál. */
            'Si querés, te marco alguna como hecha o le cambio la fecha.',
            "La marco como hecha para el miércoles 14/10/2026.\n\nEstá agendada para el miércoles 14/10, que todavía no llegó. ¿Querés que la marque hecha con fecha de hoy, sábado 10/10?",
            'Si ya lo llamaste hoy, la marco como hecha. Pero necesito saber cuál de las cinco tareas es: hay cinco iguales para el martes 13/10 y no distingo una de otra. ¿Las marco todas como hechas?',
            'Necesito confirmar cuál es la tarea. ¿La marco ahora como hecha?',
            'Hay dos proveedores llamados "Distribuidora Norte". ¿A cuál le cambio el teléfono? Uno es el que acabo de crear recién; el otro ya existía de antes.',
            'Hay 3 proveedores que se llaman "Distribuidora Norte". Decime cuál es: el que quedó recién cargado (el último) o alguno de los otros dos.',
            'No encontré esa tarea en la agenda, así que no pude cambiarla. Quizás ya quedó hecha o borrada. ¿Querés que busque las tareas pendientes para revisar?',
            'De nada. Si querés, puedo registrar el próximo gasto o mostrarte cómo quedó cargado este.',
            'Hay tres tareas cargadas con el mismo texto para el martes 13/10. No sé cuál querés mover: ¿paso las tres al miércoles 14/10 o solo una?',
            'No tenés tareas en la agenda para esta semana, ni vencidas sin hacer. Si querés, te agendo una.',
            'No hay problema. Cuando me confirmes la caja, armo el gasto de comisiones bancarias por 15.000 en efectivo.',
            /* Medidos en "resuelto": las tarjetas son de la otra familia. */
            'Dejé la tarjeta para confirmar: paso la tarea "Llamar a Herramientas del Interior" al miércoles 14/10.',
            'Dejé la tarjeta para confirmar: tarea "Pagar el alquiler" con gasto asociado de $400.000, agendada para el jueves 15/10. El método de pago y la caja se preguntan el día que la marques como hecha.',
            'Dejé la tarjeta corregida: Distribuidora Norte con teléfono 3415559999. La anterior quedó reemplazada.',
            'Quedaron dos tarjetas para marcar como hechas las dos copias del recordatorio "Llamar a Herramientas del Interior". Confirmá las dos para cerrarlas.',
            self::TEXTO_DE_LUCAS,
            /* Condicionado a confirmar, negado, repaso. */
            'Al confirmar, el gasto queda registrado.',
            'Quedó registrado al confirmar la tarjeta.',
            'Todavía no lo cargué: me falta el monto.',
            'No quedó registrado porque la caja está cerrada.',
            'Ayer te cargué el gasto del alquiler.',
            /* Los verbos genéricos sin su objeto. */
            'Pasé la tarjeta al cliente Juan.',
            'Te pasé el detalle de las ventas.',
            'Te armé el resumen: 3 ventas con tarjeta y 2 en efectivo.',
            'Hice la cuenta: te da $45.000.',
            'Cambié de idea: mejor lo vemos mañana.',
            'Pasado mañana vence el alquiler.',
            'El cargo por usar la tarjeta lo absorbe el negocio.',
            'El registro de ventas de hoy da $45.000.',
            'El proveedor lo cambió por otro modelo.',
            'Creo que conviene cargarlo mañana.',
            /* Renglones de lista: los datos de una consulta. */
            "Tenés estas tareas:\n- Tarea cargada: Llamar a Juan\n1. Gasto registrado el lunes por \$5.000",
            'Cuando quieras.',
            /* Los textos honestos no se vuelven a disparar. */
            TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA,
            TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA_CON_TARJETA,
            TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA_CON_HISTORIA,
            TarjetaPrometidaIaHelper::TEXTO_HONESTO,
            '',
        ];

        foreach ($no_afirman as $texto) {
            $this->assertSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto), 'No tendría que afirmar una carga: ' . $texto);
        }
    }

    /**
     * 8 — Los dos datos del pedido de la persona que usa la excepción del repaso (E2) y la de la
     * pregunta (E1), y las dos notas del reintento.
     *
     * @group chat-ia
     * @test
     */
    public function el_acuse_la_pregunta_y_las_notas_del_reintento()
    {
        foreach (['Gracias!', 'Muchas gracias', 'Genial, mil gracias 👍', 'Dale', 'Okk', '👍', 'Bárbaro', '¡Gracias, máquina!', 'Joya, gracias'] as $acuse) {
            $this->assertTrue(TarjetaPrometidaIaHelper::es_acuse($acuse), 'Es un acuse: ' . $acuse);
        }

        /* 🔴 Un mensaje sin texto es una foto sola: casi siempre, el pedido de OTRA carga. */
        foreach (['', 'Gracias, ahora cargame otro', 'Pasala al miércoles', 'no, eran 6000', 'Ya lo llamé', 'gracias gracias gracias gracias gracias gracias gracias'] as $no_acuse) {
            $this->assertFalse(TarjetaPrometidaIaHelper::es_acuse($no_acuse), 'No es un acuse: ' . $no_acuse);
        }

        $this->assertTrue(TarjetaPrometidaIaHelper::es_pregunta('¿Quedó agendada?'));
        $this->assertTrue(TarjetaPrometidaIaHelper::es_pregunta('quedó?'));
        $this->assertFalse(TarjetaPrometidaIaHelper::es_pregunta('Pasala al miércoles'));

        $sin_carga = TarjetaPrometidaIaHelper::nota_para_reintentar_carga(['proponer_gasto', 'proponer_gasto'], false);

        $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $sin_carga);
        $this->assertStringContainsString(self::NOTA_SIN_CARGA, $sin_carga);
        $this->assertStringContainsString('Pediste proponer_gasto y ninguna terminó en una carga', $sin_carga);
        $this->assertStringContainsString('No le menciones este aviso a la persona.', $sin_carga);

        $this->assertStringNotContainsString('Pediste', TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false));

        $con_tarjeta = TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], true);

        $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $con_tarjeta);
        $this->assertStringContainsString(self::NOTA_CON_TARJETA, $con_tarjeta);
        $this->assertStringNotContainsString(self::NOTA_SIN_CARGA, $con_tarjeta);
        $this->assertStringContainsString('No le menciones este aviso a la persona.', $con_tarjeta);
    }
}
