<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\AperturaCaja;
use App\Models\Article;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): venta de contado cobrada con VARIOS
 * métodos de pago (el reparto del modal de Vender, `selected_payment_methods[]`) y la caja.
 *
 * QUÉ FIJA Y POR QUÉ. Es el camino por el que la plata de una venta de mostrador llega a la
 * caja: `SaleHelper::attachSelectedPaymentMethods()` adjunta un pivote
 * `current_acount_payment_method_sale` por cada renglón del reparto
 * (`PaymentMethodHelper::attach_payment_methods()`), y después `SaleCajaHelper::check_caja()`
 * crea UN `movimiento_cajas` por cada pivote que tenga `caja_id`. Lo que estos tests dejan
 * escrito:
 *  - N renglones válidos en el reparto → N pivotes, cada uno con su importe y su caja;
 *  - un movimiento de caja por cada método CON caja, con el importe de ese método, colgado de la
 *    apertura vigente; el método sin caja adjunta el pivote pero no mueve ninguna caja;
 *  - la suma de los importes es el total de la venta, y `cajas.saldo` sube exactamente eso;
 *  - la lista de precios no cambia nada de lo anterior: la misma venta con `price_type_id` da
 *    los mismos pivotes y los mismos movimientos, y en una cuenta con listas la venta SIN lista
 *    es 422 antes de tocar la caja (ni pivote ni movimiento ni saldo).
 *
 * Lo que ya cubre tests/Feature/Sales/6_Attach_Payment_Methods_Test.php y acá NO se repite: un
 * renglón sin `current_acount_payment_method_id` se saltea sin descartar los siguientes, un id
 * inexistente no rompe y no adjunta, y un solo método con caja genera su movimiento.
 *
 * La caja es PROPIA del test y se abre acá (`CajaAperturaHelper`, como CajaSeeder), porque una
 * caja sin apertura no puede recibir movimientos. DatabaseTransactions la revierte, y además el
 * tearDown la borra por nombre —mismo seguro que Sales/6 y Reportes/4— por si alguna corrida
 * muere a mitad de camino. Listas y artículo se crean adentro de la transacción con prefijo
 * `zz`, y `users.listas_de_precio` se restaura en tearDown.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Varios_metodos_de_pago_y_caja_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var int Efectivo, del catálogo global `current_acount_payment_methods`. */
    const METODO_EFECTIVO = 3;

    /** @var int Transferencia, del mismo catálogo. */
    const METODO_TRANSFERENCIA = 4;

    /** @var string Nombre de la caja propia, por el que el tearDown la busca para borrarla. */
    const NOMBRE_CAJA = 'zz Caja vender varios metodos de pago';

    /** @var float Tolerancia de un centavo. */
    const DELTA = 0.01;

    /** @var int `articles.final_price`: el precio base. */
    const PRECIO_BASE = 100;

    /** @var int Precio del artículo en la lista: el que se cobra en la cuenta con listas. */
    const PRECIO_LISTA = 150;

    /** @var int Cantidad del renglón. Total: 300. */
    const CANTIDAD = 2;

    /** @var int Parte del total que va en Efectivo. */
    const IMPORTE_EFECTIVO = 180;

    /** @var int Parte del total que va en Transferencia. 180 + 120 = 300. */
    const IMPORTE_TRANSFERENCIA = 120;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\Caja Caja propia del test, abierta. */
    protected $caja;

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
            'name'     => 'zz General (varios metodos de pago)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        /* Sin stock a propósito: acá se mide el cobro, no el stock. */
        $this->article = Article::create([
            'name'        => 'zz Articulo varios metodos de pago',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_BASE,
            'status'      => 'active',
        ]);

        $this->article->price_types()->attach($this->lista->id, [
            'final_price' => self::PRECIO_LISTA,
        ]);

        /*
         * Caja propia, abierta. `abrir_caja()` crea la apertura y deja
         * `cajas.current_apertura_caja_id` apuntando a ella: sin eso
         * `MovimientoCajaHelper::get_current_aperutra_caja()` no tiene de dónde colgar el
         * movimiento. Mismo paso que hace CajaSeeder.
         */
        $this->caja = Caja::create([
            'num'     => 900021,
            'name'    => self::NOMBRE_CAJA,
            'user_id' => self::USER_ID,
            'saldo'   => 0,
        ]);

        (new CajaAperturaHelper($this->caja->id))->abrir_caja();

        $this->caja = Caja::find($this->caja->id);
    }

    protected function tearDown(): void
    {
        if (!is_null($this->user) && !is_null($this->listas_original)) {
            User::where('id', self::USER_ID)->update(['listas_de_precio' => $this->listas_original]);
        }

        /* Seguro por si la transacción no revirtió (corrida muerta a mitad de camino). */
        $cajas = Caja::where('name', self::NOMBRE_CAJA)->get();

        foreach ($cajas as $caja) {
            MovimientoCaja::where('caja_id', $caja->id)->delete();
            AperturaCaja::where('caja_id', $caja->id)->delete();
            $caja->delete();
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
     * Un renglón del reparto tal como lo arma el modal de Vender: método, importe y caja
     * (`caja_id` en 0 es "sin caja", que es lo que manda el select sin elegir).
     *
     * @param  int    $current_acount_payment_method_id
     * @param  float  $amount
     * @param  int    $caja_id
     * @return array
     */
    protected function renglon_reparto($current_acount_payment_method_id, $amount, $caja_id)
    {
        return [
            'current_acount_payment_method_id' => $current_acount_payment_method_id,
            'amount'                           => $amount,
            'caja_id'                          => $caja_id,
            'amount_cotizado'                  => null,
            'cotizacion'                       => null,
            'moneda_id'                        => 1,
        ];
    }

    /**
     * El reparto de los tests: Efectivo y Transferencia, los dos a la caja propia.
     *
     * @return array
     */
    protected function reparto_en_dos_con_caja()
    {
        return [
            $this->renglon_reparto(self::METODO_EFECTIVO, self::IMPORTE_EFECTIVO, $this->caja->id),
            $this->renglon_reparto(self::METODO_TRANSFERENCIA, self::IMPORTE_TRANSFERENCIA, $this->caja->id),
        ];
    }

    /**
     * Payload de POST api/sale de una venta de mostrador cobrada por reparto, calcado de
     * tests/Feature/Vender/4 y Sales/6. `current_acount_payment_method_id` va en null porque en
     * el reparto el método único no se usa (`attachSelectedPaymentMethods()` toma el array).
     *
     * @param  array  $selected_payment_methods
     * @param  int    $price_vender
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($selected_payment_methods, $price_vender, $overrides = [])
    {
        $total = $price_vender * self::CANTIDAD;

        return array_merge([
            'client_id'                         => null,
            'address_id'                        => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 0,
            'to_check'                          => 0,
            'price_type_id'                     => null,
            'current_acount_payment_method_id'  => null,
            'selected_payment_methods'          => $selected_payment_methods,
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
            'items'                             => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => $price_vender,
                    'amount'       => self::CANTIDAD,
                ],
            ],
        ], $overrides);
    }

    /**
     * Pivotes de métodos de pago de una venta, ordenados por método, leídos con DB::table.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function pivotes($sale_id)
    {
        return DB::table('current_acount_payment_method_sale')
                    ->where('sale_id', $sale_id)
                    ->orderBy('current_acount_payment_method_id')
                    ->get();
    }

    /**
     * Movimientos de caja de una venta, ordenados por id.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos($sale_id)
    {
        return MovimientoCaja::where('sale_id', $sale_id)->orderBy('id')->get();
    }

    /**
     * @return float
     */
    protected function saldo_de_la_caja()
    {
        return (float) DB::table('cajas')->where('id', $this->caja->id)->value('saldo');
    }

    /**
     * Las aserciones del reparto en dos con caja, compartidas por el caso sin lista y el caso
     * con lista: son las mismas a propósito, porque eso es lo que se afirma.
     *
     * @param  \App\Models\Sale  $sale
     * @return void
     */
    protected function assert_reparto_en_dos_con_caja($sale)
    {
        $pivotes = $this->pivotes($sale->id);

        $this->assertCount(2, $pivotes, 'Dos renglones válidos en el reparto son dos pivotes.');

        $this->assertEquals(self::METODO_EFECTIVO, (int) $pivotes[0]->current_acount_payment_method_id);
        $this->assertEqualsWithDelta(self::IMPORTE_EFECTIVO, (float) $pivotes[0]->amount, self::DELTA);
        $this->assertEquals($this->caja->id, (int) $pivotes[0]->caja_id);

        $this->assertEquals(self::METODO_TRANSFERENCIA, (int) $pivotes[1]->current_acount_payment_method_id);
        $this->assertEqualsWithDelta(self::IMPORTE_TRANSFERENCIA, (float) $pivotes[1]->amount, self::DELTA);
        $this->assertEquals($this->caja->id, (int) $pivotes[1]->caja_id);

        $this->assertEqualsWithDelta(
            (float) $sale->total,
            (float) $pivotes[0]->amount + (float) $pivotes[1]->amount,
            self::DELTA,
            'La suma de los importes del reparto es el total de la venta.'
        );

        $movimientos = $this->movimientos($sale->id);

        $this->assertCount(2, $movimientos, 'Un movimiento de caja por cada método con caja.');

        $ingresos = [];

        foreach ($movimientos as $movimiento) {

            $this->assertEquals($this->caja->id, (int) $movimiento->caja_id);
            $this->assertEquals(
                $this->caja->current_apertura_caja_id,
                (int) $movimiento->apertura_caja_id,
                'El movimiento cuelga de la apertura vigente de la caja.'
            );
            $this->assertNull($movimiento->egreso, 'Un cobro es un ingreso, nunca un egreso.');

            $ingresos[] = (float) $movimiento->ingreso;
        }

        sort($ingresos);

        $this->assertEqualsWithDelta(self::IMPORTE_TRANSFERENCIA, $ingresos[0], self::DELTA);
        $this->assertEqualsWithDelta(self::IMPORTE_EFECTIVO, $ingresos[1], self::DELTA);

        $this->assertEqualsWithDelta(
            (float) $sale->total,
            $this->saldo_de_la_caja(),
            self::DELTA,
            'La caja sube exactamente lo que se cobró en ella.'
        );
    }

    /**
     * Reparto en dos métodos, los dos a la caja: dos pivotes, dos movimientos de caja con el
     * importe de cada método, suma = total y la caja sube el total. Cuenta sin listas: es el
     * reparto a secas, la línea base contra la que se compara el caso con lista.
     *
     * @group vender
     * @test
     */
    public function reparto_en_dos_metodos_con_caja_genera_dos_pivotes_y_un_movimiento_por_metodo()
    {
        $this->cuenta_con_listas(0);

        $response = $this->postJson('api/sale', $this->payload_venta($this->reparto_en_dos_con_caja(), self::PRECIO_LISTA));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertNull($sale->price_type_id);

        $this->assert_reparto_en_dos_con_caja($sale);
    }

    /**
     * Un método con caja y otro sin caja (`caja_id` en 0, el select sin elegir): los dos pivotes
     * se adjuntan —el segundo con `caja_id` null—, pero solo el primero mueve la caja. La caja
     * sube el importe de ese método, no el total.
     *
     * @group vender
     * @test
     */
    public function un_metodo_con_caja_y_otro_sin_caja_solo_mueve_la_caja_del_primero()
    {
        $this->cuenta_con_listas(0);

        $reparto = [
            $this->renglon_reparto(self::METODO_EFECTIVO, self::IMPORTE_EFECTIVO, $this->caja->id),
            $this->renglon_reparto(self::METODO_TRANSFERENCIA, self::IMPORTE_TRANSFERENCIA, 0),
        ];

        $response = $this->postJson('api/sale', $this->payload_venta($reparto, self::PRECIO_LISTA));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $pivotes = $this->pivotes($sale->id);

        $this->assertCount(2, $pivotes, 'El método sin caja también se adjunta: el cobro existe aunque no mueva caja.');
        $this->assertEquals($this->caja->id, (int) $pivotes[0]->caja_id);
        $this->assertNull($pivotes[1]->caja_id, 'caja_id 0 se persiste como null en el pivote.');

        $movimientos = $this->movimientos($sale->id);

        $this->assertCount(1, $movimientos, 'Solo el método con caja genera movimiento.');
        $this->assertEqualsWithDelta(self::IMPORTE_EFECTIVO, (float) $movimientos->first()->ingreso, self::DELTA);
        $this->assertEquals($this->caja->id, (int) $movimientos->first()->caja_id);

        $this->assertEqualsWithDelta(self::IMPORTE_EFECTIVO, $this->saldo_de_la_caja(), self::DELTA, 'La caja sube solo lo que entró en ella.');
    }

    /**
     * CON LISTA: la misma venta en una cuenta con listas y con `price_type_id` da exactamente los
     * mismos pivotes y los mismos movimientos que sin lista. La lista precia los renglones; el
     * reparto es lo que el vendedor tipeó en el modal y la caja recibe eso.
     *
     * @group vender
     * @test
     */
    public function con_lista_el_reparto_y_la_caja_son_los_mismos()
    {
        $this->cuenta_con_listas(1);

        $response = $this->postJson('api/sale', $this->payload_venta($this->reparto_en_dos_con_caja(), self::PRECIO_LISTA, [
            'price_type_id' => $this->lista->id,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertEquals($this->lista->id, (int) $sale->price_type_id, 'La venta se guarda con la lista.');

        $renglon = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->assertEqualsWithDelta(self::PRECIO_LISTA, (float) $renglon->price, self::DELTA, 'El renglón se cobró a precio de lista.');

        $this->assert_reparto_en_dos_con_caja($sale);
    }

    /**
     * SIN LISTA en una cuenta con listas: 422 antes de la transacción, así que el reparto no
     * llega a ningún lado: ni venta, ni pivotes, ni movimientos, y la caja queda en cero. Es la
     * garantía de que el rechazo nuevo no deja plata contada en una caja por una venta que no
     * existe.
     *
     * @group vender
     * @test
     */
    public function sin_lista_en_cuenta_con_listas_el_reparto_no_llega_a_la_caja()
    {
        $this->cuenta_con_listas(1);

        $ventas_antes = Sale::where('user_id', self::USER_ID)->count();
        $movimientos_antes = MovimientoCaja::where('caja_id', $this->caja->id)->count();

        $response = $this->postJson('api/sale', $this->payload_venta($this->reparto_en_dos_con_caja(), self::PRECIO_LISTA, [
            'price_type_id' => null,
        ]));

        $response->assertStatus(422);
        $this->assertTrue((bool) $response->json('sin_lista_de_precios'));
        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));

        $this->assertEquals($ventas_antes, Sale::where('user_id', self::USER_ID)->count(), 'Un 422 no puede haber creado la venta.');
        $this->assertEquals($movimientos_antes, MovimientoCaja::where('caja_id', $this->caja->id)->count(), 'Un 422 no puede haber movido la caja.');
        $this->assertEqualsWithDelta(0, $this->saldo_de_la_caja(), self::DELTA, 'La caja sigue en cero.');
    }
}
