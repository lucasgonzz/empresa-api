<?php

namespace Tests\Feature\MotorDeOfertas;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Jobs\GenerarResumenSugerenciaOfertaJob;
use App\Jobs\GenerateOfferSuggestionChunksJob;
use App\Models\AiConversation;
use App\Models\Article;
use App\Models\ArticlePurchase;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\OfferSuggestion;
use App\Models\OfferSuggestionLine;
use App\Models\Sale;
use App\Models\User;
use App\Notifications\GlobalNotification;
use App\Services\OfertasClientes\ResumenIaOfertasService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión motor-de-ofertas-por-cliente — archivo 4: el pipeline (los tres jobs, la prioridad y el
 * cierre de la corrida).
 * 🔴 El test que más importa del archivo es el del REPARTO DE LA POSTA de notificación: con
 * credenciales avisa el job del resumen y sin ellas el job del lote. Si los dos avisan el comerciante
 * recibe el mismo aviso dos veces; si no avisa ninguno, no recibe nada. Es un bug que este proyecto ya
 * pagó dos veces y que en cola 'sync' (la de los tests) NO se ve solo: hay que buscarlo a propósito,
 * que es lo que hacen los dos tests de abajo.
 */
class Pipeline_y_prioridad_Test extends TestCase
{
    use DatabaseTransactions;

    /** ivas.id de la alícuota del 21%. */
    const IVA_21 = 2;

    /** @var User */
    protected $comercio;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // 🔴 Sin esto el pipeline sale a la API real de Anthropic: la clave vive en el .env.testing.
        config(['services.anthropic.api_key' => null]);
        // El usuario 500 es el de .env.testing: es el único con la config fiscal sembrada que hace que
        // la cadena de precios devuelva un precio real (sin precio no hay techo y no hay línea).
        $this->comercio = User::find(500);
        if (is_null($this->comercio)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        config(['app.USER_ID' => $this->comercio->id]);
    }

    protected function tearDown(): void
    {
        // El objeto en memoria y la cache estática del helper no participan de la transacción.
        ArticlePricesHelper::$sale_taxes_cache = [];
        parent::tearDown();
    }

    /**
     * Artículo pasado por el camino REAL del sistema (setFinalPrice con $guardar_cambios = true) para
     * que costo_real y final_price queden persistidos como en producción. 🔴 Ningún precio se
     * hardcodea acá ni en las aserciones: los esperados salen del techo y el piso que calculó el motor.
     * @return Article
     */
    protected function articulo($nombre)
    {
        $article = Article::create([
            'name' => $nombre . '-' . uniqid(), 'user_id' => $this->comercio->id,
            'cost' => 1000, 'percentage_gain' => 20, 'aplicar_iva' => 1, 'iva_id' => self::IVA_21,
        ]);
        ArticleHelper::setFinalPrice($article, null, $this->comercio, null, true);

        return Article::find($article->id);
    }

    /** @return Client */
    protected function cliente($nombre)
    {
        return Client::create(['name' => $nombre . '-' . uniqid(), 'user_id' => $this->comercio->id]);
    }

    /** Una compra real: la venta y su línea de article_purchases, con la misma fecha. */
    protected function comprar($client, $article, $dias_atras)
    {
        $fecha = now()->subDays($dias_atras);
        $sale  = Sale::create(['user_id' => $this->comercio->id, 'client_id' => $client->id, 'created_at' => $fecha]);
        ArticlePurchase::create(['sale_id' => $sale->id, 'client_id' => $client->id,
            'article_id' => $article->id, 'amount' => 1, 'created_at' => $fecha]);
    }

    /** @return OfferSuggestion */
    protected function corrida(array $overrides = [])
    {
        return OfferSuggestion::create(array_merge([
            'user_id' => $this->comercio->id, 'status' => 'pendiente', 'origen_generacion' => 'manual',
            'dias_historial_afinidad' => 180, 'dias_inactividad_reactivacion' => 60,
            'dias_carrito_abandonado' => 7, 'max_ofertas_por_cliente' => 3, 'dias_vigencia_sugerida' => 15,
        ], $overrides));
    }

    /**
     * Dos clientes que compraron dos artículos: alcanza para que la corrida tenga varias líneas y el
     * ranking tenga algo que ordenar.
     * @return array Los clientes del escenario
     */
    protected function escenario()
    {
        $clientes  = [$this->cliente('Cliente pipeline A'), $this->cliente('Cliente pipeline B')];
        $articulos = [$this->articulo('zz-pipe-uno'), $this->articulo('zz-pipe-dos')];

        foreach ($clientes as $cliente) {
            foreach ($articulos as $articulo) {
                $this->comprar($cliente, $articulo, 10);
                $this->comprar($cliente, $articulo, 30);
            }
        }

        return $clientes;
    }

    protected function dar_extension($slug, $nombre)
    {
        if (UserHelper::hasExtencion($slug, $this->comercio)) {
            return;
        }
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();
        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $nombre]);
        }
        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /** La respuesta que devolvería Anthropic, con el JSON adentro del bloque de texto. */
    protected function respuesta_ia($resumen)
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['resumen' => $resumen, 'lineas' => []])]],
            'usage'   => ['input_tokens' => 300, 'output_tokens' => 60],
        ], 200)]);
    }

    /**
     * El camino completo: corrida manual, líneas insertadas, ranking materializado 1..N sin repetidos
     * (numerar por lote daría varias "prioridad 1") y los tres informativos de la cabecera llenos.
     * total_clientes_excluidos_por_deuda no se puede recalcular después de la corrida: los clientes con
     * deuda no dejan ninguna línea de la que contarlos.
     * @group motor-de-ofertas
     * @test
     */
    public function la_corrida_manual_cierra_con_la_prioridad_de_1_a_n_y_los_tres_informativos()
    {
        $clientes   = $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();
        $ids = array_map(function ($c) { return $c->id; }, $clientes);
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertGreaterThanOrEqual(4, OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)
            ->whereIn('client_id', $ids)->count(), 'dos clientes por dos artículos');
        $prioridades = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)
            ->orderBy('prioridad', 'ASC')->pluck('prioridad')->all();
        $this->assertSame(range(1, count($prioridades)), array_map('intval', $prioridades));
        $this->assertSame(count($prioridades), count(array_unique($prioridades)), 'ninguna prioridad repetida');

        $this->assertSame(count($prioridades), (int) $suggestion->total_lineas);
        $this->assertGreaterThanOrEqual(count($clientes), (int) $suggestion->total_clientes);
        $this->assertNotNull($suggestion->total_clientes_excluidos_por_deuda, 'explica por qué hay menos clientes de los esperados');
    }

    /**
     * 🔴 Re-entrancia: el job padre borra las líneas ANTES de calcular total_chunks, así que un retry
     * re-inserta desde cero en vez de duplicar.
     * @group motor-de-ofertas
     * @test
     */
    public function correr_el_job_padre_dos_veces_no_duplica_lineas()
    {
        $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $primera = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)->count();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $segunda = OfferSuggestionLine::where('offer_suggestion_id', $suggestion->id)->count();

        $this->assertGreaterThan(0, $primera);
        $this->assertSame($primera, $segunda, 'la segunda corrida limpia y regenera, no acumula');
        $this->assertEquals('terminado', $suggestion->fresh()->status);
    }

    /**
     * failed() deja la corrida en 'error' con su mensaje, y nunca pisa una que ya terminó.
     * @group motor-de-ofertas
     * @test
     */
    public function failed_marca_error_y_nunca_pisa_una_corrida_terminada()
    {
        $rota = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($rota->id))->failed(new \RuntimeException('se cayó el worker'));
        $rota->refresh();
        $this->assertEquals('error', $rota->status);
        $this->assertStringContainsString('se cayó el worker', (string) $rota->error_mensaje);

        $terminada = $this->corrida(['status' => 'terminado']);
        (new GenerateOfferSuggestionChunksJob($terminada->id))->failed(new \RuntimeException('llegó tarde'));
        $this->assertEquals('terminado', $terminada->fresh()->status);
        $this->assertNull($terminada->fresh()->error_mensaje);
    }

    /**
     * 🔴 SIN credenciales: la corrida termina igual, el resumen queda en null (no en error) y el aviso
     * sale DEL JOB DEL LOTE, porque no hay job de resumen que pueda darlo.
     * @group motor-de-ofertas
     * @test
     */
    public function sin_credenciales_notifica_el_job_del_lote_y_no_se_despacha_el_resumen()
    {
        config(['services.anthropic.api_key' => '']);
        Queue::fake();
        $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();

        $this->assertEquals('terminado', $suggestion->status);
        $this->assertNull($suggestion->resumen_ia_estado, 'no tener IA contratada no es un error');
        Queue::assertNotPushed(GenerarResumenSugerenciaOfertaJob::class);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            return $notification->message_text === 'Ofertas sugeridas listas'
                && array_column($notification->functions_to_execute, 'btn_text') === ['Ver las ofertas', 'Entendido']
                && !array_key_exists('ai_conversation_id', $notification->info_to_show[0]);
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * 🔴 CON credenciales: el job del lote NO avisa, le pasa la posta al del resumen — que avisa recién
     * cuando la conversación del chat ya existe. Si el lote avisara también, en producción (cola
     * database) el aviso saldría antes de que exista la conversación y el botón "Charlar con la IA" no
     * aparecería nunca; en cola 'sync' se vería bien igual. Por eso se prueba en dos mitades.
     * @group motor-de-ofertas
     * @test
     */
    public function con_credenciales_no_avisa_el_lote_y_el_aviso_sale_del_job_del_resumen()
    {
        $this->dar_extension('asistente_ia', 'Asistente IA');
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        Queue::fake();
        $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();
        $this->assertEquals('terminado', $suggestion->status);
        $this->assertEquals('pendiente', $suggestion->resumen_ia_estado);

        // Mitad 1: el lote cerró la corrida SIN notificar...
        Notification::assertNothingSent();
        // ...porque le pasó la posta al job del resumen.
        Queue::assertPushed(GenerarResumenSugerenciaOfertaJob::class, function ($job) {
            return $job->notificar_al_terminar === true;
        });

        // Mitad 2: corre el job del resumen, que es lo que en producción hace el worker de la cola.
        $this->respuesta_ia('Le podés dar una mano a los clientes que hace rato no vienen.');
        (new GenerarResumenSugerenciaOfertaJob($suggestion->id, true))->handle();

        $conversation = AiConversation::where('origen', 'sugerencia_oferta')
            ->where('referencia_id', $suggestion->id)->first();
        $this->assertNotNull($conversation, 'la conversación existe ANTES del aviso: ese es el arreglo que este test protege');
        $this->assertEquals('listo', $suggestion->fresh()->resumen_ia_estado);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) use ($conversation) {
            return array_column($notification->functions_to_execute, 'btn_text') === ['Ver las ofertas', 'Charlar con la IA', 'Entendido']
                && (int) $notification->info_to_show[0]['ai_conversation_id'] === $conversation->id;
        });
        Notification::assertSentToTimes($this->comercio, GlobalNotification::class, 1);
    }

    /**
     * 🔴 Sin la extensión asistente_ia NO se crea ninguna conversación —y por eso el aviso sale sin el
     * botón del chat—, pero la corrida y el resumen terminan igual. Va declarado en el despliegue.
     * @group motor-de-ofertas
     * @test
     */
    public function sin_la_extension_del_asistente_no_se_crea_conversacion_y_el_aviso_sale_igual()
    {
        if (UserHelper::hasExtencion('asistente_ia', $this->comercio)) {
            $this->markTestSkipped('El comercio 500 ya tiene la extensión asistente_ia sembrada.');
        }
        config(['services.anthropic.api_key' => 'clave-de-prueba']);
        /*
         * 🔴 LOS DOS FAKES VAN ANTES DEL handle(), Y NO ES CINTURÓN Y TIRADORES. Este test repone la
         * clave (pisando el config() del setUp) y el pipeline, con la cola en 'sync' de phpunit,
         * ejecuta GenerarResumenSugerenciaOfertaJob EN EL ACTO adentro del handle()
         * (ProcessOfferSuggestionChunkJob.php:172). Sin Queue::fake() ni Http::fake() puestos antes,
         * ese job hacía un POST REAL a api.anthropic.com — comprobado con un proxy roto: el test
         * pasaba igual porque el servicio se traga el error de conexión, así que la fuga era muda.
         * Hoy viaja 'clave-de-prueba', pero .env.testing tiene una clave REAL y lo único que separaba
         * a la suite de gastarla era esa línea de más arriba.
         *
         * Queue::fake() es el arreglo estructural (mismo criterio que el test de acá arriba: el job
         * del resumen se corre a mano después, que es lo que este test quiere probar) y el Http::fake()
         * queda igual antes por si alguien saca el Queue::fake() sin mirar esto.
         */
        Queue::fake();
        $this->respuesta_ia('Resumen sin chat.');
        $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        (new GenerarResumenSugerenciaOfertaJob($suggestion->id, true))->handle();

        $this->assertNull(AiConversation::where('origen', 'sugerencia_oferta')->where('referencia_id', $suggestion->id)->first());
        $this->assertEquals('listo', $suggestion->fresh()->resumen_ia_estado);
        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notification) {
            return array_column($notification->functions_to_execute, 'btn_text') === ['Ver las ofertas', 'Entendido'];
        });
    }

    /**
     * 🔴 armar_datos() son SOLO datos: es lo que el chat guarda en ai_conversations.contexto, y si se
     * le colara una instrucción de redacción la conversación heredaría la orden de escribir un resumen
     * en vez de charlar sobre los números. Cada palabra prohibida se afirma en las DOS puntas —ausente
     * en los datos, presente en el prompt— para que el test no pase por escribirlas distinto.
     * @group motor-de-ofertas
     * @test
     */
    public function armar_datos_no_trae_ninguna_instruccion_de_redaccion()
    {
        $this->escenario();
        $suggestion = $this->corrida();
        (new GenerateOfferSuggestionChunksJob($suggestion->id))->handle();
        $suggestion->refresh();
        $servicio = new ResumenIaOfertasService();
        $datos    = $servicio->armar_datos($suggestion);
        $prompt   = $servicio->armar_prompt($suggestion);

        $this->assertStringContainsString('Datos ya calculados:', $datos);
        $this->assertStringContainsString('clientes quedaron afuera porque tienen ventas sin cobrar', $datos);
        foreach (['Reglas', 'oraciones', 'markdown', 'No recalcules', 'Responde SOLO'] as $prohibida) {
            $this->assertStringNotContainsString($prohibida, $datos, 'armar_datos() no puede traer "' . $prohibida . '"');
            $this->assertStringContainsString($prohibida, $prompt, 'pero armar_prompt() sí');
        }
    }
}
