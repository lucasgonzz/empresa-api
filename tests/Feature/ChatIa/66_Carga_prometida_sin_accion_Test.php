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
use Illuminate\Http\Client\ConnectionException;
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
 * Lo que decide si dispara es el PEDIDO de la persona (correcciones del chequeo independiente, mismo
 * 10/10/2026; ver AsistenteIaService::afirma_una_carga_que_no_se_hizo): un pedido de carga —también en
 * forma de pregunta— dispara siempre; una consulta, sólo con una tarjeta de la respuesta anterior sin
 * confirmar; un "gracias" o un "dale", salvo que la respuesta anterior haya contado una carga hecha (y,
 * el "dale", que no haya terminado ofreciendo otra cosa).
 *
 * Lo que protege este archivo, con un doble del modelo que devuelve esos textos SIN herramienta:
 *
 * - el caso literal de Lucas en "resuelto" (lo sigue atajando la guarda de la tarjeta, con su nota);
 * - que en "directo" el turno reintente UNA vez, con la nota de la CARGA y en el Profundo, y que si el
 *   reintento hace la carga la persona lea el texto del modelo con la carga ejecutada en la base;
 * - que si el reintento también miente, la respuesta se REEMPLACE sólo en la mentira segura (una carga
 *   pedida, ninguna herramienta, la primera respuesta de la conversación, nada confirmado, ninguna
 *   tarjeta esperando: "No pude hacer esa carga…") y en todo lo demás quede con el aviso verdadero que
 *   corresponde (tercera y cuarta vuelta, 10/10/2026): con la tarjeta de este mensaje, el de la
 *   corrección, con la de la respuesta anterior, o el de verificación — nunca un "no pude" sobre algo
 *   que puede estar hecho;
 * - que la nota del reintento mande a mirar lo que ya existía ANTES de cargar (cuarta vuelta: el
 *   reintento real que creó una tarea de más), y que los avisos no viajen en el historial ni queden
 *   en una respuesta donde la guarda no los puso;
 * - los agujeros que encontraron los chequeos: el pedido en forma de pregunta, el "dale" a un
 *   ofrecimiento, la mentira después de una consulta preparatoria, el "gracias" con la tarjeta sin
 *   confirmar;
 * - que el reintento sea UNO por turno aunque lo pidan las dos guardas, y que si falla no llegue como
 *   un error;
 * - que NO dispare cuando no corresponde: el repaso de lo recién hecho, la consulta, la pregunta por
 *   algo de otra conversación, la carga ejecutada en el turno, la confirmación determinista, WhatsApp y
 *   sin acciones; y que la guarda de la TARJETA ya no le borre a la persona la pregunta por la caja;
 * - y los detectores, oración por oración, con los textos medidos y los de los chequeadores.
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

    /**
     * Los dos falsos positivos de la guarda de la TARJETA, medidos con esta rama contra DeepSeek real
     * ("Gracias!" con el gasto esperando la caja). El primero, tal cual; del segundo la medición guardó
     * la cola ("…Decime cuál y dejo la tarjeta para confirmar."), y el comienzo es el de su contexto.
     */
    const TARJETA_FALSA_1 = 'Si querés que arme la carga, decime a qué caja va la plata y dejo la tarjeta para que la confirmes.';
    const TARJETA_FALSA_2 = 'Hay dos cajas con efectivo: Caja Efectivo y Caja Mercado Pago. Decime cuál y dejo la tarjeta para confirmar.';

    /**
     * La frase que distingue la nota de la carga sin tarjeta en el mensaje. Cuarta vuelta (10/10/2026):
     * la nota empieza distinto ("…pero con este mensaje no se registró nada.") y manda a mirar lo que ya
     * existía antes de cargar; la de la corrección quedó como estaba (NOTA_SIN_CARGA_DE_LA_CORRECCION).
     */
    const NOTA_SIN_CARGA = 'con este mensaje no se registró nada';

    /** La frase con que sigue empezando la nota de la carga en una corrección (no cambió en la cuarta vuelta). */
    const NOTA_SIN_CARGA_DE_LA_CORRECCION = 'NO se ejecutó ninguna carga';

    /** B, cuarta vuelta: lo que ya figuraba registrado no se carga de nuevo. */
    const NOTA_NO_LO_CARGUES = 'Si lo que dijiste ya figuraba registrado ANTES de este mensaje, NO lo cargues de nuevo';

    /** B, cuarta vuelta: un cambio sobre algo que ya existe se busca primero. */
    const NOTA_BUSCALO_PRIMERO = 'buscalo primero con la consulta que corresponde (consultar_tareas, consultar_proveedores…) y trabajá sobre ése: no crees uno nuevo';

    /** B, cuarta vuelta: recién al final, la carga nueva. */
    const NOTA_CARGA_NUEVA = 'Si es una carga nueva, llamá ahora a la herramienta proponer_ que corresponde';

    /** La frase que distingue la nota de la carga con una tarjeta de este mensaje esperando el botón. */
    const NOTA_CON_TARJETA = 'quedó como tarjeta para que la persona la confirme';

    /** La frase que suma la nota de la carga cuando la tarjeta que espera es de la respuesta anterior. */
    const NOTA_DE_ANTES = 'tarjeta que dejaste antes sigue sin confirmar';

    /** La frase de la nota de la carga en una corrección (M3, tercera vuelta). */
    const NOTA_DE_CORRECCION = 'La persona está corrigiendo la tarjeta que dejaste antes: armá una tarjeta NUEVA';

    /** La frase de la nota de la carga para algo que ya figuraba registrado (G1, tercera vuelta). */
    const NOTA_DEL_REPASO = 'decí con qué número y fecha figura';

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
     * @param  string  $respuesta_anterior
     * @return array{0: AiConversation, 1: AiMessage, 2: Pending}
     */
    protected function tarea_confirmada_en_el_mensaje_anterior($pedido_nuevo, Carbon $fecha, $respuesta_anterior = 'Dejé la tarjeta para agendar la llamada.')
    {
        $detalle = 'Llamar a Herramientas del Interior P66';

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Recordame llamar a Herramientas del Interior', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => $respuesta_anterior, 'estado' => 'listo', 'acciones_habilitadas' => true]);

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
     * Una conversación donde la respuesta anterior dejó la tarjeta de un gasto por el despacho REAL
     * (proponer_gasto, en "resuelto") y la tarjeta sigue `propuesta`. Después, el pedido nuevo y el
     * assistant pendiente.
     *
     * @param  string  $pedido_nuevo
     * @param  float  $monto
     * @return array{0: AiConversation, 1: AiMessage, 2: AiMessageAction}
     */
    protected function gasto_propuesto_en_el_mensaje_anterior($pedido_nuevo, $monto = 15000)
    {
        $comisiones = $this->subcategoria('Comisiones bancarias P66');

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Gasté 15000 de comisiones bancarias, en efectivo', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del gasto para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($comisiones, 'toolu_p66_original', $monto)['input'], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error'], 'La propuesta del fixture tendría que haber dejado la tarjeta.');

        $tarjeta = AiMessageAction::where('ai_message_id', $anterior->id)->firstOrFail();

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->estado_guardado(), 'En "resuelto" el gasto queda como tarjeta.');

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => $pedido_nuevo, 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant, $tarjeta];
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
     * @param  string  $frase  NOTA_SIN_CARGA, NOTA_CON_TARJETA o NOTA_DE_ANTES.
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

    /**
     * 🔴 El agujero del pedido en forma de PREGUNTA (chequeo del 10/10/2026): "¿Me la pasás al
     * miércoles?" es un pedido de carga, no una consulta. "Listo, la pasé al miércoles." sin herramienta
     * reintenta igual que con "Pasala al miércoles", y el reintento mueve la tarea.
     *
     * @group chat-ia
     * @test
     */
    public function un_pedido_de_carga_en_forma_de_pregunta_tambien_reintenta()
    {
        $martes    = $this->martes();
        $miercoles = $martes->copy()->addDay();

        list($conversation, $assistant, $tarea) = $this->tarea_confirmada_en_el_mensaje_anterior('¿Me la pasás al miércoles?', $martes);

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, la pasé al miércoles.'), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('proponer_cambios_en_tarea', ['tarea_id' => $tarea->id, 'fecha' => $miercoles->format('Y-m-d')], 'toolu_p66_cambio')]), 200)
                ->push($this->end_turn('Listo, la tarea quedó para el miércoles.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Listo, la tarea quedó para el miércoles.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $this->tarjetas_de($assistant)[0]->estado_guardado());
        $this->assertSame($miercoles->format('Y-m-d'), $this->fecha_de($tarea));
    }

    /**
     * 🔴 El agujero de la CONSULTA PREPARATORIA (chequeo del 10/10/2026): "Pasala al miércoles" →
     * consultar_tareas → "Quedó pasada al miércoles." sin proponer_. Que el turno haya consultado no
     * vuelve verdad el "quedó": reintenta, y si el reintento insiste, la respuesta queda con el aviso de
     * verificación (el turno pidió una herramienta: no es la mentira segura). La tarea sigue el martes.
     *
     * @group chat-ia
     * @test
     */
    public function la_mentira_despues_de_una_consulta_preparatoria_reintenta()
    {
        $martes = $this->martes();

        list($conversation, $assistant, $tarea) = $this->tarea_confirmada_en_el_mensaje_anterior('Pasala al miércoles', $martes);

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->tool_use('consultar_tareas', [], 'toolu_p66_consulta')]), 200)
                ->push($this->end_turn('Quedó pasada al miércoles.'), 200)
                ->push($this->end_turn('Quedó pasada al miércoles 14/10.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Quedó pasada al miércoles 14/10.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'La consulta, el texto que miente y un solo reintento.');
        $this->assert_reintento_de_la_carga($bodies[2], self::NOTA_SIN_CARGA);
        $this->assertStringNotContainsString('Pediste', $this->ultimo_turno_como_texto($bodies[2]), 'Una consulta no es una herramienta de carga.');
        $this->assertSame($martes->format('Y-m-d'), $this->fecha_de($tarea), 'Nadie movió la tarea.');
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

        /*
         * 🔴 B (cuarta vuelta): la nota manda a buscar la tarea que ya existe ANTES de cargar. Medido con
         * la tercera vuelta, en "directo", a este mismo "Ya lo llamé": el reintento llamó a proponer_tarea
         * antes que a consultar_tareas, creó una tarea de más y marcó las dos hechas.
         */
        $nota = $this->ultimo_turno_como_texto($bodies[1]);

        $this->assertStringContainsString(self::NOTA_NO_LO_CARGUES, $nota);
        $this->assertStringContainsString(self::NOTA_BUSCALO_PRIMERO, $nota);
        $this->assertLessThan(mb_strpos($nota, self::NOTA_CARGA_NUEVA), mb_strpos($nota, self::NOTA_BUSCALO_PRIMERO), 'Primero buscar lo que existe, después cargar.');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) use ($assistant) {
            return strpos($mensaje, 'se reintenta una vez') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['tipo_de_afirmacion'] === 'hecho'
                && $contexto['tarjetas_propuestas'] === 0
                && $contexto['clase_del_pedido'] === 'otro'
                && $contexto['tarjeta_pendiente_de_antes'] === false
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
                && $contexto['accion'] === 'reemplazo'
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
     * 4 — La variante: el reintento INSISTE en que quedó registrado. La respuesta del modelo queda y se
     * le agrega el aviso de que la carga todavía espera la tarjeta (tercera vuelta: ya no se reemplaza
     * fuera de la mentira segura). En la base la tarjeta sigue esperando y no hay ningún gasto.
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

        $this->assertSame("Listo, el gasto quedó registrado.\n\n" . TarjetaPrometidaIaHelper::AVISO_CARGA_CON_TARJETA, $texto);
        $this->assertCount(3, $this->bodies_enviados(), 'Un solo reintento: nunca dos.');

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado(), 'La tarjeta sigue esperando el botón.');
        $this->assertSame(0, Expense::where('user_id', $this->comercio->id)->count(), 'No se registró ningún gasto.');
    }

    /**
     * 🔴 La tarjeta de la RESPUESTA ANTERIOR sigue sin confirmar y la persona pregunta "¿Ya quedó
     * cargado?": una consulta, pero "Sí, quedó registrado." miente. Reintenta con la nota que dice que la
     * tarjeta de antes sigue esperando y, si insiste, la respuesta queda con el aviso de que la tarjeta
     * de antes todavía no está confirmada. La tarjeta sigue `propuesta` y no hay gasto.
     *
     * @group chat-ia
     * @test
     */
    public function una_consulta_con_la_tarjeta_de_antes_sin_confirmar_reintenta_y_avisa_que_la_tarjeta_sigue_esperando()
    {
        list($conversation, $assistant, $tarjeta) = $this->gasto_propuesto_en_el_mensaje_anterior('¿Ya quedó cargado?');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Sí, quedó registrado.'), 200)
                ->push($this->end_turn('Sí, ya quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Sí, ya quedó registrado el gasto.\n\n" . TarjetaPrometidaIaHelper::AVISO_TARJETA_DE_ANTES, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'Un solo reintento: nunca dos.');
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_DE_ANTES);

        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado_guardado(), 'La tarjeta de antes sigue esperando el botón.');
        $this->assertSame(0, Expense::where('user_id', $this->comercio->id)->count(), 'No se registró ningún gasto.');
    }

    /**
     * 🔴 El "gracias" con la tarjeta SIN confirmar (chequeo del 10/10/2026): "Gracias!" → "De nada, quedó
     * registrado el gasto." miente — la tarjeta de la respuesta anterior sigue esperando. Dispara, y si el
     * reintento insiste, la respuesta queda con el aviso de que la tarjeta de antes no está confirmada.
     *
     * La respuesta anterior registró OTRA carga además (una tarea, confirmada): aunque haya una carga
     * hecha que agradecer, la tarjeta que sigue esperando manda.
     *
     * @group chat-ia
     * @test
     */
    public function un_gracias_con_la_tarjeta_de_antes_sin_confirmar_dispara()
    {
        list($conversation, $assistant, $tarjeta) = $this->gasto_propuesto_en_el_mensaje_anterior('Gracias!');

        AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $tarjeta->ai_message_id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea_nueva:p66-hecha',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Tarea P66', 'renglones' => [], 'aviso' => null],
            'resuelta_at'        => Carbon::now(),
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('De nada, quedó registrado el gasto.'), 200)
                ->push($this->end_turn('De nada, el gasto quedó registrado.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("De nada, el gasto quedó registrado.\n\n" . TarjetaPrometidaIaHelper::AVISO_TARJETA_DE_ANTES, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_DE_ANTES);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado_guardado());
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
     * confirmada, no es la mentira segura y la respuesta no se reemplaza: queda con el aviso de
     * verificación (nunca un "no pude", que invitaría a repetir algo que puede estar hecho).
     *
     * @group chat-ia
     * @test
     */
    public function en_directo_quedo_cambiado_tras_un_alta_y_un_reintento_que_insiste_deja_el_texto_con_el_aviso()
    {
        list($conversation, $assistant, $proveedor) = $this->proveedor_dado_de_alta_antes();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn(self::CAMBIADO_MEDIDO), 200)
                ->push($this->end_turn('Listo, el teléfono de Distribuidora Norte quedó cambiado a 3415559999.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Listo, el teléfono de Distribuidora Norte quedó cambiado a 3415559999.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertStringNotContainsString('No pude', $texto);

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
     * cambié a 6000." sin herramienta. Reintenta con la nota de la carga —que en una corrección pide
     * una tarjeta NUEVA con el dato corregido, sin mandar a confirmar la vieja (M3)—; el reintento rearma
     * la tarjeta con el dato nuevo. El turno arranca
     * en el Profundo (la corrección ya escalaba) y el reintento también.
     *
     * @group chat-ia
     * @test
     */
    public function la_correccion_de_una_tarjeta_pendiente_sin_herramienta_reintenta()
    {
        list($conversation, $assistant) = $this->gasto_propuesto_en_el_mensaje_anterior('no, eran 6000', 5000);

        $comisiones = ExpenseConcept::where('user_id', $this->comercio->id)->firstOrFail();

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, lo cambié a 6000.'), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($comisiones, 'toolu_p66_correccion', 6000)]), 200)
                ->push($this->end_turn('Te dejé la tarjeta corregida con 6000 para que la confirmes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta corregida con 6000 para que la confirmes.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);
        $this->assertSame(config('services.deepseek.model_profundo'), $bodies[0]['model'], 'La corrección de una tarjeta pendiente ya arranca escalada.');
        $this->assertSame('Listo, lo cambié a 6000.', $this->turno_del_asistente_del_reintento($bodies[1]));
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA_DE_LA_CORRECCION);
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_DE_CORRECCION);
        $this->assertStringNotContainsString(self::NOTA_NO_LO_CARGUES, $this->ultimo_turno_como_texto($bodies[1]), 'La nota de la corrección quedó como estaba.');
        $this->assertStringNotContainsString(self::NOTA_DE_ANTES, $this->ultimo_turno_como_texto($bodies[1]), 'En una corrección la nota no manda a confirmar la tarjeta vieja.');

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas, 'El reintento dejó la tarjeta corregida en este mensaje.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());
    }

    /**
     * 6 — Si el reintento de la corrección INSISTE, la respuesta NO queda con el aviso de "la tarjeta de
     * antes": la de antes tiene el dato viejo (5000) y la mandaría a confirmar lo que acaba de corregir.
     * Queda con el aviso de la CORRECCIÓN (cuarta vuelta, 10/10/2026: hasta la tercera era el de
     * verificación, que no decía que la tarjeta que espera tiene el dato viejo). Tampoco es la mentira
     * segura (hay una tarjeta esperando, y no es la primera respuesta).
     *
     * @group chat-ia
     * @test
     */
    public function la_correccion_que_insiste_no_manda_a_confirmar_la_tarjeta_vieja()
    {
        list($conversation, $assistant, $tarjeta) = $this->gasto_propuesto_en_el_mensaje_anterior('no, eran 6000', 5000);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, lo cambié a 6000.'), 200)
                ->push($this->end_turn('Listo, ya lo cambié a 6000.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Listo, ya lo cambié a 6000.\n\n" . TarjetaPrometidaIaHelper::AVISO_CORRECCION, $texto);
        $this->assertCount(2, $this->bodies_enviados());
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjeta->fresh()->estado_guardado(), 'La tarjeta vieja sigue como estaba: nadie la confirmó.');
    }

    // ---------------------------------------------------------------------
    // El "dale" a un ofrecimiento
    // ---------------------------------------------------------------------

    /**
     * 🔴 El agujero del "DALE" A UN OFRECIMIENTO (chequeo del 10/10/2026): la respuesta anterior contó
     * una carga hecha y terminó ofreciendo otra ("¿Querés que también te agende el pago de la luz?", o
     * sin signo: "Si querés, también te agendo el pago."). El "Dale" es un pedido nuevo, y "Listo,
     * agendado el recordatorio…" sin herramienta miente: reintenta. Con la misma carga hecha pero SIN
     * ofrecimiento, el "dale" es un acuse y el texto queda.
     *
     * @group chat-ia
     * @test
     */
    public function un_dale_a_un_ofrecimiento_reintenta_y_un_dale_sin_ofrecimiento_no()
    {
        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, agendado el recordatorio para el lunes 12/10.'), 200)
                ->push($this->end_turn('¿Para qué día te agendo el pago de la luz?'), 200)
                ->push($this->end_turn('Listo, agendado el pago de la luz para el lunes 12/10.'), 200)
                ->push($this->end_turn('¿Para qué día te agendo el pago de la luz?'), 200)
                ->push($this->end_turn('Listo, quedó registrado.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach (['Quedó agendada la tarea para el martes. ¿Querés que también te agende el pago de la luz?', 'Quedó agendada la tarea para el martes. Si querés, también te agendo el pago de la luz.'] as $indice => $ofrecimiento) {

            list($conversation, $assistant) = $this->conversacion_con_historia([
                ['Recordame el martes llamar a Juan', $ofrecimiento, AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_TAREA_NUEVA],
            ], 'Dale');

            $this->assertSame('¿Para qué día te agendo el pago de la luz?', (new AsistenteIaService())->responder($conversation, $assistant));

            $bodies = $this->bodies_enviados();

            $this->assertCount(2 * ($indice + 1), $bodies, 'El "dale" a un ofrecimiento: el texto que miente y el reintento.');
            $this->assert_reintento_de_la_carga($bodies[2 * $indice + 1], self::NOTA_SIN_CARGA);
        }

        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Recordame el martes llamar a Juan', 'Quedó agendada la tarea para el martes.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_TAREA_NUEVA],
        ], 'Dale');

        $this->assertSame('Listo, quedó registrado.', (new AsistenteIaService())->responder($conversation, $assistant));

        $this->assertCount(5, $this->bodies_enviados(), 'El "dale" sin ofrecimiento: una sola llamada, ningún reintento.');
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
     * El MISMO texto afirma una tarjeta y una carga ("Dejé la tarjeta… Quedó registrado…") y el mensaje
     * no tiene ninguna acción: la nota es la de la TARJETA, que le pide justamente armarla. El reintento
     * la arma y la persona lee el texto del modelo.
     *
     * @group chat-ia
     * @test
     */
    public function un_texto_que_afirma_tarjeta_y_carga_sin_ninguna_accion_lleva_la_nota_de_la_tarjeta()
    {
        $alquiler = $this->subcategoria();

        list($conversation, $assistant) = $this->conversacion('Gasté 400000 de alquiler, en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Dejé la tarjeta para confirmar el gasto. Quedó registrado con fecha de hoy.'), 200)
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn('Te dejé la tarjeta del gasto para que la confirmes.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Te dejé la tarjeta del gasto para que la confirmes.', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies);

        $nota = $this->ultimo_turno_como_texto($bodies[1]);

        $this->assertStringContainsString(self::NOTA_DE_LA_TARJETA, $nota, 'La nota es la de la tarjeta.');
        $this->assertStringNotContainsString(self::NOTA_SIN_CARGA, $nota);
        $this->assertSame(1, $this->tarjetas_de($assistant)->count());
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
     * Lo mismo con una falla de RED en el reintento de la carga (la llamada tira excepción): la persona
     * lee el texto honesto, y el log del reintento que falla dice que era el de la carga.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_reintento_de_la_carga_se_corta_por_la_red_la_persona_lee_el_texto_honesto()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        $llamadas = 0;
        $primera  = $this->end_turn(self::MARQUE_MEDIDO);

        Http::fake([
            'api.deepseek.com/*' => function () use (&$llamadas, $primera) {
                $llamadas++;

                if ($llamadas === 1) {
                    return Http::response($primera, 200);
                }

                throw new ConnectionException('se cortó la red (P66)');
            },
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion('Ya lo llamé');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA, $texto);
        $this->assertSame(2, $llamadas);

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'falló el reintento') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['excepcion'] === ConnectionException::class;
        })->once();
    }

    /**
     * 🔴 'hecho' después de sólo una consulta es mentira igual: "Marqué la tarea como hecha" tras un
     * consultar_tareas (la consulta no marca nada). Reintenta, y la nota no nombra ninguna herramienta
     * de carga porque no hubo.
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
     * 🔴 7 — EL CASO MEDIDO: en "resuelto", con la tarjeta del gasto ya CONFIRMADA en la respuesta
     * anterior, "Gracias!" → "Quedó registrado el gasto de Comisiones bancarias por $15.000…" sin
     * herramientas. Es VERDAD: una sola llamada y el texto intacto. Lo mismo con un 'hecho' ("ya te
     * cargué el gasto") y con los agradecimientos del chequeo ("Gracias genio!").
     *
     * @group chat-ia
     * @test
     */
    public function un_gracias_despues_de_una_carga_confirmada_no_dispara()
    {
        $textos = [
            ['Gracias!', self::GRACIAS_MEDIDO],
            ['Genial, mil gracias 👍', 'De nada: ya te cargué el gasto de $15.000.'],
            ['Gracias genio!', 'De nada, quedó registrado el gasto.'],
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
     * 🔴 El "gracias" después de una CONFIRMACIÓN DETERMINISTA: el "dale" confirmó una tarjeta de un
     * mensaje más viejo, la respuesta que lo contó ("Listo, quedó registrado el gasto.") no tiene tarjeta
     * propia, y el "Gracias!" siguiente → "De nada, quedó registrado el gasto." es verdad. No dispara.
     *
     * @group chat-ia
     * @test
     */
    public function un_gracias_despues_de_una_confirmacion_determinista_no_dispara()
    {
        $comisiones = $this->subcategoria('Comisiones bancarias P66');

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Gasté 15000 de comisiones bancarias, en efectivo', 'estado' => 'listo']);

        $con_la_tarjeta = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del gasto para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($comisiones, 'toolu_p66_gasto', 15000)['input'], $conversation, $con_la_tarjeta);

        $this->assertFalse($propuesta['is_error']);

        $tarjeta = AiMessageAction::where('ai_message_id', $con_la_tarjeta->id)->firstOrFail();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'dale', 'estado' => 'listo']);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Listo, quedó registrado el gasto.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        /* Lo que hizo la confirmación determinista mientras se escribía esa respuesta. */
        ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $tarjeta->id);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $tarjeta->fresh()->estado_guardado());

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Gracias!', 'estado' => 'listo']);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('De nada, quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $this->assertSame('De nada, quedó registrado el gasto.', (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(1, $this->bodies_enviados(), 'Sin reintento.');
    }

    /**
     * 7 — Una CONSULTA que cuenta lo que leyó (medido: tras `consultar_tareas`, "Quedaron cargadas dos
     * veces con el mismo detalle"). Sin reintento: la herramienta y el texto, dos llamadas.
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
     * 7 — Una PREGUNTA por un estado: "Sí, quedó agendada para el martes." Con la tarea confirmada en la
     * respuesta anterior y sin nada antes: una llamada por pedido y el texto intacto. Sin una tarjeta de
     * antes esperando, una consulta no dispara.
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
     * 🔴 "¿Lo cargaste?" sin nada en la conversación (chequeo del 10/10/2026): el modelo puede estar
     * contando algo de OTRA conversación ("Sí, lo registré el lunes"), y un "no pude" ahí invitaría a
     * duplicar una carga que sí existe. Una consulta sin tarjeta de antes no dispara: una llamada por
     * pedido, el texto intacto (también con un 'hecho' que el detector ve: "Sí, lo registré.").
     *
     * @group chat-ia
     * @test
     */
    public function una_pregunta_por_algo_de_otra_conversacion_no_dispara()
    {
        $textos = ['Sí, lo registré el lunes.', 'Sí, lo registré.'];

        $secuencia = $this->secuencia();

        foreach ($textos as $texto_del_modelo) {
            $secuencia->push($this->end_turn($texto_del_modelo), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ($textos as $texto_del_modelo) {

            list($conversation, $assistant) = $this->conversacion('¿Lo cargaste?');

            $this->assertSame($texto_del_modelo, (new AsistenteIaService())->responder($conversation, $assistant));
        }

        $this->assertCount(count($textos), $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
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
     * 7 — En "resuelto", el texto da la carga por registrada en una oración pero en la otra le dice a la
     * persona que dejó la tarjeta, y la tarjeta ESTÁ en el mensaje ("Dejé la tarjeta para confirmar el
     * gasto. Quedó registrado con fecha de hoy."): la persona tiene la tarjeta y el texto se la nombra.
     * Sin reintento.
     *
     * @group chat-ia
     * @test
     */
    public function un_texto_que_tambien_dice_la_tarjeta_con_la_tarjeta_en_el_mensaje_no_dispara()
    {
        $alquiler = $this->subcategoria();

        list($conversation, $assistant) = $this->conversacion('Gasté 400000 de alquiler, en efectivo');

        $texto_del_modelo = 'Dejé la tarjeta para confirmar el gasto. Quedó registrado con fecha de hoy.';

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($alquiler)]), 200)
                ->push($this->end_turn($texto_del_modelo), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $this->assertSame('quedo', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto_del_modelo), 'El detector ve la carga en la segunda oración: la que decide es la regla de la tarjeta.');

        $this->assertSame($texto_del_modelo, (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertCount(2, $this->bodies_enviados(), 'La herramienta y el texto: ningún reintento.');
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $this->tarjetas_de($assistant)[0]->estado_guardado());
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

    /**
     * 🔴 LOS DOS FALSOS POSITIVOS DE LA GUARDA DE LA TARJETA (medidos con esta rama contra DeepSeek real,
     * 10/10/2026): "Gracias!" con el gasto esperando la caja → "…decime a qué caja va la plata y dejo la
     * tarjeta para que la confirmes." La guarda de la tarjeta lo cambiaba por "No pude armar la tarjeta…"
     * y a la persona se le borraba la pregunta por la caja. Ahora: una llamada y el texto intacto.
     *
     * @group chat-ia
     * @test
     */
    public function la_guarda_de_la_tarjeta_no_le_borra_a_la_persona_la_pregunta_por_la_caja()
    {
        $textos = [self::TARJETA_FALSA_1, self::TARJETA_FALSA_2];

        $secuencia = $this->secuencia();

        foreach ($textos as $texto_del_modelo) {
            $secuencia->push($this->end_turn($texto_del_modelo), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ($textos as $texto_del_modelo) {

            list($conversation, $assistant) = $this->conversacion_con_historia([
                ['Gasté 15000 de comisiones bancarias, en efectivo', '¿A qué caja va el pago en efectivo: Caja Efectivo o Caja Mercado Pago?', null, AiMessageAction::TIPO_GASTO],
            ], 'Gracias!');

            $this->assertSame($texto_del_modelo, (new AsistenteIaService())->responder($conversation, $assistant));
        }

        $this->assertCount(count($textos), $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    // ---------------------------------------------------------------------
    // Tercera vuelta · el reemplazo sólo en la mentira segura, el aviso en lo demás
    // ---------------------------------------------------------------------

    /**
     * 🔴 LA MENTIRA SEGURA: conversación nueva, la persona pide una carga, el turno no pide ninguna
     * herramienta, no hay nada confirmado ni ninguna tarjeta esperando, y el modelo dice "Listo, quedó
     * registrado el gasto." dos veces. Ahí la frase no puede estar contando nada: se reemplaza por
     * TEXTO_HONESTO_CARGA. El log dice que fue un reemplazo.
     *
     * @group chat-ia
     * @test
     */
    public function la_mentira_segura_se_reemplaza_por_el_texto_honesto()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Gasté 15000 de comisiones bancarias, en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA, $texto);
        $this->assertStringContainsString('con este mensaje no quedó nada registrado', $texto);
        $this->assertCount(2, $this->bodies_enviados());

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se reemplaza por el texto honesto') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['accion'] === 'reemplazo'
                && $contexto['texto_honesto'] === TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA;
        })->once();
    }

    /**
     * 🔴 El pedido repetido de algo que YA EXISTE (rechequeo de la tercera vuelta): "Cargame el gasto de
     * la luz" → el modelo consulta y contesta "Ya está cargado: Gasto N° 7…" (dos veces). Puede ser
     * verdad —ese gasto se cargó en otra conversación—, así que la respuesta queda y se le agrega el
     * aviso de verificación: nunca un "No pude…", que invitaría a cargarlo de nuevo y DUPLICARLO.
     *
     * @group chat-ia
     * @test
     */
    public function lo_que_ya_existia_queda_con_el_aviso_de_verificacion_y_nunca_con_no_pude()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Cargame el gasto de la luz de 30000');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->tool_use('consultar_opciones_de_carga', [], 'toolu_p66_consulta')]), 200)
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000 en Luz, de hoy.'), 200)
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Ya está cargado: Gasto N° 7 por $30.000.' . "\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertStringNotContainsString('No pude', $texto);
        $this->assertCount(3, $this->bodies_enviados());

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se le agrega un aviso') !== false
                && isset($contexto['clase']) && $contexto['clase'] === 'carga'
                && $contexto['accion'] === 'aviso'
                && $contexto['aviso'] === TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION;
        })->once();
    }

    /**
     * El aviso nunca va dos veces: si el reintento ya lo trae (el modelo lo copió), la respuesta queda
     * con UNO. Cuarta vuelta (11): el reintento dice otra cosa que el primer texto —así se ve que la
     * respuesta es la del reintento y no la de antes— y el turno hizo exactamente dos llamadas.
     *
     * @group chat-ia
     * @test
     */
    public function el_aviso_no_se_duplica()
    {
        list($conversation, $assistant) = $this->tarea_confirmada_en_el_mensaje_anterior('Pasala al miércoles', $this->martes());

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Quedó pasada al miércoles.'), 200)
                ->push($this->end_turn("Listo, la pasé al miércoles 14/10.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Listo, la pasé al miércoles 14/10.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertSame(1, substr_count($texto, TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION));
        $this->assertCount(2, $this->bodies_enviados(), 'El texto que miente y el reintento: ninguna llamada de más.');
    }

    /**
     * 🔴 La tarjeta con el aviso de una CARGA PARECIDA (G6 del rechequeo): hace un momento se confirmó el
     * mismo gasto y el modelo lo cuenta ("Ese gasto ya lo registré recién (Gasto N° 1)."). La tarjeta
     * nueva ya lleva el aviso de AccionesIaHelper y el texto es verdad: no dispara.
     *
     * @group chat-ia
     * @test
     */
    public function la_tarjeta_con_el_aviso_de_carga_parecida_no_dispara()
    {
        $comisiones = $this->subcategoria('Comisiones bancarias P66');

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Gasté 15000 de comisiones bancarias, en efectivo', 'estado' => 'listo']);

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejé la tarjeta del gasto para confirmar.', 'estado' => 'listo', 'acciones_habilitadas' => true]);

        $propuesta = HerramientasDeCarga::ejecutar('proponer_gasto', $this->bloque_proponer_gasto($comisiones, 'toolu_p66_gasto', 15000)['input'], $conversation, $anterior);

        $this->assertFalse($propuesta['is_error']);

        $vieja = AiMessageAction::where('ai_message_id', $anterior->id)->firstOrFail();

        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'Gasté 15000 de comisiones bancarias, en efectivo', 'estado' => 'listo']);

        /* La persona confirmó la de antes con el botón DESPUÉS de mandar el pedido nuevo: la carga parecida. */
        ConfirmacionPorTextoIaHelper::confirmar_como_el_boton($conversation, $vieja->id);

        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $vieja->fresh()->estado_guardado());

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->respuesta('tool_use', [$this->bloque_proponer_gasto($comisiones, 'toolu_p66_gasto_2', 15000)]), 200)
                ->push($this->end_turn('Ese gasto ya lo registré recién (Gasto N° 1).'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Ese gasto ya lo registré recién (Gasto N° 1).', $texto);
        $this->assertCount(2, $this->bodies_enviados(), 'La herramienta y el texto: ningún reintento.');

        $tarjetas = $this->tarjetas_de($assistant);

        $this->assertCount(1, $tarjetas);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $tarjetas[0]->estado_guardado());
        $this->assertStringContainsString(TarjetaPrometidaIaHelper::MARCA_DE_CARGA_PARECIDA, (string) $tarjetas[0]->presentacion['aviso'], 'La tarjeta nueva lleva el aviso de la carga parecida.');
    }

    /**
     * 🔴 Un HECHO más una pregunta es un pedido de carga (M2 del rechequeo): "Ya lo llamé, ¿qué más tengo
     * hoy?" → "Marqué la tarea como hecha. Hoy te queda pagar la luz." sin herramienta reintenta.
     *
     * @group chat-ia
     * @test
     */
    public function un_hecho_con_una_pregunta_tambien_reintenta()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Ya lo llamé, ¿qué más tengo hoy?');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Marqué la tarea como hecha. Hoy te queda pagar la luz.'), 200)
                ->push($this->end_turn('No encontré ninguna tarea para hoy: ¿cuál es la que llamaste?'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('No encontré ninguna tarea para hoy: ¿cuál es la que llamaste?', $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assert_reintento_de_la_carga($bodies[1], self::NOTA_SIN_CARGA);
    }

    /**
     * Los acuses de la tercera vuelta tras una carga confirmada ("Gracias Lucas", "Nada más, gracias") y
     * la consulta con "¿Me podés…?" sin nada antes no disparan: una llamada por pedido, el texto intacto.
     *
     * @group chat-ia
     * @test
     */
    public function las_despedidas_y_la_consulta_con_podes_no_disparan()
    {
        $casos = [
            ['Gracias Lucas', 'De nada, el gasto quedó registrado.', true],
            ['Nada más, gracias', '¡De nada! El gasto quedó registrado. Cualquier cosa, acá estoy.', true],
            ['¿Me podés decir si quedó registrado el pago?', 'Sí, quedó registrado el pago de $20.000.', false],
        ];

        $secuencia = $this->secuencia();

        foreach ($casos as $caso) {
            $secuencia->push($this->end_turn($caso[1]), 200);
        }

        Http::fake([
            'api.deepseek.com/*' => $secuencia,
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        foreach ($casos as $caso) {

            if ($caso[2]) {
                list($conversation, $assistant) = $this->conversacion_con_historia([
                    ['Gasté 15000 de comisiones bancarias, en efectivo', 'Quedó registrado el Gasto N° 1 por $15.000.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_GASTO],
                ], $caso[0]);
            } else {
                list($conversation, $assistant) = $this->conversacion($caso[0]);
            }

            $this->assertSame($caso[1], (new AsistenteIaService())->responder($conversation, $assistant), 'Pedido: ' . $caso[0]);
        }

        $this->assertCount(count($casos), $this->bodies_enviados(), 'Una llamada por pedido, ninguna de más.');
    }

    // ---------------------------------------------------------------------
    // Cuarta vuelta · la mentira segura sólo en la primera respuesta, la nota y los avisos
    // ---------------------------------------------------------------------

    /**
     * 🔴 A (cuarta vuelta): con HISTORIA en la conversación —aunque ninguna carga se haya confirmado— la
     * mentira no es segura. "Cargame el gasto de la luz de 30000" → "Ya está cargado: Gasto N° 7…" sin
     * ninguna herramienta, dos veces: el modelo puede saber de un turno anterior que el gasto ya estaba.
     * Queda la respuesta con el aviso de verificación, nunca "No pude…". Lo mismo en la primera respuesta
     * de una conversación que trae `contexto` (los datos de una sugerencia del mostrador).
     *
     * @group chat-ia
     * @test
     */
    public function con_historia_o_con_contexto_y_sin_herramientas_queda_el_aviso_y_no_el_reemplazo()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000.'), 200)
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000.'), 200)
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000.'), 200)
                ->push($this->end_turn('Ya está cargado: Gasto N° 7 por $30.000.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['¿Cuánto vendí hoy?', 'Hoy vendiste $45.000 en 12 ventas.', null, AiMessageAction::TIPO_GASTO],
        ], 'Cargame el gasto de la luz de 30000');

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Ya está cargado: Gasto N° 7 por $30.000.' . "\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertStringNotContainsString('No pude', $texto);

        list($conversation, $assistant) = $this->conversacion('Cargame el gasto de la luz de 30000');

        $conversation->contexto = 'Gastos del mes: luz $30.000 (Gasto N° 7).';
        $conversation->save();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('Ya está cargado: Gasto N° 7 por $30.000.' . "\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertCount(4, $this->bodies_enviados(), 'Dos pedidos, cada uno con su reintento.');

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se le agrega un aviso') !== false
                && $contexto['accion'] === 'aviso'
                && $contexto['aviso'] === TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION;
        })->twice();
    }

    /**
     * A (cuarta vuelta): la carga confirmada DOS mensajes atrás también saca el turno de la mentira
     * segura (no es la primera respuesta, y hay una carga confirmada de antes). Queda el aviso.
     *
     * @group chat-ia
     * @test
     */
    public function la_carga_confirmada_dos_mensajes_atras_tampoco_es_la_mentira_segura()
    {
        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Gasté 15000 de comisiones bancarias, en efectivo', 'Quedó registrado el Gasto N° 1 por $15.000.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_GASTO],
            ['¿Cuánto vendí hoy?', 'Hoy vendiste $45.000 en 12 ventas.', null, AiMessageAction::TIPO_GASTO],
        ], 'Gasté 15000 de comisiones bancarias, en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Listo, quedó registrado el gasto.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertCount(2, $this->bodies_enviados());
    }

    /**
     * Una herramienta pedida SÓLO en el reintento (una consulta) saca el turno de la mentira segura: el
     * modelo leyó algo, y lo que cuenta puede ser eso. Queda la respuesta con el aviso, no el reemplazo.
     *
     * @group chat-ia
     * @test
     */
    public function una_herramienta_pedida_solo_en_el_reintento_deja_el_aviso_y_no_el_reemplazo()
    {
        Log::spy();

        $this->en_modo(ConfianzaDelAgenteIaHelper::DIRECTO);

        list($conversation, $assistant) = $this->conversacion('Gasté 15000 de comisiones bancarias, en efectivo');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200)
                ->push($this->respuesta('tool_use', [$this->tool_use('consultar_opciones_de_carga', [], 'toolu_p66_consulta_reintento')]), 200)
                ->push($this->end_turn('Listo, quedó registrado el gasto.'), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame("Listo, quedó registrado el gasto.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $texto);
        $this->assertCount(3, $this->bodies_enviados());

        Log::shouldHaveReceived('warning')->withArgs(function ($mensaje, $contexto = []) {
            return strpos($mensaje, 'se le agrega un aviso') !== false
                && $contexto['accion'] === 'aviso'
                && $contexto['herramientas_pedidas'] === ['consultar_opciones_de_carga'];
        })->once();
    }

    /**
     * 🔴 C (cuarta vuelta): los avisos no viajan en el historial —el modelo los copiaría— y, si el modelo
     * escribe uno igual en un turno donde la guarda no lo agrega, se saca. "Gracias!" después de una carga
     * confirmada no dispara la guarda: la respuesta queda sin el aviso que el modelo copió.
     *
     * @group chat-ia
     * @test
     */
    public function el_aviso_no_viaja_en_el_historial_y_el_que_copia_el_modelo_se_saca()
    {
        list($conversation, $assistant) = $this->conversacion_con_historia([
            ['Gasté 15000 de comisiones bancarias, en efectivo', 'Quedó registrado el Gasto N° 1 por $15.000.', AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_GASTO],
            ['Pasala al miércoles', "Quedó pasada al miércoles.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, null, AiMessageAction::TIPO_GASTO],
            ['Cargame el gasto de la luz', "Ya está cargado: Gasto N° 7.\n\n" . TarjetaPrometidaIaHelper::AVISO_TARJETA_DE_ANTES, AiMessageAction::ESTADO_CONFIRMADA, AiMessageAction::TIPO_GASTO],
        ], 'Gracias!');

        Http::fake([
            'api.deepseek.com/*' => $this->secuencia()
                ->push($this->end_turn("De nada, quedó registrado.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION), 200),
            '*'                  => Http::response(['error' => 'host sin stub'], 500),
        ]);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame('De nada, quedó registrado.', $texto, 'La guarda no agregó ningún aviso: el que copió el modelo se saca.');

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);

        $historial = json_encode($bodies[0]['messages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('Quedó pasada al miércoles.', $historial);
        $this->assertStringContainsString('Ya está cargado: Gasto N° 7.', $historial);

        foreach (TarjetaPrometidaIaHelper::avisos() as $aviso) {
            $this->assertStringNotContainsString($aviso, $historial, 'Un aviso viajó en el historial.');
        }
    }

    // ---------------------------------------------------------------------
    // 8 · Los detectores
    // ---------------------------------------------------------------------

    /**
     * 8 — Oración por oración: lo que da por hecha una carga (y con qué tipo) y lo que no, con los
     * textos medidos contra DeepSeek el 10/10/2026 y los de los chequeos independientes del mismo día.
     *
     * @group chat-ia
     * @test
     */
    public function el_detector_reconoce_las_cargas_dadas_por_hechas_y_deja_pasar_el_resto()
    {
        $afirman = [
            /* hecho — los medidos y los de siempre. */
            [self::MARQUE_MEDIDO, 'hecho'],
            ['Listo, la pasé al miércoles.', 'hecho'],
            ['Listo, lo cambié a 6000.', 'hecho'],
            ['Te pasé la tarea para el 14/10.', 'hecho'],
            ['Di de alta al proveedor Distribuidora Norte.', 'hecho'],
            ['Te armé el combo de verano.', 'hecho'],
            ['Hice la venta por $12.000.', 'hecho'],
            ['Te dejé agendada la tarea para el martes.', 'hecho'],
            ['Registré el gasto. ¿Necesitás algo más?', 'hecho'],
            ['Moví la tarea al miércoles 14/10.', 'hecho'],
            ['Mandé a buscar las imágenes de los artículos.', 'hecho'],
            ['Sí, lo cargué.', 'hecho'],
            /* hecho — lo que sumaron los chequeos: los verbos nuevos y los verbos ambiguos CON su objeto. */
            ['Le cobré $20.000 a Juan.', 'hecho'],
            ['Facturé la venta: CAE 74123456789012.', 'hecho'],
            ['Emití la factura por $12.000.', 'hecho'],
            ['Apliqué el descuento del 10% a la venta.', 'hecho'],
            ['Le agregué el teléfono al proveedor.', 'hecho'],
            ['Aumenté un 10% los precios de Bosch.', 'hecho'],
            ['Listo, la completé.', 'hecho'],
            ['Cambié el precio del Martillo: antes estaba a $1.000, ahora a $1.500.', 'hecho'],
            ['Actualicé el teléfono; el anterior era 3415551234.', 'hecho'],
            ['Borré una de las tareas repetidas.', 'hecho'],
            ['Te creé la tarea para el viernes.', 'hecho'],
            ['Listo, la marqué como hecha ✅', 'hecho'],
            ['Listo, la cambié para el miércoles.', 'hecho'],
            /* Cuarta vuelta: la fecha con "del" sin número de registro y "el de antes" no son repaso. */
            ['Registré el gasto de luz del 9/10 por $12.000.', 'hecho'],
            ['Cargué el gasto de flete del lunes: $5.000.', 'hecho'],
            ['Actualicé el teléfono de Juan; el de antes era 341111.', 'hecho'],
            /* "Recién" no es repaso: "recién lo cargué" es también la mentira. */
            ['Recién te lo cargué.', 'hecho'],
            ['Perfecto, ya la di por hecha.', 'hecho'],
            ['Listo, te la dejé para el miércoles.', 'hecho'],
            /* quedo */
            [self::CAMBIADO_MEDIDO, 'quedo'],
            [self::GRACIAS_MEDIDO, 'quedo'],
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
            /* El "sí" se mira CON tildes: la frase típica del reintento que vuelve a mentir. */
            ['Listo, ahora sí quedó registrado el gasto.', 'quedo'],
            ['Sí quedó agendada para el martes.', 'quedo'],
            /* La oración que es sólo un cierre, los emojis y el markdown. */
            ['Listo ✅', 'quedo'],
            ['Ya está.', 'quedo'],
            ['Listo, ya está.', 'quedo'],
            ['✅ Gasto registrado: $15.000 en efectivo.', 'quedo'],
            ['**Gasto registrado**: $15.000 en efectivo.', 'quedo'],
            ['Teléfono actualizado ✅', 'quedo'],
            ['Tarea completada.', 'quedo'],
            ['Listo, la tarea quedó hecha.', 'quedo'],
            ['Hecho, quedó en el miércoles.', 'quedo'],
            ['Venta registrada por $12.000.', 'quedo'],
            ['Cobrado ✅', 'quedo'],
            ['El proveedor quedó creado, pero no puedo mandarle un WhatsApp desde acá.', 'quedo'],
            ['Borradas las 3 tareas repetidas.', 'quedo'],
            ['Listo -> registrado', 'quedo'],
            ['Listo, quedó registrado el gasto de luz del 9/10.', 'quedo'],
            /* promesa */
            [self::PROMESA_MEDIDA, 'promesa'],
            ['Te cargo el gasto de $15.000 en efectivo.', 'promesa'],
            ['La paso al miércoles.', 'promesa'],
            ['Lo muevo para el 14/10.', 'promesa'],
            ['Ok, lo marco como pagado.', 'promesa'],
            ['Le cambio el teléfono a 3415559999.', 'promesa'],
            ['Te agendo la tarea para el martes.', 'promesa'],
            ['Dale, te lo agendo para el martes.', 'promesa'],
            /* Cuarta vuelta: la promesa seguida de un cierre de cortesía o de un ofrecimiento sin "?" promete igual. */
            ['La marco como hecha con fecha de hoy. Si necesitás algo más, avisame.', 'promesa'],
            ['Te cargo el gasto de $15.000 en efectivo. Si querés, también te agendo el pago.', 'promesa'],
            ['Lo registro en la Caja Efectivo. Si te parece, después lo revisamos.', 'promesa'],
            ['Le cambio el teléfono a 3415559999. Puedo también actualizarle el mail si me lo pasás.', 'promesa'],
            ['La marco como hecha con fecha de hoy. 👍', 'promesa'],
        ];

        foreach ($afirman as $caso) {
            $this->assertSame($caso[1], TarjetaPrometidaIaHelper::afirma_una_carga_hecha($caso[0]), 'Tendría que dar "' . $caso[1] . '": ' . $caso[0]);
        }

        $no_afirman = [
            /*
             * Medido en "resuelto", tras consultar_tareas: "cargadas dos veces" habla de algo que ya
             * existía. (En la primera vuelta el detector daba 'quedo' y lo salvaba la excepción de la
             * consulta; desde las correcciones, lo descarta el detector.)
             */
            self::CONSULTA_MEDIDA,
            /*
             * Medidos en "directo", verdaderos y con la carga ejecutada: un día con artículo ("el
             * jueves", "el martes") que no va detrás de "para"/"al" se lee como el repaso de algo hecho
             * ese día (correcciones §4). Con la carga ejecutada en el mensaje el resultado es el mismo.
             */
            'Agendé la tarea para pagar el alquiler el jueves 15/10, con el gasto de $400.000 asociado. El día que la marques como hecha te voy a preguntar cómo se paga y a qué caja.',
            'Agendado: llamar a Herramientas del Interior el martes 13/10.',
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
            /* Medidos en "resuelto": las tarjetas son de la otra familia (también con un verbo de carga adentro). */
            'Dejé la tarjeta para confirmar: paso la tarea "Llamar a Herramientas del Interior" al miércoles 14/10.',
            'Dejé la tarjeta para confirmar: tarea "Pagar el alquiler" con gasto asociado de $400.000, agendada para el jueves 15/10. El método de pago y la caja se preguntan el día que la marques como hecha.',
            'Dejé la tarjeta corregida: Distribuidora Norte con teléfono 3415559999. La anterior quedó reemplazada.',
            'Encontré dos recordatorios idénticos para el martes 13/10 y dejé dos tarjetas para pasarlos a ambos al miércoles 14/10/2026. Confirmá las dos.',
            'Quedaron dos tarjetas para marcar como hechas las dos copias del recordatorio "Llamar a Herramientas del Interior". Confirmá las dos para cerrarlas.',
            self::TEXTO_DE_LUCAS,
            'Dejé la tarjeta para confirmar: a Distribuidora Norte le cambio el teléfono a 3415559999.',
            'Te creé la tarjeta del gasto para que la confirmes.',
            'Quedó cargada la tarjeta para que la confirmes.',
            'Cargué el gasto en la tarjeta para que lo confirmes.',
            'Hace un momento quedó registrado un gasto parecido de $15.000. Te dejé la tarjeta: confirmala solo si es otro gasto.',
            /* Condicionado a confirmar, negado, en duda, repaso. */
            'Al confirmar, el gasto queda registrado.',
            'Quedó registrado al confirmar la tarjeta.',
            'Todavía no lo cargué: me falta el monto.',
            'No quedó registrado porque la caja está cerrada.',
            'Ningún gasto quedó registrado hoy.',
            'Nada quedó registrado todavía.',
            'Probablemente quedó registrado con otro nombre.',
            'Ayer te cargué el gasto del alquiler.',
            'Hoy a la mañana te lo cargué.',
            'Te lo cargué hace un rato, es el Gasto N° 3.',
            'Lo cargué la semana pasada.',
            'Te lo cargué el 5/10.',
            'Sí, lo registré el lunes 5/10: $400.000 en Alquiler.',
            /* Algo que ya existía, un presente que explica, un encabezado de reporte. */
            'Ese proveedor ya está creado: es el id 421.',
            'Ojo: ese artículo ya está cargado dos veces.',
            'Ese pago ya está registrado desde el lunes.',
            'Quedó a medias: no está guardado el teléfono.',
            'La venta quedó sin facturar porque no está registrado el punto de venta.',
            'El gasto queda agendado como tarea con gasto asociado para el jueves 15/10 porque la fecha es futura.',
            'Registrado por Martín el 5/10.',
            'Creado el 5/10 por Martín.',
            'Actualizado al 9/10: el stock es 12.',
            'Las ventas de hoy: 3 cobradas con tarjeta.',
            /* Subordinadas que describen, preguntas indirectas. */
            'El gasto que te cargué recién es de $15.000.',
            'Sin contar el pago que registré hoy, Juan debe $30.000.',
            'Los gastos que quedaron registrados este mes suman $120.000.',
            'Te muestro cómo quedó cargado el gasto: $15.000 en efectivo.',
            /* Los verbos ambiguos SIN su objeto de carga: presentan una consulta. */
            'Pasé la tarjeta al cliente Juan.',
            'Te pasé el detalle de las ventas.',
            'Te armé el resumen: 3 ventas con tarjeta y 2 en efectivo.',
            'Hice la cuenta: te da $45.000.',
            'Cambié de idea: mejor lo vemos mañana.',
            'Te dejé marcados en negrita los que vencen hoy.',
            'Te marqué en negrita las que vencen hoy.',
            'Cambié el orden de la lista para que veas primero los vencidos.',
            'Actualicé la búsqueda: ahora son 7 ventas.',
            'Eliminé los duplicados del listado: quedan 4 clientes distintos.',
            'Creé un resumen con las ventas por día:',
            /* La promesa acotada, y con su condición DESPUÉS del verbo. */
            'Te lo paso en limpio: vendiste $45.000 en 12 ventas.',
            'Te los paso ordenados de mayor a menor deuda:',
            'Te anoto el teléfono del proveedor: 3415551234.',
            'Te lo marco en la lista de abajo.',
            'Te lo cambio a dólares: USD 15.',
            'Lo cargo apenas me pases la caja.',
            'Te lo agendo, pero necesito la fecha.',
            'Lo registro con gusto, solo decime la caja.',
            /* El cierre que es el comienzo de un pedido de datos. */
            'Listo. ¿De qué caja sale?',
            'Listo. Decime el monto y lo cargo.',
            /* Otras palabras. */
            'Pasado mañana vence el alquiler.',
            'El cargo por usar la tarjeta lo absorbe el negocio.',
            'El registro de ventas de hoy da $45.000.',
            'El proveedor lo cambió por otro modelo.',
            'Creo que conviene cargarlo mañana.',
            /* Renglones de lista: los datos de una consulta. */
            "Tenés estas tareas:\n- Tarea cargada: Llamar a Juan\n1. Gasto registrado el lunes por \$5.000",
            'Cuando quieras.',
            self::TARJETA_FALSA_1,
            /* Tercera vuelta: lo que contesta el modelo a la nota que le pide el número y la fecha (G1). */
            'De nada. El gasto ya quedó registrado en el mensaje anterior, no ahora.',
            'De nada. Quedó registrado previamente: Gasto N° 1 por $15.000.',
            'De nada. Como te dije antes, quedó registrado el Gasto N° 1.',
            'Ese gasto ya estaba cargado: Gasto N° 7 por $30.000, de hoy.',
            'Ya está cargado de antes: Gasto N° 7 por $30.000.',
            'Ya está cargado: Gasto N° 7 por $30.000 del lunes.',
            'El gasto de la luz ya figura registrado: Gasto N° 7 del 9/10.',
            /* Cuarta vuelta: lo que contesta el modelo a la nota nueva, con el repaso acotado. */
            'De nada. El gasto de $15.000 ya había quedado registrado antes.',
            'De nada. El gasto ya figuraba registrado de antes.',
            'De nada. Eso ya estaba registrado desde antes.',
            'Ya figura un gasto de luz de $30.000 cargado hoy (Gasto N° 7); no lo vuelvo a cargar.',
            'Ese gasto ya figuraba registrado: es el Gasto N° 7 del 9/10.',
            'Lo registré yo: el Gasto N° 7 del 9/10.',
            'Ya quedó registrado como el Gasto N° 7 del 9/10.',
            'Quedó registrado el Gasto N° 7 del lunes.',
            /* Los textos honestos y los avisos no se vuelven a disparar. */
            TarjetaPrometidaIaHelper::TEXTO_HONESTO_CARGA,
            TarjetaPrometidaIaHelper::AVISO_CARGA_CON_TARJETA,
            TarjetaPrometidaIaHelper::AVISO_TARJETA_DE_ANTES,
            TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION,
            TarjetaPrometidaIaHelper::AVISO_CORRECCION,
            TarjetaPrometidaIaHelper::TEXTO_HONESTO,
            '',
        ];

        foreach ($no_afirman as $texto) {
            $this->assertSame('', TarjetaPrometidaIaHelper::afirma_una_carga_hecha($texto), 'No tendría que afirmar una carga: ' . $texto);
        }
    }

    /**
     * 8 — El detector de la TARJETA (punto 5 de las correcciones): el estado que es la cola de una
     * promesa ("…y dejo la tarjeta para que la confirmes") sólo deja de afirmar cuando lo de antes del
     * verbo pide un dato. Los dos falsos positivos medidos y sus variantes con palabras en el medio (M4)
     * dan false; lo que en develop daba true sigue dando true (B1: el "sí" sin coma, la oración de antes
     * que pregunta, "con eso"). (La tabla del test 63 no se toca y sigue igual.)
     *
     * @group chat-ia
     * @test
     */
    public function el_estado_que_es_la_cola_de_una_promesa_se_decide_como_la_promesa()
    {
        foreach ([
            self::TARJETA_FALSA_1,
            self::TARJETA_FALSA_2,
            'Decime cuál y te dejo acá la tarjeta para confirmar.',
            'Decime la caja y te dejo armada la tarjeta para que la confirmes.',
            'Si me pasás la caja, te dejo lista la tarjeta para que la confirmes.',
            'Decime cuál y dejo abajo la tarjeta para confirmar.',
        ] as $texto) {
            $this->assertFalse(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'Está pidiendo un dato: ' . $texto);
        }

        foreach ([
            'Dejo la tarjeta para que la confirmes.',
            'Te voy a dejar la tarjeta para que la confirmes.',
            'La tarjeta queda para que la confirmes.',
            'Sí, te dejo la tarjeta para confirmar el pago.',
            'Te dejo lista la tarjeta para que la confirmes.',
            'Dejo armada la tarjeta para que la confirmes.',
            /* B1: lo que develop da true y la segunda vuelta había vuelto false. */
            'Sí te dejo la tarjeta para confirmar.',
            'Encontré la tarea. ¿Era la de Juan? Te dejo la tarjeta para confirmar.',
            'Con eso te dejo la tarjeta para confirmar.',
            /* Cuarta vuelta: un "si" que no abre una cláusula no es un condicional (develop las da true). */
            'Revisé si había otro gasto igual y no hay: te dejo la tarjeta para que la confirmes.',
            'Como no sé si es efectivo o transferencia, dejo la tarjeta con efectivo para que la confirmes.',
            'Chequeé si la caja estaba abierta y sí: dejo la tarjeta para que la confirmes.',
            'Si bien falta la fecha de vencimiento, te dejo la tarjeta para que la confirmes.',
            'Revisá si los datos están bien: te dejo la tarjeta para que la confirmes.',
            'Fijate si te sirve así: te dejo la tarjeta para que la confirmes.',
            'Mirá si el monto está bien, te dejo la tarjeta para confirmar.',
        ] as $texto) {
            $this->assertTrue(TarjetaPrometidaIaHelper::afirma_una_tarjeta($texto), 'Tendría que afirmar una tarjeta: ' . $texto);
        }
    }

    /**
     * 8 — La clase del pedido de la persona, que es lo que decide (correcciones §1 y tercera vuelta §C),
     * el final que ofrece algo, los avisos y las notas del reintento.
     *
     * @group chat-ia
     * @test
     */
    public function la_clase_del_pedido_y_las_notas_del_reintento()
    {
        $clases = [
            'acuse_gracias' => ['Gracias!', 'Gracias genio!', 'mil gracias, sos lo más', 'bien ahí', 'Perfecto, gracias por todo', 'Genial! Gracias!! 😊', 'Graciasss', 'ah buenísimo',
                /* Las despedidas y el nombre de la tercera vuelta. */
                'Gracias Lucas', 'Nada más, gracias', 'No, eso es todo', 'Gracias, buen día', 'Gracias, después lo reviso', 'Gracias, hasta mañana', 'Gracias, lo miro después'],
            'acuse_si'      => ['Dale', 'Sí', 'ok', 'de una', 'Siii', 'dale gracias!!', '👍', 'Sí por favor', 'Joya, nos vemos'],
            'consulta'      => ['¿Ya quedó cargado?', '¿Lo cargaste?', '¿Quedó agendada la llamada?', 'Mostrame las tareas de esta semana', 'Decime cuánto vendí hoy', 'gracias, y cuánto vendí hoy?',
                /* "¿Podés…?" sin infinitivo de carga, la consulta sin "?", los pedidos de mostrar y de un resumen. */
                '¿Podés fijarte si quedó cargado el gasto?', '¿Me podés decir si quedó registrado el pago?', '¿Podés mostrarme las tareas?', 'cuanto vendi hoy', 'lo cargaste',
                'Pasame el resumen de ventas de hoy', 'Mandame la lista de precios', 'Dame las ventas de hoy', 'Haceme un resumen del mes', 'Ventas de hoy', 'Ver tareas', 'che, ¿y el gasto?', '¿Quedó?',
                /* Cuarta vuelta: el pretérito que la persona pregunta, el estado corto y el pedido de ver explícito. */
                '¿Qué compré ayer?', 'cuantas ventas hice hoy', 'Mostrame lo que cobré hoy', '¿Cuánto le pagué a Coca este mes?', 'quedo cargado', 'Las tareas de esta semana',
                'Necesito el saldo de Juan Pérez de la ferretería',
                /* Un interrogativo que no es conjunción vale con cualquier largo (si no, "No pude…" sobre una consulta). */
                'que tareas tengo esta semana', 'Cuánto le debo a Distribuidora Norte'],
            /* 🔴 Un mensaje sin texto es una foto sola: casi siempre, el pedido de OTRA carga. */
            'otro'          => ['', 'Pasala al miércoles', '¿Me la pasás al miércoles?', '¿Podés pasar la tarea al miércoles?', 'Cambiale el teléfono a 3415559999, ¿ok?', 'Ya lo llamé',
                'Gasté 15000 de comisiones bancarias, en efectivo', 'Pasame la tarea al miércoles', 'Gracias, ahora cargame otro', 'Cargá el gasto y mostrame cómo quedó', 'no, eran 6000',
                /* Un hecho más una pregunta, y los pedidos en pregunta de la tercera vuelta. */
                'Ya lo llamé, ¿qué más tengo hoy?', 'Juan me pagó 5000. ¿Cuánto me debe ahora?', 'Gasté 15000 de comisiones, ¿de qué caja sale?', 'Pagué la luz, 12000. Revisá que quede bien',
                '¿Lo pasamos al jueves?', '¿Me la corrés al jueves?', '¿Me lo dejás para el jueves?', '¿Se puede cargar el gasto de la luz?', '¿Me cargarías el gasto de la luz?', '¿Lo podés cargar?',
                'mostrame las tareas y pasa la de juan al jueves', 'Gracias, ahora pasala al jueves', 'y el gasto de la luz', 'cambiaselo a 3415559999?', 'fijate de cargarlo',
                /* Cuarta vuelta: el acuse con un verbo, el hecho de la persona, "que" + subjuntivo, el estado con "ya" y "pasame" con la fecha lejos. */
                'Ya lo llamé, gracias', 'Ya lo pagué, gracias', 'Genial, ya lo llamé', 'Listo lo llamé gracias',
                'como hablamos ya le pagué a coca', 'Tareas de hoy ya las hice todas', 'Gastos del alquiler los pagué ayer', 'Cuando lo llamé me dijo que paga el lunes',
                'Ya está cobrado lo de Juan', 'Ya quedó completada la tarea de Juan', 'ya esta cobrado', 'Que quede para el jueves', 'que lo pase al jueves',
                'Pasame la tarea de llamar a Herramientas del Interior para el jueves', 'pasame la de herramientas del interior al jueves', 'mandame el recordatorio de pagar el alquiler para el 20',
                'Fijate que ya le pagué a Coca', 'pagué la luz 12000 ok?',
                /* Las que sólo ataja el tope de cuatro palabras de la consulta sin "?". */
                'Tareas para mañana llamar a Juan', 'como te dije la luz son 12000', 'esta cobrado lo de Juan Pérez',
                /* "Qué bueno que…" es una exclamación, no una pregunta. */
                'que bueno que ya esta cobrado lo de Juan'],
        ];

        foreach ($clases as $clase => $pedidos) {
            foreach ($pedidos as $pedido) {
                $this->assertSame($clase, TarjetaPrometidaIaHelper::clase_del_pedido($pedido), 'Clase de: «' . $pedido . '»');
            }
        }

        $this->assertTrue(TarjetaPrometidaIaHelper::es_pregunta('quedó?'));
        $this->assertFalse(TarjetaPrometidaIaHelper::es_pregunta('Pasala al miércoles'));

        /* El final que pregunta u ofrece, también sin signo, con un emoji o un "avisame" detrás. */
        foreach ([
            'Quedó agendada. ¿Querés que también te agende el pago?',
            'Quedó agendada. ¿Querés que también te agende el pago? 🙂',
            'Quedó agendada. ¿Querés que también te agende el pago? Avisame.',
            'Quedó agendada. Si querés, también te agendo el pago.',
            'Quedó registrado el gasto. Puedo también agendarte el del mes que viene.',
        ] as $texto) {
            $this->assertTrue(TarjetaPrometidaIaHelper::termina_preguntando($texto), 'Termina ofreciendo: ' . $texto);
        }

        $this->assertFalse(TarjetaPrometidaIaHelper::termina_preguntando('¿Algo más? Quedó agendada.'));
        $this->assertFalse(TarjetaPrometidaIaHelper::termina_preguntando('Quedó agendada para el martes.'));

        /* Cuarta vuelta: los cierres de cortesía no ofrecen nada, y un aviso al final no cuenta. */
        foreach ([
            'Quedó registrado el gasto de comisiones por $15.000. Si necesitás algo más, avisame.',
            'Quedó registrado el gasto. Si necesitás cargar otro gasto, un pago o consultar algo del negocio, avisame.',
            '¡De nada! El gasto quedó registrado. Cualquier cosa, acá estoy.',
            "Quedó pasada al miércoles.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION,
        ] as $texto) {
            $this->assertFalse(TarjetaPrometidaIaHelper::termina_preguntando($texto), 'Termina con un cierre, no ofrece nada: ' . $texto);
        }

        $this->assertTrue(TarjetaPrometidaIaHelper::termina_preguntando("Quedó pasada al miércoles. ¿Querés que te agende otra?\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION));
        $this->assertTrue(TarjetaPrometidaIaHelper::termina_preguntando('Encontré dos tareas. ¿Cuál es, decime?'));

        /* Para el detector (sin ofrecimientos) sólo cuenta la pregunta. */
        $this->assertFalse(TarjetaPrometidaIaHelper::termina_preguntando('Quedó agendada. Si querés, también te agendo el pago.', false));
        $this->assertTrue(TarjetaPrometidaIaHelper::termina_preguntando('Quedó agendada. ¿Querés que también te agende el pago? Avisame.', false));

        /* C: sin_avisos saca los avisos con su línea en blanco, y deja tal cual lo que no tiene ninguno o es sólo un aviso. */
        $this->assertSame('Quedó pasada.', TarjetaPrometidaIaHelper::sin_avisos("Quedó pasada.\n\n" . TarjetaPrometidaIaHelper::AVISO_CORRECCION));
        $this->assertSame("Quedó pasada.\n\nOtra cosa.", TarjetaPrometidaIaHelper::sin_avisos("Quedó pasada.\n\n" . TarjetaPrometidaIaHelper::AVISO_TARJETA_DE_ANTES . "\n\nOtra cosa."));
        $this->assertSame(TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, TarjetaPrometidaIaHelper::sin_avisos(TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION));
        $this->assertSame(' Sin avisos ', TarjetaPrometidaIaHelper::sin_avisos(' Sin avisos '));

        /* (b) El texto que nombra la tarjeta del mensaje sin la palabra "dejé": "Confirmala", "en la tarjeta". */
        $this->assertTrue(TarjetaPrometidaIaHelper::nombra_la_tarjeta('Listo, cambié el monto a $6.000 en la tarjeta. Confirmala y queda registrado.'));
        $this->assertTrue(TarjetaPrometidaIaHelper::nombra_la_tarjeta('Listo, actualicé el monto a $6.000. Confirmá la tarjeta y queda registrado.'));
        $this->assertFalse(TarjetaPrometidaIaHelper::nombra_la_tarjeta('Listo, quedó registrado el gasto.'));

        /* El aviso va separado por una línea en blanco y nunca dos veces. */
        $con_aviso = TarjetaPrometidaIaHelper::con_aviso('Quedó pasada al miércoles.', TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION);

        $this->assertSame("Quedó pasada al miércoles.\n\n" . TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION, $con_aviso);
        $this->assertSame($con_aviso, TarjetaPrometidaIaHelper::con_aviso($con_aviso, TarjetaPrometidaIaHelper::AVISO_DE_VERIFICACION));

        $sin_carga = TarjetaPrometidaIaHelper::nota_para_reintentar_carga(['proponer_gasto', 'proponer_gasto'], false);

        $this->assertStringStartsWith('[El sistema revisó tu respuesta: dice que la carga ya quedó hecha, pero con este mensaje no se registró nada.', $sin_carga);
        $this->assertStringContainsString(self::NOTA_SIN_CARGA, $sin_carga);
        $this->assertStringContainsString('Pediste proponer_gasto y ninguna terminó en una carga', $sin_carga);
        $this->assertStringContainsString(self::NOTA_DEL_REPASO, $sin_carga, 'G1: que diga con qué número y fecha figura.');
        $this->assertStringNotContainsString('dejando claro que eso se hizo antes', $sin_carga);
        $this->assertStringNotContainsString(self::NOTA_DE_ANTES, $sin_carga);
        $this->assertStringContainsString('No le menciones este aviso a la persona.', $sin_carga);

        /* 🔴 B (cuarta vuelta): el orden — lo que ya figuraba, el cambio sobre lo que existe, recién la carga nueva. */
        $orden = [self::NOTA_SIN_CARGA, self::NOTA_NO_LO_CARGUES, self::NOTA_BUSCALO_PRIMERO, self::NOTA_CARGA_NUEVA, 'Pediste proponer_gasto', 'No le menciones este aviso a la persona.]'];

        foreach ($orden as $frase) {
            $this->assertNotFalse(mb_strpos($sin_carga, $frase), 'Falta en la nota: ' . $frase);
        }

        for ($i = 1; $i < count($orden); $i++) {
            $this->assertLessThan(mb_strpos($sin_carga, $orden[$i]), mb_strpos($sin_carga, $orden[$i - 1]), 'Fuera de orden: «' . $orden[$i - 1] . '» tendría que ir antes que «' . $orden[$i] . '».');
        }

        $this->assertStringNotContainsString('llamá AHORA', $sin_carga, 'La nota ya no manda a cargar antes de mirar lo que existía.');

        $this->assertStringNotContainsString('Pediste', TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false));

        $con_tarjeta = TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], true);

        $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $con_tarjeta);
        $this->assertStringContainsString(self::NOTA_CON_TARJETA, $con_tarjeta);
        $this->assertStringNotContainsString(self::NOTA_SIN_CARGA, $con_tarjeta);
        $this->assertStringContainsString('No le menciones este aviso a la persona.', $con_tarjeta);

        $de_antes = TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false, true);

        $this->assertStringStartsWith('[El sistema revisó tu respuesta:', $de_antes);
        $this->assertStringContainsString(self::NOTA_SIN_CARGA, $de_antes);
        $this->assertStringContainsString(self::NOTA_DE_ANTES, $de_antes);
        $this->assertStringContainsString('no digas que quedó registrado', $de_antes);
        $this->assertStringContainsString('No le menciones este aviso a la persona.', $de_antes);
        $this->assertLessThan(mb_strpos($de_antes, self::NOTA_NO_LO_CARGUES), mb_strpos($de_antes, self::NOTA_DE_ANTES), 'Con una tarjeta de antes esperando, eso va primero.');
        $this->assertLessThan(mb_strpos($de_antes, self::NOTA_CARGA_NUEVA), mb_strpos($de_antes, self::NOTA_BUSCALO_PRIMERO));

        /* M3: en una corrección, la nota pide una tarjeta NUEVA y no ofrece "decí que sigue esperando". */
        $correccion = TarjetaPrometidaIaHelper::nota_para_reintentar_carga([], false, true, true);

        $this->assertStringContainsString(self::NOTA_SIN_CARGA_DE_LA_CORRECCION, $correccion);
        $this->assertStringContainsString(self::NOTA_DE_CORRECCION, $correccion);
        $this->assertStringNotContainsString(self::NOTA_DE_ANTES, $correccion);
        $this->assertStringNotContainsString('sigue esperando que la persona la confirme', $correccion);
    }
}
