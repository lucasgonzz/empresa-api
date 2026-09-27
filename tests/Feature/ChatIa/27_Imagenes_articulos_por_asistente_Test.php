<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenesArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\GeocoderCounter;
use App\Models\Image;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use App\Services\ImageSearch\ImageSearchProviderFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Misión asistente-masivas-imagenes-y-remito — la búsqueda de imágenes para artículos por filtro que
 * manda el asistente (PropuestaImagenesArticulosIaHelper + ImagenesAutomaticasHelper, plan §4.3,
 * contrato §1.4).
 *
 * Lo que protege: que con el dueño en "resuelto" la propuesta se auto-confirme y encole la búsqueda
 * con los ids en orden de alta ascendente, limitados a N y sin los que ya tienen imagen; que en
 * "cauteloso" quede la tarjeta propuesta con los renglones del contrato; que el registro visible
 * `imagenes_automaticas` nazca `pendiente` al encolar; y que el endpoint del botón del listado
 * (GoogleController@batch_assign_images) siga devolviendo `{status, batch_uuid}` y ahora también deje
 * ese registro pendiente.
 *
 * 🔁 CAMBIO DE CONTRATO A PROPÓSITO (misión imagenes-catalogo-completo, 27/9/2026, plan §8): hasta
 * esa misión encolar() despachaba ProcessArticleBatchImagesJob con los ids adentro. Ahora crea una
 * ASIGNACIÓN (image_assignment_runs + un item por artículo, EN EL ORDEN pedido) con el uuid que
 * devuelve, y despacha ProcessImageAssignmentRunJob con el id de esa asignación. Las aserciones que
 * leían las properties del job viejo ahora leen la asignación (ids en orden, dueño, uuid, tope diario
 * del dueño) y el job nuevo encolado; las del registro visible, el límite, el orden, la tarjeta y la
 * cuota no cambiaron.
 *
 * 🔴 Sin red: la cola se falsea en todos los casos (el job real busca imágenes y valida con IA).
 */
class Imagenes_articulos_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var User */
    protected $comercio;

    /** @var Provider */
    protected $bulonera;

    /** @var array<int, Article> Los de la bulonera, del más viejo al más nuevo por fecha de alta. */
    protected $por_alta = [];

    /** @var Article El que ya tiene imagen (el segundo por alta). */
    protected $con_imagen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio imagenes P27',
            'company_name'     => 'Ferreteria P27',
            'email'            => 'imagenes-p27-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'resuelto',
        ]);

        $this->bulonera = Provider::create(['name' => 'Bulonera P27', 'user_id' => $this->comercio->id]);

        // Se crean del más nuevo al más viejo a propósito: el id no puede ser lo que ordena.
        foreach ([1, 2, 3, 4] as $dias_atras) {
            $this->por_alta[$dias_atras] = Article::create([
                'name'        => 'zz-p27 alta hace ' . $dias_atras,
                'user_id'     => $this->comercio->id,
                'provider_id' => $this->bulonera->id,
                'status'      => 'active',
                'created_at'  => Carbon::now()->subDays($dias_atras),
                'updated_at'  => Carbon::now()->subDays($dias_atras),
            ]);
        }

        // El segundo más viejo ya tiene imagen. 🔴 El morph map usa el alias 'article', no el FQCN.
        $this->con_imagen = $this->por_alta[3];
        Image::create(['imageable_id' => $this->con_imagen->id, 'imageable_type' => 'article', 'hosting_url' => 'https://ejemplo.test/p27.webp']);

        // Uno de otro proveedor, que ningún filtro por bulonera alcanza.
        Article::create(['name' => 'zz-p27 ajeno al filtro', 'user_id' => $this->comercio->id, 'status' => 'active']);
    }

    /**
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion()
    {
        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $this->comercio->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Buscale imágenes a los primeros 2 de la bulonera',
            'estado'             => 'listo',
        ]);

        $assistant = AiMessage::create([
            'ai_conversation_id'   => $conversation->id,
            'rol'                  => 'assistant',
            'estado'               => 'pendiente',
            'acciones_habilitadas' => true,
        ]);

        return [$conversation, $assistant];
    }

    /**
     * Lee una property protegida del job encolado (mismo criterio que ImagenesGoogle/4: hacerla
     * pública solo para el test le ampliaría la superficie a una clase de producción).
     *
     * Sin tipo en el parámetro desde la misión imagenes-catalogo-completo: el job encolado ahora es
     * ProcessImageAssignmentRunJob (antes ProcessArticleBatchImagesJob).
     *
     * @param  object  $job
     * @param  string  $nombre
     * @return mixed
     */
    protected function propiedad_del_job($job, $nombre)
    {
        $property = new ReflectionProperty($job, $nombre);
        $property->setAccessible(true);

        return $property->getValue($job);
    }

    /**
     * La última asignación de imágenes del comercio del test (la que acaba de crear encolar()).
     *
     * @return \App\Models\ImageAssignmentRun|null
     */
    protected function ultima_asignacion()
    {
        return ImageAssignmentRun::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
    }

    /**
     * Los ids de artículo de una asignación, en el orden en que se van a procesar.
     *
     * @param  \App\Models\ImageAssignmentRun  $run
     * @return array<int, int>
     */
    protected function ids_de_la_asignacion(ImageAssignmentRun $run)
    {
        return ImageAssignmentItem::where('run_id', $run->id)
            ->orderBy('orden')
            ->pluck('article_id')
            ->map(function ($id) {
                return (int) $id;
            })
            ->all();
    }

    /**
     * @param  User  $user
     * @return void
     */
    protected function actuar_como($user)
    {
        Auth::forgetGuards();

        $this->actingAs($user, 'web');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function en_resuelto_se_auto_confirma_y_encola_los_primeros_n_sin_imagen_en_orden_de_alta()
    {
        list($conversation, $assistant) = $this->conversacion();

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $resultado = HerramientasDeCarga::ejecutar('proponer_imagenes_para_articulos', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
            'orden'   => 'primeros_creados',
            'limite'  => 2,
        ], $conversation, $assistant);

        $this->assertFalse($resultado['is_error'], $resultado['content']);

        $contenido = json_decode($resultado['content'], true);
        $this->assertTrue($contenido['ok'], $resultado['content']);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $contenido['estado'], 'En "resuelto" la propuesta se confirma en el acto: ' . $resultado['content']);
        $this->assertSame('Mandé a buscar imágenes para 2 artículos. Te va a aparecer en el sistema cuando termine.', $contenido['resultado']);

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)
            ->where('tipo', AiMessageAction::TIPO_IMAGENES_ARTICULOS)
            ->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $accion->estado_guardado());
        $this->assertSame('article', $accion->resultado->ruta->name);

        // Los dos más viejos SIN imagen: el de hace 4 días y el de hace 2 (el de hace 3 tiene imagen).
        $esperados = [(int) $this->por_alta[4]->id, (int) $this->por_alta[2]->id];

        // La asignación que creó encolar(): esos ids en ese orden, del comercio, con su uuid, y con
        // el tope diario del dueño (la cuota por defecto de siempre, porque no tiene una cargada).
        $asignacion = $this->ultima_asignacion();
        $this->assertNotNull($asignacion);
        $this->assertSame($esperados, $this->ids_de_la_asignacion($asignacion));
        $this->assertSame((int) $this->comercio->id, (int) $asignacion->user_id);
        $this->assertNotSame('', (string) $asignacion->uuid);
        $this->assertTrue((bool) $asignacion->aplica_tope_diario);
        $this->assertSame(ImageSearchProviderFactory::nombre_para($this->comercio), $asignacion->proveedor);
        $this->assertSame(ImagenesAutomaticasHelper::CUOTA_POR_DEFECTO, ImagenesAutomaticasHelper::cuota_de($this->comercio)['cuota']);

        $self = $this;

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, function ($job) use ($self, $asignacion) {
            return (int) $self->propiedad_del_job($job, 'run_id') === (int) $asignacion->id;
        });

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        // El registro visible nació pendiente al encolar, con el total y quién lo pidió.
        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_automaticas')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($proceso);
        $this->assertSame('pendiente', $proceso->status);
        $this->assertSame('En espera del procesador', $proceso->etapa);
        $this->assertSame(2, (int) $proceso->total);
        $this->assertSame('artículos', $proceso->unidad);
        $this->assertSame('2 artículos', $proceso->detalle);
        $this->assertSame((int) $this->comercio->id, (int) $proceso->auth_user_id);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_busquedas_disponibles_hoy_no_se_propone_ni_se_encola()
    {
        // La cuota del día ya se usó entera (el botón del listado, por ejemplo).
        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 10]);

        list($conversation, $assistant) = $this->conversacion();

        Queue::fake();

        $resultado = HerramientasDeCarga::ejecutar('proponer_imagenes_para_articulos', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
        ], $conversation, $assistant);

        $contenido = json_decode($resultado['content'], true);
        $this->assertFalse($contenido['ok'], $resultado['content']);
        $this->assertSame(PropuestaImagenesArticulosIaHelper::MENSAJE_SIN_CUOTA, $contenido['error']);
        $this->assertSame(0, AiMessageAction::where('ai_conversation_id', $conversation->id)->count());
        Queue::assertNothingPushed();
    }

    /**
     * @group chat-ia
     * @test
     */
    public function en_cauteloso_queda_la_tarjeta_propuesta_con_los_renglones_del_contrato_y_no_encola_nada()
    {
        $this->comercio->agente_confianza = 'cauteloso';
        $this->comercio->save();

        // Tres búsquedas ya usadas hoy: la tarjeta tiene que decir 7 de 10.
        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 3]);

        list($conversation, $assistant) = $this->conversacion();

        Queue::fake();

        $resultado = HerramientasDeCarga::ejecutar('proponer_imagenes_para_articulos', [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
            'limite'  => 2,
        ], $conversation, $assistant);

        $contenido = json_decode($resultado['content'], true);
        $this->assertTrue($contenido['ok'], $resultado['content']);
        $this->assertSame(AiMessageAction::TIPO_IMAGENES_ARTICULOS, $contenido['tipo']);
        $this->assertArrayNotHasKey('estado', $contenido);
        $this->assertSame(2, $contenido['articulos']);
        $this->assertSame(3, $contenido['total_del_filtro']);
        $this->assertSame(7, $contenido['busquedas_disponibles_hoy']);
        $this->assertSame(10, $contenido['cuota_diaria']);

        $accion = AiMessageAction::find($contenido['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());

        $presentacion = $accion->presentacion;
        $this->assertSame('Búsqueda de imágenes para artículos', $presentacion['titulo']);
        $this->assertSame([
            ['etiqueta' => 'Artículos', 'valor' => '2 (los primeros 2 cargados, sin imagen)'],
            ['etiqueta' => 'Proveedor', 'valor' => 'Bulonera P27'],
            ['etiqueta' => 'Búsquedas disponibles hoy', 'valor' => '7 de 10'],
        ], $presentacion['renglones']);
        $this->assertSame(PropuestaImagenesArticulosIaHelper::AVISO, $presentacion['aviso']);

        $datos = $accion->datos;
        $this->assertSame('en_blanco', $datos['imagen'], 'Por defecto se saltean los que ya tienen imagen.');
        $this->assertSame('primeros_creados', $datos['orden']);
        $this->assertSame(2, $datos['limite']);
        $this->assertTrue($datos['solo_sin_imagen']);
        $this->assertSame([['key' => 'provider_id', 'type' => 'search', 'igual_que' => (int) $this->bulonera->id]], $datos['filter_form']);

        Queue::assertNothingPushed();
        $this->assertSame(0, BackgroundProcess::where('user_id', $this->comercio->id)->count());

        // Con solo_sin_imagen en false entran también los que ya tienen imagen, y sin límite van todos.
        $todos = HerramientasDeCarga::ejecutar('proponer_imagenes_para_articulos', [
            'filtros'         => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
            'solo_sin_imagen' => false,
        ], $conversation, $assistant);

        $contenido = json_decode($todos['content'], true);
        $this->assertSame(4, $contenido['articulos']);
        $this->assertSame([
            ['etiqueta' => 'Artículos', 'valor' => '4'],
            ['etiqueta' => 'Proveedor', 'valor' => 'Bulonera P27'],
            ['etiqueta' => 'Búsquedas disponibles hoy', 'valor' => '7 de 10'],
        ], AiMessageAction::find($contenido['tarjeta_id'])->presentacion['renglones']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_articulos_que_cumplan_el_filtro_vuelve_como_error_y_el_conteo_los_anticipa()
    {
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        // Todos los que cumplen ya tienen imagen.
        $respuesta = PropuestaImagenesArticulosIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'alta hace 3']],
        ]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertSame('Ningún artículo sin imagen cumple ese filtro: los que lo cumplen ya tienen imagen.', $respuesta['error']);

        $ninguno = PropuestaImagenesArticulosIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'no-existe-p27']],
        ]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($ninguno));

        $ambiguo = PropuestaImagenesArticulosIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'no-existe-p27']],
        ]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($ambiguo));
        $this->assertStringContainsString('ningún proveedor', $ambiguo['error']);

        // contar_articulos_por_filtro, por la herramienta, anticipa lo que la propuesta va a alcanzar.
        $conteo = HerramientasDeCarga::ejecutar('contar_articulos_por_filtro', [
            'filtros'         => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
            'solo_sin_imagen' => true,
            'limite'          => 2,
        ], $conversation, $assistant);

        $contenido = json_decode($conteo['content'], true);
        $this->assertTrue($contenido['ok']);
        $this->assertSame(3, $contenido['total']);
        $this->assertSame(2, $contenido['a_procesar']);
        $this->assertSame(['Proveedor: Bulonera P27', 'Imagen: sin imagen'], $contenido['filtros_legibles']);
        $this->assertSame(['zz-p27 alta hace 4', 'zz-p27 alta hace 2', 'zz-p27 alta hace 1'], $contenido['muestra']);

        // Sin mensaje, una propuesta no tiene dónde colgar la tarjeta; el conteo sí anda sin mensaje.
        $sin_mensaje = HerramientasDeCarga::ejecutar('proponer_imagenes_para_articulos', ['filtros' => []], $conversation, null);
        $this->assertTrue($sin_mensaje['is_error']);
        $conteo_sin_mensaje = HerramientasDeCarga::ejecutar('contar_articulos_por_filtro', ['filtros' => []], $conversation, null);
        $this->assertFalse($conteo_sin_mensaje['is_error']);
        $this->assertSame(5, json_decode($conteo_sin_mensaje['content'], true)['total']);
    }

    /**
     * Al ejecutar, los ids se resuelven de nuevo: una imagen cargada a mano entre la tarjeta y el clic
     * saca al artículo de la tanda.
     *
     * @group chat-ia
     * @test
     */
    public function ejecutar_vuelve_a_resolver_los_ids_con_los_datos_de_hoy()
    {
        $this->comercio->agente_confianza = 'cauteloso';
        $this->comercio->save();

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaImagenesArticulosIaHelper::proponer($contexto, $assistant, [
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P27']],
            'limite'  => 2,
        ]);
        $this->assertTrue($respuesta['ok']);

        // Entre la tarjeta y el clic, el más viejo recibe una imagen a mano.
        Image::create(['imageable_id' => $this->por_alta[4]->id, 'imageable_type' => 'article', 'hosting_url' => 'https://ejemplo.test/p27-b.webp']);

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $resultado = PropuestaImagenesArticulosIaHelper::ejecutar($contexto, AiMessageAction::find($respuesta['tarjeta_id']));

        $this->assertSame('Mandé a buscar imágenes para 2 artículos. Te va a aparecer en el sistema cuando termine.', $resultado['texto']);

        $esperados = [(int) $this->por_alta[2]->id, (int) $this->por_alta[1]->id];

        $asignacion = $this->ultima_asignacion();
        $this->assertNotNull($asignacion);
        $this->assertSame($esperados, $this->ids_de_la_asignacion($asignacion));

        $self = $this;

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, function ($job) use ($self, $asignacion) {
            return (int) $self->propiedad_del_job($job, 'run_id') === (int) $asignacion->id;
        });
    }

    /**
     * 🔴 El botón del listado sigue igual por fuera (`{status, batch_uuid}`) y por dentro deja el
     * registro visible pendiente desde el request.
     *
     * @group chat-ia
     * @test
     */
    public function el_endpoint_del_listado_sigue_devolviendo_status_y_batch_uuid_y_ahora_deja_el_registro_pendiente()
    {
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->actuar_como($this->comercio);

        $ids = [(int) $this->por_alta[1]->id, (int) $this->por_alta[2]->id, (int) $this->por_alta[3]->id];

        $response = $this->postJson('api/google/batch-assign-images', ['article_ids' => $ids]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'batch_uuid']);
        $this->assertSame('processing', $response->json('status'));
        $this->assertNotSame('', (string) $response->json('batch_uuid'));

        $uuid_prometido = (string) $response->json('batch_uuid');

        // El uuid prometido es el de la asignación, y la asignación lleva esos ids en ese orden.
        $asignacion = ImageAssignmentRun::where('user_id', $this->comercio->id)->where('uuid', $uuid_prometido)->first();
        $this->assertNotNull($asignacion, 'El uuid de la respuesta tiene que ser el de la asignación creada.');
        $this->assertSame($ids, $this->ids_de_la_asignacion($asignacion));

        $self = $this;

        Queue::assertPushed(ProcessImageAssignmentRunJob::class, function ($job) use ($self, $asignacion) {
            return (int) $self->propiedad_del_job($job, 'run_id') === (int) $asignacion->id;
        });

        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'imagenes_automaticas')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($proceso, 'El registro visible tiene que nacer al encolar, no cuando el worker levanta el job.');
        $this->assertSame('pendiente', $proceso->status);
        $this->assertSame('En espera del procesador', $proceso->etapa);
        $this->assertSame(3, (int) $proceso->total);
        $this->assertSame('3 artículos', $proceso->detalle);
        $this->assertSame((int) $this->comercio->id, (int) $proceso->auth_user_id);
        $this->assertSame('Imágenes automáticas', $proceso->titulo);

        // Sin artículos, el request se rechaza como siempre (validación del controller).
        $this->postJson('api/google/batch-assign-images', ['article_ids' => []])->assertStatus(422);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function la_cuota_y_las_credenciales_salen_del_dueno_o_del_default()
    {
        $this->assertSame(['cuota' => 10, 'usadas_hoy' => 0, 'disponibles' => 10], ImagenesAutomaticasHelper::cuota_de($this->comercio));

        GeocoderCounter::create(['user_id' => $this->comercio->id, 'counter' => 4]);
        $this->assertSame(['cuota' => 10, 'usadas_hoy' => 4, 'disponibles' => 6], ImagenesAutomaticasHelper::cuota_de($this->comercio));

        $this->comercio->google_cuota = 3;
        $this->comercio->google_custom_search_api_key = 'KEY-DEL-DUENO-P27';
        $this->comercio->save();

        $this->assertSame(['cuota' => 3, 'usadas_hoy' => 4, 'disponibles' => 0], ImagenesAutomaticasHelper::cuota_de($this->comercio->fresh()));
        $this->assertSame(['api_key' => 'KEY-DEL-DUENO-P27', 'cx' => 'c442e5f346f314951', 'cuota' => 3], ImagenesAutomaticasHelper::credenciales($this->comercio->fresh()));

        // Un contador de otro día no cuenta para hoy.
        GeocoderCounter::where('user_id', $this->comercio->id)->update(['created_at' => Carbon::yesterday()]);
        $this->assertSame(0, ImagenesAutomaticasHelper::cuota_de($this->comercio->fresh())['usadas_hoy']);
    }
}
