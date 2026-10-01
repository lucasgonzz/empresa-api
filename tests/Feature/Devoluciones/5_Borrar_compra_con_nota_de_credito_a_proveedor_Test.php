<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Address;
use App\Models\ProviderOrder;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Borrar una compra que ya tuvo una devolución al proveedor (misión
 * devoluciones-compras-y-rediseno, 1/10/2026). Ver ProviderOrderController::destroy() y
 * ProviderOrderHelper::resetArticlesStock().
 *
 * 🔴 La regla (decisión del orquestador de la misión): una compra con notas de crédito a proveedor
 * VIVAS que fueron a CUENTA CORRIENTE no se borra (una NC sin C/C no frena: no tiene plata, y su
 * stock lo cubre la resta por libro de resetArticlesStock); responde 422 pidiendo eliminar primero esas NC desde la cuenta corriente del
 * proveedor. Borrarla igual dejaba a la NC apuntando a una compra inexistente, con su haber en la
 * cuenta y sin el débito contra el que se imputó, y el stock había que adivinarlo. Eliminando
 * primero la NC (que devuelve su stock, ver 6_) y después la compra, todo vuelve a como estaba antes
 * de la compra.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group devoluciones
 */
class Borrar_compra_con_nota_de_credito_a_proveedor_Test extends NotaCreditoProveedorTestCase
{
    /**
     * Compra 10, devolución 4 A CUENTA CORRIENTE, borrar la compra → 422 con el número de la NC,
     * y no se toca nada: la compra sigue, el stock sigue en 11.
     *
     * @test
     */
    public function borrar_una_compra_con_nc_viva_responde_422_y_no_toca_nada()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC global', ['stock' => 5]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        $this->assertEquals(11, $this->stock($articulo), 'La devolución no sacó las 4 unidades; el escenario no sirve.');

        $nota_credito = $this->nota_credito_de($compra);

        $response = $this->deleteJson('api/provider-order/'.$compra->id);

        $response->assertStatus(422);
        $this->assertStringContainsString('notas de crédito a proveedor', $response->json('message'));
        $this->assertStringContainsString('N° '.$nota_credito->num_receipt, $response->json('message'));

        $this->assertNotNull(ProviderOrder::find($compra->id), 'La compra no se tenía que borrar.');
        $this->assertEquals(11, $this->stock($articulo), 'Un 422 no puede mover el stock.');
        $this->assertNotNull($nota_credito->fresh());
    }

    /**
     * Con depósito, la misma regla: 422 y el depósito intacto.
     *
     * @test
     */
    public function borrar_una_compra_con_deposito_y_nc_viva_no_toca_el_deposito()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC deposito', ['stock' => 0]);
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

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(422);

        $this->assertEquals(6, (float) DB::table('address_article')->where('article_id', $articulo->id)->where('address_id', $principal->id)->value('amount'));
    }

    /**
     * El camino que sí funciona: eliminar primero la NC (que devuelve su stock) y después la
     * compra. Por bulto, para cubrir también la conversión: el stock vuelve al de antes de la
     * compra.
     *
     * @test
     */
    public function eliminando_primero_la_nc_la_compra_se_borra_y_el_stock_vuelve_al_de_antes()
    {
        $articulo = $this->crear_articulo('zz Borrar compra despues de la NC', ['stock' => 5, 'unidades_individuales' => 12]);
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

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertNull(ProviderOrder::find($compra->id));
        $this->assertEquals(5, $this->stock($articulo));
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

    /**
     * Una NC SIN cuenta corriente no frena el borrado: la compra se borra y el stock vuelve al de
     * antes de la compra, porque resetArticlesStock() saca lo ingresado menos lo que la NC ya sacó.
     *
     * @test
     */
    public function borrar_una_compra_con_nc_sin_cuenta_corriente_se_borra_y_el_stock_vuelve()
    {
        $articulo = $this->crear_articulo('zz Borrar compra con NC sin cc', ['stock' => 5]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $compra = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $compra,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['generar_current_acount' => 0]
        ))->assertStatus(201);

        $this->assertEquals(11, $this->stock($articulo));

        $this->deleteJson('api/provider-order/'.$compra->id)->assertStatus(200);

        $this->assertNull(ProviderOrder::find($compra->id));
        $this->assertEquals(5, $this->stock($articulo), 'Borrar la compra tiene que sacar sólo lo que quedó de ella (10 − 4).');
    }

    /**
     * El borrado MASIVO respeta el freno: la compra con NC a C/C no vuelve como eliminada (el
     * listado no la saca de la pantalla), el motivo viaja en `not_deleted`, y las demás sí se
     * borran.
     *
     * @test
     */
    public function el_borrado_masivo_no_cuenta_como_eliminada_la_compra_frenada()
    {
        $articulo = $this->crear_articulo('zz Borrado masivo compra frenada', ['stock' => 5]);
        $proveedor = $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO);

        $frenada = $this->crear_compra([$this->renglon_compra($articulo, 100, 10)]);
        $libre = $this->crear_compra([$this->renglon_compra($articulo, 100, 1)]);

        $this->postJson('api/devoluciones/', $this->payload_devolucion(
            $proveedor->id,
            $frenada,
            [$this->item_devolucion($articulo, 121, 4)],
            484,
            ['generar_current_acount' => 1]
        ))->assertStatus(201);

        // Un solo registro: va por el camino síncrono de DeleteController.
        $response = $this->putJson('api/delete/provider_order', [
            'from_filter' => 0,
            'models_id'   => [$frenada->id],
        ]);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('models'), 'La compra frenada no puede volver como eliminada.');
        $this->assertEquals($frenada->id, $response->json('not_deleted.0.id'));
        $this->assertStringContainsString('notas de crédito a proveedor', $response->json('not_deleted.0.message'));
        $this->assertNotNull(ProviderOrder::find($frenada->id));

        // Varios registros (el camino del job): cuenta solo la que sí se borró.
        $resultado = \App\Http\Controllers\Helpers\DeleteModelsHelper::process_delete('provider_order', [$frenada->id, $libre->id]);

        $this->assertEquals(1, $resultado['deleted_count']);
        $this->assertCount(1, $resultado['not_deleted']);
        $this->assertNotNull(ProviderOrder::find($frenada->id));
        $this->assertNull(ProviderOrder::find($libre->id));
    }
}
