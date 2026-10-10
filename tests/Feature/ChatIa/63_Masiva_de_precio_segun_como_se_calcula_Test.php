<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\MasiveUpdate;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión asistente-masiva-precio-manual (10/10/2026) — "subile 10 % el precio" por el asistente.
 *
 * El defecto, medido en demo (4.3.8): "Subile 10 % el precio a toda la categoría Adhesivos y
 * selladores" salía como "Precio manual sube 10 % (redondeado)" sobre artículos que calculan con
 * costo + margen; la masiva terminaba completada con 0 afectados y 0 cambios, y de paso escribía
 * `price = 0.00` sin registrarlo.
 *
 * Lo que protege: que un cambio de precio manual que no le mueve el precio a nadie vuelva como error
 * (sin tarjeta) con la sugerencia de precio_final; que precio_final suba el precio que se cobra
 * (ajustando el margen en los de costo + margen y el precio en los de precio manual) y se pueda
 * revertir; que con listas de precio se proponga subir el costo; que un porcentaje sobre null no
 * escriba 0; y que el aviso final diga cuando la masiva no cambió nada.
 *
 * 🔴 Sin red: la clave de Anthropic va en null y la cola se falsea donde se encola.
 */
class Masiva_de_precio_segun_como_se_calcula_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'asistente_ia';

    const CATEGORIA = 'Adhesivos P63';

    /** @var User */
    protected $comercio;

    /** @var Category */
    protected $adhesivos;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio masiva P63',
            'company_name'     => 'Ferreteria P63',
            'email'            => 'masiva-p63-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $this->adhesivos = Category::create(['name' => self::CATEGORIA, 'user_id' => $this->comercio->id]);
    }

    /**
     * Un artículo de la categoría Adhesivos, sin costo ni precio salvo que se diga.
     *
     * @param  array  $atributos
     * @return Article
     */
    protected function articulo(array $atributos = [])
    {
        return Article::create(array_merge([
            'name'        => 'zz-p63-' . uniqid(),
            'user_id'     => $this->comercio->id,
            'status'      => 'active',
            'category_id' => $this->adhesivos->id,
        ], $atributos));
    }

    /**
     * Le calcula el precio final como lo haría el sistema, para partir de un precio real.
     *
     * Sobre el artículo leído de la base y no sobre el que devuelve create(): ese no trae los
     * defaults de la tabla (`iva_id` = 2, `apply_provider_percentage_gain` = 1) y calcularía sin IVA,
     * mientras que la masiva recalcula con el motor, que lee de la base.
     *
     * @param  Article  $articulo
     * @return Article
     */
    protected function con_precio(Article $articulo)
    {
        $fresco = $articulo->fresh();

        ArticleHelper::setFinalPrice($fresco, $this->comercio->id);

        return $fresco->fresh();
    }

    /**
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
            'contenido'          => 'Subile 10 % el precio a toda la categoría Adhesivos',
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
     * El input de la herramienta: los de la categoría Adhesivos, con estos cambios.
     *
     * @param  array  $cambios
     * @return array
     */
    protected function input(array $cambios)
    {
        return [
            'filtros' => [['campo' => 'categoria', 'operador' => 'igual', 'valor' => self::CATEGORIA]],
            'cambios' => $cambios,
        ];
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
     * Los cuatro casos de la regla: precio manual $1000, costo 1000 + 30 %, costo 1000 sin margen y
     * uno sin costo ni precio. Con el precio final ya calculado.
     *
     * @return array<string, Article>
     */
    protected function catalogo_mixto()
    {
        return [
            'manual'     => $this->con_precio($this->articulo(['name' => 'zz-p63 manual', 'price' => 1000])),
            'con_margen' => $this->con_precio($this->articulo(['name' => 'zz-p63 con margen', 'cost' => 1000, 'percentage_gain' => 30])),
            'sin_margen' => $this->con_precio($this->articulo(['name' => 'zz-p63 sin margen', 'cost' => 1000])),
            'sin_precio' => $this->con_precio($this->articulo(['name' => 'zz-p63 sin precio'])),
        ];
    }

    /**
     * Propone, confirma por el endpoint de la tarjeta y corre el job a mano.
     *
     * @param  array  $cambios
     * @return array{0: array, 1: MasiveUpdate}  La respuesta de proponer y la masiva ya procesada.
     */
    protected function aplicar_por_la_tarjeta(array $cambios)
    {
        $this->dar_extension();

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input($cambios));
        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $assistant->estado = 'listo';
        $assistant->save();

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        $this->actuar_como($this->comercio);

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')
            ->assertStatus(200);

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
        $this->assertNotNull($masiva);

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        return [$respuesta, $masiva->fresh()];
    }

    /**
     * Cuántas tarjetas dejó la conversación del comercio.
     *
     * @return int
     */
    protected function tarjetas()
    {
        return AiMessageAction::whereIn('ai_conversation_id', AiConversation::where('user_id', $this->comercio->id)->pluck('id'))->count();
    }

    /**
     * 🔴 El de demo: "precio manual sube 10 % (redondeado)" sobre artículos de costo + margen no le
     * mueve el precio a ninguno. Vuelve como error, con la sugerencia de precio_final, y no deja
     * tarjeta ni masiva.
     *
     * @group chat-ia
     * @test
     */
    public function el_de_demo_precio_manual_sobre_costo_mas_margen_vuelve_como_error_con_la_sugerencia_de_precio_final()
    {
        $a = $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 2000, 'percentage_gain' => 50]));
        $this->con_precio($this->articulo(['cost' => 500, 'percentage_gain' => 40]));

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertStringContainsString('Ninguno de los 3 artículos usa precio manual', $respuesta['error']);
        $this->assertStringContainsString('usá el campo precio_final con el mismo porcentaje', $respuesta['error']);
        $this->assertSame(
            ['sugerencia' => ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10]],
            $respuesta['opciones']
        );

        $this->assertSame(0, $this->tarjetas(), 'Sin tarjeta: el cambio no le movía el precio a nadie.');
        $this->assertSame(0, MasiveUpdate::where('user_id', $this->comercio->id)->count());
        $this->assertNull($a->fresh()->price);
        $this->assertEquals(30, (float) $a->fresh()->percentage_gain);
    }

    /**
     * Fijar el precio manual a artículos con margen tampoco les mueve nada: setFinalPrice() lo
     * vuelve a null en el próximo recálculo.
     *
     * @group chat-ia
     * @test
     */
    public function setear_el_precio_manual_a_articulos_con_margen_vuelve_como_error()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 25]));

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_manual', 'operacion' => 'setear', 'valor' => 1500],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertStringContainsString('Ninguno de los 2 artículos usa precio manual', $respuesta['error']);
        $this->assertStringContainsString('sacarles el margen desde la ficha de cada artículo', $respuesta['error']);
        $this->assertSame(0, $this->tarjetas());
    }

    /**
     * Si a algunos sí les mueve el precio, la tarjeta sale con un renglón que avisa a cuántos no, y la
     * respuesta a la IA trae `avisos` para que se lo cuente a la persona.
     *
     * @group chat-ia
     * @test
     */
    public function precio_manual_sobre_un_manual_y_dos_con_margen_deja_la_tarjeta_con_el_aviso()
    {
        $this->con_precio($this->articulo(['name' => 'zz-p63 manual', 'price' => 1000]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 20]));

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = '2 de 3 artículos no usan precio manual: este cambio no les mueve el precio';

        $this->assertSame([$aviso], $respuesta['avisos']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '3'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio manual sube 10 %'],
            ['etiqueta' => 'Atención', 'valor' => $aviso],
        ], $accion->presentacion['renglones']);
        $this->assertSame([['type' => 'number', 'key' => 'increment_price', 'value' => 10]], $accion->datos['update_form']);
    }

    /**
     * precio_final sube el precio que se cobra: el manual sube su precio, los de costo (con o sin
     * margen) ajustan el margen, y el que no tiene costo ni precio queda igual. Confirmado por el
     * endpoint y con el job corrido a mano, cada precio final sube 10 %.
     *
     * @group chat-ia
     * @test
     */
    public function precio_final_sube_el_precio_que_se_cobra_segun_como_se_calcula_cada_articulo()
    {
        $c = $this->catalogo_mixto();

        $this->assertEquals(1000, (float) $c['manual']->final_price, 'El manual cobra su precio.');
        $this->assertGreaterThan(0, (float) $c['con_margen']->final_price);
        $this->assertGreaterThan(0, (float) $c['sin_margen']->final_price);
        $this->assertNull($c['sin_precio']->final_price);

        list($respuesta, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $como = 'En 2 artículos se ajusta el margen para que el precio final suba 10 % y en 1 sube el precio manual';
        $atencion = '1 no tiene costo ni precio cargado: no cambia';

        $this->assertSame([$como, $atencion], $respuesta['avisos']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '4'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final sube 10 %'],
            ['etiqueta' => 'Cómo', 'valor' => $como],
            ['etiqueta' => 'Atención', 'valor' => $atencion],
        ], $accion->presentacion['renglones']);

        $form = [
            'type'  => PrecioFinalEnMasivaHelper::TIPO,
            'key'   => PrecioFinalEnMasivaHelper::SUBIR,
            'value' => 10,
            'label' => 'Aumentar el precio final',
        ];
        $this->assertSame([$form], $accion->datos['update_form']);

        // El update_form llega tal cual a la masiva, con el label que lee el historial.
        $criteria = json_decode($masiva->criteria_json, true);
        $this->assertSame([$form], $criteria['update_form']);

        $this->assertSame('completed', $masiva->status);
        $this->assertSame(3, (int) $masiva->affected_count);
        $this->assertSame(3, (int) $masiva->changes_count);

        $manual = $c['manual']->fresh();
        $this->assertEquals(1100, (float) $manual->price, 'El manual sube su precio.');
        $this->assertEqualsWithDelta(1100, (float) $manual->final_price, 0.01);

        $con_margen = $c['con_margen']->fresh();
        $this->assertEquals(43, (float) $con_margen->percentage_gain, '(1,30 × 1,10 − 1) = 43 %.');
        $this->assertNull($con_margen->price);
        $this->assertEqualsWithDelta((float) $c['con_margen']->final_price * 1.1, (float) $con_margen->final_price, 0.01);

        $sin_margen = $c['sin_margen']->fresh();
        $this->assertEquals(10, (float) $sin_margen->percentage_gain, 'Sin margen, el margen nuevo es el porcentaje.');
        $this->assertEqualsWithDelta((float) $c['sin_margen']->final_price * 1.1, (float) $sin_margen->final_price, 0.01);

        $sin_precio = DB::table('articles')->where('id', $c['sin_precio']->id)->first();
        $this->assertNull($sin_precio->price, 'Sin costo ni precio no se escribe nada (ni un 0).');
        $this->assertNull($sin_precio->percentage_gain);
        $this->assertNull($sin_precio->final_price);

        $this->assertSame(
            ['price'],
            array_keys(json_decode($masiva->articles()->where('articles.id', $manual->id)->first()->pivot->changes_json, true))
        );
        $this->assertSame(
            ['percentage_gain'],
            array_keys(json_decode($masiva->articles()->where('articles.id', $con_margen->id)->first()->pivot->changes_json, true))
        );
    }

    /**
     * Revertir la masiva de precio final deja margen, precio manual y precio final como estaban.
     *
     * @group chat-ia
     * @test
     */
    public function revertir_la_masiva_de_precio_final_deja_todo_como_estaba()
    {
        $c = $this->catalogo_mixto();

        list(, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertTrue(MasiveUpdateHelper::can_revert($masiva));

        $reversion = MasiveUpdateHelper::create_pending_revert($masiva, $this->comercio->id);
        MasiveUpdateHelper::process_revert($reversion->fresh(), $masiva->fresh());

        $this->assertSame('reverted', $masiva->fresh()->status);

        foreach ($c as $caso => $antes) {

            $despues = $antes->fresh();

            $this->assertEquals($antes->price, $despues->price, $caso . ': el precio manual vuelve al de antes.');
            $this->assertEquals($antes->percentage_gain, $despues->percentage_gain, $caso . ': el margen vuelve al de antes.');

            if (is_null($antes->final_price)) {
                $this->assertNull($despues->final_price, $caso);
            } else {
                $this->assertEqualsWithDelta((float) $antes->final_price, (float) $despues->final_price, 0.01, $caso . ': el precio final vuelve al de antes.');
            }
        }

        $this->assertNull($c['sin_margen']->fresh()->percentage_gain, 'El margen que no tenía vuelve a null, no a 0.');
    }

    /**
     * Al bajar, la tarjeta avisa cuántos quedarían con margen negativo.
     *
     * @group chat-ia
     * @test
     */
    public function bajar_el_precio_final_avisa_los_que_quedarian_con_margen_negativo()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 10]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 50]));

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_final', 'operacion' => 'bajar_porcentaje', 'valor' => 20],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '2'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final baja 20 %'],
            ['etiqueta' => 'Cómo', 'valor' => 'En 2 artículos se ajusta el margen para que el precio final baje 20 %'],
            ['etiqueta' => 'Atención', 'valor' => '1 quedaría con margen negativo'],
        ], AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['renglones']);

        $this->assertSame(-12.0, PrecioFinalEnMasivaHelper::nuevo_margen(10, 20, false), '(1,10 × 0,80 − 1) = −12 %.');

        // Setear o bajar 100 % o más no son operaciones de precio final.
        $setear = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_final', 'operacion' => 'setear', 'valor' => 1500],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($setear));
        $this->assertStringContainsString('El precio final se calcula, no se fija', $setear['error']);

        $todo = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_final', 'operacion' => 'bajar_porcentaje', 'valor' => 100],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($todo));
    }

    /**
     * Con listas de precio el margen del artículo no mueve las listas y el precio manual no se usa:
     * precio_final sube el costo, y precio_manual no le mueve el precio a nadie.
     *
     * @group chat-ia
     * @test
     */
    public function con_listas_de_precio_el_precio_final_sube_el_costo_y_el_precio_manual_es_error()
    {
        $this->comercio->listas_de_precio = 1;
        $this->comercio->save();

        $this->articulo(['cost' => 1000, 'percentage_gain' => 30]);
        $this->articulo(['name' => 'zz-p63 manual con listas', 'price' => 1000]);
        $this->articulo(['name' => 'zz-p63 sin nada con listas']);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['Costo sube 10 %'], $respuesta['cambios_legibles']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);
        $this->assertSame([['type' => 'number', 'key' => 'increment_cost', 'value' => 10]], $accion->datos['update_form']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '3'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Costo sube 10 %'],
            ['etiqueta' => 'Cómo', 'valor' => 'Con listas de precio cada lista sale del costo: sube el costo 10 % y los márgenes quedan iguales. Si después se actualiza el costo desde el proveedor, el aumento se reemplaza.'],
            ['etiqueta' => 'Atención', 'valor' => '2 no tienen costo: no cambian'],
        ], $accion->presentacion['renglones']);

        $manual = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($manual));
        $this->assertStringContainsString('Ninguno de los 3 artículos usa precio manual', $manual['error'], 'Con listas, ni el que tiene precio cargado lo usa.');
        $this->assertStringContainsString('precio_final', $manual['error']);
    }

    /**
     * 🔴 Un porcentaje sobre null no escribe 0: antes guardaba `price = 0.00` sin registrarlo, y en un
     * artículo sin margen eso se leía después como un precio manual de $0.
     *
     * @group chat-ia
     * @test
     */
    public function subir_o_bajar_un_porcentaje_sobre_null_no_escribe_cero()
    {
        $articulo = $this->articulo(['cost' => 1000, 'percentage_gain' => 30]);
        $sin_margen = $this->articulo(['cost' => 1000]);

        $this->assertNull(MasiveUpdateHelper::apply_form_change($articulo, ['type' => 'number', 'key' => 'increment_price', 'value' => 10]));
        $this->assertNull(MasiveUpdateHelper::apply_form_change($articulo, ['type' => 'number', 'key' => 'decrement_price', 'value' => 10, 'round' => true]));
        $this->assertNull(MasiveUpdateHelper::apply_form_change($sin_margen, ['type' => 'number', 'key' => 'increment_percentage_gain', 'value' => 10]));

        $this->assertNull(DB::table('articles')->where('id', $articulo->id)->value('price'), 'Sigue null, no 0.00.');
        $this->assertNull(DB::table('articles')->where('id', $sin_margen->id)->value('percentage_gain'));

        // Con valor, el porcentaje se sigue aplicando como siempre.
        $cambio = MasiveUpdateHelper::apply_form_change($articulo, ['type' => 'number', 'key' => 'increment_cost', 'value' => 10]);
        $this->assertSame('increment', $cambio['operation']);
        $this->assertEqualsWithDelta(1100, (float) DB::table('articles')->where('id', $articulo->id)->value('cost'), 0.0001);
    }

    /**
     * El aviso final de una masiva que no cambió nada lo dice, y cuántos alcanzó. Una que sí cambió
     * sigue diciendo "finalizó correctamente", con los alcanzados cuando difieren de los afectados.
     *
     * @group chat-ia
     * @test
     */
    public function el_aviso_de_una_masiva_sin_cambios_dice_que_no_cambio_nada()
    {
        Notification::fake();

        $sin_cambios = MasiveUpdate::create([
            'user_id'        => $this->comercio->id,
            'employee_id'    => $this->comercio->id,
            'model_name'     => 'article',
            'action'         => 'update',
            'status'         => 'completed',
            'from_filter'    => true,
            'criteria_json'  => json_encode(['resolved_models_id' => [11, 12, 13, 14, 15]]),
            'affected_count' => 0,
            'changes_count'  => 0,
        ]);

        MasiveUpdateHelper::notify_result($sin_cambios, true);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'La actualización masiva de artículos terminó sin cambiar ningún artículo'
                && $notificacion->color_variant === 'warning'
                && $notificacion->info_to_show[0]['parrafos'] === [
                    'Alcanzó a 5 artículos, pero ninguno cambió: ya tenían esos valores o el cambio no se les aplica.',
                    'Registros afectados: 0',
                    'Cambios aplicados: 0',
                ];
        });

        $con_cambios = MasiveUpdate::create([
            'user_id'        => $this->comercio->id,
            'employee_id'    => $this->comercio->id,
            'model_name'     => 'article',
            'action'         => 'update',
            'status'         => 'completed',
            'from_filter'    => true,
            'criteria_json'  => json_encode(['resolved_models_id' => [11, 12, 13, 14, 15]]),
            'affected_count' => 3,
            'changes_count'  => 3,
        ]);

        MasiveUpdateHelper::notify_result($con_cambios, true);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'La actualización masiva de artículos finalizó correctamente'
                && $notificacion->color_variant === 'success'
                && $notificacion->info_to_show[0]['parrafos'] === [
                    'Artículos alcanzados: 5',
                    'Registros afectados: 3',
                    'Cambios aplicados: 3',
                ];
        });

        // La reversión en 0 sigue como siempre.
        $reversion = MasiveUpdate::create([
            'user_id'        => $this->comercio->id,
            'employee_id'    => $this->comercio->id,
            'model_name'     => 'article',
            'action'         => 'revert',
            'status'         => 'completed',
            'from_filter'    => false,
            'criteria_json'  => json_encode(['revert_of_masive_update_id' => $con_cambios->id]),
            'affected_count' => 0,
            'changes_count'  => 0,
        ]);

        MasiveUpdateHelper::notify_result($reversion, true);

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'La reversión de la actualización masiva finalizó correctamente'
                && $notificacion->color_variant === 'success';
        });
    }
}
