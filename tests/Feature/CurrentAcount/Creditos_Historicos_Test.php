<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\CurrentAcountFromSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountPagoHelper;
use App\Http\Controllers\Helpers\SaleHelper;
use App\Http\Controllers\Helpers\currentAcount\CreditosHistoricosHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\User;
use App\Models\Sale;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\Seller;
use App\Models\SellerCommission;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Creditos_Historicos_Test extends TestCase
{
    use DatabaseTransactions;

    protected function cuenta($moneda = 1)
    {
        $user = User::findOrFail(500);
        $this->actingAs($user, 'web');
        $client = Client::create(['name' => 'Crédito histórico ticket 145', 'user_id' => $user->id]);
        return CreditAccount::create(['model_name' => 'client', 'model_id' => $client->id,
            'moneda_id' => $moneda, 'user_id' => $user->id, 'saldo' => 0]);
    }

    protected function movimiento($cuenta, $debe, $haber, $fecha, $extra = [])
    {
        return CurrentAcount::create(array_merge(['credit_account_id' => $cuenta->id,
            'client_id' => $cuenta->model_id, 'user_id' => $cuenta->user_id, 'moneda_id' => $cuenta->moneda_id,
            'debe' => $debe, 'haber' => $haber, 'pagandose' => 0, 'is_provisorio' => 0,
            'detalle' => 'Historial de prueba', 'status' => is_null($haber) ? 'sin_pagar' : 'pago_from_client',
            'created_at' => $fecha, 'updated_at' => $fecha], $extra));
    }

    public function test_historial_del_ticket_no_conserva_deuda_fantasma_y_es_repetible()
    {
        $cuenta = $this->cuenta();
        $enero = $this->movimiento($cuenta, 400000, null, '2026-01-01 10:00:00');
        $febrero = $this->movimiento($cuenta, 956927.11, null, '2026-02-01 10:00:00');
        $this->movimiento($cuenta, null, 400000, '2026-06-23 10:00:00');
        $credito = $this->movimiento($cuenta, -848000, null, '2026-07-10 10:00:00');
        $this->movimiento($cuenta, null, 108927.11, '2026-08-21 10:00:00');
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertEquals(0, (float) $cuenta->fresh()->saldo);
        $this->assertSame('pagado', $enero->fresh()->status);
        $this->assertSame('pagado', $febrero->fresh()->status, 'El saldo es cero pero febrero sigue pendiente: falta imputar 848000 del crédito histórico.');
        $this->assertEquals(956927.11, (float) $febrero->fresh()->pagandose);
        $this->assertEquals(-848000, (float) $credito->fresh()->debe);
        $this->assertNull($credito->fresh()->haber);
        $foto = $this->foto($cuenta);
        CurrentAcountHelper::checkPagos($cuenta->id);
        $this->assertEquals($foto, $this->foto($cuenta));
    }

    protected function foto($cuenta)
    {
        $ids = CurrentAcount::where('credit_account_id', $cuenta->id)->pluck('id');
        return [CurrentAcount::whereIn('id', $ids)->orderBy('id')->get(['id', 'debe', 'haber', 'status', 'pagandose', 'saldo'])->toArray(),
            DB::table('pagado_por')->whereIn('debe_id', $ids)->orderBy('debe_id')->orderBy('haber_id')->get(['debe_id','haber_id','pagado','total_pago'])->map(function ($r) { return (array) $r; })->all()];
    }

    public function test_credito_sin_pago_se_imputa_y_conserva_saldo_a_favor()
    {
        $cuenta = $this->cuenta();
        $debito = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        $credito = $this->movimiento($cuenta, -150, null, '2026-02-01 10:00:00');
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertSame('pagado', $debito->fresh()->status);
        $this->assertSame('pagado', $credito->fresh()->status);
        $this->assertEquals(0, $credito->fresh()->pagandose);
        $this->assertEquals(-50, $cuenta->fresh()->saldo);
        $plan = CreditosHistoricosHelper::plan($cuenta);
        $this->assertEquals(50, $plan['remanente']);
        $this->assertCount(1, $plan['imputaciones']);
        $this->assertEquals(100, $plan['imputaciones'][0]['pagado']);
        $this->assertEquals(150, $plan['imputaciones'][0]['total_pago']);
        $foto = $this->foto($cuenta);
        CreditosHistoricosHelper::plan($cuenta);
        $this->assertEquals($foto, $this->foto($cuenta), 'Simular no escribe.');
    }

    public function test_fechas_iguales_y_credito_antes_de_deuda_no_crean_pivots_negativos_ni_cero()
    {
        $cuenta = $this->cuenta();
        $fecha = '2026-01-01 10:00:00';
        $this->movimiento($cuenta, -100.01, null, $fecha);
        $this->movimiento($cuenta, 0, null, $fecha);
        $debito = $this->movimiento($cuenta, 100.01, null, $fecha);
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertSame('pagado', $debito->fresh()->status);
        $plan = CreditosHistoricosHelper::plan($cuenta);
        $this->assertEquals(0, $plan['saldo_economico']);
        $this->assertCount(1, $plan['imputaciones']);
        $this->assertGreaterThan(0, $plan['imputaciones'][0]['pagado']);
        $antes = $this->foto($cuenta);
        CurrentAcountHelper::checkPagos($cuenta->id);
        $this->assertEquals($antes, $this->foto($cuenta));
    }

    public function test_destinos_dirigidos_simples_y_multiples_se_conservan()
    {
        foreach ([false, true] as $multiple) {
            $cuenta = $this->cuenta();
            $a = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
            $b = $this->movimiento($cuenta, 100, null, '2026-01-02 10:00:00');
            $c = $this->movimiento($cuenta, 100, null, '2026-01-03 10:00:00');
            $pago = $this->movimiento($cuenta, null, 150, '2026-02-01 10:00:00', $multiple ? ['to_pay_ids' => [$b->id, $c->id]] : ['to_pay_id' => $b->id]);
            $this->movimiento($cuenta, -25, null, '2026-03-01 10:00:00');
            CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
            $this->assertEquals(100, $b->fresh()->pagandose);
            $this->assertEquals($multiple ? 25 : 75, $a->fresh()->pagandose);
            $this->assertEquals($multiple ? 50 : 0, $c->fresh()->pagandose);
            $this->assertEquals(150, DB::table('pagado_por')->where('haber_id', $pago->id)->sum('pagado'));
        }
    }

    public function test_otra_moneda_y_provisorios_no_se_modifican()
    {
        $cuenta = $this->cuenta();
        $otra = $this->cuenta(2);
        $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        $this->movimiento($cuenta, -50, null, '2026-02-01 10:00:00');
        $provisorio = $this->movimiento($cuenta, 300, null, '2026-03-01 10:00:00', ['is_provisorio' => 1]);
        $this->movimiento($otra, 100, null, '2026-01-01 10:00:00');
        $foto = $this->foto($otra);
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertEquals($foto, $this->foto($otra));
        $this->assertSame('sin_pagar', $provisorio->fresh()->status);
        $this->assertEquals(0, $provisorio->fresh()->pagandose);
    }

    public function test_venta_negativa_actual_y_venta_posterior_aplican_credito_sin_pago()
    {
        $cuenta = $this->cuenta();
        $debito = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        foreach ([-150, 75] as $importe) {
            $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id,
                'moneda_id' => 1, 'total' => $importe, 'terminada' => 1, 'created_at' => '2026-11-18 10:00:00']);
            (new CurrentAcountFromSaleHelper($venta))->crear_current_acount();
        }
        $this->assertSame('pagado', $debito->fresh()->status);
        $this->assertEquals(25, $cuenta->fresh()->saldo);
        $nuevo = CurrentAcount::where('sale_id', $venta->id)->first();
        $this->assertEquals(50, $nuevo->pagandose);
        $this->assertSame('pagandose', $nuevo->status);
    }

    public function test_pago_nuevo_actual_y_retroactivo_usan_el_motor_comun()
    {
        foreach ([1, 0] as $actual) {
            $cuenta = $this->cuenta();
            $debito = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
            $this->movimiento($cuenta, -75, null, '2026-02-01 10:00:00');
            $respuesta = $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, $actual));
            $respuesta->assertStatus(201);
            $this->assertSame('pagado', $debito->fresh()->status);
            $this->assertEquals(100, $debito->fresh()->pagandose);
            $this->assertEquals(0, $cuenta->fresh()->saldo);
            $this->assertEquals(25, CurrentAcount::where('credit_account_id', $cuenta->id)->sum('haber'));
        }
    }

    protected function payload_pago($cuenta, $actual)
    {
        return ['credit_account_id' => $cuenta->id, 'model_name' => 'client', 'model_id' => $cuenta->model_id,
            'is_provisorio' => 0, 'description' => 'Prueba de crédito', 'current_date' => $actual,
            'created_at' => '2026-03-01', 'current_acount_payment_methods' => [[
                'current_acount_payment_method_id' => CurrentAcountPaymentMethod::where('name', 'Efectivo')->firstOrFail()->id,
                'amount' => 25, 'moneda_id' => 1]]];
    }

    public function test_validacion_ambigua_identifica_movimiento_y_revierte_endpoint()
    {
        foreach (['chequear', 'pago', 'nota_credito'] as $camino) {
            $cuenta = $this->cuenta();
            $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
            $credito = $this->movimiento($cuenta, -75, 10, '2026-02-01 10:00:00');
            $antes = $this->foto($cuenta);
            if ($camino === 'chequear') {
                $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
            } elseif ($camino === 'pago') {
                $respuesta = $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta, 1));
            } else {
                $respuesta = $this->postJson('api/current-acount/nota-credito', ['credit_account_id' => $cuenta->id,
                    'model_name' => 'client', 'model_id' => $cuenta->model_id, 'form' => ['nota_credito' => 25, 'description' => 'Prueba']]);
            }
            $respuesta->assertStatus(422);
            $this->assertStringContainsString('#'.$credito->id, $respuesta->json('message'));
            $this->assertStringContainsString('Revisá', $respuesta->json('message'));
            $this->assertEquals($antes, $this->foto($cuenta), 'El rechazo debe revertir pago, NC, saldos y estados.');
        }
    }

    public function test_destinos_invalidos_anclas_y_moneda_cruzada_se_rechazan_sin_reasignar()
    {
        foreach (['dirigido_ajeno', 'dirigido_credito', 'dirigido_singleton_conflicto', 'lista_invalida', 'ancla_cero', 'debe_cero_haber', 'moneda', 'venta_moneda', 'pivote_ajeno', 'pivote_provisorio'] as $caso) {
            $cuenta = $this->cuenta();
            $debito = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
            $credito = $this->movimiento($cuenta, -50, null, '2026-02-01 10:00:00');
            $pago = $this->movimiento($cuenta, null, 25, '2026-03-01 10:00:00');
            if ($caso === 'dirigido_ajeno' || $caso === 'pivote_ajeno') {
                $ajeno = $this->movimiento($this->cuenta(2), 100, null, '2026-01-01 10:00:00');
                if ($caso === 'dirigido_ajeno') {
                    $pago->update(['to_pay_id' => $ajeno->id]);
                } else {
                    $debito->pagado_por()->attach($ajeno->id, ['pagado' => 1, 'total_pago' => 1]);
                }
            } elseif ($caso === 'dirigido_credito') {
                $pago->update(['to_pay_id' => $credito->id]);
            } elseif ($caso === 'dirigido_singleton_conflicto') {
                $pago->update(['to_pay_id' => $debito->id, 'to_pay_ids' => [$credito->id]]);
            } elseif ($caso === 'lista_invalida') {
                $pago->update(['to_pay_ids' => 'selección ilegible']);
            } elseif ($caso === 'pivote_provisorio') {
                $provisorio = $this->movimiento($cuenta, null, 25, '2026-01-01 10:00:00', ['is_provisorio' => 1]);
                $debito->pagado_por()->attach($provisorio->id, ['pagado' => 1, 'total_pago' => 25]);
            } elseif ($caso === 'ancla_cero') {
                $this->movimiento($cuenta, null, null, '2026-02-02 10:00:00', ['saldo' => 0]);
            } elseif ($caso === 'debe_cero_haber') {
                $pago->update(['debe' => 0]);
            } elseif ($caso === 'moneda') {
                $credito->update(['moneda_id' => 2]);
            } else {
                $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id, 'moneda_id' => 2, 'total' => -50]);
                $credito->update(['sale_id' => $venta->id, 'moneda_id' => null]);
            }
            $foto = $this->foto($cuenta);
            $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
            $respuesta->assertStatus(422);
            $this->assertStringContainsString('soporte', $respuesta->json('message'));
            $this->assertEquals($foto, $this->foto($cuenta), $caso.' no debe alterar el historial.');
        }
    }

    public function test_nota_debito_nueva_consume_credito_disponible_y_nc_mantiene_destino()
    {
        $cuenta = $this->cuenta();
        $this->movimiento($cuenta, -50, null, '2026-01-01 10:00:00');
        $respuesta = $this->postJson('api/current-acount/nota-debito', ['credit_account_id' => $cuenta->id,
            'model_name' => 'client', 'model_id' => $cuenta->model_id, 'debe' => 100, 'description' => 'Prueba']);
        $respuesta->assertSuccessful();
        $debito = CurrentAcount::where('credit_account_id', $cuenta->id)->where('debe', 100)->firstOrFail();
        $this->assertEquals(50, $debito->pagandose);
        $this->assertSame('pagandose', $debito->status);
        $nc = CurrentAcountHelper::notaCredito($cuenta->id, 50, 'NC real', 'client', $cuenta->model_id, null, null, null, $debito->id);
        $this->assertSame('nota_credito', $nc->status);
        $this->assertSame('pagado', $debito->fresh()->status);
        $this->assertEquals($debito->id, DB::table('pagado_por')->where('haber_id', $nc->id)->value('debe_id'));
    }

    public function test_comision_al_saldar_conserva_importe_y_no_duplica_liquidacion()
    {
        $cuenta = $this->cuenta();
        $seller = Seller::create(['user_id' => 500, 'num' => 999145, 'name' => 'Comisión crédito histórico', 'commission_after_pay_sale' => 1, 'percentage_commission' => 10]);
        $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id, 'moneda_id' => 1, 'total' => 100]);
        $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00', ['sale_id' => $venta->id]);
        $credito = $this->movimiento($cuenta, -100, null, '2026-02-01 10:00:00');
        $comision = SellerCommission::create(['user_id' => 500, 'seller_id' => $seller->id, 'sale_id' => $venta->id,
            'moneda_id' => 1, 'debe' => 10, 'status' => 'inactive']);
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertSame('active', $comision->fresh()->status);
        $this->assertEquals(10, $comision->fresh()->debe);
        $this->assertEquals(1, $comision->pagada_por()->count());
        CurrentAcountHelper::checkPagos($cuenta->id);
        $this->assertEquals(1, $comision->pagada_por()->count());
        $credito->update(['debe' => -50]);
        CurrentAcountHelper::checkPagos($cuenta->id);
        $this->assertSame('inactive', $comision->fresh()->status);
        $this->assertEquals(0, $comision->pagada_por()->count());
        $this->assertEquals(10, $comision->fresh()->debe);
    }

    public function test_devolucion_y_actualizacion_de_precios_devuelven_validacion_con_rollback()
    {
        foreach (['devolucion', 'precios'] as $camino) {
            $cuenta = $this->cuenta();
            $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id, 'moneda_id' => 1,
                'total' => 100, 'save_current_acount' => 1, 'terminada' => 1]);
            $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00', ['sale_id' => $venta->id]);
            $credito = $this->movimiento($cuenta, -50, 10, '2026-02-01 10:00:00');
            $foto = $this->foto($cuenta);
            if ($camino === 'devolucion') {
                $respuesta = $this->postJson('api/devoluciones/', ['sale_id' => $venta->id,
                    'client_id' => $cuenta->model_id, 'generar_current_acount' => true, 'total_devolucion' => 25,
                    'observaciones' => 'Prueba', 'items' => [], 'descriptions' => [], 'discounts' => [], 'surchages' => [],
                    'regresar_stock' => false, 'update_unidades_devueltas' => false, 'facturar_nota_credito' => null]);
            } else {
                $respuesta = $this->putJson('api/sale/update-prices/'.$venta->id, ['items' => []]);
            }
            $respuesta->assertStatus(422);
            $this->assertStringContainsString('#'.$credito->id, $respuesta->json('message'));
            $this->assertEquals($foto, $this->foto($cuenta), $camino.' debe revertir todas las escrituras.');
            $this->assertEquals(100, $venta->fresh()->total);
        }
    }

    public function test_baja_de_pago_y_nc_limpia_fuentes_sin_dejar_imputaciones_huerfanas()
    {
        foreach (['pago', 'nota_credito'] as $tipo) {
            $cuenta = $this->cuenta();
            $debito = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
            $this->movimiento($cuenta, -25, null, '2026-02-01 10:00:00');
            $fuente = $this->movimiento($cuenta, null, 75, '2026-03-01 10:00:00', ['status' => $tipo === 'pago' ? 'pago_from_client' : 'nota_credito']);
            CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
            $this->assertSame('pagado', $debito->fresh()->status);
            $this->deleteJson('api/current-acount/client/'.$fuente->id)->assertSuccessful();
            $this->assertNull($fuente->fresh());
            $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $fuente->id)->count());
            $this->assertEquals(25, $debito->fresh()->pagandose);
            $this->assertEquals(75, $cuenta->fresh()->saldo);
        }
    }

    public function test_baja_de_fuente_que_liquido_comision_no_deja_pivote_huerfano()
    {
        $cuenta = $this->cuenta();
        $seller = Seller::create(['user_id' => 500, 'num' => 999146, 'name' => 'Comisión reemplazo fuente', 'commission_after_pay_sale' => 1, 'percentage_commission' => 10]);
        $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id, 'moneda_id' => 1, 'total' => 100]);
        $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00', ['sale_id' => $venta->id]);
        $this->movimiento($cuenta, -25, null, '2026-02-01 10:00:00');
        $viejo = $this->movimiento($cuenta, null, 75, '2026-03-01 10:00:00');
        $nuevo = $this->movimiento($cuenta, null, 75, '2026-04-01 10:00:00');
        $comision = SellerCommission::create(['user_id' => 500, 'seller_id' => $seller->id, 'sale_id' => $venta->id,
            'moneda_id' => 1, 'debe' => 10, 'status' => 'inactive']);
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->assertEquals([$viejo->id], $comision->pagada_por()->pluck('current_acounts.id')->all());
        $this->deleteJson('api/current-acount/client/'.$viejo->id)->assertSuccessful();
        $this->assertSame('active', $comision->fresh()->status);
        $this->assertEquals(10, $comision->fresh()->debe);
        $this->assertEquals(1, DB::table('current_acount_seller_commission')->where('seller_commission_id', $comision->id)->count());
        $this->assertEquals([$nuevo->id], $comision->pagada_por()->pluck('current_acounts.id')->all());
        CurrentAcountHelper::checkPagos($cuenta->id);
        $this->assertEquals(1, DB::table('current_acount_seller_commission')->where('seller_commission_id', $comision->id)->count());
    }

    public function test_baja_de_un_destino_multiple_conserva_los_otros_destinos()
    {
        $cuenta = $this->cuenta();
        $a = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        $b = $this->movimiento($cuenta, 100, null, '2026-01-02 10:00:00');
        $c = $this->movimiento($cuenta, 100, null, '2026-01-03 10:00:00');
        $this->movimiento($cuenta, -25, null, '2026-02-01 10:00:00');
        $pago = $this->movimiento($cuenta, null, 50, '2026-03-01 10:00:00', ['to_pay_ids' => [$b->id, $c->id]]);
        CurrentAcountHelper::check_saldos_y_pagos($cuenta->id);
        $this->deleteJson('api/current-acount/client/'.$b->id)->assertSuccessful();
        $this->assertEquals($c->id, $pago->fresh()->to_pay_id);
        $this->assertNull($pago->fresh()->to_pay_ids);
        $this->assertEquals(50, $c->fresh()->pagandose);
        $this->assertEquals(25, $a->fresh()->pagandose);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $b->id)->count());
    }

    public function test_editar_y_mover_venta_negativa_reemplaza_fuente_y_reconcilia_ambas_cuentas()
    {
        $cuenta = $this->cuenta();
        $otra = $this->cuenta();
        $a = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        $b = $this->movimiento($otra, 100, null, '2026-01-01 10:00:00');
        $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id, 'moneda_id' => 1,
            'total' => -50, 'save_current_acount' => 1, 'terminada' => 1, 'created_at' => '2026-02-01 10:00:00']);
        (new CurrentAcountFromSaleHelper($venta))->crear_current_acount();
        $viejo = CurrentAcount::where('sale_id', $venta->id)->firstOrFail()->id;
        $venta->update(['total' => -60]);
        SaleHelper::updateCurrentAcountsAndCommissions($venta);
        $this->assertEquals(60, $a->fresh()->pagandose);
        $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $viejo)->count());
        $venta->update(['client_id' => $otra->model_id]);
        SaleHelper::updateCurrentAcountsAndCommissions($venta);
        $this->assertEquals(0, $a->fresh()->pagandose);
        $this->assertSame('sin_pagar', $a->fresh()->status);
        $this->assertEquals(60, $b->fresh()->pagandose);
        $this->assertEquals(100, $cuenta->fresh()->saldo);
        $this->assertEquals(40, $otra->fresh()->saldo);
    }
}
