<?php

namespace Tests\Feature\Compras;

use App\Models\Iva;
use App\Models\ProviderOrder;
use App\Models\ProviderOrderAfipTicket;
use App\Models\ProviderOrderExtraCost;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Tests de persistencia del grupo 156 (`fix-guardado-iva-compra-y-costos-extra`), Grupo 247
 * Prompt 01: cierran el hueco de wiring donde `ProviderOrderController`/
 * `ProviderOrderExtraCostController` recibian `precios_incluyen_iva` (compra) y
 * `facturado`/`iva_id`/`en_factura_compra`/`emisor_cuit`/`emisor_razon_social` (costo extra) del
 * request pero nunca los guardaban (arrays de creacion/asignacion explicitos que no los
 * mencionaban).
 *
 * Todos los tests usan `modo_facturacion = 'manual'` (no genera factura automatica, ver
 * `ModoFacturacionHelper::check_modo_facturacion()`), `update_prices = 0` y `update_stock = 0` (no
 * tocan `articles.cost`/`costo_real`/stock del fixture): son tests de PERSISTENCIA pura,
 * deliberadamente aislados del motor de costeo/facturacion para no necesitar restaurar el fixture
 * compartido. Proveedor Rosario (sin bonificaciones) por el mismo motivo de simplicidad que
 * `Costo_Extra_Factura_Aparte_Test`.
 *
 * @group compras
 */
class Persistencia_Iva_Y_Costos_Extra_Test extends ComprasTestCase
{
    /**
     * Arma y confirma una compra minima (1 articulo, Rosario, sin tocar precios/stock/cuenta
     * corriente) para los tests de persistencia de `precios_incluyen_iva`.
     *
     * `modo_facturacion` es 'manual' por defecto (no genera factura automatica, simplifica la
     * limpieza) EXCEPTO en el Test 1, que lo pide explicito en 'automatico' (texto literal del
     * prompt: "crear una compra en modo automático con el flag prendido") porque es el unico modo
     * donde `precios_incluyen_iva` se CONSUME ademas de guardarse: `ModoFacturacionHelper::
     * check_modo_facturacion()` solo dispara `calcular_iva()` (que lee `precios_incluyen_iva` para
     * decidir neto vs. back-out por linea) en modo automatico. En manual esa rama no corre.
     *
     * @param bool $precios_incluyen_iva
     * @param string $modo_facturacion
     * @return int Id de la orden creada.
     */
    protected function crear_compra_minima($precios_incluyen_iva, $modo_facturacion = 'manual')
    {
        $payload = $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => $modo_facturacion,
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'total_with_iva'          => 0,
            'precios_incluyen_iva'    => $precios_incluyen_iva ? 1 : 0,
            'articles' => [
                $this->item('Marco para cama', 100, 1),
            ],
        ]);

        $response = $this->postJson('api/provider-order', $payload);

        $response->assertStatus(201);

        return $response->json('model.id');
    }

    /**
     * Actualiza la compra minima con un nuevo valor de `precios_incluyen_iva`, reusando el mismo
     * resto de payload que `crear_compra_minima()` (mismo criterio que
     * `Costo_Extra_Factura_Aparte_Test::reconfirmar_compra_base()`: el controller de compras no es
     * un PATCH parcial, hay que remandar el payload completo). Ver docblock de `crear_compra_minima()`
     * sobre por que `modo_facturacion` es parametrizable.
     *
     * @param int $order_id
     * @param bool $precios_incluyen_iva
     * @param string $modo_facturacion
     * @return \Illuminate\Testing\TestResponse
     */
    protected function actualizar_precios_incluyen_iva($order_id, $precios_incluyen_iva, $modo_facturacion = 'manual')
    {
        return $this->putJson('api/provider-order/'.$order_id, $this->payload_compra([
            'provider_id'             => $this->proveedor(TestingFerreteriaSeeder::PROVIDER_OTRO)->id,
            'modo_facturacion'        => $modo_facturacion,
            'update_prices'           => 0,
            'update_stock'            => 0,
            'generate_current_acount' => 0,
            'total_with_iva'          => 0,
            'precios_incluyen_iva'    => $precios_incluyen_iva ? 1 : 0,
            'articles' => [
                $this->item('Marco para cama', 100, 1),
            ],
        ]));
    }

    /**
     * Borra la compra de persistencia y todo lo que le cuelga (costos extra, facturas y su
     * desglose de IVA si `modo_facturacion` llegara a generar alguna). Con `update_prices`/
     * `update_stock`/`generate_current_acount` en 0 no hay nada mas que restaurar: el fixture de
     * articulos nunca se toco.
     *
     * @param int|null $order_id
     * @return void
     */
    protected function limpiar_compra_de_persistencia($order_id)
    {
        if (is_null($order_id)) {
            return;
        }

        foreach (ProviderOrderAfipTicket::where('provider_order_id', $order_id)->get() as $ticket) {
            $ticket->provider_order_afip_ticket_ivas()->delete();
            $ticket->delete();
        }

        ProviderOrderExtraCost::where('provider_order_id', $order_id)->delete();

        ProviderOrder::where('id', $order_id)->delete();
    }

    /**
     * Test 1 — `precios_incluyen_iva` prendido via `update()` persiste. Ciclo completo
     * (crear apagado -> actualizar prendido -> releer), porque el bug de wiring original vivia en
     * `update()`, no en `store()`.
     *
     * En modo 'automatico' (texto literal del prompt), a diferencia de los tests 2-5: es el unico
     * modo donde `precios_incluyen_iva` se consume (via `ModoFacturacionHelper::calcular_iva()`),
     * no solo se guarda. Ver docblock de `crear_compra_minima()`.
     *
     * @group compras
     * @test
     */
    public function precios_incluyen_iva_prendido_via_update_persiste()
    {
        $order_id = null;

        try {

            $order_id = $this->crear_compra_minima(false, 'automatico');

            $update_response = $this->actualizar_precios_incluyen_iva($order_id, true, 'automatico');

            $update_response->assertStatus(200);

            $get_response = $this->getJson('api/provider-order/'.$order_id);

            $get_response->assertStatus(200);

            $this->assertTrue(
                (bool) $get_response->json('model.precios_incluyen_iva'),
                'precios_incluyen_iva tiene que quedar en true despues del update que lo prende, y seguir asi al releer'
            );

        } finally {

            $this->limpiar_compra_de_persistencia($order_id);
        }
    }

    /**
     * Test 2 — 🔴 el test del bug original del grupo 156: `precios_incluyen_iva` apagado via
     * `update()` NO vuelve a prenderse solo al releer. `ProviderOrderController::update()` nunca
     * leia este campo del request, asi que el `update()` que lo apagaba no se guardaba y el valor
     * anterior (prendido) sobrevivia en la base — el sintoma exacto que reporto Lucas.
     *
     * @group compras
     * @test
     */
    public function precios_incluyen_iva_apagado_via_update_no_vuelve_a_prenderse_solo()
    {
        $order_id = null;

        try {

            $order_id = $this->crear_compra_minima(true);

            $update_response = $this->actualizar_precios_incluyen_iva($order_id, false);

            $update_response->assertStatus(200);

            $get_response = $this->getJson('api/provider-order/'.$order_id);

            $get_response->assertStatus(200);

            $this->assertFalse(
                (bool) $get_response->json('model.precios_incluyen_iva'),
                'precios_incluyen_iva tiene que quedar en false despues del update que lo apaga, y NO revivir a true al releer (bug original del grupo 156)'
            );

        } finally {

            $this->limpiar_compra_de_persistencia($order_id);
        }
    }

    /**
     * Test 3 — costo extra con `facturado = 1` y una alicuota (`iva_id`): crear, releer, ambos
     * siguen ahi.
     *
     * @group compras
     * @test
     */
    public function costo_extra_facturado_con_alicuota_persiste()
    {
        $order_id = null;

        try {

            $order_id = $this->crear_compra_minima(false);

            $iva_21 = Iva::where('percentage', '21')->first();

            $create_response = $this->postJson('api/provider-order-extra-cost', [
                'model_id'    => $order_id,
                'description' => 'Flete',
                'value'       => 500,
                'tipo'        => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => 1,
                'iva_id'            => $iva_21->id,
                // en_factura_compra es NOT NULL en la base: siempre hay que mandarlo explicito.
                'en_factura_compra' => 1,
            ]);

            $create_response->assertStatus(201);

            $extra_cost_id = $create_response->json('model.id');

            $get_response = $this->getJson('api/provider-order-extra-cost/'.$extra_cost_id);

            $get_response->assertStatus(200);

            $this->assertTrue((bool) $get_response->json('model.facturado'), 'facturado tiene que persistir en true');
            $this->assertEquals((int) $iva_21->id, (int) $get_response->json('model.iva_id'), 'la alicuota (iva_id) tiene que persistir');

        } finally {

            $this->limpiar_compra_de_persistencia($order_id);
        }
    }

    /**
     * Test 4 — costo extra con `facturado = 1`, `en_factura_compra = 0`, y CUIT + razon social del
     * emisor: los tres datos del emisor persisten. Es el caso con mas campos nuevos en juego.
     *
     * @group compras
     * @test
     */
    public function costo_extra_facturado_aparte_con_datos_del_emisor_persiste()
    {
        $order_id = null;

        try {

            $order_id = $this->crear_compra_minima(false);

            $create_response = $this->postJson('api/provider-order-extra-cost', [
                'model_id'            => $order_id,
                'description'         => 'Despachante de aduana',
                'value'               => 605,
                'tipo'                => ProviderOrderExtraCost::TIPO_ARANCEL_IMPORTACION,
                'facturado'           => 1,
                'en_factura_compra'   => 0,
                'emisor_cuit'         => '30-99999999-1',
                'emisor_razon_social' => 'Despachante SRL',
            ]);

            $create_response->assertStatus(201);

            $extra_cost_id = $create_response->json('model.id');

            $get_response = $this->getJson('api/provider-order-extra-cost/'.$extra_cost_id);

            $get_response->assertStatus(200);

            $this->assertTrue((bool) $get_response->json('model.facturado'), 'facturado tiene que persistir en true');
            $this->assertFalse((bool) $get_response->json('model.en_factura_compra'), 'en_factura_compra tiene que persistir en false');
            $this->assertEquals('30-99999999-1', $get_response->json('model.emisor_cuit'), 'emisor_cuit tiene que persistir');
            $this->assertEquals('Despachante SRL', $get_response->json('model.emisor_razon_social'), 'emisor_razon_social tiene que persistir');

        } finally {

            $this->limpiar_compra_de_persistencia($order_id);
        }
    }

    /**
     * Test 5 — espejo del Test 2 para el otro booleano: apagar `facturado` en un costo extra que
     * lo tenia prendido, via `update()`, no revive al releer.
     *
     * @group compras
     * @test
     */
    public function costo_extra_facturado_apagado_via_update_no_vuelve_a_prenderse_solo()
    {
        $order_id = null;

        try {

            $order_id = $this->crear_compra_minima(false);

            $iva_21 = Iva::where('percentage', '21')->first();

            $create_response = $this->postJson('api/provider-order-extra-cost', [
                'model_id'    => $order_id,
                'description' => 'Flete',
                'value'       => 500,
                'tipo'        => ProviderOrderExtraCost::TIPO_TRANSPORTE,
                'facturado'         => 1,
                'iva_id'            => $iva_21->id,
                // en_factura_compra es NOT NULL en la base: siempre hay que mandarlo explicito.
                'en_factura_compra' => 1,
            ]);

            $create_response->assertStatus(201);

            $extra_cost_id = $create_response->json('model.id');

            // update() reasigna los 5 campos de facturacion de forma directa e incondicional (no
            // parcial, ver grupo 156): hay que remandar iva_id tal cual para no perderlo, el punto
            // de este test es solo facturado.
            $update_response = $this->putJson('api/provider-order-extra-cost/'.$extra_cost_id, [
                'description'       => 'Flete',
                'value'             => 500,
                'facturado'         => 0,
                'iva_id'            => $iva_21->id,
                'en_factura_compra' => 1,
            ]);

            $update_response->assertStatus(200);

            $get_response = $this->getJson('api/provider-order-extra-cost/'.$extra_cost_id);

            $get_response->assertStatus(200);

            $this->assertFalse(
                (bool) $get_response->json('model.facturado'),
                'facturado tiene que quedar en false despues del update que lo apaga, y NO revivir a true al releer'
            );

        } finally {

            $this->limpiar_compra_de_persistencia($order_id);
        }
    }
}
