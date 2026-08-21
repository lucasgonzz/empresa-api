<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Pdf\Puntos\PuntosComprobanteHelper;
use App\Models\MovimientoPunto;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Lo que el CLIENTE ve impreso: que el comprobante cierre, y que los puntos digan la verdad.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE CIERRA.
 *
 *  El canje bajaba `sales.total` y NINGÚN comprobante lo nombraba. El papel salía con
 *
 *      Sub Total: $121.000
 *      Total:     $116.000
 *
 *  y $5.000 de diferencia que el cliente no podía explicar. El renglón del canje es lo que
 *  vuelve a hacer que Sub Total − descuentos = Total, y es lo único obligatorio de todo esto.
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 POR QUÉ ESTA SUITE MIDE EL HELPER Y NO EL PDF ARMADO.
 *
 *  Los tres comprobantes de venta terminan su constructor con `$this->Output(); exit;`, así que
 *  instanciar cualquiera de ellos adentro de PHPUnit mata el proceso de test. Y encima cada
 *  archivo de `Pdf/` hace `require(fpdf.php)` sin `_once`: cargar dos clases de comprobante en
 *  el mismo proceso revienta con "Constant FPDF_VERSION already defined".
 *
 *  Por eso lo que se mide acá es `PuntosComprobanteHelper`, que es donde vive TODA la decisión
 *  —qué se imprime, con qué texto y bajo qué condiciones— y lo único que los tres comprobantes
 *  hacen con él es ubicar el renglón en su propio layout. El PDF de verdad se verificó a mano
 *  generándolo y volcándolo a texto (ver el informe de la misión).
 * ─────────────────────────────────────────────────────────────────────────────
 */
class Comprobante_puntos_en_el_papel_Test extends PuntosTestCase
{
    /** El punto vale $100 en esta suite, para que los números del canje sean legibles. */
    const VALOR_PUNTO = 100;

    /** Mínimo de canje bajo a propósito: el default de 500 puntos exigiría ventas gigantes. */
    const MINIMO_CANJE = 10;

    /** Total bruto de la venta, antes del canje. */
    const TOTAL_BRUTO = 10000;

    /** Cuántos puntos canjea la venta de los tests que necesitan canje. */
    const PUNTOS_CANJEADOS = 15;

    /**
     * Extensión prendida + programa activo, que es el piso de todo lo que se prueba acá.
     *
     * @return \App\Models\SistemaDePuntos
     */
    protected function escenario()
    {
        $this->dar_extencion();

        return $this->crear_programa([
            'valor_punto'     => self::VALOR_PUNTO,
            'minimo_canje'    => self::MINIMO_CANJE,
            'tope_porcentaje' => 20,
        ]);
    }

    /**
     * Payload de una venta con canje.
     *
     * 🔴 `sub_total` va con el BRUTO, no con el neto. Es lo que manda VENDER de verdad
     * (`vender_set_total.js`: `sub_total` es la sumatoria de los ítems y el canje se resta
     * después, sobre `total`), y es de lo que depende que el ticket cierre: `SaleTicketPdf`
     * arranca su cuenta en `sales.sub_total` y le va restando los descuentos.
     *
     * @param  int   $client_id
     * @param  bool  $de_cuenta_corriente
     * @return array
     */
    protected function payload_con_canje($client_id, $de_cuenta_corriente = false)
    {
        $descuento = self::PUNTOS_CANJEADOS * self::VALOR_PUNTO;

        return $this->payload_venta($client_id, self::TOTAL_BRUTO - $descuento, [
            'save_current_acount'        => $de_cuenta_corriente ? 1 : 0,
            'omitir_en_cuenta_corriente' => $de_cuenta_corriente ? 0 : 1,
            'sub_total'                  => self::TOTAL_BRUTO,
            'puntos_canjeados'           => self::PUNTOS_CANJEADOS,
            'descuento_puntos'           => $descuento,
            'items'                      => [
                [
                    'is_article'   => true,
                    'id'           => $this->articulo_de_puntos()->id,
                    'price_vender' => self::TOTAL_BRUTO,
                    'amount'       => 1,
                ],
            ],
        ]);
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  1. Que el comprobante cierre
     * ---------------------------------------------------------------------------------------
     */

    /**
     * 🔴 El renglón del canje existe y su importe es EXACTAMENTE la diferencia que el papel no
     * explicaba: sub_total − renglón = total.
     *
     * @return void
     */
    public function test_el_renglon_del_canje_cierra_la_cuenta_del_comprobante()
    {
        $sistema = $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->sembrar_lote($cliente, $sistema, 40);

        $venta = $this->crear_venta($this->payload_con_canje($cliente->id));

        $descuento = PuntosComprobanteHelper::descuento_del_canje($venta);

        $this->assertEqualsWithDelta(
            self::PUNTOS_CANJEADOS * self::VALOR_PUNTO,
            $descuento,
            self::DELTA,
            'El canje guardado no es el que se pidió.'
        );

        /*
         * La cuenta del papel, hecha con las mismas columnas que lee el comprobante.
         */
        $this->assertEqualsWithDelta(
            (float) $venta->sub_total - $descuento,
            (float) $venta->total,
            self::DELTA,
            'Sub Total menos el renglón del canje no da el Total: el comprobante sigue sin cerrar.'
        );

        $renglon = PuntosComprobanteHelper::renglon_descuento($venta);

        $this->assertNotNull($renglon, 'Una venta con canje tiene que imprimir el renglón del canje.');
        $this->assertStringContainsString('1.500', $renglon, 'El renglón no dice el importe del canje.');
        $this->assertStringContainsString('15 puntos', $renglon, 'El renglón no dice cuántos puntos se canjearon.');

        $this->assertTrue(
            PuntosComprobanteHelper::tiene_canje($venta),
            'tiene_canje() es lo que hace que el comprobante imprima el Sub Total; con canje tiene que dar true.'
        );
    }

    /**
     * Una venta sin canje no gana ni un renglón: el comprobante de siempre queda igual.
     *
     * @return void
     */
    public function test_una_venta_sin_canje_no_agrega_ningun_renglon_de_descuento()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->assertFalse(PuntosComprobanteHelper::tiene_canje($venta));
        $this->assertNull(PuntosComprobanteHelper::renglon_descuento($venta));
        $this->assertNull(PuntosComprobanteHelper::renglon_descuento_corto($venta));
    }

    /**
     * 🔴 El renglón del canje NO pega a la base. Se imprime en todos los comprobantes de todas
     * las ventas, así que si costara una consulta la pagaría hasta el comercio que no tiene el
     * módulo.
     *
     * @return void
     */
    public function test_el_renglon_del_canje_no_cuesta_ni_una_consulta()
    {
        $sistema = $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->sembrar_lote($cliente, $sistema, 40);

        $venta = $this->crear_venta($this->payload_con_canje($cliente->id));

        DB::flushQueryLog();
        DB::enableQueryLog();

        PuntosComprobanteHelper::tiene_canje($venta);
        PuntosComprobanteHelper::renglon_descuento($venta);
        PuntosComprobanteHelper::renglon_descuento_corto($venta);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertCount(0, $consultas, 'El renglón del canje se resolvió con una consulta a la base.');
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  2. Los puntos impresos
     * ---------------------------------------------------------------------------------------
     */

    /**
     * Venta de mostrador ya cobrada: el movimiento 'ganados' existe, así que el papel dice
     * cuántos puntos sumó, y el número es el DEL LIBRO, no uno recalculado.
     *
     * @return void
     */
    public function test_la_venta_de_mostrador_cobrada_dice_cuantos_puntos_sumo()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $ganados = (float) MovimientoPunto::where('sale_id', $venta->id)
                                            ->where('tipo', 'ganados')
                                            ->whereNull('anulado_at')
                                            ->sum('puntos');

        $this->assertGreaterThan(
            0,
            $ganados,
            'El fixture no acumuló nada: sin lote de "ganados" este test no mide lo que dice medir.'
        );

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $this->comercio());

        $texto = implode(' | ', $renglones);

        $this->assertStringContainsString('Sumaste', $texto, 'El ticket de mostrador no dice cuántos puntos sumó.');
        $this->assertStringContainsString(
            number_format($ganados, 0, ',', '.'),
            $texto,
            'El número impreso no es el que quedó en el libro de movimientos.'
        );
        $this->assertStringContainsString('Puntos acumulados', $texto, 'Falta el saldo acumulado.');
    }

    /**
     * 🔴 Venta de cuenta corriente todavía impaga: los puntos NO EXISTEN cuando se imprime la
     * factura, porque suman al cobrar. El papel no puede decir "sumaste X".
     *
     * @return void
     */
    public function test_la_venta_de_cuenta_corriente_impaga_no_promete_puntos_que_no_existen()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

        $this->asegurar_cuenta_corriente($cliente);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO));

        $ganados = MovimientoPunto::where('sale_id', $venta->id)
                                    ->where('tipo', 'ganados')
                                    ->whereNull('anulado_at')
                                    ->count();

        $this->assertEquals(
            0,
            $ganados,
            'La premisa del test se cayó: una venta de cuenta corriente impaga no debería tener puntos acreditados.'
        );

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $this->comercio());

        $texto = implode(' | ', $renglones);

        $this->assertStringNotContainsString(
            'Sumaste',
            $texto,
            'La factura de una venta impaga está prometiendo puntos que todavía no existen.'
        );

        $this->assertStringContainsString(
            'se acreditan cuando quede paga',
            $texto,
            'La factura de cuenta corriente tiene que explicar por qué todavía no hay puntos.'
        );

        $this->assertStringContainsString('Puntos acumulados', $texto, 'El saldo acumulado se puede leer siempre.');
    }

    /**
     * El saldo acumulado que se imprime es `SUM(movimiento_puntos.puntos)`, la única definición
     * de saldo del sistema, y se traduce a pesos con el `valor_punto` del programa.
     *
     * @return void
     */
    public function test_el_saldo_impreso_es_el_del_libro_y_su_equivalencia_en_pesos()
    {
        $sistema = $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $this->sembrar_lote($cliente, $sistema, 40);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $saldo = $this->saldo_de_puntos($cliente);

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $this->comercio());

        $texto = implode(' | ', $renglones);

        $this->assertStringContainsString(
            'Puntos acumulados: '.number_format($saldo, 0, ',', '.'),
            $texto,
            'El saldo impreso no es el del libro.'
        );

        $this->assertStringContainsString(
            number_format($saldo * self::VALOR_PUNTO, 0, '', '.'),
            $texto,
            'Falta la equivalencia en pesos del saldo.'
        );
    }

    /*
     * ---------------------------------------------------------------------------------------
     *  3. El gate de la extensión
     * ---------------------------------------------------------------------------------------
     */

    /**
     * 🔴 Un comercio sin la extensión no ve NI UN renglón nuevo, y no paga NI UNA consulta por
     * que este bloque exista.
     *
     * La consulta se mide pasándole el `User` con `extencions` ya cargada, que es exactamente
     * la situación de los tres comprobantes: `NewSalePdf` y `SaleAfipTicketPdf` corren otros
     * `hasExtencion()` antes, y `SaleTicketPdf` arma su user con `UserHelper::getFullModel()`,
     * que es `withAll()`.
     *
     * @return void
     */
    public function test_un_comercio_sin_la_extencion_no_ve_ni_un_renglon_ni_paga_una_consulta()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $this->quitar_extencion();

        $user = $this->comercio();
        $user->load('extencions');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $user);

        $consultas = DB::getQueryLog();

        DB::disableQueryLog();

        $this->assertCount(0, $renglones, 'Un comercio sin la extensión está imprimiendo renglones de puntos.');

        $this->assertCount(
            0,
            $consultas,
            'Un comercio sin la extensión está pagando consultas al imprimir un comprobante.'
        );
    }

    /**
     * Extensión prendida pero sin programa activo: tampoco se imprime nada. Sin programa no hay
     * nada que el cliente pueda hacer con esos puntos.
     *
     * @return void
     */
    public function test_sin_programa_activo_no_se_imprime_nada()
    {
        $this->dar_extencion();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $venta = $this->crear_venta($this->payload_venta($cliente->id, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $this->comercio());

        $this->assertCount(0, $renglones);
    }

    /**
     * Una venta de mostrador SIN cliente no imprime nada de puntos: los puntos son por cliente.
     *
     * @return void
     */
    public function test_una_venta_sin_cliente_no_imprime_puntos()
    {
        $this->escenario();

        $venta = $this->crear_venta($this->payload_venta(null, self::TOTAL_BRUTO, [
            'omitir_en_cuenta_corriente' => 1,
        ]));

        $renglones = PuntosComprobanteHelper::renglones_puntos($venta, $this->comercio());

        $this->assertCount(0, $renglones, 'Una venta sin cliente no tiene saldo de puntos que mostrar.');
    }
}
