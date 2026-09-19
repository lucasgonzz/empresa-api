<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaImagenesArticulosIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Http\Controllers\Helpers\ImagenesAutomaticasHelper;
use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\GeocoderCounter;
use App\Models\Image;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
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
 * Lo que protege: que con el dueño en "resuelto" la propuesta se auto-confirme y encole
 * ProcessArticleBatchImagesJob con los ids en orden de alta ascendente, limitados a N y sin los que
 * ya tienen imagen; que en "cauteloso" quede la tarjeta propuesta con los renglones del contrato; que
 * el registro visible `imagenes_automaticas` nazca `pendiente` al encolar; y que el endpoint del
 * botón del listado (GoogleController@batch_assign_images) siga devolviendo `{status, batch_uuid}` y
 * ahora también deje ese registro pendiente.
 *
 * 🔴 Sin red: la cola se falsea en todos los casos (el job real busca en Google y valida con IA).
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
     * @param  ProcessArticleBatchImagesJob  $job
     * @param  string  $nombre
     * @return mixed
     */
    protected function propiedad_del_job(ProcessArticleBatchImagesJob $job, $nombre)
    {
        $property = new ReflectionProperty($job, $nombre);
        $property->setAccessible(true);

        return $property->getValue($job);
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

        $self = $this;

        Queue::assertPushed(ProcessArticleBatchImagesJob::class, function ($job) use ($self, $esperados) {
            return $self->propiedad_del_job($job, 'article_ids') === $esperados
                && (int) $self->propiedad_del_job($job, 'user_id') === (int) $self->comercio->id
                && $self->propiedad_del_job($job, 'cx') === ImagenesAutomaticasHelper::CX
                && (int) $self->propiedad_del_job($job, 'google_cuota') === ImagenesAutomaticasHelper::CUOTA_POR_DEFECTO
                && $self->propiedad_del_job($job, 'batch_uuid') !== '';
        });

        Queue::assertPushed(ProcessArticleBatchImagesJob::class, 1);

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

        $self = $this;

        Queue::assertPushed(ProcessArticleBatchImagesJob::class, function ($job) use ($self, $esperados) {
            return $self->propiedad_del_job($job, 'article_ids') === $esperados;
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

        $self = $this;

        Queue::assertPushed(ProcessArticleBatchImagesJob::class, function ($job) use ($self, $ids, $uuid_prometido) {
            return $self->propiedad_del_job($job, 'article_ids') === $ids
                && $self->propiedad_del_job($job, 'batch_uuid') === $uuid_prometido;
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
