<?php

namespace Tests\Feature\ChatIa;

use App\Events\ChatIaMensajeActualizado;
use App\Exceptions\AsistenteIaException;
use App\Jobs\ResponderMensajeChatIaJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Misión agente-ia-mano-derecha — A4: que el dueño pueda distinguir por qué falló.
 *
 * Lo que protege este archivo: CUATRO modos de falla bien distintos —el presupuesto de tiempo
 * agotado, el techo de iteraciones sin respuesta, un 529 de Anthropic y cualquier otra falla
 * técnica— salían los cuatro con el MISMO texto rojo, porque el catch genérico del job pisaba los
 * mensajes finos que el servicio sí producía. "Probá de nuevo en unos segundos" es un consejo
 * correcto para el 529 y uno inútil para una consulta que se hizo larga: repetirla igual vuelve a
 * chocar con el mismo techo.
 *
 * 🔴 Y el otro agujero que se cierra acá: el body CRUDO de la respuesta de Anthropic viajaba
 * adentro del mensaje de la excepción. Tiene que seguir yendo al log y a error_mensaje, y no tiene
 * que poder llegar nunca a la pantalla.
 *
 * 🔴 Ningún test de este archivo sale a la red: Http::fake en todos, y la clave se fija a una de
 * mentira. Event::fake porque el evento del canal es ShouldBroadcastNow.
 *
 * PHP 7.4: sin match, sin ?-> y sin str_contains.
 */
class Errores_distinguibles_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el chat. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->comercio = User::create([
            'name'         => 'Comercio errores IA',
            'company_name' => 'Ferreteria errores',
            'email'        => 'chat-ia-errores-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');

        Event::fake([ChatIaMensajeActualizado::class]);
    }

    /**
     * Conversación del dueño con un user 'listo' y un assistant 'pendiente'.
     *
     * @return AiMessage El assistant pendiente.
     */
    protected function pendiente()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => '¿Cuánto stock tengo de tornillos?',
        ]);

        return AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'assistant',
            'estado'             => 'pendiente',
        ]);
    }

    /**
     * Corre el job sobre un pendiente nuevo y devuelve el mensaje recargado.
     *
     * @return AiMessage
     */
    protected function correr_el_job()
    {
        $assistant = $this->pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->handle();

        return $assistant->fresh();
    }

    /**
     * (b) Anthropic sobrecargado: se le dice eso, y no el genérico de conexión cortada. Es el único
     * de los cuatro modos donde "probá de nuevo en unos segundos" es un consejo que sirve.
     *
     * @group chat-ia
     * @test
     */
    public function un_529_le_dice_a_la_persona_que_el_servicio_esta_sobrecargado()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(
            AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_SOBRECARGADO],
            $assistant->contenido
        );
        $this->assertNotEquals(
            ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
            $assistant->contenido,
            'Un 529 ya no puede salir con el texto genérico: es el caso que SÍ se reintenta solo.'
        );
        $this->assertStringContainsString('HTTP 529', (string) $assistant->error_mensaje, 'El detalle técnico va en su columna.');
    }

    /**
     * (a) El loop se comió todas las vueltas encadenando tools y nunca contestó: hay que pedirle a
     * la persona que acote, no que reintente lo mismo.
     *
     * @group chat-ia
     * @test
     */
    public function el_techo_de_iteraciones_sin_respuesta_le_pide_acotar_la_consulta()
    {
        // Anthropic contesta SIEMPRE con una tool call: el loop nunca llega a un texto final.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'model'       => 'claude-modelo-fake',
                'stop_reason' => 'tool_use',
                'content'     => [[
                    'type'  => 'tool_use',
                    'id'    => 'toolu_loop',
                    'name'  => 'consultar_stock_de_articulos',
                    'input' => ['busqueda' => ''],
                ]],
                'usage'       => ['input_tokens' => 100, 'output_tokens' => 10],
            ], 200),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(
            AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_TIEMPO_AGOTADO],
            $assistant->contenido
        );
        $this->assertStringContainsString(
            'iteraciones',
            (string) $assistant->error_mensaje,
            'El detalle técnico tiene que decir que se agotaron las vueltas.'
        );
    }

    /**
     * (c) Cualquier otra falla cae al genérico.
     *
     * 🔴 Y EL BODY CRUDO NO LLEGA A LA PANTALLA. Anthropic contesta el motivo del rechazo adentro
     * del body; ese texto va al log y a error_mensaje, nunca al globo que lee el dueño.
     *
     * @group chat-ia
     * @test
     */
    public function un_error_http_cualquiera_cae_al_generico_y_el_body_crudo_no_llega_a_la_pantalla()
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'invalid_request_error', 'message' => 'esto-es-el-body-crudo-de-anthropic'],
            ], 400),
        ]);

        $assistant = $this->correr_el_job();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE, $assistant->contenido);
        $this->assertStringNotContainsString(
            'esto-es-el-body-crudo-de-anthropic',
            (string) $assistant->contenido,
            'El body crudo de Anthropic NUNCA puede llegar al mensaje que ve la persona.'
        );
        $this->assertStringContainsString(
            'esto-es-el-body-crudo-de-anthropic',
            (string) $assistant->error_mensaje,
            'Pero tampoco se pierde: para diagnosticar hace falta el body entero.'
        );
    }

    /**
     * El presupuesto de tiempo agotado no se puede provocar sin esperar PRESUPUESTO_SEGUNDOS, así
     * que se mide por su contrato: el motivo que lanza el servicio y el texto que el job elige para
     * ese motivo. failed() —la red de seguridad del worker muerto— tiene que elegir igual que
     * handle(), porque los dos pasan por el mismo lugar.
     *
     * @group chat-ia
     * @test
     */
    public function failed_tambien_elige_el_texto_por_el_motivo()
    {
        $assistant = $this->pendiente();

        (new ResponderMensajeChatIaJob($assistant->id))->failed(
            AsistenteIaException::tiempo_agotado('presupuesto de tiempo del loop agotado (210s) tras 3 iteraciones')
        );

        $assistant = $assistant->fresh();

        $this->assertEquals('error', $assistant->estado);
        $this->assertEquals(
            AsistenteIaException::MENSAJES[AsistenteIaException::MOTIVO_TIEMPO_AGOTADO],
            $assistant->contenido
        );
    }

    /**
     * 🔴 EL TEST QUE RESUME LA UNIDAD: los cuatro modos de falla no pueden decir todos lo mismo,
     * que es exactamente lo que pasaba antes. Tiempo agotado pide acotar, sin respuesta pide
     * reintentar, el sobrecargado habla de Anthropic y la falla técnica cae al genérico: cuatro
     * motivos, cuatro textos distintos, y ninguno vacío.
     *
     * @group chat-ia
     * @test
     */
    public function los_cuatro_modos_de_falla_no_dicen_todos_lo_mismo()
    {
        $job = new ResponderMensajeChatIaJob(0);

        $textos = [];

        foreach ([
            AsistenteIaException::tiempo_agotado('t'),
            AsistenteIaException::sin_respuesta('s'),
            AsistenteIaException::sobrecargado('(HTTP 529)'),
            AsistenteIaException::falla_tecnica('body crudo'),
        ] as $exception) {
            $propio = $exception->mensaje_para_la_persona();
            $textos[] = is_null($propio) ? ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE : $propio;
        }

        foreach ($textos as $texto) {
            $this->assertNotEquals('', trim($texto), 'Ningún modo de falla puede dejar el globo vacío.');
        }

        $this->assertCount(
            4,
            array_unique($textos),
            'Los cuatro modos tienen que decir cosas distintas: un solo texto para todos es el defecto que esta unidad viene a cerrar.'
        );
        $this->assertEquals(
            ResponderMensajeChatIaJob::CONTENIDO_ERROR_AMIGABLE,
            $textos[3],
            'El genérico queda SOLO para la falla técnica: lo que lo espere sigue andando.'
        );

        // La falla técnica es la única sin texto propio, y es a propósito: su detalle puede traer
        // el body crudo de Anthropic.
        $this->assertNull(AsistenteIaException::falla_tecnica('body crudo')->mensaje_para_la_persona());

        // Y el job existe para que esto no quede solo en la excepción.
        $this->assertTrue(method_exists($job, 'handle'));
    }
}
