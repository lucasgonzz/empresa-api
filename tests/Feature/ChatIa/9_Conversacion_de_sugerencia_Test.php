<?php

namespace Tests\Feature\ChatIa;

use App\Jobs\GenerarResumenSugerenciaJob;
use App\Jobs\ProcessStockSuggestionChunkJob;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiTokenUsage;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\StockSuggestion;
use App\Models\StockSuggestionArticle;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Misión chat-ia-y-modulo-ia — P9: la conversación automática de la
 * sugerencia de stock.
 *
 * Lo que protege este archivo: que una sugerencia terminada deja UNA
 * conversación del dueño con el bloque de DATOS como contexto (nunca las
 * instrucciones de redacción) y el resumen como primer mensaje del
 * assistant; que el botón de reintento no multiplica conversaciones (D26);
 * que sin la extensión NADA cambia respecto de hoy (ni conversación ni
 * botón nuevo en la notificación); y que el retrofit de metering del
 * resumen quedó cerrado (pedir_resumen ahora recibe user_id y graba).
 */
class Conversacion_de_sugerencia_Test extends TestCase
{
    use DatabaseTransactions;

    /** Slug de la extensión que habilita el chat. */
    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var Address */
    protected $origen;

    /** @var Address */
    protected $destino;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        // 🔴 Nunca la clave real del .env.testing: cada test setea su fake.
        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'         => 'Comercio chat-ia P9',
            'company_name' => 'Ferreteria P9',
            'email'        => 'chat-ia-p9-' . uniqid() . '@test.local',
            'password'     => Hash::make('secret'),
        ]);

        $this->origen = Address::create([
            'street'  => 'Deposito central P9',
            'user_id' => $this->comercio->id,
        ]);

        $this->destino = Address::create([
            'street'  => 'Sucursal centro P9',
            'user_id' => $this->comercio->id,
        ]);
    }

    /**
     * Asigna la extensión al comercio (creando la fila del catálogo si la
     * base del slot todavía no la tiene sembrada).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => self::SLUG,
                'name' => 'Asistente IA',
            ]);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Sugerencia terminada con dos líneas priorizadas, para que armar_datos()
     * tenga números y nombres reales que verificar en el contexto.
     *
     * @return StockSuggestion
     */
    protected function sugerencia_terminada()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'terminado',
            'user_id'       => $this->comercio->id,
        ]);

        $nombres = ['Tornillo P9', 'Pinza P9'];

        foreach ($nombres as $indice => $nombre) {
            $article = Article::create([
                'name'    => $nombre,
                'user_id' => $this->comercio->id,
            ]);

            StockSuggestionArticle::create([
                'stock_suggestion_id' => $suggestion->id,
                'article_id'          => $article->id,
                'from_address_id'     => $this->origen->id,
                'to_address_id'       => $this->destino->id,
                'suggested_amount'    => 5 + $indice,
                'prioridad'           => $indice + 1,
                'cobertura_dias'      => 3 + $indice,
            ]);
        }

        return $suggestion;
    }

    /**
     * Texto que el fake de Anthropic responde en la PRÓXIMA llamada (lo lee
     * el stub registrado en correr_el_job).
     *
     * @var string
     */
    protected $texto_de_resumen_fake = '';

    /**
     * true cuando el stub de api.anthropic.com ya quedó registrado en este test.
     *
     * @var bool
     */
    protected $fake_de_anthropic_registrado = false;

    /**
     * Corre el job del resumen contra un fake que responde el texto dado.
     *
     * 🔴 El stub se registra UNA sola vez por test y lee el texto vigente de
     * la propiedad: Http::fake ACUMULA stubs (Factory::fake hace merge) y el
     * handler toma el PRIMERO que matchea (buildStubHandler:
     * ->filter()->first()). Registrar un stub nuevo por corrida deja al
     * primero respondiendo para siempre — el test de idempotencia corría el
     * reintento contra el resumen viejo y el "no actualiza" era del fake, no
     * del job.
     *
     * @param StockSuggestion $suggestion
     * @param string $texto
     * @return void
     */
    protected function correr_el_job($suggestion, $texto)
    {
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $this->texto_de_resumen_fake = $texto;

        if (!$this->fake_de_anthropic_registrado) {
            Http::fake([
                'api.anthropic.com/*' => function () {
                    return Http::response([
                        'model'   => 'claude-modelo-fake',
                        'content' => [
                            ['type' => 'text', 'text' => $this->texto_de_resumen_fake],
                        ],
                        'usage'   => ['input_tokens' => 400, 'output_tokens' => 90],
                    ], 200);
                },
            ]);

            $this->fake_de_anthropic_registrado = true;
        }

        (new GenerarResumenSugerenciaJob($suggestion->id))->handle();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_la_extension_el_job_deja_una_conversacion_del_dueno_con_el_resumen()
    {
        $this->dar_extension();

        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Mové primero los tornillos al centro: quedan 3 días de cobertura.');

        // El resumen en sí sigue funcionando igual que antes.
        $suggestion->refresh();
        $this->assertEquals('listo', $suggestion->resumen_ia_estado);

        $conversaciones = AiConversation::where('origen', 'sugerencia_stock')
            ->where('referencia_id', $suggestion->id)
            ->get();

        $this->assertCount(1, $conversaciones);

        $conversation = $conversaciones[0];

        // D25: la conversación es del DUEÑO (la sugerencia no sabe de personas).
        $this->assertEquals($this->comercio->id, (int) $conversation->user_id);
        $this->assertEquals($this->comercio->id, (int) $conversation->auth_user_id);
        $this->assertEquals('Sugerencia de stock #' . $suggestion->id, $conversation->titulo);
        $this->assertNotNull($conversation->last_message_at, 'Sin actividad la sidebar la mandaría al fondo.');

        // El primer (y único) mensaje es el resumen, ya 'listo'.
        $mensajes = AiMessage::where('ai_conversation_id', $conversation->id)->get();
        $this->assertCount(1, $mensajes);
        $this->assertEquals('assistant', $mensajes[0]->rol);
        $this->assertEquals('listo', $mensajes[0]->estado);
        $this->assertEquals('Mové primero los tornillos al centro: quedan 3 días de cobertura.', $mensajes[0]->contenido);

        // Retrofit de metering cerrado: pedir_resumen recibió user_id y grabó.
        $fila = AiTokenUsage::where('proceso', 'resumen_sugerencia_stock')
            ->where('referencia_id', $suggestion->id)
            ->first();
        $this->assertNotNull($fila, 'El resumen tiene que dejar su fila de consumo (cierre del retrofit P3/P9).');
        $this->assertEquals($this->comercio->id, (int) $fila->user_id);
        $this->assertEquals(400, (int) $fila->input_tokens);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function el_contexto_guarda_los_datos_calculados_y_no_la_instruccion_de_redactar()
    {
        $this->dar_extension();

        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Resumen para el contexto.');

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();

        // D13: el contexto es armar_datos() — los números y nombres reales...
        $this->assertStringContainsString('Datos ya calculados:', $conversation->contexto);
        $this->assertStringContainsString('Lineas de traslado sugeridas: 2', $conversation->contexto);
        $this->assertStringContainsString('Tornillo P9', $conversation->contexto);
        $this->assertStringContainsString('Deposito central P9', $conversation->contexto);

        // ...y NUNCA el prompt de redacción: la conversación charla sobre los
        // datos, no hereda las órdenes de escribir un resumen.
        $this->assertStringNotContainsString('Responde solo con el texto plano', $conversation->contexto);
        $this->assertStringNotContainsString('Maximo 6 oraciones', $conversation->contexto);
        $this->assertStringNotContainsString('encargado de deposito', $conversation->contexto);
    }

    /**
     * D26: regenerar_resumen vuelve a despachar el job. Correrlo de nuevo
     * actualiza la conversación existente en vez de crear otra — sin esto,
     * tres reintentos dejaban tres conversaciones iguales en la sidebar.
     *
     * @group chat-ia
     * @test
     */
    public function correr_el_job_dos_veces_actualiza_la_conversacion_en_vez_de_duplicarla()
    {
        $this->dar_extension();

        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Primer resumen.');
        $this->correr_el_job($suggestion, 'Segundo resumen, corregido tras el reintento.');

        $conversaciones = AiConversation::where('origen', 'sugerencia_stock')
            ->where('referencia_id', $suggestion->id)
            ->get();

        $this->assertCount(1, $conversaciones, 'El reintento no puede duplicar la conversación.');

        $mensajes = AiMessage::where('ai_conversation_id', $conversaciones[0]->id)->get();

        $this->assertCount(1, $mensajes, 'El reintento tampoco puede apilar mensajes.');
        $this->assertEquals(
            'Segundo resumen, corregido tras el reintento.',
            $mensajes[0]->contenido,
            'El primer mensaje assistant se actualiza con el resumen nuevo.'
        );
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_no_se_crea_conversacion_y_la_notificacion_queda_como_hoy()
    {
        // A propósito SIN dar_extension: es el cliente que no tiene el chat.
        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Resumen sin chat.');

        // El resumen sigue andando; la conversación no existe.
        $this->assertEquals('listo', $suggestion->fresh()->resumen_ia_estado);
        $this->assertEquals(0, AiConversation::where('referencia_id', $suggestion->id)->count());

        // Y la notificación es EXACTAMENTE la de hoy: dos botones, sin claves nuevas.
        (new ProcessStockSuggestionChunkJob([], $suggestion->id))->notificacion();

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            $funciones = array_column($notification->functions_to_execute, 'btn_text');

            return count($notification->functions_to_execute) === 2
                && $funciones === ['Ver sugerencia', 'Entendido']
                && !array_key_exists('ai_conversation_id', $notification->info_to_show[0])
                && !array_key_exists('ai_conversation_auth_user_id', $notification->info_to_show[0]);
        });
    }

    /**
     * D21/D22: con la conversación ya creada, la notificación suma el botón
     * "Charlar con la IA" y los dos ids que lee la SPA — y nada más: por el
     * canal público de notificaciones no viaja ni una letra del chat.
     *
     * @group chat-ia
     * @test
     */
    public function con_la_conversacion_creada_la_notificacion_suma_el_boton_y_los_ids()
    {
        $this->dar_extension();

        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Resumen que abre conversación.');

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation);

        (new ProcessStockSuggestionChunkJob([], $suggestion->id))->notificacion();

        Notification::assertSentTo(
            $this->comercio,
            GlobalNotification::class,
            function ($notification) use ($conversation, $suggestion) {
                $textos = array_column($notification->functions_to_execute, 'btn_text');
                $info = $notification->info_to_show[0];

                return $textos === ['Ver sugerencia', 'Charlar con la IA', 'Entendido']
                    && $notification->functions_to_execute[1]['function_name'] === 'abrir_conversacion_de_sugerencia'
                    // "Ver sugerencia" sigue intacto: misma función de siempre.
                    && $notification->functions_to_execute[0]['function_name'] === 'ir_a_sugerencias_de_stock'
                    && (int) $info['ai_conversation_id'] === $conversation->id
                    && (int) $info['ai_conversation_auth_user_id'] === $this->comercio->id
                    && (int) $info['stock_suggestion_id'] === $suggestion->id;
            }
        );
    }
}
