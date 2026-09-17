<?php

namespace Tests\Feature\ForzarTotal;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;

/**
 * Archivo 6 — la factura electronica tiene que dar EXACTAMENTE el total forzado, con el IVA
 * repartido en la misma proporcion que el resto del comprobante.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  POR QUE ESTO NO SALIA SOLO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Para un emisor Responsable Inscripto, `AfipHelper::getImportes()` va por
 *  `AfipImportesCalculator::calculate_from_sale_items()`, que RECONSTRUYE el total renglon por
 *  renglon en vez de leer `sales.total`. O sea que un total forzado no llega a la factura por su
 *  cuenta: hay que prorratearlo. Es exactamente la misma asimetria que tuvo el canje por puntos
 *  —un descuento que bajaba el total y no bajaba lo facturado—, y se resuelve igual, con un factor
 *  por renglon.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL TEST QUE MAS IMPORTA ES EL DE FORZADO **MAS** CANJE DE PUNTOS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  El bruto facturable que usa el canje para sacar su porcentaje se arma llamando al mismo metodo
 *  donde se aplica el factor del forzado. Si el factor entrara tambien ahi, el canje quedaria sin
 *  escalar y el total facturado se pasaria del forzado en `canje x (factor - 1)`. Con un canje
 *  grande y un forzado chico la diferencia son centavos, y por eso el escenario del test 5 usa un
 *  canje de $10.000: ahi la falla es visible y no se puede confundir con redondeo.
 *
 * ⚠️ NUNCA se llama a `make_afip_ticket()`: eso pegaria contra ARCA de verdad. Se arma el
 * `AfipTicket` EN MEMORIA (sin `save()`) y se llama `getImportes()`, que es literalmente lo que
 * hace `SaleHelper::set_total_a_facturar()` en produccion.
 *
 * Sobre las aserciones de plata: `assertEqualsWithDelta()`, nunca `assertEquals()` con un cuarto
 * argumento — PHPUnit 9.6 lo descarta en silencio y compara con EPSILON 1e-10.
 *
 * @group facturacion
 * @group forzar_total
 */
class Prorrateo_del_ajuste_en_la_factura_Test extends ForzarTotalTestCase
{
    /** Tolerancia "exacta": para los invariantes que cierran al centavo redondo. */
    const DELTA_EXACTO = 0.0001;

    /**
     * Tolerancia del invariante `suma(Iva[].BaseImp) == ImpNeto`.
     *
     * ⚠️ NO es aflojar una asercion: es una PROPIEDAD PREEXISTENTE de `AfipImportesCalculator`,
     * ya medida y documentada por `tests/Feature/Puntos/12`. `ImpNeto` se acumula SIN redondear y
     * se redondea una sola vez al final, mientras que cada bucket de `Iva[]` se redondea renglon
     * por renglon. Sumar-y-redondear no da lo mismo que redondear-y-sumar: con dos alicuotas
     * gravadas la diferencia puede ser de un centavo. Es ajeno al forzado y esta dentro de la
     * tolerancia que ARCA admite.
     */
    const DELTA_REDONDEO_POR_BUCKET = 0.02;

    /** Bruto de la venta mezclada de este archivo, con tres alicuotas distintas. */
    const BRUTO_FACTURA = 100000.00;

    /**
     * Configuracion fiscal EN MEMORIA (no toca la base) con la condicion IVA pedida.
     *
     * @param  string  $condicion
     * @return \App\Models\AfipInformation
     */
    protected function afip_information_en_memoria($condicion)
    {
        $iva_condition = IvaCondition::where('name', $condicion)->first();

        $this->assertNotNull($iva_condition, 'Falta la iva_condition "'.$condicion.'" en la base de testing.');

        $afip_information = new AfipInformation();
        $afip_information->iva_condition_id = $iva_condition->id;
        $afip_information->punto_venta = 1;
        $afip_information->cuit = '20111111112';
        $afip_information->setRelation('iva_condition', $iva_condition);

        return $afip_information;
    }

    /**
     * Corre el calculo de importes de una venta sin tocar la red.
     *
     * @param  \App\Models\Sale  $sale
     * @param  string            $condicion  Condicion IVA del EMISOR.
     * @return array
     */
    protected function importes_de($sale, $condicion = 'Responsable inscripto')
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->facturar_importe_personalizado = null;
        $afip_ticket->importe_personalizado_ivas_json = null;
        $afip_ticket->afip_tipo_comprobante_id = 1;
        $afip_ticket->sale = $sale;
        $afip_ticket->setRelation('afip_information', $this->afip_information_en_memoria($condicion));

        $afip_helper = new AfipHelper($afip_ticket);

        return $afip_helper->getImportes();
    }

    /**
     * Engancha un articulo del fixture con su alicuota escrita en el pivote.
     *
     * `article_sale.iva_percentage` se escribe con la MISMA cadena que tiene el articulo en
     * `ivas.percentage` ('21', '10.5', 'Exento'): asi `resolve_article_iva_percentage()` —que
     * prioriza el pivote— y `get_importe_gravado()` —que mira la relacion— no pueden discrepar.
     *
     * @param  \App\Models\Sale  $sale
     * @param  string            $nombre
     * @param  float             $price
     * @param  float             $amount
     * @return void
     */
    protected function enganchar_con_iva($sale, $nombre, $price, $amount)
    {
        $articulo = $this->articulo($nombre);

        $this->assertNotNull($articulo, 'Falta el articulo "'.$nombre.'" del fixture.');
        $this->assertNotNull($articulo->iva, 'El articulo "'.$nombre.'" del fixture no tiene IVA.');

        $sale->articles()->attach($articulo->id, [
            'amount'         => $amount,
            'price'          => $price,
            'iva_percentage' => $articulo->iva->percentage,
        ]);
    }

    /**
     * Venta con las tres clases de renglon que arman un desglose de verdad.
     *
     *     Martillo acero  21 %      605.00 x 100 =  60.500,00
     *     Cuchilla        10,5 %  1.105.00 x  25 =  27.625,00
     *     Cuchara         Exento  1.187.50 x  10 =  11.875,00
     *                                            ───────────
     *                                            100.000,00
     *
     * Los precios estan elegidos para que las bases den redondas (60.500 / 1,21 = 50.000 y
     * 27.625 / 1,105 = 25.000): asi un rojo se lee como un error de logica y no como ruido de
     * redondeo. Es el mismo escenario que usa `tests/Feature/Puntos/12`, a proposito.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function venta_mezclada($overrides = [])
    {
        $sale = $this->crear_venta_en_base(array_merge([
            'sub_total'          => self::BRUTO_FACTURA,
            'total'              => self::BRUTO_FACTURA,
            'forzar_total_monto' => null,
        ], $overrides));

        $this->enganchar_con_iva($sale, 'Martillo acero', 605.00, 100);
        $this->enganchar_con_iva($sale, 'Cuchilla', 1105.00, 25);
        $this->enganchar_con_iva($sale, 'Cuchara', 1187.50, 10);

        return $sale->fresh();
    }

    /**
     * Los dos invariantes estructurales que ARCA valida sobre cualquier comprobante.
     *
     * @param  array  $importes
     * @return void
     */
    protected function assert_desglose_cierra($importes)
    {
        $this->assertEqualsWithDelta(
            $importes['total'],
            $importes['gravado'] + $importes['iva'] + $importes['exento'] + $importes['neto_no_gravado'],
            self::DELTA_EXACTO,
            'ImpNeto + ImpIVA + ImpOpEx + ImpTotConc tiene que dar ImpTotal exacto'
        );

        $suma_bases = 0;
        $suma_ivas = 0;

        foreach ($importes['ivas'] as $bucket) {
            $suma_bases += $bucket['BaseImp'];
            $suma_ivas += $bucket['Importe'];
        }

        $this->assertEqualsWithDelta(
            $importes['gravado'],
            $suma_bases,
            self::DELTA_REDONDEO_POR_BUCKET,
            'la suma de las bases del arreglo Iva[] tiene que dar ImpNeto'
        );

        $this->assertEqualsWithDelta(
            $importes['iva'],
            $suma_ivas,
            self::DELTA_REDONDEO_POR_BUCKET,
            'la suma de los importes del arreglo Iva[] tiene que dar ImpIVA'
        );
    }

    /**
     * Test 1 — LINEA DE BASE. Sin forzar, la venta mezclada factura su bruto y el desglose cierra.
     * Si este test se pone rojo, los numeros de todos los demas dejan de significar algo.
     *
     * @group forzar_total
     * @test
     */
    public function sin_forzado_la_venta_mezclada_factura_su_bruto()
    {
        $importes = $this->importes_de($this->venta_mezclada());

        $this->assertEqualsWithDelta(
            self::BRUTO_FACTURA,
            $importes['total'],
            self::DELTA,
            'sin forzado la factura tiene que dar el bruto'
        );

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 2 — EL CASO CENTRAL. Con el total forzado a 99.000, la factura da 99.000.
     *
     * @group forzar_total
     * @test
     */
    public function con_el_total_forzado_la_factura_da_el_total_forzado()
    {
        $sale = $this->venta_mezclada([
            'total'              => 99000.00,
            'forzar_total_monto' => -1000.00,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(
            99000.00,
            $importes['total'],
            self::DELTA,
            'la factura tiene que dar el total forzado, no el bruto de 100.000'
        );

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 3 — hacia arriba: el recargo tambien se prorratea.
     *
     * @group forzar_total
     * @test
     */
    public function con_el_total_forzado_hacia_arriba_la_factura_sube_igual()
    {
        $sale = $this->venta_mezclada([
            'total'              => 100500.00,
            'forzar_total_monto' => 500.00,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(100500.00, $importes['total'], self::DELTA, 'un monto positivo tiene que subir el importe facturado');

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 4 — LA GUARDA de `base <= 0`.
     *
     * ─────────────────────────────────────────────────────────────────────────────
     *  🔴 CUAL ES EL ESTADO QUE DISPARA LA GUARDA, Y CUAL NO
     * ─────────────────────────────────────────────────────────────────────────────
     *
     *  La base es `total - monto`, o sea el total ANTES del forzado. Con un monto NEGATIVO (un
     *  descuento) la base siempre es mayor o igual que el total, asi que forzar una venta a cero
     *  NO dispara esta guarda: ahi la base da 100.000, el factor da 0 y la factura da 0 —que es
     *  exactamente lo que pidio el vendedor, y `AfipWsfeHelper::solicitar_cae()` corta antes de
     *  pedirle un CAE a ARCA por un comprobante de cero.
     *
     *  El estado que SI la dispara es un RECARGO mas grande que el total: la venta quedo en $500
     *  con un ajuste de +$600, o sea que "el total antes del forzado" seria -$100. Se llega ahi
     *  sacando items despues de haber forzado. Sin la guarda, el factor daria negativo (500/-100 =
     *  -5) y se le mandarian importes negativos a ARCA, que los rechaza.
     *
     * @group forzar_total
     * @test
     */
    public function con_la_base_negativa_no_se_divide_y_se_factura_sin_escalar()
    {
        $sale = $this->venta_mezclada([
            'total'              => 500.00,
            'forzar_total_monto' => 600.00,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(
            self::BRUTO_FACTURA,
            $importes['total'],
            self::DELTA,
            'con base negativa el factor tiene que quedar en 1: se factura el bruto en vez de mandar importes negativos a ARCA'
        );

        $this->assertGreaterThan(
            0,
            $importes['total'],
            'el importe facturado nunca puede ser negativo'
        );

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 4 bis — forzar el total a CERO factura cero, sin reventar.
     *
     * No es la guarda de arriba (ver su bloque): es el camino normal con factor 0. Se afirma acá
     * para que quede escrito cual de los dos comportamientos corresponde a cual estado, que es
     * justo lo que se confunde al leer `base <= 0` sin la cuenta al lado.
     *
     * @group forzar_total
     * @test
     */
    public function forzar_el_total_a_cero_factura_cero_sin_reventar()
    {
        $sale = $this->venta_mezclada([
            'total'              => 0.00,
            'forzar_total_monto' => 0.00 - self::BRUTO_FACTURA,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(
            0.00,
            $importes['total'],
            self::DELTA,
            'forzar el total a cero tiene que facturar cero: es lo que pidio el vendedor, y solicitar_cae() corta antes de pedirle un CAE a ARCA'
        );
    }

    /**
     * Test 5 — FORZADO **MAS** CANJE DE PUNTOS, que es donde se rompe la guarda de reentrada.
     *
     * La venta bruta da 100.000, el cliente canjea 10.000 en puntos (el total baja a 90.000) y el
     * vendedor redondea a 89.000, o sea un monto de -1.000.
     *
     *     factor = 89.000 / 90.000
     *
     * Con la guarda (correcto):  el bruto facturable se arma SIN el factor, el canje sale 10 % y
     *                            la suma da (100.000 - 10.000) x factor = 89.000,00.
     * Sin la guarda:             el bruto quedaria escalado y el canje no, y la factura da
     *                            88.888,90 — MEDIDO el 17/9/2026 sacando la guarda y corriendo
     *                            este mismo test, no deducido.
     *
     * Son $111,10 de diferencia sobre una factura: no se puede confundir con redondeo.
     *
     * @group forzar_total
     * @test
     */
    public function el_forzado_convive_con_el_canje_de_puntos()
    {
        $sale = $this->venta_mezclada([
            'total'              => 89000.00,
            'forzar_total_monto' => -1000.00,
            'puntos_canjeados'   => 100,
            'descuento_puntos'   => 10000.00,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(
            89000.00,
            $importes['total'],
            self::DELTA,
            'con canje de puntos encima, la factura tiene que seguir dando el total forzado exacto (si diera 89.111,11 el factor se estaria colando en el bruto del canje)'
        );

        $this->assert_desglose_cierra($importes);
    }
}
