<?php

namespace Tests\Feature\ListasSiempre;

use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Http\Controllers\Helpers\article\ArticlePricesHelper;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Stock\AuditoriaStockTestCase;

/**
 * D4/D5 de la misión prueba-listas-siempre, del lado de la API: un negocio con UNA sola lista (la
 * «Precio final») vende por `POST api/sale` con esa lista y el renglón queda al precio final de
 * siempre. La SPA elige sola la única lista y no la pide; y si igual llega sin lista (pestaña vieja, venta
 * sin conexión, catálogo caído), la API toma esa misma lista y cobra lo mismo (10_Comprobante_sin_lista...).
 *
 * Sobre el dueño 500 del fixture (tiene el cliente, la sucursal y los medios de pago que la venta
 * necesita); sus cuatro listas se sacan dentro de la transacción del test para dejarlo con una sola.
 */
class Vender_con_una_sola_lista_Test extends AuditoriaStockTestCase
{
    /** @var int|null */
    protected $listas_original = null;

    protected function setUp(): void
    {
        parent::setUp();

        PriceTypeHelper::olvidar_precio_final();

        $this->listas_original = $this->usuario()->listas_de_precio;
    }

    protected function tearDown(): void
    {
        DB::table('users')->where('id', $this->usuario()->id)->update(array('listas_de_precio' => $this->listas_original));

        PriceTypeHelper::olvidar_precio_final();

        parent::tearDown();
    }

    /**
     * Deja al dueño del fixture con una sola lista, la «Precio final», y el flag prendido.
     *
     * @return \App\Models\PriceType
     */
    protected function una_sola_lista()
    {
        $user = $this->usuario();

        // Sin observers: dentro de la transacción, y las listas del fixture no tienen que disparar nada.
        DB::table('article_price_type')->whereIn('price_type_id', DB::table('price_types')->where('user_id', $user->id)->pluck('id'))->delete();
        DB::table('price_types')->where('user_id', $user->id)->delete();

        $lista = PriceTypeHelper::crear_lista_precio_final($user->id);

        DB::table('users')->where('id', $user->id)->update(array('listas_de_precio' => 1));

        PriceTypeHelper::olvidar_precio_final($user->id);

        return $lista;
    }

    /** @test */
    public function la_venta_sale_con_la_unica_lista_y_al_precio_final_de_siempre()
    {
        $lista = $this->una_sola_lista();

        $articulo = $this->crear_articulo('zz Vendido con una sola lista');

        $final_price = (float) DB::table('articles')->where('id', $articulo->id)->value('final_price');

        $this->assertGreaterThan(100, $final_price);

        $pivote = DB::table('article_price_type')->where('article_id', $articulo->id)->where('price_type_id', $lista->id)->first();
        $this->assertNotNull($pivote, 'El artículo nuevo tiene su pivote de «Precio final».');
        $this->assertEqualsWithDelta($final_price, (float) $pivote->final_price, 0.005);

        $resuelto = ArticlePricesHelper::resolver_precio_de_venta(Article::find($articulo->id), User::find($this->usuario()->id));
        $this->assertSame((int) $lista->id, (int) $resuelto['price_type_id']);
        $this->assertEqualsWithDelta($final_price, (float) $resuelto['final_price'], 0.005);

        $this->actingAs($this->usuario(), 'web');

        $venta = $this->crear_venta(array(
            array('article' => $articulo, 'amount' => 2, 'price' => $final_price),
        ), array('price_type_id' => $lista->id));

        $this->assertSame((int) $lista->id, (int) $venta->price_type_id);

        $renglon = DB::table('article_sale')->where('sale_id', $venta->id)->where('article_id', $articulo->id)->first();
        $this->assertNotNull($renglon);
        $this->assertEqualsWithDelta($final_price, (float) $renglon->price, 0.005, 'El renglón se cobra al precio final de siempre.');
    }

    /** @test */
    public function sin_lista_la_api_toma_la_unica_lista_y_cobra_lo_mismo()
    {
        /*
         * Hasta el 4/11/2026 este test fijaba un 422. Se invirtió por pedido explícito (misión
         * vender-tras-migrar-precio-final): probando un negocio migrado en navegador real, ese 422
         * rechazaba la pestaña abierta durante la migración y la venta guardada sin conexión.
         */
        $lista = $this->una_sola_lista();

        $articulo = $this->crear_articulo('zz Sin lista con una sola lista');

        $this->actingAs($this->usuario(), 'web');

        $respuesta = $this->postJson('api/sale', $this->payload_venta(array(
            array('article' => $articulo, 'amount' => 1, 'price' => 150),
        ), array('price_type_id' => null)));

        $respuesta->assertStatus(201);
        $this->assertSame((int) $lista->id, (int) DB::table('sales')->where('id', $respuesta->json('model.id'))->value('price_type_id'));
        $this->assertEqualsWithDelta(150, (float) DB::table('article_sale')->where('sale_id', $respuesta->json('model.id'))->where('article_id', $articulo->id)->value('price'), 0.005);
    }
}
