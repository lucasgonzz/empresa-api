<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\Discount;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Surchage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): descuentos y recargos de venta en una
 * cuenta que trabaja con listas de precios.
 *
 * QUÉ FIJA Y POR QUÉ. El total de una venta con lista es la suma de los renglones A PRECIO DE
 * LISTA (el `price_vender` que el front resolvió con la lista y que `attachArticle()` persiste
 * tal cual) menos los descuentos de venta y más los recargos de venta, en ese orden y compuestos,
 * exactamente como lo suma la SPA (`vender_set_total.js`: `aplicar_discounts()` y después
 * `aplicar_surchages()`). El back guarda el `total` que mandó el front (`SaleController::store()`
 * lo asigna pelado), pero NO es un espejo pasivo: `SaleHelper::getTotalSale()` recalcula ese
 * mismo total desde los pivotes cuando se confirma una venta chequeada (`update_total_sale()`),
 * cuando se puntúa (`PuntosBaseHelper`) y cuando se factura (`AfipItemCalculator`). Si lo
 * persistido —precios de línea, `discount_sale.percentage`, `sale_surchage.percentage` y las
 * tres banderas— no reproduce el total que el vendedor vio, todos esos caminos discrepan del
 * comprobante. Por eso cada test afirma las dos cosas: las columnas en la base, y que
 * `getTotalSale()` sobre la venta persistida da lo mismo que `sales.total`.
 *
 * Los tres casos que importan:
 *  - descuento y recargo comunes: pivotes con `percentage`, total = renglones × (1 − d) × (1 + r);
 *  - `aplicar_recargos_directo_a_items = 1`: los renglones YA vienen recargados y el total no
 *    vuelve a sumar el recargo. Para presupuestos lo fija Presupuestos/2; acá es la venta, y el
 *    mutante que se caza es el que ignora la bandera y recarga dos veces. Los descuentos se
 *    siguen aplicando: la opción es SOLO de recargos;
 *  - `discounts_in_services` / `surchages_in_services`: los servicios reciben el porcentaje solo
 *    con su bandera prendida, y las banderas se persisten como viajaron.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Lista,
 * artículo, descuento, recargo y servicio se crean adentro de la transacción con prefijo `zz`, y
 * `users.listas_de_precio` se restaura en tearDown además del rollback. Molde:
 * tests/Feature/Vender/4 (auth y payload) y tests/Feature/Presupuestos/2 (fixtures de descuento,
 * recargo y servicio; `aplicar_recargos_directo_a_items`).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Descuentos_y_recargos_con_lista_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var int Método de pago Efectivo, global. Toda venta de contado lo manda (tanda 2, A7). */
    const METODO_EFECTIVO = 3;

    /** @var float Tolerancia de un centavo, la misma que usan los helpers de totales. */
    const DELTA = 0.01;

    /** @var int `articles.final_price`: el precio base, que en una cuenta con listas no se cobra. */
    const PRECIO_BASE = 100;

    /** @var int Precio del artículo en la lista (pivote article_price_type): el que se cobra. */
    const PRECIO_LISTA = 150;

    /** @var int Cantidad del renglón. Con 4 la diferencia entre sumar el recargo una o dos veces es 144: lejos de cualquier tolerancia. */
    const CANTIDAD = 4;

    /** @var int Descuento de venta, en porcentaje. */
    const PORCENTAJE_DESCUENTO = 10;

    /** @var int Recargo de venta, en porcentaje. */
    const PORCENTAJE_RECARGO = 20;

    /** @var int El precio de lista con el recargo ya adentro: lo que manda la SPA con `aplicar_recargos_directo_a_items`. */
    const PRECIO_LISTA_RECARGADO = 180;

    /** @var int Precio del servicio. */
    const PRECIO_SERVICIO = 80;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\Discount */
    protected $discount;

    /** @var \App\Models\Surchage */
    protected $surchage;

    /** @var \App\Models\Service */
    protected $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz General (descuentos con lista)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        /* Sin stock a propósito: acá se miden precios y porcentajes, no el stock. */
        $this->article = Article::create([
            'name'        => 'zz Articulo descuentos con lista',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_BASE,
            'status'      => 'active',
        ]);

        /* El precio de lista es distinto del base: así se ve que el renglón se cobró por lista. */
        $this->article->price_types()->attach($this->lista->id, [
            'final_price' => self::PRECIO_LISTA,
        ]);

        $this->discount = Discount::create([
            'name'       => 'zz Descuento con lista',
            'percentage' => self::PORCENTAJE_DESCUENTO,
            'user_id'    => self::USER_ID,
        ]);

        $this->surchage = Surchage::create([
            'name'       => 'zz Recargo con lista',
            'percentage' => self::PORCENTAJE_RECARGO,
            'user_id'    => self::USER_ID,
        ]);

        $this->service = Service::create([
            'name'    => 'zz Servicio con lista',
            'price'   => self::PRECIO_SERVICIO,
            'user_id' => self::USER_ID,
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
     * Prende o apaga las listas de precio de la cuenta (por query: el controlador lee al dueño
     * fresco en cada request).
     *
     * @param  int  $usa  1 o 0
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * Renglón de artículo ya preciado por el front.
     *
     * @param  float  $price_vender
     * @return array
     */
    protected function renglon_articulo($price_vender)
    {
        return [
            'is_article'   => true,
            'id'           => $this->article->id,
            'name'         => $this->article->name,
            'price_vender' => $price_vender,
            'amount'       => self::CANTIDAD,
        ];
    }

    /**
     * Renglón de servicio tal como lo manda VENDER (`is_service`, `price_vender`, `amount`).
     *
     * @return array
     */
    protected function renglon_servicio()
    {
        return [
            'is_service'   => true,
            'id'           => $this->service->id,
            'name'         => $this->service->name,
            'price_vender' => self::PRECIO_SERVICIO,
            'amount'       => 1,
        ];
    }

    /**
     * El descuento en la forma en que viaja en `discounts[]`: el modelo entero (la SPA manda
     * `get_models_by_id('discount', ...)`); el back lee `id` y `percentage`.
     *
     * @return array
     */
    protected function descuento_del_payload()
    {
        return [
            'id'         => $this->discount->id,
            'name'       => $this->discount->name,
            'percentage' => self::PORCENTAJE_DESCUENTO,
            'user_id'    => self::USER_ID,
        ];
    }

    /**
     * @return array
     */
    protected function recargo_del_payload()
    {
        return [
            'id'         => $this->surchage->id,
            'name'       => $this->surchage->name,
            'percentage' => self::PORCENTAJE_RECARGO,
            'user_id'    => self::USER_ID,
        ];
    }

    /**
     * Payload de POST api/sale de una venta de mostrador con lista, calcado de
     * tests/Feature/Vender/4. `sub_total` y `total` los decide cada test: son justamente lo que
     * se está midiendo.
     *
     * @param  array  $items
     * @param  float  $sub_total
     * @param  float  $total
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($items, $sub_total, $total, $overrides = [])
    {
        return array_merge([
            'client_id'                         => null,
            'address_id'                        => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'price_type_id'                     => $this->lista->id,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'aplicar_recargos_directo_a_items'  => 0,
            'employee_id'                       => null,
            'sub_total'                         => $sub_total,
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
            'discounts'                         => [$this->descuento_del_payload()],
            'surchages'                         => [$this->recargo_del_payload()],
            'items'                             => $items,
        ], $overrides);
    }

    /**
     * Aplica un porcentaje de descuento y después uno de recargo, compuestos, en el mismo orden
     * que la SPA y que `getTotalSale()`.
     *
     * @param  float  $monto
     * @return float
     */
    protected function con_descuento_y_recargo($monto)
    {
        $monto -= $monto * self::PORCENTAJE_DESCUENTO / 100;
        $monto += $monto * self::PORCENTAJE_RECARGO / 100;

        return $monto;
    }

    /**
     * Lo que `getTotalSale()` calcula desde los pivotes de la venta persistida: es el número que
     * usan `update_total_sale()`, `PuntosBaseHelper` y `AfipItemCalculator`.
     *
     * @param  int  $sale_id
     * @return float
     */
    protected function total_recalculado_por_el_back($sale_id)
    {
        return (float) SaleHelper::getTotalSale(Sale::find($sale_id), true, true, false, true);
    }

    /**
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_descuento($sale_id)
    {
        return DB::table('discount_sale')->where('sale_id', $sale_id)->get();
    }

    /**
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function filas_recargo($sale_id)
    {
        return DB::table('sale_surchage')->where('sale_id', $sale_id)->get();
    }

    /**
     * Las aserciones comunes a toda venta guardada de esta clase: la lista, el renglón a precio
     * de lista, los dos pivotes con su porcentaje y el total que cierra por los dos lados.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @param  float                             $precio_de_linea_esperado
     * @param  float                             $total_esperado
     * @return \App\Models\Sale
     */
    protected function assert_venta_con_lista_y_porcentajes($response, $precio_de_linea_esperado, $total_esperado)
    {
        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertEquals($this->lista->id, (int) $sale->price_type_id, 'La venta se guarda con la lista.');

        $renglon = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->assertNotNull($renglon);
        $this->assertEqualsWithDelta(
            $precio_de_linea_esperado,
            (float) $renglon->price,
            self::DELTA,
            'El renglón se persiste al precio que mandó el front (el de lista), no al precio base del artículo.'
        );

        $descuentos = $this->filas_descuento($sale->id);

        $this->assertCount(1, $descuentos, 'El descuento de venta tiene que quedar en discount_sale.');
        $this->assertEquals($this->discount->id, (int) $descuentos->first()->discount_id);
        $this->assertEqualsWithDelta(self::PORCENTAJE_DESCUENTO, (float) $descuentos->first()->percentage, self::DELTA);

        $recargos = $this->filas_recargo($sale->id);

        $this->assertCount(1, $recargos, 'El recargo de venta tiene que quedar en sale_surchage.');
        $this->assertEquals($this->surchage->id, (int) $recargos->first()->surchage_id);
        $this->assertEqualsWithDelta(self::PORCENTAJE_RECARGO, (float) $recargos->first()->percentage, self::DELTA);

        $this->assertEqualsWithDelta($total_esperado, (float) $sale->total, self::DELTA, 'sales.total es el total que vio el vendedor.');

        $this->assertEqualsWithDelta(
            (float) $sale->total,
            $this->total_recalculado_por_el_back($sale->id),
            self::DELTA,
            'Lo persistido (renglones, porcentajes y banderas) tiene que reproducir sales.total: es lo que usan la confirmación, los puntos y la factura.'
        );

        return $sale;
    }

    /**
     * Descuento y recargo comunes: el total es los renglones a precio de lista, menos el 10 %,
     * más el 20 % sobre lo que quedó. Los dos pivotes guardan su porcentaje y el back reproduce el
     * total desde ellos.
     *
     * @group vender
     * @test
     */
    public function venta_con_lista_descuento_y_recargo_guarda_los_pivotes_y_el_total_cierra()
    {
        $this->cuenta_con_listas(1);

        $sub_total = self::PRECIO_LISTA * self::CANTIDAD;
        $total = $this->con_descuento_y_recargo($sub_total);

        $response = $this->postJson('api/sale', $this->payload_venta(
            [$this->renglon_articulo(self::PRECIO_LISTA)],
            $sub_total,
            $total
        ));

        $sale = $this->assert_venta_con_lista_y_porcentajes($response, self::PRECIO_LISTA, $total);

        $this->assertEqualsWithDelta($sub_total, (float) $sale->sub_total, self::DELTA, 'sub_total es la suma de renglones sin porcentajes.');
        $this->assertEquals(0, (int) $sale->aplicar_recargos_directo_a_items);

        /* El número concreto, para que el test no se cumpla por una fórmula que se copie a sí misma. */
        $this->assertEqualsWithDelta(648, (float) $sale->total, self::DELTA, '150 × 4 = 600; − 10 % = 540; + 20 % = 648.');
    }

    /**
     * 🔴 `aplicar_recargos_directo_a_items = 1`: la SPA manda cada renglón con el recargo YA
     * adentro (180 = 150 + 20 %) y un total que no lo vuelve a sumar. El pivote del recargo se
     * guarda igual (queda el rastro de qué recargo se aplicó), la bandera se persiste en 1, y
     * `getTotalSale()` NO lo suma otra vez. El descuento sí se aplica: la opción es solo de
     * recargos y el precio del renglón no lo trae adentro.
     *
     * Un back que ignorara la bandera daría 777,60 (720 − 10 % + 20 %) donde el vendedor vio
     * 648: ese es el mutante que este test caza.
     *
     * @group vender
     * @test
     */
    public function con_aplicar_recargos_directo_a_items_el_recargo_no_se_suma_dos_veces()
    {
        $this->cuenta_con_listas(1);

        $sub_total = self::PRECIO_LISTA_RECARGADO * self::CANTIDAD;
        $total = $sub_total - $sub_total * self::PORCENTAJE_DESCUENTO / 100;

        $response = $this->postJson('api/sale', $this->payload_venta(
            [$this->renglon_articulo(self::PRECIO_LISTA_RECARGADO)],
            $sub_total,
            $total,
            ['aplicar_recargos_directo_a_items' => 1]
        ));

        $sale = $this->assert_venta_con_lista_y_porcentajes($response, self::PRECIO_LISTA_RECARGADO, $total);

        $this->assertEquals(1, (int) $sale->aplicar_recargos_directo_a_items, 'La bandera se persiste como viajó.');

        $this->assertEqualsWithDelta(648, (float) $sale->total, self::DELTA, '180 × 4 = 720; − 10 % = 648; el 20 % ya está en el renglón y no se vuelve a sumar.');

        $this->assertEqualsWithDelta(
            648,
            $this->total_recalculado_por_el_back($sale->id),
            self::DELTA,
            'Con la bandera en 1, getTotalSale() no puede volver a aplicar el recargo del pivote.'
        );
    }

    /**
     * Los servicios reciben el descuento y el recargo SOLO con su bandera prendida. Tres ventas
     * con el mismo servicio y las banderas en (1,1), (0,0) y (1,0): la bandera se persiste como
     * viajó, el pivote del servicio guarda su precio sin tocar, y el total cierra por los dos lados
     * en las tres. Los totales son distintos entre sí, así que el debounce de
     * `venta_ya_cread()` no se come ninguna.
     *
     * @group vender
     * @test
     */
    public function los_servicios_reciben_descuento_y_recargo_solo_con_las_banderas_prendidas()
    {
        $this->cuenta_con_listas(1);

        $articulos = self::PRECIO_LISTA * self::CANTIDAD;
        $sub_total = $articulos + self::PRECIO_SERVICIO;

        $casos = [
            /* [discounts_in_services, surchages_in_services, servicio esperado] */
            [1, 1, $this->con_descuento_y_recargo(self::PRECIO_SERVICIO)],
            [0, 0, self::PRECIO_SERVICIO],
            [1, 0, self::PRECIO_SERVICIO - self::PRECIO_SERVICIO * self::PORCENTAJE_DESCUENTO / 100],
        ];

        foreach ($casos as $caso) {

            list($descuentos_en_servicios, $recargos_en_servicios, $servicio_esperado) = $caso;

            $total = $this->con_descuento_y_recargo($articulos) + $servicio_esperado;

            $response = $this->postJson('api/sale', $this->payload_venta(
                [$this->renglon_articulo(self::PRECIO_LISTA), $this->renglon_servicio()],
                $sub_total,
                $total,
                [
                    'discounts_in_services' => $descuentos_en_servicios,
                    'surchages_in_services' => $recargos_en_servicios,
                ]
            ));

            $sale = $this->assert_venta_con_lista_y_porcentajes($response, self::PRECIO_LISTA, $total);

            $etiqueta = 'banderas ('.$descuentos_en_servicios.', '.$recargos_en_servicios.')';

            $this->assertEquals($descuentos_en_servicios, (int) $sale->discounts_in_services, 'discounts_in_services se persiste como viajó: '.$etiqueta);
            $this->assertEquals($recargos_en_servicios, (int) $sale->surchages_in_services, 'surchages_in_services se persiste como viajó: '.$etiqueta);

            $servicio = DB::table('sale_service')->where('sale_id', $sale->id)->first();

            $this->assertNotNull($servicio, 'El servicio tiene que quedar en sale_service: '.$etiqueta);
            $this->assertEqualsWithDelta(self::PRECIO_SERVICIO, (float) $servicio->price, self::DELTA, 'El pivote del servicio guarda el precio sin porcentajes: '.$etiqueta);
        }
    }

    /**
     * Tener descuentos y recargos no cambia la regla de la lista: en una cuenta con listas, sin
     * lista es 422 y no queda ningún pivote de descuento ni de recargo (el rechazo va antes de la
     * transacción).
     *
     * @group vender
     * @test
     */
    public function sin_lista_con_descuentos_y_recargos_responde_422_y_no_escribe_pivotes()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = Sale::where('user_id', self::USER_ID)->count();
        $descuentos_antes = DB::table('discount_sale')->where('discount_id', $this->discount->id)->count();
        $recargos_antes = DB::table('sale_surchage')->where('surchage_id', $this->surchage->id)->count();

        $sub_total = self::PRECIO_LISTA * self::CANTIDAD;

        $response = $this->postJson('api/sale', $this->payload_venta(
            [$this->renglon_articulo(self::PRECIO_LISTA)],
            $sub_total,
            $this->con_descuento_y_recargo($sub_total),
            ['price_type_id' => null]
        ));

        $response->assertStatus(422);
        $this->assertTrue((bool) $response->json('sin_lista_de_precios'));
        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));

        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count(), 'Un 422 no puede haber creado la venta.');
        $this->assertEquals($descuentos_antes, DB::table('discount_sale')->where('discount_id', $this->discount->id)->count(), 'Un 422 no puede haber escrito en discount_sale.');
        $this->assertEquals($recargos_antes, DB::table('sale_surchage')->where('surchage_id', $this->surchage->id)->count(), 'Un 422 no puede haber escrito en sale_surchage.');
    }
}
