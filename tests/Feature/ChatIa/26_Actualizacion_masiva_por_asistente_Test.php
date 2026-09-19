<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\asistente_ia\AccionIaException;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\BackgroundProcess;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\Image;
use App\Models\MasiveUpdate;
use App\Models\Provider;
use App\Models\User;
use App\Services\AsistenteIa\HerramientasDeCarga;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-masivas-imagenes-y-remito — la actualización masiva de artículos que propone el
 * asistente (PropuestaActualizacionMasivaIaHelper, plan §4.2, contrato §1.5).
 *
 * Lo que protege: que proponer deje la tarjeta con los renglones del contrato y NUNCA la
 * auto-confirme, ni con el dueño en "resuelto"; que sin artículos alcanzados o con 3000 o más vuelva
 * como error; que confirmar encole ProcessMasiveUpdateJob por el MISMO camino que la pantalla (la
 * misma criteria_json con resolved_models_id, el registro visible en pendiente) y que el job, corrido
 * a mano, cambie a los alcanzados y a nadie más; que un empleado sin article.update no pueda ni
 * proponer ni confirmar; y que el endpoint PUT update/article de la pantalla siga respondiendo igual.
 *
 * 🔴 Sin red: la clave de Anthropic va en null y la cola se falsea donde se encola.
 */
class Actualizacion_masiva_por_asistente_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'asistente_ia';

    /** @var User */
    protected $comercio;

    /** @var User Empleado raso: sin admin_access y sin permisos. */
    protected $empleado;

    /** @var Provider */
    protected $bulonera;

    /** @var Provider */
    protected $pinturas;

    /** @var Article[] Los de la bulonera, en orden de alta. */
    protected $de_la_bulonera = [];

    /** @var Article El de pinturas: nunca lo alcanza el filtro. */
    protected $de_pinturas;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio masiva P26',
            'company_name'     => 'Ferreteria P26',
            'email'            => 'masiva-p26-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->empleado = User::create([
            'name'     => 'Empleado masiva P26',
            'email'    => 'masiva-p26-emp-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $this->comercio->id,
        ]);

        $this->bulonera = Provider::create(['name' => 'Bulonera P26', 'user_id' => $this->comercio->id]);
        $this->pinturas = Provider::create(['name' => 'Pinturas P26', 'user_id' => $this->comercio->id]);

        $this->de_la_bulonera[] = $this->articulo(['name' => 'zz-p26 tornillo', 'provider_id' => $this->bulonera->id]);
        $this->de_la_bulonera[] = $this->articulo(['name' => 'zz-p26 tuerca', 'provider_id' => $this->bulonera->id]);
        $this->de_pinturas = $this->articulo(['name' => 'zz-p26 latex', 'provider_id' => $this->pinturas->id]);
    }

    /**
     * @param  array  $atributos
     * @return Article
     */
    protected function articulo(array $atributos = [])
    {
        return Article::create(array_merge([
            'name'            => 'zz-p26-' . uniqid(),
            'user_id'         => $this->comercio->id,
            'status'          => 'active',
            'cost'            => 1000,
            'percentage_gain' => 30,
        ], $atributos));
    }

    /**
     * Le da la extensión del asistente al comercio (la piden las rutas del chat).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Asistente IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Conversación de la persona con el assistant pendiente que propone.
     *
     * @param  User|null  $persona
     * @return array{0: AiConversation, 1: AiMessage}
     */
    protected function conversacion($persona = null)
    {
        $persona = is_null($persona) ? $this->comercio : $persona;

        $conversation = AiConversation::create([
            'user_id'      => $this->comercio->id,
            'auth_user_id' => $persona->id,
        ]);

        AiMessage::create([
            'ai_conversation_id' => $conversation->id,
            'rol'                => 'user',
            'contenido'          => 'Ponele 40 % de margen a los de la bulonera',
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
     * El input de la herramienta para "40 % de margen a los de la bulonera".
     *
     * @param  array  $extra
     * @return array
     */
    protected function input_del_margen(array $extra = [])
    {
        return array_merge([
            'filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P26']],
            'cambios' => [['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 40]],
        ], $extra);
    }

    /**
     * Autentica para las requests que siguen (mismo motivo que en ImagenesGoogle: sanctum cachea).
     *
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
    public function proponer_deja_la_tarjeta_con_los_renglones_del_contrato_y_el_update_form_de_la_pantalla()
    {
        list($conversation, $assistant) = $this->conversacion();

        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen([
            'cambios' => [
                ['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 40],
                ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
                ['campo' => 'categoria', 'operacion' => 'asignar', 'valor' => 'Ferretería P26'],
                ['campo' => 'iva', 'operacion' => 'asignar', 'valor' => '21 %'],
                ['campo' => 'en_tienda', 'operacion' => 'activar'],
            ],
        ]));

        // La categoría que se asigna existe recién acá abajo: primero se prueba que sin ella es error.
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertStringContainsString('ninguna categoría', $respuesta['error']);

        $categoria = Category::create(['name' => 'Ferretería P26', 'user_id' => $this->comercio->id]);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen([
            'cambios' => [
                ['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 40],
                ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
                ['campo' => 'categoria', 'operacion' => 'asignar', 'valor' => 'Ferretería P26'],
                ['campo' => 'iva', 'operacion' => 'asignar', 'valor' => '21 %'],
                ['campo' => 'en_tienda', 'operacion' => 'activar'],
            ],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(AiMessageAction::TIPO_ACTUALIZACION_MASIVA, $respuesta['tipo']);
        $this->assertSame(2, $respuesta['articulos_alcanzados']);
        $this->assertSame(['Proveedor: Bulonera P26'], $respuesta['filtros_legibles']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());

        $presentacion = $accion->presentacion;
        $this->assertSame('Actualización masiva de artículos', $presentacion['titulo']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '2'],
            ['etiqueta' => 'Proveedor', 'valor' => 'Bulonera P26'],
            ['etiqueta' => 'Cambio', 'valor' => 'Margen de ganancia → 40 %'],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio manual sube 10 % (redondeado)'],
            ['etiqueta' => 'Cambio', 'valor' => 'Categoría → Ferretería P26'],
            ['etiqueta' => 'Cambio', 'valor' => 'IVA → 21 %'],
            ['etiqueta' => 'Cambio', 'valor' => 'En tienda → activado'],
        ], $presentacion['renglones']);
        $this->assertSame(PropuestaActualizacionMasivaIaHelper::AVISO, $presentacion['aviso']);

        $iva_21 = DB::table('ivas')->where('percentage', '21')->value('id');

        $datos = $accion->datos;
        $this->assertSame([
            ['type' => 'number',   'key' => 'set_percentage_gain', 'value' => 40],
            ['type' => 'number',   'key' => 'increment_price',     'value' => 10, 'round' => true],
            ['type' => 'search',   'key' => 'category_id',         'value' => (int) $categoria->id],
            ['type' => 'select',   'key' => 'iva_id',              'value' => (int) $iva_21],
            ['type' => 'checkbox', 'key' => 'online',              'value' => 1],
        ], $datos['update_form']);
        $this->assertSame([['key' => 'provider_id', 'type' => 'search', 'igual_que' => (int) $this->bulonera->id]], $datos['filter_form']);
        $this->assertNull($datos['imagen']);
        $this->assertSame(2, $datos['total_estimado']);

        // La clave: mismos filtros y cambios → misma clave; otro cambio → otra.
        $this->assertStringStartsWith('actualizacion_masiva:', PropuestaActualizacionMasivaIaHelper::clave($datos['filtros'], [['campo' => 'en_tienda', 'operacion' => 'activar']]));
        $this->assertNotSame(
            PropuestaActualizacionMasivaIaHelper::clave($datos['filtros'], [['campo' => 'en_tienda', 'operacion' => 'activar']]),
            PropuestaActualizacionMasivaIaHelper::clave($datos['filtros'], [['campo' => 'en_tienda', 'operacion' => 'desactivar']])
        );

        // Nada se aplicó todavía.
        $this->assertEquals(30, (float) $this->de_la_bulonera[0]->fresh()->percentage_gain);
        $this->assertSame(0, MasiveUpdate::where('user_id', $this->comercio->id)->count());
    }

    /**
     * 🔴 La masiva NUNCA se auto-confirma, ni con el dueño en "resuelto": la tarjeta queda propuesta.
     *
     * @group chat-ia
     * @test
     */
    public function la_masiva_nunca_se_auto_confirma_ni_con_el_dueno_en_resuelto()
    {
        $this->assertNotContains(AiMessageAction::TIPO_ACTUALIZACION_MASIVA, HerramientasDeCarga::AUTO_CONFIRMABLES);

        $this->comercio->agente_confianza = 'resuelto';
        $this->comercio->save();

        list($conversation, $assistant) = $this->conversacion();

        Queue::fake();

        $resultado = HerramientasDeCarga::ejecutar('proponer_actualizacion_masiva', $this->input_del_margen(), $conversation, $assistant);

        $this->assertFalse($resultado['is_error']);

        $contenido = json_decode($resultado['content'], true);
        $this->assertTrue($contenido['ok']);
        $this->assertSame(AiMessageAction::TIPO_ACTUALIZACION_MASIVA, $contenido['tipo']);
        $this->assertArrayNotHasKey('estado', $contenido, 'Una respuesta con "estado" es la de confirmar_del_agente: la masiva no puede haber pasado por ahí.');
        $this->assertSame('La tarjeta queda para que la persona la confirme. No digas que ya está cargado.', $contenido['nota']);

        $accion = AiMessageAction::where('ai_conversation_id', $conversation->id)
            ->where('tipo', AiMessageAction::TIPO_ACTUALIZACION_MASIVA)
            ->first();
        $this->assertNotNull($accion);
        $this->assertSame(AiMessageAction::ESTADO_PROPUESTA, $accion->estado_guardado());

        Queue::assertNotPushed(ProcessMasiveUpdateJob::class);
        $this->assertSame(0, MasiveUpdate::where('user_id', $this->comercio->id)->count());
        $this->assertEquals(30, (float) $this->de_la_bulonera[0]->fresh()->percentage_gain);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_articulos_alcanzados_sin_filtro_o_con_3000_o_mas_vuelve_como_error()
    {
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $ninguno = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen([
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'no-existe-p26']],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($ninguno));
        $this->assertSame('Ningún artículo cumple ese filtro. Revisá el filtro con la persona.', $ninguno['error']);

        // Sin filtro no hay masiva: mismo guard que la pantalla.
        $sin_filtro = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen(['filtros' => []]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($sin_filtro));
        $this->assertCount(1, $sin_filtro['faltan']);

        $sin_cambios = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen(['cambios' => []]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($sin_cambios));
        $this->assertCount(1, $sin_cambios['faltan']);

        $cambio_raro = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen([
            'cambios' => [['campo' => 'color', 'operacion' => 'setear', 'valor' => 'rojo']],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($cambio_raro));
        $this->assertStringContainsString('No conozco el campo "color"', $cambio_raro['error']);

        /*
         * El tope: 3000 artículos de la pinturería, sembrados por insert directo (sin observers ni
         * precios) porque lo único que se mide es el conteo. Con 2999 sigue pasando; con 3000, no.
         */
        $filas = [];

        for ($i = 0; $i < 2998; $i++) {
            $filas[] = [
                'name'        => 'zz-p26 tope ' . $i,
                'user_id'     => $this->comercio->id,
                'provider_id' => $this->pinturas->id,
                'status'      => 'active',
            ];
        }

        foreach (array_chunk($filas, 500) as $tanda) {
            DB::table('articles')->insert($tanda);
        }

        $input = $this->input_del_margen(['filtros' => [['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Pinturas P26']]]);

        $justo_debajo = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $input);
        $this->assertTrue($justo_debajo['ok'], '2999 artículos tienen que poder actualizarse: ' . json_encode($justo_debajo));
        $this->assertSame(2999, $justo_debajo['articulos_alcanzados']);

        DB::table('articles')->insert([['name' => 'zz-p26 tope 2999', 'user_id' => $this->comercio->id, 'provider_id' => $this->pinturas->id, 'status' => 'active']]);

        $tope = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $input);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($tope));
        $this->assertSame('Son 3000 artículos y el tope de una actualización masiva es 3000: acotá el filtro.', $tope['error']);
    }

    /**
     * Confirmar por el endpoint de la tarjeta encola por el mismo camino que la pantalla, y el job
     * corrido a mano cambia a los alcanzados y a nadie más.
     *
     * @group chat-ia
     * @test
     */
    public function confirmar_encola_la_masiva_como_la_pantalla_y_el_job_cambia_solo_a_los_alcanzados()
    {
        $this->dar_extension();

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen([
            'filtros' => [
                ['campo' => 'proveedor', 'operador' => 'igual', 'valor' => 'Bulonera P26'],
                ['campo' => 'imagen', 'operador' => 'en_blanco'],
            ],
        ]));
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        // La tuerca tiene imagen: el filtro de imagen la tiene que dejar afuera al ejecutar.
        Image::create(['imageable_id' => $this->de_la_bulonera[1]->id, 'imageable_type' => 'article', 'hosting_url' => 'https://ejemplo.test/p26.webp']);

        $assistant->estado = 'listo';
        $assistant->save();

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->actuar_como($this->comercio);

        $confirmacion = $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar');

        $confirmacion->assertStatus(200);
        $this->assertSame(AiMessageAction::ESTADO_CONFIRMADA, $confirmacion->json('model.estado'));
        $this->assertSame('Actualización masiva encolada: 1 artículos. Te va a aparecer en el sistema cuando termine.', $confirmacion->json('model.resultado.texto'));
        $this->assertSame('article', $confirmacion->json('model.resultado.ruta.name'));
        $this->assertSame('Ver el listado', $confirmacion->json('model.resultado.ruta.texto'));

        Queue::assertPushed(ProcessMasiveUpdateJob::class, 1);

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
        $this->assertNotNull($masiva);
        $this->assertSame('pending', $masiva->status);
        $this->assertTrue($masiva->from_filter);
        $this->assertSame('article', $masiva->model_name);
        $this->assertSame((int) $this->comercio->id, (int) $masiva->employee_id);

        $criteria = json_decode($masiva->criteria_json, true);
        $this->assertSame([['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]], $criteria['update_form']);
        $this->assertTrue($criteria['from_filter']);
        $this->assertSame([(int) $this->de_la_bulonera[0]->id], $criteria['resolved_models_id'], 'Solo el tornillo: la tuerca tiene imagen y el látex es de otro proveedor.');
        $this->assertSame([['key' => 'provider_id', 'type' => 'search', 'igual_que' => (int) $this->bulonera->id]], $criteria['filter_form']);
        $this->assertContains(['key' => 'imagen', 'operator' => 'en_blanco', 'value' => true, 'type' => 'imagen'], $criteria['used_filters']);

        $proceso = BackgroundProcess::where('user_id', $this->comercio->id)->where('tipo', 'actualizacion_masiva')->orderBy('id', 'DESC')->first();
        $this->assertNotNull($proceso, 'El registro visible tiene que nacer al encolar.');
        $this->assertSame('pendiente', $proceso->status);
        $this->assertSame('En espera del procesador', $proceso->etapa);
        $this->assertSame((int) $masiva->id, (int) $proceso->referencia_id);

        // El job, a mano: lo que haría el worker.
        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame('completed', $masiva->fresh()->status);
        $this->assertEquals(40, (float) $this->de_la_bulonera[0]->fresh()->percentage_gain, 'El tornillo tenía que quedar en 40.');
        $this->assertEquals(30, (float) $this->de_la_bulonera[1]->fresh()->percentage_gain, 'La tuerca (con imagen) no estaba alcanzada.');
        $this->assertEquals(30, (float) $this->de_pinturas->fresh()->percentage_gain, 'El látex es de otro proveedor.');
        $this->assertSame('completado', $proceso->fresh()->status);

        // Y por el segundo clic no pasa nada: la tarjeta ya está resuelta.
        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')->assertStatus(409);
        $this->assertSame(1, MasiveUpdate::where('user_id', $this->comercio->id)->count());
    }

    /**
     * @group chat-ia
     * @test
     */
    public function un_empleado_sin_article_update_no_puede_proponer_ni_confirmar()
    {
        list($conversation_del_dueno, $assistant_del_dueno) = $this->conversacion();
        $contexto_del_dueno = ContextoDeCargaIa::de_la_conversacion($conversation_del_dueno);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto_del_dueno, $assistant_del_dueno, $this->input_del_margen());
        $this->assertTrue($respuesta['ok']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        // El empleado raso, sin el permiso: ni propone...
        list($conversation, $assistant) = $this->conversacion($this->empleado);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $negada = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input_del_margen());
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($negada));
        $this->assertSame('No tenés permiso para cargar actualizaciones masivas de artículos desde tu usuario.', $negada['error']);

        // ...ni confirma, aunque le pongan adelante la tarjeta del dueño.
        Queue::fake();

        try {
            PropuestaActualizacionMasivaIaHelper::ejecutar($contexto, $accion);
            $this->fail('Ejecutar sin permiso tenía que lanzar AccionIaException.');
        } catch (AccionIaException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('No tenés permiso para cargar actualizaciones masivas de artículos desde tu usuario.', $e->getMessage());
        }

        Queue::assertNotPushed(ProcessMasiveUpdateJob::class);
        $this->assertSame(0, MasiveUpdate::where('user_id', $this->comercio->id)->count());
    }

    /**
     * 🔴 El endpoint de la pantalla no cambia: sigue en 200 con queued_count y en 422 sin filtros.
     *
     * @group chat-ia
     * @test
     */
    public function el_endpoint_de_la_pantalla_sigue_respondiendo_igual()
    {
        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->actuar_como($this->comercio);

        $con_filtro = $this->putJson('api/update/article', [
            'from_filter' => 1,
            'filter_form' => [['key' => 'provider_id', 'type' => 'search', 'igual_que' => $this->bulonera->id]],
            'update_form' => [['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]],
        ]);

        $con_filtro->assertStatus(200);
        $this->assertSame('La actualización masiva se está procesando en segundo plano', $con_filtro->json('message'));
        $this->assertSame(2, $con_filtro->json('queued_count'));
        $this->assertNotNull($con_filtro->json('masive_update_id'));

        Queue::assertPushed(ProcessMasiveUpdateJob::class, 1);

        $masiva = MasiveUpdate::find($con_filtro->json('masive_update_id'));
        $criteria = json_decode($masiva->criteria_json, true);
        $this->assertEqualsCanonicalizing([(int) $this->de_la_bulonera[0]->id, (int) $this->de_la_bulonera[1]->id], $criteria['resolved_models_id']);

        $sin_filtros = $this->putJson('api/update/article', [
            'from_filter' => 1,
            'filter_form' => [],
            'update_form' => [['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]],
        ]);

        $sin_filtros->assertStatus(422);
        $this->assertSame('No se permite actualizar por filtro si no hay criterios de filtrado.', $sin_filtros->json('message'));

        $solo_orden = $this->putJson('api/update/article', [
            'from_filter' => 1,
            'filter_form' => [['key' => 'name', 'type' => 'textarea', 'ordenar_de' => 'ASC']],
            'update_form' => [['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]],
        ]);

        $solo_orden->assertStatus(422);
        $this->assertSame('No se permite actualizar por filtro si no hay criterios de filtrado.', $solo_orden->json('message'));

        $sin_seleccion = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => [],
            'update_form' => [['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]],
        ]);

        $sin_seleccion->assertStatus(422);
        $this->assertSame('No se permite actualizar sin selección de registros.', $sin_seleccion->json('message'));

        $seleccion = $this->putJson('api/update/article', [
            'from_filter' => 0,
            'models_id'   => [$this->de_pinturas->id],
            'update_form' => [['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 40]],
        ]);

        $seleccion->assertStatus(200);
        $this->assertSame(1, $seleccion->json('queued_count'));

        Queue::assertPushed(ProcessMasiveUpdateJob::class, 2);
    }
}
