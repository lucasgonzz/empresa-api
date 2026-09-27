<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\BackgroundProcess;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * "Buscar imágenes para todo el catálogo" (plan §3 y §5.4): solo el acceso maestro, con la previa
 * que ve Lucas antes de lanzar, y la selección que se hace (activos, sin imagen, sin pendientes de
 * revisión, sin "ya buscado sin éxito" de los últimos 90 días, primero los publicados en la tienda,
 * después los que tienen stock, hasta el tope).
 */
class Catalogo_completo_Test extends ImagenesInteligentesTestCase
{
    /** @var array Los artículos del escenario, por letra. */
    protected $art = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
    }

    /**
     * El escenario de la selección (se crean en orden alfabético: los ids suben con la letra, así
     * el orden de prioridad se distingue del orden de alta):
     *
     *   A  publicado, stock 5            → candidato (1º)
     *   B  NO publicado, stock 10        → candidato (último: no está en la tienda)
     *   C  publicado, sin stock          → candidato
     *   D  con imagen                    → no cuenta (ya tiene)
     *   E  con una imagen a revisar      → excluido (pendiente de revisión)
     *   F  buscado sin éxito hace 10 días → excluido (ya buscado)
     *   G  buscado sin éxito hace 100 días → candidato (pasaron los 90)
     *   H  inactivo                      → no cuenta
     *   I  error de búsqueda reciente    → candidato (un error no dice nada del producto)
     *   J  publicado, stock 3            → candidato (2º: publicado con stock)
     *
     * @return void
     */
    protected function escenario()
    {
        $datos = [
            'A' => ['online' => 1, 'stock' => 5],
            'B' => ['online' => 0, 'stock' => 10],
            'C' => ['online' => 1, 'stock' => 0],
            'D' => ['online' => 1, 'stock' => 1],
            'E' => ['online' => 1, 'stock' => 1],
            'F' => ['online' => 1, 'stock' => 1],
            'G' => ['online' => 1, 'stock' => null],
            'H' => ['online' => 1, 'stock' => 1, 'status' => 'inactive'],
            'I' => ['online' => 1, 'stock' => 0],
            'J' => ['online' => 1, 'stock' => 3],
        ];

        foreach ($datos as $letra => $extra) {
            $this->art[$letra] = $this->nuevo_articulo('Catálogo '.$letra, null, $extra);
        }

        Image::create(['hosting_url' => 'https://ejemplo.test/d.webp', 'imageable_id' => $this->art['D']->id, 'imageable_type' => 'article']);

        $anterior = ImageAssignmentRun::create([
            'user_id'         => $this->owner->id,
            'uuid'            => (string) Str::uuid(),
            'origen'          => ImageAssignmentRun::ORIGEN_SELECCION,
            'proveedor'       => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'          => ImageAssignmentRun::STATUS_TERMINADA,
            'total_articulos' => 4,
            'finished_at'     => Carbon::now()->subDays(5),
        ]);

        $items = [
            'E' => ['status' => 'a_revisar', 'motivo' => 'confianza_media', 'procesado_at' => Carbon::now()->subDays(5)],
            'F' => ['status' => 'no_asignada', 'motivo' => 'no_corresponden', 'procesado_at' => Carbon::now()->subDays(10)],
            'G' => ['status' => 'no_asignada', 'motivo' => 'no_corresponden', 'procesado_at' => Carbon::now()->subDays(100)],
            'I' => ['status' => 'no_asignada', 'motivo' => 'error_de_busqueda', 'procesado_at' => Carbon::now()->subDays(2)],
        ];

        $orden = 0;

        foreach ($items as $letra => $item) {
            $orden++;

            ImageAssignmentItem::create(array_merge([
                'run_id'     => $anterior->id,
                'user_id'    => $this->owner->id,
                'article_id' => $this->art[$letra]->id,
                'orden'      => $orden,
            ], $item));
        }
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_la_sesion_del_acceso_maestro_da_403()
    {
        $run = ImageAssignmentRun::create([
            'user_id'   => $this->owner->id,
            'uuid'      => (string) Str::uuid(),
            'origen'    => ImageAssignmentRun::ORIGEN_CATALOGO,
            'proveedor' => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'    => ImageAssignmentRun::STATUS_EN_PROCESO,
        ]);

        Queue::fake();

        $this->getJson('api/image-assignment-runs/catalogo/previa')->assertStatus(403)->assertJsonStructure(['message']);
        $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(403)->assertJsonStructure(['message']);
        $this->postJson('api/image-assignment-runs/'.$run->id.'/detener')->assertStatus(403);
        $this->postJson('api/image-assignment-runs/'.$run->id.'/reanudar')->assertStatus(403);

        $this->assertSame(1, ImageAssignmentRun::where('user_id', $this->owner->id)->count(), 'No se creó nada.');
        $this->assertSame(ImageAssignmentRun::STATUS_EN_PROCESO, $run->fresh()->status, 'No se detuvo.');
        Queue::assertNothingPushed();
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function la_previa_cuenta_los_sin_imagen_los_excluidos_el_tope_y_la_estimacion()
    {
        $this->escenario();

        config(['services.imagenes_inteligentes.tope_catalogo' => 4]);

        $this->actuar_como($this->owner, true);

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa');

        $previa->assertStatus(200);
        $previa->assertExactJson([
            'sin_imagen'                       => 8,
            'excluidos_pendientes_de_revision' => 1,
            'excluidos_ya_buscados'            => 1,
            'a_buscar'                         => 4,
            'tope'                             => 4,
            'quedan_para_otra_corrida'         => 2,
            'proveedor_configurado'            => true,
            'proveedor'                        => 'serper',
            'estimacion'                       => [
                'busquedas'     => 6,
                'minutos'       => 1,
                'usd_busquedas' => 0.01,
                'usd_ia'        => 0.02,
            ],
            'corrida_activa'                   => null,
        ]);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function lanzarla_crea_los_items_en_orden_de_prioridad_hasta_el_tope()
    {
        $this->escenario();

        config(['services.imagenes_inteligentes.tope_catalogo' => 4]);

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $respuesta = $this->postJson('api/image-assignment-runs/catalogo');

        $respuesta->assertStatus(201);
        $respuesta->assertJsonPath('model.origen', 'catalogo');
        $respuesta->assertJsonPath('model.es_de_catalogo', true);
        $respuesta->assertJsonPath('model.proveedor', 'serper');
        $respuesta->assertJsonPath('model.total_articulos', 4);
        $respuesta->assertJsonPath('model.lanzada_por', 'ComercioCity');
        $respuesta->assertJsonPath('model.status', 'pendiente');

        $run = ImageAssignmentRun::find($respuesta->json('model.id'));
        $this->assertFalse((bool) $run->aplica_tope_diario, 'El catálogo no consume el cupo diario del dueño.');

        // Publicados con stock (A, J), publicados sin stock (C, G) por id; B (no publicado) y I quedan
        // para la próxima corrida.
        $esperados = [$this->art['A']->id, $this->art['J']->id, $this->art['C']->id, $this->art['G']->id];
        $this->assertSame(array_map('intval', $esperados), ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->pluck('article_id')->map(function ($id) {
            return (int) $id;
        })->all());

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        $proceso = BackgroundProcess::find($run->background_process_id);
        $this->assertSame('pendiente', $proceso->status);
        $this->assertSame('Imágenes de todo el catálogo', $proceso->titulo);
        $this->assertSame(4, (int) $proceso->total);

        // Con otra de catálogo en curso no se lanza una segunda, y la previa la muestra.
        $this->postJson('api/image-assignment-runs/catalogo')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Ya hay una búsqueda de todo el catálogo en curso: esperá a que termine o detenela.']);

        $this->assertSame((int) $run->id, (int) $this->getJson('api/image-assignment-runs/catalogo/previa')->json('corrida_activa.id'));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_la_clave_de_serper_no_se_lanza()
    {
        $this->escenario();

        config(['services.serper.api_key' => '']);

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $this->postJson('api/image-assignment-runs/catalogo')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->assertStringContainsString('SERPER_API_KEY', $this->postJson('api/image-assignment-runs/catalogo')->json('message'));

        $previa = $this->getJson('api/image-assignment-runs/catalogo/previa');
        $this->assertFalse($previa->json('proveedor_configurado'));
        $this->assertNull($previa->json('proveedor'));

        Queue::assertNothingPushed();
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function sin_articulos_para_buscar_da_422()
    {
        $articulo = $this->nuevo_articulo('Ya tiene imagen');
        Image::create(['hosting_url' => 'https://ejemplo.test/x.webp', 'imageable_id' => $articulo->id, 'imageable_type' => 'article']);

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $this->postJson('api/image-assignment-runs/catalogo')->assertStatus(422)->assertJsonStructure(['message']);

        Queue::assertNothingPushed();
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_acceso_maestro_puede_detenerla_y_reanudarla()
    {
        $this->escenario();

        $this->actuar_como($this->owner, true);

        Queue::fake();

        $run = ImageAssignmentRun::find($this->postJson('api/image-assignment-runs/catalogo')->json('model.id'));
        $proceso_original = (int) $run->background_process_id;

        $this->postJson('api/image-assignment-runs/'.$run->id.'/detener')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'detenida');

        Event::assertDispatched(ArticleBatchImagesProcessed::class, function ($evento) use ($run) {
            return $evento->batch_uuid === $run->uuid;
        });
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, BackgroundProcess::find($proceso_original)->status);

        // Una detenida no se vuelve a detener.
        $this->postJson('api/image-assignment-runs/'.$run->id.'/detener')->assertStatus(422);

        Queue::fake();

        $this->postJson('api/image-assignment-runs/'.$run->id.'/reanudar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'pendiente');

        $run->refresh();
        $this->assertNull($run->finished_at);
        $this->assertNotSame($proceso_original, (int) $run->background_process_id, 'El registro visible cerrado no se reabre: se abre otro.');
        $this->assertSame('pendiente', BackgroundProcess::find($run->background_process_id)->status);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        // Una en curso y sana no se "reanuda".
        $run->update(['status' => ImageAssignmentRun::STATUS_EN_PROCESO, 'last_progress_at' => Carbon::now()]);
        $this->postJson('api/image-assignment-runs/'.$run->id.'/reanudar')->assertStatus(422);

        // Una trabada (sin avanzar hace más de 15 minutos) sí, y lo que quedó a medias hace rato (de
        // un worker muerto) vuelve a pendiente. Lo que se reclamó recién lo está terminando un
        // tramo vivo: se deja (si no, otro tramo lo procesaría de nuevo en paralelo).
        $run->update(['last_progress_at' => Carbon::now()->subMinutes(20)]);
        $items    = ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->get();
        $a_medias = $items[0];
        $reciente = $items[1];

        DB::table('image_assignment_items')->where('id', $a_medias->id)->update([
            'status'     => ImageAssignmentItem::STATUS_PROCESANDO,
            'intentos'   => 1,
            'tramo'      => 'ficha-de-un-tramo-muerto',
            'updated_at' => Carbon::now()->subMinutes(20),
        ]);

        DB::table('image_assignment_items')->where('id', $reciente->id)->update([
            'status'     => ImageAssignmentItem::STATUS_PROCESANDO,
            'intentos'   => 1,
            'tramo'      => 'ficha-de-un-tramo-vivo',
            'updated_at' => Carbon::now()->subMinute(),
        ]);

        $this->assertTrue($this->getJson('api/image-assignment-runs/'.$run->id)->json('model.trabada'));

        Queue::fake();

        $this->postJson('api/image-assignment-runs/'.$run->id.'/reanudar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'en_proceso')
            ->assertJsonPath('model.trabada', false);

        $this->assertSame(ImageAssignmentItem::STATUS_PENDIENTE, $a_medias->fresh()->status);
        $this->assertSame(ImageAssignmentItem::STATUS_PROCESANDO, $reciente->fresh()->status, 'El que procesa un tramo vivo no se repone.');
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);
    }
}
