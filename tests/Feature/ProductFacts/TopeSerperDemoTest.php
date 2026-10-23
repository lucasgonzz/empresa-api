<?php

namespace Tests\Feature\ProductFacts;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Jobs\ProcessProductEnrichmentJob;
use App\Models\BackgroundProcess;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\ProductEnrichment;
use App\Models\SerperUsageEvent;
use App\Services\ImageAssignment\ArticleImageAssignmentEngine;
use App\Services\ImageSearch\ImageSearchProvider;
use App\Services\ImageSearch\SerperImageSearchProvider;
use App\Services\ProductFacts\Gtin;
use App\Services\ProductFacts\ProductEnrichmentService;
use App\Services\ProductFacts\ProductFactsResearchService;
use App\Services\ProductFacts\TopeSerperDemo;
use App\Services\ProductFacts\TopeSerperDemoAgotado;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\ImagenesInteligentes\ImagenesInteligentesTestCase;

/**
 * Tope de 25 búsquedas de Serper por dueño en las demos (misión tope-serper-demos, 23/10/2026).
 *
 * Demo = `config('app.FOR_USER') === 'demo'` (estricto). El tope va por dueño
 * (`serper_usage_events.user_id`) y solo cuentan los eventos posteriores al alta del dueño. Fuera de
 * una demo nada cambia. Extiende la base de Imágenes inteligentes por su único Http::fake (Serper,
 * imágenes generadas e IA falsos): 🔴 ningún test sale a la red.
 */
class TopeSerperDemoTest extends ImagenesInteligentesTestCase
{
    const CODIGOS = ['7791234567898', '7790001000019', '7790002000018'];

    /** @var mixed Lo que había en config antes del test (se restaura en tearDown). */
    protected $for_user_anterior;
    protected $user_id_anterior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->for_user_anterior = config('app.FOR_USER');
        $this->user_id_anterior  = config('app.USER_ID');

        config(['app.FOR_USER' => 'demo', 'app.USER_ID' => $this->owner->id]);

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        config(['app.FOR_USER' => $this->for_user_anterior, 'app.USER_ID' => $this->user_id_anterior]);

        parent::tearDown();
    }

    /**
     * Eventos de Serper ya registrados para el dueño (las búsquedas que "ya hizo").
     *
     * @param  int              $cantidad
     * @param  \Carbon\Carbon|null $cuando
     * @return void
     */
    protected function eventos($cantidad, $cuando = null)
    {
        $cuando = $cuando ?: Carbon::now();
        $filas  = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = [
                'event_uuid'  => (string) Str::uuid(),
                'user_id'     => $this->owner->id,
                'process'     => 'imagenes_seleccion',
                'endpoint'    => 'images',
                'query'       => 'búsqueda previa '.$i,
                'ok'          => true,
                'occurred_at' => $cuando,
                'created_at'  => $cuando,
                'updated_at'  => $cuando,
            ];
        }

        if (!empty($filas)) {
            DB::table('serper_usage_events')->insert($filas);
        }
    }

    /** @return \App\Services\ImageSearch\SerperImageSearchProvider */
    protected function proveedor_serper()
    {
        return (new SerperImageSearchProvider('SERPER-DE-PRUEBA'))->usageContext([
            'user_id' => $this->owner->id,
            'process' => 'imagenes_seleccion',
        ]);
    }

    /**
     * Artículos con código real que Serper y la IA falsos resuelven con una imagen cada uno.
     *
     * @param  int           $cantidad
     * @param  callable|null $al_buscar
     * @return array
     */
    protected function articulos_asignables($cantidad, $al_buscar = null)
    {
        $colores    = ['rojo', 'azul', 'verde'];
        $articulos  = [];
        $serper     = [];
        $imagenes   = [];
        $veredictos = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $url = $this->url_imagen('demo-'.$i);

            $articulos[] = $this->nuevo_articulo('Producto demo '.$i, self::CODIGOS[$i]);

            $serper[self::CODIGOS[$i]]      = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]                 = $this->png(900, 900, $colores[$i]);
            $veredictos[$colores[$i]]       = $this->veredicto('si', 'high');
        }

        $this->falsear($serper, $imagenes, $veredictos, $al_buscar);

        return $articulos;
    }

    /**
     * En una demo con 24 búsquedas usadas, la 25 sale a Serper y queda registrada.
     *
     * @test
     */
    public function en_una_demo_bajo_el_tope_llama_y_registra_la_numero_25()
    {
        $this->eventos(24);
        $this->falsear(['Producto' => [$this->resultado($this->url_imagen('x'), 900, 900, 1)]], [], []);

        $respuesta = $this->proveedor_serper()->buscar('Producto');

        $this->assertTrue($respuesta['ok']);
        $this->assertSame(['Producto'], $this->consultas_serper);
        $this->assertSame(25, TopeSerperDemo::usadas($this->owner));
        $this->assertSame(['aplica' => true, 'tope' => 25, 'usadas' => 25, 'restantes' => 0], TopeSerperDemo::estado($this->owner));
    }

    /**
     * Justo en 25: ni el buscador de imágenes, ni la ficha por barras, ni una corrida con Google
     * salen a la red. No se cae a Google como sustituto.
     *
     * @test
     */
    public function en_el_tope_no_llama_ni_a_serper_ni_a_google()
    {
        $this->eventos(25);
        $articulos = $this->articulos_asignables(1);

        $respuesta = $this->proveedor_serper()->buscar('Producto');

        $this->assertFalse($respuesta['ok']);
        $this->assertTrue($respuesta['tope_demo']);
        $this->assertSame(TopeSerperDemo::MENSAJE, $respuesta['error']);
        $this->assertSame('Llegaste al límite de búsquedas de la demo (25). Podés seguir cargando a mano.', TopeSerperDemo::MENSAJE);

        // La ficha por código de barras: el mismo tope, con el mensaje para la persona.
        $research = app()->make(ProductFactsResearchService::class, ['owner' => $this->owner, 'person' => $this->owner]);

        try {
            $research->research(Gtin::canonical(self::CODIGOS[0]));
            $this->fail('La ficha tenía que frenar por el tope de la demo.');
        } catch (TopeSerperDemoAgotado $e) {
            $this->assertSame(TopeSerperDemo::MENSAJE, $e->getMessage());
        }

        // Una asignación creada con Google (sin clave de Serper) tampoco busca.
        $run = $this->asignacion($articulos, ['proveedor' => ImageAssignmentRun::PROVEEDOR_GOOGLE]);
        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->fresh()->status);
        $this->assertSame([], $this->consultas_serper);
        $this->assertSame(0, $this->requests_a('serper.dev'));
        $this->assertSame(0, $this->requests_a('googleapis.com'));
        $this->assertSame(25, SerperUsageEvent::where('user_id', $this->owner->id)->count(), 'Nada nuevo registrado: no hubo llamadas.');
    }

    /**
     * Por encima del tope: 30 eventos de un setup viejo (anteriores al alta del dueño) no cuentan;
     * 30 actuales sí, y no se llama.
     *
     * @test
     */
    public function los_eventos_de_un_setup_viejo_no_cuentan_y_los_actuales_si()
    {
        DB::table('users')->where('id', $this->owner->id)->update(['created_at' => Carbon::now()->subMinutes(5)]);

        $this->eventos(30, Carbon::now()->subDay());
        $this->falsear([], [], []);

        $this->assertSame(0, TopeSerperDemo::usadas($this->owner));
        $this->assertTrue($this->proveedor_serper()->buscar('Producto')['ok']);
        $this->assertCount(1, $this->consultas_serper);

        $this->eventos(30);

        $this->assertTrue(TopeSerperDemo::agotado($this->owner));
        $this->assertSame(0, TopeSerperDemo::restantes($this->owner));

        $respuesta = $this->proveedor_serper()->buscar('Otro producto');

        $this->assertTrue($respuesta['tope_demo']);
        $this->assertCount(1, $this->consultas_serper, 'Con 31 actuales no sale a Serper.');
    }

    /**
     * Fuera de una demo (otro FOR_USER, o ninguno) no hay tope: con 100 eventos llama igual, y
     * cuota_de() no cambia.
     *
     * @test
     */
    public function fuera_de_una_demo_no_hay_tope()
    {
        $this->eventos(100);
        $this->falsear([], [], []);

        foreach (['fenix', null, 'demo2'] as $for_user) {
            config(['app.FOR_USER' => $for_user]);

            $this->assertTrue($this->proveedor_serper()->buscar('Producto '.$for_user)['ok']);
            $this->assertSame(['aplica' => false, 'tope' => null, 'usadas' => null, 'restantes' => null], TopeSerperDemo::estado($this->owner));
            $this->assertFalse(TopeSerperDemo::agotado($this->owner));

            $cuota = ImagenesAutomaticasHelper::cuota_de($this->owner);
            $this->assertSame(ImagenesAutomaticasHelper::CUOTA_POR_DEFECTO, $cuota['disponibles']);
            $this->assertArrayNotHasKey('tope_demo', $cuota, 'Fuera de una demo cuota_de() queda como antes.');
        }

        $this->assertCount(3, $this->consultas_serper);
    }

    /**
     * En una demo, cuota_de() nunca da más que las búsquedas que le quedan a la demo (el asistente y
     * las asignaciones por selección ya miran ese número).
     *
     * @test
     */
    public function en_una_demo_la_cuota_diaria_queda_acotada_por_el_tope()
    {
        $this->eventos(22);

        $cuota = ImagenesAutomaticasHelper::cuota_de($this->owner);

        $this->assertSame(3, $cuota['disponibles']);
        $this->assertSame(ImagenesAutomaticasHelper::CUOTA_POR_DEFECTO, $cuota['cuota']);
        $this->assertSame(['aplica' => true, 'tope' => 25, 'usadas' => 22, 'restantes' => 3], $cuota['tope_demo']);

        // Lo que va SOLO por Google (categorías, foto por código de barras) no se frena por Serper.
        $this->eventos(10);
        $solo_google = ImagenesAutomaticasHelper::cuota_de($this->owner, false);
        $this->assertSame(ImagenesAutomaticasHelper::CUOTA_POR_DEFECTO, $solo_google['disponibles']);
        $this->assertArrayNotHasKey('tope_demo', $solo_google);
        $this->assertSame(0, ImagenesAutomaticasHelper::cuota_de($this->owner)['disponibles']);
    }

    /**
     * La ficha por código de barras con la demo agotada: la tarea termina `failed` con el mensaje de
     * la demo, la reserva del catálogo compartido se libera y Serper no se llama.
     *
     * @test
     */
    public function la_ficha_agotada_termina_failed_con_el_mensaje_y_libera_la_reserva()
    {
        config([
            'services.admin_api.url'         => 'https://admin.test',
            'services.admin_api.inbound_key' => 'CLAVE-ADMIN-FALSA',
        ]);

        $this->eventos(25);

        Http::swap(new \Tests\Fakes\HttpFactorySinSalida(app('events')));
        Http::fake(function ($request) {
            if (strpos($request->url(), 'admin.test/api/inbound/product-facts/') !== false) {
                return Http::response($request->method() === 'POST' ? ['status' => 'claimed', 'lease_token' => 'reserva-falsa'] : ['status' => 'ok']);
            }

            throw new \RuntimeException('HTTP inesperado en el test: '.$request->url());
        });

        $task = ProductEnrichment::create([
            'uuid'         => (string) Str::uuid(),
            'user_id'      => $this->owner->id,
            'auth_user_id' => $this->owner->id,
            'bar_code'     => self::CODIGOS[0],
            'gtin'         => Gtin::canonical(self::CODIGOS[0]),
            'status'       => 'queued',
        ]);

        (new ProcessProductEnrichmentJob($task->id))->handle(app(ProductEnrichmentService::class));

        $task = $task->fresh();

        $this->assertSame('failed', $task->status);
        $this->assertSame(TopeSerperDemo::MENSAJE, $task->message);
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT' && $request['status'] === 'failed';
        });
        Http::assertNotSent(function ($request) {
            return strpos($request->url(), 'serper') !== false;
        });
        $this->assertSame(25, SerperUsageEvent::where('user_id', $this->owner->id)->count());
        // Se cortó antes de reservar el intento diario de la ficha: no se gastó ninguno.
        $this->assertSame(0, (int) $task->search_attempts);
        $this->assertNull($task->search_started_at);
    }

    /**
     * Una corrida de TODO EL CATÁLOGO (que no aplica tope diario) llega al tope: busca la 25, y lo
     * que falta queda `sin_procesar` / `sin_cupo` con el detalle de la demo; la corrida termina
     * diciéndolo.
     *
     * @test
     */
    public function una_corrida_de_catalogo_que_llega_al_tope_termina_con_el_motivo_de_la_demo()
    {
        $this->eventos(24);
        $articulos = $this->articulos_asignables(3);

        $run = $this->asignacion($articulos, ['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'aplica_tope_diario' => false]);

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertSame('Llegaste al límite de búsquedas de la demo (25): 2 artículos quedaron sin procesar. Podés cargar las fotos a mano.', $run->motivo_estado);
        $this->assertSame([self::CODIGOS[0]], $this->consultas_serper);
        $this->assertSame(25, TopeSerperDemo::usadas($this->owner));

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[0]->id)->value('status'));

        $sin_procesar = ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_SIN_PROCESAR)->get();
        $this->assertCount(2, $sin_procesar);

        foreach ($sin_procesar as $item) {
            $this->assertSame('sin_cupo', $item->motivo);
            $this->assertSame(TopeSerperDemo::DETALLE_SIN_BUSCAR, $item->motivo_detalle);
        }

        $this->assertSame(0, ImageAssignmentItem::where('run_id', $run->id)->whereIn('status', [ImageAssignmentItem::STATUS_PENDIENTE, ImageAssignmentItem::STATUS_PROCESANDO])->count());
        $this->assertSame('Llegaste al límite de búsquedas de la demo', BackgroundProcess::find($run->background_process_id)->etapa);

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid && $evento->quota_reached === true && $evento->skipped_by_quota === 2;
        });
    }

    /**
     * El tope se alcanza A MITAD de un artículo (la búsqueda por código gastó la última): el
     * artículo cierra `no_asignada` / `sin_cupo` con el detalle de la demo, sin quedar en proceso,
     * y la corrida corta.
     *
     * @test
     */
    public function el_tope_a_mitad_de_un_articulo_corta_la_corrida_sin_dejarlo_en_proceso()
    {
        $test = $this;

        $articulos = [
            $this->nuevo_articulo('Producto demo uno', self::CODIGOS[0]),
            $this->nuevo_articulo('Producto demo dos', self::CODIGOS[1]),
        ];

        // Por código no aparece nada (el motor querría seguir por nombre), y esa primera consulta se
        // registra como la 25: cuando le toca al nombre, no hay más.
        $this->falsear([], [], [], function ($consulta) use ($test) {
            if (count($test->consultas_serper) === 1) {
                $test->eventos(24);
            }
        });

        $run = $this->asignacion($articulos);

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();

        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertSame('Llegaste al límite de búsquedas de la demo (25): 1 artículo quedó sin procesar. Podés cargar las fotos a mano.', $run->motivo_estado);
        $this->assertSame([self::CODIGOS[0]], $this->consultas_serper, 'Por nombre ya no se buscó.');

        $primero = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[0]->id)->first();
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $primero->status);
        $this->assertSame('sin_cupo', $primero->motivo);
        $this->assertStringContainsString('límite de búsquedas de la demo (25)', $primero->motivo_detalle);

        $nombre = $this->diagnostico_de($primero, 'nombre');
        $this->assertNotNull($nombre);
        $this->assertSame(TopeSerperDemo::CRITERIO_NO_BUSCADO, $nombre['motivo_no_usado']);

        $segundo = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[1]->id)->first();
        $this->assertSame(ImageAssignmentItem::STATUS_SIN_PROCESAR, $segundo->status);
        $this->assertSame('sin_cupo', $segundo->motivo);
    }

    /**
     * La carrera: el motor no vio el tope, pero el proveedor sí (otro proceso gastó la última bajo el
     * candado). El fallo con `tope_demo` es cupo agotado, no un error del proveedor.
     *
     * @test
     */
    public function un_fallo_del_proveedor_con_tope_demo_es_cupo_agotado_y_no_error()
    {
        $articulos = [$this->nuevo_articulo('Producto carrera', self::CODIGOS[0])];
        $run       = $this->asignacion($articulos);

        $proveedor = new class implements ImageSearchProvider {
            public function nombre()
            {
                return 'serper';
            }

            public function buscar($consulta)
            {
                return [
                    'ok'          => false,
                    'error'       => TopeSerperDemo::MENSAJE,
                    'detalle'     => null,
                    'resultados'  => [],
                    'total'       => null,
                    'http_status' => null,
                    'duracion_ms' => null,
                    'tope_demo'   => true,
                ];
            }
        };

        $item = ImageAssignmentItem::where('run_id', $run->id)->firstOrFail();
        $item->status   = ImageAssignmentItem::STATUS_PROCESANDO;
        $item->intentos = 1;
        $item->save();

        $resultado = (new ArticleImageAssignmentEngine($run->fresh(), $this->owner, $proveedor))->procesar($item);

        $this->assertTrue($resultado['cupo_agotado']);
        $this->assertTrue($resultado['tope_demo']);
        $this->assertFalse($resultado['error_de_proveedor']);
        $this->assertSame(0, $resultado['busquedas_intentadas']);

        $item->refresh();
        $this->assertSame(ImageAssignmentItem::STATUS_SIN_PROCESAR, $item->status);
        $this->assertSame('sin_cupo', $item->motivo);
        $this->assertSame(TopeSerperDemo::DETALLE_SIN_BUSCAR, $item->motivo_detalle);
        $this->assertSame(0, (int) $run->fresh()->errores_proveedor_seguidos);
    }

    /**
     * GET image-assignment-runs/tope-demo en una demo y fuera de ella.
     *
     * @test
     */
    public function get_tope_demo_en_una_demo_y_fuera()
    {
        $this->eventos(10);

        $this->getJson('/api/image-assignment-runs/tope-demo')
            ->assertOk()
            ->assertExactJson(['aplica' => true, 'tope' => 25, 'usadas' => 10, 'restantes' => 15]);

        config(['app.FOR_USER' => 'fenix']);

        $this->getJson('/api/image-assignment-runs/tope-demo')
            ->assertOk()
            ->assertExactJson(['aplica' => false, 'tope' => null, 'usadas' => null, 'restantes' => null]);
    }

    /**
     * Con 0 restantes en una demo: crear por selección, crear el catálogo y reanudar dan 422 con el
     * mensaje de la demo, y no se encola nada. La previa del catálogo lo anticipa en `tope_demo`.
     *
     * @test
     */
    public function con_cero_restantes_crear_y_reanudar_dan_422()
    {
        $this->eventos(25);
        $articulo = $this->nuevo_articulo('Producto sin cupo', self::CODIGOS[0]);

        $this->postJson('/api/google/batch-assign-images', ['article_ids' => [$articulo->id]])
            ->assertStatus(422)
            ->assertExactJson(['message' => TopeSerperDemo::MENSAJE]);

        $this->assertSame(0, ImageAssignmentRun::where('user_id', $this->owner->id)->count());

        $this->actuar_como($this->owner, true);

        $this->getJson('/api/image-assignment-runs/catalogo/previa')
            ->assertOk()
            ->assertJsonPath('tope_demo.aplica', true)
            ->assertJsonPath('tope_demo.restantes', 0);

        $this->postJson('/api/image-assignment-runs/catalogo')
            ->assertStatus(422)
            ->assertExactJson(['message' => TopeSerperDemo::MENSAJE]);

        $run = ImageAssignmentRun::create([
            'user_id'         => $this->owner->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => ImageAssignmentRun::ORIGEN_SELECCION,
            'proveedor'       => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'          => ImageAssignmentRun::STATUS_FALLIDA,
            'total_articulos' => 1,
        ]);

        $this->postJson('/api/image-assignment-runs/'.$run->id.'/reanudar')
            ->assertStatus(422)
            ->assertExactJson(['message' => TopeSerperDemo::MENSAJE]);

        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->fresh()->status);
        Queue::assertNotPushed(ProcessImageAssignmentRunJob::class);
    }
}
