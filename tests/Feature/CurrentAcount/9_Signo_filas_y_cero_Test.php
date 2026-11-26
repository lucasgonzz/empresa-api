<?php

namespace Tests\Feature\CurrentAcount;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\currentAcount\CurrentAcountPagoAltaHelper;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\CurrentAcount;
use App\Models\CurrentAcountPaymentMethod;
use App\Models\MovimientoCaja;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Misión cc-pivotes-huerfanos-signo-20261125, ampliación del 25/11/2026: casos independientes que no dependen de
 * cómo se calcula el total.
 *  - CA12 por fila: una fila con monto negativo se rechaza aunque el `haber` del pedido sea positivo (el total que
 *    calcula get_haber() ignora las filas <= 0, así que mirar sólo el total no la ve).
 *  - CA11 con cero: CurrentAcountHelper::notaCredito() sigue aceptando 0 (contrato original: Vender, devoluciones e
 *    importaciones pueden llegar con total 0).
 * Los helpers de fixture se copian de 8_Pivotes_huerfanos_y_signo_Test: no hay herencia entre tests.
 */
class Signo_filas_y_cero_Test extends TestCase
{
    use DatabaseTransactions;

    protected function cuenta()
    {
        $user = User::findOrFail(500);
        $this->actingAs($user, 'web');
        $client = Client::create(['name' => 'Signo filas ticket 171', 'user_id' => $user->id]);
        return CreditAccount::create(['model_name' => 'client', 'model_id' => $client->id,
            'moneda_id' => 1, 'user_id' => $user->id, 'saldo' => 0]);
    }

    protected function filas($montos)
    {
        $efectivo = CurrentAcountPaymentMethod::where('name', 'Efectivo')->firstOrFail()->id;
        $filas = [];
        foreach ($montos as $monto) {
            $filas[] = ['current_acount_payment_method_id' => $efectivo, 'amount' => $monto, 'moneda_id' => 1];
        }
        return $filas;
    }

    protected function payload_pago($cuenta, $montos, $extra = [])
    {
        return array_merge(['credit_account_id' => $cuenta->id, 'model_name' => 'client', 'model_id' => $cuenta->model_id,
            'is_provisorio' => 0, 'description' => 'Prueba de signo por fila', 'current_date' => 1,
            'created_at' => '2026-03-01', 'current_acount_payment_methods' => $this->filas($montos)], $extra);
    }

    /** CA12 por fila: una fila negativa con otra positiva y `haber` positivo no crea pago ni caja. */
    public function test_fila_negativa_con_haber_positivo_no_se_registra()
    {
        $cuenta = $this->cuenta();
        $pagos = CurrentAcount::count();
        $cajas = MovimientoCaja::count();
        try {
            CurrentAcountPagoAltaHelper::registrar($this->payload_pago($cuenta, [30, -10], ['haber' => 20]));
            $this->fail('Una fila con monto negativo no debería registrarse aunque el haber sea positivo.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cuenta_corriente', $e->errors());
            $this->assertStringContainsString('El total del pago no puede ser negativo', $e->errors()['cuenta_corriente'][0]);
        }
        $this->assertEquals($pagos, CurrentAcount::count());
        $this->assertEquals($cajas, MovimientoCaja::count());
    }

    /** CA12 por fila: lo mismo con el `haber` negativo y todas las filas positivas. */
    public function test_haber_negativo_con_filas_positivas_no_se_registra()
    {
        $cuenta = $this->cuenta();
        $pagos = CurrentAcount::count();
        try {
            CurrentAcountPagoAltaHelper::registrar($this->payload_pago($cuenta, [30], ['haber' => -5]));
            $this->fail('Un haber negativo no debería registrarse.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cuenta_corriente', $e->errors());
        }
        $this->assertEquals($pagos, CurrentAcount::count());
    }

    /** CA11 con cero: el helper NO rechaza el 0 (contrato original); sólo negativos y no numéricos. */
    public function test_nota_credito_con_cero_por_el_helper_no_lanza_validacion_de_signo()
    {
        $cuenta = $this->cuenta();
        $antes = CurrentAcount::where('credit_account_id', $cuenta->id)->count();
        DB::beginTransaction();
        try {
            $nota = CurrentAcountHelper::notaCredito($cuenta->id, 0, 'cero', 'client', $cuenta->model_id);
            $this->assertNotNull($nota, 'Con total 0 el helper tiene que seguir devolviendo la nota.');
            $this->assertEquals($antes + 1, CurrentAcount::where('credit_account_id', $cuenta->id)->count());
        } finally {
            DB::rollBack();
        }
    }
}
