<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria (17/9/2026), lado presupuestos: una cuenta que trabaja con
 * listas de precios no puede guardar un presupuesto sin lista, ni en el alta ni en la edición.
 *
 * Es el mismo agujero que el de las ventas (Trama, 17/9/2026: ventas de mostrador a costo porque
 * la SPA mandaba `price_type_id: null` y el back lo guardaba tal cual), con un agravante propio:
 * `BudgetController::update()` ni siquiera tocaba `price_type_id`, y `vender_presupuestos.js`
 * no lo mandaba en `actualizar()`. Un presupuesto que se guardaba sin lista se confirmaba con la
 * del cliente o con ninguna, y la venta nacía con los renglones tal cual se habían preciado.
 *
 * Lo que fijan estos tests: el 422 del alta (`budgets` no crece), el rescate del cliente, la
 * lista explícita, la no-regresión de las cuentas sin listas, el PUT en sus tres variantes
 * (clave nueva, clave ausente, null explícito) y que confirmar lleva la lista a la venta —incluido
 * el presupuesto viejo sin lista, que confirma con la del cliente o con null a propósito
 * (`BudgetHelper::get_price_type_id()`).
 *
 * DatabaseTransactions sobre la base sembrada del slot; `budget_statuses` se siembra en setUp
 * porque en la base de un slot puede venir vacía (medido el 21/8/2026), mismo cuidado que
 * tests/Feature/Presupuestos/1. `users.listas_de_precio` se guarda en setUp y se restaura en
 * tearDown además del rollback.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Presupuesto_sin_lista_de_precios_Test extends TestCase
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

    /** @var int|null Valor original de users.listas_de_precio. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType Position ALTA. */
    protected $lista_general;

    /** @var \App\Models\PriceType Position baja: la del cliente. */
    protected $lista_mostrador;

    /** @var \App\Models\Article */
    protected $article;

    /**
     * ⚠️ `budget_statuses` puede venir vacía en la base del slot. Se siembra con ids explícitos y
     * `DatabaseTransactions` lo revierte. Importa: `BudgetHelper::checkStatus()` hace
     * `$budget->budget_status->name` sin chequear null.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $estados = [
            self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            $existente = BudgetStatus::find($id);

            if (is_null($existente)) {

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

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista_general = PriceType::create([
            'name'     => 'zz General (presupuesto sin lista)',
            'user_id'  => self::USER_ID,
            'position' => 9,
        ]);

        $this->lista_mostrador = PriceType::create([
            'name'     => 'zz Mostrador (presupuesto sin lista)',
            'user_id'  => self::USER_ID,
            'position' => 1,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo presupuesto sin lista',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'costo_real'  => 50,
            'status'      => 'active',
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
     * @param  int  $usa
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * Cliente propio del test, con la lista que le pidan (o ninguna), y con su cuenta en pesos.
     *
     * 🔴 La cuenta en pesos no es opcional acá: confirmar crea la venta y
     * `SaleHelper::create_current_acount()` → `CurrentAcountFromSaleHelper` la usa sin chequear
     * null. Mismo apaño que tests/Feature/Presupuestos/1.
     *
     * @param  int|null  $price_type_id
     * @return \App\Models\Client
     */
    protected function cliente($price_type_id)
    {
        $client = Client::create([
            'name'          => 'zz Cliente presupuesto sin lista '.uniqid(),
            'user_id'       => self::USER_ID,
            'price_type_id' => $price_type_id,
        ]);

        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        return $client;
    }

    /**
     * Payload de POST api/budget con un renglón, calcado de tests/Feature/Presupuestos/2 (que es
     * el molde de alta que pasa en este slot). `price_type_id` en null, como lo manda
     * `vender_presupuestos.js::crear()` cuando la SPA no resolvió lista.
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
            'price_type_id'                    => null,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => self::PRECIO * self::CANTIDAD,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => null,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'articles'                         => [$this->articulo_payload(null)],
        ], $overrides);
    }

    /**
     * El renglón tal como lo manda `vender_presupuestos.js::crear()`: PLANO, con
     * `price_type_personalizado_id` (la lista por línea de los rangos por cantidad) en la raíz y
     * no bajo `pivot`.
     *
     * @param  int|null  $price_type_personalizado_id
     * @return array
     */
    protected function articulo_payload($price_type_personalizado_id)
    {
        return [
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
            'price_type_personalizado_id' => $price_type_personalizado_id,
            'bonus'                       => null,
            'location'                    => null,
        ];
    }

    /**
     * El mismo renglón como viaja en la ACTUALIZACIÓN (`actualizar()` con el ítem ya cargado, y el
     * form genérico del módulo Presupuestos): los datos del renglón bajo `pivot`, sin la clave en
     * la raíz.
     *
     * @param  int|null  $price_type_personalizado_id
     * @return array
     */
    protected function articulo_payload_pivot($price_type_personalizado_id)
    {
        return [
            'id'         => $this->article->id,
            'status'     => $this->article->status,
            'name'       => $this->article->name,
            'costo_real' => 50,
            'pivot'      => [
                'amount'                      => self::CANTIDAD,
                'price'                       => self::PRECIO,
                'bonus'                       => null,
                'location'                    => null,
                'price_type_personalizado_id' => $price_type_personalizado_id,
            ],
        ];
    }

    /**
     * La lista por línea del único renglón de un presupuesto, leída del pivote.
     *
     * @param  int  $budget_id
     * @return mixed
     */
    protected function lista_por_linea_del_presupuesto($budget_id)
    {
        $article = Budget::find($budget_id)->articles()->first();

        $this->assertNotNull($article, 'El presupuesto tiene que tener su renglón adjuntado.');

        return $article->pivot->price_type_personalizado_id;
    }

    /**
     * Presupuesto ya guardado, directo en la base (sin pasar por el controlador), para los tests
     * del PUT y del presupuesto viejo. Sin renglones a propósito: lo que se mide es la lista.
     *
     * @param  \App\Models\Client  $client
     * @param  int|null            $price_type_id
     * @return \App\Models\Budget
     */
    protected function presupuesto_guardado($client, $price_type_id)
    {
        return Budget::create([
            'user_id'               => self::USER_ID,
            'client_id'             => $client->id,
            'price_type_id'         => $price_type_id,
            'budget_status_id'      => self::ESTADO_SIN_CONFIRMAR,
            'observations'          => 'presupuesto de prueba',
            'total'                 => 100,
            'discount_stock'        => 0,
            'discounts_in_services' => 1,
            'surchages_in_services' => 1,
        ]);
    }

    /**
     * Payload mínimo y válido de PUT api/budget/{id} (molde de tests/Feature/Presupuestos/1). Los
     * arrays van vacíos pero presentes: los `attach*` hacen foreach sin chequear null. SIN
     * `price_type_id`: cada test decide si la clave viaja y con qué.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $overrides
     * @return array
     */
    protected function payload_actualizar($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'actualizado por el test',
            'total'                 => $budget->total,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ], $overrides);
    }

    /**
     * @return int
     */
    protected function cantidad_de_presupuestos()
    {
        return Budget::where('user_id', self::USER_ID)->count();
    }

    /**
     * Las aserciones del rechazo, compartidas: 422, la bandera y el texto para presupuestos.
     *
     * @param  \Illuminate\Testing\TestResponse  $response
     * @return void
     */
    protected function assert_rechazo_sin_lista($response)
    {
        $response->assertStatus(422);

        $this->assertTrue((bool) $response->json('sin_lista_de_precios'));

        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista_presupuesto(), $response->json('message'));
    }

    /**
     * 🔴 Cuenta con listas, cliente SIN lista, sin `price_type_id`: 422 y `budgets` no crece. El
     * rechazo va antes de la transacción, así que tampoco se consume número de presupuesto.
     *
     * @group presupuestos
     * @test
     */
    public function cuenta_con_listas_con_cliente_sin_lista_y_sin_lista_responde_422_y_no_crea_el_presupuesto()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente(null);

        $antes = $this->cantidad_de_presupuestos();

        $response = $this->postJson('api/budget', $this->payload_crear($client));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($antes, $this->cantidad_de_presupuestos(), 'Un 422 no puede haber creado el presupuesto.');
    }

    /**
     * El cliente con lista rescata: 201 y el presupuesto nace con la lista del cliente, que es la
     * que el front usa para preciar cuando hay cliente con lista.
     *
     * @group presupuestos
     * @test
     */
    public function con_cliente_con_lista_el_presupuesto_nace_con_la_lista_del_cliente()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $response = $this->postJson('api/budget', $this->payload_crear($client));

        $response->assertStatus(201);

        $budget = Budget::find($response->json('model.id'));

        $this->assertNotNull($budget);
        $this->assertEquals($this->lista_mostrador->id, (int) $budget->price_type_id);
    }

    /**
     * La lista explícita del request manda sobre la del cliente.
     *
     * @group presupuestos
     * @test
     */
    public function con_lista_explicita_el_presupuesto_nace_con_esa_lista()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $response = $this->postJson('api/budget', $this->payload_crear($client, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(201);

        $this->assertEquals(
            $this->lista_general->id,
            (int) Budget::find($response->json('model.id'))->price_type_id
        );
    }

    /**
     * NO REGRESIÓN: una cuenta sin listas (`listas_de_precio = 0`) sigue guardando presupuestos
     * sin lista, con 201 y null.
     *
     * @group presupuestos
     * @test
     */
    public function cuenta_sin_listas_sigue_guardando_el_presupuesto_sin_lista()
    {
        $this->cuenta_con_listas(0);

        $client = $this->cliente(null);

        $response = $this->postJson('api/budget', $this->payload_crear($client));

        $response->assertStatus(201);

        $this->assertNull(Budget::find($response->json('model.id'))->price_type_id);
    }

    /**
     * PUT con `price_type_id`: se persiste. Hasta esta misión update() no tocaba la columna.
     *
     * @group presupuestos
     * @test
     */
    public function put_con_lista_nueva_la_persiste()
    {
        $this->cuenta_con_listas(1);

        $budget = $this->presupuesto_guardado($this->cliente($this->lista_mostrador->id), $this->lista_mostrador->id);

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'price_type_id' => $this->lista_general->id,
        ]));

        $response->assertStatus(200);

        $this->assertEquals($this->lista_general->id, (int) Budget::find($budget->id)->price_type_id);
    }

    /**
     * 🔴 PUT SIN la clave (el form genérico del módulo Presupuestos y la SPA anterior no la
     * mandan): la lista guardada se preserva. Mismo criterio que `aplicar_recargos_directo_a_items`
     * en el mismo método.
     *
     * @group presupuestos
     * @test
     */
    public function put_sin_la_clave_preserva_la_lista_guardada()
    {
        $this->cuenta_con_listas(1);

        $budget = $this->presupuesto_guardado($this->cliente(null), $this->lista_mostrador->id);

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget));

        $response->assertStatus(200);

        $guardado = Budget::find($budget->id)->price_type_id;

        $this->assertNotNull($guardado, 'El PUT sin la clave dejó la lista en null: la pisó.');
        $this->assertEquals($this->lista_mostrador->id, (int) $guardado);
    }

    /**
     * PUT con null explícito en una cuenta con listas: 422 y el presupuesto queda como estaba.
     * `update()` no abre transacción, así que la única garantía es que el rechazo corte ANTES del
     * primer save(): las observaciones distintas del payload son la prueba.
     *
     * @group presupuestos
     * @test
     */
    public function put_con_null_explicito_en_cuenta_con_listas_responde_422_y_no_toca_el_presupuesto()
    {
        $this->cuenta_con_listas(1);

        $budget = $this->presupuesto_guardado($this->cliente(null), $this->lista_mostrador->id);

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, [
            'price_type_id' => null,
            'observations'  => 'no tendria que guardarse',
        ]));

        $this->assert_rechazo_sin_lista($response);

        $despues = Budget::find($budget->id);

        $this->assertEquals($this->lista_mostrador->id, (int) $despues->price_type_id, 'La lista no se tiene que haber tocado.');
        $this->assertEquals('presupuesto de prueba', $despues->observations, 'El 422 tiene que cortar antes del save().');
    }

    /**
     * Confirmar un presupuesto con lista: la venta nace con esa lista
     * (`BudgetHelper::get_price_type_id()` → `saveSale()`).
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_un_presupuesto_con_lista_crea_la_venta_con_esa_lista()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $budget_id = $this->postJson('api/budget', $this->payload_crear($client, [
            'price_type_id' => $this->lista_general->id,
        ]))->assertStatus(201)->json('model.id');

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertEquals(
            $this->lista_general->id,
            (int) $sale->price_type_id,
            'La venta se lleva la lista del presupuesto, no la del cliente.'
        );
    }

    /**
     * El presupuesto VIEJO sin lista (guardado antes de esta misión) confirma con la lista del
     * cliente, o con null si el cliente tampoco tiene: sus renglones ya se preciaron así, y ponerle
     * una lista ahora diría que la venta se cobró con precios que nadie aplicó. Es el comportamiento
     * documentado en `BudgetHelper::get_price_type_id()` y no se le exige lista a la confirmación.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_viejo_sin_lista_confirma_con_la_del_cliente_o_con_ninguna()
    {
        $this->cuenta_con_listas(1);

        $con_lista = $this->presupuesto_guardado($this->cliente($this->lista_mostrador->id), null);

        $this->assertEquals($this->lista_mostrador->id, (int) BudgetHelper::get_price_type_id($con_lista));

        $this->post('api/budget/'.$con_lista->id.'/confirmar')->assertStatus(200);

        $venta_con_lista = Sale::where('budget_id', $con_lista->id)->first();

        $this->assertNotNull($venta_con_lista);
        $this->assertEquals($this->lista_mostrador->id, (int) $venta_con_lista->price_type_id);

        $sin_lista = $this->presupuesto_guardado($this->cliente(null), null);

        $this->assertNull(BudgetHelper::get_price_type_id($sin_lista));

        $this->post('api/budget/'.$sin_lista->id.'/confirmar')->assertStatus(200);

        $venta_sin_lista = Sale::where('budget_id', $sin_lista->id)->first();

        $this->assertNotNull($venta_sin_lista, 'Confirmar un presupuesto viejo sin lista no se rechaza.');
        $this->assertNull($venta_sin_lista->price_type_id);
    }

    /**
     * 🔴 El cliente con `price_type_id = 0` (el form genérico de clientes nace en 0) es un cliente
     * sin lista: en una cuenta con listas, 422. Hasta esta misión el rescate hubiera copiado el 0.
     *
     * @group presupuestos
     * @test
     */
    public function con_cliente_con_lista_en_cero_y_sin_lista_responde_422()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente(0);

        $antes = $this->cantidad_de_presupuestos();

        $response = $this->postJson('api/budget', $this->payload_crear($client));

        $this->assert_rechazo_sin_lista($response);

        $this->assertEquals($antes, $this->cantidad_de_presupuestos());
    }

    /**
     * Un presupuesto con `price_type_id = 0` (guardado así por una SPA vieja o por el form
     * genérico) confirma con la lista del cliente, o con null si el cliente tampoco tiene —
     * NUNCA con 0. `BudgetHelper::get_price_type_id()` preguntaba `!is_null` en las dos puntas y
     * dejaba pasar el 0 del presupuesto y el del cliente; ahora usa el mismo resolvedor que el alta.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_con_lista_en_cero_confirma_con_la_del_cliente_o_con_ninguna()
    {
        $this->cuenta_con_listas(1);

        $con_cliente_con_lista = $this->presupuesto_guardado($this->cliente($this->lista_mostrador->id), 0);

        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) BudgetHelper::get_price_type_id(Budget::find($con_cliente_con_lista->id)),
            'El 0 del presupuesto no es una lista: tiene que caer al cliente.'
        );

        $this->post('api/budget/'.$con_cliente_con_lista->id.'/confirmar')->assertStatus(200);

        $venta = Sale::where('budget_id', $con_cliente_con_lista->id)->first();

        $this->assertNotNull($venta);
        $this->assertEquals($this->lista_mostrador->id, (int) $venta->price_type_id);

        $con_cliente_en_cero = $this->presupuesto_guardado($this->cliente(0), 0);

        $this->assertNull(
            BudgetHelper::get_price_type_id(Budget::find($con_cliente_en_cero->id)),
            'Presupuesto en 0 y cliente en 0: ninguna lista, y ninguna es null, no 0.'
        );

        $this->post('api/budget/'.$con_cliente_en_cero->id.'/confirmar')->assertStatus(200);

        $venta_sin_lista = Sale::where('budget_id', $con_cliente_en_cero->id)->first();

        $this->assertNotNull($venta_sin_lista);
        $this->assertNull($venta_sin_lista->price_type_id, 'La venta no puede nacer con price_type_id = 0.');
    }

    /**
     * 🔴 La lista por línea (rangos por cantidad, `price_type_personalizado_id`) que VENDER manda
     * PLANA en el alta del presupuesto se guarda en el pivote y viaja a la venta al confirmar.
     * Hasta esta misión `BudgetHelper::attachArticles()` la leía solo de `pivot`, así que el alta
     * la perdía: el precio ya estaba congelado en `pivot.price`, pero la trazabilidad de con qué
     * lista se cobró ese renglón desaparecía (y con ella la base de puntos por lista).
     *
     * @group presupuestos
     * @test
     */
    public function el_alta_desde_vender_guarda_la_lista_por_linea_que_viaja_plana()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $budget_id = $this->postJson('api/budget', $this->payload_crear($client, [
            'price_type_id' => $this->lista_general->id,
            'articles'      => [$this->articulo_payload($this->lista_mostrador->id)],
        ]))->assertStatus(201)->json('model.id');

        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) $this->lista_por_linea_del_presupuesto($budget_id),
            'La lista por línea que viaja plana tiene que quedar en el pivote del presupuesto.'
        );

        $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget_id)->first();

        $this->assertNotNull($sale);

        $renglon = $sale->articles()->first();

        $this->assertNotNull($renglon, 'La venta tiene que nacer con el renglón del presupuesto.');
        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) $renglon->pivot->price_type_personalizado_id,
            'Al confirmar, la línea de la venta conserva la lista por línea del presupuesto.'
        );
    }

    /**
     * El 0 con que nacen los ítems de VENDER es "sin lista por línea" y se guarda como NULL, con
     * el mismo criterio que `SaleHelper::get_price_type_personalizado()` usa para la venta: en el
     * alta (plano) y en la actualización (bajo `pivot`). Hasta esta misión la actualización guardaba
     * el 0 tal cual y al confirmar llegaba a `article_sale`, donde la venta directa nunca escribe
     * un 0. Y cuando la clave viene solo bajo `pivot` con una lista real, se respeta.
     *
     * @group presupuestos
     * @test
     */
    public function la_lista_por_linea_en_cero_se_guarda_como_null_en_el_alta_y_en_la_edicion()
    {
        $this->cuenta_con_listas(1);

        $client = $this->cliente($this->lista_mostrador->id);

        $budget_id = $this->postJson('api/budget', $this->payload_crear($client, [
            'price_type_id' => $this->lista_general->id,
            'articles'      => [$this->articulo_payload(0)],
        ]))->assertStatus(201)->json('model.id');

        $this->assertNull(
            $this->lista_por_linea_del_presupuesto($budget_id),
            'El 0 plano del alta se guarda como null, no como 0.'
        );

        $budget = Budget::find($budget_id);

        $this->putJson('api/budget/'.$budget_id, $this->payload_actualizar($budget, [
            'articles' => [$this->articulo_payload_pivot(0)],
        ]))->assertStatus(200);

        $this->assertNull(
            $this->lista_por_linea_del_presupuesto($budget_id),
            'El 0 bajo pivot de la actualización se guarda como null, no como 0.'
        );

        $this->putJson('api/budget/'.$budget_id, $this->payload_actualizar($budget, [
            'articles' => [$this->articulo_payload_pivot($this->lista_mostrador->id)],
        ]))->assertStatus(200);

        $this->assertEquals(
            $this->lista_mostrador->id,
            (int) $this->lista_por_linea_del_presupuesto($budget_id),
            'Una lista real bajo pivot, sin la clave en la raíz, se respeta.'
        );
    }
}
