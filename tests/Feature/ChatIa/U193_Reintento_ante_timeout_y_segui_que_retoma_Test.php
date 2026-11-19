<?php

namespace Tests\Feature\ChatIa;

use App\Http\Controllers\Helpers\asistente_ia\SeguiTrasUnCorteIaHelper;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\AsistenteIa\AsistenteIaService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión asistente-diario-20261119, rama B — M1: turnos cortados por timeout del proveedor de IA.
 *
 * 🔴 EL CASO REAL (Ferre Total, conv 72, 18/11/2026, 22:25-23:33). Tres turnos terminaron en «No llegué a terminar
 * esta respuesta. Escribime «seguí»» por «cURL error 28 … 60000 ms» contra el proveedor. El reintento de la llamada
 * pedía una llamada ENTERA (60 s) adentro del techo del canal, así que por WhatsApp casi nunca entraba. Y en 725 se
 * registró la masiva de los 145 pero las dos fotos anunciadas nunca se cargaron: el «seguí» no sabía qué faltaba.
 *
 * Se protege: el reintento sale con el tiempo que queda hasta el techo (si quedan al menos 20 s), no pasa el techo, y
 * el «seguí» después de un corte lleva la nota con el pedido que se estaba resolviendo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, ?->, argumentos nombrados, union types, readonly ni enum.
 *
 * @group chat-ia
 */
class U193_Reintento_ante_timeout_y_segui_que_retoma_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.anthropic.api_key'       => null,
            'services.deepseek.api_key'        => 'clave-deepseek-de-prueba-u193',
            'services.deepseek.model'          => 'deepseek-general-u193',
            'services.deepseek.model_agil'     => 'deepseek-flash-u193',
            'services.deepseek.model_profundo' => 'deepseek-pro-u193',
            'services.deepseek.model_vision'   => 'deepseek-vision-u193',
            'broadcasting.default'             => 'null',
        ]);

        $this->comercio = User::create([
            'name'         => 'Comercio timeout U193',
            'company_name' => 'Ferretería U193',
            'email'        => 'timeout-u193-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->comercio->agente_proveedor   = 'deepseek';
        $this->comercio->agente_pensamiento = 'agil';
        $this->comercio->agente_confianza   = 'resuelto';
        $this->comercio->save();
    }

    protected function conversacion()
    {
        return AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
            'origen'       => AiConversation::ORIGEN_WHATSAPP,
        ]);
    }

    protected function mensaje(AiConversation $conversation, $rol, $contenido, $estado = 'listo', array $extra = [])
    {
        return AiMessage::create(array_merge([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => $rol,
            'contenido'            => $contenido,
            'estado'               => $estado,
            'acciones_habilitadas' => false,
            'canal'                => AiMessage::CANAL_WHATSAPP,
        ], $extra));
    }

    /**
     * Un pedido por WhatsApp cuyo assistant se creó hace $hace segundos (el reloj del admin).
     */
    protected function pedido_de_hace($hace)
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', '¿Cuánto vendí hoy?');
        $assistant = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        AiMessage::where('id', $assistant->id)->update(['created_at' => Carbon::now()->subSeconds($hace)]);

        return [$conversation, $assistant->fresh()];
    }

    /**
     * El proveedor: la primera llamada se corta por la red, la segunda contesta.
     *
     * @return \stdClass  Con `llamadas`.
     */
    protected function proveedor_que_se_corta_una_vez()
    {
        $contador = new \stdClass();
        $contador->llamadas = 0;

        Http::fake(function ($request) use ($contador) {
            if (strpos($request->url(), 'deepseek') === false) {
                return Http::response(['error' => 'host sin stub'], 500);
            }

            $contador->llamadas++;

            if ($contador->llamadas === 1) {
                throw new ConnectionException('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received (U193)');
            }

            return Http::response([
                'model'       => 'deepseek-flash-u193',
                'stop_reason' => 'end_turn',
                'content'     => [['type' => 'text', 'text' => 'Hoy vendiste $ 100.']],
                'usage'       => ['input_tokens' => 10, 'output_tokens' => 4],
            ], 200);
        });

        return $contador;
    }

    protected function segundos_para_el_reintento(AsistenteIaService $servicio, $inicio, AiMessage $assistant)
    {
        $metodo = new \ReflectionMethod($servicio, 'segundos_para_el_reintento');
        $metodo->setAccessible(true);

        return $metodo->invoke($servicio, $inicio, $assistant);
    }

    // ---------------------------------------------------------------------
    // El reintento
    // ---------------------------------------------------------------------

    /**
     * 🔴 Por WhatsApp a los 120 s ya no entraba una llamada entera (120 + 60 > 165) y el turno moría. Ahora el
     * reintento sale con los 45 s que quedan y la respuesta llega.
     *
     * @test
     */
    public function por_whatsapp_reintenta_con_el_tiempo_que_queda()
    {
        $contador = $this->proveedor_que_se_corta_una_vez();

        list($conversation, $assistant) = $this->pedido_de_hace(120);

        $texto = (new AsistenteIaService())->responder($conversation, $assistant);

        $this->assertSame(2, $contador->llamadas, 'Se reintenta una vez.');
        $this->assertSame('Hoy vendiste $ 100.', $texto);
    }

    /**
     * Con menos de SEGUNDOS_MINIMOS_DEL_REINTENTO hasta el techo no se reintenta: el corte sigue su camino.
     *
     * @test
     */
    public function sin_tiempo_para_el_reintento_no_se_reintenta()
    {
        $contador = $this->proveedor_que_se_corta_una_vez();

        list($conversation, $assistant) = $this->pedido_de_hace(AsistenteIaService::TECHO_DEL_REINTENTO_EN_WHATSAPP - 10);

        $lanzo = false;

        try {
            (new AsistenteIaService())->responder($conversation, $assistant);
        } catch (\Throwable $e) {
            $lanzo = true;
        }

        $this->assertTrue($lanzo, 'Sin reintento, la falla de red sigue como antes.');
        $this->assertSame(1, $contador->llamadas);
    }

    /**
     * La cuenta: lo que queda hasta el techo del canal, con 60 como máximo. Nunca estira el techo.
     *
     * @test
     */
    public function el_reintento_nunca_pasa_el_techo_del_canal()
    {
        $servicio = new AsistenteIaService();

        list(, $reciente) = $this->pedido_de_hace(0);
        list(, $de_120) = $this->pedido_de_hace(120);
        list(, $de_150) = $this->pedido_de_hace(150);

        $this->assertSame(AsistenteIaService::TIMEOUT_SEGUNDOS, $this->segundos_para_el_reintento($servicio, time(), $reciente));
        $this->assertSame(AsistenteIaService::TECHO_DEL_REINTENTO_EN_WHATSAPP - 120, $this->segundos_para_el_reintento($servicio, time(), $de_120));
        $this->assertLessThan(AsistenteIaService::SEGUNDOS_MINIMOS_DEL_REINTENTO, $this->segundos_para_el_reintento($servicio, time(), $de_150));

        /* En el sistema, el presupuesto del loop. */
        $panel = AiMessage::create(['ai_conversation_id' => $this->conversacion()->id, 'rol' => 'assistant', 'estado' => 'pendiente']);
        $this->assertSame(AsistenteIaService::PRESUPUESTO_SEGUNDOS - 180, $this->segundos_para_el_reintento($servicio, time() - 180, $panel));
    }

    // ---------------------------------------------------------------------
    // El «seguí»
    // ---------------------------------------------------------------------

    /** La escena 722-726: el pedido, el turno cortado y el «seguí». */
    protected function escena_del_corte($segui = 'Seguí')
    {
        $conversation = $this->conversacion();

        $this->mensaje($conversation, 'user', 'Poné la lata como principal y la paleta como secundaria en los 145 y publicalos');
        $this->mensaje(
            $conversation,
            'assistant',
            "Quedó hecho:\n- Actualización masiva encolada: 145 artículos.\n\nNo llegué a terminar esta respuesta. Escribime «seguí» y sigo con lo que falta.",
            'listo',
            ['error_mensaje' => 'turno sin terminar, se contestó con lo hecho: falló la vuelta 5 (después de 4 rondas de herramientas): cURL error 28']
        );
        $this->mensaje($conversation, 'user', $segui);
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        return [$conversation, $contestando];
    }

    /**
     * 🔴 El «seguí» después del corte lleva la nota con el pedido que se estaba resolviendo.
     *
     * @test
     */
    public function el_segui_despues_de_un_corte_lleva_el_pedido_pendiente()
    {
        list($conversation, $contestando) = $this->escena_del_corte();

        $nota = SeguiTrasUnCorteIaHelper::nota($conversation, $contestando);

        $this->assertNotNull($nota);
        $this->assertStringContainsString('se CORTÓ antes de terminar', $nota);
        $this->assertStringContainsString('la paleta como secundaria en los 145', $nota);
        $this->assertStringContainsString('[Tarjeta …]', $nota);
    }

    /**
     * Y viaja en el turno real, pegada al «seguí».
     *
     * @test
     */
    public function la_nota_del_segui_viaja_al_modelo()
    {
        Http::fake([
            'api.deepseek.com/*' => Http::sequence()
                ->push([
                    'model'       => 'deepseek-flash-u193',
                    'stop_reason' => 'end_turn',
                    'content'     => [['type' => 'text', 'text' => 'Sigo con las fotos.']],
                    'usage'       => ['input_tokens' => 10, 'output_tokens' => 4],
                ], 200)
                ->whenEmpty(Http::response(['error' => 'agotada'], 500)),
            '*' => Http::response(['error' => 'host sin stub'], 500),
        ]);

        list($conversation, $contestando) = $this->escena_del_corte('dale, seguí con lo que falta');

        (new AsistenteIaService())->responder($conversation, $contestando);

        $body = null;

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'api.deepseek.com') !== false) {
                $body = json_decode($par[0]->body(), true);
                break;
            }
        }

        $this->assertNotNull($body);

        $ultimo = end($body['messages']);
        $texto = is_string($ultimo['content']) ? $ultimo['content'] : json_encode($ultimo['content'], JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('se CORTÓ antes de terminar', $texto);
    }

    /**
     * Sin corte antes, un «seguí» es un «seguí» común: sin nota. Y lo que no es un «seguí» tampoco la lleva.
     *
     * @test
     */
    public function sin_corte_o_sin_segui_no_hay_nota()
    {
        $conversation = $this->conversacion();
        $this->mensaje($conversation, 'user', 'Listame los remaches');
        $this->mensaje($conversation, 'assistant', 'Son 20. ¿Querés el resto?');
        $this->mensaje($conversation, 'user', 'Seguí');
        $contestando = $this->mensaje($conversation, 'assistant', null, 'pendiente');

        $this->assertNull(SeguiTrasUnCorteIaHelper::nota($conversation, $contestando));

        list($conversation, $contestando) = $this->escena_del_corte('Cuánto vendí hoy en la sucursal centro y en la de florida');
        $this->assertNull(SeguiTrasUnCorteIaHelper::nota($conversation, $contestando));

        $this->assertTrue(SeguiTrasUnCorteIaHelper::es_segui('Seguí'));
        $this->assertTrue(SeguiTrasUnCorteIaHelper::es_segui('dale, seguí con lo que falta'));
        $this->assertTrue(SeguiTrasUnCorteIaHelper::es_segui('Continuá'));
        $this->assertFalse(SeguiTrasUnCorteIaHelper::es_segui('¿seguís ahí?'));
        $this->assertFalse(SeguiTrasUnCorteIaHelper::es_segui('Seguidores de la tienda'));
    }
}
