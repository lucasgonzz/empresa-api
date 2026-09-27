<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Los endpoints de Alertas → Imágenes (contrato §5.3 del plan) y el de siempre del listado.
 *
 * Lo que protege: el listado con sus conteos y las búsquedas por artículo; las solapas paginadas en
 * el servidor (25 por defecto); aprobar (crea la fila de images y renombra el archivo), rechazar
 * (borra el archivo), en lote y quitar; que un comercio no pueda ver ni tocar las asignaciones de
 * otro (404); y que POST google/batch-assign-images siga respondiendo igual con el uuid de la
 * asignación.
 */
class Endpoints_de_asignaciones_Test extends ImagenesInteligentesTestCase
{
    /**
     * Una asignación armada a mano, con un item por estado pedido.
     *
     * @param  array $estados  Lista de estados de item.
     * @param  array $datos    Columnas de la asignación.
     * @param  \App\Models\User|null $owner
     * @return \App\Models\ImageAssignmentRun
     */
    protected function asignacion_armada(array $estados, array $datos = [], $owner = null)
    {
        $owner = is_null($owner) ? $this->owner : $owner;

        $run = ImageAssignmentRun::create(array_merge([
            'user_id'            => $owner->id,
            'auth_user_id'       => $owner->id,
            'uuid'               => (string) Str::uuid(),
            'origen'             => ImageAssignmentRun::ORIGEN_SELECCION,
            'proveedor'          => ImageAssignmentRun::PROVEEDOR_SERPER,
            'status'             => ImageAssignmentRun::STATUS_TERMINADA,
            'total_articulos'    => count($estados),
            'aplica_tope_diario' => true,
            'finished_at'        => Carbon::now(),
        ], $datos));

        foreach (array_values($estados) as $orden => $estado) {
            $articulo = Article::create([
                'name'     => 'Artículo '.$estado.' '.$orden.' '.uniqid(),
                'bar_code' => '77912345678'.str_pad((string) $orden, 2, '0', STR_PAD_LEFT),
                'user_id'  => $owner->id,
                'status'   => 'active',
            ]);

            ImageAssignmentItem::create([
                'run_id'           => $run->id,
                'user_id'          => $owner->id,
                'article_id'       => $articulo->id,
                'article_name'     => $articulo->name,
                'article_bar_code' => $articulo->bar_code,
                'orden'            => $orden + 1,
                'status'           => $estado,
            ]);
        }

        return $run;
    }

    /**
     * Pone un item "a revisar" con su archivo candidato en el disco.
     *
     * @param  \App\Models\ImageAssignmentItem $item
     * @return string  El nombre del archivo.
     */
    protected function con_candidata(ImageAssignmentItem $item)
    {
        $archivo = 'imgcand_'.(string) Str::uuid().'.webp';

        Storage::disk('public')->put($archivo, $this->png(600, 600, 'rojo'));

        $item->update([
            'status'         => ImageAssignmentItem::STATUS_A_REVISAR,
            'motivo'         => 'confianza_media',
            'imagen_archivo' => $archivo,
            'imagen_url'     => 'http://empresa.local/storage/'.$archivo,
            'imagen_meta'    => ['ancho' => 600, 'alto' => 600, 'fondo_blanco' => true, 'fondo_blanco_ratio' => 0.97, 'dominio' => 'tienda.test', 'pagina' => 'https://tienda.test/p', 'ia' => ['veredicto' => 'si', 'confianza' => 'medium', 'problemas' => [], 'motivo' => 'Parece.'], 'avisos' => ['La IA lo reconoce con confianza media']],
        ]);

        return $archivo;
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_listado_trae_las_asignaciones_con_sus_conteos_y_las_busquedas_por_articulo()
    {
        $vieja = $this->asignacion_armada(
            ['asignada', 'asignada', 'aprobada', 'a_revisar', 'no_asignada', 'rechazada', 'sin_procesar', 'pendiente', 'procesando'],
            [
                'status'           => ImageAssignmentRun::STATUS_EN_PROCESO,
                'procesados'       => 7,
                'busquedas'        => 10,
                'busquedas_codigo' => 6,
                'busquedas_nombre' => 4,
                'validaciones_ia'  => 5,
                'finished_at'      => null,
                'created_at'       => Carbon::now()->subHour(),
            ]
        );

        $nueva = $this->asignacion_armada(['pendiente'], ['status' => ImageAssignmentRun::STATUS_PENDIENTE, 'finished_at' => null]);

        $respuesta = $this->getJson('api/image-assignment-runs');

        $respuesta->assertStatus(200);
        $this->assertSame(25, (int) $respuesta->json('models.per_page'));
        $this->assertSame(2, (int) $respuesta->json('models.total'));

        // Más nuevas primero.
        $this->assertSame((int) $nueva->id, (int) $respuesta->json('models.data.0.id'));
        $this->assertNull($respuesta->json('models.data.0.busquedas_por_articulo'), 'Sin procesados no hay promedio.');

        $fila = $respuesta->json('models.data.1');
        $this->assertSame((int) $vieja->id, (int) $fila['id']);
        $this->assertSame($vieja->uuid, $fila['uuid']);
        $this->assertSame(['asignadas' => 3, 'a_revisar' => 1, 'no_asignadas' => 3, 'pendientes' => 2], $fila['conteos']);
        $this->assertSame(10, $fila['busquedas']);
        $this->assertEquals(1.43, $fila['busquedas_por_articulo']);
        $this->assertSame(['codigo_de_barras' => 6, 'nombre' => 4], $fila['busquedas_por_criterio']);
        $this->assertSame(5, $fila['validaciones_ia']);
        $this->assertSame('seleccion', $fila['origen']);
        $this->assertSame('serper', $fila['proveedor']);
        $this->assertSame($this->owner->name, $fila['lanzada_por']);
        $this->assertFalse($fila['es_de_catalogo']);
        $this->assertFalse($fila['trabada']);

        $this->assertSame(['a_revisar' => 1, 'sin_ver' => 0, 'en_proceso' => 2], $respuesta->json('resumen'));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function el_resumen_cuenta_las_sin_ver_y_abrirla_la_marca_vista()
    {
        $terminada = $this->asignacion_armada(['asignada', 'a_revisar', 'a_revisar']);

        $this->getJson('api/image-assignment-runs/resumen')
            ->assertStatus(200)
            ->assertExactJson(['a_revisar' => 2, 'sin_ver' => 1, 'en_proceso' => 0]);

        $this->getJson('api/image-assignment-runs/'.$terminada->id)
            ->assertStatus(200)
            ->assertJsonPath('model.id', (int) $terminada->id)
            ->assertJsonPath('model.visto', true);

        $this->getJson('api/image-assignment-runs/resumen')->assertExactJson(['a_revisar' => 2, 'sin_ver' => 0, 'en_proceso' => 0]);

        // Por uuid (el aviso de fin de corrida) también la encuentra.
        $this->getJson('api/image-assignment-runs/por-uuid/'.$terminada->uuid)
            ->assertStatus(200)
            ->assertJsonPath('model.uuid', $terminada->uuid);

        // Una en curso no se marca vista al abrirla.
        $en_curso = $this->asignacion_armada(['pendiente'], ['status' => ImageAssignmentRun::STATUS_EN_PROCESO, 'finished_at' => null]);
        $this->getJson('api/image-assignment-runs/'.$en_curso->id)->assertJsonPath('model.visto', false);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function los_items_se_paginan_por_solapa_de_a_25_por_defecto()
    {
        $estados = array_merge(array_fill(0, 30, 'no_asignada'), ['rechazada', 'sin_procesar', 'a_revisar', 'asignada']);
        $run     = $this->asignacion_armada($estados);

        $respuesta = $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=no_asignadas');

        $respuesta->assertStatus(200);
        $this->assertSame(25, (int) $respuesta->json('models.per_page'));
        $this->assertSame(32, (int) $respuesta->json('models.total'), 'No asignadas = no_asignada + rechazada + quitada + sin_procesar.');
        $this->assertCount(25, $respuesta->json('models.data'));
        $this->assertSame(['asignadas' => 1, 'a_revisar' => 1, 'no_asignadas' => 32, 'pendientes' => 0], $respuesta->json('conteos'));

        // El item trae la forma del contrato.
        $item = $respuesta->json('models.data.0');
        foreach (['id', 'article_id', 'article_name', 'article_bar_code', 'status', 'motivo', 'motivo_detalle', 'criterio_usado', 'busquedas', 'validaciones_ia', 'imagen_url', 'imagen', 'avisos', 'diagnostico', 'revisado_por', 'revisado_at', 'procesado_at'] as $clave) {
            $this->assertArrayHasKey($clave, $item);
        }

        // Página 2.
        $this->assertCount(7, $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=no_asignadas&page=2')->json('models.data'));

        // per_page acotado a 10..100.
        $this->assertSame(10, (int) $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=no_asignadas&per_page=3')->json('models.per_page'));
        $this->assertSame(100, (int) $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=no_asignadas&per_page=500')->json('models.per_page'));

        // Las otras solapas.
        $this->assertSame(1, (int) $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=a_revisar')->json('models.total'));
        $this->assertSame(1, (int) $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=asignadas')->json('models.total'));

        // Buscador por nombre o código.
        $uno = ImageAssignmentItem::where('run_id', $run->id)->where('status', 'asignada')->first();
        $buscado = $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=asignadas&buscar='.urlencode($uno->article_name));
        $this->assertSame(1, (int) $buscado->json('models.total'));
        $this->assertSame(0, (int) $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=asignadas&buscar=no-existe-zzz')->json('models.total'));

        // Una solapa que no existe.
        $this->getJson('api/image-assignment-runs/'.$run->id.'/items?solapa=cualquiera')->assertStatus(422);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_crea_la_fila_de_images_renombra_el_archivo_y_no_se_aprueba_dos_veces()
    {
        $run  = $this->asignacion_armada(['a_revisar']);
        $item = ImageAssignmentItem::where('run_id', $run->id)->first();

        $candidata = $this->con_candidata($item);

        $respuesta = $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar');

        $respuesta->assertStatus(200);
        $respuesta->assertJsonPath('model.status', 'aprobada');
        $respuesta->assertJsonPath('model.revisado_por', $this->owner->name);

        // Ya es una imagen asignada: sus avisos son los de una asignada (contrato §5.2, solo
        // "Fondo no blanco"); el "confianza media" por el que fue a revisar no la sigue.
        $respuesta->assertJsonPath('model.avisos', []);

        $item->refresh();
        $this->assertSame(ImageAssignmentItem::STATUS_APROBADA, $item->status);
        $this->assertMatchesRegularExpression('/^\d+\.webp$/', $item->imagen_archivo, 'Deja el prefijo imgcand_: ya es una imagen real.');
        $this->assertFalse(Storage::disk('public')->exists($candidata));
        $this->assertTrue(Storage::disk('public')->exists($item->imagen_archivo));

        $imagen = Image::find($item->image_id);
        $this->assertNotNull($imagen);
        $this->assertSame('article', $imagen->imageable_type);
        $this->assertSame((int) $item->article_id, (int) $imagen->imageable_id);
        $this->assertStringEndsWith('/storage/'.$item->imagen_archivo, $imagen->hosting_url);
        $this->assertTrue((bool) Article::find($item->article_id)->needs_sync_with_tn);

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')->assertStatus(422);
        $this->assertSame(1, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_la_imagen_de_un_articulo_borrado_da_422_y_lo_pasa_a_no_asignada()
    {
        $run  = $this->asignacion_armada(['a_revisar']);
        $item = ImageAssignmentItem::where('run_id', $run->id)->first();

        $candidata = $this->con_candidata($item);

        Article::find($item->article_id)->delete();

        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $item->refresh();
        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, $item->status);
        $this->assertSame('articulo_borrado', $item->motivo);
        $this->assertFalse(Storage::disk('public')->exists($candidata));
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function rechazar_borra_el_archivo_y_la_manda_a_no_asignadas()
    {
        $run  = $this->asignacion_armada(['a_revisar', 'asignada']);
        $item = ImageAssignmentItem::where('run_id', $run->id)->where('status', 'a_revisar')->first();

        $candidata = $this->con_candidata($item);

        $this->postJson('api/image-assignment-items/'.$item->id.'/rechazar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'rechazada')
            ->assertJsonPath('model.motivo', 'rechazada');

        $this->assertFalse(Storage::disk('public')->exists($candidata));
        $this->assertNull($item->fresh()->imagen_url);
        $this->assertNull($item->fresh()->imagen_meta, 'Sin imagen no quedan datos de imagen (ni avisos) a la vista.');
        $this->assertSame(0, Image::where('imageable_type', 'article')->where('imageable_id', $item->article_id)->count());

        // Una asignada no se "rechaza".
        $asignada = ImageAssignmentItem::where('run_id', $run->id)->where('status', 'asignada')->first();
        $this->postJson('api/image-assignment-items/'.$asignada->id.'/rechazar')->assertStatus(422);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function aprobar_y_rechazar_varios_informan_cuantas_salieron_y_por_que_fallaron_las_otras()
    {
        $run   = $this->asignacion_armada(['a_revisar', 'a_revisar', 'asignada', 'a_revisar', 'a_revisar']);
        $items = ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->get();

        foreach ($items as $item) {
            if ($item->status === 'a_revisar') {
                $this->con_candidata($item);
            }
        }

        // La segunda fue a revisar con el fondo no blanco: aprobada, ese aviso es el que le queda.
        $meta = $items[1]->fresh()->imagen_meta;
        $meta['fondo_blanco'] = false;
        $meta['avisos']       = ['Fondo no blanco', 'La IA lo reconoce con confianza media'];
        $items[1]->update(['imagen_meta' => $meta]);

        $aprobar = $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => [$items[0]->id, $items[1]->id, $items[2]->id]]);

        $aprobar->assertStatus(200);
        $this->assertSame(2, $aprobar->json('aprobados'));
        $this->assertCount(1, $aprobar->json('fallidos'));
        $this->assertSame((int) $items[2]->id, (int) $aprobar->json('fallidos.0.id'));
        $this->assertNotEmpty($aprobar->json('fallidos.0.message'));

        $this->assertSame([], $items[0]->fresh()->imagen_meta['avisos']);
        $this->assertSame(['Fondo no blanco'], $items[1]->fresh()->imagen_meta['avisos']);

        $rechazar = $this->postJson('api/image-assignment-items/rechazar-varios', ['ids' => [$items[3]->id, $items[4]->id]]);

        $rechazar->assertStatus(200);
        $this->assertSame(2, $rechazar->json('rechazados'));
        $this->assertSame([], $rechazar->json('fallidos'));

        $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => []])->assertStatus(422);
    }

    /**
     * @group imagenes-inteligentes
     * @test
     */
    public function quitar_borra_la_imagen_asignada_y_la_deja_quitada()
    {
        $run  = $this->asignacion_armada(['asignada', 'a_revisar']);
        $item = ImageAssignmentItem::where('run_id', $run->id)->where('status', 'asignada')->first();

        $archivo = time().rand(1, 100000).'.webp';
        Storage::disk('public')->put($archivo, $this->png(600, 600, 'azul'));

        $imagen = Image::create([
            'hosting_url'    => 'http://empresa.local/storage/'.$archivo,
            'imageable_id'   => $item->article_id,
            'imageable_type' => 'article',
        ]);

        $item->update(['image_id' => $imagen->id, 'imagen_archivo' => $archivo, 'imagen_url' => $imagen->hosting_url]);

        $this->postJson('api/image-assignment-items/'.$item->id.'/quitar')
            ->assertStatus(200)
            ->assertJsonPath('model.status', 'quitada')
            ->assertJsonPath('model.motivo', 'quitada');

        $this->assertNull(Image::find($imagen->id), 'La fila de images se borró (ImageController::deleteImageModel).');
        $this->assertFalse(Storage::disk('public')->exists($archivo));
        $this->assertNull($item->fresh()->imagen_meta);

        // Solo se quita lo asignado.
        $a_revisar = ImageAssignmentItem::where('run_id', $run->id)->where('status', 'a_revisar')->first();
        $this->postJson('api/image-assignment-items/'.$a_revisar->id.'/quitar')->assertStatus(422);
    }

    /**
     * Un comercio no ve ni toca las asignaciones de otro: todo da 404.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function las_asignaciones_de_otro_comercio_no_existen()
    {
        $otro = User::create([
            'name'     => 'Otro comercio',
            'email'    => 'otro-comercio-'.uniqid().'@test.local',
            'password' => Hash::make('secret'),
        ]);

        $ajena = $this->asignacion_armada(['a_revisar', 'asignada'], [], $otro);
        $item  = ImageAssignmentItem::where('run_id', $ajena->id)->where('status', 'a_revisar')->first();
        $this->con_candidata($item);

        $this->getJson('api/image-assignment-runs/'.$ajena->id)->assertStatus(404);
        $this->getJson('api/image-assignment-runs/por-uuid/'.$ajena->uuid)->assertStatus(404);
        $this->getJson('api/image-assignment-runs/'.$ajena->id.'/items?solapa=a_revisar')->assertStatus(404);
        $this->postJson('api/image-assignment-items/'.$item->id.'/aprobar')->assertStatus(404);
        $this->postJson('api/image-assignment-items/'.$item->id.'/rechazar')->assertStatus(404);
        $this->postJson('api/image-assignment-items/'.$item->id.'/quitar')->assertStatus(404);

        $lote = $this->postJson('api/image-assignment-items/aprobar-varios', ['ids' => [$item->id]]);
        $this->assertSame(0, $lote->json('aprobados'));

        $this->assertSame(0, (int) $this->getJson('api/image-assignment-runs')->json('models.total'));
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->fresh()->status, 'Nada de lo de arriba tocó el item ajeno.');
    }

    /**
     * 🔴 El botón del listado sigue igual por fuera ({status, batch_uuid}) y por dentro crea la
     * asignación con ESE uuid, los artículos en el orden pedido y el job nuevo encolado.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_boton_del_listado_crea_una_asignacion_de_seleccion_con_el_uuid_que_devuelve()
    {
        $tercero = $this->nuevo_articulo('Tercero');
        $primero = $this->nuevo_articulo('Primero');
        $segundo = $this->nuevo_articulo('Segundo');

        Queue::fake();

        $ids = [(int) $primero->id, (int) $segundo->id, (int) $tercero->id];

        $respuesta = $this->postJson('api/google/batch-assign-images', ['article_ids' => $ids]);

        $respuesta->assertStatus(200);
        $this->assertSame(['status', 'batch_uuid'], array_keys($respuesta->json()));
        $this->assertSame('processing', $respuesta->json('status'));

        $run = ImageAssignmentRun::where('uuid', $respuesta->json('batch_uuid'))->first();
        $this->assertNotNull($run, 'El uuid de la respuesta es el de la asignación.');
        $this->assertSame((int) $this->owner->id, (int) $run->user_id);
        $this->assertSame('seleccion', $run->origen);
        $this->assertTrue((bool) $run->aplica_tope_diario, 'La selección del dueño sigue con su tope diario.');
        $this->assertSame(3, (int) $run->total_articulos);
        $this->assertSame($ids, ImageAssignmentItem::where('run_id', $run->id)->orderBy('orden')->pluck('article_id')->map(function ($id) {
            return (int) $id;
        })->all());

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, function ($job) use ($run) {
            $propiedad = new \ReflectionProperty($job, 'run_id');
            $propiedad->setAccessible(true);

            return (int) $propiedad->getValue($job) === (int) $run->id;
        });

        $proceso = BackgroundProcess::find($run->background_process_id);
        $this->assertSame('pendiente', $proceso->status);
        $this->assertSame(ImageAssignmentRun::class, $proceso->referencia_type);
    }
}
