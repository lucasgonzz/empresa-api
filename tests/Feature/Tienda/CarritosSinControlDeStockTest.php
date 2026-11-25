<?php

namespace Tests\Feature\Tienda;

use App\Http\Controllers\Helpers\CartArticleAmountInsificienteHelper;
use App\Models\Buyer;
use App\Models\Cart;
use App\Models\Message;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión `unicas-carritos-sin-control-stock` (25/11/2026).
 *
 * El chequeo de carritos (`CartArticleAmountInsificienteHelper::checkCartsAmounts`) recortaba los
 * renglones de los carritos de la tienda (y avisaba al comprador con un Message
 * `cart_amount_updated`) aunque la tienda del comercio no ponga tope de cantidad. Caso Únicas:
 * `ignorar_stock` prendido y cientos de artículos con stock negativo; cada venta del sistema
 * recortaba carritos de compradores que sí podían comprar.
 *
 * El criterio de "la tienda no pone tope" es el de tienda-api (`ConsultaStockHelper::clasificar`):
 *  1. `ignorar_stock` prendido: ningún renglón tiene tope.
 *  2. stock null y `stock_null_equal_0` apagado: sin tope.
 *  3. Modo Consultar (`permitir_pedidos_sin_stock` prendido, `ignorar_stock` apagado) con stock
 *     exactamente 0: sin tope (con stock negativo sí hay tope).
 *  4. Sin fila de online_configurations: se conserva el comportamiento de siempre (recorte).
 *
 * CA1 y CA2 pasan por el camino real: venta por `api/sale` -> SetArticleStock -> CheckCartAmount ->
 * helper. CA3 a CA5 llaman directo al helper.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promoción de constructor, readonly, enum ni #[...].
 *
 * @group tienda
 */
class CarritosSinControlDeStockTest extends AuditoriaStockTestCase
{
    /**
     * Deja la configuración online del dueño con los flags indicados (la crea si no existe).
     * `ignorar_stock` y `permitir_pedidos_sin_stock` sólo se escriben si la columna existe.
     *
     * @param array $flags  Claves: ignorar_stock, permitir_pedidos_sin_stock, stock_null_equal_0.
     * @return void
     */
    protected function configurar_tienda($flags)
    {
        /** Dueño del fixture. */
        $user = $this->usuario();

        /** Valores por defecto: tienda con control de stock. */
        $valores = array_merge([
            'ignorar_stock'              => 0,
            'permitir_pedidos_sin_stock' => 0,
            'stock_null_equal_0'         => 0,
        ], $flags);

        OnlineConfiguration::where('user_id', $user->id)->delete();

        OnlineConfiguration::create(array_merge(['user_id' => $user->id], $valores));
    }

    /**
     * Arma un comprador con un carrito de la tienda que tiene un renglón del artículo.
     *
     * @param \App\Models\Article $articulo
     * @param float $cantidad
     * @return array  [buyer_id, article_cart_id]
     */
    protected function armar_carrito($articulo, $cantidad)
    {
        /** Comprador de la tienda del dueño. */
        $buyer = Buyer::create([
            'name'    => 'Comprador carrito sin control',
            'phone'   => '2216000000',
            'email'   => 'carrito-sin-control@testing.local',
            'user_id' => $this->usuario()->id,
        ]);

        /** Carrito del comprador. */
        $cart = Cart::create(['buyer_id' => $buyer->id, 'user_id' => $this->usuario()->id]);

        /** Renglón del carrito. */
        $article_cart_id = DB::table('article_cart')->insertGetId([
            'cart_id'             => $cart->id,
            'article_id'          => $articulo->id,
            'amount'              => $cantidad,
            'amount_insuficiente' => 0,
            'price'               => 100,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return [$buyer->id, $article_cart_id];
    }

    /**
     * Renglón del carrito leído de la base.
     *
     * @param int $article_cart_id
     * @return object
     */
    protected function renglon($article_cart_id)
    {
        return DB::table('article_cart')->where('id', $article_cart_id)->first();
    }

    /**
     * Cantidad de avisos de carrito actualizado del comprador.
     *
     * @param int $buyer_id
     * @return int
     */
    protected function avisos($buyer_id)
    {
        return Message::where('buyer_id', $buyer_id)->where('type', 'cart_amount_updated')->count();
    }

    /**
     * CA1 (caso Únicas): con ignorar_stock prendido, una venta que deja el stock en negativo no
     * recorta el carrito ni avisa al comprador.
     *
     * @test
     */
    public function test_ignorar_stock_no_recorta_el_carrito_ni_avisa()
    {
        $this->configurar_tienda(['ignorar_stock' => 1]);

        $articulo = $this->crear_articulo('zz Carrito sin control ignorar stock', ['stock' => -200]);

        list($buyer_id, $article_cart_id) = $this->armar_carrito($articulo, 5);

        $this->crear_venta([
            ['article' => $articulo, 'amount' => 1, 'price' => 300],
        ]);

        $this->assertEquals(-201.0, $this->stock($articulo), 'La venta debía mover el stock del artículo.');

        $renglon = $this->renglon($article_cart_id);

        $this->assertEquals(5.0, (float) $renglon->amount);
        $this->assertEquals(0.0, (float) $renglon->amount_insuficiente);
        $this->assertEquals(0, $this->avisos($buyer_id));
    }

    /**
     * CA2 (sin regresión): con control de stock el carrito se recorta al stock y se avisa.
     *
     * @test
     */
    public function test_con_control_de_stock_el_carrito_se_recorta_y_avisa()
    {
        $this->configurar_tienda(['ignorar_stock' => 0, 'permitir_pedidos_sin_stock' => 0]);

        $articulo = $this->crear_articulo('zz Carrito con control de stock', ['stock' => 3]);

        list($buyer_id, $article_cart_id) = $this->armar_carrito($articulo, 5);

        $this->crear_venta([
            ['article' => $articulo, 'amount' => 1, 'price' => 300],
        ]);

        $this->assertEquals(2.0, $this->stock($articulo), 'La venta debía dejar el stock en 2.');

        $renglon = $this->renglon($article_cart_id);

        $this->assertEquals(2.0, (float) $renglon->amount);
        $this->assertEquals(3.0, (float) $renglon->amount_insuficiente);
        $this->assertEquals(1, $this->avisos($buyer_id));
    }

    /**
     * CA3: stock null sin stock_null_equal_0 es "sin tope": el carrito no cambia ni avisa.
     *
     * @test
     */
    public function test_stock_null_sin_stock_null_equal_0_no_recorta()
    {
        $this->configurar_tienda(['ignorar_stock' => 0, 'stock_null_equal_0' => 0]);

        $articulo = $this->crear_articulo('zz Carrito stock null', ['stock' => null]);

        list($buyer_id, $article_cart_id) = $this->armar_carrito($articulo, 5);

        CartArticleAmountInsificienteHelper::checkCartsAmounts($articulo->fresh());

        $renglon = $this->renglon($article_cart_id);

        $this->assertEquals(5.0, (float) $renglon->amount);
        $this->assertEquals(0.0, (float) $renglon->amount_insuficiente);
        $this->assertEquals(0, $this->avisos($buyer_id));
    }

    /**
     * CA4: modo Consultar con stock exactamente 0: el carrito no cambia ni avisa.
     *
     * @test
     */
    public function test_modo_consultar_con_stock_cero_no_recorta()
    {
        $this->configurar_tienda(['ignorar_stock' => 0, 'permitir_pedidos_sin_stock' => 1]);

        $articulo = $this->crear_articulo('zz Carrito modo consultar', ['stock' => 0]);

        list($buyer_id, $article_cart_id) = $this->armar_carrito($articulo, 5);

        CartArticleAmountInsificienteHelper::checkCartsAmounts($articulo->fresh());

        $renglon = $this->renglon($article_cart_id);

        $this->assertEquals(5.0, (float) $renglon->amount);
        $this->assertEquals(0.0, (float) $renglon->amount_insuficiente);
        $this->assertEquals(0, $this->avisos($buyer_id));
    }

    /**
     * CA5: sin fila de online_configurations para el dueño se conserva el recorte de siempre.
     *
     * @test
     */
    public function test_sin_configuracion_online_recorta_como_antes()
    {
        OnlineConfiguration::where('user_id', $this->usuario()->id)->delete();

        $articulo = $this->crear_articulo('zz Carrito sin configuracion online', ['stock' => 2]);

        list($buyer_id, $article_cart_id) = $this->armar_carrito($articulo, 5);

        CartArticleAmountInsificienteHelper::checkCartsAmounts($articulo->fresh());

        $renglon = $this->renglon($article_cart_id);

        $this->assertEquals(2.0, (float) $renglon->amount);
        $this->assertEquals(3.0, (float) $renglon->amount_insuficiente);
        $this->assertEquals(1, $this->avisos($buyer_id));
    }
}
