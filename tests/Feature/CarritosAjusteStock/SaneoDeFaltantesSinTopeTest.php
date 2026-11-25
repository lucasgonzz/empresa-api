<?php

namespace Tests\Feature\CarritosAjusteStock;

use App\Models\Buyer;
use App\Models\Cart;
use App\Models\Message;
use App\Models\OnlineConfiguration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * Misión `carritos-ajuste-stock`, ronda 2 (R2-1, CA24, lado empresa-api): un renglón con faltante viejo
 * (`amount_insuficiente` > 0) en una tienda donde AHORA no hay tope (por ejemplo `ignorar_stock` prendido,
 * caso Únicas) se sanea en silencio cuando se mueve el stock del artículo: sin mail, sin Message y sin tocar
 * `carts.updated_at`.
 *
 * - Casilla `completar_al_reponer` = 1 (o columna ausente): la cantidad vuelve a lo pedido (amount + faltante)
 *   y el faltante queda en 0.
 * - Casilla = 0: el faltante se descarta (queda en 0) y la cantidad no cambia.
 *
 * Falla hoy porque `CartStockAdjustmentHelper` devuelve "sin cambio" cuando el tope es null y el renglón
 * queda 0/2 (aserción de cantidad y faltante del renglón).
 *
 * IMPORTANTE: PHP 7.4 estricto.
 *
 * @group tienda
 */
class SaneoDeFaltantesSinTopeTest extends AuditoriaStockTestCase
{
    /** Clase del job de mail, como texto para interceptarla con Bus::fake. */
    const JOB_DE_MAIL = 'App\Jobs\ProcessSendCartAdjustedMail';

    /**
     * Intercepta sólo el job de mail del ajuste.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([self::JOB_DE_MAIL]);
    }

    /**
     * Arma la tienda con `ignorar_stock` prendido y un carrito activo con un renglón 0/2 con la casilla dada.
     *
     * @param int $casilla Valor de completar_al_reponer (0 o 1).
     * @param array $configuracion Flags de la tienda (por defecto ignorar_stock = 1).
     * @param float $stock Stock inicial del artículo.
     * @param float $faltante Faltante del renglón.
     * @return array ['articulo', 'line_id', 'cart_id', 'cart_updated_at']
     */
    protected function armar_escenario($casilla, $configuracion = ['ignorar_stock' => 1], $stock = 10, $faltante = 2)
    {
        $user = $this->usuario();

        OnlineConfiguration::where('user_id', $user->id)->delete();
        OnlineConfiguration::create(array_merge(['user_id' => $user->id], $configuracion));

        $articulo = $this->crear_articulo('zz Saneo sin tope ' . uniqid(), ['stock' => $stock]);

        $buyer = Buyer::create([
            'name'    => 'Comprador ' . uniqid(),
            'phone'   => '2216000000',
            'email'   => 'comprador-' . uniqid() . '@ejemplo.test',
            'user_id' => $user->id,
        ]);

        $cart = Cart::create(['buyer_id' => $buyer->id, 'user_id' => $user->id]);

        // Carrito tocado hace 3 días (activo): el saneo no debe moverle updated_at.
        $viejo = now()->subDays(3)->startOfSecond();
        DB::table('carts')->where('id', $cart->id)->update(['updated_at' => $viejo]);

        $fila = [
            'cart_id'             => $cart->id,
            'article_id'          => $articulo->id,
            'variant_id'          => null,
            'amount'              => 0,
            'amount_insuficiente' => $faltante,
            'price'               => 100,
            'cost'                => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ];

        if (Schema::hasColumn('article_cart', 'completar_al_reponer')) {
            $fila['completar_al_reponer'] = $casilla;
        }

        return [
            'articulo'        => $articulo,
            'line_id'         => DB::table('article_cart')->insertGetId($fila),
            'cart_id'         => $cart->id,
            'cart_updated_at' => $viejo,
        ];
    }

    /**
     * Movimiento de stock real por el endpoint.
     *
     * @param \App\Models\Article $articulo
     * @param float $cantidad Positiva ingresa, negativa egresa.
     * @param string $concepto Concepto de stock.
     * @return void
     */
    protected function mover_stock($articulo, $cantidad = 1, $concepto = 'Ingreso manual')
    {
        $this->postJson('api/stock-movement', [
            'model_id'                     => $articulo->id,
            'amount'                       => $cantidad,
            'concepto_stock_movement_name' => $concepto,
        ])->assertStatus(201);
    }

    /**
     * CA24 casilla marcada: el renglón 0/2 vuelve a 2/0, sin mail, sin Message y sin tocar el carrito.
     *
     * @test
     */
    public function test_sin_tope_con_casilla_marcada_el_faltante_viejo_vuelve_al_renglon()
    {
        $e = $this->armar_escenario(1);
        $mensajes_antes = Message::where('type', 'cart_amount_updated')->count();

        $this->mover_stock($e['articulo']);

        $linea = DB::table('article_cart')->where('id', $e['line_id'])->first();
        $this->assertEquals(2.0, (float) $linea->amount, 'La cantidad vuelve a lo pedido.');
        $this->assertEquals(0.0, (float) $linea->amount_insuficiente, 'El faltante queda en 0.');

        Bus::assertNotDispatched(self::JOB_DE_MAIL);
        $this->assertEquals($mensajes_antes, Message::where('type', 'cart_amount_updated')->count(), 'Sin Message.');

        $cart = DB::table('carts')->where('id', $e['cart_id'])->first();
        $this->assertEquals($e['cart_updated_at']->toDateTimeString(), (string) $cart->updated_at, 'No toca carts.updated_at.');
    }

    /**
     * CA24 casilla desmarcada: el faltante se descarta (0/0) sin mail ni Message.
     *
     * @test
     */
    public function test_sin_tope_con_casilla_desmarcada_el_faltante_viejo_se_descarta()
    {
        $e = $this->armar_escenario(0);
        $mensajes_antes = Message::where('type', 'cart_amount_updated')->count();

        $this->mover_stock($e['articulo']);

        $linea = DB::table('article_cart')->where('id', $e['line_id'])->first();
        $this->assertEquals(0.0, (float) $linea->amount, 'La cantidad no cambia.');
        $this->assertEquals(0.0, (float) $linea->amount_insuficiente, 'El faltante se descarta.');

        Bus::assertNotDispatched(self::JOB_DE_MAIL);
        $this->assertEquals($mensajes_antes, Message::where('type', 'cart_amount_updated')->count(), 'Sin Message.');

        $cart = DB::table('carts')->where('id', $e['cart_id'])->first();
        $this->assertEquals($e['cart_updated_at']->toDateTimeString(), (string) $cart->updated_at, 'No toca carts.updated_at.');
    }

    /**
     * Excepción del saneo (alineada con tienda-api, CA17): con consultas activas
     * (`permitir_pedidos_sin_stock` sin `ignorar_stock`) y stock exactamente 0, un renglón 0/5 con la casilla
     * marcada SIGUE esperando stock: no se sanea, aunque el tope de la tienda sea null en ese caso.
     *
     * Falla hoy porque el saneo lo deja en 5/0 (aserción de cantidad y faltante del renglón).
     *
     * @test
     */
    public function test_con_consultas_activas_y_stock_cero_el_renglon_sigue_esperando_stock()
    {
        $e = $this->armar_escenario(1, ['ignorar_stock' => 0, 'permitir_pedidos_sin_stock' => 1], 1, 5);

        // Una venta de 1 deja el stock en exactamente 0.
        $this->mover_stock($e['articulo'], -1, 'Venta');

        $linea = DB::table('article_cart')->where('id', $e['line_id'])->first();
        $this->assertEquals(0.0, (float) $linea->amount, 'El renglón sigue sin unidades.');
        $this->assertEquals(5.0, (float) $linea->amount_insuficiente, 'El faltante se conserva.');

        Bus::assertNotDispatched(self::JOB_DE_MAIL);
    }
}
