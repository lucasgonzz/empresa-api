<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Http\Controllers\Helpers\sale\RecargosEnPreciosEsquemaHelper;
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
 * Misión 2r-presupuestos-editables (1/10/2026): el PUT con el cuerpo REAL que manda la SPA para un
 * renglón ya cargado, ya convertido a dólares.
 *
 * `vender_presupuestos.js::get_articles(true)` manda, para un renglón que ya estaba en el
 * presupuesto, el artículo con `id`, `status`, `cost_in_dollars`, `name` y
 * `name_vender_personalizado` en la raíz, y TODO lo demás ANIDADO en `pivot`: `amount`, `price`,
 * `cost`, `costo_real`, `unidades_individuales`, `presentacion`, `price_type_personalizado_id`,
 * `bonus`, `location` y `price_vender_sin_recargos`. Es el camino que usa "Actualizar en VENDER"
 * cuando el vendedor cambia la moneda: la SPA convierte `pivot.price` y `pivot.cost` y los manda.
 *
 * LO QUE SE FIJA: la api guarda ese costo TAL CUAL. `SaleHelper::getCost()` ve `pivot.cost` y
 * devuelve "el ya cotizado" sin volver a dividir por la cotización ni por las unidades
 * individuales, y `corregir_costo_de_bulto_sin_dividir` no lo toca (un costo en dólares frente a
 * una ficha `costo_real` en pesos queda lejísimos de ser "el bulto sin dividir"). Y al confirmar, la
 * venta nace con precio y costo coherentes en dólares: ganancia = (precio − costo) × cantidad.
 *
 * El artículo lleva `unidades_individuales = 10` A PROPÓSITO: es lo que haría tentador dividir el
 * costo de nuevo (0,5 -> 0,05), y es lo que activa la heurística del bulto.
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Update_con_renglon_ya_convertido_a_dolares_Test extends TestCase
{
    use DatabaseTransactions;

    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** @var int Usuario del fixture de testing. */
    const USER_ID = 500;

    /** @var float Cotización: 12000 pesos = 12 dólares, 500 pesos de costo = 0,5 dólares. */
    const COTIZACION = 1000;

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
            'name'     => 'zz Lista (renglon convertido)',
            'user_id'  => self::USER_ID,
            'position' => 5,
        ]);

        // Ficha en pesos: costo del BULTO 5000, 10 unidades individuales -> 500 por unidad.
        $this->article = Article::create([
            'name'                  => 'zz Articulo renglon convertido',
            'user_id'               => self::USER_ID,
            'final_price'           => 12000,
            'costo_real'            => 5000,
            'unidades_individuales' => 10,
            'status'                => 'active',
        ]);
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente()
    {
        $client = Client::create([
            'name'    => 'zz Cliente renglon convertido '.uniqid(),
            'user_id' => self::USER_ID,
        ]);

        CreditAccountHelper::crear_credit_accounts('client', $client->id, self::USER_ID);

        return $client;
    }

    /**
     * Presupuesto en pesos creado por el endpoint con el renglón PLANO que manda `crear()`:
     * 12000 × 2 = 24000, y `getCost()` calcula el costo por unidad (5000 / 10 = 500).
     *
     * @return \App\Models\Budget
     */
    protected function presupuesto_en_pesos()
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
            'total'                            => 12000 * self::CANTIDAD,
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
            'articles'                         => [[
                'id'                          => $this->article->id,
                'status'                      => $this->article->status,
                'cost_in_dollars'             => null,
                'name'                        => $this->article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => self::CANTIDAD,
                'price'                       => 12000,
                'cost'                        => 500,
                'costo_real'                  => 5000,
                'unidades_individuales'       => 10,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
            ]],
        ])->assertStatus(201)->json('model.id');

        $fila = DB::table('article_budget')->where('budget_id', $id)->first();

        $this->assertEquals(500, (float) $fila->cost, 'El presupuesto en pesos arranca con el costo por unidad en pesos.');

        return Budget::find($id);
    }

    /**
     * El renglón como lo manda `get_articles(true)` para uno ya cargado: raíz mínima y todo lo demás
     * anidado en `pivot`. Ya convertido a dólares: precio 12, costo 0,5.
     *
     * @return array
     */
    protected function renglon_ya_cargado_en_dolares()
    {
        return [
            'id'                          => $this->article->id,
            'status'                      => $this->article->status,
            'cost_in_dollars'             => null,
            'name'                        => $this->article->name,
            'name_vender_personalizado'   => null,
            'pivot'                       => [
                'amount'                      => self::CANTIDAD,
                'price'                       => 12,
                'cost'                        => 0.5,
                'costo_real'                  => 5000,
                'unidades_individuales'       => 10,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
                'price_vender_sin_recargos'   => null,
            ],
        ];
    }

    /**
     * @param  \App\Models\Budget  $budget
     * @return array
     */
    protected function put_en_dolares($budget)
    {
        return [
            'client_id'             => $budget->client_id,
            'start_at'              => null,
            'finish_at'             => null,
            'observations'          => 'convertido a dolares',
            'total'                 => 12 * self::CANTIDAD,
            'budget_status_id'      => $budget->budget_status_id,
            'address_id'            => null,
            'surchages_in_services' => 1,
            'discounts_in_services' => 1,
            'sale_status_id'        => null,
            'discount_stock'        => 0,
            'iva_aplicado'          => 1,
            'moneda_id'             => 2,
            'valor_dolar'           => self::COTIZACION,
            'articles'              => [$this->renglon_ya_cargado_en_dolares()],
            'services'              => [],
            'promocion_vinotecas'   => [],
            'discounts'             => [],
            'surchages'             => [],
        ];
    }

    /**
     * 🔴 El costo que manda la SPA ya convertido queda TAL CUAL en `article_budget`: ni dividido
     * otra vez por la cotización (0,0005), ni por las unidades individuales (0,05), ni "corregido"
     * como si fuera un bulto sin dividir. Y el precio, la cantidad y el total, los de la SPA.
     *
     * @group presupuestos
     * @test
     */
    public function el_costo_ya_convertido_queda_tal_cual_en_dolares()
    {
        $budget = $this->presupuesto_en_pesos();

        $this->putJson('api/budget/'.$budget->id, $this->put_en_dolares($budget))->assertStatus(200);

        $despues = Budget::find($budget->id);

        $this->assertSame(2, (int) $despues->moneda_id);
        $this->assertEquals(self::COTIZACION, (float) $despues->valor_dolar);
        $this->assertEquals(24, (float) $despues->total);

        $filas = DB::table('article_budget')->where('budget_id', $budget->id)->get();

        $this->assertCount(1, $filas);
        $this->assertEquals(12, (float) $filas[0]->price, 'El precio es el que mandó la SPA, en dólares.');
        $this->assertEquals(0.5, (float) $filas[0]->cost, 'El costo ya convertido no se reconvierte ni se divide de nuevo.');
        $this->assertEquals(self::CANTIDAD, (float) $filas[0]->amount);

        if (RecargosEnPreciosEsquemaHelper::hay_columna('article_budget')) {
            $this->assertNull($filas[0]->price_sin_recargos_de_venta, 'La clave en null viaja como "sin base" y se guarda así.');
        }
    }

    /**
     * 🔴 Confirmar el presupuesto convertido: la venta nace en dólares, con la misma cotización,
     * con el precio y el costo del presupuesto (en dólares, sin tocar) y con la ganancia
     * `(precio − costo) × cantidad` positiva: (12 − 0,5) × 2 = 23.
     *
     * @group presupuestos
     * @test
     */
    public function al_confirmar_la_venta_nace_con_precio_costo_y_ganancia_coherentes_en_dolares()
    {
        $budget = $this->presupuesto_en_pesos();

        $this->putJson('api/budget/'.$budget->id, $this->put_en_dolares($budget))->assertStatus(200);

        $this->post('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

        $sale = Sale::where('budget_id', $budget->id)->first();

        $this->assertNotNull($sale, 'Confirmar tiene que haber creado la venta.');
        $this->assertSame(2, (int) $sale->moneda_id);
        $this->assertEquals(self::COTIZACION, (float) $sale->valor_dolar);
        $this->assertEquals(24, (float) $sale->total);

        $renglon = DB::table('article_sale')->where('sale_id', $sale->id)->first();

        $this->assertNotNull($renglon);
        $this->assertEquals(12, (float) $renglon->price);
        $this->assertEquals(0.5, (float) $renglon->cost, 'El costo de la venta es el del presupuesto: unitario y en dólares.');
        $this->assertEquals(23, (float) $renglon->ganancia, 'ganancia = (precio − costo) × cantidad, en dólares.');
        $this->assertGreaterThan(0, (float) $renglon->ganancia);
    }
}
