<?php

namespace Tests\Feature\ChatIa;

use App\Jobs\GenerarResumenSugerenciaJob;
use App\Jobs\GenerateStockSuggestionChunksJob;
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
use Illuminate\Support\Facades\Queue;
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
 *
 * Y desde el arreglo post-chequeo, el ORDEN de la notificación de fin de
 * corrida: con credenciales el chunk job NO notifica — le pasa la posta a
 * GenerarResumenSugerenciaJob, que anuncia en TODOS sus finales (éxito con
 * el botón del chat, error/failed con los botones de hoy) —; sin
 * credenciales notifica el chunk job como siempre. Antes el chunk
 * notificaba inline y, con la cola database real, el aviso salía antes de
 * que la conversación existiera: el botón no aparecía nunca en producción.
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
     * @param bool $con_posta true = como lo despacha el chunk job (el job del
     *                        resumen anuncia el fin de la corrida); false =
     *                        como lo despacha regenerar_resumen (no anuncia).
     * @return void
     */
    protected function correr_el_job($suggestion, $texto, $con_posta = false)
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

        (new GenerarResumenSugerenciaJob($suggestion->id, $con_posta))->handle();
    }

    /**
     * Artículo con déficit en la sucursal destino, para que el pipeline
     * completo genere al menos una línea (molde del test 6 de
     * SugerenciasStock: needed = stock_min - amount).
     *
     * @param string $nombre
     * @return Article
     */
    protected function articulo_en_deficit($nombre)
    {
        $article = Article::create([
            'name'    => $nombre,
            'user_id' => $this->comercio->id,
        ]);

        $article->addresses()->attach($this->origen->id, [
            'amount' => 100, 'stock_min' => 10, 'stock_max' => 20,
        ]);
        $article->addresses()->attach($this->destino->id, [
            'amount' => 2, 'stock_min' => 10, 'stock_max' => 20,
        ]);

        return $article;
    }

    /**
     * Corre el pipeline real de generación (los chunks corren inline adentro
     * de GenerateStockSuggestionChunksJob) y devuelve la sugerencia fresca.
     * Queue::fake lo pone cada test ANTES, para capturar el dispatch del job
     * del resumen sin que salga a la red.
     *
     * @return StockSuggestion
     */
    protected function correr_pipeline_completo()
    {
        $suggestion = StockSuggestion::create([
            'modo'          => 'minimo',
            'origen'        => 'absoluto',
            'limite_origen' => 'minimo',
            'status'        => 'pendiente',
            'user_id'       => $this->comercio->id,
        ]);

        (new GenerateStockSuggestionChunksJob($suggestion->id))->handle();

        return $suggestion->fresh();
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

        // Sin la posta (así lo despacha regenerar_resumen) el job del resumen
        // NO anuncia el fin de la corrida: ya se anunció cuando terminó, y
        // cada reintento del botón re-notificando sería ruido.
        Notification::assertNothingSent();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_la_extension_no_se_crea_conversacion_y_la_notificacion_queda_como_hoy()
    {
        // A propósito SIN dar_extension: es el cliente que no tiene el chat.
        $suggestion = $this->sugerencia_terminada();

        // Con la posta, como lo despacha el chunk job cuando hay credenciales:
        // el aviso de fin de corrida lo manda este job al terminar.
        $this->correr_el_job($suggestion, 'Resumen sin chat.', true);

        // El resumen sigue andando; la conversación no existe.
        $this->assertEquals('listo', $suggestion->fresh()->resumen_ia_estado);
        $this->assertEquals(0, AiConversation::where('referencia_id', $suggestion->id)->count());

        // Y la notificación es EXACTAMENTE la de hoy: dos botones, sin claves nuevas.
        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            $funciones = array_column($notification->functions_to_execute, 'btn_text');

            return count($notification->functions_to_execute) === 2
                && $funciones === ['Ver sugerencia', 'Entendido']
                && !array_key_exists('ai_conversation_id', $notification->info_to_show[0])
                && !array_key_exists('ai_conversation_auth_user_id', $notification->info_to_show[0]);
        });
    }

    /**
     * D21/D22: la notificación que manda el job del resumen con la posta suma
     * el botón "Charlar con la IA" y los dos ids que lee la SPA — y nada más:
     * por el canal público de notificaciones no viaja ni una letra del chat.
     * Es UNA sola corrida la que crea la conversación Y anuncia: la carrera
     * del diseño anterior (aviso antes de que la conversación exista)
     * desapareció de raíz.
     *
     * @group chat-ia
     * @test
     */
    public function con_la_posta_la_notificacion_del_job_del_resumen_suma_el_boton_y_los_ids()
    {
        $this->dar_extension();

        $suggestion = $this->sugerencia_terminada();

        $this->correr_el_job($suggestion, 'Resumen que abre conversación.', true);

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation);

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

        // Ni un camino mudo NI dos avisos: el fin de la corrida se anuncia
        // exactamente una vez.
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * El ORDEN nuevo, mitad "con credenciales" (arreglo post-chequeo): el
     * chunk job que cierra la corrida NO notifica — despacha el job del
     * resumen con la posta — y es ESE job, al correr después (acá se lo corre
     * a mano, como lo haría el worker de la cola database), el que anuncia ya
     * con el botón del chat y los ids en info_to_show.
     *
     * @group chat-ia
     * @test
     */
    public function con_credenciales_el_chunk_job_no_notifica_y_el_aviso_sale_del_job_del_resumen()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();

        $this->articulo_en_deficit('Taladro posta P9');
        $suggestion = $this->correr_pipeline_completo();

        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals('pendiente', $suggestion->resumen_ia_estado);

        // Mitad 1: el chunk job cerró la corrida SIN notificar...
        Notification::assertNothingSent();

        // ...porque le pasó la posta al job del resumen.
        Queue::assertPushed(GenerarResumenSugerenciaJob::class, function ($job) {
            return $job->notificar_al_terminar === true;
        });

        // Mitad 2: corre el job del resumen (el turno que en producción le da
        // el worker) y el aviso sale con el botón del chat: en el diseño
        // anterior este mismo flujo notificaba antes de crear la conversación
        // y el botón no aparecía nunca fuera de los tests.
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Mové el taladro a la sucursal antes del quiebre.'],
                ],
                'usage'   => ['input_tokens' => 300, 'output_tokens' => 60],
            ], 200),
        ]);

        (new GenerarResumenSugerenciaJob($suggestion->id, true))->handle();

        $conversation = AiConversation::where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation, 'La conversación existe ANTES del aviso: ese es el arreglo.');

        Notification::assertSentTo(
            $this->comercio,
            GlobalNotification::class,
            function ($notification) use ($conversation) {
                $textos = array_column($notification->functions_to_execute, 'btn_text');

                return $textos === ['Ver sugerencia', 'Charlar con la IA', 'Entendido']
                    && (int) $notification->info_to_show[0]['ai_conversation_id'] === $conversation->id;
            }
        );
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * El ORDEN nuevo, mitad "sin credenciales": no hay job de resumen que
     * pueda avisar, así que el chunk job notifica inline como siempre — con
     * los dos botones de hoy y sin claves del chat.
     *
     * @group chat-ia
     * @test
     */
    public function sin_credenciales_notifica_el_chunk_job_y_no_se_despacha_el_resumen()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => '']);
        Queue::fake();

        $this->articulo_en_deficit('Taladro sin IA P9');
        $suggestion = $this->correr_pipeline_completo();

        $this->assertEquals('terminado', $suggestion->status);

        Queue::assertNotPushed(GenerarResumenSugerenciaJob::class);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            $funciones = array_column($notification->functions_to_execute, 'btn_text');

            return $notification->message_text === 'Sugerencia de stock terminada'
                && $funciones === ['Ver sugerencia', 'Entendido']
                && !array_key_exists('ai_conversation_id', $notification->info_to_show[0]);
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * Camino de error con la posta: si Anthropic se cae (529), el resumen
     * queda en error, la tabla sigue usable y la corrida SE ANUNCIA IGUAL —
     * con los botones de hoy, sin el botón del chat. Ningún final del job del
     * resumen puede dejar la corrida sin aviso.
     *
     * @group chat-ia
     * @test
     */
    public function si_el_resumen_falla_la_corrida_se_anuncia_igual_sin_el_boton_del_chat()
    {
        $this->dar_extension();
        config(['services.anthropic.api_key' => 'clave-de-prueba']);

        $suggestion = $this->sugerencia_terminada();

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded'],
            ], 529),
        ]);

        (new GenerarResumenSugerenciaJob($suggestion->id, true))->handle();

        $suggestion->refresh();
        $this->assertEquals('error', $suggestion->resumen_ia_estado);
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals(0, AiConversation::where('referencia_id', $suggestion->id)->count());

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            $funciones = array_column($notification->functions_to_execute, 'btn_text');

            return $notification->message_text === 'Sugerencia de stock terminada'
                && $funciones === ['Ver sugerencia', 'Entendido'];
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * Camino failed() con la posta (worker muerto, timeout: handle() nunca
     * llegó a ninguno de sus avisos): el resumen queda en error amigable y la
     * corrida también se anuncia. Es la quinta salida del patrón de
     * RunExcelAnalysisJob (misión 61).
     *
     * @group chat-ia
     * @test
     */
    public function failed_con_la_posta_tambien_anuncia_la_corrida()
    {
        // A propósito sin extensión ni credenciales: el aviso de failed() no
        // depende de nada más que de la posta y de la corrida terminada.
        $suggestion = $this->sugerencia_terminada();
        $suggestion->resumen_ia_estado = 'pendiente';
        $suggestion->save();

        (new GenerarResumenSugerenciaJob($suggestion->id, true))->failed(new \Exception('worker muerto'));

        $suggestion->refresh();
        $this->assertEquals('error', $suggestion->resumen_ia_estado);
        $this->assertEquals('terminado', $suggestion->status);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            return $notification->message_text === 'Sugerencia de stock terminada';
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }
}
