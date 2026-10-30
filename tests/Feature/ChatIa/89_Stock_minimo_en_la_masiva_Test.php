<?php

namespace Tests\Feature\ChatIa;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\article\StockMinimoMaximoEnMasivaHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaActualizacionMasivaIaHelper;
use App\Http\Controllers\Helpers\asistente_ia\RespuestaDeCargaIa;
use App\Models\Address;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiMessageAction;
use App\Models\Article;
use App\Models\ExtencionEmpresa;
use App\Models\MasiveUpdate;
use App\Models\PermissionEmpresa;
use App\Models\Provider;
use App\Models\UnidadMedida;
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
 * Misión agente-mejoras-uso-real (E2 punto 1, 29/10/2026) — el stock mínimo (y máximo) en la
 * actualización masiva del agente.
 *
 * El caso real: Pablo (Ferre Total, CONV 14) pidió «a todos los clavos punta París poneles stock mínimo
 * 10». El agente le dijo que la masiva no admitía el mínimo y le ofreció setear el STOCK en 10. Lo que
 * protege:
 *  - con 0 o 1 sucursal, la tarjeta pone `articles.stock_min` (nunca el stock), el job lo escribe sin
 *    ningún movimiento de stock y la reversión lo deja como estaba; el máximo, que ahí no existe, se dice;
 *  - con 2 o más sucursales, la tarjeta pide la sucursal (o "todas"), escribe el mínimo en el pivote de
 *    cada una sin pisar el máximo, saltea (y avisa) a los que no tienen stock repartido ahí, y la
 *    reversión deja el pivote como estaba;
 *  - los permisos: sin `article.stock_min_max` no se propone, y con "solo su sucursal" "todas" queda en
 *    la suya y lo avisa;
 *  - el mínimo con la unidad de medida en la misma tarjeta (el pedido literal de Pablo) anda, y con un
 *    cambio de precio va aparte;
 *  - la descripción de la herramienta prohíbe ofrecer el stock en lugar del mínimo.
 *
 * 🔴 Sin red: la clave de Anthropic va en null y la cola se falsea donde se encola.
 */
class Stock_minimo_en_la_masiva_Test extends TestCase
{
    use DatabaseTransactions;

    const SLUG = 'agentes_ia';

    /** @var User */
    protected $comercio;

    /** @var Article[] Los clavos punta París (los que alcanza el filtro). */
    protected $clavos = [];

    /** @var Article Un tornillo: nunca lo alcanza el filtro. */
    protected $tornillo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => null]);

        $this->comercio = User::create([
            'name'             => 'Comercio minimo E2',
            'company_name'     => 'Ferreteria minimo E2',
            'email'            => 'minimo-e2-' . uniqid() . '@test.local',
            'password'         => Hash::make('secret'),
            'agente_confianza' => 'cauteloso',
        ]);

        $proveedor = Provider::create(['name' => 'Bulonera minimo E2', 'user_id' => $this->comercio->id]);

        foreach (['CLAVO PUNTA PARIS 2" X KG', 'CLAVO PUNTA PARIS 2 1/2" X KG', 'CLAVO PUNTA PARIS 3" X KG'] as $nombre) {
            $this->clavos[] = $this->articulo($nombre, $proveedor->id);
        }

        $this->tornillo = $this->articulo('TORNILLO DRYWALL 6 X 2', $proveedor->id);
    }

    /**
     * @param  string  $nombre
     * @param  int  $provider_id
     * @return Article
     */
    protected function articulo($nombre, $provider_id)
    {
        return Article::create([
            'name'            => $nombre,
            'user_id'         => $this->comercio->id,
            'status'          => 'active',
            'cost'            => 1000,
            'percentage_gain' => 30,
            'stock'           => 50,
            'provider_id'     => $provider_id,
        ]);
    }

    /**
     * @param  string  $nombre
     * @return Address
     */
    protected function sucursal($nombre)
    {
        return Address::create(['street' => $nombre, 'user_id' => $this->comercio->id]);
    }

    /**
     * Le da la extensión del agente al comercio (la piden las rutas del chat).
     *
     * @return void
     */
    protected function dar_extension()
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            $extencion = ExtencionEmpresa::forceCreate(['slug' => self::SLUG, 'name' => 'Agentes de IA']);
        }

        $this->comercio->extencions()->attach($extencion->id);
        $this->comercio->load('extencions');
    }

    /**
     * Un empleado del comercio con los permisos que se le pasen por slug.
     *
     * @param  array<int, string>  $slugs
     * @param  int|null  $address_id
     * @return User
     */
    protected function empleado(array $slugs, $address_id = null)
    {
        $empleado = User::create([
            'name'       => 'Empleado minimo E2',
            'email'      => 'minimo-e2-emp-' . uniqid() . '@test.local',
            'password'   => Hash::make('secret'),
            'owner_id'   => $this->comercio->id,
            'address_id' => $address_id,
        ]);

        $ids = [];

        foreach ($slugs as $slug) {
            $permiso = PermissionEmpresa::where('slug', $slug)->first();

            if (is_null($permiso)) {
                $permiso = PermissionEmpresa::forceCreate(['name' => $slug, 'slug' => $slug, 'model_name' => 'article']);
            }

            $ids[] = $permiso->id;
        }

        $empleado->permissions()->sync($ids);
        $empleado->load('permissions');

        return $empleado;
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
            'contenido'          => 'Necesito que a todos los clavos punta París les pongas como stock mínimo 10',
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
     * El input de "a los clavos punta París" con los cambios dados.
     *
     * @param  array  $cambios
     * @return array
     */
    protected function input(array $cambios)
    {
        return [
            'filtros' => [['campo' => 'nombre', 'operador' => 'contiene', 'valor' => 'clavo punta paris']],
            'cambios' => $cambios,
        ];
    }

    /**
     * Propone, confirma por el endpoint de la tarjeta y corre el job a mano.
     *
     * @param  array  $cambios
     * @return array{0: array, 1: MasiveUpdate}
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

        Auth::forgetGuards();
        $this->actingAs($this->comercio, 'web');

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')
            ->assertStatus(200);

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();
        $this->assertNotNull($masiva);

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame('completed', $masiva->fresh()->status);

        return [$respuesta, $masiva->fresh()];
    }

    /**
     * El pivote (artículo, sucursal) leído de la base.
     *
     * @param  Article  $articulo
     * @param  Address  $sucursal
     * @return object|null
     */
    protected function pivote(Article $articulo, Address $sucursal)
    {
        return DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $sucursal->id)->first();
    }

    // =====================================================================
    // 0 o 1 sucursal: articles.stock_min
    // =====================================================================

    /**
     * @group chat-ia
     * @test
     */
    public function sin_sucursales_la_tarjeta_pone_el_minimo_del_articulo_y_nunca_el_stock()
    {
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(3, $respuesta['articulos_alcanzados']);
        $this->assertSame(['Stock mínimo → 10'], $respuesta['cambios_legibles']);
        $this->assertContains(PropuestaActualizacionMasivaIaHelper::AVISO_DEL_MINIMO, $respuesta['avisos']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame([['type' => 'number', 'key' => 'set_stock_min', 'value' => 10]], $accion->datos['update_form']);
        $this->assertContains(['etiqueta' => 'Cambio', 'valor' => 'Stock mínimo → 10'], $accion->presentacion['renglones']);
        $this->assertStringContainsString('Alertas', $accion->presentacion['aviso']);

        // Nada se aplicó todavía.
        $this->assertNull($this->clavos[0]->fresh()->stock_min);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_sucursales_el_job_escribe_el_minimo_sin_mover_stock_y_la_reversion_lo_deja_como_estaba()
    {
        $this->clavos[1]->stock_min = 4;
        $this->clavos[1]->save();

        $movimientos_antes = DB::table('stock_movements')->whereIn('article_id', array_map(function ($a) {
            return $a->id;
        }, $this->clavos))->count();

        list(, $masiva) = $this->aplicar_por_la_tarjeta([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10],
        ]);

        foreach ($this->clavos as $clavo) {
            $fresco = $clavo->fresh();

            $this->assertSame(10, (int) $fresco->stock_min, $clavo->name . ' tenía que quedar con mínimo 10.');
            $this->assertEquals(50, (float) $fresco->stock, 'El stock NO se toca.');
        }

        $this->assertNull($this->tornillo->fresh()->stock_min, 'El tornillo no estaba alcanzado.');

        $movimientos_despues = DB::table('stock_movements')->whereIn('article_id', array_map(function ($a) {
            return $a->id;
        }, $this->clavos))->count();

        $this->assertSame($movimientos_antes, $movimientos_despues, 'Un mínimo no genera ningún movimiento de stock.');

        $this->assertTrue(MasiveUpdateHelper::can_revert($masiva));

        $reversion = MasiveUpdateHelper::create_pending_revert($masiva, $this->comercio->id);
        MasiveUpdateHelper::process_revert($reversion->fresh(), $masiva->fresh());

        $this->assertNull($this->clavos[0]->fresh()->stock_min, 'Volvió a null.');
        $this->assertSame(4, (int) $this->clavos[1]->fresh()->stock_min, 'Volvió a 4.');
    }

    /**
     * @group chat-ia
     * @test
     */
    public function sin_sucursales_el_maximo_no_existe_y_se_dice()
    {
        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_maximo', 'operacion' => 'setear', 'valor' => 40],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertStringContainsString('solo el stock mínimo', $respuesta['error']);

        // Un mínimo con decimales o negativo tampoco.
        $decimal = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10.5],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($decimal));

        $negativo = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => -1],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($negativo));

        $subir = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($subir));
    }

    /**
     * El pedido literal de Pablo: «cambiale la unidad de medida a kilos y poneme diez kilos como stock
     * mínimo», en UNA tarjeta. Y con un cambio de precio, el mínimo va aparte.
     *
     * @group chat-ia
     * @test
     */
    public function el_minimo_con_la_unidad_de_medida_va_en_una_tarjeta_y_con_un_precio_va_aparte()
    {
        $kilo = UnidadMedida::firstOrCreate(['name' => 'Kilo']);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'unidad_de_medida', 'operacion' => 'asignar', 'valor' => 'kilos'],
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['Unidad de medida → Kilo', 'Stock mínimo → 10'], $respuesta['cambios_legibles']);
        $this->assertSame([
            ['type' => 'select', 'key' => 'unidad_medida_id', 'value' => (int) $kilo->id],
            ['type' => 'number', 'key' => 'set_stock_min', 'value' => 10],
        ], AiMessageAction::find($respuesta['tarjeta_id'])->datos['update_form']);

        $con_precio = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10],
            ['campo' => 'precio_manual', 'operacion' => 'subir_porcentaje', 'valor' => 10],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($con_precio));
        $this->assertStringContainsString('tarjeta aparte', $con_precio['error']);
    }

    // =====================================================================
    // 2 o más sucursales: el pivote de cada una
    // =====================================================================

    /**
     * @group chat-ia
     * @test
     */
    public function con_dos_sucursales_sin_sucursal_se_pregunta_con_la_lista_y_todas()
    {
        $this->sucursal('Depósito Central E2');
        $this->sucursal('Local Centro E2');

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));
        $this->assertNotEmpty($respuesta['faltan']);

        $opciones = array_column(json_decode(json_encode($respuesta['opciones']), true)['sucursales'], 'nombre');

        $this->assertSame(['Depósito Central E2', 'Local Centro E2', 'todas'], $opciones);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_dos_sucursales_todas_escribe_el_minimo_en_cada_pivote_sin_pisar_el_maximo_y_se_revierte()
    {
        $central = $this->sucursal('Depósito Central E2');
        $local = $this->sucursal('Local Centro E2');

        // Clavo 0: stock en las dos (en el central, con mínimo 3 y máximo 80). Clavo 1: solo en el
        // central. Clavo 2: sin stock repartido (sin filas).
        $this->clavos[0]->addresses()->attach($central->id, ['amount' => 30, 'stock_min' => 3, 'stock_max' => 80]);
        $this->clavos[0]->addresses()->attach($local->id, ['amount' => 20]);
        $this->clavos[1]->addresses()->attach($central->id, ['amount' => 50]);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10, 'sucursal' => 'todas'],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['Stock mínimo → 10 en Depósito Central E2 y Local Centro E2'], $respuesta['cambios_legibles']);

        $accion = AiMessageAction::find($respuesta['tarjeta_id']);

        $this->assertSame([
            StockMinimoMaximoEnMasivaHelper::clave_minimo($central->id),
            StockMinimoMaximoEnMasivaHelper::clave_minimo($local->id),
        ], array_column($accion->datos['update_form'], 'key'));

        $renglones = array_column($accion->presentacion['renglones'], 'valor');

        /*
         * Revisión independiente (30/10/2026): el clavo 2 no reparte el stock en ninguna sucursal, así
         * que el reporte de Alertas le mira el mínimo GENERAL: ahí se le pone (antes se salteaba y esta
         * tarjeta lo contaba como "sin stock repartido" en las dos sucursales). El clavo 1 sí reparte,
         * pero no en el local: ahí no se le pone.
         */
        $this->assertContains('1 de los alcanzados no reparte el stock entre sucursales: se le pone el mínimo general del artículo', $renglones);
        $this->assertContains('1 de los alcanzados no tiene stock repartido en Local Centro E2: ahí no se le pone', $renglones);
        $this->assertNotContains('1 de los alcanzados no tiene stock repartido en Depósito Central E2: ahí no se le pone', $renglones);

        // Confirmar y correr el job.
        $this->dar_extension();

        $assistant->estado = 'listo';
        $assistant->save();

        Queue::fake();
        Event::fake([BackgroundProcessUpdated::class]);

        Auth::forgetGuards();
        $this->actingAs($this->comercio, 'web');

        $this->postJson('api/ai-conversations/' . $conversation->id . '/acciones/' . $respuesta['tarjeta_id'] . '/confirmar')
            ->assertStatus(200);

        $masiva = MasiveUpdate::where('user_id', $this->comercio->id)->orderBy('id', 'DESC')->first();

        Notification::fake();
        MasiveUpdateHelper::process_update($masiva->fresh());

        $this->assertSame('completed', $masiva->fresh()->status);

        $this->assertSame(10, (int) $this->pivote($this->clavos[0], $central)->stock_min);
        $this->assertSame(80, (int) $this->pivote($this->clavos[0], $central)->stock_max, 'El máximo NO se pisa.');
        $this->assertEquals(30, (float) $this->pivote($this->clavos[0], $central)->amount, 'El stock de la sucursal NO se toca.');
        $this->assertSame(10, (int) $this->pivote($this->clavos[0], $local)->stock_min);
        $this->assertSame(10, (int) $this->pivote($this->clavos[1], $central)->stock_min);
        $this->assertNull($this->pivote($this->clavos[1], $local), 'Sin fila en esa sucursal, no se abre una.');
        $this->assertSame(0, DB::table('address_article')->where('article_id', $this->clavos[2]->id)->count(), 'El clavo sin reparto sigue sin reparto: no se le abre ninguna fila.');
        $this->assertSame(10, (int) $this->clavos[2]->fresh()->stock_min, 'Sin reparto, el mínimo va al general del artículo (el que mira Alertas).');
        $this->assertSame(
            ['stock_min'],
            array_keys(json_decode($masiva->articles()->where('articles.id', $this->clavos[2]->id)->first()->pivot->changes_json, true)),
            'Una sola vez, en la columna del artículo, aunque la tarjeta vaya a las dos sucursales.'
        );

        $this->assertSame(
            [StockMinimoMaximoEnMasivaHelper::clave_minimo($central->id), StockMinimoMaximoEnMasivaHelper::clave_minimo($local->id)],
            array_keys(json_decode($masiva->articles()->where('articles.id', $this->clavos[0]->id)->first()->pivot->changes_json, true))
        );

        // Revertir deja el pivote como estaba (3 en el central, null en el local).
        $reversion = MasiveUpdateHelper::create_pending_revert($masiva->fresh(), $this->comercio->id);
        MasiveUpdateHelper::process_revert($reversion->fresh(), $masiva->fresh());

        $this->assertSame('reverted', $masiva->fresh()->status);
        $this->assertSame(3, (int) $this->pivote($this->clavos[0], $central)->stock_min);
        $this->assertSame(80, (int) $this->pivote($this->clavos[0], $central)->stock_max);
        $this->assertNull($this->pivote($this->clavos[0], $local)->stock_min);
        $this->assertNull($this->pivote($this->clavos[1], $central)->stock_min);
        $this->assertNull($this->clavos[2]->fresh()->stock_min, 'El mínimo general vuelve a null.');
    }

    /**
     * Con 2+ sucursales, el MÁXIMO no tiene columna general: a los que no reparten no se les pone, y
     * la tarjeta lo dice.
     *
     * @group chat-ia
     * @test
     */
    public function con_dos_sucursales_el_maximo_no_se_pone_a_los_que_no_reparten_y_se_dice()
    {
        $central = $this->sucursal('Depósito Central E2');
        $this->sucursal('Local Centro E2');

        $this->clavos[0]->addresses()->attach($central->id, ['amount' => 30]);

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_maximo', 'operacion' => 'setear', 'valor' => 100, 'sucursal' => 'central'],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertContains(
            '2 de los alcanzados no reparten el stock entre sucursales: el máximo no se les pone (no hay un máximo general)',
            array_column(AiMessageAction::find($respuesta['tarjeta_id'])->presentacion['renglones'], 'valor')
        );
    }

    /**
     * @group chat-ia
     * @test
     */
    public function con_dos_sucursales_el_maximo_va_por_sucursal_nombrada()
    {
        $central = $this->sucursal('Depósito Central E2');
        $this->sucursal('Local Centro E2');

        list($conversation, $assistant) = $this->conversacion();
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_maximo', 'operacion' => 'setear', 'valor' => 100, 'sucursal' => 'central'],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['Stock máximo → 100 en Depósito Central E2'], $respuesta['cambios_legibles']);
        $this->assertSame(
            [StockMinimoMaximoEnMasivaHelper::clave_maximo($central->id)],
            array_column(AiMessageAction::find($respuesta['tarjeta_id'])->datos['update_form'], 'key')
        );

        $inexistente = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_maximo', 'operacion' => 'setear', 'valor' => 100, 'sucursal' => 'Rosario'],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($inexistente));
        $this->assertNotEmpty($inexistente['faltan']);
    }

    /**
     * @group chat-ia
     * @test
     */
    public function los_permisos_del_minimo_y_de_cada_sucursal_se_respetan()
    {
        $central = $this->sucursal('Depósito Central E2');
        $this->sucursal('Local Centro E2');

        // Sin article.stock_min_max: no se propone.
        $sin_permiso = $this->empleado(['article.update']);

        list($conversation, $assistant) = $this->conversacion($sin_permiso);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10, 'sucursal' => 'todas'],
        ]));

        $this->assertTrue(RespuestaDeCargaIa::es_negativa($respuesta));

        // Con el permiso pero acotado a SU sucursal: "todas" queda en la suya y lo avisa.
        $acotado = $this->empleado(['article.update', 'article.stock_min_max', 'article.edit_stock_only_sucursal'], $central->id);

        list($conversation, $assistant) = $this->conversacion($acotado);
        $contexto = ContextoDeCargaIa::de_la_conversacion($conversation);

        $respuesta = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10, 'sucursal' => 'todas'],
        ]));

        $this->assertTrue($respuesta['ok'], json_encode($respuesta));
        $this->assertSame(['Stock mínimo → 10 en Depósito Central E2'], $respuesta['cambios_legibles']);
        $this->assertContains('Tu usuario solo puede tocar el stock de su sucursal: en Local Centro E2 no se cambia', $respuesta['avisos']);

        // Nombrando la otra sucursal, error.
        $otra = PropuestaActualizacionMasivaIaHelper::proponer($contexto, $assistant, $this->input([
            ['campo' => 'stock_minimo', 'operacion' => 'setear', 'valor' => 10, 'sucursal' => 'Local Centro E2'],
        ]));
        $this->assertTrue(RespuestaDeCargaIa::es_negativa($otra));
        $this->assertStringContainsString('no es esa', $otra['error']);
    }

    /**
     * La herramienta declara los campos nuevos y le prohíbe al modelo ofrecer el stock en lugar del mínimo.
     *
     * @group chat-ia
     * @test
     */
    public function la_herramienta_declara_el_minimo_y_prohibe_ofrecer_el_stock_en_su_lugar()
    {
        $definicion = null;

        foreach (HerramientasDeCarga::definiciones(false) as $herramienta) {
            if ($herramienta['name'] === 'proponer_actualizacion_masiva') {
                $definicion = $herramienta;
            }
        }

        $this->assertNotNull($definicion);

        $item = $definicion['input_schema']['properties']['cambios']['items'];

        $this->assertContains('stock_minimo', $item['properties']['campo']['enum']);
        $this->assertContains('stock_maximo', $item['properties']['campo']['enum']);
        $this->assertArrayHasKey('sucursal', $item['properties']);
        $this->assertStringContainsString('NUNCA ofrezcas setear stock en lugar del mínimo', $definicion['description']);
    }
}
