<?php

namespace Tests\Feature\Puntos;

use App\Models\Discount;
use App\Models\Surchage;
use Database\Seeders\testing\TestingFerreteriaSeeder;

/**
 * Archivo 19 — LAS CUATRO CAPAS DE DESCUENTO ENTRAN AL MONTO BASE.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL DEFECTO QUE ESTOS TESTS DEJAN CLAVADO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `PuntosBaseHelper::calcular_grupos()` aplicaba UNA sola de las cuatro capas de descuento del
 *  sistema —`article_sale.discount`, la del renglón— e ignoraba las tres del encabezado, que la
 *  SPA aplica como PORCENTAJES SOBRE EL TOTAL (`empresa-spa/src/mixins/vender_set_total.js`):
 *
 *    - `sales.descuento`            (el descuento global de la venta)
 *    - `discount_sale.percentage`   (la lista de Descuentos)
 *    - `sale_surchage.percentage`   (la lista de Recargos, que va para el otro lado y SUBE)
 *
 *  Medido antes del arreglo: una venta de $121.000 con `sales.descuento = 50` cerraba en
 *  `sales.total = 60.500` y el libro escribía `monto_base = 100.000` con 100 puntos otorgados,
 *  cuando corresponden 50. El programa está calibrado al 1% de retorno: en TODA venta con
 *  descuento global devolvía el doble. Y `monto_base` —la columna de auditoría, la que explica
 *  de dónde salió cada punto— quedaba escrita con un número que no existió nunca.
 *
 *  🔴 POR QUÉ SE MIDE `monto_base` Y NO SOLO LOS PUNTOS. Los puntos redondean para abajo por
 *  tramo (1 punto cada $1.000), así que un error de hasta $999 en la base no mueve la aguja.
 *  `monto_base` es el número exacto y es el que un contador va a mirar cuando alguien reclame.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  EL CANJE NO ENTRA, Y ESO TAMBIÉN SE MIDE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `sales.descuento_puntos` es la quinta capa del calculador de AFIP y también baja
 *  `sales.total`, pero la base de ACUMULACIÓN no la descuenta: los puntos se ganan sobre lo que
 *  se COMPRÓ, no sobre lo que se pagó en efectivo. Es una decisión de negocio que hasta ahora no
 *  estaba escrita en ningún lado, y el último test la deja medida para que cambiarla sin querer
 *  ponga un rojo.
 *
 * Todo se lee de la BASE después de pegarle al endpoint real de venta.
 */
class Base_con_descuentos_de_venta_Test extends PuntosTestCase
{
    /** El total con IVA de la venta de referencia. Neto: $100.000, o sea 100 puntos redondos. */
    const TOTAL_CON_IVA = 121000;

    /** El neto sin IVA de ese total, que es la base de puntos cuando no hay ningún descuento. */
    const BASE_SIN_DESCUENTOS = 100000;

    /**
     * Programa de referencia: 1 punto cada $1.000 de base neta, sin filtro por lista.
     *
     * @return \App\Models\SistemaDePuntos
     */
    protected function escenario()
    {
        $this->dar_extencion();

        return $this->crear_programa(['puntos_cada' => 1000, 'puntos_por_tramo' => 1]);
    }

    /**
     * Una venta de MOSTRADOR (no pasa por la cuenta corriente) del cliente de contado, con el
     * encabezado que le pase el test.
     *
     * Se usa mostrador y no cuenta corriente a propósito: lo que se mide acá es el CÁLCULO de la
     * base, y la venta de mostrador acumula apenas se guarda, sin necesidad de un cobro. La
     * cuenta corriente ya la miden los archivos 2 y 3.
     *
     * @param  float  $total       El `sales.total` que manda el front, ya con los descuentos.
     * @param  array  $encabezado  Lo que se le agrega al payload.
     * @return \App\Models\Sale
     */
    protected function venta_de_mostrador($total, $encabezado = [])
    {
        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $payload = $this->payload_venta($cliente->id, self::TOTAL_CON_IVA, array_merge([
            'omitir_en_cuenta_corriente' => 1,
            'total'                      => $total,
        ], $encabezado));

        return $this->crear_venta($payload);
    }

    /**
     * Crea un Descuento del comercio con el porcentaje pedido, listo para mandar en `discounts`.
     *
     * @param  float  $percentage
     * @return array
     */
    protected function descuento_de_venta($percentage)
    {
        $discount = Discount::create([
            'name'       => 'Descuento del test 19',
            'percentage' => $percentage,
            'user_id'    => $this->comercio()->id,
        ]);

        return ['id' => $discount->id, 'percentage' => $percentage];
    }

    /**
     * Crea un Recargo del comercio, listo para mandar en `surchages`.
     *
     * @param  float  $percentage
     * @return array
     */
    protected function recargo_de_venta($percentage)
    {
        $surchage = Surchage::create([
            'name'       => 'Recargo del test 19',
            'percentage' => $percentage,
            'user_id'    => $this->comercio()->id,
        ]);

        return ['id' => $surchage->id, 'percentage' => $percentage];
    }

    /**
     * El único lote 'ganados' de la venta, leído de la base.
     *
     * @param  \App\Models\Sale  $sale
     * @return \App\Models\MovimientoPunto
     */
    protected function lote_de($sale)
    {
        $lotes = $this->movimientos_de_la_venta($sale, 'ganados');

        $this->assertCount(1, $lotes, 'La venta tendría que haber dejado exactamente un lote de puntos.');

        return $lotes->first();
    }

    /**
     * @param  \App\Models\Sale  $sale
     * @param  float             $base
     * @param  float             $puntos
     * @param  string            $porque
     * @return void
     */
    protected function assertBaseYPuntos($sale, $base, $puntos, $porque)
    {
        $lote = $this->lote_de($sale);

        $this->assertEqualsWithDelta(
            $base,
            (float) $lote->monto_base,
            self::DELTA,
            'El monto base del lote no es el que corresponde. '.$porque
        );

        $this->assertEqualsWithDelta(
            $puntos,
            (float) $lote->puntos,
            self::DELTA,
            'Los puntos otorgados no son los que corresponden. '.$porque
        );
    }

    /**
     * Referencia: sin ningún descuento de encabezado, la base es el neto sin IVA.
     *
     * Sin este test los demás pasarían igual si el módulo estuviera calculando cualquier cosa.
     *
     * @group puntos
     * @test
     */
    public function sin_descuentos_la_base_es_el_neto_sin_iva()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(self::TOTAL_CON_IVA);

        $this->assertBaseYPuntos(
            $venta,
            self::BASE_SIN_DESCUENTOS,
            100,
            'Una venta de 121.000 con IVA es 100.000 de neto: 100 puntos a razón de 1 cada 1.000.'
        );
    }

    /**
     * 🔴 EL DEFECTO, TAL CUAL LO MIDIÓ EL CHEQUEO INDEPENDIENTE.
     *
     * `sales.descuento = 50` deja el total en 60.500 y la base tiene que ser 50.000, no 100.000.
     *
     * @group puntos
     * @test
     */
    public function el_descuento_global_de_la_venta_baja_la_base()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(60500, ['descuento' => 50]);

        $this->assertEqualsWithDelta(50, (float) $venta->descuento, self::DELTA, 'El fixture no guardó sales.descuento.');

        $this->assertBaseYPuntos(
            $venta,
            50000,
            50,
            'Con sales.descuento = 50 el cliente pagó 60.500 y la base es 50.000. Otorgar 100 puntos '.
            'es devolver el DOBLE de lo que el programa promete (1% del neto).'
        );
    }

    /**
     * El caso simétrico: un `descuento` NEGATIVO es como el sistema representa un recargo global.
     * Sube el total y tiene que subir la base.
     *
     * ⚠️ Esta es la divergencia anotada en `PuntosBaseHelper::factor_descuentos_de_venta()`:
     * `AfipItemCalculator` aplica `sales.descuento` con la guarda `> 0` y se pierde este caso,
     * pero la SPA lo aplica (`if (this.descuento)`) y es la SPA la que escribe `sales.total`.
     * El hecho económico es el total guardado.
     *
     * @group puntos
     * @test
     */
    public function un_descuento_global_negativo_es_un_recargo_y_sube_la_base()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(242000, ['descuento' => -100]);

        $this->assertBaseYPuntos(
            $venta,
            200000,
            200,
            'Con sales.descuento = -100 el cliente pagó 242.000: corresponden 200 puntos, no 100.'
        );
    }

    /**
     * La lista de Descuentos de la venta (`discount_sale`) también baja la base.
     *
     * @group puntos
     * @test
     */
    public function los_descuentos_de_la_lista_bajan_la_base()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(96800, [
            'discounts' => [$this->descuento_de_venta(20)],
        ]);

        $venta->load('discounts');

        $this->assertCount(1, $venta->discounts, 'El fixture no enganchó el descuento a la venta.');

        $this->assertBaseYPuntos($venta, 80000, 80, 'Un descuento de venta del 20% deja la base en 80.000.');
    }

    /**
     * Dos descuentos de venta se aplican EN CASCADA, no sumados: 10% y 10% dan 19%, no 20%.
     *
     * Es como lo hace el front y como lo hace el calculador de AFIP. Sin este test, una
     * implementación que sumara los porcentajes pasaría el test de un descuento solo.
     *
     * @group puntos
     * @test
     */
    public function dos_descuentos_de_venta_se_aplican_en_cascada()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(98010, [
            'discounts' => [$this->descuento_de_venta(10), $this->descuento_de_venta(10)],
        ]);

        $this->assertBaseYPuntos(
            $venta,
            81000,
            81,
            '100.000 con dos descuentos del 10% en cascada da 81.000, no 80.000.'
        );
    }

    /**
     * Los Recargos (`sale_surchage`) van para el otro lado: SUBEN la base.
     *
     * @group puntos
     * @test
     */
    public function los_recargos_de_la_lista_suben_la_base()
    {
        $this->escenario();

        $venta = $this->venta_de_mostrador(157300, [
            'surchages'                        => [$this->recargo_de_venta(30)],
            'aplicar_recargos_directo_a_items' => 0,
        ]);

        $this->assertBaseYPuntos($venta, 130000, 130, 'Un recargo del 30% deja la base en 130.000.');
    }

    /**
     * 🔴 `aplicar_recargos_directo_a_items` es la guarda que no se puede olvidar: con esa bandera
     * prendida el recargo YA viene metido en el `price` de cada renglón, así que aplicarlo otra
     * vez acá lo cobraría dos veces.
     *
     * Se manda el MISMO recargo del test anterior, con la bandera en 1 y el precio del renglón
     * ya recargado: la base tiene que salir del precio del renglón y nada más.
     *
     * @group puntos
     * @test
     */
    public function con_aplicar_recargos_directo_a_items_el_recargo_no_se_cobra_dos_veces()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        /*
         * El renglón viene con el recargo del 30% YA adentro (157.300 con IVA), que es lo que
         * hace el front cuando la bandera está prendida. La base tiene que ser 130.000: si el
         * helper volviera a aplicar el 30%, daría 169.000.
         */
        $payload = $this->payload_venta($cliente->id, 157300, [
            'omitir_en_cuenta_corriente'       => 1,
            'total'                            => 157300,
            'surchages'                        => [$this->recargo_de_venta(30)],
            'aplicar_recargos_directo_a_items' => 1,
        ]);

        $venta = $this->crear_venta($payload);

        $this->assertBaseYPuntos(
            $venta,
            130000,
            130,
            'Con aplicar_recargos_directo_a_items el recargo ya está en el precio del renglón: '.
            'volver a aplicarlo lo cobra dos veces.'
        );
    }

    /**
     * Las CUATRO capas juntas, que es donde se ve si el orden y la cascada están bien.
     *
     *   renglón: 121.000 con IVA, con `discount` del 10%  -> neto 100.000 * 0,90 = 90.000
     *   descuento de venta del 20%                        -> * 0,80             = 72.000
     *   recargo del 50%                                   -> * 1,50             = 108.000
     *   sales.descuento del 25%                           -> * 0,75             =  81.000
     *
     * @group puntos
     * @test
     */
    public function las_cuatro_capas_juntas()
    {
        $this->escenario();

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        $articulo = $this->articulo_de_puntos();

        $payload = $this->payload_venta($cliente->id, self::TOTAL_CON_IVA, [
            'omitir_en_cuenta_corriente'       => 1,
            'total'                            => 98010,
            'descuento'                        => 25,
            'discounts'                        => [$this->descuento_de_venta(20)],
            'surchages'                        => [$this->recargo_de_venta(50)],
            'aplicar_recargos_directo_a_items' => 0,
            'items'                            => [
                [
                    'is_article'   => true,
                    'id'           => $articulo->id,
                    'price_vender' => self::TOTAL_CON_IVA,
                    'amount'       => 1,
                    'discount'     => 10,
                ],
            ],
        ]);

        $venta = $this->crear_venta($payload);

        $this->assertBaseYPuntos(
            $venta,
            81000,
            81,
            '100.000 * 0,90 (renglón) * 0,80 (descuento de venta) * 1,50 (recargo) * 0,75 (global) = 81.000.'
        );
    }

    /**
     * 🔴 EL CANJE NO BAJA LA BASE DE ACUMULACIÓN, Y ES UNA DECISIÓN DE NEGOCIO.
     *
     * `sales.descuento_puntos` baja `sales.total` igual que las otras capas, pero los puntos se
     * ganan sobre lo que se COMPRÓ, no sobre lo que se pagó en efectivo. Descontarlo sería
     * castigar al cliente que usa el programa: gasta puntos, gana menos puntos, gasta menos.
     *
     * El cliente canjea 500 puntos a $10 el punto = $5.000 de descuento sobre una venta de
     * $121.000. La base sigue siendo 100.000.
     *
     * @group puntos
     * @test
     */
    public function el_canje_de_puntos_no_baja_la_base_de_acumulacion()
    {
        $this->dar_extencion();

        $sistema = $this->crear_programa([
            'puntos_cada'      => 1000,
            'puntos_por_tramo' => 1,
            'valor_punto'      => 10,
            'minimo_canje'     => 500,
            'tope_porcentaje'  => 20,
        ]);

        $cliente = $this->cliente(TestingFerreteriaSeeder::CLIENTE_CONTADO);

        // El saldo de partida, para que el canje sea válido.
        $this->sembrar_lote($cliente, $sistema, 500, null, \Carbon\Carbon::now()->subMonth());

        $payload = $this->payload_venta($cliente->id, self::TOTAL_CON_IVA, [
            'omitir_en_cuenta_corriente' => 1,
            'total'                      => 116000,
            'puntos_canjeados'           => 500,
            'descuento_puntos'           => 5000,
        ]);

        $venta = $this->crear_venta($payload);

        $this->assertEqualsWithDelta(
            5000,
            (float) $venta->descuento_puntos,
            self::DELTA,
            'El fixture no guardó el canje: sin canje este test no mide nada.'
        );

        $this->assertBaseYPuntos(
            $venta,
            self::BASE_SIN_DESCUENTOS,
            100,
            'El canje baja el total pero NO la base de acumulación: los puntos se ganan sobre lo comprado.'
        );
    }
}
