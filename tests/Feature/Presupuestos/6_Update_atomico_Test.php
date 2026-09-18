<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\Budget\ComboEsquemaHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tanda 2 de la misión vender-lista-obligatoria (18/9/2026), ítem A1: `BudgetController::update()`
 * es ATÓMICO. Si algo revienta a mitad de camino, el presupuesto queda exactamente como estaba.
 *
 * EL BUG: `update()` era el único método de la clase sin transacción ni try/catch, y el orden de
 * adentro lo hacía peligroso: el `save()` de los campos (`total` incluido) y el detach TOTAL de
 * los artículos (`BudgetHelper::attachArticles()` empieza por `detach()`) van ANTES de
 * `attachArticles()`, `attachCombos()` y `checkStatus()`. Un fallo en cualquiera de esos tres
 * respondía 500 con el total ya cambiado y el presupuesto sin renglones, o con los renglones a
 * medias.
 *
 * Los dos fallos que se provocan acá son reales y reproducibles con el código de hoy: un
 * `budget_status_id` que no existe (el `save()` pasa —no hay FK— y `checkStatus()` muere en
 * `$budget->budget_status->name` sobre null, DESPUÉS de re-adjuntar los artículos) y un combo con
 * el pivote mal formado (`attachCombos()` muere en `$combo['pivot']['amount']`, después del
 * detach de los artículos y de re-adjuntar los que vinieron). En los dos, lo que se mide es que
 * `article_budget` tiene los MISMOS renglones que antes y `budgets.total` y `observations` no
 * cambiaron. Y el camino feliz, para que la transacción no se coma el update que sí anda.
 *
 * DatabaseTransactions sobre la base sembrada del slot: la transacción del controlador queda
 * anidada adentro de la del test (savepoint), que es exactamente como corren store() y confirmar()
 * en Presupuestos/1 y /5. `budget_statuses` se siembra en setUp. La lista de precios viaja
 * explícita para no depender de `users.listas_de_precio` en la base del slot.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Update_atomico_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** @var int Un estado que no existe: pasa el save() y tumba checkStatus(). */
    const ESTADO_INEXISTENTE = 999999;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var \App\Models\User */
    protected $user;

    /** @var \App\Models\PriceType */
    protected $lista;

    /** @var \App\Models\Article Renglón 1: 100 × 1. */
    protected $article_a;

    /** @var \App\Models\Article Renglón 2: 200 × 1. */
    protected $article_b;

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

        $this->assertNull(BudgetStatus::find(self::ESTADO_INEXISTENTE), 'El estado inexistente del test tiene que no existir.');

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');

        $this->lista = PriceType::create([
            'name'     => 'zz Lista (update atomico)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        $this->article_a = Article::create([
            'name'        => 'zz Articulo A update atomico',
            'user_id'     => self::USER_ID,
            'final_price' => 100,
            'costo_real'  => 50,
            'status'      => 'active',
        ]);

        $this->article_b = Article::create([
            'name'        => 'zz Articulo B update atomico',
            'user_id'     => self::USER_ID,
            'final_price' => 200,
            'costo_real'  => 80,
            'status'      => 'active',
        ]);
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente update atomico '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccount::firstOrCreate(
            ['model_name' => 'client', 'model_id' => $client->id, 'moneda_id' => 1],
            ['saldo' => 0, 'user_id' => self::USER_ID]
        );

        return $client;
    }

    /**
     * El renglón como lo manda `vender_presupuestos.js::crear()` (plano).
     *
     * @param  \App\Models\Article  $article
     * @param  int                  $amount
     * @return array
     */
    protected function renglon($article, $amount)
    {
        return [
            'id'                          => $article->id,
            'status'                      => $article->status,
            'cost_in_dollars'             => null,
            'name'                        => $article->name,
            'name_vender_personalizado'   => null,
            'amount'                      => $amount,
            'price'                       => $article->final_price,
            'costo_real'                  => $article->costo_real,
            'unidades_individuales'       => null,
            'presentacion'                => null,
            'price_type_personalizado_id' => null,
            'bonus'                       => null,
            'location'                    => null,
        ];
    }

    /**
     * Presupuesto creado por el endpoint con los dos renglones (A × 1 y B × 1, total 300).
     *
     * @return \App\Models\Budget
     */
    protected function presupuesto_con_dos_renglones()
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
            'total'                            => 300,
            'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
            'address_id'                       => null,
            'surchages_in_services'            => 1,
            'discounts_in_services'            => 1,
            'aplicar_recargos_directo_a_items' => null,
            'moneda_id'                        => 1,
            'valor_dolar'                      => null,
            'omitir_en_cuenta_corriente'       => 0,
            'discounts'                        => [],
            'surchages'                        => [],
            'services'                         => [],
            'promocion_vinotecas'              => [],
            'articles'                         => [
                $this->renglon($this->article_a, 1),
                $this->renglon($this->article_b, 1),
            ],
        ])->assertStatus(201)->json('model.id');

        $budget = Budget::find($id);

        $this->assertEquals([$this->article_a->id => 1.0, $this->article_b->id => 1.0], $this->renglones_guardados($budget->id), 'El presupuesto tiene que arrancar con sus dos renglones.');

        return $budget;
    }

    /**
     * Los renglones de `article_budget` del presupuesto, como `[article_id => amount]`, leídos
     * crudos de la tabla y ordenados por artículo.
     *
     * @param  int  $budget_id
     * @return array
     */
    protected function renglones_guardados($budget_id)
    {
        $renglones = [];

        $filas = DB::table('article_budget')
                    ->where('budget_id', $budget_id)
                    ->orderBy('article_id')
                    ->get();

        foreach ($filas as $fila) {
            $renglones[(int) $fila->article_id] = (float) $fila->amount;
        }

        return $renglones;
    }

    /**
     * El PUT que cambia todo lo que se pueda medir: otro total, otras observaciones y UN SOLO
     * renglón (A × 5) en vez de los dos. Cada test le suma lo que hace reventar el update.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $overrides
     * @return array
     */
    protected function payload_que_cambia_todo($budget, $overrides = [])
    {
        return array_merge([
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'no tendria que guardarse',
            'total'                 => 500,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'articles'              => [$this->renglon($this->article_a, 5)],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ], $overrides);
    }

    /**
     * Las aserciones del "quedó como estaba", compartidas.
     *
     * @param  \App\Models\Budget  $budget
     * @return void
     */
    protected function assert_quedo_como_estaba($budget)
    {
        $despues = Budget::find($budget->id);

        $this->assertEquals(
            [$this->article_a->id => 1.0, $this->article_b->id => 1.0],
            $this->renglones_guardados($budget->id),
            'Después del error, article_budget tiene que tener los MISMOS renglones que antes.'
        );
        $this->assertEquals(300, (float) $despues->total, 'El total no se tiene que haber tocado.');
        $this->assertEquals('presupuesto de prueba', $despues->observations, 'Las observaciones no se tienen que haber tocado.');
        $this->assertEquals(self::ESTADO_SIN_CONFIRMAR, (int) $despues->budget_status_id, 'El estado no se tiene que haber tocado.');
    }

    /**
     * 🔴 EL CASO DEL BUG, con `checkStatus()`: un `budget_status_id` que no existe pasa el save()
     * (no hay FK), los artículos se detachan y se vuelven a adjuntar con el renglón nuevo, y recién
     * ahí `checkStatus()` muere en `$budget->budget_status->name`. Antes: 500 con el total en 500,
     * las observaciones nuevas y un solo renglón (A × 5). Ahora: 500 y el presupuesto intacto.
     *
     * @group presupuestos
     * @test
     */
    public function un_estado_inexistente_revienta_despues_de_escribir_y_el_presupuesto_queda_como_estaba()
    {
        $budget = $this->presupuesto_con_dos_renglones();

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_que_cambia_todo($budget, [
            'budget_status_id' => self::ESTADO_INEXISTENTE,
        ]));

        $response->assertStatus(500);

        $this->assertTrue((bool) $response->json('error'), 'El 500 tiene que ser el del catch de update(), con su cuerpo {error, message}.');
        $this->assertNotEmpty($response->json('message'));

        $this->assert_quedo_como_estaba($budget);
    }

    /**
     * 🔴 EL CASO DEL BUG, con `attachCombos()`: un combo con el pivote sin `amount` muere en
     * `$combo['pivot']['amount']`, después del detach de los artículos y de re-adjuntar el renglón
     * que vino. Antes: 500 con un solo renglón y el total cambiado. Ahora: intacto.
     *
     * Solo tiene sentido con la tabla `budget_combo` migrada: sin ella `attachCombos()` corta antes
     * de mirar el payload y no hay nada que hacer reventar por ese camino.
     *
     * @group presupuestos
     * @test
     */
    public function un_combo_mal_formado_revienta_despues_de_escribir_y_el_presupuesto_queda_como_estaba()
    {
        if (!ComboEsquemaHelper::hay_tabla()) {
            $this->markTestSkipped('La base de testing no tiene la tabla budget_combo: attachCombos() no llega a mirar el payload.');
        }

        $budget = $this->presupuesto_con_dos_renglones();

        $response = $this->putJson('api/budget/'.$budget->id, $this->payload_que_cambia_todo($budget, [
            'combos' => [
                ['id' => 999999, 'pivot' => ['price' => 10]],
            ],
        ]));

        $response->assertStatus(500);

        $this->assert_quedo_como_estaba($budget);
    }

    /**
     * El camino feliz sigue andando: el mismo PUT sin nada roto cambia el total, las observaciones
     * y deja el único renglón nuevo. Es la prueba de que la transacción se commitea.
     *
     * @group presupuestos
     * @test
     */
    public function un_put_valido_se_guarda_entero()
    {
        $budget = $this->presupuesto_con_dos_renglones();

        $this->putJson('api/budget/'.$budget->id, $this->payload_que_cambia_todo($budget))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertEquals([$this->article_a->id => 5.0], $this->renglones_guardados($budget->id), 'El PUT válido deja el renglón nuevo, y solo ese.');
        $this->assertEquals(500, (float) $despues->total);
        $this->assertEquals('no tendria que guardarse', $despues->observations);
    }
}
