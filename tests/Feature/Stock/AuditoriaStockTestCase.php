<?php

namespace Tests\Feature\Stock;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Models\Address;
use App\Models\Article;
use App\Models\Client;
use App\Models\ConceptoStockMovement;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

/**
 * Base de los tests de la auditoría de stock (5/9/2026).
 *
 * Todo por diferencia y con artículos PROPIOS (prefijo "zz"), sobre el fixture de
 * TestingFerreteriaSeeder. Los helpers replican la forma exacta con la que Vender manda una
 * venta (misma que 17_Actualizar_venta_stock_y_precio_congelado_Test), para que lo medido sea el
 * camino real y no un atajo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
abstract class AuditoriaStockTestCase extends EmpresaTestCase
{
    /**
     * @return \App\Models\User
     */
    protected function usuario()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();
    }

    /**
     * Artículo propio, con el precio calculado por el camino real del formulario.
     *
     * @param string $nombre
     * @param array $atributos
     * @return \App\Models\Article
     */
    protected function crear_articulo($nombre, $atributos = [])
    {
        $user = $this->usuario();

        $provider = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $article = Article::create(array_merge([
            'name'            => $nombre,
            'user_id'         => $user->id,
            'provider_id'     => !is_null($provider) ? $provider->id : null,
            'cost'            => 100,
            'percentage_gain' => 50,
        ], $atributos));

        $article = Article::find($article->id);

        ArticleHelper::setFinalPrice($article, $user->id);

        return $article->fresh();
    }

    /**
     * Stock del artículo leído de la base, nunca del modelo en memoria.
     *
     * @param \App\Models\Article|int $articulo
     * @return float
     */
    protected function stock($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $stock = DB::table('articles')->where('id', $id)->value('stock');

        return is_null($stock) ? null : (float) $stock;
    }

    /**
     * @param \App\Models\Article|int $articulo
     * @param int $address_id
     * @return float|null  null si no hay fila de depósito.
     */
    protected function stock_en_deposito($articulo, $address_id)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $fila = DB::table('address_article')->where('article_id', $id)->where('address_id', $address_id)->first();

        if (is_null($fila)) {
            return null;
        }

        return is_null($fila->amount) ? null : (float) $fila->amount;
    }

    /**
     * @param \App\Models\Article|int $articulo
     * @return int
     */
    protected function cantidad_de_depositos($articulo)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        return DB::table('address_article')->where('article_id', $id)->count();
    }

    /**
     * Movimientos del artículo en orden, opcionalmente filtrados por concepto.
     *
     * @param \App\Models\Article|int $articulo
     * @param string|null $concepto
     * @return \Illuminate\Support\Collection
     */
    protected function movimientos($articulo, $concepto = null)
    {
        $id = is_object($articulo) ? $articulo->id : $articulo;

        $query = StockMovement::where('article_id', $id)->orderBy('id', 'ASC');

        if (!is_null($concepto)) {
            $query->where('concepto_stock_movement_id', $this->concepto_id($concepto));
        }

        return $query->get();
    }

    /**
     * @param string $nombre
     * @return int
     */
    protected function concepto_id($nombre)
    {
        $concepto = ConceptoStockMovement::where('name', $nombre)->first();

        $this->assertNotNull($concepto, 'El fixture no tiene el concepto de stock "'.$nombre.'".');

        return (int) $concepto->id;
    }

    /**
     * Sucursal principal del fixture.
     *
     * @return \App\Models\Address
     */
    protected function sucursal()
    {
        $address = Address::where('user_id', $this->usuario()->id)->orderBy('id')->first();

        $this->assertNotNull($address, 'El fixture no tiene ninguna sucursal.');

        return $address;
    }

    /**
     * Una segunda sucursal, propia del test.
     *
     * @return \App\Models\Address
     */
    protected function segunda_sucursal()
    {
        return Address::create([
            'street'          => 'zz Deposito auditoria',
            'user_id'         => $this->usuario()->id,
            'default_address' => 0,
        ]);
    }

    /**
     * @return \App\Models\Client
     */
    protected function cliente_cc()
    {
        $cliente = Client::where('name', TestingFerreteriaSeeder::CLIENTE_CC)
            ->where('user_id', $this->usuario()->id)
            ->first();

        $this->assertNotNull($cliente, 'El fixture no tiene el cliente "'.TestingFerreteriaSeeder::CLIENTE_CC.'".');

        return $cliente;
    }

    /**
     * El payload de una venta con la forma que manda Vender.
     *
     * @param array $items  Cada uno: ['article' => Article, 'amount' => n, 'price' => p] más
     *                      claves extra opcionales del renglón (article_variant_id, varios_precios,
     *                      returned_amount...). También acepta renglones ya armados (con 'is_article'
     *                      o 'is_combo').
     * @param array $extra  Claves a pisar (por ejemplo id para el PUT, discount_stock, returned_items).
     * @return array
     */
    protected function payload_venta($items, $extra = [])
    {
        $cliente = $this->cliente_cc();
        $address = $this->sucursal();

        $total = 0;
        $items_payload = [];

        foreach ($items as $item) {

            if (isset($item['is_article']) || isset($item['is_combo'])) {
                $total += (float) $item['price_vender'] * (float) $item['amount'];
                $items_payload[] = $item;
                continue;
            }

            $total += $item['price'] * $item['amount'];

            $renglon = [
                'is_article'   => true,
                'id'           => $item['article']->id,
                'name'         => $item['article']->name,
                'price_vender' => $item['price'],
                'amount'       => $item['amount'],
            ];

            foreach ($item as $clave => $valor) {
                if (!in_array($clave, ['article', 'amount', 'price'])) {
                    $renglon[$clave] = $valor;
                }
            }

            $items_payload[] = $renglon;
        }

        return array_merge([
            'client_id'                        => $cliente->id,
            'address_id'                       => $address->id,
            'save_current_acount'              => 1,
            'omitir_en_cuenta_corriente'       => 0,
            'to_check'                         => 0,
            'checked'                          => 0,
            'confirmed'                        => 0,
            'current_acount_payment_method_id' => 1,
            'discounts_in_services'            => 1,
            'surchages_in_services'            => 1,
            'employee_id'                      => null,
            'sub_total'                        => $total,
            'total'                            => $total,
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
            'items'                            => $items_payload,
        ], $extra);
    }

    /**
     * Crea la venta por el endpoint real y la devuelve.
     *
     * @param array $items
     * @param array $extra
     * @return \App\Models\Sale
     */
    protected function crear_venta($items, $extra = [])
    {
        $payload = $this->payload_venta($items, $extra);

        $this->postJson('api/sale', $payload)->assertStatus(201);

        $venta = Sale::where('client_id', $payload['client_id'])->orderBy('id', 'DESC')->first();

        $this->assertNotNull($venta, 'El POST no dejó ninguna venta.');

        return $venta;
    }

    /**
     * Actualiza la venta por el endpoint real.
     *
     * @param \App\Models\Sale $venta
     * @param array $items
     * @param array $extra
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_venta($venta, $items, $extra = [])
    {
        return $this->putJson('api/sale/'.$venta->id, $this->payload_venta($items, array_merge(['id' => $venta->id], $extra)));
    }
}
