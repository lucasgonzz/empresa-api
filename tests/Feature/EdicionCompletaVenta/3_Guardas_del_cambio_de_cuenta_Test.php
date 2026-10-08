<?php

namespace Tests\Feature\EdicionCompletaVenta;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Las guardas del CAMBIO DE CUENTA de una venta (cambio de cliente o de moneda de una venta que va a
 * la cuenta corriente), misión edicion-completa-ventas-presupuestos, 8/10/2026. Ver
 * `CambioDeCuentaDeVentaHelper`.
 *
 *  - 422 si la venta tiene una NOTA DE CRÉDITO en la cuenta corriente: `deleteCurrentAcountFromSale()`
 *    mueve solo el débito, así que el cliente viejo se quedaría con el crédito de la devolución y el
 *    nuevo pagaría el total.
 *  - 422 si la venta está en una FACTURACIÓN CONSOLIDADA (la factura salió a nombre del cliente viejo).
 *  - 422 si el cliente nuevo NO EXISTE o es de OTRO COMERCIO.
 *  - Si el cliente nuevo no tiene la cuenta corriente de la moneda que queda, se le crea y la venta
 *    entra (antes: HTTP 500 en `CurrentAcountFromSaleHelper`).
 *  - El LÍMITE DE CRÉDITO se controla contra el cliente y la moneda que QUEDAN; un rechazo no escribe
 *    nada.
 *
 * Todos los rechazos se verifican con una foto CRUDA antes/después (venta, renglones, movimientos,
 * imputaciones, cuentas y saldos de los clientes).
 *
 * @group edicion-completa-venta
 * @group cuenta-corriente
 */
class Guardas_del_cambio_de_cuenta_Test extends EdicionCompletaVentaTestCase
{
    /**
     * Caso 7. El cliente nuevo tiene un límite de crédito que la venta excede: 422 con el cuerpo del
     * límite (contra la cuenta de B) y NADA cambió.
     *
     * @test
     */
    public function el_limite_de_credito_del_cliente_nuevo_excedido_da_422_y_no_cambia_nada()
    {
        $cliente_a = $this->cliente_nuevo('zz Limite A');
        $cliente_b = $this->cliente_nuevo('zz Limite B');

        $pesos_b = $this->cuenta($cliente_b, self::PESOS);
        CreditAccount::where('id', $pesos_b->id)->update(['limite_credito' => 50000]);

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $antes = $this->foto_cruda($venta, [$cliente_a, $cliente_b]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('error_limite_credito'));
        $this->assertEquals($pesos_b->id, (int) $response->json('limite_credito.credit_account_id'), 'El límite se tiene que controlar contra la cuenta del cliente NUEVO.');

        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $cliente_b]), 'El 422 del límite escribió algo.');

        $this->assert_saldo_independiente($cliente_a, self::PESOS, 80000, 'A después del rechazo');
        $this->assert_saldo_independiente($cliente_b, self::PESOS, 0, 'B después del rechazo');
    }

    /**
     * Caso 7, lado moneda. Pasar a dólares una venta cuyo cliente tiene tope en dólares: el límite se
     * controla contra la cuenta en DÓLARES (la moneda que queda), no la de pesos de la que sale.
     *
     * @test
     */
    public function el_limite_se_controla_contra_la_moneda_que_queda()
    {
        $cliente = $this->cliente_nuevo('zz Limite moneda');

        $dolares = $this->cuenta($cliente, self::DOLARES);
        CreditAccount::where('id', $dolares->id)->update(['limite_credito' => 50]);

        $venta = $this->vender($cliente, self::PESOS, 120000);

        $antes = $this->foto_cruda($venta, [$cliente]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1200,
            'items'       => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'   => 100,
            'total'       => 100,
        ]));

        $this->assertEquals(422, $response->getStatusCode(), 'Pasar 100 USD a una cuenta en dólares con tope 50 tenía que dar 422. Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('error_limite_credito'));
        $this->assertEquals($dolares->id, (int) $response->json('limite_credito.credit_account_id'));

        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente]), 'El 422 del límite escribió algo.');
    }

    /**
     * Caso 10bis. Venta con una NOTA DE CRÉDITO en la cuenta corriente (devolución parcial) + cambio de
     * cliente: 422 y nada cambió. Lo mismo con cambio de moneda.
     *
     * @test
     */
    public function una_venta_con_nota_de_credito_en_la_cuenta_no_cambia_de_cliente_ni_de_moneda()
    {
        $cliente_a = $this->cliente_nuevo('zz NC A');
        $cliente_b = $this->cliente_nuevo('zz NC B');

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $this->avanzar_reloj_de_ventas();
        $this->postJson('api/devoluciones', [
            'sale_id'                   => $venta->id,
            'client_id'                 => $cliente_a->id,
            'generar_current_acount'    => true,
            'total_devolucion'          => 10000,
            'observaciones'             => 'Devolución de test de edición completa',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        $this->assertEquals(1, CurrentAcount::where('sale_id', $venta->id)->whereNotNull('haber')->count(), 'Precondición: la devolución tiene que dejar la NC en la cuenta.');

        $antes = $this->foto_cruda($venta, [$cliente_a, $cliente_b]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('cambio_de_cuenta_rechazado'));
        $this->assertEquals('nota_de_credito', $response->json('motivo'));
        $this->assertNotEmpty($response->json('message'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $cliente_b]), 'El 422 escribió algo.');

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, [
            'moneda_id'   => self::DOLARES,
            'valor_dolar' => 1200,
            'items'       => $this->reexpresar($this->items_de($venta), 1 / 1200),
            'sub_total'   => 66.67,
            'total'       => 66.67,
        ]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertEquals('nota_de_credito', $response->json('motivo'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $cliente_b]), 'El 422 escribió algo.');

        // Editar sin cambiar de cuenta sigue andando.
        $this->editar_ok($venta, $this->payload_de_edicion($venta));
    }

    /**
     * 🔴 La moneda se compara NORMALIZADA de los dos lados (NULL, 0 y 1 son pesos). La SPA nueva manda
     * `moneda_id` crudo del store en cada PUT: una venta vieja con `moneda_id` NULL que el vendedor deja
     * en "Pesos" llega con 1. Eso NO es un cambio de cuenta (el débito ya estaba en la cuenta en pesos,
     * que es donde `CurrentAcountFromSaleHelper` pone una venta sin moneda), así que las guardas de
     * cambio de cuenta no corren: con una NC en la cuenta corriente, el PUT da 200 y no 422.
     *
     * @test
     */
    public function una_venta_sin_moneda_que_recibe_pesos_no_es_un_cambio_de_cuenta()
    {
        $cliente = $this->cliente_nuevo('zz Moneda NULL');

        $venta = $this->vender($cliente, self::PESOS, 80000);

        $this->avanzar_reloj_de_ventas();
        $this->postJson('api/devoluciones', [
            'sale_id'                   => $venta->id,
            'client_id'                 => $cliente->id,
            'generar_current_acount'    => true,
            'total_devolucion'          => 10000,
            'observaciones'             => 'Devolución de test de moneda NULL',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ])->assertStatus(201);

        // Setup: la venta queda como las de instalaciones viejas, sin moneda.
        DB::table('sales')->where('id', $venta->id)->update(['moneda_id' => null]);

        $this->assertEquals(1, CurrentAcount::where('sale_id', $venta->id)->whereNotNull('haber')->count(), 'Precondición: la venta tiene una NC en la cuenta.');

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['moneda_id' => self::PESOS]));

        $this->assertEquals(self::PESOS, (int) $venta->moneda_id);
        $this->assertNotNull($this->debito_de($venta, $this->cuenta($cliente, self::PESOS)), 'El débito tiene que seguir en la cuenta en pesos.');

        // 80000 de la venta − 10000 de la NC.
        $this->assert_saldo_independiente($cliente, self::PESOS, 70000, 'Pesos');
        $this->assert_saldo_independiente($cliente, self::DOLARES, 0, 'Dólares');
    }

    /**
     * Caso 10bis. Venta incluida en una FACTURACIÓN CONSOLIDADA + cambio de cliente: 422 y nada cambió.
     *
     * @test
     */
    public function una_venta_consolidada_no_cambia_de_cliente()
    {
        $cliente_a = $this->cliente_nuevo('zz Consolidada A');
        $cliente_b = $this->cliente_nuevo('zz Consolidada B');

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        // Setup: la venta contenedora de la consolidación (no va a la cuenta corriente).
        $contenedora = Sale::create([
            'num'                          => (int) Sale::where('user_id', $this->usuario->id)->max('num') + 1,
            'user_id'                      => $this->usuario->id,
            'client_id'                    => $cliente_a->id,
            'omitir_en_cuenta_corriente'   => 1,
            'save_current_acount'          => 0,
            'is_consolidacion_facturacion' => 1,
            'terminada'                    => 1,
            'moneda_id'                    => 1,
            'sub_total'                    => 80000,
            'total'                        => 80000,
        ]);

        DB::table('sales')->where('id', $venta->id)->update(['consolidacion_facturacion_id' => $contenedora->id]);

        $antes = $this->foto_cruda($venta, [$cliente_a, $cliente_b]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('cambio_de_cuenta_rechazado'));
        $this->assertEquals('consolidada', $response->json('motivo'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $cliente_b]), 'El 422 escribió algo.');
    }

    /**
     * Caso 10bis. El cliente nuevo es de OTRO comercio, o no existe: 422 y nada cambió.
     *
     * @test
     */
    public function el_cliente_nuevo_tiene_que_existir_y_ser_del_mismo_comercio()
    {
        $cliente_a = $this->cliente_nuevo('zz Tenencia A');
        $ajeno = $this->cliente_de_otro_comercio();

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $antes = $this->foto_cruda($venta, [$cliente_a, $ajeno]);

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['client_id' => $ajeno->id]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertTrue((bool) $response->json('cambio_de_cuenta_rechazado'));
        $this->assertEquals('cliente_ajeno', $response->json('motivo'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $ajeno]), 'El 422 escribió algo.');

        $inexistente = (int) Client::withTrashed()->max('id') + 1000;

        $response = $this->put_venta($venta, $this->payload_de_edicion($venta, ['client_id' => $inexistente]));

        $this->assertEquals(422, $response->getStatusCode(), 'Cuerpo: '.substr($response->getContent(), 0, 500));
        $this->assertEquals('cliente_inexistente', $response->json('motivo'));
        $this->assertEquals($antes, $this->foto_cruda($venta, [$cliente_a, $ajeno]), 'El 422 escribió algo.');
    }

    /**
     * Caso 10bis. El cliente nuevo NO tiene la cuenta corriente de la moneda de la venta: se le crea y
     * la venta entra (200). Antes reventaba con 500 en `CurrentAcountFromSaleHelper`.
     *
     * @test
     */
    public function si_el_cliente_nuevo_no_tiene_la_cuenta_de_la_moneda_se_le_crea_y_la_venta_entra()
    {
        $cliente_a = $this->cliente_nuevo('zz Sin cuenta A');

        $cliente_b = Client::create([
            'num'     => (int) Client::where('user_id', $this->usuario->id)->max('num') + 1,
            'name'    => 'zz Sin cuenta B '.uniqid(),
            'user_id' => $this->usuario->id,
        ]);

        $this->assertEquals(0, CreditAccount::where('model_name', 'client')->where('model_id', $cliente_b->id)->count(), 'Precondición: B no tiene cuentas.');

        $venta = $this->vender($cliente_a, self::PESOS, 80000);

        $venta = $this->editar_ok($venta, $this->payload_de_edicion($venta, ['client_id' => $cliente_b->id]));

        $this->assertEquals($cliente_b->id, (int) $venta->client_id);

        $pesos_b = $this->cuenta($cliente_b, self::PESOS);
        $this->assertEquals($this->usuario->id, (int) $pesos_b->user_id, 'La cuenta nueva tiene que ser del dueño de la venta.');
        $this->assertNotNull($this->debito_de($venta, $pesos_b), 'La venta no entró en la cuenta nueva de B.');

        $this->assert_saldo_independiente($cliente_b, self::PESOS, 80000, 'B (cuenta creada en la edición)');
        $this->assert_saldo_independiente($cliente_a, self::PESOS, 0, 'A (cliente original)');
    }
}
