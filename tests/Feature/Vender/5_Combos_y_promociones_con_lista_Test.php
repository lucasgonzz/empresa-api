<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\Combo;
use App\Models\PriceType;
use App\Models\PromocionVinoteca;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): combos y promociones vinoteca en una
 * cuenta que trabaja con listas de precios.
 *
 * QUÉ FIJA Y POR QUÉ. La lista de precios de la venta (`sales.price_type_id`) precia SOLO los
 * artículos: en la SPA `aplicar_tipos_de_precio()` (`generals.js`) aplica la lista únicamente a
 * los ítems `is_article`, y en el back `SaleHelper::attachCombos()` (:1437) y
 * `attachPromocionVinotecas()` (:1424) persisten el `price_vender` que vino en el payload sin
 * mirar la lista. Hasta esta misión eso pasaba POR ACCIDENTE —nadie lo había escrito— y la regla
 * nueva del 422 podía tentar a alguien a "arreglarlo" preciando combos por lista o, al revés, a
 * relajar el rechazo cuando el comprobante trae solo combos. Estos tests dejan las dos cosas
 * fijadas de forma explícita:
 *
 *  - el precio de la línea del combo y de la promoción es EXACTAMENTE el `price_vender` del
 *    payload, y no cambia aunque cambie la lista de la venta (dos altas iguales con listas
 *    distintas dan el mismo precio de combo);
 *  - la regla "cuenta con listas exige lista" es POR COMPROBANTE, no por renglón: un alta sin
 *    lista que trae solo combos (o solo promociones) sigue siendo 422, y no escribe nada;
 *  - en la edición, un PUT que agrega un combo o una promoción los persiste con su precio y no
 *    toca la lista guardada si la clave no viaja (SPA anterior), y la cambia si viaja.
 *
 * El `price_vender` del combo y de la promoción se manda A PROPÓSITO distinto del precio de
 * catálogo (`combos.price`, `promocion_vinotecas.final_price`): así el test distingue "el back
 * persistió lo que vino" de "el back fue a buscar el precio a algún lado". Un back que re-preciara
 * por catálogo o por lista se pone rojo.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Listas,
 * artículo, combo y promoción se crean adentro de la transacción con prefijo `zz`, y
 * `users.listas_de_precio` se guarda en setUp y se restaura en tearDown además del rollback.
 * Molde: tests/Feature/Vender/4_Venta_sin_lista_de_precios_Test.php; el renglón de combo va
 * calcado de tests/Feature/Puntos/6 (con la clave `articles` que `ComboHelper` recorre).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Combos_y_promociones_con_lista_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var int Método de pago Efectivo, global (CurrentAcountPaymentMethodSeeder). Toda venta de contado lo manda. */
    const METODO_EFECTIVO = 3;

    /** @var int `articles.final_price`: el precio base, que en una cuenta con listas no se cobra. */
    const PRECIO_ARTICULO_BASE = 100;

    /** @var int Precio del artículo en la lista General (pivote article_price_type). */
    const PRECIO_ARTICULO_GENERAL = 150;

    /** @var int Precio del artículo en la lista Mostrador (pivote article_price_type). */
    const PRECIO_ARTICULO_MOSTRADOR = 120;

    /** @var int Cantidad del renglón de artículo. */
    const CANTIDAD_ARTICULO = 1;

    /** @var int `combos.price`, el precio de catálogo del combo. */
    const PRECIO_COMBO_CATALOGO = 500;

    /** @var int Lo que viaja como `price_vender` del combo: distinto del catálogo a propósito. */
    const PRECIO_COMBO_VENDER = 480;

    /** @var int Cantidad de combos del renglón. */
    const CANTIDAD_COMBO = 2;

    /** @var int Unidades del artículo componente que lleva UN combo (`article_combo.amount`). */
    const UNIDADES_POR_COMBO = 2;

    /** @var int `promocion_vinotecas.final_price`, el precio de catálogo de la promoción. */
    const PRECIO_PROMO_CATALOGO = 300;

    /** @var int Lo que viaja como `price_vender` de la promoción: distinto del catálogo a propósito. */
    const PRECIO_PROMO_VENDER = 290;

    /** @var int Cantidad de promociones del renglón. */
    const CANTIDAD_PROMO = 3;

    /** @var int Stock con el que nace la promoción (attachPromocionVinotecas lo descuenta siempre). */
    const STOCK_PROMO = 10;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType Position ALTA: la que el front elige por defecto. */
    protected $lista_general;

    /** @var \App\Models\PriceType Position baja. */
    protected $lista_mostrador;

    /** @var \App\Models\Article Artículo suelto de la venta y, a la vez, componente del combo. */
    protected $article;

    /** @var \App\Models\Combo */
    protected $combo;

    /** @var \App\Models\PromocionVinoteca */
    protected $promocion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista_general = PriceType::create([
            'name'     => 'zz General (combos con lista)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        $this->lista_mostrador = PriceType::create([
            'name'     => 'zz Mostrador (combos con lista)',
            'user_id'  => self::USER_ID,
            'position' => 1,
        ]);

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` y `ComboHelper::usa_stock()` dan false
         * con stock null y ni el artículo suelto ni el componente del combo mueven stock. Acá se
         * mide el precio y la lista, no el stock (eso lo cubre tests/Feature/Stock).
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo combos con lista',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_ARTICULO_BASE,
            'status'      => 'active',
        ]);

        /*
         * El mismo artículo tiene un precio DISTINTO en cada lista. Es lo que permite afirmar que
         * el combo no depende de la lista: si el back preciara el combo por sus componentes con la
         * lista de la venta, las dos altas del test de "no cambia con la lista" darían números
         * distintos.
         */
        $this->article->price_types()->attach($this->lista_general->id, [
            'final_price' => self::PRECIO_ARTICULO_GENERAL,
        ]);

        $this->article->price_types()->attach($this->lista_mostrador->id, [
            'final_price' => self::PRECIO_ARTICULO_MOSTRADOR,
        ]);

        $this->combo = Combo::create([
            'num'     => 9521,
            'name'    => 'zz Combo con lista',
            'price'   => self::PRECIO_COMBO_CATALOGO,
            'cost'    => 200,
            'user_id' => self::USER_ID,
        ]);

        $this->combo->articles()->attach($this->article->id, ['amount' => self::UNIDADES_POR_COMBO]);

        $this->promocion = PromocionVinoteca::create([
            'name'        => 'zz Promocion con lista',
            'stock'       => self::STOCK_PROMO,
            'cost'        => 100,
            'final_price' => self::PRECIO_PROMO_CATALOGO,
            'user_id'     => self::USER_ID,
        ]);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            User::where('id', self::USER_ID)->update(['listas_de_precio' => $this->listas_original]);
        }

        parent::tearDown();
    }

    /**
     * Prende o apaga las listas de precio de la cuenta. Por query y no por save(): el controlador
     * lee al dueño fresco en cada request (`UserHelper::user()` hace `User::find`).
     *
     * @param  int  $usa  1 o 0
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * Renglón de artículo tal como lo manda VENDER: ya preciado por el front con la lista.
     *
     * @param  int  $price_vender
     * @return array
     */
    protected function renglon_articulo($price_vender)
    {
        return [
            'is_article'   => true,
            'id'           => $this->article->id,
            'name'         => $this->article->name,
            'price_vender' => $price_vender,
            'amount'       => self::CANTIDAD_ARTICULO,
        ];
    }

    /**
     * Renglón de combo en la forma exacta que arma VENDER (`deteccion_combos.js` y
     * `header-form/Combos.vue`): el modelo del combo entero con `is_combo`, `price_vender` y
     * `amount`. La clave `articles` va sí o sí: `ComboHelper::discount_articles_stock()` la
     * recorre sin `isset` cuando la venta descuenta stock.
     *
     * @return array
     */
    protected function renglon_combo()
    {
        return [
            'is_combo'                    => true,
            'id'                          => $this->combo->id,
            'name'                        => $this->combo->name,
            'price'                       => self::PRECIO_COMBO_CATALOGO,
            'final_price'                 => self::PRECIO_COMBO_CATALOGO,
            'price_vender'                => self::PRECIO_COMBO_VENDER,
            'amount'                      => self::CANTIDAD_COMBO,
            'article_variant_id'          => 0,
            'price_type_personalizado_id' => 0,
            'articles'                    => [
                [
                    'id'    => $this->article->id,
                    'pivot' => ['amount' => self::UNIDADES_POR_COMBO],
                ],
            ],
        ];
    }

    /**
     * Renglón de promoción vinoteca en la forma que arma `header-form/PromocionVinoteca.vue`: el
     * modelo con `is_promocion_vinoteca`, `price_vender` y `amount`.
     *
     * @return array
     */
    protected function renglon_promocion()
    {
        return [
            'is_promocion_vinoteca' => true,
            'id'                    => $this->promocion->id,
            'name'                  => $this->promocion->name,
            'final_price'           => self::PRECIO_PROMO_CATALOGO,
            'price_vender'          => self::PRECIO_PROMO_VENDER,
            'amount'                => self::CANTIDAD_PROMO,
        ];
    }

    /**
     * Total de una lista de renglones como lo suma la SPA (`vender_set_total.js`): precio por
     * cantidad, sin descuentos ni recargos de venta (acá no hay).
     *
     * @param  array  $items
     * @return float
     */
    protected function total_de($items)
    {
        $total = 0;

        foreach ($items as $item) {
            $total += (float) $item['price_vender'] * (float) $item['amount'];
        }

        return $total;
    }

    /**
     * Payload de POST api/sale de una venta de mostrador (sin cliente), calcado de
     * tests/Feature/Vender/4. Lleva el método de pago Efectivo porque una venta de contado sin
     * método se rechaza (tanda 2, hallazgo A7).
     *
     * @param  array  $items
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($items, $overrides = [])
    {
        $total = $this->total_de($items);

        return array_merge([
            'client_id'                         => null,
            'address_id'                        => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'price_type_id'                     => null,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'employee_id'                       => null,
            'sub_total'                         => $total,
            'total'                             => $total,
            'terminada'                         => 1,
            'seller_id'                         => null,
            'cantidad_cuotas'                   => null,
            'cuota_descuento'                   => 0,
            'cuota_recargo'                     => 0,
            'caja_id'                           => null,
            'afip_tipo_comprobante_id'          => null,
            'descuento'                         => null,
            'moneda_id'                         => 1,
            'discount_stock'                    => 0,
            'discounts'                         => [],
            'surchages'                         => [],
            'items'                             => $items,
        ], $overrides);
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id}, calcado de tests/Feature/Vender/4. SIN
     * `price_type_id` a propósito: cada test decide si la clave viaja.
     *
     * @param  array  $items
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($items, $overrides = [])
    {
        $total = $this->total_de($items);

        return array_merge([
            'client_id'                         => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'checked'                           => 0,
            'confirmed'                         => 0,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'discount_stock'                    => 0,
            'sub_total'                         => $total,
            'total'                             => $total,
            'moneda_id'                         => 1,
            'items'                             => $items,
            'discounts'                         => [],
            'surchages'                         => [],
            'returned_items'                    => [],
        ], $overrides);
    }

    /**
     * Venta de mostrador ya guardada con lista y con el artículo en el pivote, para los tests del
     * PUT. Va directo a la base (mismo molde que tests/Feature/Vender/4 y Sales/9): lo que se mide
     * es qué hace update() con el combo y con la lista, no el alta. Sin métodos de pago adjuntos
     * a propósito: el usuario 500 tiene cajas y una venta ya cobrada no se puede editar
     * (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`).
     *
     * @param  int  $price_type_id
     * @param  int  $precio_del_pivote
     * @return \App\Models\Sale
     */
    protected function venta_guardada_con_lista($price_type_id, $precio_del_pivote)
    {
        $sale = Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => $price_type_id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => $precio_del_pivote * self::CANTIDAD_ARTICULO,
            'total'                      => $precio_del_pivote * self::CANTIDAD_ARTICULO,
        ]);

        $sale->articles()->attach($this->article->id, [
            'amount' => self::CANTIDAD_ARTICULO,
            'price'  => $precio_del_pivote,
            'cost'   => 0,
        ]);

        return $sale;
    }

    /**
     * Corre `created_at` de una venta 10 segundos para atrás. `SaleController::venta_ya_cread()`
     * debounea (devuelve 200 sin cuerpo) una venta con el mismo user/client/employee/total de los
     * últimos 5 segundos: dos altas iguales seguidas en el mismo test —que es justamente lo que
     * hace el test de "no cambia con la lista"— se comerían la segunda sin este paso.
     *
     * @param  int  $sale_id
     * @return void
     */
    protected function envejecer_venta($sale_id)
    {
        Sale::where('id', $sale_id)->update(['created_at' => Carbon::now()->subSeconds(10)]);
    }

    /**
     * Filas de `combo_sale` de una venta, leídas con DB::table: lo que interesa es lo que quedó
     * escrito en el pivote, no lo que Eloquent devuelve después de pasarlo por el modelo.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_combo($sale_id)
    {
        return DB::table('combo_sale')->where('sale_id', $sale_id)->get();
    }

    /**
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_promocion($sale_id)
    {
        return DB::table('promocion_vinoteca_sale')->where('sale_id', $sale_id)->get();
    }

    /**
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_articulo($sale_id)
    {
        return DB::table('article_sale')->where('sale_id', $sale_id)->get();
    }

    /**
     * @return int
     */
    protected function cantidad_de_ventas()
    {
        return Sale::where('user_id', self::USER_ID)->count();
    }

    /**
     * Las aserciones del rechazo, compartidas con tests/Feature/Vender/4.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return void
     */
    protected function assert_rechazo_sin_lista($response)
    {
        $response->assertStatus(422);

        $this->assertTrue(
            (bool) $response->json('sin_lista_de_precios'),
            'El 422 tiene que llevar sin_lista_de_precios: true, que es lo que distingue este rechazo en la SPA.'
        );

        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));
    }

    /**
     * Alta con lista y un combo: 201, `sales.price_type_id` es la lista, y la línea del combo
     * queda con el `price_vender` del payload —ni el precio de catálogo del combo ni nada
     * derivado de la lista—, con su cantidad.
     *
     * @group vender
     * @test
     */
    public function combo_con_lista_se_guarda_al_precio_del_payload_y_con_la_lista_de_la_venta()
    {
        $this->cuenta_con_listas(1);

        $items = [
            $this->renglon_articulo(self::PRECIO_ARTICULO_GENERAL),
            $this->renglon_combo(),
        ];

        $response = $this->postJson('api/sale', $this->payload_venta($items, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertEquals($this->lista_general->id, (int) $sale->price_type_id, 'La venta se guarda con la lista que viajó.');

        $combos = $this->filas_combo($sale->id);

        $this->assertCount(1, $combos, 'El combo tiene que quedar en combo_sale.');
        $this->assertEquals($this->combo->id, (int) $combos->first()->combo_id);
        $this->assertEqualsWithDelta(
            self::PRECIO_COMBO_VENDER,
            (float) $combos->first()->price,
            0.01,
            'El precio de la línea del combo es el price_vender del payload, no el de catálogo ni uno derivado de la lista.'
        );
        $this->assertEquals(self::CANTIDAD_COMBO, (int) $combos->first()->amount);

        /* El artículo suelto sí va a precio de lista, porque así lo mandó el front. */
        $articulos = $this->filas_articulo($sale->id);

        $this->assertCount(1, $articulos);
        $this->assertEqualsWithDelta(self::PRECIO_ARTICULO_GENERAL, (float) $articulos->first()->price, 0.01);
    }

    /**
     * Lo mismo para una promoción vinoteca: precio del payload, lista de la venta. Y el stock
     * de la promoción baja por la cantidad vendida (`PromocionVinotecaHelper` lo descuenta
     * siempre que la venta no esté a chequear), que es la única escritura que la promoción hace
     * fuera del pivote.
     *
     * @group vender
     * @test
     */
    public function promocion_con_lista_se_guarda_al_precio_del_payload_y_con_la_lista_de_la_venta()
    {
        $this->cuenta_con_listas(1);

        $items = [
            $this->renglon_articulo(self::PRECIO_ARTICULO_GENERAL),
            $this->renglon_promocion(),
        ];

        $response = $this->postJson('api/sale', $this->payload_venta($items, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertEquals($this->lista_general->id, (int) $sale->price_type_id);

        $promos = $this->filas_promocion($sale->id);

        $this->assertCount(1, $promos, 'La promoción tiene que quedar en promocion_vinoteca_sale.');
        $this->assertEquals($this->promocion->id, (int) $promos->first()->promocion_vinoteca_id);
        $this->assertEqualsWithDelta(
            self::PRECIO_PROMO_VENDER,
            (float) $promos->first()->price,
            0.01,
            'El precio de la línea de la promoción es el price_vender del payload.'
        );
        $this->assertEquals(self::CANTIDAD_PROMO, (int) $promos->first()->amount);

        $this->assertEquals(
            self::STOCK_PROMO - self::CANTIDAD_PROMO,
            (int) PromocionVinoteca::find($this->promocion->id)->stock,
            'El stock de la promoción baja por la cantidad vendida.'
        );
    }

    /**
     * 🔴 LO QUE PASABA POR ACCIDENTE, ESCRITO: dos altas idénticas salvo la lista (Mostrador y
     * General, donde el componente del combo vale distinto) dan el MISMO precio de combo y de
     * promoción. Solo cambia `sales.price_type_id`. Un back que preciara combos por sus
     * componentes con la lista de la venta, o promociones por lista, se pone rojo acá.
     *
     * @group vender
     * @test
     */
    public function el_precio_del_combo_y_de_la_promocion_no_cambia_con_la_lista()
    {
        $this->cuenta_con_listas(1);

        $items = [
            $this->renglon_combo(),
            $this->renglon_promocion(),
        ];

        $con_mostrador = $this->postJson('api/sale', $this->payload_venta($items, [
            'price_type_id' => $this->lista_mostrador->id,
        ]));

        $con_mostrador->assertStatus(201);

        $venta_mostrador = Sale::find($con_mostrador->json('model.id'));

        $this->envejecer_venta($venta_mostrador->id);

        $con_general = $this->postJson('api/sale', $this->payload_venta($items, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $con_general->assertStatus(201);

        $venta_general = Sale::find($con_general->json('model.id'));

        $this->assertNotEquals($venta_mostrador->id, $venta_general->id, 'Son dos ventas distintas (el debounce no se comió la segunda).');
        $this->assertEquals($this->lista_mostrador->id, (int) $venta_mostrador->price_type_id);
        $this->assertEquals($this->lista_general->id, (int) $venta_general->price_type_id);

        $combo_mostrador = $this->filas_combo($venta_mostrador->id)->first();
        $combo_general   = $this->filas_combo($venta_general->id)->first();

        $this->assertNotNull($combo_mostrador);
        $this->assertNotNull($combo_general);
        $this->assertEqualsWithDelta(self::PRECIO_COMBO_VENDER, (float) $combo_mostrador->price, 0.01);
        $this->assertEqualsWithDelta(
            (float) $combo_mostrador->price,
            (float) $combo_general->price,
            0.01,
            'El precio del combo no puede depender de la lista de la venta.'
        );

        $promo_mostrador = $this->filas_promocion($venta_mostrador->id)->first();
        $promo_general   = $this->filas_promocion($venta_general->id)->first();

        $this->assertNotNull($promo_mostrador);
        $this->assertNotNull($promo_general);
        $this->assertEqualsWithDelta(self::PRECIO_PROMO_VENDER, (float) $promo_mostrador->price, 0.01);
        $this->assertEqualsWithDelta(
            (float) $promo_mostrador->price,
            (float) $promo_general->price,
            0.01,
            'El precio de la promoción no puede depender de la lista de la venta.'
        );
    }

    /**
     * 🔴 LA REGLA ES POR COMPROBANTE, NO POR RENGLÓN: un alta sin lista que trae SOLO combos
     * —ningún artículo que la lista pudiera preciar— sigue siendo 422 en una cuenta con listas, y
     * no escribe ni la venta ni el pivote ni toca el stock. Relajar el rechazo "porque no hay
     * artículos" abriría la puerta de vuelta: el próximo artículo que se agregue a esa venta en la
     * edición saldría a precio base.
     *
     * @group vender
     * @test
     */
    public function alta_sin_lista_con_solo_combos_responde_422_y_no_escribe_nada()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = $this->cantidad_de_ventas();
        $combos_antes = DB::table('combo_sale')->where('combo_id', $this->combo->id)->count();

        $response = $this->postJson('api/sale', $this->payload_venta([$this->renglon_combo()], [
            'price_type_id' => null,
        ]));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas(), 'Un 422 no puede haber creado la venta.');
        $this->assertEquals(
            $combos_antes,
            DB::table('combo_sale')->where('combo_id', $this->combo->id)->count(),
            'Un 422 no puede haber escrito en combo_sale.'
        );
    }

    /**
     * Ídem con solo promociones: 422, y el stock de la promoción queda intacto (el rechazo va
     * antes de la transacción, así que `PromocionVinotecaHelper` nunca corre).
     *
     * @group vender
     * @test
     */
    public function alta_sin_lista_con_solo_promociones_responde_422_y_no_toca_el_stock()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([$this->renglon_promocion()], [
            'price_type_id' => null,
        ]));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
        $this->assertEquals(
            self::STOCK_PROMO,
            (int) PromocionVinoteca::find($this->promocion->id)->stock,
            'Un 422 no puede haber descontado el stock de la promoción.'
        );
    }

    /**
     * EDICIÓN, SPA anterior (sin `price_type_id` en el PUT): agregar un combo y una promoción a
     * una venta con lista los persiste con su precio del payload, el renglón de artículo que ya
     * estaba conserva el precio del pivote, y la lista guardada NO cambia.
     *
     * @group vender
     * @test
     */
    public function put_que_agrega_combo_y_promocion_los_persiste_y_no_cambia_la_lista()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id, self::PRECIO_ARTICULO_MOSTRADOR);

        $items = [
            $this->renglon_articulo(self::PRECIO_ARTICULO_MOSTRADOR),
            $this->renglon_combo(),
            $this->renglon_promocion(),
        ];

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update($items));

        $response->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) $despues->price_type_id,
            'Sin la clave en el PUT, la lista guardada se preserva aunque se agreguen combos.'
        );

        $combos = $this->filas_combo($venta->id);

        $this->assertCount(1, $combos, 'El combo agregado en la edición tiene que quedar en combo_sale.');
        $this->assertEqualsWithDelta(self::PRECIO_COMBO_VENDER, (float) $combos->first()->price, 0.01);
        $this->assertEquals(self::CANTIDAD_COMBO, (int) $combos->first()->amount);

        $promos = $this->filas_promocion($venta->id);

        $this->assertCount(1, $promos, 'La promoción agregada en la edición tiene que quedar en promocion_vinoteca_sale.');
        $this->assertEqualsWithDelta(self::PRECIO_PROMO_VENDER, (float) $promos->first()->price, 0.01);

        $articulos = $this->filas_articulo($venta->id);

        $this->assertCount(1, $articulos);
        $this->assertEqualsWithDelta(
            self::PRECIO_ARTICULO_MOSTRADOR,
            (float) $articulos->first()->price,
            0.01,
            'El renglón que ya estaba conserva el precio con el que se cobró.'
        );
    }

    /**
     * EDICIÓN, SPA nueva (con `price_type_id` en el PUT): la lista de la venta cambia a la que
     * viajó, y el combo agregado en la misma edición sigue valiendo lo que dice el payload. La
     * lista se mueve; el combo, no.
     *
     * @group vender
     * @test
     */
    public function put_con_lista_nueva_y_combo_cambia_la_lista_y_el_combo_conserva_su_precio()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_guardada_con_lista($this->lista_mostrador->id, self::PRECIO_ARTICULO_MOSTRADOR);

        $items = [
            $this->renglon_articulo(self::PRECIO_ARTICULO_GENERAL),
            $this->renglon_combo(),
        ];

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update($items, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertEquals($this->lista_general->id, (int) $despues->price_type_id, 'Con la clave en el PUT, la lista cambia.');

        $combos = $this->filas_combo($venta->id);

        $this->assertCount(1, $combos);
        $this->assertEqualsWithDelta(
            self::PRECIO_COMBO_VENDER,
            (float) $combos->first()->price,
            0.01,
            'Cambiar la lista de la venta no toca el precio del combo.'
        );
    }
}
