<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CurrentAcountFromSaleHelper;
use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CreditosHistoricosHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MovimientoCaja;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Misión cc-pivotes-huerfanos-signo-20261125 (ticket 171, San Cayetano).
 *
 * Dos clases de error:
 *  (a) un pivote `pagado_por` con una punta que ya no existe (resto inofensivo de un borrado viejo) se
 *      trataba igual que un cruce real entre cuentas y trababa ventas, pagos, NC y "Chequear saldos";
 *  (b) una NC o un pago con Haber negativo se podían crear (NC N°267 de la cuenta 5377).
 * Los helpers de fixture se copian de Creditos_Historicos_Test: no hay herencia entre tests.
 *
 * Hotfix lateral v4.3.75.1 (San Cayetano, ticket 174): subconjunto de Pivotes_huerfanos_y_signo_Test@c8b5fc2b que aplica
 * sólo a CreditosHistoricosHelper (vía con créditos). Copia textual, sin cambios de aserciones.
 */
class Pivotes_huerfanos_hotfix_Test extends TestCase
{
    use DatabaseTransactions;

    protected function cuenta($moneda = 1)
    {
        $user = User::findOrFail(500);
        $this->actingAs($user, 'web');
        $client = Client::create(['name' => 'Pivotes huérfanos ticket 171', 'user_id' => $user->id]);
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

    protected function foto($cuenta)
    {
        $ids = CurrentAcount::where('credit_account_id', $cuenta->id)->pluck('id');
        return [CurrentAcount::whereIn('id', $ids)->orderBy('id')->get(['id', 'debe', 'haber', 'status', 'pagandose', 'saldo'])->toArray(),
            DB::table('pagado_por')->whereIn('debe_id', $ids)->orderBy('debe_id')->orderBy('haber_id')->get(['debe_id', 'haber_id', 'pagado', 'total_pago'])->map(function ($r) { return (array) $r; })->all()];
    }

    /** Fixture "Ajeno": cuenta A (D1 100, D2 200, C -50, P haber 25; saldo 225) y cuenta X de otro cliente con DX 100. */
    protected function cuenta_ajeno()
    {
        $cuenta = $this->cuenta();
        $m = [];
        $m['D1'] = $this->movimiento($cuenta, 100, null, '2026-01-01 10:00:00');
        $m['D2'] = $this->movimiento($cuenta, 200, null, '2026-01-15 10:00:00');
        $m['C'] = $this->movimiento($cuenta, -50, null, '2026-02-01 10:00:00');
        $m['P'] = $this->movimiento($cuenta, null, 25, '2026-03-01 10:00:00');
        $cuenta_x = $this->cuenta();
        $dx = $this->movimiento($cuenta_x, 100, null, '2026-01-10 10:00:00');
        $cuenta->update(['saldo' => 225]);
        return [$cuenta, $m, $cuenta_x, $dx];
    }

    /** Pivotes de los movimientos dados como [debe_id, haber_id, pagado], ordenados, para comparar exactos. */
    protected function pivotes_de($movimientos)
    {
        $ids = array_map(function ($mov) { return $mov->id; }, $movimientos);
        return DB::table('pagado_por')->whereIn('debe_id', $ids)->orderBy('debe_id')->orderBy('haber_id')
            ->get(['debe_id', 'haber_id', 'pagado'])->map(function ($r) { return [(int) $r->debe_id, (int) $r->haber_id, (float) $r->pagado]; })->all();
    }

    protected function pivote($debe_id, $haber_id, $pagado)
    {
        DB::table('pagado_por')->insert(['debe_id' => $debe_id, 'haber_id' => $haber_id, 'pagado' => $pagado, 'total_pago' => $pagado]);
    }

    protected function id_inexistente()
    {
        return CurrentAcount::max('id') + 1000;
    }

    protected function payload_pago($cuenta, $actual, $monto = 25)
    {
        return ['credit_account_id' => $cuenta->id, 'model_name' => 'client', 'model_id' => $cuenta->model_id,
            'is_provisorio' => 0, 'description' => 'Prueba de crédito', 'current_date' => $actual,
            'created_at' => '2026-03-01', 'current_acount_payment_methods' => [[
                'current_acount_payment_method_id' => CurrentAcountPaymentMethod::where('name', 'Efectivo')->firstOrFail()->id,
                'amount' => $monto, 'moneda_id' => 1]]];
    }

    /** CA1: huérfano por la punta de deuda borrada (caso San Cayetano 119 / 2471). */
    public function test_pivote_huerfano_de_una_deuda_borrada_no_bloquea_y_se_descarta()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $x = $this->id_inexistente();
        $this->pivote($x, $m['P']->id, 25);
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(200);
        $this->assertEquals([[$m['D1']->id, $m['C']->id, 50.0], [$m['D1']->id, $m['P']->id, 25.0]], $this->pivotes_de($m));
        $this->assertSame('pagandose', $m['D1']->fresh()->status);
        $this->assertEquals(75, $m['D1']->fresh()->pagandose);
        $this->assertSame('sin_pagar', $m['D2']->fresh()->status);
        $this->assertEquals(0, $m['D2']->fresh()->pagandose);
        $this->assertEquals(225, $cuenta->fresh()->saldo);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $x)->count());
        $this->assertEquals(100, $m['D1']->fresh()->debe);
        $this->assertEquals(25, $m['P']->fresh()->haber);
    }

    /** CA2: huérfano por la punta de pago borrado; el débito que figuraba pagado vuelve a su estado real. */
    public function test_pivote_huerfano_de_un_pago_borrado_no_bloquea_y_corrige_el_estado()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $x = $this->id_inexistente();
        $m['D2']->update(['status' => 'pagado', 'pagandose' => 200]);
        $this->pivote($m['D2']->id, $x, 200);
        $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);
        $this->assertSame('sin_pagar', $m['D2']->fresh()->status);
        $this->assertEquals(0, $m['D2']->fresh()->pagandose);
        $this->assertEquals([[$m['D1']->id, $m['C']->id, 50.0], [$m['D1']->id, $m['P']->id, 25.0]], $this->pivotes_de($m));
        $this->assertEquals(0, DB::table('pagado_por')->where('haber_id', $x)->count());
        $this->assertEquals(225, $cuenta->fresh()->saldo);
    }

    /** CA3: plan() informa el huérfano en una clave nueva y no escribe nada. */
    public function test_plan_informa_los_pivotes_huerfanos_sin_escribir()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $sin = CreditosHistoricosHelper::plan($cuenta);
        $this->assertArrayHasKey('pivotes_huerfanos', $sin);
        $this->assertSame([], $sin['pivotes_huerfanos']);
        $x = $this->id_inexistente();
        $this->pivote($x, $m['P']->id, 25);
        $foto = $this->foto($cuenta);
        $plan = CreditosHistoricosHelper::plan($cuenta);
        $this->assertSame([['debe_id' => $x, 'haber_id' => $m['P']->id, 'pagado' => 25.0]], $plan['pivotes_huerfanos']);
        $this->assertArrayHasKey('destinos_ignorados', $plan);
        $this->assertArrayHasKey('arrastre_legado', $plan);
        $this->assertEquals($foto, $this->foto($cuenta), 'Simular no escribe.');
        $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $x)->count(), 'La fila huérfana sigue ahí.');
    }

    /** CA4: la venta a CC y el pago, que el rechazo bloqueaba, se registran y limpian el huérfano. */
    public function test_venta_y_pago_en_cuenta_con_pivote_huerfano_se_registran()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $x = $this->id_inexistente();
        $this->pivote($x, $m['P']->id, 25);
        $venta = Sale::create(['user_id' => 500, 'client_id' => $cuenta->model_id,
            'moneda_id' => 1, 'total' => 40, 'terminada' => 1, 'created_at' => '2026-11-18 10:00:00']);
        (new CurrentAcountFromSaleHelper($venta))->crear_current_acount();
        $debito = CurrentAcount::where('sale_id', $venta->id)->firstOrFail();
        $this->assertEquals(265, $cuenta->fresh()->saldo);
        $this->assertSame('pagandose', $m['D1']->fresh()->status);
        $this->assertEquals(75, $m['D1']->fresh()->pagandose);
        $this->assertSame('sin_pagar', $debito->fresh()->status);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $x)->count());

        list($cuenta2, $m2) = $this->cuenta_ajeno();
        $x2 = $this->id_inexistente();
        $this->pivote($x2, $m2['P']->id, 25);
        $respuesta = $this->postJson('api/current-acount/pago', $this->payload_pago($cuenta2, 1));
        $respuesta->assertStatus(201);
        $this->assertSame('pagado', $m2['D1']->fresh()->status);
        $this->assertEquals(100, $m2['D1']->fresh()->pagandose);
        $this->assertSame('sin_pagar', $m2['D2']->fresh()->status);
        $this->assertEquals(200, $cuenta2->fresh()->saldo);
        $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $x2)->count());
    }

    /** CA5: recalcular de nuevo no cambia nada. */
    public function test_recalculo_con_pivote_huerfano_es_idempotente()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $this->pivote($this->id_inexistente(), $m['P']->id, 25);
        $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);
        $primera = $this->foto($cuenta);
        $this->getJson('api/check-saldos/'.$cuenta->id)->assertStatus(200);
        $this->assertEquals($primera, $this->foto($cuenta));
    }

    /** CA6: un cruce real sigue rechazado, y el mensaje dice qué movimientos y qué cuenta. */
    public function test_cruce_real_sigue_rechazado_e_identifica_movimientos_y_cuenta()
    {
        list($cuenta, $m, $cuenta_x, $dx) = $this->cuenta_ajeno();
        $this->pivote($dx->id, $m['P']->id, 25);
        $foto = $this->foto($cuenta);
        $foto_x = $this->foto($cuenta_x);
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(422);
        $mensaje = $respuesta->json('message');
        $this->assertStringContainsString('imputaciones vinculadas con otra cuenta', $mensaje);
        $this->assertStringContainsString('#'.$m['P']->id, $mensaje);
        $this->assertStringContainsString('#'.$dx->id, $mensaje);
        $this->assertStringContainsString('cuenta corriente #'.$cuenta_x->id, $mensaje);
        $this->assertStringContainsString('soporte', $mensaje);
        $this->assertEquals($foto, $this->foto($cuenta));
        $this->assertEquals($foto_x, $this->foto($cuenta_x));
        $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $dx->id)->where('haber_id', $m['P']->id)->count());
    }

    /** CA7: cruce y huérfano juntos se rechazan por el cruce y NO se borra nada. */
    public function test_cruce_y_huerfano_juntos_rechazan_sin_borrar_el_huerfano()
    {
        list($cuenta, $m, $cuenta_x, $dx) = $this->cuenta_ajeno();
        $x = $this->id_inexistente();
        $this->pivote($x, $m['P']->id, 25);
        $this->pivote($dx->id, $m['P']->id, 25);
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(422);
        $mensaje = $respuesta->json('message');
        $this->assertStringContainsString('imputaciones vinculadas con otra cuenta', $mensaje);
        $this->assertStringContainsString('#'.$dx->id, $mensaje);
        $this->assertStringContainsString('cuenta corriente #'.$cuenta_x->id, $mensaje);
        $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $x)->count(), 'El huérfano no se borra si la cuenta se rechaza.');
        $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $dx->id)->count());
    }

    /** CA8: el provisorio de la propia cuenta se nombra como provisorio, con los ids. */
    public function test_pivote_con_provisorio_dice_que_es_provisorio()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $provisorio = $this->movimiento($cuenta, null, 25, '2026-03-05 10:00:00', ['is_provisorio' => 1]);
        $this->pivote($m['D1']->id, $provisorio->id, 25);
        $foto = $this->foto($cuenta);
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(422);
        $mensaje = $respuesta->json('message');
        $this->assertStringContainsString('imputaciones vinculadas con un movimiento provisorio', $mensaje);
        $this->assertStringContainsString('#'.$m['D1']->id, $mensaje);
        $this->assertStringContainsString('#'.$provisorio->id, $mensaje);
        $this->assertStringContainsString('soporte', $mensaje);
        $this->assertEquals($foto, $this->foto($cuenta));
    }

    /** CA9: el Haber negativo se informa como signo invertido; Debe+Haber conserva el texto de siempre. */
    public function test_haber_negativo_se_informa_como_signo_invertido()
    {
        list($cuenta, $m) = $this->cuenta_ajeno();
        $negativo = $this->movimiento($cuenta, null, -10, '2026-03-10 10:00:00', ['detalle' => 'Pago']);
        $foto = $this->foto($cuenta);
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(422);
        $mensaje = $respuesta->json('message');
        foreach (['importes incompatibles', 'Haber es negativo', '-10,00', 'signo invertido', '#'.$negativo->id, 'soporte'] as $fragmento) {
            $this->assertStringContainsString($fragmento, $mensaje, $fragmento);
        }
        $this->assertEquals($foto, $this->foto($cuenta));

        $negativo->delete();
        $mixto = $this->movimiento($cuenta, 0, 25, '2026-03-11 10:00:00');
        $respuesta = $this->getJson('api/check-saldos/'.$cuenta->id);
        $respuesta->assertStatus(422);
        $this->assertStringContainsString('importes incompatibles', $respuesta->json('message'));
        $this->assertStringContainsString('#'.$mixto->id, $respuesta->json('message'));
        $this->assertStringNotContainsString('Haber es negativo', $respuesta->json('message'));
    }

    /** Cantidad de movimientos que hace saltar el límite de 65.535 placeholders de MariaDB con la guarda vieja (4 por id). */
    const CUENTA_GRANDE = 16400;

    /**
     * Cuenta con N débitos cargados en lote (más un pago, un huérfano y, si se pide, un cruce con otra cuenta).
     * Devuelve [cuenta, ids de débitos, pago, cuenta_x, débito de x].
     */
    protected function cuenta_grande($con_credito)
    {
        $cuenta = $this->cuenta();
        $filas = [];
        for ($i = 0; $i < self::CUENTA_GRANDE; $i++) {
            $filas[] = ['credit_account_id' => $cuenta->id, 'client_id' => $cuenta->model_id, 'user_id' => $cuenta->user_id,
                'moneda_id' => 1, 'debe' => 10, 'haber' => null, 'pagandose' => 0, 'is_provisorio' => 0,
                'detalle' => 'Débito masivo', 'status' => 'sin_pagar', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'];
        }
        foreach (array_chunk($filas, 1000) as $lote) {
            DB::table('current_acounts')->insert($lote);
        }
        $debitos = CurrentAcount::where('credit_account_id', $cuenta->id)->orderBy('id')->pluck('id')->all();
        $pago = $this->movimiento($cuenta, null, 25, '2026-03-01 10:00:00');
        if ($con_credito) {
            $this->movimiento($cuenta, -50, null, '2026-02-01 10:00:00');
        }
        $cuenta_x = $this->cuenta();
        $dx = $this->movimiento($cuenta_x, 100, null, '2026-01-10 10:00:00');
        return [$cuenta, $debitos, $pago, $cuenta_x, $dx];
    }

    /** Cuenta los placeholders de cada consulta que dispara $accion (el límite de MariaDB es 65.535: error 1390). */
    protected function max_bindings($accion)
    {
        $max = 0;
        DB::listen(function ($query) use (&$max) { $max = max($max, count($query->bindings)); });
        $accion();
        return $max;
    }

    /** Mismo defecto en la vía con créditos: plan() y aplicar() sobre una cuenta enorme, con huérfano tolerado. */
    public function test_el_plan_y_la_aplicacion_con_creditos_funcionan_con_una_cuenta_enorme()
    {
        list($cuenta, $debitos, $pago, $cuenta_x, $dx) = $this->cuenta_grande(true);
        $fantasma = $this->id_inexistente();
        $this->pivote($fantasma, $pago->id, 25);
        $plan = null;
        $max = $this->max_bindings(function () use ($cuenta, &$plan) { $plan = CreditosHistoricosHelper::plan($cuenta); });
        $this->assertSame([['debe_id' => $fantasma, 'haber_id' => $pago->id, 'pagado' => 25.0]], $plan['pivotes_huerfanos']);
        $this->assertLessThan(100, $max, 'La cantidad de placeholders no puede crecer con los movimientos de la cuenta.');
        DB::beginTransaction();
        try {
            $max = $this->max_bindings(function () use ($cuenta) { CreditosHistoricosHelper::aplicar($cuenta); });
            $this->assertLessThan(1000, $max, 'El borrado de aplicar() tampoco puede crecer con los movimientos.');
            $this->assertEquals(0, DB::table('pagado_por')->where('debe_id', $fantasma)->count());
        } finally {
            DB::rollBack();
        }
    }

    /** Aislamiento en cuenta enorme: un cruce con otra cuenta, de cualquiera de los dos lados, se rechaza y no se borra nada. */
    public function test_el_cruce_y_el_provisorio_se_rechazan_en_una_cuenta_enorme_por_ambos_lados()
    {
        list($cuenta, $debitos, $pago, $cuenta_x, $dx) = $this->cuenta_grande(false);
        $px = $this->movimiento($cuenta_x, null, 30, '2026-02-01 10:00:00');
        $provisorio = $this->movimiento($cuenta, null, 5, '2026-03-02 10:00:00', ['is_provisorio' => 1]);
        $casos = [
            'cruce del lado deuda' => [[$debitos[0], $px->id, 30], 'imputaciones vinculadas con otra cuenta', '#'.$px->id],
            'cruce del lado pago' => [[$dx->id, $pago->id, 25], 'imputaciones vinculadas con otra cuenta', '#'.$dx->id],
            'provisorio' => [[$debitos[1], $provisorio->id, 5], 'imputaciones vinculadas con un movimiento provisorio', '#'.$provisorio->id],
        ];
        foreach ($casos as $nombre => $caso) {
            list($fila, $texto, $id) = $caso;
            $this->pivote($fila[0], $fila[1], $fila[2]);
            try {
                CreditosHistoricosHelper::verificar_vinculos($cuenta);
                $this->fail('Tenía que rechazar: '.$nombre);
            } catch (ValidationException $e) {
                $mensaje = $e->errors()['cuenta_corriente'][0];
                $this->assertStringContainsString($texto, $mensaje, $nombre);
                $this->assertStringContainsString($id, $mensaje, $nombre);
            }
            $this->assertEquals(1, DB::table('pagado_por')->where('debe_id', $fila[0])->where('haber_id', $fila[1])->count(), $nombre);
            DB::table('pagado_por')->where('debe_id', $fila[0])->where('haber_id', $fila[1])->delete();
        }
    }
}
