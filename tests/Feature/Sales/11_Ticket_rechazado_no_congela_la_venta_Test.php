<?php

namespace Tests\Feature\Sales;

use App\Models\AfipTicket;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Tanda correctivos 24/8 — ítem 11: el freno de edición de la venta
 * (SaleHelper::motivo_por_el_que_no_se_puede_editar) contaba TICKETS, no CAEs: una
 * factura RECHAZADA por ARCA (queda en afip_tickets pero sin CAE) congelaba la venta
 * para siempre sin comprobante fiscal real de por medio. Criterio nuevo: solo congela
 * un comprobante CON CAE, el mismo criterio que ya usa la SPA (!!afip_ticket.cae).
 *
 * DatabaseTransactions y molde calcados de 5_No_se_puede_editar_venta_Test.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class Ticket_rechazado_no_congela_la_venta_Test extends TestCase
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
     * Venta mínima editable (sin cerrar, sin caja, sin métodos de pago), igual que en el
     * test 5 de esta carpeta.
     *
     * @param array $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        return Sale::create(array_merge([
            'user_id'                       => 500,
            'client_id'                     => null,
            'omitir_en_cuenta_corriente'    => 0,
            'save_current_acount'           => 0,
            'terminada'                     => 1,
            'is_cerrada'                    => 0,
            'caja_id'                       => null,
            'sub_total'                     => 100,
            'total'                         => 100,
        ], $overrides));
    }

    /**
     * Payload mínimo y válido para PUT api/sale/{id} (mismo molde que el test 5: items
     * vacíos a propósito, acá solo importa si el endpoint deja pasar o rechaza).
     *
     * @param \App\Models\Sale $sale
     * @param array $overrides
     * @return array
     */
    protected function payload_actualizar($sale, $overrides = [])
    {
        return array_merge([
            'id'                                => $sale->id,
            'client_id'                         => $sale->client_id,
            'address_id'                        => null,
            'save_current_acount'               => $sale->save_current_acount,
            'omitir_en_cuenta_corriente'         => $sale->omitir_en_cuenta_corriente,
            'to_check'                           => 0,
            'checked'                            => 0,
            'confirmed'                          => 0,
            'current_acount_payment_method_id'   => null,
            'discounts_in_services'              => 1,
            'surchages_in_services'              => 1,
            'employee_id'                        => null,
            'sub_total'                          => $sale->sub_total,
            'total'                              => $sale->total,
            'terminada'                          => 1,
            'seller_id'                          => null,
            'cantidad_cuotas'                    => null,
            'cuota_descuento'                    => 0,
            'cuota_recargo'                      => 0,
            'caja_id'                            => null,
            'afip_tipo_comprobante_id'           => null,
            'descuento'                          => null,
            'items'                              => [],
            'discounts'                          => [],
            'surchages'                          => [],
        ], $overrides);
    }

    /**
     * Una venta cuyo único comprobante fue RECHAZADO (sin CAE) sigue siendo editable.
     *
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_con_ticket_rechazado_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $venta = $this->crear_venta();

        /*
         * Ticket rechazado por ARCA: queda registrado con resultado R, con número de
         * comprobante pero SIN CAE (así lo persiste AfipWsfeHelper cuando el resultado
         * no es A).
         */
        AfipTicket::create([
            'sale_id'      => $venta->id,
            'resultado'    => 'R',
            'cbte_numero'  => 90001,
            'cae'          => null,
        ]);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_actualizar($venta));

        $response->assertStatus(200);
    }

    /**
     * Una venta con comprobante autorizado (CON CAE) sigue congelada, igual que siempre.
     *
     * @group ventas-edicion
     * @group sales
     * @test
     */
    public function venta_con_ticket_con_cae_no_se_puede_actualizar()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->actingAs($user, 'web');

        $venta = $this->crear_venta();

        AfipTicket::create([
            'sale_id'      => $venta->id,
            'resultado'    => 'A',
            'cbte_numero'  => 90002,
            'cae'          => '71234567890123',
        ]);

        $response = $this->putJson('api/sale/'.$venta->id, $this->payload_actualizar($venta));

        $response->assertStatus(409);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertStringContainsString('ya fue facturada', $cuerpo['message']);
    }
}
