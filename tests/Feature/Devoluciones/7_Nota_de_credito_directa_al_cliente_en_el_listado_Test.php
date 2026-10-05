<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Nota de crédito DIRECTA al cliente (sin venta de origen) con artículos: la que hace el módulo
 * de Devoluciones cuando se elige solo el cliente y se cargan los artículos a mano.
 *
 * Pedido de Fénix (3/10/2026): la NC quedaba en la cuenta corriente del cliente pero no aparecía
 * en el módulo de Notas de crédito.
 *
 * Se va por el camino real: POST api/devoluciones/ con el payload que arma la SPA y después el
 * GET que usa el listado (`api/nota-credito/from-date/{hoy}`).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Nota_de_credito_directa_al_cliente_en_el_listado_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Cliente con su cuenta corriente en pesos y un artículo.
     *
     * @return array{client: Client, credit_account: CreditAccount, article: Article}
     */
    protected function escenario()
    {
        $client = Client::create([
            'name'    => 'zz Cliente NC directa',
            'user_id' => 500,
        ]);

        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => 500,
        ]);

        $article = Article::create([
            'name'    => 'zz Articulo NC directa',
            'user_id' => 500,
        ]);

        return [
            'client'         => $client,
            'credit_account' => $credit_account,
            'article'        => $article,
        ];
    }

    /**
     * El payload que manda la SPA para una devolución sin venta: los items nacen del buscador de
     * artículos con `pivot: {}`, `discount: ''` y sin `sale_id`.
     *
     * @param array $e
     * @param int   $unidades
     * @param float $precio
     * @return array
     */
    protected function payload_sin_venta($e, $unidades, $precio)
    {
        return [
            'sale_id'                   => null,
            'client_id'                 => $e['client']->id,
            'generar_current_acount'    => 1,
            'total_devolucion'          => $unidades * $precio,
            'observaciones'             => 'test sin venta',
            'items'                     => [[
                'id'                 => $e['article']->id,
                'is_article'         => true,
                'price_vender'       => $precio,
                'amount'             => '',
                'article_variant_id' => 0,
                'discount'           => '',
                'returned_amount'    => $unidades,
                'unidades_devueltas' => $unidades,
                'ya_devueltas'       => null,
                'costo_real'         => 50,
                'stock'              => 0,
                'pivot'              => [],
            ]],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => 0,
            'update_unidades_devueltas' => 0,
            'facturar_nota_credito'     => 0,
        ];
    }

    /**
     * @test
     */
    public function la_nc_directa_con_articulos_aparece_en_el_listado_de_notas_de_credito()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario();

        $this->post('api/devoluciones/', $this->payload_sin_venta($e, 2, 100))->assertStatus(201);

        $nota = CurrentAcount::where('client_id', $e['client']->id)->where('haber', 200)->first();

        $this->assertNotNull($nota, 'La NC no quedó en la cuenta corriente del cliente.');
        $this->assertEquals('nota_credito', $nota->status);

        $respuesta = $this->get('api/nota-credito/from-date/'.date('Y-m-d'));
        $respuesta->assertStatus(200);

        $ids = collect($respuesta->json('models'))->pluck('id')->all();

        $this->assertContains($nota->id, $ids, 'La NC está en la cuenta corriente pero no en el listado de notas de crédito.');
    }

    /**
     * Misma NC pero con deuda previa en la cuenta (venta sin saldar, que la NC imputa) y devolviendo
     * stock: lo que hace una cuenta real.
     *
     * @test
     */
    public function la_nc_directa_con_deuda_previa_y_stock_aparece_en_el_listado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario();

        CurrentAcount::create([
            'detalle'           => 'Venta previa',
            'debe'              => 150,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $e['client']->id,
            'credit_account_id' => $e['credit_account']->id,
            'saldo'             => 150,
        ]);

        $payload = $this->payload_sin_venta($e, 2, 100);
        $payload['regresar_stock'] = 1;
        $payload['address_id'] = 0;

        $this->post('api/devoluciones/', $payload)->assertStatus(201);

        $nota = CurrentAcount::where('client_id', $e['client']->id)->where('haber', 200)->first();
        $this->assertNotNull($nota);

        $respuesta = $this->get('api/nota-credito/from-date/'.date('Y-m-d'));
        $respuesta->assertStatus(200);

        $this->assertContains($nota->id, collect($respuesta->json('models'))->pluck('id')->all());
    }
}
