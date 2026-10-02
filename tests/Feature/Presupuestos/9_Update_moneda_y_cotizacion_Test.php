<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión 2r-presupuestos-editables (1/10/2026): `PUT api/budget/{id}` persiste `valor_dolar` y deja
 * cambiar la moneda de un presupuesto ya generado ($ <-> USD) sin romper a la SPA vieja.
 *
 * EL HUECO: `BudgetController::update()` persistía `moneda_id` pero NO `valor_dolar`. Un presupuesto
 * pasado a dólares quedaba con la cotización vieja (o NULL) y la venta que nace al confirmarlo la
 * heredaba, porque `BudgetHelper::saveSale()` copia `valor_dolar` tal cual.
 *
 * LAS REGLAS QUE FIJAN ESTOS TESTS (todas compatibles hacia atrás, porque la api y la spa no llegan
 * juntas a producción):
 *
 *  - clave ausente = se preserva la cotización guardada (SPA vieja);
 *  - 422 SOLO si el presupuesto QUEDA en dólares y (a) el request manda una cotización que no sirve,
 *    o (b) la moneda CAMBIA a dólares en este request y no hay cotización ni en el request ni
 *    guardada. Siempre antes de escribir nada;
 *  - un presupuesto que YA estaba en dólares con `valor_dolar` NULL (así quedaron los de 2R) se
 *    sigue pudiendo editar desde una SPA que no manda la clave;
 *  - confirmado sigue dando el 422 de siempre;
 *  - y confirmar un presupuesto convertido a dólares da una venta en dólares con la misma cotización.
 *
 * DatabaseTransactions sobre la base sembrada del slot; `budget_statuses` se siembra en setUp (mismo
 * cuidado que Presupuestos/6 y /7). La lista de precios viaja explícita para no depender de
 * `users.listas_de_precio` en la base del slot.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Update_moneda_y_cotizacion_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var int Precio en pesos del renglón (12345 / 1234,5 = 10 dólares justos). */
    const PRECIO_PESOS = 12345;

    /** @var float Cotización con la que el precio en pesos da dólares justos. */
    const COTIZACION = 1234.5;

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
            'name'     => 'zz Lista (moneda y cotizacion)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo moneda y cotizacion',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_PESOS,
            'costo_real'  => 5000,
            'status'      => 'active',
        ]);
    }

    /**
     * Cliente propio del test con sus cuentas en pesos Y en dólares (confirmar en moneda 2 necesita
     * la de dólares: `CurrentAcountFromSaleHelper` la busca por la moneda de la venta).
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente moneda y cotizacion '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $client->id, self::USER_ID);

        return $client;
    }

    /**
     * El renglón como lo manda VENDER (plano), con precio y cantidad a elección.
     *
     * @param  float  $price
     * @param  float  $amount
     * @return array
     */
    protected function renglon($price, $amount)
    {
        return [
            'id'                          => $this->article->id,
            'status'                      => $this->article->status,
            'cost_in_dollars'             => null,
            'name'                        => $this->article->name,
            'name_vender_personalizado'   => null,
            'amount'                      => $amount,
            'price'                       => $price,
            'costo_real'                  => 5000,
            'unidades_individuales'       => null,
            'presentacion'                => null,
            'price_type_personalizado_id' => null,
            'bonus'                       => null,
            'location'                    => null,
        ];
    }

    /**
     * Presupuesto en pesos creado por el endpoint (un renglón: 12345 × 2 = 24690), con la cotización
     * guardada que se pida (null = ninguna, como un presupuesto en pesos de siempre).
     *
     * @param  float|null  $valor_dolar
     * @param  int         $moneda_id
     * @return \App\Models\Budget
     */
    protected function presupuesto($valor_dolar = null, $moneda_id = 1)
    {
        $id = $this->postJson('api/budget', [
            'client_id'                        => $this->cliente()->id,
            'start_at'                         => null,
            'finish_at'                        => null,
            'observations'                     => 'presupuesto de prueba',
            'price_type_id'                    => $this->lista->id,
            'sale_status_id'                   => null,
            'discount_stock'                   => 0,
            'iva_aplicado'                     => 1,
            'total'                            => self::PRECIO_PESOS * self::CANTIDAD,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => null,
            'moneda_id'                        => $moneda_id,
            'valor_dolar'                      => $valor_dolar,
            'omitir_en_cuenta_corriente'       => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'articles'                         => [$this->renglon(self::PRECIO_PESOS, self::CANTIDAD)],
        ])->assertStatus(201)->json('model.id');

        return Budget::find($id);
    }

    /**
     * El PUT que manda la SPA al editar un presupuesto, SIN `moneda_id` ni `valor_dolar`: cada test
     * suma lo que quiere medir (con `array_merge`, así que una clave puesta en null SÍ viaja).
     * El renglón viaja en pesos salvo que el test lo pise.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $overrides
     * @return array
     */
    protected function payload_put($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'editado por el test',
            'total'                 => self::PRECIO_PESOS * self::CANTIDAD,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [$this->renglon(self::PRECIO_PESOS, self::CANTIDAD)],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ], $overrides);
    }

    /**
     * Las filas de `article_budget` del presupuesto como `[price, amount, cost]`, crudas de la tabla.
     *
     * @param  int  $budget_id
     * @return array
     */
    protected function renglones_guardados($budget_id)
    {
        $renglones = [];

        $filas = DB::table('article_budget')->where('budget_id', $budget_id)->orderBy('id')->get();

        foreach ($filas as $fila) {
            $renglones[] = [(float) $fila->price, (float) $fila->amount, (float) $fila->cost];
        }

        return $renglones;
    }

    /**
     * Foto cruda del presupuesto y de sus renglones, para comprobar que un 422 no escribió NADA.
     *
     * @param  int  $budget_id
     * @return array
     */
    protected function foto($budget_id)
    {
        return [
            'atributos' => Budget::find($budget_id)->getAttributes(),
            'renglones' => $this->renglones_guardados($budget_id),
        ];
    }

    /**
     * Pesos -> dólares con la cotización en el request: guarda moneda y cotización, y los renglones
     * viajan en dólares (los convierte la SPA, no la api: la api guarda lo que le mandan).
     *
     * @group presupuestos
     * @test
     */
    public function pasar_de_pesos_a_dolares_guarda_la_moneda_y_la_cotizacion()
    {
        $budget = $this->presupuesto(null, 1);

        $this->assertNull($budget->valor_dolar, 'El presupuesto en pesos arranca sin cotización.');

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 2,
            'valor_dolar' => self::COTIZACION,
            'total'       => 20,
            'articles'    => [$this->renglon(10, self::CANTIDAD)],
        ]))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(2, (int) $despues->moneda_id);
        $this->assertEquals(self::COTIZACION, (float) $despues->valor_dolar, 'La cotización del request tiene que quedar guardada.');
        $this->assertEquals(20, (float) $despues->total);

        $renglones = $this->renglones_guardados($budget->id);

        $this->assertCount(1, $renglones);
        $this->assertEquals(10, $renglones[0][0], 'El precio del renglón es el que mandó la SPA, en dólares.');
    }

    /**
     * La cotización se guarda redondeada a 2 decimales, la misma función que usa
     * `SaleController::store()`: es el valor con el que `getCost()` cotiza y el que después copia
     * la venta, así que tiene que ser el que queda en la columna DECIMAL(20,2).
     *
     * @group presupuestos
     * @test
     */
    public function la_cotizacion_se_guarda_redondeada_a_dos_decimales()
    {
        $budget = $this->presupuesto(null, 1);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 2,
            'valor_dolar' => 1234.5678,
        ]))->assertStatus(200);

        $this->assertEquals(1234.57, (float) Budget::find($budget->id)->valor_dolar);
    }

    /**
     * Dólares -> pesos: la moneda vuelve a 1 y la cotización que manda la SPA queda guardada (sirve
     * si después se lo vuelve a pasar a dólares).
     *
     * @group presupuestos
     * @test
     */
    public function volver_de_dolares_a_pesos_guarda_la_moneda_y_la_cotizacion()
    {
        $budget = $this->presupuesto(self::COTIZACION, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 1,
            'valor_dolar' => 1300,
        ]))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(1, (int) $despues->moneda_id);
        $this->assertEquals(1300, (float) $despues->valor_dolar);
    }

    /**
     * 🔴 COMPATIBILIDAD CON LA SPA VIEJA: un PUT sin la clave `valor_dolar` preserva la cotización
     * guardada, tanto si la moneda no cambia como si vuelve a pesos. Con una asignación pelada esa
     * SPA borraría la cotización en cada edición.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_sin_valor_dolar_preserva_la_cotizacion_guardada()
    {
        $budget = $this->presupuesto(self::COTIZACION, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(2, (int) $despues->moneda_id, 'Sin moneda_id en el PUT, la moneda no cambia.');
        $this->assertEquals(self::COTIZACION, (float) $despues->valor_dolar, 'Sin la clave, la cotización guardada no se toca.');
        $this->assertEquals('editado por el test', $despues->observations, 'Y el resto del PUT sí se guardó.');

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['moneda_id' => 1]))->assertStatus(200);

        $this->assertEquals(self::COTIZACION, (float) Budget::find($budget->id)->valor_dolar, 'Volver a pesos sin mandar la cotización tampoco la borra.');
    }

    /**
     * La clave presente pero en null también cuenta como "no mandó cotización": se preserva la
     * guardada, igual que `moneda_id` e `iva_aplicado` (mismo patrón `!is_null`).
     *
     * @group presupuestos
     * @test
     */
    public function un_valor_dolar_en_null_preserva_la_cotizacion_guardada()
    {
        $budget = $this->presupuesto(self::COTIZACION, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['valor_dolar' => null]))->assertStatus(200);

        $this->assertEquals(self::COTIZACION, (float) Budget::find($budget->id)->valor_dolar);
    }

    /**
     * Un presupuesto en pesos que tiene una cotización guardada (la de una conversión anterior) y se
     * pasa a dólares SIN mandarla: la cotización guardada alcanza, no hay 422.
     *
     * @group presupuestos
     * @test
     */
    public function pasar_a_dolares_sin_mandar_la_cotizacion_usa_la_guardada()
    {
        $budget = $this->presupuesto(self::COTIZACION, 1);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['moneda_id' => 2]))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(2, (int) $despues->moneda_id);
        $this->assertEquals(self::COTIZACION, (float) $despues->valor_dolar);
    }

    /**
     * 🔴 El 422 de la regla (b): pasar a dólares sin cotización ni en el request ni guardada. Y que
     * NO escribió nada: ni la moneda, ni el total, ni las observaciones, ni los renglones.
     *
     * @group presupuestos
     * @test
     */
    public function pasar_a_dolares_sin_cotizacion_da_422_y_no_escribe_nada()
    {
        $budget = $this->presupuesto(null, 1);

        $antes = $this->foto($budget->id);

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'    => 2,
            'observations' => 'no tendria que guardarse',
            'total'        => 20,
            'articles'     => [$this->renglon(10, self::CANTIDAD)],
        ]));

        $response->assertStatus(422);

        $this->assertTrue((bool) $response->json('sin_cotizacion_dolar'), 'El 422 lleva la marca que la SPA lee.');
        $this->assertNotEmpty($response->json('message'), 'Y un mensaje para el usuario.');

        $this->assertEquals($antes, $this->foto($budget->id), 'Un 422 no puede escribir nada.');
    }

    /**
     * La regla (b) con la cotización en null explícito: es lo mismo que no mandarla.
     *
     * @group presupuestos
     * @test
     */
    public function pasar_a_dolares_con_la_cotizacion_en_null_y_sin_guardada_da_422()
    {
        $budget = $this->presupuesto(null, 1);

        $antes = $this->foto($budget->id);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['moneda_id' => 2, 'valor_dolar' => null]))->assertStatus(422);

        $this->assertEquals($antes, $this->foto($budget->id));
    }

    /**
     * 🔴 El 422 de la regla (a): el presupuesto QUEDA en dólares y el request manda una cotización
     * que no sirve (cero, negativa, texto, o que redondeada a 2 decimales da cero). Sin escribir
     * nada, y tanto si ya estaba en dólares como si recién pasa.
     *
     * @group presupuestos
     * @test
     */
    public function una_cotizacion_invalida_en_dolares_da_422_y_no_escribe_nada()
    {
        $invalidas = [0, '0', -5, -0.01, 'mil doscientos', '1234,50', 0.004];

        foreach ($invalidas as $invalida) {

            // Ya en dólares y con cotización guardada: mandar basura no puede pisarla.
            $budget = $this->presupuesto(self::COTIZACION, 2);

            $antes = $this->foto($budget->id);

            $response = $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
                'observations' => 'no tendria que guardarse',
                'valor_dolar'  => $invalida,
            ]));

            $response->assertStatus(422);

            $this->assertTrue((bool) $response->json('sin_cotizacion_dolar'), 'Cotización '.var_export($invalida, true));
            $this->assertEquals($antes, $this->foto($budget->id), 'Un 422 no escribe nada (cotización '.var_export($invalida, true).').');

            // Pasando de pesos a dólares con una cotización guardada válida: el request manda basura,
            // y la guardada no la rescata (el usuario escribió algo y se equivocó).
            $budget_pesos = $this->presupuesto(self::COTIZACION, 1);

            $antes_pesos = $this->foto($budget_pesos->id);

            $this->putJson('api/budget/'.$budget_pesos->id, $this->payload_put($budget_pesos, [
                'moneda_id'   => 2,
                'valor_dolar' => $invalida,
            ]))->assertStatus(422);

            $this->assertEquals($antes_pesos, $this->foto($budget_pesos->id));
        }
    }

    /**
     * Con el presupuesto en PESOS una cotización que no sirve no se rechaza (no interviene en nada)
     * y tampoco pisa la guardada: sirve si más adelante se lo pasa a dólares.
     *
     * @group presupuestos
     * @test
     */
    public function una_cotizacion_invalida_en_pesos_no_se_rechaza_y_no_pisa_la_guardada()
    {
        $budget = $this->presupuesto(self::COTIZACION, 1);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['valor_dolar' => 0]))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(1, (int) $despues->moneda_id);
        $this->assertEquals(self::COTIZACION, (float) $despues->valor_dolar);
        $this->assertEquals('editado por el test', $despues->observations);
    }

    /**
     * 🔴 COMPATIBILIDAD, EL CASO DE 2R: un presupuesto que YA estaba en dólares con `valor_dolar`
     * NULL, editado por una SPA que no manda la clave, NO da 422. Solo se le exige cotización a
     * quien la está tocando o a quien está pasando a dólares.
     *
     * @group presupuestos
     * @test
     */
    public function editar_un_presupuesto_en_dolares_sin_cotizacion_desde_una_spa_vieja_no_da_422()
    {
        // El alta no valida la cotización (store() la guarda como venga): así quedan los de 2R.
        $budget = $this->presupuesto(null, 2);

        $this->assertSame(2, (int) $budget->moneda_id);
        $this->assertNull($budget->valor_dolar);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(2, (int) $despues->moneda_id);
        $this->assertNull($despues->valor_dolar, 'Sin la clave no se inventa una cotización.');
        $this->assertEquals('editado por el test', $despues->observations, 'La edición se guardó.');

        // Y mandando la moneda (la SPA de septiembre de 2025 en adelante la manda) tampoco.
        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['moneda_id' => 2, 'observations' => 'otra vez']))->assertStatus(200);

        $this->assertNull(Budget::find($budget->id)->valor_dolar);
    }

    /**
     * Y el mismo presupuesto de 2R, si la SPA nueva le manda una cotización válida, la guarda: así
     * se "repara" el presupuesto al editarlo.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_en_dolares_sin_cotizacion_la_recibe_al_editarlo()
    {
        $budget = $this->presupuesto(null, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, ['moneda_id' => 2, 'valor_dolar' => 1500]))->assertStatus(200);

        $this->assertEquals(1500, (float) Budget::find($budget->id)->valor_dolar);
    }

    /**
     * El 422 de siempre: un presupuesto confirmado no se edita, venga la moneda y la cotización que
     * venga. Y no escribe nada.
     *
     * @group presupuestos
     * @test
     */
    public function un_presupuesto_confirmado_sigue_dando_422_y_no_se_toca()
    {
        $budget = $this->presupuesto(self::COTIZACION, 1);

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $antes = $this->foto($budget->id);

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'budget_status_id' => self::ESTADO_CONFIRMADO,
            'moneda_id'        => 2,
            'valor_dolar'      => 1500,
        ]));

        $response->assertStatus(422);

        $this->assertFalse((bool) $response->json('sin_cotizacion_dolar'), 'Es el 422 del estado, no el de la cotización.');
        $this->assertStringContainsString('confirmado', $response->json('message'));

        $this->assertEquals($antes, $this->foto($budget->id));
    }

    /**
     * 🔴 EL PUNTO DE LA MISIÓN, de punta a punta: un presupuesto en pesos se convierte a dólares
     * (renglón 12345 -> 10 con cotización 1234,5; total 24690 -> 20) y al CONFIRMARLO la venta nace
     * en dólares, con la misma cotización, con los renglones en dólares y con el total en dólares.
     * `BudgetHelper::saveSale()` ya copia `moneda_id` y `valor_dolar`: esto lo deja fijado.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_un_presupuesto_convertido_a_dolares_da_una_venta_en_dolares_con_la_misma_cotizacion()
    {
        $budget = $this->presupuesto(null, 1);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 2,
            'valor_dolar' => self::COTIZACION,
            'total'       => 20,
            'articles'    => [$this->renglon(10, self::CANTIDAD)],
        ]))->assertStatus(200);

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertSame(2, (int) $sale->moneda_id, 'La venta nace en dólares.');
        $this->assertEquals(self::COTIZACION, (float) $sale->valor_dolar, 'Con la misma cotización que el presupuesto.');
        $this->assertEquals(20, (float) $sale->total, 'Y con el total en dólares.');

        $renglon = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->assertNotNull($renglon);
        $this->assertEquals(10, (float) $renglon->price, 'El renglón de la venta es el del presupuesto, en dólares.');
    }

    /**
     * La vuelta: un presupuesto en dólares se devuelve a pesos y la venta que nace es en pesos con
     * los renglones en pesos.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_un_presupuesto_devuelto_a_pesos_da_una_venta_en_pesos()
    {
        $budget = $this->presupuesto(self::COTIZACION, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 1,
            'valor_dolar' => self::COTIZACION,
        ]))->assertStatus(200);

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale);
        $this->assertSame(1, (int) $sale->moneda_id);
        $this->assertEquals(self::PRECIO_PESOS * self::CANTIDAD, (float) $sale->total);
    }

    /**
     * 🔴 La cotización nueva ya está en el presupuesto cuando se calcula el costo de los renglones:
     * `SaleHelper::getCost()` cotiza con `moneda_id` y `valor_dolar` del presupuesto EN MEMORIA, y
     * `update()` los asigna antes de `save()` y de `attachArticles()`. Un artículo cargado en pesos
     * (costo 5000) en un presupuesto en dólares con cotización 1000 queda con costo 5; si la
     * asignación se moviera después de los renglones, el costo saldría con la cotización vieja.
     *
     * @group presupuestos
     * @test
     */
    public function el_costo_de_los_renglones_se_cotiza_con_la_cotizacion_nueva()
    {
        // Arranca en pesos con otra cotización guardada: si el costo usara la vieja, no daría 5.
        $budget = $this->presupuesto(2000, 1);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 2,
            'valor_dolar' => 1000,
            'total'       => 20,
            'articles'    => [$this->renglon(10, self::CANTIDAD)],
        ]))->assertStatus(200);

        $renglones = $this->renglones_guardados($budget->id);

        $this->assertCount(1, $renglones);
        $this->assertEquals(5, $renglones[0][2], 'Costo en pesos 5000 / cotización 1000 = 5 dólares.');
    }

    /**
     * Revisión de `BudgetHelper::getTotal()` con un presupuesto en dólares y precios con decimales:
     * no convierte nada (suma lo que dice el pivot, en la moneda del presupuesto), así que la cuenta
     * es la misma que en pesos. Fija el comportamiento: descuento por renglón y descuento general
     * se aplican sobre importes en dólares igual que sobre pesos.
     *
     * @group presupuestos
     * @test
     */
    public function get_total_suma_importes_en_dolares_con_decimales_sin_convertir()
    {
        $budget = $this->presupuesto(self::COTIZACION, 2);

        $this->putJson('api/budget/'.$budget->id, $this->payload_put($budget, [
            'moneda_id'   => 2,
            'valor_dolar' => self::COTIZACION,
            'articles'    => [
                array_merge($this->renglon(12.34, 3), ['bonus' => 10]),
                $this->renglon(0.5, 1),
            ],
        ]))->assertStatus(200);

        // 12,34 × 3 = 37,02 − 10 % = 33,318 ; + 0,5 = 33,818
        $this->assertEqualsWithDelta(33.818, BudgetHelper::getTotal(Budget::find($budget->id)), 0.0001);
    }
}
