<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\asistente_ia\ContextoDeCargaIa;
use App\Http\Controllers\Helpers\asistente_ia\PropuestaVentaIaHelper;
use App\Models\Budget;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Lo que apareció probando en navegador real un negocio que EXISTÍA sin listas y al que se le corrió
 * `listas:migrar-a-precio-final` (misión vender-tras-migrar-precio-final, 4/11/2026, release 4.3.29).
 *
 * Con «Precio final» como ÚNICA lista, un comprobante que llega sin `price_type_id` no se rechaza: toma
 * esa lista y cobra lo mismo, porque esa lista ES el precio del artículo con el que ya vienen preciados
 * los renglones. Los tres casos reales que daban 422:
 *
 *  - Pestaña abierta durante la migración: el SPA sigue creyendo flag 0 y manda null. Si otra pestaña de
 *    la misma sesión recargó, el usuario de la SESIÓN ya tiene el flag prendido y la API contestaba 422.
 *    Si no recargó nadie, el de la sesión seguía viejo y la venta quedaba con `price_type_id` NULL.
 *  - Venta guardada sin conexión ANTES de migrar: al subirla, 422 y quedaba rechazada en el equipo.
 *  - Catálogo de listas que no llega: la pantalla frenaba para siempre; ahora el usuario dice que la
 *    cuenta tiene la «Precio final» sola y la venta viaja sin lista.
 *
 * Una cuenta con listas propias o con varias listas sigue exigiendo lista: ahí los renglones sí dependen
 * de la lista, y completarla mentiría (el docblock de PriceTypeHelper).
 *
 * Sobre el dueño 500 del fixture, igual que 6_Vender_con_una_sola_lista_Test; todo dentro de la transacción.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe, argumentos nombrados, union types ni enum.
 */
class Comprobante_sin_lista_en_negocio_migrado_Test extends AuditoriaStockTestCase
{
    const EXTENCION_RANGOS = 'lista_de_precios_por_rango_de_cantidad_vendida';

    /** @var int|null */
    protected $listas_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        PriceTypeHelper::olvidar_precio_final();

        $this->listas_original = $this->usuario()->listas_de_precio;

        $this->dejar_sin_listas();
    }

    protected function tearDown(): void
    {
        DB::table('users')->where('id', $this->usuario()->id)->update(array('listas_de_precio' => $this->listas_original));

        PriceTypeHelper::olvidar_precio_final();

        parent::tearDown();
    }

    /** Saca las cuatro listas del fixture, por SQL para no disparar observers. */
    protected function dejar_sin_listas()
    {
        $user = $this->usuario();

        DB::table('article_price_type')->whereIn('price_type_id', DB::table('price_types')->where('user_id', $user->id)->pluck('id'))->delete();
        DB::table('price_types')->where('user_id', $user->id)->delete();
        DB::table('users')->where('id', $user->id)->update(array('listas_de_precio' => 0));
    }

    /**
     * El dueño del fixture como queda después de migrar: «Precio final» sola y el flag prendido.
     *
     * @return \App\Models\PriceType
     */
    protected function migrado()
    {
        $lista = PriceTypeHelper::crear_lista_precio_final($this->usuario()->id);

        DB::table('users')->where('id', $this->usuario()->id)->update(array('listas_de_precio' => 1));

        PriceTypeHelper::olvidar_precio_final($this->usuario()->id);

        return $lista;
    }

    /**
     * Una lista propia del dueño, sin observers.
     *
     * @param  string  $nombre
     * @return \App\Models\PriceType
     */
    protected function lista_propia($nombre = 'zz Mayorista')
    {
        DB::table('users')->where('id', $this->usuario()->id)->update(array('listas_de_precio' => 1));

        return PriceType::withoutEvents(function () use ($nombre) {
            return PriceType::create(array('name' => $nombre, 'position' => 5, 'percentage' => 10, 'user_id' => $this->usuario()->id));
        });
    }

    /**
     * Un artículo con su precio calculado.
     *
     * @return array  [Article, float precio final]
     */
    protected function articulo_con_precio()
    {
        $articulo = $this->crear_articulo('zz Comprobante sin lista '.uniqid());

        return array($articulo, (float) DB::table('articles')->where('id', $articulo->id)->value('final_price'));
    }

    /**
     * Un presupuesto de VENDER, con un renglón.
     *
     * @param  \App\Models\Article  $articulo
     * @param  float                $precio
     * @param  array                $extra
     * @return array
     */
    protected function payload_presupuesto($articulo, $precio, array $extra = array())
    {
        return array_merge(array(
            'client_id'                          => $this->cliente_cc()->id,
            'start_at'                           => null,
            'finish_at'                          => null,
            'observations'                       => null,
            'price_type_id'                      => null,
            'sale_status_id'                     => null,
            'discount_stock'                     => 0,
            'iva_aplicado'                       => 1,
            'total'                              => $precio * 2,
            'budget_status_id'                   => 1,
            'address_id'                         => null,
            'surchages_in_services'              => 1,
            'discounts_in_services'              => 1,
            'aplicar_descuentos_directo_a_items' => 0,
            'aplicar_recargos_directo_a_items'   => 0,
            'moneda_id'                          => 1,
            'valor_dolar'                        => null,
            'discounts'                          => array(),
            'surchages'                          => array(),
            'articles'                           => array(array('id' => $articulo->id, 'status' => 'active', 'amount' => 2, 'bonus' => null, 'location' => null, 'price' => $precio)),
            'services'                           => array(),
            'promocion_vinotecas'                => array(),
            'combos'                             => array(),
        ), $extra);
    }

    /**
     * El renglón guardado de la venta y su lista, leídos de la base.
     *
     * @param  int    $sale_id
     * @param  int    $article_id
     * @param  float  $precio
     * @param  int    $lista_id
     * @param  string $que
     * @return void
     */
    protected function assert_venta_con_lista_y_precio($sale_id, $article_id, $precio, $lista_id, $que)
    {
        $this->assertSame((int) $lista_id, (int) DB::table('sales')->where('id', $sale_id)->value('price_type_id'), $que.': la venta toma la única lista.');

        $renglon = DB::table('article_sale')->where('sale_id', $sale_id)->where('article_id', $article_id)->first();

        $this->assertNotNull($renglon, $que);
        $this->assertEqualsWithDelta($precio, (float) $renglon->price, 0.005, $que.': el renglón cobra lo mismo.');
    }

    // ── Los casos reales ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function pestana_vieja_con_la_sesion_ya_refrescada_no_recibe_422_y_toma_la_unica_lista()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 2, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Pestaña vieja');
    }

    /** @test */
    public function pestana_vieja_con_el_usuario_de_la_sesion_previo_a_migrar_tambien_toma_la_lista()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        // Lo que deja `UserHelper::set_sessions()` si nadie recargó después de migrar: el flag viejo.
        $vieja = User::find($this->usuario()->id);
        $vieja->listas_de_precio = 0;

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->withSession(array('auth_user' => $vieja, 'owner' => $vieja))->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Sesión previa a migrar');
    }

    /** @test */
    public function la_venta_guardada_sin_conexion_antes_de_migrar_se_sube_con_la_lista()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null, 'offline_uuid' => 'zz-offline-'.uniqid())));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Venta sin conexión');
    }

    /** @test */
    public function el_cero_y_la_clave_ausente_son_lo_mismo_que_null()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $con_cero = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => 0)));
        $con_cero->assertStatus(201);
        $this->assert_venta_con_lista_y_precio($con_cero->json('model.id'), $articulo->id, $precio, $lista->id, 'Con cero');

        // El deduplicado del back mira los segundos: otra cantidad para que no se lea como el mismo POST.
        $sin_clave = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 3, 'price' => $precio),
        )));
        $sin_clave->assertStatus(201);
        $this->assert_venta_con_lista_y_precio($sin_clave->json('model.id'), $articulo->id, $precio, $lista->id, 'Sin la clave');
    }

    /** @test */
    public function un_empleado_tambien_y_la_lista_sale_del_dueno()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $empleado = User::create(array(
            'name'     => 'zz Empleado migrado',
            'email'    => 'zz-migrado-'.uniqid().'@test.local',
            'password' => Hash::make('zz-password-testing'),
            'status'   => 'commerce',
            'owner_id' => $this->usuario()->id,
        ));

        $this->actingAs($empleado, 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Empleado');
    }

    /** @test */
    public function un_cliente_con_una_lista_que_ya_no_existe_no_le_gana_a_la_unica_lista()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        DB::table('clients')->where('id', $this->cliente_cc()->id)->update(array('price_type_id' => 99999999));

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Cliente con lista colgada');
    }

    /** @test */
    public function editar_una_venta_vieja_sin_lista_le_pone_la_unica_y_no_le_cambia_el_precio()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $venta = $this->crear_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => $lista->id));

        // La venta de antes de migrar.
        DB::table('sales')->where('id', $venta->id)->update(array('price_type_id' => null));

        $this->actualizar_venta($venta, array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null))->assertStatus(200);

        $this->assert_venta_con_lista_y_precio($venta->id, $articulo->id, $precio, $lista->id, 'PUT con null');
    }

    /** @test */
    public function un_put_sin_la_clave_sigue_sin_tocar_la_lista_de_la_venta_vieja()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $venta = $this->crear_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => $lista->id));

        DB::table('sales')->where('id', $venta->id)->update(array('price_type_id' => null));

        $this->actualizar_venta($venta, array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ))->assertStatus(200);

        $this->assertNull(DB::table('sales')->where('id', $venta->id)->value('price_type_id'));
    }

    /** @test */
    public function el_presupuesto_sin_lista_toma_la_unica_en_el_alta_y_en_la_edicion()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $alta = $this->postJson('api/budget', $this->payload_presupuesto($articulo, $precio));
        $alta->assertStatus(201);

        $id = $alta->json('model.id');

        $this->assertSame((int) $lista->id, (int) DB::table('budgets')->where('id', $id)->value('price_type_id'));
        $this->assertEqualsWithDelta($precio, (float) DB::table('article_budget')->where('budget_id', $id)->value('price'), 0.005);

        // El de antes de migrar, editado desde una pestaña vieja.
        DB::table('budgets')->where('id', $id)->update(array('price_type_id' => null));

        $this->putJson('api/budget/'.$id, $this->payload_presupuesto($articulo, $precio))->assertStatus(200);

        $this->assertSame((int) $lista->id, (int) DB::table('budgets')->where('id', $id)->value('price_type_id'));
        $this->assertEqualsWithDelta($precio, (float) DB::table('article_budget')->where('budget_id', $id)->value('price'), 0.005);
    }

    /** @test */
    public function el_presupuesto_previo_a_migrar_pasado_a_venta_sale_con_la_unica_lista_y_el_mismo_precio()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $alta = $this->postJson('api/budget', $this->payload_presupuesto($articulo, $precio));
        $alta->assertStatus(201);

        $id = $alta->json('model.id');

        DB::table('budgets')->where('id', $id)->update(array('price_type_id' => null));

        $this->postJson('api/budget/'.$id.'/confirmar', array())->assertStatus(200);

        $venta = Sale::where('budget_id', $id)->first();

        $this->assertNotNull($venta, 'Confirmar tiene que crear la venta.');

        $this->assert_venta_con_lista_y_precio($venta->id, $articulo->id, $precio, $lista->id, 'Presupuesto confirmado');
    }

    /** @test */
    public function el_asistente_resuelve_la_unica_lista_sin_pedirla_aunque_el_cliente_tenga_una_colgada()
    {
        $lista = $this->migrado();

        $contexto = new ContextoDeCargaIa();
        $contexto->owner_id = (int) $this->usuario()->id;
        $contexto->owner = User::find($this->usuario()->id);

        $sin_cliente = PropuestaVentaIaHelper::resolver_lista_de_precios($contexto, null, '');
        $this->assertSame((int) $lista->id, (int) $sin_cliente->id);

        $cliente = Client::find($this->cliente_cc()->id);
        $cliente->price_type_id = 99999999;

        $con_cliente = PropuestaVentaIaHelper::resolver_lista_de_precios($contexto, $cliente, '');
        $this->assertSame((int) $lista->id, (int) $con_cliente->id);
    }

    // ── La SPA se entera sin el catálogo ─────────────────────────────────────────────────────────

    /** @test */
    public function get_user_dice_si_la_cuenta_tiene_la_precio_final_sola()
    {
        $this->migrado();

        $this->actingAs($this->usuario(), 'web');

        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.solo_lista_precio_final', true);

        $this->lista_propia();

        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.solo_lista_precio_final', false);
    }

    /** @test */
    public function un_negocio_sin_migrar_no_figura_con_la_precio_final_sola()
    {
        $this->actingAs($this->usuario(), 'web');

        $this->getJson('api/user')->assertStatus(200)->assertJsonPath('user.solo_lista_precio_final', false);
    }

    // ── Lo que NO cambia ─────────────────────────────────────────────────────────────────────────

    /** @test */
    public function una_sola_lista_propia_sigue_exigiendo_lista()
    {
        $this->lista_propia();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(422);
        $this->assertTrue((bool) $respuesta->json('sin_lista_de_precios'));

        $presupuesto = $this->postJson('api/budget', $this->payload_presupuesto($articulo, $precio));
        $presupuesto->assertStatus(422);
        $this->assertTrue((bool) $presupuesto->json('sin_lista_de_precios'));
    }

    /** @test */
    public function la_precio_final_y_otra_lista_siguen_exigiendo_lista_en_el_alta_y_en_la_edicion()
    {
        $precio_final = $this->migrado();

        $this->lista_propia();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(422);
        $this->assertTrue((bool) $respuesta->json('sin_lista_de_precios'));

        $venta = $this->crear_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => $precio_final->id));

        $put = $this->actualizar_venta($venta, array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null));

        $put->assertStatus(422);
        $this->assertSame((int) $precio_final->id, (int) DB::table('sales')->where('id', $venta->id)->value('price_type_id'));
    }

    /** @test */
    public function sin_migrar_sigue_guardando_sin_lista()
    {
        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);
        $this->assertNull(DB::table('sales')->where('id', $respuesta->json('model.id'))->value('price_type_id'));
    }

    /** @test */
    public function con_la_extension_de_rangos_la_venta_sigue_sin_lista_de_comprobante()
    {
        $this->migrado();

        $extension = ExtencionEmpresa::where('slug', self::EXTENCION_RANGOS)->first();

        if (is_null($extension)) {
            $extension = ExtencionEmpresa::forceCreate(array('slug' => self::EXTENCION_RANGOS, 'name' => 'Rangos'));
        }

        $this->usuario()->extencions()->syncWithoutDetaching(array($extension->id));

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);
        $this->assertNull(DB::table('sales')->where('id', $respuesta->json('model.id'))->value('price_type_id'));
    }

    /** @test */
    public function la_lista_explicita_se_respeta()
    {
        $lista = $this->migrado();

        list($articulo, $precio) = $this->articulo_con_precio();

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => $precio),
        ), array('price_type_id' => $lista->id)));

        $respuesta->assertStatus(201);

        $this->assert_venta_con_lista_y_precio($respuesta->json('model.id'), $articulo->id, $precio, $lista->id, 'Explícita');
    }
}
