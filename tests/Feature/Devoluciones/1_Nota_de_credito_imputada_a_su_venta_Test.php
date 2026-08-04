<?php

namespace Tests\Feature\Devoluciones;

use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Feature tests del grupo 327 (prompt 03): la nota de credito generada desde una devolucion
 * sobre una venta a cuenta corriente tiene que imputarse DIRECTAMENTE al debito de esa venta
 * (to_pay_id dirigido), no a la deuda mas vieja del cliente por la cola FIFO de siempre. Regla
 * cerrada por Lucas el 4/7/2026 (refactor_empresa/cuentas_corrientes.md, lineas 116-121).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing de esta rama esta sembrada
 * de antes y un refresh la vaciaria, rompiendo el resto de las suites. Cada test arma su
 * propio cliente/ventas/debitos en vez de depender de fixtures.
 */
class Nota_de_credito_imputada_a_su_venta_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Escenario base: un cliente con cuenta corriente en pesos y dos ventas, la vieja (A,
     * 1000) primero y la nueva (B, 500) despues, cada una con su debito en sin_pagar.
     *
     * @param string $nombre_cliente
     * @return array{client: Client, credit_account: CreditAccount, venta_a: Sale, debito_a: CurrentAcount, venta_b: Sale, debito_b: CurrentAcount}
     */
    protected function escenario_base($nombre_cliente)
    {
        $client = Client::create([
            'name'    => $nombre_cliente,
            'user_id' => 500,
        ]);

        $credit_account = CreditAccount::create([
            'model_name' => 'client',
            'model_id'   => $client->id,
            'moneda_id'  => 1,
            'user_id'    => 500,
        ]);

        $venta_a = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => 1,
            'total'     => 1000,
            'terminada' => 1,
        ]);

        $debito_a = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta_a->id,
            'debe'              => 1000,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta_a->id,
            'credit_account_id' => $credit_account->id,
            'created_at'        => Carbon::now()->subDays(2),
        ]);

        $venta_b = Sale::create([
            'user_id'   => 500,
            'client_id' => $client->id,
            'moneda_id' => 1,
            'total'     => 500,
            'terminada' => 1,
        ]);

        $debito_b = CurrentAcount::create([
            'detalle'           => 'Venta N°'.$venta_b->id,
            'debe'              => 500,
            'pagandose'         => 0,
            'status'            => 'sin_pagar',
            'client_id'         => $client->id,
            'sale_id'           => $venta_b->id,
            'credit_account_id' => $credit_account->id,
            'created_at'        => Carbon::now()->subDay(),
        ]);

        return [
            'client'         => $client,
            'credit_account' => $credit_account,
            'venta_a'        => $venta_a,
            'debito_a'       => $debito_a,
            'venta_b'        => $venta_b,
            'debito_b'       => $debito_b,
        ];
    }

    /**
     * Payload minimo y valido para POST api/devoluciones/. discounts/surchages vacios pero
     * presentes a proposito: set_pivot_percentage() los recorre con foreach sin chequear null.
     *
     * @param Sale|null $venta
     * @param Client $client
     * @param float $total
     * @param array $overrides
     * @return array
     */
    protected function payload_devolucion($venta, $client, $total, $overrides = [])
    {
        return array_merge([
            'sale_id'                   => $venta ? $venta->id : null,
            'client_id'                 => $client->id,
            'generar_current_acount'    => true,
            'total_devolucion'          => $total,
            'observaciones'             => 'test',
            'items'                     => [],
            'descriptions'              => [],
            'discounts'                 => [],
            'surchages'                 => [],
            'regresar_stock'            => false,
            'update_unidades_devueltas' => false,
            'facturar_nota_credito'     => null,
        ], $overrides);
    }

    /**
     * @group devoluciones
     * @test
     */
    public function la_nc_se_imputa_a_su_propia_venta_y_no_a_la_mas_vieja()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Devolucion 327 A');

        $response = $this->post('api/devoluciones/', $this->payload_devolucion($e['venta_b'], $e['client'], 500));

        $response->assertStatus(201);

        $nota_credito = CurrentAcount::where('status', 'nota_credito')
                                    ->where('sale_id', $e['venta_b']->id)
                                    ->latest('id')
                                    ->first();
        $this->assertNotNull($nota_credito, 'No se creo la nota de credito.');
        $this->assertEquals($e['debito_b']->id, $nota_credito->to_pay_id);

        $debito_b = $e['debito_b']->fresh();
        $this->assertEquals('pagado', $debito_b->status);

        // La asercion que dice si la regla se cumplio: antes del grupo 327 la NC saldaba A.
        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('sin_pagar', $debito_a->status);
        $this->assertEquals(0, $debito_a->pagandose);

        $this->assertTrue(
            $debito_b->pagado_por->pluck('id')->contains($nota_credito->id),
            'No hay fila en pagado_por que vincule el debito de B con la NC.'
        );
    }

    /**
     * @group devoluciones
     * @test
     */
    public function el_sobrante_de_la_nc_cae_en_la_siguiente_venta_sin_saldar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Devolucion 327 B');

        // Devolucion de 800 sobre B, que debe 500: 300 de sobrante tienen que caer en A.
        $response = $this->post('api/devoluciones/', $this->payload_devolucion($e['venta_b'], $e['client'], 800));

        $response->assertStatus(201);

        $debito_b = $e['debito_b']->fresh();
        $this->assertEquals('pagado', $debito_b->status);

        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('pagandose', $debito_a->status);
        $this->assertEquals(300, $debito_a->pagandose);
    }

    /**
     * @group devoluciones
     * @test
     */
    public function la_devolucion_sin_venta_asociada_no_dirige_la_imputacion()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Devolucion 327 C');

        // Devolucion desde cero (sin sale_id), como la del grupo 113. 1000 para saldar
        // exactamente A por FIFO, sin tocar B.
        $response = $this->post('api/devoluciones/', $this->payload_devolucion(null, $e['client'], 1000));

        $response->assertStatus(201);

        $nota_credito = CurrentAcount::where('status', 'nota_credito')
                                    ->where('client_id', $e['client']->id)
                                    ->whereNull('sale_id')
                                    ->latest('id')
                                    ->first();
        $this->assertNotNull($nota_credito, 'No se creo la nota de credito.');
        $this->assertNull($nota_credito->to_pay_id);
        $this->assertEquals($e['client']->id, $nota_credito->client_id);

        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('pagado', $debito_a->status);
        $this->assertEquals(1000, $debito_a->pagandose);

        $debito_b = $e['debito_b']->fresh();
        $this->assertEquals('sin_pagar', $debito_b->status);
        $this->assertEquals(0, $debito_b->pagandose);
    }

    /**
     * @group devoluciones
     * @test
     */
    public function la_nc_de_una_venta_ya_saldada_no_se_dirige_y_cae_en_el_resto()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $e = $this->escenario_base('zz Cliente Devolucion 327 D');

        // El debito de B ya esta pagado antes de la devolucion: no hay remanente que dirigir.
        $e['debito_b']->status = 'pagado';
        $e['debito_b']->pagandose = 500;
        $e['debito_b']->save();

        $response = $this->post('api/devoluciones/', $this->payload_devolucion($e['venta_b'], $e['client'], 200));

        $response->assertStatus(201);

        $nota_credito = CurrentAcount::where('status', 'nota_credito')
                                    ->where('sale_id', $e['venta_b']->id)
                                    ->latest('id')
                                    ->first();
        $this->assertNotNull($nota_credito, 'No se creo la nota de credito.');
        $this->assertNull($nota_credito->to_pay_id);

        $debito_b = $e['debito_b']->fresh();
        $this->assertFalse(
            $debito_b->pagado_por->pluck('id')->contains($nota_credito->id),
            'No puede haber una fila de pagado_por contra un debito ya saldado.'
        );

        // El haber cae sobre A, que es lo unico sin_pagar/pagandose que queda.
        $debito_a = $e['debito_a']->fresh();
        $this->assertEquals('pagandose', $debito_a->status);
        $this->assertEquals(200, $debito_a->pagandose);
    }
}
