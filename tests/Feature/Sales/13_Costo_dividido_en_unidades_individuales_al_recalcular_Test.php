<?php

namespace Tests\Feature\Sales;

use App\Models\Article;
use App\Models\Client;
use App\Models\User;
use Tests\TestCase;

/**
 * Protege el contrato general de SaleHelper::getCost(): cuando el item que llega en el
 * POST /api/sale NO trae un pivot con costo ya guardado (rama "fresca", cost se recalcula
 * desde costo_real), si el articulo tiene unidades_individuales el costo tiene que quedar
 * dividido por esa cantidad, no el costo del bulto entero.
 *
 * No es el camino que produjo el bug real (ver 14_Costo_..._Presupuesto_Test.php para
 * ese) — este test protege el contrato compartido del que dependen TODOS los que arman
 * un item "fresco" sin pivot (incluida BudgetHelper::attachArticles, que llama a esta
 * misma funcion).
 */
class Costo_dividido_en_unidades_individuales_al_recalcular_Test extends TestCase
{
    public $costo_real = 1000;
    public $unidades_individuales = 10;
    public $amount = 3;

    /**
     * @group sales
     * @test
    */
    public function costo_se_divide_por_unidades_individuales_sin_pivot_de_origen()
    {
        $user = User::find(500);

        $this->actingAs($user, 'web');

        $article = Article::create([
            'name'                   => 'zz Test unidades individuales '.uniqid(),
            'user_id'                => $user->id,
            'costo_real'             => $this->costo_real,
            'unidades_individuales'  => $this->unidades_individuales,
        ]);

        $client = Client::create([
            'name'    => 'zz Cliente test unidades individuales '.uniqid(),
            'user_id' => $user->id,
        ]);

        $costo_esperado_por_unidad = $this->costo_real / $this->unidades_individuales;

        $data = [
            'client_id'                         => $client->id,
            'address_id'                        => null,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'         => 0,
            'to_check'                           => 0,
            'current_acount_payment_method_id'   => null,
            'discounts_in_services'              => 1,
            'surchages_in_services'               => 1,
            'employee_id'                        => null,
            'sub_total'                          => 300,
            'total'                              => 300,
            'terminada'                          => 1,
            'seller_id'                          => null,
            'cantidad_cuotas'                    => null,
            'cuota_descuento'                    => 0,
            'cuota_recargo'                       => 0,
            'caja_id'                            => null,
            'afip_tipo_comprobante_id'            => null,
            'descuento'                          => null,
            'discounts'                          => [],
            'surchages'                          => [],
            'items'                              => [[
                'is_article'              => true,
                'id'                      => $article->id,
                'price_vender'            => 150,
                'amount'                  => $this->amount,
                /**
                 * Shape del item cuando SE REPITE un documento anterior sin costo en
                 * el pivot de origen (getItemsPreviusSale, post-fix): no llega 'pivot',
                 * llega 'costo_real' crudo y 'unidades_individuales' del articulo.
                 */
                'costo_real'              => $this->costo_real,
                'unidades_individuales'   => $this->unidades_individuales,
            ]],
        ];

        $response = $this->post('api/sale', $data);

        $response->assertStatus(201);

        $sale_id = $response->json('model.id');

        $pivot = \DB::table('article_sale')
            ->where('article_id', $article->id)
            ->where('sale_id', $sale_id)
            ->first();

        $this->assertNotNull($pivot, 'No se encontro la linea de venta del articulo de prueba');

        $this->assertEquals(
            round($costo_esperado_por_unidad, 2),
            round((float)$pivot->cost, 2),
            'El costo de la linea quedo sin dividir por unidades_individuales (guardo el costo del bulto entero)'
        );
    }
}
