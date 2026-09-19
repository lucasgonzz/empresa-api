<?php

namespace Tests\Feature\Presupuestos;

use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ExtencionEmpresa;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda 2 y 3 de la misión vender-lista-obligatoria (18/9/2026), ítem A4: "omitir en cuenta
 * corriente" NO existe para un presupuesto. Decisión de Lucas (18/9/2026): los presupuestos van
 * siempre a la cuenta corriente al confirmarse, y la opción se ve deshabilitada en Vender cuando lo
 * que se arma es un presupuesto.
 *
 * LA HISTORIA: la SPA manda `omitir_en_cuenta_corriente` desde 2024 (`vender_presupuestos.js`) y
 * la columna existe desde marzo de 2026, pero `BudgetController` nunca la persistía (siempre 0). La
 * tanda 2 la persistió durante unas horas y ahí quedó a la vista el problema: la confirmación
 * desde el listado no trae ningún dato de cobro, y honrar el tilde habría creado ventas de contado
 * sin método de pago ni caja, justo lo que `SaleController::store()` rechaza con el 422
 * `sin_metodo_de_pago`. La tanda 3 cerró la regla: el back fija 0 en el alta, la edición y el
 * duplicado; `saveSale()` escribe 0 en la venta; la SPA manda 0 y bloquea el toggle.
 *
 * Lo que fijan estos tests: el alta guarda 0 con 1, con 0 y sin la clave; confirmar crea la venta
 * a la cuenta corriente con su movimiento y sin métodos de pago, también con un 1 viejo en la fila;
 * el PUT deja 0 con y sin la clave; duplicar nace en 0.
 *
 * DatabaseTransactions sobre la base sembrada del slot; `budget_statuses` se siembra en setUp
 * (mismo cuidado que Presupuestos/1 y /5). La lista de precios viaja explícita en cada POST para
 * que estos tests midan el omitir sin depender de cómo esté `users.listas_de_precio` en la base
 * del slot.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Omitir_cuenta_corriente_Test extends TestCase
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
            'name'     => 'zz Lista (presupuesto omitir cta cte)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article = Article::create([
            'name'        => 'zz Articulo presupuesto omitir cta cte',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO,
            'costo_real'  => 50,
            'status'      => 'active',
        ]);
    }

    /**
     * Cliente propio del test, con su cuenta en pesos (confirmar crea la venta y
     * `CurrentAcountFromSaleHelper` la usa sin chequear null cuando la venta entra a la cuenta).
     *
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente presupuesto omitir '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        return $client;
    }

    /**
     * Payload de POST api/budget con un renglón (molde de Presupuestos/5), tal como lo manda
     * `vender_presupuestos.js::crear()`. SIN `omitir_en_cuenta_corriente`: cada test decide.
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
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
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
     * Payload mínimo y válido de PUT api/budget/{id} (molde de Presupuestos/5). SIN
     * `omitir_en_cuenta_corriente` y sin `price_type_id` (se preservan): cada test decide.
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
     * Presupuesto creado por el endpoint con el omitir que se pida.
     *
     * @param  \App\Models\Client  $client
     * @param  mixed               $omitir
     * @return \App\Models\Budget
     */
    protected function presupuesto_creado($client, $omitir)
    {
        $id = $this->postJson('api/budget', $this->payload_crear($client, [
            'omitir_en_cuenta_corriente' => $omitir,
        ]))->assertStatus(201)->json('model.id');

        return Budget::find($id);
    }

    /**
     * 🔴 LA REGLA: aunque el request mande el omitir en 1 (una SPA vieja con el toggle prendido en
     * el store de Vender), el presupuesto se guarda con 0. Un presupuesto no se omite.
     *
     * @group presupuestos
     * @test
     */
    public function el_alta_con_omitir_en_uno_lo_guarda_en_cero()
    {
        $budget = $this->presupuesto_creado($this->cliente(), 1);

        $this->assertSame(0, (int) $budget->omitir_en_cuenta_corriente, 'Un presupuesto no se puede omitir de la cuenta corriente: el alta tiene que fijar 0.');
    }

    /**
     * Con 0 queda 0, y sin la clave (una SPA que no la manda) también 0, que es el default del
     * store de VENDER y de la columna.
     *
     * @group presupuestos
     * @test
     */
    public function el_alta_con_cero_o_sin_la_clave_guarda_cero()
    {
        $con_cero = $this->presupuesto_creado($this->cliente(), 0);

        $this->assertSame(0, (int) $con_cero->omitir_en_cuenta_corriente);

        $sin_clave_id = $this->postJson('api/budget', $this->payload_crear($this->cliente()))->assertStatus(201)->json('model.id');

        $this->assertSame(0, (int) Budget::find($sin_clave_id)->omitir_en_cuenta_corriente, 'Sin la clave, 0: la columna es NOT NULL y "no omitir" es el default.');
    }

    /**
     * 🔴 Confirmar un presupuesto crea la venta A LA CUENTA CORRIENTE, siempre: aunque el request
     * del alta haya mandado omitir en 1, y aunque la fila del presupuesto tenga un 1 escrito a mano
     * (un dato viejo de la tanda 2, que durante unas horas sí lo persistió). La venta nace con
     * omitir en 0, con su movimiento en la cuenta del cliente y sin ningún método de pago
     * inventado: el cobro se registra después, como pago.
     *
     * Por qué (decisión de Lucas, 18/9/2026): la confirmación desde el listado no trae ningún dato
     * de cobro, y una venta de contado sin método de pago ni caja es justo lo que
     * `SaleController::store()` rechaza con el 422 `sin_metodo_de_pago`.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_un_presupuesto_va_siempre_a_la_cuenta_corriente()
    {
        $client = $this->cliente();

        $budget = $this->presupuesto_creado($client, 1);

        // Un 1 viejo escrito a mano en la fila: tampoco cambia nada al confirmar.
        Budget::where('id', $budget->id)->update(['omitir_en_cuenta_corriente' => 1]);

        $movimientos_antes = CurrentAcount::where('client_id', $client->id)->count();

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertSame(0, (int) $sale->omitir_en_cuenta_corriente, 'La venta nacida de un presupuesto nunca nace omitida.');
        $this->assertTrue(
            CurrentAcount::where('sale_id', $sale->id)->exists(),
            'La venta confirmada deja su movimiento en la cuenta corriente, siempre.'
        );
        $this->assertEquals(
            $movimientos_antes + 1,
            CurrentAcount::where('client_id', $client->id)->count(),
            'La cuenta corriente del cliente tiene un movimiento más: la venta.'
        );
        $this->assertSame(
            0,
            $sale->current_acount_payment_methods()->count(),
            'Y no se inventa ningún método de pago: el cobro se registra después, como pago de la cuenta.'
        );
    }

    /**
     * NO REGRESIÓN: confirmar un presupuesto no omitido sigue creando la venta a la cuenta
     * corriente, con su movimiento.
     *
     * @group presupuestos
     * @test
     */
    public function confirmar_un_presupuesto_no_omitido_sigue_creando_el_movimiento_de_cuenta_corriente()
    {
        $client = $this->cliente();

        $budget = $this->presupuesto_creado($client, 0);

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale);
        $this->assertSame(0, (int) $sale->omitir_en_cuenta_corriente);
        $this->assertTrue(
            CurrentAcount::where('sale_id', $sale->id)->exists(),
            'Una venta a cuenta corriente tiene que dejar su movimiento.'
        );
    }

    /**
     * La edición tampoco lo deja prender: con la clave en 1, sin la clave, o con un 1 viejo en la
     * fila, después del PUT el presupuesto queda en 0.
     *
     * @group presupuestos
     * @test
     */
    public function put_con_o_sin_la_clave_deja_el_omitir_en_cero()
    {
        $budget = $this->presupuesto_creado($this->cliente(), 0);

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, ['omitir_en_cuenta_corriente' => 1]))->assertStatus(200);

        $this->assertSame(0, (int) Budget::find($budget->id)->omitir_en_cuenta_corriente, 'Un PUT con la clave en 1 no puede prender el omitir de un presupuesto.');

        Budget::where('id', $budget->id)->update(['omitir_en_cuenta_corriente' => 1]);

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget))->assertStatus(200);

        $this->assertSame(0, (int) Budget::find($budget->id)->omitir_en_cuenta_corriente, 'Un PUT sin la clave sobre una fila con un 1 viejo la deja en 0.');

        $this->putJson('api/budget/'.$budget->id, $this->payload_actualizar($budget, ['omitir_en_cuenta_corriente' => null]))->assertStatus(200);

        $this->assertSame(0, (int) Budget::find($budget->id)->omitir_en_cuenta_corriente, 'Null explícito también termina en 0 (columna NOT NULL).');
    }

    /**
     * Duplicar un presupuesto con un 1 viejo en la fila: el duplicado nace en 0.
     *
     * @group presupuestos
     * @test
     */
    public function duplicar_un_presupuesto_con_un_uno_viejo_nace_en_cero()
    {
        $this->dar_extension_duplicar();

        $budget = $this->presupuesto_creado($this->cliente(), 0);

        Budget::where('id', $budget->id)->update(['omitir_en_cuenta_corriente' => 1]);

        $duplicado_id = $this->post('api/budget/'.$budget->id.'/duplicate')->assertStatus(201)->json('model.id');

        $this->assertNotNull($duplicado_id, 'Duplicar tiene que devolver el presupuesto nuevo.');
        $this->assertSame(0, (int) Budget::find($duplicado_id)->omitir_en_cuenta_corriente, 'El duplicado nace en 0 aunque el origen tenga un 1 viejo.');
    }
    /**
     * Le da al usuario de testing la extensión que gatea `BudgetController::duplicate()` (403 sin
     * ella), creando la fila del catálogo si la base del slot no la tiene: mismo helper que
     * Presupuestos/2. `DatabaseTransactions` revierte las dos filas.
     *
     * @return void
     */
    protected function dar_extension_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();

        if (is_null($extencion)) {
            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => 'duplicar_presupuestos',
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = User::find(self::USER_ID);

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }
}
