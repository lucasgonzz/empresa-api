<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\SellerCommission;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tanda 2 de la misión vender-lista-obligatoria (18/9/2026), ítems A3 y A6: la venta que nace al
 * confirmar un presupuesto se lleva lo mismo que una venta guardada desde VENDER.
 *
 * `BudgetHelper::saveSale()` es el otro creador de ventas de mostrador y hasta hoy dejaba en el
 * default de la columna cosas que `SaleController::store()` sí resuelve: el vendedor
 * (`seller_id`: sin él no hay comisión por este camino), la cotización (`valor_dolar`: no se
 * copiaba del presupuesto) y la fecha de terminada (`terminada_at`: null con `terminada = 1`). Y
 * la moneda del presupuesto no tenía default (A6): sin `moneda_id` el alta moría en la columna
 * NOT NULL, y con 0 `getCost()` no cotizaba y la venta heredaba el 0.
 *
 * DatabaseTransactions sobre la base sembrada del slot; `budget_statuses` se siembra en setUp
 * (mismo cuidado que Presupuestos/1 y /5). La lista de precios viaja explícita para no depender
 * de `users.listas_de_precio` en la base del slot.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Confirmar_arrastra_vendedor_cotizacion_y_terminada_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Precio del renglón. */
    const PRECIO = 100;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article */
    protected $article;

    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            if (is_null(BudgetStatus::find($id))) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (confirmar arrastra)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo confirmar arrastra',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'costo_real'  => 50,
            'status'      => 'active',
        ]);
    }

    /**
     * Cliente propio del test, con sus cuentas en pesos Y en dólares (las crea el mismo helper que
     * usa `ClientController@store` en producción: confirmar en moneda 2 necesita la de dólares,
     * `CurrentAcountFromSaleHelper` la busca por la moneda de la venta y la usa sin chequear
     * null), y con el vendedor que se pida.
     *
     * @param  int|null  $seller_id
     * @return \App\Models\Client
     */
    protected function cliente($seller_id = null)
    {
        $client = Client::create([
            'name'      => 'zz Cliente confirmar arrastra '.uniqid(),
            'user_id'   => self::USER_ID,
            'seller_id' => $seller_id,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $client->id, self::USER_ID);

        return $client;
    }

    /**
     * Payload de POST api/budget con un renglón (molde de Presupuestos/5). SIN `moneda_id` ni
     * `valor_dolar`: cada test decide.
     *
     * @param  \App\Models\Client  $client
     * @param  array               $overrides
     * @return array
     */
    protected function payload_crear($client, $overrides = [])
    {
        return array_merge([
            'client_id'                        => $client->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => null,
            'price_type_id'                    => $this->lista->id,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => self::PRECIO * self::CANTIDAD,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => null,
            'omitir_en_cuenta_corriente'       => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'articles'                         => [
                [
                    'id'                          => $this->article->id,
                    'status'                      => $this->article->status,
                    'cost_in_dollars'             => null,
                    'name'                        => $this->article->name,
                    'name_vender_personalizado'   => null,
                    'amount'                      => self::CANTIDAD,
                    'price'                       => self::PRECIO,
                    'costo_real'                  => 50,
                    'unidades_individuales'       => null,
                    'presentacion'                => null,
                    'price_type_personalizado_id' => null,
                    'bonus'                       => null,
                    'location'                    => null,
                ],
            ],
        ], $overrides);
    }

    /**
     * Presupuesto creado por el endpoint y devuelto fresco.
     *
     * @param  \App\Models\Client  $client
     * @param  array               $overrides
     * @return \App\Models\Budget
     */
    protected function presupuesto_creado($client, $overrides = [])
    {
        $id = $this->postJson('api/budget', $this->payload_crear($client, $overrides))->assertStatus(201)->json('model.id');

        return Budget::find($id);
    }

    /**
     * Confirma por el endpoint y devuelve la venta que nació.
     *
     * @param  \App\Models\Budget  $budget
     * @return \App\Models\Sale
     */
    protected function confirmar($budget)
    {
        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');

        return $sale;
    }

    /**
     * Un vendedor del usuario 500 con comisión del 10 %, que se cobra en el acto
     * (`commission_after_pay_sale = 0`) para que la comisión nazca activa sin depender de la
     * cuenta corriente.
     *
     * @return \App\Models\Seller
     */
    protected function vendedor()
    {
        return Seller::create([
            'num'                       => 999001,
            'name'                      => 'zz Vendedor confirmar arrastra '.uniqid(),
            'user_id'                   => self::USER_ID,
            'percentage_commission'     => 10,
            'commission_after_pay_sale' => 0,
            'commission_with_iva'       => 1,
        ]);
    }

    /**
     * Le da a la cuenta la extensión `check_sales` (ventas que pasan por depósito antes de
     * terminarse). forceCreate porque el modelo no declara $fillable; DatabaseTransactions lo
     * revierte.
     *
     * @return void
     */
    protected function dar_extension_check_sales()
    {
        $extencion = ExtencionEmpresa::where('slug', 'check_sales')->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'check_sales',
                'name' => 'Chequear ventas',
            ]);
        }

        if (!$this->user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $this->user->extencions()->attach($extencion->id);
        }
    }

    /**
     * 🔴 A3, EL VENDEDOR: presupuesto de un cliente con vendedor asignado → la venta nace con ese
     * `seller_id` y con su comisión en `seller_commissions`, exactamente como una venta de VENDER
     * para el mismo cliente. Hasta hoy `saveSale()` no escribía el vendedor ni creaba la comisión:
     * ferretotal, donde el presupuesto es el camino dominante, no comisionaba ninguna de esas ventas.
     *
     * @group presupuestos
     * @test
     */
    public function la_venta_nace_con_el_vendedor_del_cliente_y_su_comision()
    {
        $vendedor = $this->vendedor();

        $client = $this->cliente($vendedor->id);

        $budget = $this->presupuesto_creado($client);

        $sale = $this->confirmar($budget);

        $this->assertEquals($vendedor->id, (int) $sale->seller_id, 'La venta se lleva el vendedor del cliente, como en el alta desde VENDER.');

        $comision = SellerCommission::where('sale_id', $sale->id)->first();

        $this->assertNotNull($comision, 'Confirmar un presupuesto de un cliente con vendedor tiene que crear la comisión.');
        $this->assertEquals($vendedor->id, (int) $comision->seller_id);
        $this->assertEquals(
            self::PRECIO * self::CANTIDAD * 10 / 100,
            (float) $comision->debe,
            'La comisión es el 10 % del total de la venta.'
        );
    }

    /**
     * A3: sin vendedor por ningún lado (cliente sin vendedor, confirma el dueño) la venta queda
     * con 0 —el "sin vendedor" que esta columna siempre usó en el alta— y no hay comisión. Fija
     * que el no-op siga siendo un no-op.
     *
     * @group presupuestos
     * @test
     */
    public function sin_vendedor_la_venta_queda_en_cero_y_no_hay_comision()
    {
        $budget = $this->presupuesto_creado($this->cliente(null));

        $sale = $this->confirmar($budget);

        $this->assertSame(0, (int) $sale->seller_id);
        $this->assertFalse(SellerCommission::where('sale_id', $sale->id)->exists(), 'Sin vendedor no puede haber comisión.');
    }

    /**
     * A3: el mismo criterio que el alta, sin request. Cliente → empleado → 0, y el vendedor
     * elegido manda sobre todos. `get_seller_id_desde()` es lo que `get_seller_id($request)` llama
     * por adentro, así que fijarlo acá fija las dos puntas.
     *
     * @group presupuestos
     * @test
     */
    public function la_regla_del_vendedor_es_la_misma_con_y_sin_request()
    {
        $vendedor_del_cliente = $this->vendedor();
        $vendedor_del_empleado = $this->vendedor();
        $vendedor_elegido = $this->vendedor();

        $client = $this->cliente($vendedor_del_cliente->id);

        $empleado = User::create([
            'name'      => 'zz Empleado confirmar arrastra',
            'email'     => 'zz-confirmar-arrastra-'.uniqid().'@test.local',
            'password'  => Hash::make('zz-password-testing'),
            'status'    => 'commerce',
            'owner_id'  => self::USER_ID,
            'seller_id' => $vendedor_del_empleado->id,
        ]);

        $this->assertEquals($vendedor_elegido->id, SaleHelper::get_seller_id_desde($vendedor_elegido->id, $client->id, $empleado->id), 'El elegido manda.');
        $this->assertEquals($vendedor_del_cliente->id, SaleHelper::get_seller_id_desde(null, $client->id, $empleado->id), 'Sin elegido, el del cliente.');
        $this->assertEquals($vendedor_del_cliente->id, SaleHelper::get_seller_id_desde(0, $client->id, $empleado->id), 'El 0 es "no eligió".');
        $this->assertEquals($vendedor_del_empleado->id, SaleHelper::get_seller_id_desde(null, $this->cliente(null)->id, $empleado->id), 'Cliente sin vendedor: el del empleado.');
        $this->assertSame(0, SaleHelper::get_seller_id_desde(null, $this->cliente(null)->id, null), 'Nadie: 0.');
        $this->assertSame(0, SaleHelper::get_seller_id_desde(null, 999999999, null), 'Un cliente inexistente se saltea en vez de reventar.');

        $request = new Request(['seller_id' => null, 'client_id' => $client->id, 'employee_id' => null]);

        $this->assertEquals(
            SaleHelper::get_seller_id_desde(null, $client->id, null),
            SaleHelper::get_seller_id($request),
            'get_seller_id($request) tiene que dar lo mismo que la regla sin request.'
        );
    }

    /**
     * A3: `valor_dolar` del presupuesto viaja a la venta. Hasta hoy la venta perdía la cotización
     * con la que se preciaron sus renglones.
     *
     * @group presupuestos
     * @test
     */
    public function la_venta_se_lleva_el_valor_dolar_del_presupuesto()
    {
        $budget = $this->presupuesto_creado($this->cliente(), ['moneda_id' => 1, 'valor_dolar' => 1234]);

        $this->assertEquals(1234, (float) $budget->valor_dolar, 'El presupuesto guarda la cotización.');

        $sale = $this->confirmar($budget);

        $this->assertEquals(1234, (float) $sale->valor_dolar, 'La venta se lleva la cotización del presupuesto.');
    }

    /**
     * A3: sin la extensión `check_sales` la venta nace terminada Y fechada: `terminada = 1` con
     * `terminada_at` no nulo, como en el alta desde VENDER. Hasta hoy quedaba terminada sin fecha.
     *
     * @group presupuestos
     * @test
     */
    public function sin_check_sales_la_venta_nace_terminada_y_con_fecha()
    {
        $this->assertFalse(
            $this->user->extencions()->where('slug', 'check_sales')->exists(),
            'Este test mide la cuenta SIN check_sales; si la base del slot la tiene sembrada para el 500, no mide nada.'
        );

        $sale = $this->confirmar($this->presupuesto_creado($this->cliente()));

        $this->assertSame(1, (int) $sale->terminada);
        $this->assertSame(0, (int) $sale->to_check);
        $this->assertNotNull($sale->terminada_at, 'Una venta terminada tiene que tener terminada_at.');
    }

    /**
     * A3, la otra rama: con `check_sales` la venta nace `to_check`, sin terminar y sin fecha, que
     * es lo que hace `SaleController::store()` con la misma extensión. Fija que el criterio sea el
     * mismo en las dos puertas.
     *
     * @group presupuestos
     * @test
     */
    public function con_check_sales_la_venta_nace_a_chequear_sin_terminar_y_sin_fecha()
    {
        $this->dar_extension_check_sales();

        $sale = $this->confirmar($this->presupuesto_creado($this->cliente()));

        $this->assertSame(1, (int) $sale->to_check);
        $this->assertSame(0, (int) $sale->terminada);
        $this->assertNull($sale->terminada_at);
    }

    /**
     * A6: sin `moneda_id` en el alta, el presupuesto queda en pesos (1) y la venta también. Hasta
     * hoy el alta moría con "Column 'moneda_id' cannot be null".
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_sin_moneda_queda_en_pesos_y_la_venta_tambien()
    {
        $budget = $this->presupuesto_creado($this->cliente());

        $this->assertSame(1, (int) $budget->moneda_id, 'Sin moneda_id el presupuesto tiene que quedar en pesos.');

        $sale = $this->confirmar($budget);

        $this->assertSame(1, (int) $sale->moneda_id, 'La venta hereda la moneda del presupuesto, y es pesos.');
    }

    /**
     * A6: con `moneda_id` explícita se respeta (2 = dólares en el catálogo de monedas), en el
     * presupuesto y en la venta.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_con_moneda_explicita_la_conserva_y_la_venta_la_hereda()
    {
        $budget = $this->presupuesto_creado($this->cliente(), ['moneda_id' => 2, 'valor_dolar' => 1234]);

        $this->assertSame(2, (int) $budget->moneda_id);

        $sale = $this->confirmar($budget);

        $this->assertSame(2, (int) $sale->moneda_id);
    }

    /**
     * A6, lado `saveSale()`: un presupuesto viejo guardado con `moneda_id` en 0 (sin modo
     * estricto, el null caía a 0) confirma con la venta en pesos, no en 0.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_viejo_con_moneda_en_cero_confirma_la_venta_en_pesos()
    {
        $budget = $this->presupuesto_creado($this->cliente());

        Budget::where('id', $budget->id)->update(['moneda_id' => 0]);

        $sale = $this->confirmar(Budget::find($budget->id));

        $this->assertSame(1, (int) $sale->moneda_id, 'El 0 no es una moneda: la venta nace en pesos.');
    }

    /**
     * A6, lado update(): un PUT sin `moneda_id` (SPA anterior a septiembre de 2025) preserva la
     * guardada en vez de morir en la columna NOT NULL.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_sin_moneda_preserva_la_guardada()
    {
        $budget = $this->presupuesto_creado($this->cliente(), ['moneda_id' => 2, 'valor_dolar' => 1234]);

        $this->putJson('api/budget/'.$budget->id, [
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'actualizado por el test',
            'total'                 => $budget->total,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ])->assertStatus(200);

        $this->assertSame(2, (int) Budget::find($budget->id)->moneda_id, 'Un PUT sin moneda_id no puede cambiar la moneda del presupuesto.');
    }
}
