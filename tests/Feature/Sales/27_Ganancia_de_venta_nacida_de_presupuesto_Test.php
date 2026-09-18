<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\BudgetHelper;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La venta que nace de un presupuesto confirmado tiene que quedar con los mismos numeros que una
 * venta cargada a mano (mision saneo-ganancia-ventas, 17/9/2026).
 *
 * Dos defectos, los dos vivos hasta el 17/9/2026, en `BudgetHelper`:
 *
 *   1. `attachSaleArticles()` guardaba `'ganancia' => $price − $cost` SIN multiplicar por la
 *      cantidad, mientras `SaleHelper::attachArticle()` guarda `($price − $cost) × $amount`. O sea
 *      que TODA venta nacida de un presupuesto tenia la ganancia de linea dividida por la cantidad.
 *      Y el presupuesto es el camino dominante de las ventas en ferretotal.
 *
 *   2. `saveSale()` llamaba a `SaleTotalesHelper::set_total_cost()` y nada mas, asi que
 *      `sales.ganancia` se quedaba en NULL para toda venta nacida de presupuesto — el camino normal
 *      (`SaleHelper::updateOrCreate()`) llama a `set_sale_ganancia()` justo despues del total.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot esta sembrada de antes y
 * un refresh la vaciaria.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class Ganancia_de_venta_nacida_de_presupuesto_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

    /** @var float */
    public $costo_unitario = 200;

    /** @var float */
    public $precio_unitario = 500;

    /** @var float */
    public $cantidad = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(500);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($this->user, 'web');
    }

    /**
     * El estado que dispara la creacion de la venta. El nombre tiene que ser exactamente
     * 'Confirmado': es lo que compara `BudgetHelper::checkStatus()`.
     *
     * @return \App\Models\BudgetStatus
     */
    protected function estado_confirmado()
    {
        $estado = BudgetStatus::where('name', 'Confirmado')->first();

        if (!is_null($estado)) {
            return $estado;
        }

        $estado = new BudgetStatus();
        $estado->name = 'Confirmado';
        $estado->save();

        return $estado;
    }

    /**
     * Presupuesto confirmado con una sola linea, con el costo unitario ya correcto en el pivot.
     *
     * @return \App\Models\Budget
     */
    protected function crear_presupuesto_confirmado()
    {
        $article = new Article();
        $article->user_id = $this->user->id;
        $article->name = 'ZZ Test ganancia presupuesto ' . uniqid();
        $article->status = 'active';
        $article->iva_id = 2;
        $article->costo_real = $this->costo_unitario;
        $article->save();

        $client = Client::create([
            'name' => 'ZZ Cliente test ganancia presupuesto ' . uniqid(),
            'user_id' => $this->user->id,
        ]);

        /*
         * La venta que nace del presupuesto va a la cuenta corriente del cliente, y
         * `CurrentAcountFromSaleHelper` necesita que esa cuenta exista. No es lo que este test
         * protege: es andamiaje para poder llegar a la venta.
         */
        CreditAccount::create([
            'model_name' => 'client',
            'model_id' => $client->id,
            'moneda_id' => 1,
            'user_id' => $this->user->id,
            'saldo' => 0,
        ]);

        $budget = Budget::create([
            'user_id' => $this->user->id,
            'client_id' => $client->id,
            'num' => rand(900000, 999999),
            'total' => $this->precio_unitario * $this->cantidad,
            'budget_status_id' => $this->estado_confirmado()->id,
            'discount_stock' => 0,
            'iva_aplicado' => 1,
            'moneda_id' => 1,
        ]);

        $budget->articles()->attach($article->id, [
            'amount' => $this->cantidad,
            'price' => $this->precio_unitario,
            'cost' => $this->costo_unitario,
        ]);

        return $budget->fresh();
    }

    /**
     * 🔴 La ganancia de la linea es el TOTAL de la linea, no la unitaria: la convencion la fijan
     * `SaleHelper::attachArticle()`, `SaleTotalesHelper::set_total_cost()` y
     * `ContabilidadRepository::costo_mercaderia_vendida()`.
     *
     * @group sales
     * @test
     */
    public function la_ganancia_de_la_linea_se_multiplica_por_la_cantidad()
    {
        $budget = $this->crear_presupuesto_confirmado();

        BudgetHelper::checkStatus($budget, []);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'El presupuesto confirmado no genero la venta');

        $linea = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->assertNotNull($linea, 'La venta nacida del presupuesto quedo sin lineas');

        $this->assertEquals(
            $this->costo_unitario,
            (float) $linea->cost,
            'El costo de la linea tiene que seguir siendo el UNITARIO'
        );

        $this->assertEquals(
            ($this->precio_unitario - $this->costo_unitario) * $this->cantidad,
            (float) $linea->ganancia,
            'La venta nacida de un presupuesto guardo la ganancia de linea sin multiplicar por la cantidad'
        );
    }

    /**
     * Y la ganancia de la VENTA no puede quedar en NULL: el camino normal la persiste enseguida
     * despues del costo total.
     *
     * @group sales
     * @test
     */
    public function la_venta_nacida_del_presupuesto_queda_con_su_ganancia_persistida()
    {
        $budget = $this->crear_presupuesto_confirmado();

        BudgetHelper::checkStatus($budget, []);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'El presupuesto confirmado no genero la venta');

        $this->assertEquals(
            $this->costo_unitario * $this->cantidad,
            (float) $sale->total_cost,
            'sales.total_cost tiene que ser Σ(costo unitario x cantidad)'
        );

        $this->assertNotNull(
            $sale->ganancia,
            'sales.ganancia quedo en NULL: saveSale() llamaba a set_total_cost() y nunca a set_sale_ganancia()'
        );

        /** Sin comprobante de AFIP el IVA declarado es 0, asi que la ganancia es total − costo. */
        $this->assertEquals(
            $this->precio_unitario * $this->cantidad - $this->costo_unitario * $this->cantidad,
            (float) $sale->ganancia
        );
    }
}
