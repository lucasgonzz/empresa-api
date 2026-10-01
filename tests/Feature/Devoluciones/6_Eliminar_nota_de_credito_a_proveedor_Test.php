<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Address;
use App\Models\StockMovement;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Eliminar una nota de crédito a proveedor desde la cuenta corriente del proveedor (misión
 * devoluciones-compras-y-rediseno, 1/10/2026). Ver NotaCreditoProveedorHelper::deshacer_stock().
 *
 * 🔴 El defecto: `DELETE current-acount/provider/{id}` borraba la NC (el haber) pero dejaba el
 * stock como si la mercadería se hubiera devuelto, y el tope seguía contando esas unidades como
 * devueltas. Ahora la baja vuelve a meter en el stock exactamente lo que la NC había sacado, y el
 * tope se libera.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Eliminar_nota_de_credito_a_proveedor_Test extends NotaCreditoProveedorTestCase
{
    /**
     * Compra 10, NC 4 (a C/C), borrar la NC: el stock vuelve, lo ya devuelto vuelve a 0 y se
     * pueden devolver las 10.
     *
     * @test
     */
    public function borrar_la_nc_devuelve_el_stock_y_libera_el_tope()
    {
        $articulo = $this->crear_articulo('zz Eliminar NC proveedor global', ['stock' => 5]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $this->assertEquals(11, $this->stock($articulo), 'La NC no sacó las 4; el escenario no sirve.');

        $nota_credito = $this->nota_credito_de($compra);

        $this->deleteJson('api/current-acount/provider/'.$nota_credito->id)->assertStatus(200);

        $this->assertEquals(15, $this->stock($articulo), 'Borrar la NC tiene que volver a meter las 4 unidades.');

        $reverso = StockMovement::where('nota_credito_id', $nota_credito->id)
                                ->where('concepto_stock_movement_id', $this->concepto_nc_proveedor())
                                ->where('amount', '>', 0)
                                ->first();

        $this->assertNotNull($reverso, 'El reverso tiene que quedar en el libro con el concepto de la NC a proveedor.');
        $this->assertEquals(4, (float) $reverso->amount);
        $this->assertEquals($compra->id, $reverso->provider_order_id);

        $this->assertEquals(0, $this->buscar_compra($compra)['articles'][$articulo->id]['ya_devueltas']);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 10)],
            1210
        ))->assertStatus(201);

        $this->assertEquals(5, $this->stock($articulo));
    }

    /**
     * Por bulto: el movimiento original ya quedó en UNIDADES (12 por bulto); el reverso no se
     * vuelve a multiplicar.
     *
     * @test
     */
    public function borrar_la_nc_de_un_articulo_por_bulto_no_vuelve_a_multiplicar()
    {
        $articulo = $this->crear_articulo('zz Eliminar NC proveedor bulto', ['stock' => 5, 'unidades_individuales' => 12]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 1200, 2)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 1452, 1)],
            1452,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $this->assertEquals(17, $this->stock($articulo));

        $this->deleteJson('api/current-acount/provider/'.$this->nota_credito_de($compra)->id)->assertStatus(200);

        $this->assertEquals(29, $this->stock($articulo), 'El reverso tiene que meter 12 unidades, no 144.');
        $this->assertEquals(0, $this->buscar_compra($compra)['articles'][$articulo->id]['ya_devueltas']);
    }

    /**
     * Con depósito: lo que la NC sacó de un depósito vuelve a ESE depósito.
     *
     * @test
     */
    public function borrar_la_nc_devuelve_al_deposito_del_que_salio()
    {
        $articulo = $this->crear_articulo('zz Eliminar NC proveedor deposito', ['stock' => 0]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);
        $principal = Address::where('street', TestingFerreteriaSeeder::DEPOSITO)->first();

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)], ['address_id' => $principal->id]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $deposito = function () use ($articulo, $principal) {
            return (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount');
        };

        $this->assertEquals(6, $deposito());

        $this->deleteJson('api/current-acount/provider/'.$this->nota_credito_de($compra)->id)->assertStatus(200);

        $this->assertEquals(10, $deposito());
        $this->assertEquals(10, $this->stock($articulo));
    }
}
