<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\PaymentMethodHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tanda 2 de la misión vender-lista-obligatoria (18/9/2026), ítem A7: una venta de CONTADO no se
 * guarda sin método de pago, ni en el alta ni en la edición, y el método único con id 0 (el
 * placeholder del select de VENDER) no se adjunta nunca.
 *
 * EL CASO REAL: el chequeo del front (`chequeos/payment_methods.js`) estuvo apagado del 4/3/2026
 * a la tanda 1. En ese lapso una venta de contado con el select en "Seleccione método de pago"
 * llegaba con `current_acount_payment_method_id: 0`, `SaleHelper::attachSelectedPaymentMethods()`
 * adjuntaba ese 0 tal cual (una fila del pivote que no apunta a ningún método) y `SaleCajaHelper`
 * no creaba movimiento de caja: venta "cobrada" sin método y sin caja, sin un solo error.
 *
 * Lo que fijan estos tests, en dos mitades:
 *  - el RECHAZO (422 con `sin_metodo_de_pago: true`, `sales` no crece; en el PUT, la venta queda
 *    como estaba), con el 0 explícito, con null + reparto vacío (la forma exacta del payload de la
 *    SPA) y con un id que no existe en el catálogo;
 *  - los casos en que NO se rechaza y no pueden regresar: el método único válido se adjunta, el
 *    reparto válido adjunta sus N renglones, la venta a cuenta corriente no entra en la regla, y un
 *    request que no habla del cobro (ninguna de las dos claves) no se rechaza ni adjunta nada.
 *
 * DatabaseTransactions sobre la base sembrada del slot. El artículo, el cliente y la lista de
 * precios se crean adentro de la transacción con prefijo `zz`. La lista viaja explícita en cada
 * POST para que estos tests midan el método de pago sin depender de cómo esté
 * `users.listas_de_precio` en la base del slot (el 422 de la lista es de la tanda 1 y tiene su
 * propia suite).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Venta_de_contado_sin_metodo_de_pago_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Efectivo: el default del select de VENDER, sembrado fijo por CurrentAcountPaymentMethodSeeder. */
    const EFECTIVO = 3;

    /** @var int Transferencia, del mismo catálogo. */
    const TRANSFERENCIA = 4;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\PriceType */
    protected $lista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        if (!CurrentAcountPaymentMethod::where('id', self::EFECTIVO)->exists()
            || !CurrentAcountPaymentMethod::where('id', self::TRANSFERENCIA)->exists()) {
            $this->markTestSkipped('La base de testing no tiene el catálogo de métodos de pago sembrado (ids 3 y 4).');
        }

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (venta sin metodo de pago)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        /*
         * Sin stock a propósito: `SaleHelper::usa_stock()` da false con stock null y el alta no
         * descuenta nada. Acá se mide el cobro, no el stock.
         */
        $this->article = Article::create([
            'name'        => 'zz Articulo venta sin metodo de pago',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'status'      => 'active',
        ]);
    }

    /**
     * Cliente propio del test, con su cuenta en pesos (la necesita `CurrentAcountFromSaleHelper`
     * cuando la venta entra a la cuenta corriente).
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente venta sin metodo de pago '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        return $client;
    }

    /**
     * El payload de POST api/sale tal como lo manda VENDER para una venta de mostrador, calcado
     * de tests/Feature/Vender/4 y Sales/6: sin cliente, un renglón ya preciado, y las dos claves
     * del cobro con la forma EXACTA del select en el placeholder y el reparto vacío
     * (`current_acount_payment_method_id: 0`, `selected_payment_methods: []`). Cada test pisa lo
     * que necesita.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_venta($overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                        => null,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => 0,
            'selected_payment_methods'         => [],
            'price_type_id'                    => $this->lista->id,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'moneda_id'                        => 1,
            'discount_stock'                   => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
        ], $overrides);
    }

    /**
     * Venta de mostrador ya guardada, sin ningún método adjunto, para los tests del PUT. Va
     * directo a la base (molde de Sales/9 y Vender/4): lo que se mide es qué hace update() con el
     * cobro, no el alta. Sin métodos y sin caja es editable aunque el comercio tenga cajas
     * (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`).
     *
     * @return \App\Models\Sale
     */
    protected function venta_de_contado_guardada()
    {
        return Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => null,
            'price_type_id'              => $this->lista->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => self::PRECIO * self::CANTIDAD,
            'total'                      => self::PRECIO * self::CANTIDAD,
        ]);
    }

    /**
     * Payload mínimo y válido de PUT api/sale/{id}, calcado de tests/Feature/Vender/4. SIN las dos
     * claves del cobro a propósito: cada test decide si viajan y con qué.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update($overrides = [])
    {
        $total = self::PRECIO * self::CANTIDAD;

        return array_merge([
            'client_id'                  => null,
            'save_current_acount'        => 0,
            'omitir_en_cuenta_corriente' => 0,
            'to_check'                   => 0,
            'checked'                    => 0,
            'confirmed'                  => 0,
            'discounts_in_services'      => 1,
            'surchages_in_services'      => 1,
            'discount_stock'             => 0,
            'sub_total'                  => $total,
            'total'                      => $total,
            'moneda_id'                  => 1,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO,
                    'amount'       => self::CANTIDAD,
                ],
            ],
            'discounts'                  => [],
            'surchages'                  => [],
            'returned_items'             => [],
        ], $overrides);
    }

    /**
     * @return int
     */
    protected function cantidad_de_ventas()
    {
        return Sale::where('user_id', self::USER_ID)->count();
    }

    /**
     * Las filas crudas del pivote de la venta, sin pasar por la relación: la relación ignora una
     * fila con id 0 (no hay método 0 contra el cual unir) y justamente eso es lo que escondía el
     * bug. Acá se cuenta lo que hay en la tabla.
     *
     * @param  int  $sale_id
     * @return \Illuminate\Support\Collection
     */
    protected function pivotes_de_cobro($sale_id)
    {
        return DB::table('current_acount_payment_method_sale')
                    ->where('sale_id', $sale_id)
                    ->orderBy('current_acount_payment_method_id')
                    ->get();
    }

    /**
     * Las aserciones del rechazo, compartidas: 422, la bandera que la SPA nueva lee y el texto que
     * la SPA vieja muestra en su catch genérico.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return void
     */
    protected function assert_rechazo_sin_metodo($response)
    {
        $response->assertStatus(422);

        $this->assertTrue(
            (bool) $response->json('sin_metodo_de_pago'),
            'El 422 tiene que llevar sin_metodo_de_pago: true, que es lo que distingue este rechazo en la SPA.'
        );

        $this->assertEquals(PaymentMethodHelper::mensaje_sin_metodo_de_pago(), $response->json('message'));
    }

    /**
     * 🔴 EL CASO DEL BUG: venta de mostrador con el select en el placeholder (0) y el reparto vacío,
     * o sea la forma exacta con la que la SPA la mandaba. Antes salía 201 con un pivote apuntando
     * al método 0 y sin caja. Ahora es 422 y no se crea nada.
     *
     * @group sales
     * @test
     */
    public function contado_con_el_cero_del_placeholder_responde_422_y_no_crea_la_venta()
    {
        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta());

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals(
            $ventas_antes,
            $this->cantidad_de_ventas(),
            'Un 422 no puede haber creado la venta: el rechazo va antes de la transacción.'
        );
    }

    /**
     * Null en el select y reparto vacío: la otra forma de "ninguno" que manda la SPA. Mismo 422.
     *
     * @group sales
     * @test
     */
    public function contado_con_null_y_reparto_vacio_responde_422()
    {
        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => null,
            'selected_payment_methods'         => [],
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * Con cliente pero omitida en cuenta corriente también es de contado (es la misma condición
     * con la que `attachSelectedPaymentMethods()` adjunta métodos): sin método, 422.
     *
     * @group sales
     * @test
     */
    public function con_cliente_omitido_en_cuenta_corriente_y_sin_metodo_responde_422()
    {
        $client = $this->cliente();

        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * Un id que no existe en el catálogo no es un método: adjuntarlo dejaría el mismo pivote
     * huérfano que el 0. 422, igual que el placeholder.
     *
     * @group sales
     * @test
     */
    public function contado_con_un_metodo_unico_inexistente_responde_422()
    {
        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => 999999,
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * Un reparto cuyos renglones no son métodos (ids inexistentes) tampoco cobra nada:
     * `attach_payment_methods()` los saltearía todos y la venta quedaría sin caja. 422.
     *
     * @group sales
     * @test
     */
    public function contado_con_un_reparto_de_metodos_inexistentes_responde_422()
    {
        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => null,
            'selected_payment_methods'         => [
                ['current_acount_payment_method_id' => 999999, 'amount' => self::PRECIO * self::CANTIDAD],
            ],
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * El camino normal: método único Efectivo → 201 y UN pivote con el id 3, ni uno más.
     *
     * @group sales
     * @test
     */
    public function contado_con_metodo_unico_valido_guarda_la_venta_con_ese_metodo()
    {
        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => self::EFECTIVO,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);

        $pivotes = $this->pivotes_de_cobro($sale->id);

        $this->assertCount(1, $pivotes, 'El método único tiene que dejar exactamente un pivote.');
        $this->assertEquals(self::EFECTIVO, (int) $pivotes[0]->current_acount_payment_method_id);
        $this->assertEquals(self::PRECIO * self::CANTIDAD, (float) $pivotes[0]->amount, 'El método único cobra el total de la venta.');
        $this->assertEquals(1, $sale->current_acount_payment_methods()->count(), 'La relación tiene que ver el mismo método.');
    }

    /**
     * El reparto en dos métodos → 201 y dos pivotes, uno por método, con sus montos. La lista de
     * precios no cambia nada acá: viaja igual que en el resto de la suite.
     *
     * @group sales
     * @test
     */
    public function contado_con_reparto_valido_guarda_la_venta_con_sus_n_metodos()
    {
        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => null,
            'selected_payment_methods'         => [
                ['current_acount_payment_method_id' => self::EFECTIVO,      'amount' => 150],
                ['current_acount_payment_method_id' => self::TRANSFERENCIA, 'amount' => 50],
            ],
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);

        $pivotes = $this->pivotes_de_cobro($sale->id);

        $this->assertCount(2, $pivotes, 'El reparto en dos métodos tiene que dejar dos pivotes.');
        $this->assertEquals(self::EFECTIVO, (int) $pivotes[0]->current_acount_payment_method_id);
        $this->assertEquals(150, (float) $pivotes[0]->amount);
        $this->assertEquals(self::TRANSFERENCIA, (int) $pivotes[1]->current_acount_payment_method_id);
        $this->assertEquals(50, (float) $pivotes[1]->amount);
    }

    /**
     * Un reparto con un renglón basura y uno válido adjunta el válido (es lo que ya fijaba
     * Sales/6 para el loop de `attach_payment_methods()`); acá se fija que la regla nueva no lo
     * rechaza: hay al menos un método real.
     *
     * @group sales
     * @test
     */
    public function contado_con_un_reparto_con_un_renglon_valido_y_uno_basura_no_se_rechaza()
    {
        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => null,
            'selected_payment_methods'         => [
                ['amount' => 30],
                ['current_acount_payment_method_id' => 0, 'amount' => 30],
                ['current_acount_payment_method_id' => self::EFECTIVO, 'amount' => 140],
            ],
        ]));

        $response->assertStatus(201);

        $pivotes = $this->pivotes_de_cobro($response->json('model.id'));

        $this->assertCount(1, $pivotes);
        $this->assertEquals(self::EFECTIVO, (int) $pivotes[0]->current_acount_payment_method_id);
    }

    /**
     * La venta A CUENTA CORRIENTE no entra en la regla: no se cobra en el acto, así que no lleva
     * método de pago (`attachSelectedPaymentMethods()` tampoco adjunta nada para ella). Con el
     * select en el placeholder, 201, sin pivotes y con su movimiento de cuenta corriente.
     *
     * @group sales
     * @test
     */
    public function con_cliente_en_cuenta_corriente_y_sin_metodo_no_aplica_y_guarda()
    {
        $client = $this->cliente();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 1,
        ]));

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertCount(0, $this->pivotes_de_cobro($sale->id), 'Una venta a cuenta corriente no adjunta métodos de pago.');
        $this->assertTrue(
            CurrentAcount::where('sale_id', $sale->id)->exists(),
            'La venta a cuenta corriente tiene que dejar su movimiento, como siempre.'
        );
    }

    /**
     * `save_current_acount` (ítem A8 de la misma tanda, vive acá porque es el mismo POST con
     * cliente de arriba): sin la clave, la venta con cliente entra a la cuenta corriente, que es
     * la semántica de la columna (NOT NULL, default 1) y lo que ya hacía
     * `CreateSaleOrderHelper::createSale()`. Hasta hoy `store()` la asignaba pelada y un request
     * sin la clave insertaba null en una columna NOT NULL: 500 sin causa a la vista. La SPA la
     * manda siempre; esto cubre a cualquier otro llamador.
     *
     * @group sales
     * @test
     */
    public function post_sin_save_current_acount_con_cliente_entra_a_la_cuenta_corriente()
    {
        $client = $this->cliente();

        $payload = $this->payload_venta([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 0,
        ]);

        unset($payload['save_current_acount']);

        $response = $this->postJson('api/sale', $payload);

        $response->assertStatus(201);

        $sale = Sale::find($response->json('model.id'));

        $this->assertNotNull($sale);
        $this->assertSame(1, (int) $sale->save_current_acount, 'Sin la clave, save_current_acount tiene que quedar en 1.');
        $this->assertTrue(
            CurrentAcount::where('sale_id', $sale->id)->exists(),
            'Con save_current_acount en 1 la venta tiene que entrar a la cuenta corriente del cliente.'
        );
    }

    /**
     * 🔴 El default de `save_current_acount` (1) lo tiene que ver TAMBIÉN el límite de crédito.
     *
     * Cuando el default vivía solo en el create() de store(), `LimiteCreditoHelper::validar_venta_nueva()`
     * armaba la venta hipotética con el request crudo: null → "no va a la cuenta corriente" → no
     * hay tope que controlar → 201, y la venta se guardaba con 1 y su movimiento por encima del
     * límite. Un POST sin la clave contra un cliente con límite excedido esquivaba el 422 que el
     * mismo POST con la clave recibe. Lo encontró el revisor adversarial de la tanda 2 (18/9/2026).
     *
     * @group sales
     * @test
     */
    public function post_sin_save_current_acount_contra_un_cliente_con_limite_excedido_responde_422()
    {
        $client = $this->cliente();

        CreditAccount::where('model_name', 'client')
            ->where('model_id', $client->id)
            ->where('moneda_id', 1)
            ->update(['limite_credito' => 1]);

        $ventas_antes = $this->cantidad_de_ventas();

        $payload = $this->payload_venta([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 0,
        ]);

        unset($payload['save_current_acount']);

        $con_la_clave = $this->postJson('api/sale', $this->payload_venta([
            'client_id'                  => $client->id,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 1,
        ]));

        $con_la_clave->assertStatus(422);
        $this->assertTrue((bool) $con_la_clave->json('error_limite_credito'), 'Con la clave, el tope frena: es la referencia.');

        $sin_la_clave = $this->postJson('api/sale', $payload);

        $sin_la_clave->assertStatus(422);
        $this->assertTrue((bool) $sin_la_clave->json('error_limite_credito'), 'Sin la clave el default es 1: el tope tiene que frenar igual.');

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas(), 'Ninguno de los dos POST puede haber creado la venta.');
    }

    /**
     * 🔴 La validación tiene que espejar la rama que después adjunta: con un reparto no vacío
     * manda el reparto, y el método único no cuenta. Si valiera cualquiera de los dos (OR), un
     * método único válido con un reparto de renglones inválidos pasaba la validación y el attach
     * tomaba la rama del reparto, salteaba los renglones y la venta quedaba con CERO métodos.
     *
     * @group sales
     * @test
     */
    public function metodo_unico_valido_con_un_reparto_de_renglones_invalidos_responde_422()
    {
        $ventas_antes = $this->cantidad_de_ventas();

        $response = $this->postJson('api/sale', $this->payload_venta([
            'current_acount_payment_method_id' => 3,
            'selected_payment_methods'         => [
                ['current_acount_payment_method_id' => 0, 'amount' => self::PRECIO * self::CANTIDAD],
            ],
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $this->assertEquals($ventas_antes, $this->cantidad_de_ventas());
    }

    /**
     * Un request que NO habla del cobro —ninguna de las dos claves— no se rechaza y no adjunta
     * nada: no está cobrando con un placeholder, no está diciendo nada. Es la puerta de los
     * llamadores que no son VENDER (y de los tests que miden otra cosa), y queda fijada a
     * propósito: si alguien la cierra, tiene que ser una decisión y no un accidente.
     *
     * @group sales
     * @test
     */
    public function un_request_que_no_habla_del_cobro_no_se_rechaza_y_no_adjunta_nada()
    {
        $payload = $this->payload_venta();

        unset($payload['current_acount_payment_method_id']);
        unset($payload['selected_payment_methods']);

        $response = $this->postJson('api/sale', $payload);

        $response->assertStatus(201);

        $this->assertCount(0, $this->pivotes_de_cobro($response->json('model.id')));
    }

    /**
     * 🔴 PUT de una venta de contado con el select en el placeholder y el reparto vacío: 422 y la
     * venta queda EXACTAMENTE como estaba (las observaciones y el total distintos del payload son
     * la prueba: el rechazo va antes de la transacción). Sin esto, el update hacía detach y
     * adjuntaba el 0.
     *
     * @group sales
     * @test
     */
    public function put_de_contado_con_el_cero_del_placeholder_responde_422_y_no_toca_la_venta()
    {
        $venta = $this->venta_de_contado_guardada();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'current_acount_payment_method_id' => 0,
            'selected_payment_methods'         => [],
            'total'                            => 999,
            'sub_total'                        => 999,
            'observations'                     => 'no tendria que guardarse',
        ]));

        $this->assert_rechazo_sin_metodo($response);

        $despues = Sale::find($venta->id);

        $this->assertEquals(self::PRECIO * self::CANTIDAD, (float) $despues->total, 'El total no se tiene que haber tocado.');
        $this->assertNull($despues->observations, 'Las observaciones no se tienen que haber tocado.');
        $this->assertCount(0, $this->pivotes_de_cobro($venta->id), 'No se puede haber adjuntado el 0.');
    }

    /**
     * PUT con un método válido: 200 y la venta queda cobrada con ese método.
     *
     * @group sales
     * @test
     */
    public function put_de_contado_con_metodo_valido_la_deja_cobrada_con_ese_metodo()
    {
        $venta = $this->venta_de_contado_guardada();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'current_acount_payment_method_id' => self::EFECTIVO,
            'selected_payment_methods'         => [],
        ]));

        $response->assertStatus(200);

        $pivotes = $this->pivotes_de_cobro($venta->id);

        $this->assertCount(1, $pivotes);
        $this->assertEquals(self::EFECTIVO, (int) $pivotes[0]->current_acount_payment_method_id);
    }

    /**
     * PUT que no habla del cobro (ninguna de las dos claves): no opina, 200, y la venta sigue sin
     * método como estaba. Mismo criterio que en el alta.
     *
     * @group sales
     * @test
     */
    public function put_sin_las_claves_del_cobro_no_opina()
    {
        $venta = $this->venta_de_contado_guardada();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update());

        $response->assertStatus(200);

        $this->assertCount(0, $this->pivotes_de_cobro($venta->id));
    }

    /**
     * PUT que pasa la venta a cuenta corriente (cliente, no omitida) con el select en el
     * placeholder: no es de contado después del update, así que no aplica. 200 y sin pivotes.
     *
     * @group sales
     * @test
     */
    public function put_que_pasa_la_venta_a_cuenta_corriente_no_aplica()
    {
        $client = $this->cliente();

        $venta = $this->venta_de_contado_guardada();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update([
            'client_id'                        => $client->id,
            'omitir_en_cuenta_corriente'       => 0,
            'save_current_acount'              => 1,
            'current_acount_payment_method_id' => 0,
            'selected_payment_methods'         => [],
        ]));

        $response->assertStatus(200);

        $this->assertEquals($client->id, (int) Sale::find($venta->id)->client_id);
        $this->assertCount(0, $this->pivotes_de_cobro($venta->id));
    }
}
