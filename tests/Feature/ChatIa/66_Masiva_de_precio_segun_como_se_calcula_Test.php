<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\precios\PrecioFinalEnMasivaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Jobs\ProcessMasiveUpdateJob;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\Category;
use App\Models\ExtencionEmpresa;
use App\Models\MasiveUpdate;
use App\Models\PriceType;
use App\Models\Provider;
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
 * Lo que protege: que la operación "precio final sube X %" mueva cada artículo con su palanca (precio
 * manual, margen o costo, según el comercio y el proveedor) y se pueda revertir; que un precio manual
 * que no mueve a nadie se convierta en precio final; que precio final vaya solo en su tarjeta; que la
 * tarjeta cuente cómo se aplica (y avise lo que no cambia, lo que quedaría por debajo del costo, la
 * suba de costo que se reemplaza y las listas fijadas a mano); que un porcentaje sobre null no
 * escriba 0; y que el aviso final diga cuando la masiva no cambió nada.
 *
 * 🔴 Sin red: la clave de Anthropic va en null y la cola se falsea donde se encola.
 */
class Masiva_de_precio_segun_como_se_calcula_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'asistente_ia';

    const CATEGORIA = 'Adhesivos P66';

    /** @var User */
    protected $comercio;

    /** @var Category */
    protected $adhesivos;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio masiva P66',
            'company_name'     => 'Ferreteria P66',
            'email'            => 'masiva-p66-' . uniqid() . '@test.local',
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
            'name'        => 'zz-p66-' . uniqid(),
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
     * @param  string  $slug
     * @return void
     */
    protected function dar_extension($slug = self::SLUG)
    {
        $extencion = ExtencionEmpresa::where('slug', $slug)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => $slug, 'name' => $slug]);
        }

        if (!$this->comercio->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->comercio->extencions()->attach($extencion->id);
        }

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
     * Propone en una conversación nueva.
     *
     * @param  array  $cambios
     * @return array  La respuesta de la herramienta.
     */
    protected function proponer(array $cambios)
    {
        list($conversation, $assistant) = $this->conversacion();

        return PropuestaActualizacionMasivaIaHelper::proponer(
            ContextoDeCargaIa::de_la_conversacion($conversation),
            $assistant,
            $this->input($cambios)
        );
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
     * Los casos de la regla con precio único: precio manual $1000, costo 1000 + 30 %, costo 1000 sin
     * margen, uno sin costo ni precio y uno con el precio manual en $0. Con el precio final calculado.
     *
     * @return array<string, Article>
     */
    protected function catalogo_mixto()
    {
        return [
            'manual'     => $this->con_precio($this->articulo(['name' => 'zz-p66 manual', 'price' => 1000])),
            'con_margen' => $this->con_precio($this->articulo(['name' => 'zz-p66 con margen', 'cost' => 1000, 'percentage_gain' => 30])),
            'sin_margen' => $this->con_precio($this->articulo(['name' => 'zz-p66 sin margen', 'cost' => 1000])),
            'sin_precio' => $this->con_precio($this->articulo(['name' => 'zz-p66 sin precio'])),
            'en_cero'    => $this->con_precio($this->articulo(['name' => 'zz-p66 manual en cero', 'price' => 0])),
        ];
    }

    /**
     * Propone y confirma por el endpoint de la tarjeta, sin correr el job.
     *
     * @param  array  $cambios
     * @return array{0: array, 1: MasiveUpdate}  La respuesta de proponer y la masiva encolada.
     */
    protected function confirmar_por_la_tarjeta(array $cambios)
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

        return [$respuesta, $masiva];
    }

    /**
     * Propone, confirma por el endpoint de la tarjeta y corre el job a mano.
     *
     * @param  array  $cambios
     * @return array{0: array, 1: MasiveUpdate}  La respuesta de proponer y la masiva ya procesada.
     */
    protected function aplicar_por_la_tarjeta(array $cambios)
    {
        list($respuesta, $masiva) = $this->confirmar_por_la_tarjeta($cambios);

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        return [$respuesta, $masiva->fresh()];
    }

    /**
     * Los renglones de la tarjeta de una respuesta de proponer.
     *
     * @param  array  $respuesta
     * @return array
     */
    protected function renglones(array $respuesta)
    {
        return AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['renglones'];
    }

    /**
     * Cuántas tarjetas dejaron las conversaciones del comercio.
     *
     * @return int
     */
    protected function tarjetas()
    {
        return AiMessageAction::whereIn('ai_conversation_id', AiConversation::where('user_id', $this->comercio->id)->pluck('id'))->count();
    }

    /**
     * El precio de después es el de antes por el factor. Con tolerancia de centavos, o de una
     * centésima de punto cuando la palanca es el margen: se guarda con 2 decimales.
     *
     * @param  float|string  $antes
     * @param  float|string  $despues
     * @param  float  $factor
     * @param  string  $mensaje
     * @return void
     */
    protected function assertSubio($antes, $despues, $factor, $mensaje = '')
    {
        $esperado = (float) $antes * $factor;

        $this->assertEqualsWithDelta($esperado, (float) $despues, max(0.01, $esperado * 0.0001), $mensaje);
    }

    /**
     * 🔴 El de demo: "precio manual sube 10 % (redondeado)" sobre artículos de costo + margen no le
     * movía el precio a ninguno. Ahora se convierte en precio final (con el renglón que lo dice), sin
     * el redondeo (no hay precios manuales) y, confirmado, cada precio final sube 10 %. Con montos no
     * redondos: costo 1234,56 y margen 33,33.
     *
     * @group chat-ia
     * @test
     */
    public function el_de_demo_precio_manual_sobre_costo_mas_margen_se_convierte_en_precio_final()
    {
        $a = $this->con_precio($this->articulo(['cost' => 1234.56, 'percentage_gain' => 33.33]));
        $b = $this->con_precio($this->articulo(['cost' => 2000, 'percentage_gain' => 50]));
        $c = $this->con_precio($this->articulo(['cost' => 500, 'percentage_gain' => 40]));

        list($respuesta, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
        ]);

        $convertido = 'Ninguno de estos 3 artículos usa precio manual: se sube el precio final';
        $como = 'En 3 artículos se ajusta el margen para que el precio final suba 10 %';

        $this->assertSame(['Precio final sube 10 %'], $respuesta['cambios_legibles'], 'Sin precios manuales, sin "(redondeado…)".');
        $this->assertSame([$convertido, $como], $respuesta['avisos']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '3'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final sube 10 %'],
            ['etiqueta' => 'Atención', 'valor' => $convertido],
            ['etiqueta' => 'Cómo', 'valor' => $como],
        ], $this->renglones($respuesta));

        $form = [
            'type'  => PrecioFinalEnMasivaHelper::TIPO,
            'key'   => PrecioFinalEnMasivaHelper::SUBIR,
            'value' => 10,
            'label' => 'Aumentar el precio final',
        ];
        $this->assertSame([$form], AiMessageAction::find($respuesta['tarjeta_id'])->datos['update_form'], 'Sin `round`: no hay precios manuales.');

        $this->assertSame(3, (int) $masiva->affected_count);
        $this->assertSame(3, (int) $masiva->changes_count);

        $this->assertEquals(46.66, (float) $a->fresh()->percentage_gain, '(1,3333 × 1,10 − 1) = 46,663 → 46,66 %.');
        $this->assertEquals(65, (float) $b->fresh()->percentage_gain);
        $this->assertEquals(54, (float) $c->fresh()->percentage_gain);

        foreach ([$a, $b, $c] as $antes) {
            $despues = $antes->fresh();
            $this->assertNull(DB::table('articles')->where('id', $antes->id)->value('price'), 'El precio manual sigue null (nada de 0.00).');
            $this->assertSubio($antes->final_price, $despues->final_price, 1.1);
        }
    }

    /**
     * Fijar el precio manual a artículos con margen no se usaría: error con un texto para el dueño,
     * sin jerga de la herramienta.
     *
     * @group chat-ia
     * @test
     */
    public function setear_el_precio_manual_a_articulos_con_margen_es_un_error_para_el_dueno()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 25]));

        $respuesta = $this->proponer([
            ['campo' => 'precio_manual', 'operacion' => 'setear', 'valor' => 1500],
        ]);

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertSame(
            'Ninguno de estos 2 artículos usa precio manual: su precio sale del costo más el margen, así que un precio fijado a mano no se usaría. Para fijarles un precio a mano hay que sacarles el margen en la ficha de cada artículo.',
            $respuesta['error']
        );
        $this->assertStringNotContainsString('precio_final', $respuesta['error']);
        $this->assertStringNotContainsString('contáselo', $respuesta['error']);
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
        $this->con_precio($this->articulo(['name' => 'zz-p66 manual', 'price' => 1000]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 20]));

        $respuesta = $this->proponer([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $aviso = '2 de 3 artículos no usan precio manual: este cambio no les mueve el precio';

        $this->assertSame([$aviso], $respuesta['avisos']);

        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '3'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio manual sube 10 %'],
            ['etiqueta' => 'Atención', 'valor' => $aviso],
        ], $this->renglones($respuesta));
        $this->assertSame([['type' => 'number', 'key' => 'increment_price', 'value' => 10]], AiMessageAction::find($respuesta['tarjeta_id'])->datos['update_form']);
    }

    /**
     * precio_final con precio único: el manual sube su precio (redondeado), los de costo (con o sin
     * margen) ajustan el margen, y los sin costo ni precio y el de precio manual en $0 quedan igual.
     * Confirmado por el endpoint y con el job corrido a mano, cada precio final sube 10 %.
     *
     * @group chat-ia
     * @test
     */
    public function precio_final_mueve_cada_articulo_con_su_palanca()
    {
        $c = $this->catalogo_mixto();

        $this->assertEquals(1000, (float) $c['manual']->final_price, 'El manual cobra su precio.');
        $this->assertNull($c['sin_precio']->final_price);

        list($respuesta, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10, 'redondear' => true],
        ]);

        $como = 'En 2 artículos se ajusta el margen y en 1 sube el precio manual, para que el precio final suba 10 %';
        $sin_precio = '1 no tiene costo ni precio cargado: no cambia';
        $en_cero = '1 tiene el precio manual en $0: no cambia';

        $this->assertSame([$como, $sin_precio, $en_cero], $respuesta['avisos']);
        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '5'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final sube 10 % (redondeado en los de precio manual)'],
            ['etiqueta' => 'Cómo', 'valor' => $como],
            ['etiqueta' => 'Atención', 'valor' => $sin_precio],
            ['etiqueta' => 'Atención', 'valor' => $en_cero],
        ], $this->renglones($respuesta));

        $form = [
            'type'  => PrecioFinalEnMasivaHelper::TIPO,
            'key'   => PrecioFinalEnMasivaHelper::SUBIR,
            'value' => 10,
            'label' => 'Aumentar el precio final',
            'round' => true,
        ];
        $this->assertSame([$form], AiMessageAction::find($respuesta['tarjeta_id'])->datos['update_form']);

        // El update_form llega tal cual a la masiva, con el label que lee el historial.
        $this->assertSame([$form], json_decode($masiva->criteria_json, true)['update_form']);

        $this->assertSame('completed', $masiva->status);
        $this->assertSame(3, (int) $masiva->affected_count);
        $this->assertSame(3, (int) $masiva->changes_count);

        $manual = $c['manual']->fresh();
        $this->assertEquals(1100, (float) $manual->price, 'El manual sube su precio.');
        $this->assertEqualsWithDelta(1100, (float) $manual->final_price, 0.01);

        $con_margen = $c['con_margen']->fresh();
        $this->assertEquals(43, (float) $con_margen->percentage_gain, '(1,30 × 1,10 − 1) = 43 %.');
        $this->assertNull($con_margen->price);
        $this->assertSubio($c['con_margen']->final_price, $con_margen->final_price, 1.1);

        $sin_margen = $c['sin_margen']->fresh();
        $this->assertEquals(10, (float) $sin_margen->percentage_gain, 'Sin margen, el margen nuevo es el porcentaje.');
        $this->assertSubio($c['sin_margen']->final_price, $sin_margen->final_price, 1.1);

        $sin_precio = DB::table('articles')->where('id', $c['sin_precio']->id)->first();
        $this->assertNull($sin_precio->price, 'Sin costo ni precio no se escribe nada (ni un 0).');
        $this->assertNull($sin_precio->percentage_gain);
        $this->assertNull($sin_precio->final_price);

        $en_cero = DB::table('articles')->where('id', $c['en_cero']->id)->first();
        $this->assertEquals(0, (float) $en_cero->price);
        $this->assertNull($en_cero->percentage_gain);

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
     * Al bajar, la tarjeta avisa cuántos quedarían por debajo de su costo: por margen
     * (`base_margen × (1 + margen nuevo)` contra el costo real) y por precio manual (el precio nuevo
     * contra el costo real).
     *
     * @group chat-ia
     * @test
     */
    public function bajar_el_precio_final_avisa_los_que_quedarian_por_debajo_de_su_costo()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 10]));
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 50]));
        $this->con_precio($this->articulo(['name' => 'zz-p66 manual caro', 'price' => 1000, 'cost' => 950]));
        $this->con_precio($this->articulo(['name' => 'zz-p66 manual con aire', 'price' => 1000, 'cost' => 500]));

        $respuesta = $this->proponer([
            ['campo' => 'precio_final', 'operacion' => 'bajar_porcentaje', 'valor' => 20],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));

        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '4'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final baja 20 %'],
            ['etiqueta' => 'Cómo', 'valor' => 'En 2 artículos se ajusta el margen y en 2 baja el precio manual, para que el precio final baje 20 %'],
            ['etiqueta' => 'Atención', 'valor' => '2 quedarían por debajo de su costo'],
        ], $this->renglones($respuesta), 'El de margen 10 (queda en −12 %) y el manual de costo 950 (queda en 800).');

        $this->assertSame(-12.0, PrecioFinalEnMasivaHelper::nuevo_margen(10, 20, false), '(1,10 × 0,80 − 1) = −12 %.');

        // Setear o bajar 100 % o más no son operaciones de precio final.
        $setear = $this->proponer([['campo' => 'precio_final', 'operacion' => 'setear', 'valor' => 1500]]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($setear));
        $this->assertStringContainsString('El precio final se calcula, no se fija', $setear['error']);

        $todo = $this->proponer([['campo' => 'precio_final', 'operacion' => 'bajar_porcentaje', 'valor' => 100]]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($todo));
    }

    /**
     * 🔴 Con listas de precio el margen del artículo no mueve las listas y el precio manual no se usa:
     * el precio final sube el COSTO. De punta a punta: el precio manual se convierte en precio final,
     * la masiva sube el costo, la lista calculada sube 10 % y la lista fijada a mano no cambia.
     * También: setear el precio manual es error, y un cambio de margen avisa que no mueve las listas.
     *
     * @group chat-ia
     * @test
     */
    public function con_listas_de_precio_el_precio_final_sube_el_costo_de_punta_a_punta()
    {
        $this->comercio->listas_de_precio = 1;
        $this->comercio->save();

        $mayorista = PriceType::create(['name' => 'zz Mayorista P66', 'user_id' => $this->comercio->id, 'percentage' => 20, 'position' => 1]);

        $a = $this->con_precio($this->articulo(['name' => 'zz-p66 lista a', 'cost' => 1000, 'percentage_gain' => 30]));
        $b = $this->con_precio($this->articulo(['name' => 'zz-p66 lista b', 'cost' => 1234.56, 'percentage_gain' => 33.33]));
        $sin_costo = $this->con_precio($this->articulo(['name' => 'zz-p66 lista manual', 'price' => 1000]));
        $fijado = $this->con_precio($this->articulo(['name' => 'zz-p66 lista fijada', 'cost' => 500]));

        DB::table('article_price_type')
            ->where('article_id', $fijado->id)
            ->where('price_type_id', $mayorista->id)
            ->update(['setear_precio_final' => 1, 'final_price' => 7777]);

        $lista_de_a = function () use ($a, $mayorista) {
            return (float) DB::table('article_price_type')->where('article_id', $a->id)->where('price_type_id', $mayorista->id)->value('final_price');
        };

        $lista_a_antes = $lista_de_a();
        $this->assertGreaterThan(0, $lista_a_antes, 'Guarda: la lista calculada tenía que tener precio.');

        // Setear el precio manual con listas: no se usa.
        $setear = $this->proponer([['campo' => 'precio_manual', 'operacion' => 'setear', 'valor' => 1500]]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($setear));
        $this->assertSame('Con listas de precio el precio manual no se usa: el precio de cada lista sale del costo.', $setear['error']);

        // Un cambio de margen con listas avisa que no mueve las listas.
        $margen = $this->proponer([['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 40]]);
        $this->assertTrue($margen['ok'], json_encode($margen));
        $this->assertSame(['Con listas de precio, el margen del artículo no cambia el precio de las listas'], $margen['avisos']);

        // El precio manual con listas no mueve a nadie: se convierte en precio final, y se aplica.
        list($respuesta, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertSame([
            ['etiqueta' => 'Artículos alcanzados', 'valor' => '4'],
            ['etiqueta' => 'Categoría', 'valor' => self::CATEGORIA],
            ['etiqueta' => 'Cambio', 'valor' => 'Precio final sube 10 %'],
            ['etiqueta' => 'Atención', 'valor' => 'Ninguno de estos 4 artículos usa precio manual: se sube el precio final'],
            ['etiqueta' => 'Cómo', 'valor' => 'En 3 artículos sube el costo para que el precio final suba 10 %'],
            ['etiqueta' => 'Atención', 'valor' => '1 no tiene costo ni precio cargado: no cambia'],
            ['etiqueta' => 'Atención', 'valor' => 'Si después se actualiza el costo desde el proveedor, la suba de esos 3 se reemplaza.'],
            ['etiqueta' => 'Atención', 'valor' => '1 tiene el precio de alguna lista fijado a mano: esa lista no cambia'],
        ], $this->renglones($respuesta));

        $this->assertSame(3, (int) $masiva->affected_count);

        $this->assertEqualsWithDelta(1100, (float) $a->fresh()->cost, 0.000001);
        $this->assertEqualsWithDelta(1358.016, (float) $b->fresh()->cost, 0.000001, '1234,56 × 1,10.');
        $this->assertEqualsWithDelta(550, (float) $fijado->fresh()->cost, 0.000001);
        $this->assertEquals(30, (float) $a->fresh()->percentage_gain, 'Los márgenes quedan iguales.');
        $this->assertEquals(33.33, (float) $b->fresh()->percentage_gain);
        $this->assertNull($sin_costo->fresh()->cost);

        $this->assertSubio($lista_a_antes, $lista_de_a(), 1.1, 'La lista calculada sube 10 %.');
        $this->assertEquals(7777, (float) DB::table('article_price_type')->where('article_id', $fijado->id)->where('price_type_id', $mayorista->id)->value('final_price'), 'La lista fijada a mano no cambia.');

        $this->assertSame(['cost'], array_keys(json_decode($masiva->articles()->where('articles.id', $a->id)->first()->pivot->changes_json, true)));
    }

    /**
     * Con la extensión de listas por categoría las listas también salen de la base de antes del margen
     * del artículo: la palanca es el costo.
     *
     * @group chat-ia
     * @test
     */
    public function con_listas_por_categoria_el_precio_final_sube_el_costo()
    {
        $this->dar_extension('lista_de_precios_por_categoria');

        $this->articulo(['cost' => 1000, 'percentage_gain' => 30]);

        $respuesta = $this->proponer([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame([
            'En 1 artículo sube el costo para que el precio final suba 10 %',
            'Si después se actualiza el costo desde el proveedor, la suba de ese artículo se reemplaza.',
        ], $respuesta['avisos']);
    }

    /**
     * 🔴 Con un proveedor de costo de lista + IVA (`price_from_cost_mas_iva`) el margen del artículo
     * no entra en el precio: la palanca es el costo, y el precio final sube 10 % por process_update.
     *
     * @group chat-ia
     * @test
     */
    public function con_proveedor_de_costo_mas_iva_el_precio_final_sube_el_costo()
    {
        $proveedor = Provider::create(['name' => 'zz Lista mas IVA P66', 'user_id' => $this->comercio->id, 'price_from_cost_mas_iva' => 1]);

        $articulo = $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30, 'provider_id' => $proveedor->id]));

        $this->assertGreaterThan(0, (float) $articulo->final_price);

        list($respuesta, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertSame('En 1 artículo sube el costo para que el precio final suba 10 %', $respuesta['avisos'][0]);
        $this->assertSame(1, (int) $masiva->affected_count);

        $despues = $articulo->fresh();
        $this->assertEqualsWithDelta(1100, (float) $despues->cost, 0.000001);
        $this->assertEquals(30, (float) $despues->percentage_gain, 'El margen no se toca: acá no entra en el precio.');
        $this->assertSubio($articulo->final_price, $despues->final_price, 1.1);
    }

    /**
     * Un proveedor con margen y artículos sin margen propio: manda el margen del proveedor, así que la
     * palanca es el margen del artículo. En la masiva el proveedor se lee por la memoria de la corrida
     * (los artículos llegan sin la relación cargada).
     *
     * @group chat-ia
     * @test
     */
    public function con_margen_del_proveedor_y_sin_margen_propio_se_ajusta_el_margen()
    {
        $proveedor = Provider::create(['name' => 'zz Con margen P66', 'user_id' => $this->comercio->id, 'percentage_gain' => 25]);

        $uno = $this->con_precio($this->articulo(['cost' => 1000, 'provider_id' => $proveedor->id]));
        $dos = $this->con_precio($this->articulo(['cost' => 1234.56, 'provider_id' => $proveedor->id]));

        list($respuesta) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]);

        $this->assertSame('En 2 artículos se ajusta el margen para que el precio final suba 10 %', $respuesta['avisos'][0]);

        foreach ([$uno, $dos] as $antes) {
            $despues = $antes->fresh();
            $this->assertEquals(10, (float) $despues->percentage_gain);
            $this->assertNull($despues->price);
            $this->assertSubio($antes->final_price, $despues->final_price, 1.1);
        }
    }

    /**
     * precio_final va solo en su tarjeta; un precio manual que se convertiría también. Y con el margen
     * cambiando en la misma tarjeta, el precio manual no se analiza (no se puede predecir).
     *
     * @group chat-ia
     * @test
     */
    public function precio_final_con_otro_cambio_en_la_misma_tarjeta_es_un_error()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));

        $combinado = $this->proponer([
            ['campo' => 'precio_final', 'operacion' => 'subir_porcentaje', 'valor' => 10],
            ['campo' => 'en_tienda', 'operacion' => 'activar'],
        ]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($combinado));
        $this->assertSame('Subir o bajar el precio va en una tarjeta aparte: primero ese cambio y después los demás.', $combinado['error']);

        $manual_combinado = $this->proponer([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
            ['campo' => 'en_tienda', 'operacion' => 'activar'],
        ]);
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($manual_combinado));
        $this->assertSame('Subir o bajar el precio va en una tarjeta aparte: primero ese cambio y después los demás.', $manual_combinado['error']);

        $this->assertSame(0, $this->tarjetas());

        $con_margen = $this->proponer([
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
            ['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 0],
        ]);
        $this->assertTrue($con_margen['ok'], json_encode($con_margen));
        $this->assertArrayNotHasKey('avisos', $con_margen, 'Con el margen cambiando en la misma tarjeta no se predice nada.');
        $this->assertSame(
            [['type' => 'number', 'key' => 'increment_price', 'value' => 10], ['type' => 'number', 'key' => 'set_percentage_gain', 'value' => 0]],
            AiMessageAction::find($con_margen['tarjeta_id'])->datos['update_form']
        );
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
     * 🔴 El aviso de una masiva real que no cambió nada (el job de verdad: process_update() y
     * notify_result()) lo dice, y cuántos alcanzó.
     *
     * @group chat-ia
     * @test
     */
    public function el_aviso_de_una_masiva_real_sin_cambios_dice_que_no_cambio_nada()
    {
        $this->con_precio($this->articulo(['cost' => 1000, 'percentage_gain' => 30]));
        $this->con_precio($this->articulo(['cost' => 2000, 'percentage_gain' => 30]));

        list(, $masiva) = $this->confirmar_por_la_tarjeta([
            ['campo' => 'margen_de_ganancia', 'operacion' => 'setear', 'valor' => 30],
        ]);

        Notification::fake();

        (new ProcessMasiveUpdateJob($masiva->id))->handle();

        $masiva = $masiva->fresh();
        $this->assertSame('completed', $masiva->status);
        $this->assertSame(0, (int) $masiva->affected_count, 'Los dos ya tenían 30 %.');

        Notification::assertSentTo($this->comercio, GlobalNotification::class, function ($notificacion) {
            return $notificacion->message_text === 'La actualización masiva de artículos terminó sin cambiar ningún artículo'
                && $notificacion->color_variant === 'warning'
                && $notificacion->info_to_show[0]['parrafos'] === [
                    'Alcanzó a 2 artículos, pero ninguno cambió: ya tenían esos valores o el cambio no se les aplica.',
                    'Registros afectados: 0',
                    'Cambios aplicados: 0',
                ];
        });
    }

    /**
     * Las demás formas del aviso final: con cambios sigue diciendo "finalizó correctamente", con los
     * alcanzados cuando difieren de los afectados, y la reversión en 0 sigue como siempre.
     *
     * @group chat-ia
     * @test
     */
    public function el_aviso_de_una_masiva_con_cambios_y_de_una_reversion_sigue_como_siempre()
    {
        Notification::fake();

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
