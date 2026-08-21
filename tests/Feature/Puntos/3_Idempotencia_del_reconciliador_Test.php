<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\CurrentAcountHelper;
use App\Http\Controllers\Helpers\puntos\PuntosAcumulacionHelper;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 3 — 🔴 EL TEST MÁS IMPORTANTE DE LOS ONCE.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  QUÉ PROTEGE, Y POR QUÉ ES EL QUE MÁS IMPORTA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `CurrentAcountHelper::checkPagos()` (CurrentAcountHelper.php:579) NO es un evento: es un
 *  RECÁLCULO. Borra todas las imputaciones de `pagado_por` de la cuenta, resetea TODOS los débitos
 *  a `pagandose = 0, status = 'sin_pagar'` y vuelve a correr `CurrentAcountPagoHelper` por CADA
 *  pago. O sea que la transición "el débito llegó a pagado" se dispara N veces por UN solo hecho
 *  económico. Y a esa función la llaman once lugares: alta y baja de pagos, alta y baja de ventas,
 *  restaurar de la papelera, dos jobs de fondo y dos comandos.
 *
 *  Enganchar los puntos en esa transición habría REGALADO PUNTOS INFINITOS: a un cliente al que le
 *  borran un pago viejo se le volverían a otorgar los puntos de todas sus ventas ya saldadas.
 *
 *  Por eso el módulo no escucha un evento: RECONCILIA. Compara lo que corresponde contra lo que ya
 *  está escrito y escribe únicamente la diferencia. Este archivo es el que afirma que eso es
 *  verdad, y lo hace de la única forma en que se puede afirmar: haciendo correr `checkPagos()`
 *  muchas veces y contando filas.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL LOTE SE ACTUALIZA EN EL LUGAR, NO SE ANULA Y SE CREA OTRO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El unique de `movimiento_puntos` es `(sale_id, tipo, price_type_id)` y NO incluye `anulado_at`,
 *  así que una segunda fila 'ganados' para la misma venta y la misma lista viola el índice aunque
 *  la primera esté anulada. El invariante que sostiene el módulo es:
 *
 *    - para cada (venta, lista) hay A LO SUMO UNA fila 'ganados' y A LO SUMO UNA 'revertidos';
 *    - la de 'revertidos' existe SOLO mientras la de 'ganados' está anulada;
 *    - revivir = sacarle el `anulado_at` a la 'ganados' y BORRAR su 'revertidos';
 *    - el saldo es SUM(puntos), sin ninguna condición extra.
 *
 *  Los tests de abajo lo verifican por el ID de la fila, no solo por la cantidad: si el lote se
 *  borrara y se recreara, la cantidad podría dar bien y el id cambiaría.
 */
class Idempotencia_del_reconciliador_Test extends PuntosTestCase
{
    /** Venta A: neto $100.000 al 21% -> 100 puntos. */
    const TOTAL_A = 121000;
    const PUNTOS_A = 100;

    /** Venta B: neto $50.000 al 21% -> 50 puntos. */
    const TOTAL_B = 60500;
    const PUNTOS_B = 50;

    /**
     * 🔴 EL NÚCLEO: `checkPagos()` corre N veces y los puntos de la venta no se mueven.
     *
     * Se lo llama directo diez veces seguidas, que es lo que pasa de verdad en un comercio con
     * movimiento (cada guardado de venta del mismo cliente, cada pago, cada corrida de
     * ProcessCheckSaldosChunk). Si el módulo escuchara la transición en vez de reconciliar, acá
     * habría once lotes o un lote con mil puntos.
     *
     * @group puntos
     * @test
     */
    public function correr_checkpagos_diez_veces_deja_un_solo_lote_con_los_mismos_puntos()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $cuenta = $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_A));

        $this->pagar($cliente, self::TOTAL_A, 0)->assertStatus(201);

        $lote_original = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote_original, 'La venta saldada tiene que tener su lote antes de medir la idempotencia.');
        $this->assertEqualsWithDelta(self::PUNTOS_A, (float) $lote_original->puntos, self::DELTA);

        for ($i = 0; $i < 10; $i++) {
            CurrentAcountHelper::checkPagos($cuenta->id);
        }

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(
            1,
            $ganados,
            'Diez corridas de checkPagos() dejaron más de un movimiento ganados para la misma venta: '.
            'el reconciliador no es idempotente y el comercio está regalando puntos.'
        );

        $lote = $ganados->first();

        $this->assertEquals(
            $lote_original->id,
            $lote->id,
            'El lote cambió de id: se borró y se recreó en vez de dejarse como estaba.'
        );
        $this->assertEqualsWithDelta(
            self::PUNTOS_A,
            (float) $lote->puntos,
            self::DELTA,
            'Los puntos del lote cambiaron después de reconciliar sin que cambiara nada de la venta.'
        );
        $this->assertNull($lote->anulado_at, 'El lote de una venta que sigue saldada no puede quedar anulado.');

        $this->assertEqualsWithDelta(
            self::PUNTOS_A,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo del cliente se movió sin que pasara un solo hecho económico nuevo.'
        );
    }

    /**
     * Lo mismo pero por el punto de entrada por venta, que es el que corre en el camino caliente de
     * guardar una venta (`SaleHelper::attachProperies()` y `SaleController@update`).
     *
     * @group puntos
     * @test
     */
    public function reconciliar_la_misma_venta_muchas_veces_no_escribe_nada_nuevo()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_A));

        $this->pagar($cliente, self::TOTAL_A, 0)->assertStatus(201);

        $lote_original = $this->movimientos_de_la_venta($venta, 'ganados')->first();
        $this->assertNotNull($lote_original);

        $actualizado_at_original = (string) $lote_original->updated_at;

        for ($i = 0; $i < 10; $i++) {
            PuntosAcumulacionHelper::reconciliar_venta($venta->fresh());
        }

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');

        $this->assertCount(1, $ganados, 'Reconciliar diez veces la misma venta creó movimientos de más.');

        $lote = $ganados->first();

        $this->assertEquals($lote_original->id, $lote->id);
        $this->assertEqualsWithDelta(self::PUNTOS_A, (float) $lote->puntos, self::DELTA);

        /*
         * El camino del 99% de las llamadas es el que NO ESCRIBE. Si `updated_at` se movió, es que
         * el reconciliador está reescribiendo la fila en cada una de las N corridas de checkPagos()
         * de cada request: no rompe los números, pero es exactamente lo que este helper existe
         * para no hacer.
         */
        $this->assertEquals(
            $actualizado_at_original,
            (string) $lote->updated_at,
            'El reconciliador reescribió el lote sin que cambiara nada: no está comparando antes de escribir.'
        );
    }

    /**
     * El escenario real que motivó todo: DOS ventas saldadas del mismo cliente, y a una se le borra
     * el pago. `checkPagos()` re-imputa la cuenta entera, así que la venta que sigue saldada vuelve
     * a pasar por la transición "llegó a pagado" — y no puede ganar un punto más.
     *
     * @group puntos
     * @test
     */
    public function borrar_el_pago_de_una_venta_no_le_regala_puntos_a_la_otra()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta_a = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_A));
        $pago_a  = $this->pagar_y_devolver_id($cliente, self::TOTAL_A);

        $venta_b = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_B));
        $pago_b  = $this->pagar_y_devolver_id($cliente, self::TOTAL_B);

        $lote_a = $this->movimientos_de_la_venta($venta_a, 'ganados')->first();
        $lote_b = $this->movimientos_de_la_venta($venta_b, 'ganados')->first();

        $this->assertNotNull($lote_a, 'La venta A tiene que estar saldada y con su lote antes de borrar nada.');
        $this->assertNotNull($lote_b, 'La venta B tiene que estar saldada y con su lote antes de borrar nada.');

        $saldo_previo = $this->saldo_de_puntos($cliente);

        $this->assertEqualsWithDelta(self::PUNTOS_A + self::PUNTOS_B, $saldo_previo, self::DELTA);

        // Se borra el pago de la venta B. checkPagos() re-imputa TODA la cuenta.
        $this->deleteJson('api/current-acount/client/'.$pago_b)->assertStatus(200);

        $ganados_a = $this->movimientos_de_la_venta($venta_a, 'ganados');

        $this->assertCount(
            1,
            $ganados_a,
            'Borrar el pago de OTRA venta le duplicó el lote a la venta A: el reconciliador no es idempotente.'
        );
        $this->assertEquals($ganados_a->first()->id, $lote_a->id, 'El lote de la venta A cambió de id sin motivo.');
        $this->assertEqualsWithDelta(
            self::PUNTOS_A,
            (float) $ganados_a->first()->puntos,
            self::DELTA,
            'A la venta A, que no se tocó, le cambiaron los puntos.'
        );
        $this->assertNull($ganados_a->first()->anulado_at, 'La venta A sigue saldada: su lote no puede quedar anulado.');

        // Y la venta B, que dejó de estar saldada, sí tiene que perder los suyos.
        $lote_b->refresh();

        $this->assertNotNull(
            $lote_b->anulado_at,
            'La venta B dejó de estar saldada al borrarse su pago: su lote tiene que quedar anulado.'
        );

        $this->assertEqualsWithDelta(
            self::PUNTOS_A,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'El saldo tiene que quedar solo con los puntos de la venta A.'
        );

        // El pago de A sigue existiendo: la aserción de arriba no pasó por casualidad.
        $this->assertNotNull(\App\Models\CurrentAcount::find($pago_a));
    }

    /**
     * 🔴 EL CICLO COMPLETO: revertir y revivir.
     *
     *   1. venta saldada -> un lote vivo, sin reverso
     *   2. se borra el pago que la saldaba -> el MISMO lote queda anulado y aparece UN 'revertidos'
     *      por el total otorgado en negativo; el saldo vuelve a cero
     *   3. se vuelve a cobrar -> revive el MISMO lote (mismo id, mismo `anulado_at` en null) y el
     *      'revertidos' SE BORRA; el saldo vuelve a los puntos originales
     *
     * El paso 3 es el que no se puede hacer "revirtiendo y recreando": el unique
     * (sale_id, tipo, price_type_id) no incluye `anulado_at`, así que crear una segunda fila
     * 'ganados' tira clave duplicada en el medio del guardado de una venta.
     *
     * @group puntos
     * @test
     */
    public function el_ciclo_de_revertir_y_revivir_deja_siempre_una_sola_fila_de_cada_tipo()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_A));

        $pago_id = $this->pagar_y_devolver_id($cliente, self::TOTAL_A);

        // Paso 1: un lote vivo, sin reverso.
        $lote = $this->movimientos_de_la_venta($venta, 'ganados')->first();

        $this->assertNotNull($lote, 'La venta saldada tiene que tener su lote.');
        $this->assertNull($lote->anulado_at);
        $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'revertidos'), 'Un lote vivo no puede tener reverso.');
        $this->assertEqualsWithDelta(self::PUNTOS_A, $this->saldo_de_puntos($cliente), self::DELTA);

        $id_del_lote = $lote->id;

        // Paso 2: se borra el pago. El lote se anula y aparece su reverso.
        $this->deleteJson('api/current-acount/client/'.$pago_id)->assertStatus(200);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');
        $revertidos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $ganados, 'Después de revertir tiene que seguir habiendo UNA sola fila ganados.');
        $this->assertEquals($id_del_lote, $ganados->first()->id, 'La reversión borró y recreó el lote en vez de anularlo en el lugar.');
        $this->assertNotNull($ganados->first()->anulado_at, 'El lote de una venta que dejó de estar saldada tiene que quedar anulado.');

        $this->assertCount(1, $revertidos, 'Tiene que haber UN movimiento revertidos, ni cero ni dos.');
        $this->assertEqualsWithDelta(
            -self::PUNTOS_A,
            (float) $revertidos->first()->puntos,
            self::DELTA,
            'El reverso tiene que ser el total otorgado en negativo.'
        );

        $this->assertEqualsWithDelta(
            0,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Con el lote anulado y su reverso escrito, el saldo del cliente tiene que volver a cero.'
        );

        // Paso 3: se vuelve a cobrar. El MISMO lote revive y el reverso se borra.
        $this->pagar($cliente, self::TOTAL_A, 0)->assertStatus(201);

        $ganados = $this->movimientos_de_la_venta($venta, 'ganados');
        $revertidos = $this->movimientos_de_la_venta($venta, 'revertidos');

        $this->assertCount(1, $ganados, 'Revivir el lote no puede crear una segunda fila ganados (violaría el unique).');
        $this->assertEquals(
            $id_del_lote,
            $ganados->first()->id,
            'Al revivir se creó un lote NUEVO en vez de reusar el que ya estaba.'
        );
        $this->assertNull($ganados->first()->anulado_at, 'Revivir el lote es sacarle el anulado_at.');
        $this->assertEqualsWithDelta(self::PUNTOS_A, (float) $ganados->first()->puntos, self::DELTA);

        $this->assertCount(
            0,
            $revertidos,
            'El movimiento revertidos tiene que BORRARSE al revivir el lote: si no, resta dos veces.'
        );

        $this->assertEqualsWithDelta(
            self::PUNTOS_A,
            $this->saldo_de_puntos($cliente),
            self::DELTA,
            'Con el lote revivido y el reverso borrado, el saldo tiene que volver a los puntos originales.'
        );
    }

    /**
     * Y el ciclo se puede repetir: cobrar, borrar el pago, volver a cobrar, volver a borrarlo. En
     * ningún momento hay más de una fila de cada tipo, y el saldo oscila entre los puntos y cero
     * sin acumular residuos.
     *
     * @group puntos
     * @test
     */
    public function el_ciclo_se_puede_repetir_sin_acumular_filas()
    {
        $this->dar_extencion();
        $this->crear_programa();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);
        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_A));

        for ($vuelta = 1; $vuelta <= 3; $vuelta++) {

            $pago_id = $this->pagar_y_devolver_id($cliente, self::TOTAL_A);

            $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'ganados'), 'Vuelta '.$vuelta.': hay más de un lote.');
            $this->assertCount(0, $this->movimientos_de_la_venta($venta, 'revertidos'), 'Vuelta '.$vuelta.': el reverso no se borró al revivir.');
            $this->assertEqualsWithDelta(self::PUNTOS_A, $this->saldo_de_puntos($cliente), self::DELTA, 'Vuelta '.$vuelta.': el saldo con la venta cobrada no es el esperado.');

            $this->deleteJson('api/current-acount/client/'.$pago_id)->assertStatus(200);

            $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'ganados'), 'Vuelta '.$vuelta.': la reversión duplicó el lote.');
            $this->assertCount(1, $this->movimientos_de_la_venta($venta, 'revertidos'), 'Vuelta '.$vuelta.': hay más de un reverso.');
            $this->assertEqualsWithDelta(0, $this->saldo_de_puntos($cliente), self::DELTA, 'Vuelta '.$vuelta.': el saldo con la venta sin cobrar no volvió a cero.');
        }
    }

    /**
     * Registra un pago por el endpoint real y devuelve el id del `current_acounts` creado.
     *
     * Va con `current_date = 0` a propósito: es el camino del endpoint que sí pasa por
     * `checkPagos()`, que es lo que estos tests necesitan tener corriendo para poder medir la
     * idempotencia del reconciliador. El otro camino lo mide el archivo 2.
     *
     * @param  \App\Models\Client  $cliente
     * @param  float               $monto
     * @return int
     */
    protected function pagar_y_devolver_id($cliente, $monto)
    {
        $response = $this->pagar($cliente, $monto, 0);

        $response->assertStatus(201);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertArrayHasKey('current_acount', $cuerpo, 'POST api/current-acount/pago no devolvió el pago creado.');

        return $cuerpo['current_acount']['id'];
    }
}
