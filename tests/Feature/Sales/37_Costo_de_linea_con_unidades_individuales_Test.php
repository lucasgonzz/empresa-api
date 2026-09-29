<?php

namespace Tests\Feature\Sales;

use App\Http\Controllers\Helpers\sale\CostoDeLineaDeVentaHelper;
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
 * El costo unitario de una linea de venta o de presupuesto de un articulo con
 * `unidades_individuales` tiene que quedar DIVIDIDO por esas unidades, llegue el item como llegue
 * (mision ganancia-unidades-individuales, 29/9/2026).
 *
 * Caso real: ferretotal, venta 54.499 (`sales.id 54735`), ganancia negativa. Nacio del presupuesto
 * 411, que se creo el 2/9 con el SPA viejo, sin la clave `unidades_individuales` en el item:
 * `SaleHelper::getCost()` solo dividia si el item la traia y guardo el costo del BULTO en
 * `article_budget.cost`; al confirmarlo, `BudgetHelper::attachSaleArticles()` lo copio tal cual a
 * `article_sale.cost`.
 *
 * Los 9 casos del plan mas dos extra que cubren la propagacion por el pivot en una venta:
 *
 *  1. venta, item SIN la clave           → divide (lo lee del articulo)
 *  2. venta, item CON la clave           → divide UNA vez, no dos
 *  3. venta, clave presente y en null    → no divide (se respeta al front)
 *  4. presupuesto, item SIN la clave     → divide
 *  5. confirmar presupuesto con bulto    → la venta sale dividida
 *  6. confirmar presupuesto ya unitario  → no se vuelve a dividir
 *  7. perdida legitima sin ui            → intacta
 *  8. ui > 1 con costo plausible         → intacta (caso mangueras)
 *  9. unitario del helper                → tabla de bordes
 * 10. (extra) venta con pivot en bulto, sin la clave → divide
 * 11. (extra) venta con pivot en bulto y clave       → divide UNA vez
 *
 * DatabaseTransactions: la base del slot no trae fixtures (`Kit 1`, `Stock Global`, el cliente
 * "Lucas Gonzalez"), asi que cada test crea sus propios articulo y cliente y no depende de nada.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 */
class Costo_de_linea_con_unidades_individuales_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var \App\Models\User */
    protected $user;

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
     * @param  float $costo_real
     * @param  float|null $unidades_individuales
     * @return \App\Models\Article
     */
    protected function crear_articulo($costo_real, $unidades_individuales)
    {
        return Article::create([
            'name'                  => 'zz Test costo de linea ui ' . uniqid(),
            'user_id'               => $this->user->id,
            'status'                => 'active',
            'costo_real'            => $costo_real,
            'unidades_individuales' => $unidades_individuales,
        ]);
    }

    /**
     * @return \App\Models\Client
     */
    protected function crear_cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente test costo de linea ui ' . uniqid(),
            'user_id' => $this->user->id,
        ]);

        /*
         * Cuenta corriente en pesos: la necesita la venta que nace de un presupuesto confirmado
         * (`CurrentAcountFromSaleHelper`). Es andamiaje, no lo que se protege.
         */
        CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => $this->user->id,
            'saldo'      => 0,
        ]);

        return $client;
    }

    /**
     * POST /api/sale con un solo item, y devuelve la linea de `article_sale` y la venta.
     *
     * @param  \App\Models\Article $article
     * @param  array $item_extra  Claves que se le suman (o se le pisan) al item base.
     * @param  float $price
     * @param  float $amount
     * @return array [$linea, $venta]
     */
    protected function vender($article, $item_extra, $price, $amount)
    {
        $client = $this->crear_cliente();

        $item = array_merge([
            'is_article'   => true,
            'id'           => $article->id,
            'price_vender' => $price,
            'amount'       => $amount,
        ], $item_extra);

        $response = $this->post('api/sale', [
            'client_id'                        => $client->id,
            'address_id'                       => null,
            'save_current_acount'              => 0,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'current_acount_payment_method_id' => null,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $price * $amount,
            'total'                            => $price * $amount,
            'terminada'                        => 1,
            'seller_id'                        => null,
            'cantidad_cuotas'                  => null,
            'cuota_descuento'                  => 0,
            'cuota_recargo'                    => 0,
            'caja_id'                          => null,
            'afip_tipo_comprobante_id'         => null,
            'descuento'                        => null,
            'discounts'                        => [],
            'surchages'                        => [],
            'items'                            => [$item],
        ]);

        $response->assertStatus(201);

        $sale_id = $response->json('model.id');

        $linea = DB::table('article_sale')
            ->where('article_id', $article->id)
            ->where('sale_id', $sale_id)
            ->first();

        $this->assertNotNull($linea, 'No se encontro la linea de venta del articulo de prueba');

        return [$linea, Sale::find($sale_id)];
    }

    /**
     * POST /api/budget con un solo item plano (el shape del alta desde VENDER), y devuelve la
     * linea de `article_budget`. Sin confirmar a proposito, igual que el test 14.
     *
     * @param  \App\Models\Article $article
     * @param  array $item_extra
     * @param  float $price
     * @param  float $amount
     * @return object
     */
    protected function presupuestar($article, $item_extra, $price, $amount)
    {
        $client = $this->crear_cliente();

        $status = BudgetStatus::where('name', 'zz Sin confirmar (test)')->first();

        if (is_null($status)) {
            $status = new BudgetStatus();
            $status->name = 'zz Sin confirmar (test)';
            $status->save();
        }

        $item = array_merge([
            'id'                          => $article->id,
            'status'                      => 'active',
            'cost_in_dollars'             => null,
            'name'                        => $article->name,
            'name_vender_personalizado'   => null,
            'amount'                      => $amount,
            'price'                       => $price,
            'presentacion'                => null,
            'price_type_personalizado_id' => null,
            'bonus'                       => null,
            'location'                    => null,
        ], $item_extra);

        $response = $this->post('api/budget', [
            'client_id'             => $client->id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => null,
            'price_type_id'         => null,
            'sale_status_id'        => null,
            'discount_stock'        => 1,
            'iva_aplicado'          => 1,
            'total'                 => $price * $amount,
            'budget_status_id'      => $status->id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'moneda_id'             => 1,
            'valor_dolar'           => null,
            'discounts'             => [],
            'surchages'             => [],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'articles'              => [$item],
        ]);

        $response->assertStatus(201);

        $linea = DB::table('article_budget')
            ->where('article_id', $article->id)
            ->where('budget_id', $response->json('model.id'))
            ->first();

        $this->assertNotNull($linea, 'No se encontro la linea del presupuesto del articulo de prueba');

        return $linea;
    }

    /**
     * Presupuesto SIN confirmar, con la linea insertada a mano en `article_budget` con el costo que
     * se le diga (como quedo el 411 de ferretotal), y lo confirma por el endpoint real.
     * Devuelve la linea de `article_sale` que nacio.
     *
     * @param  \App\Models\Article $article
     * @param  float $cost_guardado
     * @param  float $price
     * @param  float $amount
     * @return object
     */
    protected function confirmar_presupuesto_con_costo_guardado($article, $cost_guardado, $price, $amount)
    {
        $client = $this->crear_cliente();

        $sin_confirmar = BudgetStatus::where('name', 'zz Sin confirmar (test)')->first();

        if (is_null($sin_confirmar)) {
            $sin_confirmar = new BudgetStatus();
            $sin_confirmar->name = 'zz Sin confirmar (test)';
            $sin_confirmar->save();
        }

        /*
         * `BudgetController::confirmar()` compara contra el id fijo `ESTADO_CONFIRMADO`, no por
         * nombre: el estado tiene que existir con ese id o el presupuesto no se confirma bien.
         */
        $budget = Budget::create([
            'user_id'          => $this->user->id,
            'client_id'        => $client->id,
            'num'              => rand(900000, 999999),
            'total'            => $price * $amount,
            'budget_status_id' => $sin_confirmar->id,
            'discount_stock'   => 0,
            'iva_aplicado'     => 1,
            'moneda_id'        => 1,
        ]);

        $budget->articles()->attach($article->id, [
            'amount' => $amount,
            'price'  => $price,
            'cost'   => $cost_guardado,
        ]);

        $response = $this->post('api/budget/' . $budget->id . '/confirmar');

        $response->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'El presupuesto confirmado no genero la venta');

        $linea = DB::table('article_sale')
            ->where('sale_id', $sale->id)
            ->where('article_id', $article->id)
            ->first();

        $this->assertNotNull($linea, 'La venta nacida del presupuesto quedo sin la linea del articulo');

        return $linea;
    }

    /**
     * 1. El SPA viejo no manda `unidades_individuales`: el API se la lee al articulo.
     *
     * @group sales
     * @test
     */
    public function venta_sin_la_clave_de_unidades_individuales_divide_leyendola_del_articulo()
    {
        $article = $this->crear_articulo(2000, 100);

        list($linea, $venta) = $this->vender($article, ['costo_real' => 2000], 150, 3);

        $this->assertEquals(20.00, round((float) $linea->cost, 2), 'El costo quedo con el del bulto (2000) en vez de 2000 / 100');

        $this->assertEquals(
            (150 - 20) * 3,
            round((float) $linea->ganancia, 2),
            'La ganancia de la linea tiene que ser (precio - costo unitario) x cantidad'
        );

        $this->assertGreaterThan(0, (float) $linea->ganancia, 'La linea quedo con ganancia negativa o cero');

        $this->assertEquals(20.00 * 3, round((float) $venta->total_cost, 2), 'sales.total_cost tiene que ser Σ(costo unitario x cantidad)');

        $this->assertNotNull($venta->ganancia);
        $this->assertGreaterThan(0, (float) $venta->ganancia, 'La venta quedo con ganancia negativa o cero');
    }

    /**
     * 2. El SPA nuevo manda la clave: se divide UNA sola vez.
     *
     * @group sales
     * @test
     */
    public function venta_con_la_clave_de_unidades_individuales_divide_una_sola_vez()
    {
        $article = $this->crear_articulo(2000, 100);

        list($linea, $venta) = $this->vender(
            $article,
            ['costo_real' => 2000, 'unidades_individuales' => 100],
            150,
            3
        );

        $this->assertEquals(
            20.00,
            round((float) $linea->cost, 2),
            'Con la clave presente el costo tiene que ser 2000 / 100 = 20, dividido una sola vez (no 0,20)'
        );
    }

    /**
     * 3. La clave viene, pero en null: el front la mando a proposito (la tabla de VENDER permite
     * editarla por venta) y el API no la pisa con la de la ficha.
     *
     * @group sales
     * @test
     */
    public function venta_con_la_clave_en_null_no_divide_se_respeta_al_front()
    {
        $article = $this->crear_articulo(2000, 100);

        list($linea, $venta) = $this->vender(
            $article,
            ['costo_real' => 2000, 'unidades_individuales' => null],
            150,
            3
        );

        $this->assertEquals(
            2000.00,
            round((float) $linea->cost, 2),
            'Con la clave presente y en null el API no tiene que ir a buscar la de la ficha'
        );
    }

    /**
     * 4. Lo mismo, en el alta de un presupuesto: `BudgetHelper::attachArticles()` llama a la misma
     * `getCost()`. Es exactamente lo que hizo el SPA viejo con el presupuesto 411.
     *
     * @group sales
     * @test
     */
    public function presupuesto_sin_la_clave_de_unidades_individuales_guarda_el_costo_dividido()
    {
        $article = $this->crear_articulo(2000, 100);

        $linea = $this->presupuestar($article, ['costo_real' => 2000], 150, 3);

        $this->assertEquals(
            20.00,
            round((float) $linea->cost, 2),
            'article_budget.cost quedo con el costo del bulto: getCost() no le leyo las unidades al articulo'
        );
    }

    /**
     * 5. La propagacion: el presupuesto ya quedo guardado con el costo del bulto (como el 411 de
     * ferretotal, insertado a mano) y al confirmarlo la venta NO puede heredarlo.
     *
     * Numeros de la linea 175592 de la venta 54.499: PRECINTOS, ui 100, costo 4118,66, precio 61,78.
     *
     * @group sales
     * @test
     */
    public function confirmar_un_presupuesto_con_el_costo_del_bulto_deja_la_venta_dividida()
    {
        $article = $this->crear_articulo(4118.66, 100);

        $linea = $this->confirmar_presupuesto_con_costo_guardado($article, 4118.66, 61.78, 2);

        $this->assertEquals(
            41.19,
            round((float) $linea->cost, 2),
            'La venta heredo el costo del bulto del presupuesto (4118,66) en vez de 41,19'
        );

        $this->assertGreaterThan(0, (float) $linea->ganancia, 'La linea de la venta quedo con ganancia negativa');

        $this->assertEqualsWithDelta(
            (61.78 - 41.19) * 2,
            (float) $linea->ganancia,
            0.02,
            'La ganancia de la linea tiene que ser (precio - costo unitario) x cantidad'
        );
    }

    /**
     * 6. Un presupuesto que ya tiene el costo unitario NO se vuelve a dividir al confirmarse.
     *
     * @group sales
     * @test
     */
    public function confirmar_un_presupuesto_con_el_costo_ya_unitario_no_lo_vuelve_a_dividir()
    {
        $article = $this->crear_articulo(4118.66, 100);

        $linea = $this->confirmar_presupuesto_con_costo_guardado($article, 41.19, 61.78, 2);

        $this->assertEquals(
            41.19,
            round((float) $linea->cost, 2),
            'Un costo que ya es unitario se dividio otra vez (quedo 0,41)'
        );
    }

    /**
     * 7. Una linea legitimamente a perdida (costo > 2 x precio) de un articulo SIN unidades
     * individuales no se toca: la guarda no es "corregi todo lo que pierde".
     *
     * @group sales
     * @test
     */
    public function una_perdida_legitima_sin_unidades_individuales_queda_intacta()
    {
        $article = $this->crear_articulo(1000, null);

        // Camino del pivot (repetir una venta): el costo guardado se devuelve tal cual.
        list($linea, $venta) = $this->vender(
            $article,
            ['pivot' => ['cost' => 1000, 'price' => 100]],
            100,
            2
        );

        $this->assertEquals(1000.00, round((float) $linea->cost, 2), 'Se toco el costo de una perdida legitima');

        // Y por el camino de confirmar un presupuesto.
        $linea_de_presupuesto = $this->confirmar_presupuesto_con_costo_guardado($article, 1000, 100, 2);

        $this->assertEquals(1000.00, round((float) $linea_de_presupuesto->cost, 2), 'Se toco el costo de una perdida legitima al confirmar');
    }

    /**
     * 8. Caso mangueras (art. 3073/3074/3075 de ferretotal): ui 25, costo con que se vendio 388,77
     * y precio 583,16 (margen del 50 %). Es una linea SANA aunque la ficha de hoy diga otra cosa:
     * la guarda mira el precio de la propia linea, no `articles.costo_real`.
     *
     * @group sales
     * @test
     */
    public function una_linea_con_unidades_individuales_y_costo_plausible_queda_intacta()
    {
        // La ficha de HOY ya cambio: costo_real 6,17. La guarda no puede mirarla.
        $article = $this->crear_articulo(6.17, 25);

        list($linea, $venta) = $this->vender(
            $article,
            ['pivot' => ['cost' => 388.77, 'price' => 583.16]],
            583.16,
            1
        );

        $this->assertEquals(388.77, round((float) $linea->cost, 2), 'Se dividio una linea sana por sus unidades individuales');

        $linea_de_presupuesto = $this->confirmar_presupuesto_con_costo_guardado($article, 388.77, 583.16, 1);

        $this->assertEquals(388.77, round((float) $linea_de_presupuesto->cost, 2), 'Se dividio una linea sana al confirmar el presupuesto');
    }

    /**
     * 9. Unitario del criterio: tabla de casos borde de `corregir_costo_de_bulto_sin_dividir()`.
     *
     * @group sales
     * @test
     */
    public function el_criterio_de_bulto_sin_dividir_en_casos_borde()
    {
        // [descripcion, cost, price, unidades, costo_real de la ficha, esperado].
        // `null` esperado = tiene que devolver el cost tal cual.
        $casos = [
            ['bulto evidente (ferretotal 54.499, precintos)', 4118.66, 61.78, 100, 4117.509, 41.19],
            ['bulto evidente (tornillo c/tanque)', 4174.98, 31.31, 200, 4173.8345, 20.87],
            ['ui null', 4118.66, 61.78, null, 4117.509, null],
            ['ui 0', 4118.66, 61.78, 0, 4117.509, null],
            ['ui 1', 4118.66, 61.78, 1, 4117.509, null],
            ['ui menor a 1', 4118.66, 61.78, 0.5, 4117.509, null],
            ['precio 0', 4118.66, 0, 100, 4117.509, null],
            ['precio null', 4118.66, null, 100, 4117.509, null],
            ['precio negativo', 4118.66, -5, 100, 4117.509, null],
            ['costo 0', 0, 61.78, 100, 4117.509, null],
            ['costo null', null, 61.78, 100, 4117.509, null],
            ['costo ya unitario (menor al precio)', 41.19, 61.78, 100, 4117.509, null],
            ['costo plausible con ui alta (mangueras, ficha de hoy 6,171)', 388.77, 583.16, 25, 6.171, null],
            // Umbral: cost = 2 x price exacto NO es incoherente (la condicion es estrictamente mayor).
            ['justo en el umbral, no corrige', 200, 100, 10, 200, null],
            ['apenas arriba del umbral, corrige', 200.01, 100, 10, 200, 20.00],
            // Dividir no alcanza: cost / ui sigue por arriba de 2 x price → otro motivo, no se adivina.
            ['dividir no alcanza', 100000, 100, 10, 100000, null],
            // El limite del "no alcanza": cost / ui justo en 2 x price todavia corrige.
            ['dividir alcanza justo', 2000, 100, 10, 2000, 200.00],
            ['costo string numerico', '4118.66', '61.78', '100', '4117.509', 41.19],

            // Los falsos positivos que midio el verificador independiente: una linea SANA con
            // ui > 1 vendida a perdida fuerte cumple las condiciones de precio y NO es un bulto sin
            // dividir: el costo guardado esta al orden de magnitud de la UNIDAD (ficha / ui).
            ['sana a perdida fuerte (cost 100, price 40, ui 10, ficha 1000)', 100, 40, 10, 1000, null],
            ['sana a perdida fuerte (cost 100, price 10, ui 25, ficha 2500)', 100, 10, 25, 2500, null],
            ['sana a perdida fuerte (cost 100, price 40, ui 2, ficha 200)', 100, 40, 2, 200, null],

            // Sin ficha usable no se puede comparar y no se adivina.
            ['ficha null', 4118.66, 61.78, 100, null, null],
            ['ficha 0', 4118.66, 61.78, 100, 0, null],
            ['ficha negativa', 4118.66, 61.78, 100, -4117.5, null],
            ['ficha no numerica', 4118.66, 61.78, 100, 'abc', null],
            ['ficha string vacio', 4118.66, 61.78, 100, '', null],

            // Sin cota superior: si la ficha cambio mucho despues de la venta (o se cotizo en
            // dolares) el arbitro sigue siendo el precio de la propia linea.
            ['ficha muy por debajo del costo guardado, corrige por precio', 4118.66, 61.78, 100, 6.17, 41.19],
        ];

        foreach ($casos as $caso) {
            list($descripcion, $cost, $price, $unidades, $ficha, $esperado) = $caso;

            $resultado = CostoDeLineaDeVentaHelper::corregir_costo_de_bulto_sin_dividir($cost, $price, $unidades, $ficha);

            if (is_null($esperado)) {
                $this->assertSame($cost, $resultado, 'Caso "' . $descripcion . '": tenia que devolver el costo intacto');
            } else {
                $this->assertEqualsWithDelta($esperado, (float) $resultado, 0.001, 'Caso "' . $descripcion . '"');
            }
        }
    }

    /**
     * 10. (extra) Repetir una venta cuyo pivot quedo con el costo del bulto, item sin la clave:
     * combina la lectura de las unidades (A) con la guarda del pivot (B).
     *
     * @group sales
     * @test
     */
    public function venta_con_pivot_en_bulto_y_sin_la_clave_se_corrige()
    {
        $article = $this->crear_articulo(4118.66, 100);

        list($linea, $venta) = $this->vender(
            $article,
            ['pivot' => ['cost' => 4118.66, 'price' => 61.78]],
            61.78,
            2
        );

        $this->assertEquals(41.19, round((float) $linea->cost, 2), 'El pivot con el costo del bulto se copio sin dividir');
        $this->assertGreaterThan(0, (float) $linea->ganancia);
    }

    /**
     * 11. (extra) Lo mismo pero con la clave presente: se divide UNA vez, no dos.
     *
     * @group sales
     * @test
     */
    public function venta_con_pivot_en_bulto_y_con_la_clave_se_divide_una_sola_vez()
    {
        $article = $this->crear_articulo(4118.66, 100);

        list($linea, $venta) = $this->vender(
            $article,
            ['unidades_individuales' => 100, 'pivot' => ['cost' => 4118.66, 'price' => 61.78]],
            61.78,
            2
        );

        $this->assertEquals(41.19, round((float) $linea->cost, 2), 'Con pivot y clave el costo tiene que dividirse una sola vez');
    }

    /**
     * 12. (extra) Falso positivo de la guarda por el camino de confirmar un presupuesto: una linea
     * SANA de un articulo con ui 10, con el costo ya unitario (100), vendida a perdida fuerte
     * (precio 40) y con la ficha en 1000 (el bulto). Cumple las condiciones de precio del criterio
     * (100 > 2 x 40 y 100 / 10 <= 80) pero el costo esta al orden de la UNIDAD, no del bulto: la
     * venta tiene que guardar 100 sin tocar, no 10.
     *
     * @group sales
     * @test
     */
    public function confirmar_una_linea_sana_a_perdida_fuerte_con_ui_no_la_divide()
    {
        $article = $this->crear_articulo(1000, 10);

        $linea = $this->confirmar_presupuesto_con_costo_guardado($article, 100, 40, 2);

        $this->assertEquals(
            100.00,
            round((float) $linea->cost, 2),
            'Se dividio una linea sana vendida a perdida: el costo guardado ya era el unitario'
        );
    }

    /**
     * 13. (extra) Lo mismo por la rama pivot de `getCost()` (repetir / editar una venta): con la
     * ficha leida de la base (el item no trae `costo_real`) y con la ficha que viaja en el item.
     *
     * @group sales
     * @test
     */
    public function venta_con_pivot_sano_a_perdida_fuerte_y_ui_queda_intacta()
    {
        $article = $this->crear_articulo(1000, 10);

        // Ficha leida de la base: el item no trae `costo_real` ni la clave `unidades_individuales`.
        list($linea_a, $venta_a) = $this->vender(
            $article,
            ['pivot' => ['cost' => 100, 'price' => 40]],
            40,
            2
        );

        $this->assertEquals(100.00, round((float) $linea_a->cost, 2), 'Con la ficha leida de la base se dividio una linea sana');

        // Ficha que viaja en el item, y la clave presente.
        list($linea_b, $venta_b) = $this->vender(
            $article,
            ['costo_real' => 1000, 'unidades_individuales' => 10, 'pivot' => ['cost' => 100, 'price' => 40]],
            40,
            2
        );

        $this->assertEquals(100.00, round((float) $linea_b->cost, 2), 'Con la ficha del item se dividio una linea sana');
    }
}
