<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\User;
use Tests\TestCase;

/**
 * Camino real que produjo el bug (venta 50662 de ferretotal, articulo 12753): al agregar
 * un articulo con unidades_individuales a un PRESUPUESTO, vender_presupuestos.js::get_articles()
 * arma el item con una lista fija de campos que nunca incluia unidades_individuales — ni
 * en la rama "plana" (alta de un articulo nuevo, la que ejercita este test) ni en la
 * anidada bajo pivot (edicion de un articulo ya cargado).
 *
 * BudgetController::store() manda ese item, tal cual, a BudgetHelper::attachArticles(),
 * que llama a la MISMA SaleHelper::getCost() que usa una venta. Sin unidades_individuales
 * en el item, getCost() calcula el costo desde costo_real (el costo del bulto entero) y
 * nunca lo divide: el presupuesto queda guardado con el costo del bulto, no el de la
 * unidad individual — y como una venta armada por "repetir presupuesto anterior" hereda
 * ese costo tal cual (via el pivot ya guardado), la venta nace mal.
 *
 * Shape del item: replica el objeto que arma get_articles() para un articulo SIN pivot
 * previo (alta) — sin la clave 'pivot', con costo_real y (post-fix) unidades_individuales
 * a nivel raiz.
 */
class Costo_dividido_en_unidades_individuales_al_crear_presupuesto_Test extends TestCase
{
    public $costo_real = 2000;
    public $unidades_individuales = 20;
    public $amount = 5;
    public $price = 300;

    /**
     * @group sales
     * @test
    */
    public function costo_del_presupuesto_se_divide_por_unidades_individuales_al_agregar_un_articulo_nuevo()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $article = Article::create([
            'name'                   => 'zz Test presupuesto unidades individuales '.uniqid(),
            'user_id'                => $user->id,
            'costo_real'             => $this->costo_real,
            'unidades_individuales'  => $this->unidades_individuales,
        ]);

        $client = Client::create([
            'name'    => 'zz Cliente test presupuesto unidades individuales '.uniqid(),
            'user_id' => $user->id,
        ]);

        $costo_esperado_por_unidad = $this->costo_real / $this->unidades_individuales;
        $total = $this->price * $this->amount;

        /**
         * "Sin confirmar" (no 'Confirmado') a proposito: si el status fuera 'Confirmado',
         * BudgetHelper::checkStatus() dispara ademas la creacion de una venta y de una
         * cuenta corriente, que no son parte de lo que este test protege.
         */
        $budget_status = BudgetStatus::where('name', 'zz Sin confirmar (test)')->first();

        if (is_null($budget_status)) {
            $budget_status = new BudgetStatus();
            $budget_status->name = 'zz Sin confirmar (test)';
            $budget_status->save();
        }

        $data = [
            'client_id'              => $client->id,
            'start_at'               => null,
            'finish_at'              => null,
            'observations'           => null,
            'price_type_id'          => null,
            'sale_status_id'         => null,
            'discount_stock'         => 1,
            'iva_aplicado'           => 1,
            'total'                  => $total,
            'budget_status_id'       => $budget_status->id,
            'address_id'             => null,
            'surchages_in_services'  => 1,
            'discounts_in_services'  => 1,
            'moneda_id'              => 1,
            'valor_dolar'            => null,
            'discounts'              => [],
            'surchages'              => [],
            'services'               => [],
            'promocion_vinotecas'    => [],
            'articles'               => [[
                'id'                          => $article->id,
                'status'                      => $article->status,
                'cost_in_dollars'             => null,
                'name'                        => $article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => $this->amount,
                'price'                       => $this->price,
                'costo_real'                  => $this->costo_real,
                'unidades_individuales'       => $this->unidades_individuales,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
            ]],
        ];

        $response = $this->post('api/budget', $data);

        $response->assertStatus(201);

        $budget_id = $response->json('model.id');

        $pivot = \DB::table('article_budget')
            ->where('article_id', $article->id)
            ->where('budget_id', $budget_id)
            ->first();

        $this->assertNotNull($pivot, 'No se encontro la linea del presupuesto del articulo de prueba');

        $this->assertEquals(
            round($costo_esperado_por_unidad, 2),
            round((float)$pivot->cost, 2),
            'El costo de la linea del presupuesto quedo sin dividir por unidades_individuales (guardo el costo del bulto entero)'
        );
    }
}
