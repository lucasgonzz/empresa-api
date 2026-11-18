<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Models\CurrentAcount;
use Database\Seeders\testing\TestingFerreteriaSeeder;

class Creditos_Historicos_Puntos_Test extends PuntosTestCase
{
    public function test_credito_puro_no_regala_puntos_y_mixto_conserva_el_cobro_real()
    {
        $this->dar_extencion();
        $this->crear_programa();
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);
        $venta = $this->crear_venta($this->payload_venta($cliente->id, 121000));
        $debito = $this->debito_de_la_venta($venta);
        $credito = CurrentAcount::create(['credit_account_id' => $debito->credit_account_id,
            'client_id' => $cliente->id, 'debe' => -121000, 'is_provisorio' => 0,
            'detalle' => 'Crédito histórico', 'status' => 'sin_pagar']);
        CurrentAcountHelper::check_saldos_y_pagos($debito->credit_account_id);
        $this->assertSame('pagado', $debito->fresh()->status);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'ganados'));
        $credito->update(['debe' => -120000]);
        $this->pagar($cliente, 1000, 1)->assertStatus(201);
        $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'ganados'));
        $this->assertEquals(100, $this->movimientos_de_la_venta($venta, 'ganados')->first()->puntos);
        CurrentAcountHelper::checkPagos($debito->credit_account_id);
        $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'ganados'));
    }
}
