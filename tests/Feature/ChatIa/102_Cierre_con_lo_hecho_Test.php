<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Http\Controllers\Helpers\asistente_ia\AvisosDeTurnosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ConfianzaDelAgenteIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\TarjetaPrometidaIaHelper;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiAvisoDelAsistente;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\AiMessageImagen;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\HttpFactorySinSalida;
use Tests\TestCase;

/**
 * Misión agente-memoria-y-sin-techo (30/10/2026) — el turno que se queda sin vueltas o sin tiempo
 * CIERRA CON LO HECHO en vez de tirar un error.
 *
 * 🔴 EL CASO REAL. Ferre Total por WhatsApp (DeepSeek Flash), 30/10 11:44: «…sobre ese detalle de
 * cheques, de la fecha de pago de cada uno, me hagas un recordatorio para un día antes de su
 * acreditación». 8 llamadas en 13 segundos (2 s cada una): el loop tocó el techo de 8 vueltas, tiró
 * `tiempo_agotado` y Pablo leyó «La consulta se hizo larga y no llegué a terminarla. Probá pidiéndome
 * algo más acotado.», con todo lo hecho perdido.
 *
 * Lo que protege este archivo, con un doble del proveedor que SIEMPRE pide una herramienta (menos
 * cuando la llamada sale con `tool_choice`, que es la de cierre):
 *
 * - al tocar el techo, la última vuelta es la llamada de cierre (`tool_choice: none` + la nota), el
 *   mensaje queda `listo` con su texto y hubo exactamente MAX_… llamadas; con y sin acciones;
 * - el presupuesto vencido también cierra (con el reloj corrido por la costura ahora()), y si no
 *   queda tiempo para el cierre, el error de siempre sin llamar;
 * - por WhatsApp cierra pasados los 105 s contados desde que llegó el mensaje (`created_at`), el
 *   sistema no; y la primera vuelta siempre es normal;
 * - si el cierre falla (HTTP, red), vuelve sin texto o no tiene ventana, el mensaje queda `listo` con lo
 *   hecho armado sin el modelo (ronda de arreglos R1: antes era `error`), las tarjetas siguen y el
 *   «seguí» las lleva en el historial; el texto de tiempo agotado (sin ninguna ronda) no promete «desde
 *   donde quedó»;
 * - si el cierre trae un tool_use, no se ejecuta; las cargas del turno quedan en el mensaje `listo`;
 * - después del cierre no hay reintento de guarda, pero las guardas de después del bucle miran su
 *   texto; y con un reintento de guarda en curso no se cierra (vuelve al texto de antes);
 * - la regla del prompt de pedir juntas las herramientas que no dependen entre sí.
 *
 * 🔴 Ningún test sale a la red: Http::fake en todos, con claves e ids de modelo inventados.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group chat-ia
 */
class Cierre_con_lo_hecho_Test extends TestCase
{
    use DatabaseTransactions;

    /** El texto que devuelve el doble en la llamada de cierre. */
    const TEXTO_DEL_CIERRE = 'Encontré el pago 1706 y sus cinco cheques. Me faltan los recordatorios: escribime «seguí» y sigo.';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => null,
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-p102',
            'services.deepseek.model'          => 'deepseek-general-p102',
            'services.deepseek.model_agil'     => 'deepseek-flash-p102',
            'services.deepseek.model_profundo' => 'deepseek-pro-p102',
            'services.deepseek.model_vision'   => 'deepseek-vision-p102',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio cierre P102',
            'company_name' => 'Ferreteria P102',
            'email'        => 'cierre-p102-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        /* La configuración del caso real: DeepSeek ágil. Cauteloso: una propuesta deja tarjeta, no se ejecuta. */
        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = ConfianzaDelAgenteIaHelper::CAUTELOSO;
        $this->comercio->save();

        $extencion = ExtencionEmpresa::where('slug', 'agentes_ia')->first();

        if (! $extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => 'agentes_ia', 'name' => 'Agentes de IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        Event::fake([ChatIaMensajeActualizado::class]);
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    /**
     * Conversación con el pedido de la persona y el assistant pendiente.
     *
     * @param  string|null  $canal  null = el panel del sistema.
     * @param  bool  $con_acciones
     * @param  int  $hace_segundos  Cuántos segundos atrás se creó el mensaje (el reloj del admin).
     * @param  string  $pedido
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($canal = null, $con_acciones = true, $hace_segundos = 0, $pedido = 'Haceme un recordatorio un día antes de cada cheque del pago a Pinturería Sudamericana')
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => $canal === AiMessage::CANAL_WHATSAPP ? AiConversation::ORIGEN_WHATSAPP : 'usuario',
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

        $mensaje = AiMessage::create($assistant);

        if ($hace_segundos > 0) {
            AiMessage::where('id', $mensaje->id)->update(['created_at' => Carbon::now()->subSeconds($hace_segundos)]);
            $mensaje = $mensaje->fresh();
        }

        return [$conversation, $mensaje];
    }

    /**
     * El servicio con el reloj corrible: ahora() devuelve time() más $desfase, y el doble del proveedor
     * le suma segundos en cada llamada (cada llamada "tarda" eso).
     *
     * @return AsistenteIaService
     */
    protected function servicio_con_reloj()
    {
        return new class extends AsistenteIaService {
            /** @var int Segundos que se le suman a time(). */
            public $desfase = 0;

            /**
             * @return int
             */
            protected function ahora(): int
            {
                return time() + $this->desfase;
            }
        };
    }

    /**
     * Respuesta del proveedor que pide una herramienta de lectura.
     *
     * @param  int  $n  Para que cada tool_use tenga su id.
     * @return array
     */
    protected function pide_herramienta($n)
    {
        return $this->respuesta('tool_use', [[
            'type'  => 'tool_use',
            'id'    => 'toolu_p102_' . $n,
            'name'  => 'consultar_stock_de_articulos',
            'input' => ['busqueda' => ''],
        ]]);
    }

    /**
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
     * El doble del proveedor: SIEMPRE pide una herramienta, salvo en la llamada que sale con
     * `tool_choice` (la de cierre), que contesta `$cierre`. Con `$servicio`, cada llamada le corre el
     * reloj `$segundos_por_llamada`. Un host que no es DeepSeek contesta 500.
     *
     * @param  mixed  $cierre  Un body (array, 200), una respuesta ya armada (Http::response) o una excepción para tirar.
     * @param  AsistenteIaService|null  $servicio
     * @param  int  $segundos_por_llamada
     * @param  array|null  $primera  Lo que contesta la PRIMERA llamada (null = pedir una herramienta).
     * @return void
     */
    protected function proveedor_que_siempre_pide_herramientas($cierre, $servicio = null, $segundos_por_llamada = 0, $primera = null)
    {
        $llamadas = 0;

        Http::fake(function ($request) use (&$llamadas, $cierre, $servicio, $segundos_por_llamada, $primera) {
            if (strpos($request->url(), 'deepseek') === false) {
                return Http::response(['error' => 'host sin stub'], 500);
            }

            $llamadas++;

            if (! is_null($servicio)) {
                $servicio->desfase += $segundos_por_llamada;
            }

            $body = json_decode($request->body(), true);

            if (isset($body['tool_choice'])) {
                if ($cierre instanceof \Throwable) {
                    throw $cierre;
                }

                return is_array($cierre) ? Http::response($cierre, 200) : $cierre;
            }

            if ($llamadas === 1 && ! is_null($primera)) {
                return Http::response($primera, 200);
            }

            return Http::response($this->pide_herramienta($llamadas), 200);
        });
    }

    /**
     * Los bodies de los requests a DeepSeek, en orden.
     *
     * @return array<int, array>
     */
    protected function bodies_enviados()
    {
        $bodies = [];

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'deepseek') !== false) {
                $bodies[] = json_decode($par[0]->body(), true);
            }
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
     * Cuántos de los bodies salieron con `tool_choice`.
     *
     * @param  array<int, array>  $bodies
     * @return int
     */
    protected function con_tool_choice(array $bodies)
    {
        $cuantos = 0;

        foreach ($bodies as $body) {
            if (isset($body['tool_choice'])) {
                $cuantos++;
            }
        }

        return $cuantos;
    }

    /**
     * Comprueba que la ÚLTIMA llamada sea la de cierre: `tool_choice: none`, la nota después de los
     * tool_result, las herramientas igual definidas, y ninguna otra llamada con `tool_choice`.
     *
     * @param  array<int, array>  $bodies
     * @return void
     */
    protected function assertLaUltimaEsElCierre(array $bodies)
    {
        $ultima = $bodies[count($bodies) - 1];

        $this->assertSame(['type' => 'none'], isset($ultima['tool_choice']) ? $ultima['tool_choice'] : null, 'La llamada de cierre no puede pedir herramientas.');
        $this->assertSame(1, $this->con_tool_choice($bodies), 'Solo la de cierre lleva tool_choice.');
        $this->assertStringContainsString(AsistenteIaService::NOTA_DE_CIERRE, $this->ultimo_turno_como_texto($ultima));
        $this->assertArrayHasKey('tools', $ultima, 'Las tools viajan igual: el historial trae tool_use y tool_result.');

        $ultimo_turno = $ultima['messages'][count($ultima['messages']) - 1];
        $this->assertSame('user', $ultimo_turno['role']);
        $this->assertSame('tool_result', $ultimo_turno['content'][0]['type'], 'La nota va DESPUÉS de los tool_result, en el mismo turno.');
    }

    // ---------------------------------------------------------------------
    // El techo de vueltas
    // ---------------------------------------------------------------------

    /**
     * 🔴 El caso de Pablo: el modelo encadena herramientas hasta el techo. El mensaje queda `listo` con el
     * texto de la llamada de cierre, y hubo exactamente MAX_TOOL_ITERATIONS_CON_ACCIONES llamadas (la
     * última, la de cierre).
     *
     * @test
     */
    public function al_tocar_el_techo_el_turno_cierra_con_lo_hecho_en_vez_de_un_error()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE));

        list($conversation, $assistant) = $this->conversacion();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);
        $this->assertSame(self::TEXTO_DEL_CIERRE, $assistant->contenido);

        $bodies = $this->bodies_enviados();

        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $bodies, 'El techo cuenta la llamada de cierre.');
        $this->assertSame(24, AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES);
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * Sin las herramientas de carga, el techo de lectura (12), con el mismo cierre.
     *
     * @test
     */
    public function sin_acciones_cierra_al_techo_de_lectura()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE));

        list($conversation, $assistant) = $this->conversacion(null, false);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(self::TEXTO_DEL_CIERRE, $texto);

        $bodies = $this->bodies_enviados();

        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS, $bodies);
        $this->assertSame(12, AsistenteIaService::MAX_TOOL_ITERATIONS);
        $this->assertLaUltimaEsElCierre($bodies);
    }

    // ---------------------------------------------------------------------
    // El presupuesto de tiempo
    // ---------------------------------------------------------------------

    /**
     * Por el sistema, cada llamada "tarda" 50 s: a los 250 ya pasó el presupuesto de 210 y la vuelta 6
     * es la de cierre, aunque queden vueltas del techo.
     *
     * @test
     */
    public function el_presupuesto_vencido_cierra_aunque_queden_vueltas()
    {
        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 50);

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(self::TEXTO_DEL_CIERRE, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(6, $bodies, '5 vueltas normales (hasta los 250 s) y la de cierre.');
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * Si la vuelta normal que arrancó antes del presupuesto volvió tan tarde que no quedan
     * SEGUNDOS_MINIMOS_DEL_CIERRE hasta el techo del cierre (270 por el sistema), no se llama. Con 66 s
     * por llamada, la quinta vuelta arranca a los 264.
     *
     * 🔴 Contrato cambiado a propósito (ronda de arreglos R1, 30/10/2026): antes era el error de tiempo
     * agotado; ahora el turno ya hizo rondas de herramientas, así que cierra con lo hecho armado sin el
     * modelo (acá solo leyó: queda la última frase).
     *
     * @test
     */
    public function sin_tiempo_para_el_cierre_cierra_con_lo_hecho_sin_llamar()
    {
        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 66);

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(4, $bodies);
        $this->assertSame(0, $this->con_tool_choice($bodies), 'No se hizo ninguna llamada de cierre.');
    }

    /**
     * Por WhatsApp el presupuesto es de 105 s: con 40 s por llamada cierra en la vuelta 4 (a los 120 s).
     * El mismo reloj por el sistema cierra recién en la 7 (ver el test que sigue).
     *
     * 🔴 Un escenario por test a propósito: un segundo Http::fake() en el mismo test SUMA su callback
     * detrás del primero, y el primero seguiría contestando (con su reloj).
     *
     * @test
     */
    public function por_whatsapp_el_presupuesto_es_de_105_segundos()
    {
        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 40);

        list($conversation, $assistant) = $this->conversacion(AiMessage::CANAL_WHATSAPP);

        $this->assertSame(self::TEXTO_DEL_CIERRE, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(4, $bodies, 'Por WhatsApp: 3 vueltas (120 s > 105) y la de cierre.');
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * 🔴 R6 de la ronda de arreglos: por WhatsApp, con 50 s por llamada, la tercera vuelta arranca a los
     * 100 s —dentro del presupuesto de 105— pero, durando lo que la anterior, terminaría a los 150, pasado
     * 165 − MARGEN_DEL_CIERRE_EN_WHATSAPP (145): esa vuelta ya es la de cierre, con su ventana entera. Sin
     * la regla había una tercera normal (hasta los 150) y el cierre quedaba con 15 s.
     *
     * @test
     */
    public function por_whatsapp_cierra_antes_si_otra_vuelta_no_deja_ventana_para_el_cierre()
    {
        $this->assertSame(20, AsistenteIaService::MARGEN_DEL_CIERRE_EN_WHATSAPP);

        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 50);

        list($conversation, $assistant) = $this->conversacion(AiMessage::CANAL_WHATSAPP);

        $this->assertSame(self::TEXTO_DEL_CIERRE, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(3, $bodies, 'Dos vueltas normales (0-50 y 50-100 s) y la de cierre a los 100 s.');
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * R6: la regla de la ventana es solo de WhatsApp. El mismo reloj por el sistema (50 s por llamada) sigue
     * cerrando recién pasado el presupuesto de 210 (ver el_presupuesto_vencido_cierra_aunque_queden_vueltas):
     * a los 200 s, con una vuelta de 50, no cierra todavía.
     *
     * @test
     */
    public function por_el_sistema_la_ventana_de_whatsapp_no_aplica()
    {
        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 50);

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(self::TEXTO_DEL_CIERRE, $servicio->responder($conversation, $assistant));

        $this->assertCount(6, $this->bodies_enviados(), '5 vueltas normales (hasta los 250 s) y la de cierre: igual que sin la regla.');
    }

    /**
     * Por el sistema sigue el presupuesto de 210: con 40 s por llamada cierra en la vuelta 7 (a los 240 s).
     *
     * @test
     */
    public function por_el_sistema_el_presupuesto_sigue_en_210_segundos()
    {
        $servicio = $this->servicio_con_reloj();

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), $servicio, 40);

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(self::TEXTO_DEL_CIERRE, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(7, $bodies, 'Por el sistema: 6 vueltas (240 s > 210) y la de cierre.');
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * 🔴 Por WhatsApp el reloj es el del admin: un mensaje que llegó hace 110 s (esperó en la cola) cierra
     * en la segunda vuelta, sin correr el reloj del servicio.
     *
     * @test
     */
    public function por_whatsapp_cierra_pasados_los_105_segundos_desde_que_llego_el_mensaje()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE));

        list($conversation, $assistant) = $this->conversacion(AiMessage::CANAL_WHATSAPP, true, 110);

        $this->assertSame(self::TEXTO_DEL_CIERRE, (new AsistenteIaService())->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies, 'La primera vuelta es normal; la segunda, el cierre.');
        $this->assertArrayNotHasKey('tool_choice', $bodies[0], 'La primera vuelta siempre es una llamada normal.');
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * El mismo mensaje viejo por el sistema: `created_at` no cuenta y el turno llega hasta el techo.
     *
     * @test
     */
    public function por_el_sistema_el_alta_del_mensaje_no_cuenta_para_el_presupuesto()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE));

        list($conversation, $assistant) = $this->conversacion(null, true, 110);

        $this->assertSame(self::TEXTO_DEL_CIERRE, (new AsistenteIaService())->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $bodies);
        $this->assertLaUltimaEsElCierre($bodies);
    }

    /**
     * La primera vuelta siempre es normal, aunque el mensaje de WhatsApp haya esperado más que todo el
     * presupuesto: si contesta de una, no hay cierre.
     *
     * @test
     */
    public function por_whatsapp_la_primera_vuelta_siempre_es_normal()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), null, 0, $this->end_turn('Tenés 5 cheques de ese pago.'));

        list($conversation, $assistant) = $this->conversacion(AiMessage::CANAL_WHATSAPP, true, 150);

        $this->assertSame('Tenés 5 cheques de ese pago.', (new AsistenteIaService())->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(1, $bodies);
        $this->assertSame(0, $this->con_tool_choice($bodies));
    }

    // ---------------------------------------------------------------------
    // Cuando el cierre falla
    // ---------------------------------------------------------------------

    /**
     * Si la llamada de cierre vuelve con un error, el mensaje queda `listo` con lo hecho armado sin el
     * modelo (acá el turno solo leyó: la última frase, con el «seguí»).
     *
     * 🔴 Contrato cambiado a propósito (ronda de arreglos R1, 30/10/2026): antes quedaba en `error` con el
     * texto de tiempo agotado. En `error` no viajaba en el historial (el «seguí» no veía lo hecho) y por
     * WhatsApp el admin mandaba su disculpa en vez del texto, así que el «seguí» no llegaba nunca.
     *
     * @test
     */
    public function si_el_cierre_vuelve_con_error_el_mensaje_queda_listo_con_lo_hecho()
    {
        $this->proveedor_que_siempre_pide_herramientas(Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'tool_choice no soportado (P102)'],
        ], 400));

        list($conversation, $assistant) = $this->conversacion();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);
        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $assistant->contenido);
        $this->assertSame('No llegué a terminar esta respuesta. Escribime «seguí» y sigo con lo que falta.', AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR);
        $this->assertNull($assistant->error_mensaje);
        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $this->bodies_enviados(), 'Sin vueltas de más después del cierre.');
    }

    /**
     * Si la llamada de cierre se corta por la red (dos veces: también el reintento), lo hecho armado sin
     * el modelo (contrato cambiado a propósito, R1: antes era tiempo agotado).
     *
     * @test
     */
    public function si_el_cierre_se_corta_por_la_red_cierra_con_lo_hecho()
    {
        $this->proveedor_que_siempre_pide_herramientas(new ConnectionException('cURL error 28: Operation timed out (P102)'));

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, (new AsistenteIaService())->responder($conversation, $assistant));
    }

    /**
     * Si el cierre igual pide una herramienta y no escribe nada, no se ejecuta y el turno cierra con lo
     * hecho armado sin el modelo (contrato cambiado a propósito, R1: antes era tiempo agotado).
     *
     * @test
     */
    public function un_cierre_solo_con_tool_use_no_se_ejecuta_y_cierra_con_lo_hecho()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->pide_herramienta(999));

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, (new AsistenteIaService())->responder($conversation, $assistant));

        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $this->bodies_enviados(), 'El tool_use del cierre no abre otra vuelta.');
    }

    /**
     * 🔴 R1, EL CASO QUE DUPLICABA: en "directo" el turno ejecuta una carga (se confirma sola), se queda
     * sin vueltas y el cierre falla. El mensaje queda `listo` con el renglón de esa carga («Quedó hecho:
     * …») y el «seguí» de la persona lleva ese renglón en el historial —el texto y la línea de la tarjeta
     * confirmada—, así el modelo no la vuelve a cargar.
     *
     * @test
     */
    public function en_directo_una_carga_hecha_queda_en_el_texto_y_el_segui_la_lleva_en_el_historial()
    {
        $this->comercio->agente_confianza = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->comercio->save();

        $manana = Carbon::tomorrow()->format('Y-m-d');

        $this->proveedor_que_siempre_pide_herramientas(
            Http::response(['error' => ['type' => 'api_error', 'message' => 'cierre caído (P102)']], 400),
            null,
            0,
            $this->respuesta('tool_use', [[
                'type'  => 'tool_use',
                'id'    => 'toolu_p102_tarea_directa',
                'name'  => 'proponer_tarea',
                'input' => ['detalle' => 'ZZP102 Cheque 1 de Pinturería Sudamericana se acredita mañana', 'fecha' => $manana],
            ]])
        );

        list($conversation, $assistant) = $this->conversacion();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);

        $tarjeta = AiMessageAction::where('ai_message_id', $assistant->id)->first();

        $this->assertNotNull($tarjeta, 'La tarea del turno quedó colgada del mensaje.');
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, (string) $tarjeta->estado, 'En directo la tarea se confirma sola.');

        $resultado = trim((string) $tarjeta->resultado->texto);
        $this->assertNotSame('', $resultado);

        $this->assertStringStartsWith(AvisosDeTurnosIaHelper::ENCABEZADO_HECHO . "\n- " . rtrim($resultado, '. ') . '.', $assistant->contenido);
        $this->assertStringEndsWith(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $assistant->contenido);
        $this->assertStringNotContainsString('confirmes', $assistant->contenido, 'No quedó nada esperando el sí.');

        /* El «seguí»: el historial del turno siguiente lleva el texto y la línea de la tarjeta confirmada. */
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'user', 'contenido' => 'seguí', 'estado' => 'listo']);
        AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'estado' => 'pendiente', 'acciones_habilitadas' => true]);

        $historial = (new AsistenteIaService())->build_messages_payload($conversation->fresh());

        $del_asistente = '';

        foreach ($historial as $turno) {
            if ($turno['role'] === 'assistant' && is_string($turno['content'])) {
                $del_asistente .= $turno['content'] . "\n";
            }
        }

        $this->assertStringContainsString(AvisosDeTurnosIaHelper::ENCABEZADO_HECHO, $del_asistente);
        $this->assertStringContainsString('[Tarjeta #' . $tarjeta->id . ' ·', $del_asistente, 'La línea de la tarjeta viaja en el historial.');
        $this->assertStringContainsString('estado: confirmada', $del_asistente);

        $ultimo = $historial[count($historial) - 1];
        $this->assertSame('user', $ultimo['role']);
        $this->assertStringContainsString('seguí', is_string($ultimo['content']) ? $ultimo['content'] : json_encode($ultimo['content']));
    }

    /**
     * El doble del proveedor de la segunda ronda (F2): la PRIMERA llamada pide una proponer_tarea (en
     * "directo" se confirma sola) y las siguientes fallan con `$falla` (una respuesta ya armada, un body o
     * una excepción para tirar). Devuelve el contador de llamadas.
     *
     * @param  mixed  $falla
     * @param  string  $detalle  El detalle de la tarea (distinto por caso: la guarda anti-duplicado mira la clave).
     * @return \stdClass  {llamadas: int}
     */
    protected function proveedor_que_carga_y_despues_falla($falla, $detalle)
    {
        $contador = new \stdClass();
        $contador->llamadas = 0;
        $manana = Carbon::tomorrow()->format('Y-m-d');

        Http::fake(function ($request) use ($contador, $falla, $detalle, $manana) {
            if (strpos($request->url(), 'deepseek') === false) {
                return Http::response(['error' => 'host sin stub'], 500);
            }

            $contador->llamadas++;

            if ($contador->llamadas === 1) {
                return Http::response($this->respuesta('tool_use', [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_p102_f2_' . $contador->llamadas,
                    'name'  => 'proponer_tarea',
                    'input' => ['detalle' => $detalle, 'fecha' => $manana],
                ]]), 200);
            }

            if ($falla instanceof \Throwable) {
                throw $falla;
            }

            return is_array($falla) ? Http::response($falla, 200) : $falla;
        });

        return $contador;
    }

    /**
     * 🔴 F2 DE LA SEGUNDA RONDA: LA MISMA CLASE QUE R1, EN UNA VUELTA NORMAL. En "directo" la primera vuelta
     * carga una tarea (se confirma sola) y la segunda falla: un 503 (sobrecargado, ya con el reintento de
     * post_al_proveedor), un 400 (falla técnica), la red cortada dos veces, o una vuelta que cierra sin
     * texto. Antes el mensaje terminaba en `error` —lo confirmado no viajaba en el historial y por WhatsApp
     * salía la disculpa del admin—; ahora queda `listo` con el renglón de la tarea y el «seguí».
     *
     * @test
     */
    public function una_vuelta_normal_que_falla_despues_de_una_carga_deja_listo_con_lo_hecho()
    {
        $this->comercio->agente_confianza = ConfianzaDelAgenteIaHelper::DIRECTO;
        $this->comercio->save();

        $casos = [
            'sobrecargado (503)'   => Http::response(['error' => ['type' => 'api_error', 'message' => 'saturado (P102 F2)']], 503),
            'falla técnica (400)'  => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'rechazado (P102 F2)']], 400),
            'red cortada'          => new ConnectionException('cURL error 28: Operation timed out (P102 F2)'),
            'vuelta sin texto'     => $this->respuesta('end_turn', []),
        ];

        $n = 0;

        foreach ($casos as $caso => $falla) {
            $n++;

            $contador = $this->proveedor_que_carga_y_despues_falla($falla, 'ZZP102 F2 cheque ' . $n . ' de Sudamericana');

            list($conversation, $assistant) = $this->conversacion();

            (new ResponderMensajeChatIaJob($assistant->id))->handle();

            $assistant = $assistant->fresh();

            $this->assertSame('listo', $assistant->estado, $caso . ': ' . (string) $assistant->error_mensaje);

            $tarjeta = AiMessageAction::where('ai_message_id', $assistant->id)->first();

            $this->assertNotNull($tarjeta, $caso);
            $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, (string) $tarjeta->estado, $caso . ': la tarea quedó hecha y no se descartó.');
            $this->assertStringStartsWith(
                AvisosDeTurnosIaHelper::ENCABEZADO_HECHO . "\n- " . rtrim(trim((string) $tarjeta->resultado->texto), '. ') . '.',
                $assistant->contenido,
                $caso
            );
            $this->assertStringEndsWith(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $assistant->contenido, $caso);
            $this->assertGreaterThanOrEqual(2, $contador->llamadas, $caso);

            Http::swap(new HttpFactorySinSalida(app('events')));
        }
    }

    /**
     * F2: sin ninguna ronda de herramientas hecha (falla la PRIMERA llamada) no hay nada que contar: el
     * error de siempre, con su motivo.
     *
     * @test
     */
    public function si_falla_la_primera_vuelta_sigue_el_error_de_siempre()
    {
        Http::fake(function ($request) {
            return Http::response(['error' => ['type' => 'api_error', 'message' => 'saturado (P102 F2)']], 503);
        });

        list($conversation, $assistant) = $this->conversacion();

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);
            $this->fail('Sin ninguna ronda, un 503 es el error de siempre.');
        } catch (AsistenteIaException $e) {
            $this->assertSame(AsistenteIaException::MOTIVO_SOBRECARGADO, $e->motivo());
        }
    }

    /**
     * R1: lo que quedó esperando el sí (cauteloso) va como «Quedaron para que las confirmes» y las tarjetas
     * siguen `propuesta`: un mensaje `listo` no las descarta (el error sí las descartaba).
     *
     * @test
     */
    public function lo_que_espera_el_si_queda_para_confirmar_y_no_se_descarta()
    {
        $manana = Carbon::tomorrow()->format('Y-m-d');
        $bloques = [];

        for ($i = 1; $i <= 2; $i++) {
            $bloques[] = [
                'type'  => 'tool_use',
                'id'    => 'toolu_p102_espera_' . $i,
                'name'  => 'proponer_tarea',
                'input' => ['detalle' => 'ZZP102 Cheque ' . $i . ' espera el sí', 'fecha' => $manana],
            ];
        }

        $this->proveedor_que_siempre_pide_herramientas(new ConnectionException('cURL error 28 (P102)'), null, 0, $this->respuesta('tool_use', $bloques));

        list($conversation, $assistant) = $this->conversacion();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);
        $this->assertStringStartsWith(AvisosDeTurnosIaHelper::ENCABEZADO_PARA_CONFIRMAR_VARIAS . "\n- ", $assistant->contenido);
        $this->assertStringContainsString('ZZP102 Cheque 1 espera el sí', $assistant->contenido);
        $this->assertStringContainsString('ZZP102 Cheque 2 espera el sí', $assistant->contenido);
        $this->assertStringNotContainsString(AvisosDeTurnosIaHelper::ENCABEZADO_HECHO, $assistant->contenido);
        $this->assertStringEndsWith(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $assistant->contenido);

        foreach (AiMessageAction::where('ai_message_id', $assistant->id)->get() as $tarjeta) {
            $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, (string) $tarjeta->estado, 'La tarjeta sigue confirmable.');
        }
    }

    /**
     * R1 por WhatsApp: el mensaje queda `listo` (el admin manda el CONTENIDO, no su disculpa) y no deja el
     * aviso de turno sin terminar, que es solo para los mensajes en error.
     *
     * @test
     */
    public function por_whatsapp_el_cierre_fallido_queda_listo_y_no_deja_aviso()
    {
        $this->proveedor_que_siempre_pide_herramientas(Http::response(['error' => ['type' => 'api_error', 'message' => 'x']], 400));

        list($conversation, $assistant) = $this->conversacion(AiMessage::CANAL_WHATSAPP, true, 110);

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);
        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, $assistant->contenido);

        if (AvisosDeTurnosIaHelper::hay_tabla()) {
            $this->assertSame(0, AiAvisoDelAsistente::where('ai_message_id', $assistant->id)->count());
        }
    }

    /**
     * R1, el texto armado: las fotos del pedido que quedaron sin usar van en una frase neutra (alguna
     * puede estar en una tarjeta que espera el sí), en singular o plural, antes de la última frase; y la
     * tarjeta de la confirmación determinista (de un mensaje anterior) va primera entre lo hecho.
     *
     * @test
     */
    public function el_texto_armado_cuenta_las_fotos_y_la_tarjeta_confirmada_aparte()
    {
        list($conversation, $assistant) = $this->conversacion();

        $pedido = AiMessage::where('ai_conversation_id', $conversation->id)->where('rol', 'user')->first();

        foreach ([1, 2, 3] as $orden) {
            AiMessageImagen::create([
                'ai_message_id' => $pedido->id,
                'user_id'       => $this->comercio->id,
                'orden'         => $orden,
                'path'          => 'asistente_imagenes/p102/' . $orden . '.webp',
                'mime'          => 'image/webp',
                'bytes'         => 10,
            ]);
        }

        $anterior = AiMessage::create(['ai_conversation_id' => $conversation->id, 'rol' => 'assistant', 'contenido' => 'Te dejo la tarjeta.', 'estado' => 'listo']);

        $aparte = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $anterior->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_GASTO,
            'clave'              => 'gasto:p102-aparte',
            'estado'             => AiMessageAction::ESTADO_CONFIRMADA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Gasto', 'renglones' => [], 'aviso' => null],
            'resultado'          => ['texto' => 'Gasto N° 88 registrado por $ 207.500'],
            'resuelta_at'        => now(),
        ]);

        $propia = AiMessageAction::create([
            'ai_conversation_id' => $conversation->id,
            'ai_message_id'      => $assistant->id,
            'user_id'            => $this->comercio->id,
            'auth_user_id'       => $this->comercio->id,
            'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
            'clave'              => 'tarea:p102-propia',
            'estado'             => AiMessageAction::ESTADO_PROPUESTA,
            'datos'              => [],
            'presentacion'       => ['titulo' => 'Tarea', 'renglones' => [['etiqueta' => 'Detalle', 'valor' => 'Llamar al banco']], 'aviso' => null],
        ]);

        $texto = AvisosDeTurnosIaHelper::texto_de_turno_sin_terminar($assistant, $pedido, $aparte->id);

        $this->assertSame(
            "Quedó hecho:\n- Gasto N° 88 registrado por $ 207.500.\n\n"
            . "Quedó para que la confirmes:\n- Tarea (Detalle: Llamar al banco)\n\n"
            . "Me llegaron 3 fotos y quedaron guardadas.\n\n"
            . AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR,
            $texto
        );

        /* Con una sola foto, en singular; sin nada hecho ni fotos, solo la última frase. */
        AiMessageImagen::where('ai_message_id', $pedido->id)->where('orden', '>', 1)->delete();
        $propia->delete();

        $this->assertSame(
            "Me llegó la foto y quedó guardada.\n\n" . AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR,
            AvisosDeTurnosIaHelper::texto_de_turno_sin_terminar($assistant, $pedido)
        );

        AiMessageImagen::where('ai_message_id', $pedido->id)->delete();

        $this->assertSame(AvisosDeTurnosIaHelper::CIERRE_SIN_TERMINAR, AvisosDeTurnosIaHelper::texto_de_turno_sin_terminar($assistant, $pedido));

        /* Muchas tarjetas: como mucho RENGLONES_POR_SECCION por sección y «- y N más.» (el texto sale por WhatsApp). */
        for ($i = 1; $i <= AvisosDeTurnosIaHelper::RENGLONES_POR_SECCION + 3; $i++) {
            AiMessageAction::create([
                'ai_conversation_id' => $conversation->id,
                'ai_message_id'      => $assistant->id,
                'user_id'            => $this->comercio->id,
                'auth_user_id'       => $this->comercio->id,
                'tipo'               => AiMessageAction::TIPO_TAREA_NUEVA,
                'clave'              => 'tarea:p102-muchas-' . $i,
                'estado'             => AiMessageAction::ESTADO_PROPUESTA,
                'datos'              => [],
                'presentacion'       => ['titulo' => 'Tarea ' . $i, 'renglones' => [], 'aviso' => null],
            ]);
        }

        $con_muchas = AvisosDeTurnosIaHelper::texto_de_turno_sin_terminar($assistant, $pedido);

        $this->assertSame(AvisosDeTurnosIaHelper::RENGLONES_POR_SECCION + 1, substr_count($con_muchas, "\n- "), '10 renglones y el «y N más».');
        $this->assertStringContainsString("\n- y 3 más.", $con_muchas);
        $this->assertStringNotContainsString('Tarea ' . (AvisosDeTurnosIaHelper::RENGLONES_POR_SECCION + 1), $con_muchas);
    }

    /**
     * Si el cierre trae texto Y un tool_use (una propuesta), se toma el texto y la propuesta NO se
     * ejecuta: no queda ninguna tarjeta.
     *
     * @test
     */
    public function el_tool_use_del_cierre_no_se_ejecuta_y_se_toma_su_texto()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->respuesta('tool_use', [
            ['type' => 'text', 'text' => self::TEXTO_DEL_CIERRE],
            [
                'type'  => 'tool_use',
                'id'    => 'toolu_p102_cierre',
                'name'  => 'proponer_tarea',
                'input' => ['detalle' => 'ZZP102 recordatorio que no se tiene que armar', 'fecha' => Carbon::tomorrow()->format('Y-m-d')],
            ],
        ]));

        list($conversation, $assistant) = $this->conversacion();

        $this->assertSame(self::TEXTO_DEL_CIERRE, (new AsistenteIaService())->responder($conversation, $assistant));
        $this->assertSame(0, AiMessageAction::where('ai_message_id', $assistant->id)->count(), 'El tool_use del cierre no deja tarjeta.');
        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $this->bodies_enviados());
    }

    // ---------------------------------------------------------------------
    // Lo hecho queda
    // ---------------------------------------------------------------------

    /**
     * Las cargas que el turno dejó antes del cierre quedan colgadas del mensaje, que termina `listo`:
     * viajan en el historial y el «seguí» no las duplica. Las tres propuestas van JUNTAS en la primera
     * respuesta (la regla nueva del prompt).
     *
     * @test
     */
    public function las_cargas_del_turno_quedan_en_el_mensaje_listo()
    {
        $manana = Carbon::tomorrow()->format('Y-m-d');
        $bloques = [];

        for ($i = 1; $i <= 3; $i++) {
            $bloques[] = [
                'type'  => 'tool_use',
                'id'    => 'toolu_p102_tarea_' . $i,
                'name'  => 'proponer_tarea',
                'input' => ['detalle' => 'ZZP102 Cheque ' . $i . ' de Pinturería Sudamericana se acredita mañana', 'fecha' => $manana],
            ];
        }

        $this->proveedor_que_siempre_pide_herramientas($this->end_turn(self::TEXTO_DEL_CIERRE), null, 0, $this->respuesta('tool_use', $bloques));

        list($conversation, $assistant) = $this->conversacion();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        $assistant = $assistant->fresh();

        $this->assertSame('listo', $assistant->estado, (string) $assistant->error_mensaje);

        $tarjetas = AiMessageAction::where('ai_message_id', $assistant->id)->get();

        $this->assertCount(3, $tarjetas, 'Las tres propuestas del turno quedan en el mensaje.');

        foreach ($tarjetas as $tarjeta) {
            $this->assertSame('propuesta', (string) $tarjeta->estado);
        }

        $this->assertLaUltimaEsElCierre($this->bodies_enviados());
    }

    // ---------------------------------------------------------------------
    // Las guardas
    // ---------------------------------------------------------------------

    /**
     * Después del cierre no hay reintento de guarda (sería una vuelta de más), pero la guarda de después
     * del bucle mira su texto: si afirma una tarjeta que no existe, la persona lee el texto honesto.
     *
     * @test
     */
    public function el_texto_del_cierre_pasa_por_las_guardas_de_despues_del_bucle_sin_reintento()
    {
        $this->proveedor_que_siempre_pide_herramientas($this->end_turn('Dejé la tarjeta del alquiler para confirmar.'));

        list($conversation, $assistant) = $this->conversacion();

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $texto);
        $this->assertCount(AsistenteIaService::MAX_TOOL_ITERATIONS_CON_ACCIONES, $this->bodies_enviados(), 'Ni una vuelta después del cierre.');
    }

    /**
     * Con un reintento de guarda en curso no hay cierre: si se vence el tiempo, vuelve al texto de antes
     * (lo de siempre) y la guarda de después del bucle decide con él.
     *
     * @test
     */
    public function con_un_reintento_de_guarda_en_curso_no_se_cierra_y_vuelve_al_texto_de_antes()
    {
        $servicio = $this->servicio_con_reloj();

        /* 1ª: afirma una tarjeta sin herramienta (reintento); 2ª, el reintento, pide una herramienta y "tarda" 250 s. */
        $llamadas = 0;

        Http::fake(function ($request) use (&$llamadas, $servicio) {
            $llamadas++;

            if ($llamadas === 1) {
                return Http::response($this->end_turn('Encontré la subcategoría Alquiler. Dejo la tarjeta para agendar el pago del 15/10 por 400.000.'), 200);
            }

            $servicio->desfase += 250;

            return Http::response($this->pide_herramienta($llamadas), 200);
        });

        list($conversation, $assistant) = $this->conversacion(null, true, 0, 'El alquiler de 400000 se paga el 15');

        $this->assertSame(TarjetaPrometidaIaHelper::TEXTO_HONESTO, $servicio->responder($conversation, $assistant));

        $bodies = $this->bodies_enviados();

        $this->assertCount(2, $bodies);
        $this->assertSame(0, $this->con_tool_choice($bodies), 'Con un reintento en curso no hay llamada de cierre.');
    }

    // ---------------------------------------------------------------------
    // El texto de tiempo agotado y la regla del prompt
    // ---------------------------------------------------------------------

    /**
     * El texto de tiempo agotado pide «seguí» y ya no «algo más acotado» (contrato cambiado a propósito).
     * Y desde la ronda de arreglos (R1) no promete «desde donde quedó»: solo sale sin ninguna ronda hecha,
     * y un mensaje en error no viaja en el historial (otro cambio de contrato a propósito).
     *
     * @test
     */
    public function el_texto_de_tiempo_agotado_pide_seguir_y_no_acotar()
    {
        $texto = AsistenteIaException::tiempo_agotado('detalle')->mensaje_para_la_persona();

        $this->assertSame('No llegué a terminar esta respuesta. Escribime «seguí» y lo intento de nuevo.', $texto);
        $this->assertStringNotContainsString('acotad', $texto);
        $this->assertStringNotContainsString('desde donde quedó', $texto);
    }

    /**
     * La regla de pedir JUNTAS las herramientas que no dependen entre sí está una sola vez, con y sin
     * acciones y por WhatsApp.
     *
     * @test
     */
    public function el_prompt_pide_juntas_las_herramientas_que_no_dependen_entre_si()
    {
        $servicio = new AsistenteIaService();
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        $dueno = $this->comercio->fresh();

        foreach ([[false, false], [true, false], [true, true]] as $variante) {
            $prompt = $servicio->build_system_prompt($conversation, $dueno, $variante[0], $variante[1]);

            $this->assertSame(1, substr_count($prompt, 'pedilas JUNTAS, todas en la misma respuesta'), 'Variante ' . json_encode($variante));
            $this->assertStringContainsString('cinco recordatorios son cinco llamadas en una sola respuesta', $prompt);
            $this->assertStringContainsString('De a una solo cuando la segunda necesita', $prompt);
        }
    }
}
