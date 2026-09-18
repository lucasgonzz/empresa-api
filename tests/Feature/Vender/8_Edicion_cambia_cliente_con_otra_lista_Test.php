<?php

namespace Tests\Feature\Vender;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Models\Article;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\PriceType;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión vender-lista-obligatoria, tanda 2 (18/9/2026): editar una venta cambiándole el cliente
 * por otro que tiene OTRA lista de precios.
 *
 * QUÉ FIJA Y POR QUÉ. En el alta, la lista del cliente es el único default que el back aplica
 * (`PriceTypeHelper::resolver_price_type_id_para_guardar()`): si el request no trae lista y el
 * cliente tiene una, la venta nace con la del cliente. En la EDICIÓN esa regla no existe, y no
 * tiene que existir: `SaleController::update()` toca `sales.price_type_id` SOLO si el PUT trae la
 * clave, y nunca la deduce del cliente nuevo. El motivo es el mismo de siempre —el back no
 * re-precia: `attachArticle()` persiste el `price_vender` que vino—, así que cambiarle la lista a
 * una venta "porque el cliente nuevo tiene otra" diría que los renglones se cobraron con precios
 * que nadie aplicó. Tres casos:
 *
 *  - PUT con el cliente B y `price_type_id` de B (la SPA nueva, que al cambiar el cliente
 *    resuelve la lista y la manda): la venta queda con B y con la lista de B, y los renglones que
 *    ya estaban CONSERVAN su `price` (el que el front restauró del pivote), aunque en la lista de
 *    B el artículo valga otra cosa;
 *  - PUT con el cliente B y SIN la clave (la SPA anterior): la venta queda con B y con la lista
 *    de A, la que tenía. No se cambia sola;
 *  - PUT con el cliente B y `price_type_id` null en una cuenta con listas: 422 y la venta queda
 *    EXACTAMENTE como estaba (cliente A, lista A, mismo renglón). No hay rescate del cliente en
 *    la edición.
 *
 * DatabaseTransactions (no RefreshDatabase): la base del slot está sembrada de antes. Listas,
 * artículo y clientes se crean adentro de la transacción con prefijo `zz`, y
 * `users.listas_de_precio` se restaura en tearDown. La venta se arma directo en la base (molde de
 * tests/Feature/Vender/4 y Sales/9) porque lo que se mide es `update()`, no el alta; va sin
 * métodos de pago adjuntos porque el usuario 500 tiene cajas y una venta ya cobrada no se puede
 * editar (`SaleHelper::motivo_por_el_que_no_se_puede_editar()`).
 *
 * PHP 7.4: sin match, str_contains, ?->, argumentos nombrados ni union types.
 */
class Edicion_cambia_cliente_con_otra_lista_Test extends TestCase
{
    use DatabaseTransactions;

    /** @var int Usuario del fixture de testing (TestingFerreteriaSeeder). */
    const USER_ID = 500;

    /** @var int Efectivo, del catálogo global. El PUT de una venta de contado lo manda (tanda 2, A7). */
    const METODO_EFECTIVO = 3;

    /** @var float Tolerancia de un centavo. */
    const DELTA = 0.01;

    /** @var int `articles.final_price`: el precio base. */
    const PRECIO_BASE = 100;

    /** @var int Precio del artículo en la lista A: con este se cobró el renglón. */
    const PRECIO_LISTA_A = 130;

    /** @var int Precio del artículo en la lista B: distinto, para ver que el back no re-precia. */
    const PRECIO_LISTA_B = 170;

    /** @var int Cantidad del renglón. */
    const CANTIDAD = 2;

    /** @var \App\Models\User */
    protected $user;

    /** @var int|null Valor original de users.listas_de_precio, para dejarlo como estaba. */
    protected $listas_original = null;

    /** @var \App\Models\PriceType La lista del cliente A. */
    protected $lista_a;

    /** @var \App\Models\PriceType La lista del cliente B. */
    protected $lista_b;

    /** @var \App\Models\Article */
    protected $article;

    /** @var \App\Models\Client Cliente con la lista A. */
    protected $cliente_a;

    /** @var \App\Models\Client Cliente con la lista B. */
    protected $cliente_b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::find(self::USER_ID);

        if (is_null($this->user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->listas_original = $this->user->listas_de_precio;

        $this->actingAs($this->user, 'web');

        $this->lista_a = PriceType::create([
            'name'     => 'zz Lista A (edicion cambia cliente)',
            'user_id'  => self::USER_ID,
            'position' => 3,
        ]);

        $this->lista_b = PriceType::create([
            'name'     => 'zz Lista B (edicion cambia cliente)',
            'user_id'  => self::USER_ID,
            'position' => 4,
        ]);

        /* Sin stock a propósito: acá se mide la lista y el precio del renglón, no el stock. */
        $this->article = Article::create([
            'name'        => 'zz Articulo edicion cambia cliente',
            'user_id'     => self::USER_ID,
            'final_price' => self::PRECIO_BASE,
            'status'      => 'active',
        ]);

        $this->article->price_types()->attach($this->lista_a->id, ['final_price' => self::PRECIO_LISTA_A]);
        $this->article->price_types()->attach($this->lista_b->id, ['final_price' => self::PRECIO_LISTA_B]);

        $this->cliente_a = $this->cliente('A', $this->lista_a->id);
        $this->cliente_b = $this->cliente('B', $this->lista_b->id);
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
     * @param  int  $usa  1 o 0
     * @return void
     */
    protected function cuenta_con_listas($usa)
    {
        User::where('id', self::USER_ID)->update(['listas_de_precio' => $usa]);
    }

    /**
     * Cliente propio del test con su lista y su cuenta en pesos (el fixture no trae
     * `credit_accounts` de clientes y `CurrentAcountFromSaleHelper` la usa sin chequear null;
     * mismo apaño que tests/Feature/Vender/4).
     *
     * @param  string  $letra
     * @param  int     $price_type_id
     * @return \App\Models\Client
     */
    protected function cliente($letra, $price_type_id)
    {
        $client = Client::create([
            'name'          => 'zz Cliente '.$letra.' edicion cambia cliente '.uniqid(),
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
     * La venta que se edita: del cliente A, con la lista A, pagada en el acto (omitida de la
     * cuenta corriente, como el caso de mostrador de LimiteCredito/1), con el renglón cobrado a
     * precio de la lista A.
     *
     * @return \App\Models\Sale
     */
    protected function venta_del_cliente_a()
    {
        $total = self::PRECIO_LISTA_A * self::CANTIDAD;

        $sale = Sale::create([
            'user_id'                    => self::USER_ID,
            'client_id'                  => $this->cliente_a->id,
            'price_type_id'              => $this->lista_a->id,
            'omitir_en_cuenta_corriente' => 1,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'discount_stock'             => 0,
            'moneda_id'                  => 1,
            'sub_total'                  => $total,
            'total'                      => $total,
        ]);

        $sale->articles()->attach($this->article->id, [
            'amount' => self::CANTIDAD,
            'price'  => self::PRECIO_LISTA_A,
            'cost'   => 0,
        ]);

        return $sale;
    }

    /**
     * Payload de PUT api/sale/{id} que cambia el cliente a B, calcado de tests/Feature/Vender/4.
     * El renglón viaja con el `price_vender` que la SPA restaura del pivote al editar (el precio
     * de la lista A), no con el precio de la lista B: es lo que manda Vender cuando se cambia el
     * cliente sin volver a preciar. SIN `price_type_id` a propósito: cada test decide si viaja.
     *
     * @param  array  $overrides
     * @return array
     */
    protected function payload_update_con_cliente_b($overrides = [])
    {
        $total = self::PRECIO_LISTA_A * self::CANTIDAD;

        return array_merge([
            'client_id'                         => $this->cliente_b->id,
            'save_current_acount'               => 0,
            'omitir_en_cuenta_corriente'        => 1,
            'to_check'                          => 0,
            'checked'                           => 0,
            'confirmed'                         => 0,
            'current_acount_payment_method_id'  => self::METODO_EFECTIVO,
            'discounts_in_services'             => 1,
            'surchages_in_services'             => 1,
            'discount_stock'                    => 0,
            'sub_total'                         => $total,
            'total'                             => $total,
            'moneda_id'                         => 1,
            'seller_id'                         => null,
            'items'                             => [
                [
                    'is_article'   => true,
                    'id'           => $this->article->id,
                    'name'         => $this->article->name,
                    'price_vender' => self::PRECIO_LISTA_A,
                    'amount'       => self::CANTIDAD,
                ],
            ],
            'discounts'                         => [],
            'surchages'                         => [],
            'returned_items'                    => [],
        ], $overrides);
    }

    /**
     * @param  int  $sale_id
     * @return object|null
     */
    protected function renglon($sale_id)
    {
        return DB::table('article_sale')->where('sale_id', $sale_id)->first();
    }

    /**
     * SPA nueva: cambia el cliente y manda la lista del cliente nuevo. La venta queda con B y con
     * la lista de B, y el renglón que ya estaba conserva el precio con el que se cobró (130), no
     * el que el artículo tiene en la lista de B (170): el back no re-precia.
     *
     * @group vender
     * @test
     */
    public function put_con_cliente_nuevo_y_su_lista_cambia_la_lista_y_los_renglones_conservan_el_precio()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_del_cliente_a();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update_con_cliente_b([
            'price_type_id' => $this->lista_b->id,
        ]));

        $response->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertEquals($this->cliente_b->id, (int) $despues->client_id, 'La venta pasa al cliente B.');
        $this->assertEquals($this->lista_b->id, (int) $despues->price_type_id, 'Con la clave en el PUT, la lista pasa a la de B.');

        $renglon = $this->renglon($venta->id);

        $this->assertNotNull($renglon, 'El renglón tiene que seguir en la venta.');
        $this->assertEqualsWithDelta(
            self::PRECIO_LISTA_A,
            (float) $renglon->price,
            self::DELTA,
            'El renglón conserva el precio con el que se cobró: el back no lo re-precia con la lista nueva.'
        );
        $this->assertEquals(self::CANTIDAD, (int) $renglon->amount);
    }

    /**
     * 🔴 SPA anterior: cambia el cliente y NO manda `price_type_id`. La venta pasa a B pero la
     * lista sigue siendo la de A: en la edición no hay rescate del cliente, y la lista no se
     * cambia sola porque el cliente nuevo tenga otra.
     *
     * @group vender
     * @test
     */
    public function put_con_cliente_nuevo_sin_price_type_id_preserva_la_lista_guardada()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_del_cliente_a();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update_con_cliente_b());

        $response->assertStatus(200);

        $despues = Sale::find($venta->id);

        $this->assertEquals($this->cliente_b->id, (int) $despues->client_id, 'La venta pasa al cliente B.');

        $this->assertNotNull($despues->price_type_id, 'El PUT sin la clave dejó la lista en null: la pisó.');
        $this->assertEquals(
            $this->lista_a->id,
            (int) $despues->price_type_id,
            'Sin la clave, la lista guardada se preserva: no se cambia sola por el cliente nuevo.'
        );
        $this->assertNotEquals($this->lista_b->id, (int) $despues->price_type_id, 'La lista del cliente nuevo NO se aplica en la edición.');

        $this->assertEqualsWithDelta(self::PRECIO_LISTA_A, (float) $this->renglon($venta->id)->price, self::DELTA);
    }

    /**
     * Cliente nuevo con lista y `price_type_id` null explícito en una cuenta con listas: 422 y
     * la venta queda EXACTAMENTE como estaba —cliente A, lista A, mismo renglón, mismo total—. Que
     * el cliente B tenga lista no la rescata: el rescate es del alta, no de la edición, y el
     * rechazo va antes de la transacción.
     *
     * @group vender
     * @test
     */
    public function put_con_cliente_nuevo_y_lista_null_responde_422_y_la_venta_queda_como_estaba()
    {
        $this->cuenta_con_listas(1);

        $venta = $this->venta_del_cliente_a();

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_update_con_cliente_b([
            'price_type_id' => null,
            'total'         => 999,
            'sub_total'     => 999,
        ]));

        $response->assertStatus(422);
        $this->assertTrue((bool) $response->json('sin_lista_de_precios'));
        $this->assertEquals(PriceTypeHelper::mensaje_sin_lista(), $response->json('message'));

        $despues = Sale::find($venta->id);

        $this->assertEquals($this->cliente_a->id, (int) $despues->client_id, 'El cliente no se tiene que haber tocado.');
        $this->assertEquals($this->lista_a->id, (int) $despues->price_type_id, 'La lista no se tiene que haber tocado.');
        $this->assertEqualsWithDelta(self::PRECIO_LISTA_A * self::CANTIDAD, (float) $despues->total, self::DELTA, 'El total no se tiene que haber tocado.');

        $renglon = $this->renglon($venta->id);

        $this->assertNotNull($renglon, 'El renglón no se tiene que haber tocado.');
        $this->assertEqualsWithDelta(self::PRECIO_LISTA_A, (float) $renglon->price, self::DELTA);
    }
}
