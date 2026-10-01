<?php

namespace Tests\Feature\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Article;
use App\Models\ConceptoStockMovement;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\ProviderOrder;
use App\Models\User;
use Database\Seeders\ConceptoStockMovementNotaCreditoProveedorSeeder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Compras\ComprasTestCase;

/**
 * Base de los tests de la devolución de COMPRA (nota de crédito a proveedor, misión
 * devoluciones-compras-y-rediseno, 1/10/2026): los helpers para crear la compra por el endpoint
 * real, buscarla con el GET nuevo y armar el POST de la devolución con la forma exacta del contrato
 * (§4.1 del plan).
 *
 * Extiende ComprasTestCase por sus helpers de compra (`payload_compra`) y los guards de
 * EmpresaTestCase (base de testing, InnoDB, fixture): con InnoDB verificado,
 * `DatabaseTransactions` revierte todo lo del test.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class NotaCreditoProveedorTestCase extends ComprasTestCase
{
    /**
     * El concepto de stock nuevo se asegura con el seeder STANDALONE (el que se publica en el
     * despliegue), que es idempotente: si la base ya lo tiene, no hace nada.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new ConceptoStockMovementNotaCreditoProveedorSeeder())->run();

        // RRII: la compra suma el IVA por encima del total (es lo que el costo de la devolución
        // tiene que reproducir).
        $this->set_condicion_iva('RRII');
    }

    /**
     * @return \App\Models\User
     */
    protected function usuario()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Artículo propio (prefijo "zz"), de Rosario, con el precio calculado por el camino real.
     * Sin `addresses`: lleva el stock GLOBAL salvo que el test le abra depósitos.
     *
     * @param string $nombre
     * @param array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $atributos = [])
    {
        $user = $this->usuario();

        $iva_21 = DB::table('ivas')->where('percentage', '21')->first();

        $article = Article::create(array_merge([
            'name'            => $nombre,
            'user_id'         => $user->id,
            'provider_id'     => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'cost'            => 100,
            'percentage_gain' => 50,
            'stock'           => 5,
            'iva_id'          => $iva_21->id,
        ], $atributos));

        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * Renglón de compra para un artículo propio (el `item()` de ComprasTestCase resuelve por nombre
     * del fixture; acá el artículo puede ser creado por el test).
     *
     * @param \App\Models\Article $articulo
     * @param float $cost
     * @param float $amount
     * @param array $extra
     * @return array
     */
    protected function renglon_compra($articulo, $cost, $amount, $extra = [])
    {
        return [
            'id'            => $articulo->id,
            'bar_code'      => $articulo->bar_code,
            'provider_code' => $articulo->provider_code,
            'pivot'         => array_merge([
                'cost'            => $cost,
                'amount'          => $amount,
                'received'        => $amount,
                'price'           => null,
                'discount'        => null,
                'iva_id'          => $articulo->iva_id,
                'cost_in_dollars' => 0,
                'update_provider' => 0,
            ], $extra),
        ];
    }

    /**
     * Crea la compra por el endpoint real. Por defecto: Rosario (sin bonificaciones), en pesos,
     * sin depósito (stock global) y a cuenta corriente.
     *
     * @param array $articles
     * @param array $overrides
     * @return \App\Models\ProviderOrder
     */
    protected function crear_compra($articles, $overrides = [])
    {
        $payload = $this->payload_compra(array_merge([
            'provider_id' => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'moneda_id'   => 1,
            'address_id'  => null,
            'articles'    => $articles,
        ], $overrides));

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        return ProviderOrder::find($cuerpo['model']['id']);
    }

    /**
     * La compra tal como la devuelve el GET nuevo, con los artículos indexados por id.
     *
     * @param \App\Models\ProviderOrder $compra
     * @return array{provider_order: array, articles: array}
     */
    protected function buscar_compra($compra)
    {
        $response = $this->getJson('api/devoluciones/search-provider-order/'.$compra->num);

        $response->assertStatus(200);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertNotNull($cuerpo['provider_order'], 'El GET no encontró la compra por su número.');

        $articles = [];

        foreach ($cuerpo['provider_order']['articles'] as $article) {
            $articles[$article['id']] = $article;
        }

        return [
            'provider_order' => $cuerpo['provider_order'],
            'articles'       => $articles,
        ];
    }

    /**
     * Renglón de la devolución, con la forma exacta del contrato (§4.1 del plan).
     *
     * @param \App\Models\Article $articulo
     * @param float $costo
     * @param float $unidades
     * @param array $extra
     * @return array
     */
    protected function item_devolucion($articulo, $costo, $unidades, $extra = [])
    {
        return array_merge([
            'id'                 => $articulo->id,
            'is_article'         => true,
            'name'               => $articulo->name,
            'price_vender'       => $costo,
            'costo_real'         => $costo,
            'discount'           => 0,
            'amount'             => $unidades,
            'returned_amount'    => 0,
            'ya_devueltas'       => 0,
            'unidades_devueltas' => $unidades,
        ], $extra);
    }

    /**
     * Payload de `POST api/devoluciones/` con `tipo = 'compra'`.
     *
     * @param int $provider_id
     * @param \App\Models\ProviderOrder|null $compra
     * @param array $items
     * @param float $total
     * @param array $overrides
     * @return array
     */
    protected function payload_devolucion($provider_id, $compra, $items, $total, $overrides = [])
    {
        return array_merge([
            'tipo'                   => 'compra',
            'provider_id'            => $provider_id,
            'provider_order_id'      => is_null($compra) ? null : $compra->id,
            'items'                  => $items,
            'total_devolucion'       => $total,
            'observaciones'          => null,
            'descriptions'           => [],
            'discounts'              => [],
            'surchages'              => [],
            'regresar_stock'         => 1,
            'address_id'             => null,
            'generar_current_acount' => 0,
        ], $overrides);
    }

    /**
     * @param \App\Models\Article $articulo
     * @return float
     */
    protected function stock($articulo)
    {
        return (float) DB::table('articles')->where('id', $articulo->id)->value('stock');
    }

    /**
     * @return int
     */
    protected function concepto_nc_proveedor()
    {
        return (int) ConceptoStockMovement::where('name', 'Nota de credito proveedor')->value('id');
    }

    /**
     * La NC a proveedor de una compra (la última, si hay varias).
     *
     * @param \App\Models\ProviderOrder $compra
     * @return \App\Models\CurrentAcount|null
     */
    protected function nota_credito_de($compra)
    {
        return CurrentAcount::where('status', 'nota_credito')
                            ->where('devolucion_provider_order_id', $compra->id)
                            ->latest('id')
                            ->first();
    }

    /**
     * @param \App\Models\Provider $proveedor
     * @param int $moneda_id
     * @return \App\Models\CreditAccount
     */
    protected function cuenta_del_proveedor($proveedor, $moneda_id)
    {
        return CreditAccount::where('model_name', 'provider')
                            ->where('model_id', $proveedor->id)
                            ->where('moneda_id', $moneda_id)
                            ->first();
    }
}
