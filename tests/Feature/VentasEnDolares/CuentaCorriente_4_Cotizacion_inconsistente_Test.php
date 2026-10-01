<?php

namespace Tests\Feature\VentasEnDolares;

use App\Models\CurrentAcount;
use App\Models\MovimientoCaja;
use Illuminate\Support\Facades\DB;

/**
 * Pagos de cuenta corriente con DATOS DE COTIZACIÓN INCONSISTENTES: `amount_cotizado` que no cierra
 * con `amount / cotizacion`, cotización 0 o ausente, montos 0 o negativos, `haber` del payload que no
 * coincide con las filas.
 *
 * 🔴 Estos tests NO arreglan nada: fijan el comportamiento real del back frente a datos que la SPA
 * normalmente no manda pero que llegan por una integración, una PWA vieja o un bug del front. El
 * criterio de cada uno es una INVARIANTE que se deriva del código y de sus docblocks:
 *
 *  - `CurrentAcountPagoAltaHelper::get_haber()` dice que toma el monto cotizado "cuando la fila vino
 *    en otra moneda": una fila cruzada tiene que valer `amount / cotizacion` (fila en pesos sobre
 *    cuenta en dólares) o `amount x cotizacion` (fila en dólares sobre cuenta en pesos), y una fila
 *    en la moneda de la cuenta vale su `amount`.
 *  - Un pago nunca puede AUMENTAR la deuda ni dejar un ingreso NEGATIVO en una caja.
 *
 * Cuando el back acepta el dato inconsistente y lo guarda, el test queda ROJO con
 * `@group hallazgo-moneda` y el mensaje describe lo que quedó guardado. Cuando el back rebota
 * (4xx) o el dato es inofensivo, el test pasa.
 *
 * @group ventas-en-dolares
 * @group cuenta-corriente-monedas
 */
class CuentaCorriente_4_Cotizacion_inconsistente_Test extends CuentaCorriente_Base
{
    /**
     * Arma una cuenta en dólares con 100 USD de deuda (el caso "pago en pesos sobre deuda en dólares",
     * que es el que más se cotiza en 2R) y devuelve [cliente, cuenta_dolares, cuenta_pesos].
     *
     * @return array
     */
    protected function deuda_de_100_dolares()
    {
        $cliente = $this->cliente_nuevo();
        $dolares = $this->cuenta($cliente, self::DOLARES);
        $pesos = $this->cuenta($cliente, self::PESOS);

        $this->vender($cliente, self::DOLARES, 100);

        return [$cliente, $dolares, $pesos];
    }

    /**
     * Afirma que, si el back aceptó el pago, el `haber` guardado es el que corresponde a la fila
     * cruzada; si lo rebotó (4xx), que no dejó nada escrito.
     *
     * @param \Illuminate\Testing\TestResponse $response
     * @param \App\Models\Client $cliente
     * @param \App\Models\CreditAccount $cuenta
     * @param float $haber_correcto
     * @param float $saldo_previo
     * @param int $caja_desde
     * @param string $que
     * @return void
     */
    protected function assertRebotaOGuardaElHaberCorrecto($response, $cliente, $cuenta, $haber_correcto, $saldo_previo, $caja_desde, $que)
    {
        if ($response->getStatusCode() >= 400) {

            $this->assertEquals(
                0,
                CurrentAcount::where('client_id', $cliente->id)->whereNotNull('haber')->count(),
                'El pago se rechazó ('.$response->getStatusCode().') pero dejó un movimiento de cuenta corriente.'
            );
            $this->assertEquals(0, MovimientoCaja::where('id', '>', $caja_desde)->count(), 'El pago se rechazó pero dejó movimientos de caja.');
            $this->assertMonto($saldo_previo, $this->saldo_de($cuenta));

            return;
        }

        $pago = CurrentAcount::find($response->json('current_acount.id'));

        $this->assertMonto(
            $haber_correcto,
            $pago->haber,
            $que.' El back lo aceptó ('.$response->getStatusCode().') y guardó haber = '.$pago->haber.' en la cuenta de moneda '.$cuenta->moneda_id
            .' (lo correcto era '.$haber_correcto.'). Saldo de la cuenta después: '.$this->saldo_de($cuenta).'.'
        );
    }

    // ------------------------------------------------------------------------------------------
    // amount_cotizado / cotizacion
    // ------------------------------------------------------------------------------------------

    /**
     * Regla: una fila en PESOS sobre una cuenta en DÓLARES que llega SIN `amount_cotizado` (vacío) no
     * puede valer su monto nominal en dólares. 120000 ARS a 1200 son 100 USD; contar 120000 como si
     * fueran dólares dejaría la cuenta en -119900 USD.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_fila_cruzada_sin_amount_cotizado_no_vale_su_monto_nominal()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200, ['amount_cotizado' => '']),
        ]);

        $this->assertRebotaOGuardaElHaberCorrecto($response, $cliente, $dolares, 100, 100, $desde, 'Fila en pesos sin amount_cotizado.');
    }

    /**
     * Regla: idem con `amount_cotizado = 0` y `cotizacion = 0` (lo que queda si el operador borra la
     * cotización): dividir por 0 en el front deja el cotizado en 0 o vacío.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_fila_cruzada_con_cotizacion_cero_no_vale_su_monto_nominal()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 0, ['cotizacion' => 0, 'amount_cotizado' => 0]),
        ]);

        // Con cotización 0 no hay "haber correcto": lo único aceptable es rebotar.
        if ($response->getStatusCode() < 400) {

            $pago = CurrentAcount::find($response->json('current_acount.id'));

            $this->fail(
                'Un pago con cotización 0 se aceptó ('.$response->getStatusCode().') y guardó haber = '.$pago->haber.' en la cuenta en dólares (deuda: 100 USD). '
                .'Saldo de la cuenta después: '.$this->saldo_de($dolares).'.'
            );
        }

        $this->assertMonto(100, $this->saldo_de($dolares));
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count());
    }

    /**
     * Regla: una fila cuyo `amount_cotizado` NO cierra con `amount / cotizacion` (500 USD para
     * 120000 ARS a 1200, cuando son 100) no puede mandar en el haber: el back tiene que rebotar o
     * recalcular. Si guarda el 500 tal cual, un cliente que debe 100 USD queda con 400 USD a favor.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function un_amount_cotizado_que_no_cierra_con_la_cotizacion_no_manda_en_el_haber()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1200, ['amount_cotizado' => 500]),
        ]);

        $this->assertRebotaOGuardaElHaberCorrecto($response, $cliente, $dolares, 100, 100, $desde, 'amount_cotizado = 500 con cotización 1200 y amount 120000.');
    }

    /**
     * Regla: la fila EN LA MONEDA DE LA CUENTA vale su `amount` aunque traiga un `amount_cotizado`
     * viejo. `get_haber()` dice que toma el cotizado "cuando la fila vino en otra moneda"; el front
     * mismo avisa que un cotizado que queda de otra moneda "duplica el total repartido"
     * (`PaymentMethodsStep.vue::completar()`). 100 USD sobre una cuenta en dólares con un cotizado
     * residual de 120000 no pueden pagar 120000 dólares.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function un_amount_cotizado_residual_en_una_fila_de_la_moneda_de_la_cuenta_no_cuenta()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            // Fila en DÓLARES sobre cuenta en dólares, con el cotizado que quedó de una fila en pesos.
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 60, $this->caja_dolares, 1200, ['amount_cotizado' => 72000]),
        ]);

        $this->assertRebotaOGuardaElHaberCorrecto($response, $cliente, $dolares, 60, 100, $desde, 'Fila en dólares sobre cuenta en dólares con amount_cotizado residual.');
    }

    /**
     * Regla: una cotización de 1 entre pesos y dólares ("un peso = un dólar") no se acepta aunque sea
     * consistente consigo misma (`120000 / 1 = 120000`): es lo que queda cuando la cotización no se
     * cargó, y acredita 120000 dólares donde se debían 100. CASO REAL en 2R (14/8/2026, Pago N°303):
     * $126.900 con cotización 1,00 sobre una cuenta en dólares dejaron a un cliente con -126.899,97 USD.
     *
     * @group ventas-en-dolares
     * @test
     */
    public function una_cotizacion_de_uno_entre_pesos_y_dolares_no_se_acepta()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, 1, ['cotizacion' => 1, 'amount_cotizado' => 120000]),
        ]);

        $response->assertStatus(422);

        $this->assertEquals(0, CurrentAcount::where('client_id', $cliente->id)->whereNotNull('haber')->count(), 'El pago rechazado no puede dejar un movimiento.');
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count(), 'El pago rechazado no puede dejar movimientos de caja.');
        $this->assertMonto(100, $this->saldo_de($dolares));
    }

    /**
     * Regla: cotización NEGATIVA. Con cotización -1200 no existe un haber correcto (el front
     * calcularía un cotizado negativo): lo único aceptable es rebotar. Si el back lo acepta, la fila
     * termina valiendo su monto nominal (como una fila sin cotizar) o un haber negativo.
     *
     * @group hallazgo-moneda
     * @test
     */
    public function una_cotizacion_negativa_no_se_acepta()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::PESOS, 120000, $this->caja_pesos, -1200, ['cotizacion' => -1200, 'amount_cotizado' => -100]),
        ]);

        if ($response->getStatusCode() < 400) {

            $pago = CurrentAcount::find($response->json('current_acount.id'));

            $this->fail(
                'Un pago con cotización -1200 se aceptó ('.$response->getStatusCode().') y guardó haber = '.$pago->haber.' en la cuenta en dólares (deuda: 100 USD). '
                .'Saldo de la cuenta después: '.$this->saldo_de($dolares).'.'
            );
        }

        $this->assertMonto(100, $this->saldo_de($dolares));
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count());
    }

    // ------------------------------------------------------------------------------------------
    // Montos 0, vacíos y negativos
    // ------------------------------------------------------------------------------------------

    /**
     * Regla: un pago de MONTO 0 (todas las filas en 0) no mueve nada: ni el saldo, ni la imputación,
     * ni las cajas. Puede rebotar o quedar registrado, pero no puede tener efecto económico.
     *
     * @test
     */
    public function un_pago_de_monto_cero_no_tiene_efecto_economico()
    {
        list($cliente, $dolares, $pesos) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();
        $imputaciones_antes = DB::table('pagado_por')->count();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 0, $this->caja_dolares),
        ]);

        $this->assertMonto(100, $this->saldo_de($dolares), 'Un pago de 0 movió el saldo (status '.$response->getStatusCode().').');
        $this->assertMonto(0, $this->saldo_de_caja_bd($this->caja_dolares), 'Un pago de 0 movió el saldo de la caja.');
        $this->assertEquals(
            0,
            MovimientoCaja::where('id', '>', $desde)->where(function ($q) {
                $q->where('ingreso', '<>', 0)->orWhere('egreso', '<>', 0);
            })->count(),
            'Un pago de 0 dejó un movimiento de caja con importe.'
        );
        $this->assertEquals($imputaciones_antes, DB::table('pagado_por')->count(), 'Un pago de 0 imputó a un débito.');
        $this->assertMonto(0, $this->saldo_de($pesos));
    }

    /**
     * Regla: un pago con monto NEGATIVO no puede aumentar la deuda ni dejar un ingreso negativo en la
     * caja. (Un monto negativo se saltea en la validación de aperturas de caja
     * `cajas_sin_apertura_en_payload()` y en `deberia_haber_impactado_caja()`, pero
     * `attach_payment_methods()` lo adjunta igual.)
     *
     * @group hallazgo-moneda
     * @test
     */
    public function un_pago_con_monto_negativo_no_aumenta_la_deuda_ni_deja_un_ingreso_negativo()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, -40, $this->caja_dolares),
        ]);

        $negativos = MovimientoCaja::where('id', '>', $desde)->where('ingreso', '<', 0)->count();

        $this->assertEquals(
            0,
            $negativos,
            'Un pago de -40 USD (status '.$response->getStatusCode().') dejó un ingreso NEGATIVO en la caja en dólares (saldo de la caja: '.$this->saldo_de_caja_bd($this->caja_dolares).').'
        );

        $this->assertLessThanOrEqual(
            100.01,
            $this->saldo_de($dolares),
            'Un pago de -40 USD (status '.$response->getStatusCode().') AUMENTÓ la deuda en dólares de 100 a '.$this->saldo_de($dolares).'.'
        );
    }

    /**
     * Regla: una fila con monto VACÍO ('') no puede dejar un pago a medias: lo que sea que responda
     * el back (201 sin efecto o error), no quedan movimientos de caja ni el saldo cambia.
     *
     * @test
     */
    public function una_fila_con_monto_vacio_no_deja_nada_a_medias()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $desde = $this->max_id_movimiento_caja();

        $response = $this->postear_pago($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, '', $this->caja_dolares),
        ]);

        $this->assertMonto(100, $this->saldo_de($dolares), 'Un pago con monto vacío movió el saldo (status '.$response->getStatusCode().').');
        $this->assertEquals(0, MovimientoCaja::where('id', '>', $desde)->count(), 'Un pago con monto vacío movió una caja (status '.$response->getStatusCode().').');
    }

    // ------------------------------------------------------------------------------------------
    // haber del payload vs. filas
    // ------------------------------------------------------------------------------------------

    /**
     * Regla: el `haber` que manda el payload NO es la fuente de verdad: el que se guarda es la suma
     * de las filas (`get_haber()`), y la cadena de saldos se recalcula con ese. Un `haber` del
     * payload inflado (999 dólares para una fila de 40) no puede dejar el saldo de la cuenta distinto
     * del que resulta de los movimientos guardados.
     *
     * @test
     */
    public function el_haber_del_payload_no_pisa_la_suma_de_las_filas()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $pago = $this->pagar($cliente, $dolares, [
            $this->fila_de_pago(self::DOLARES, self::DOLARES, 40, $this->caja_dolares),
        ], ['haber' => 999]);

        $this->assertMonto(40, $pago->haber, 'El haber guardado tiene que ser la suma de las filas, no el del payload.');
        $this->assertMonto(60, $this->saldo_de($dolares));
        $this->assertMonto(60, $pago->fresh()->saldo);
        $this->assertCuentaConsistente($dolares);
    }

    /**
     * Regla: una fila con `moneda_id` AUSENTE (null) se trata como fila en la moneda de la cuenta:
     * vale su `amount`, entra a su caja y no cotiza. (Es lo que hace una integración vieja que no
     * conoce las monedas.)
     *
     * @test
     */
    public function una_fila_sin_moneda_vale_su_amount()
    {
        list($cliente, $dolares) = $this->deuda_de_100_dolares();

        $fila = $this->fila_de_pago(self::DOLARES, self::DOLARES, 40, $this->caja_dolares);
        unset($fila['moneda_id'], $fila['cotizacion'], $fila['amount_cotizado']);

        $pago = $this->pagar($cliente, $dolares, [$fila]);

        $this->assertMonto(40, $pago->haber);
        $this->assertMonto(60, $this->saldo_de($dolares));
        $this->assertCuentaConsistente($dolares);
    }
}
