<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Address;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Borrar una compra que ya tuvo una devolución al proveedor (misión
 * devoluciones-compras-y-rediseno, 1/10/2026). Ver ProviderOrderHelper::resetArticlesStock().
 *
 * 🔴 El defecto que estos tests fijan: al borrar la compra se sacaba del stock TODO lo que la
 * compra ingresó, sin mirar que una parte ya había salido con la nota de crédito al proveedor.
 * Compra 10, devolución 4 (stock +6), borrar la compra sacaba 10: el stock quedaba 4 unidades por
 * DEBAJO de donde estaba antes de la compra. Lo que se saca al borrar es lo ingresado menos lo ya
 * devuelto según el libro.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Borrar_compra_con_nota_de_credito_a_proveedor_Test extends NotaCreditoProveedorTestCase
{
    /**
     * Stock global: compra 10, devolución 4, borrar la compra → el stock vuelve al de antes de la
     * compra (no 4 menos).
     *
     * @test
     */
    public function borrar_la_compra_despues_de_una_devolucion_deja_el_stock_de_antes_de_la_compra()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC global', ['stock' => 5]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);

        $this->assertEquals(15, $this->stock($articulo), 'La compra no sumó al stock; el escenario no sirve.');

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484
        ))->assertStatus(201);

        $this->assertEquals(11, $this->stock($articulo), 'La devolución no sacó las 4 unidades; el escenario no sirve.');

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertEquals(5, $this->stock($articulo), 'Borrar la compra tiene que sacar sólo lo que quedó de ella (10 − 4), no las 10.');
    }

    /**
     * Por bulto (`unidades_individuales` = 12): compra 2 bultos, devolución 1, borrar → sale 1
     * bulto (12 unidades), no 2.
     *
     * @test
     */
    public function borrar_la_compra_por_bulto_descuenta_lo_devuelto_en_bultos()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC por bulto', ['stock' => 5, 'unidades_individuales' => 12]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 1200, 2)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 1452, 1)],
            1452
        ))->assertStatus(201);

        $this->assertEquals(17, $this->stock($articulo), 'El escenario por bulto no quedó como se esperaba.');

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertEquals(5, $this->stock($articulo));
    }

    /**
     * Con depósito: lo que queda de la compra sale del depósito de la compra, que vuelve a su valor
     * de antes.
     *
     * @test
     */
    public function borrar_la_compra_con_deposito_deja_el_deposito_como_antes()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC deposito', ['stock' => 0]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)], ['address_id' => $principal->id]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484
        ))->assertStatus(201);

        $deposito = function () use ($articulo, $principal) {
            return (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount');
        };

        $this->assertEquals(6, $deposito(), 'El escenario de depósito no quedó como se esperaba.');

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertEquals(0, $deposito());
        $this->assertEquals(0, $this->stock($articulo));
    }

    /**
     * Sin devolución, el borrado sigue sacando todo lo ingresado (el comportamiento de siempre).
     *
     * @test
     */
    public function borrar_una_compra_sin_devolucion_sigue_sacando_todo()
    {
        $articulo = $this->crear_articulo('zz Borrar compra sin NC', ['stock' => 5]);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertEquals(5, $this->stock($articulo));
    }
}
