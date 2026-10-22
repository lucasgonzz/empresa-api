<?php

namespace Tests\Feature\AgentesIa;

use App\Jobs\GenerateWhatsappAiReplyJob;
use App\Jobs\ProcessArticleBatchDescriptionsJob;
use App\Jobs\ProcessArticleBatchImagesJob;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Jobs\ProcessProductEnrichmentJob;
use App\Jobs\RunProviderOrderScanJob;
use App\Models\ArticleImageSearchAttempt;
use App\Models\ImageAssignmentRun;
use App\Models\ProductEnrichment;
use App\Models\ProviderOrderScan;
use App\Models\WhatsappBotConfig;
use App\Models\WhatsappChat;
use App\Models\WhatsappChatMessage;
use App\Services\ProductFacts\ProductEnrichmentService;
use App\Services\WhatsappAgentScheduler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Misión agentes-ia-extension-unica (22/10/2026) — los jobs largos y el scheduler del bot revalidan la
 * extensión AL ARRANCAR, con el dueño (no con `Auth`, que en la cola no existe), y salen en silencio.
 *
 * El caso real: el cliente encola una corrida (imágenes, descripciones, escaneo, ficha por GTIN) con la
 * extensión y, antes de que el worker la tome, el admin se la quita. El job no tiene que gastar búsquedas ni
 * llamadas a la IA: tiene que irse sin tocar nada.
 *
 * Cada test llama al job directo (`handle()`), SIN la extensión, y verifica que el estado de lo que iba a
 * procesar quedó intacto y que no salió ninguna llamada a la red.
 */
class Jobs_y_scheduler_revalidan_Test extends AgentesIaTestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->quitar_extension();
    }

    /**
     * @test
     * @return void
     */
    public function la_ficha_por_gtin_no_busca_sin_la_extension()
    {
        $tarea = ProductEnrichment::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->comercio()->id,
            'auth_user_id' => $this->comercio()->id,
            'bar_code'     => '7790000000017',
            'gtin'         => '07790000000017',
            'status'       => 'queued',
        ]);

        (new ProcessProductEnrichmentJob($tarea->id))->handle(app(ProductEnrichmentService::class));

        $this->assertSame('queued', $tarea->fresh()->status, 'El job buscó la ficha sin la extensión.');
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function el_escaneo_de_factura_no_arranca_sin_la_extension()
    {
        $escaneo = ProviderOrderScan::create([
            'uuid'              => (string) Str::uuid(),
            'user_id'           => $this->comercio()->id,
            'auth_user_id'      => $this->comercio()->id,
            'provider_order_id' => 999999,
        ]);

        (new RunProviderOrderScanJob($escaneo->id))->handle();

        $escaneo = $escaneo->fresh();

        $this->assertSame('pendiente', $escaneo->estado, 'El job abrió el escaneo sin la extensión.');
        $this->assertSame(0, (int) $escaneo->progreso);
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function la_asignacion_de_imagenes_no_corre_un_tramo_sin_la_extension()
    {
        $corrida = ImageAssignmentRun::create([
            'user_id'         => $this->comercio()->id,
            'auth_user_id'    => $this->comercio()->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => 'seleccion',
            'proveedor'       => 'google',
            'status'          => 'pendiente',
            'total_articulos' => 3,
        ]);

        (new ProcessImageAssignmentRunJob($corrida->id))->handle();

        $corrida = $corrida->fresh();

        $this->assertSame('pendiente', $corrida->status, 'El tramo corrió (o cerró la corrida) sin la extensión.');
        $this->assertSame(0, (int) $corrida->procesados);
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function la_busqueda_masiva_de_imagenes_no_gasta_cuota_sin_la_extension()
    {
        $antes = ArticleImageSearchAttempt::where('user_id', $this->comercio()->id)->count();

        (new ProcessArticleBatchImagesJob([1, 2, 3], $this->comercio()->id, 'clave-google-falsa', 'cx-falso', 100))->handle();

        $this->assertSame($antes, ArticleImageSearchAttempt::where('user_id', $this->comercio()->id)->count(), 'El job dejó diagnóstico de búsquedas sin la extensión.');
        Http::assertNothingSent();
    }

    /**
     * @test
     * @return void
     */
    public function las_descripciones_masivas_no_se_generan_sin_la_extension()
    {
        (new ProcessArticleBatchDescriptionsJob([1, 2, 3], $this->comercio()->id, 'clave-google-falsa', 'cx-falso', 100))->handle();

        Http::assertNothingSent();
        $this->assertTrue(true);
    }

    /**
     * El scheduler del bot de WhatsApp: con la extensión agenda al agente; sin ella, no encola nada. Y el job
     * que ya estaba encolado (token vigente) sale en silencio si le sacaron la extensión.
     *
     * @test
     * @return void
     */
    public function el_scheduler_y_el_job_del_agente_de_whatsapp_respetan_la_extension()
    {
        Queue::fake();

        $config = WhatsappBotConfig::create([
            'user_id'                => $this->comercio()->id,
            'kapso_api_key'          => 'kapso-agentes-ia',
            'phone_number_id'        => '5491100000098',
            'webhook_secret'         => 'secreto',
            'is_active'              => true,
            'ai_enabled_default'     => true,
            'ai_reply_delay_seconds' => 30,
        ]);

        $chat = WhatsappChat::create([
            'user_id'    => $this->comercio()->id,
            'phone'      => '5493416000011',
            'ai_enabled' => true,
        ]);

        WhatsappChatMessage::create([
            'whatsapp_chat_id' => $chat->id,
            'direction'        => 'in',
            'source'           => 'cliente',
            'body'             => 'Hola, ¿tienen clavos?',
        ]);

        $scheduler = new WhatsappAgentScheduler();

        /* Sin la extensión: no agenda nada. */
        $scheduler->schedule_after_inbound($chat, $config);
        Queue::assertNothingPushed();

        /* Con la extensión: agenda un job. */
        $this->dar_extension();
        $scheduler->schedule_after_inbound($chat, $config);
        Queue::assertPushed(GenerateWhatsappAiReplyJob::class, 1);

        /* El job ya está encolado con su token vigente y, antes de correr, le sacan la extensión. */
        $token = (int) \Illuminate\Support\Facades\DB::table('whatsapp_chats')->where('id', $chat->id)->value('ai_schedule_token');
        $this->quitar_extension();

        $salientes_antes = WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'out')->count();

        (new GenerateWhatsappAiReplyJob($chat->id, max($token, 1)))->handle($scheduler);

        $this->assertSame(
            $salientes_antes,
            WhatsappChatMessage::where('whatsapp_chat_id', $chat->id)->where('direction', 'out')->count(),
            'El job del agente respondió sin la extensión.'
        );
        Http::assertNothingSent();
    }
}
