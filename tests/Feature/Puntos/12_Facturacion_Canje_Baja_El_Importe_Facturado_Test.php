<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\AfipHelper;
use App\Models\AfipInformation;
use App\Models\AfipTicket;
use App\Models\IvaCondition;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\EmpresaTestCase;

/**
 * Archivo 12 — el canje por puntos tiene que bajar TAMBIÉN el importe que se factura, en un
 * comercio Responsable Inscripto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL DEFECTO QUE ESTA SUITE BLINDA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La decisión es que el canje baje `sales.total` Y `sales.total_a_facturar`, y el propio texto
 *  de ayuda del módulo lo afirma ("baja el total de la venta y, por lo tanto, el importe que se
 *  factura", `empresa-spa/src/models/sistema_de_puntos.js`).
 *
 *  `AfipHelper::getImportes()` se bifurca según la condición IVA del EMISOR:
 *
 *   - NO Responsable Inscripto → `AfipImportesCalculator::calculate_for_no_responsable_inscripto()`
 *     usa `sales.total`, que el front ya manda neteado. Ahí el canje llegaba solo.
 *
 *   - Responsable Inscripto → `calculate_from_sale_items()` RECONSTRUYE el total renglón por
 *     renglón. Ese camino sí resta `sales.descuento`, los `discount_sale` y los recargos, pero
 *     NO restaba el canje: un RI que canjeaba $5.000 cobraba $95.000 y facturaba $100.000.
 *
 *  La asimetría era la prueba de que era un bug y no una decisión: el canje era el ÚNICO
 *  descuento de venta que no llegaba a la factura.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ CORRECCIÓN A UNA NOTA DEL ARCHIVO 7 DE ESTA MISMA SUITE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  `7_Canje_en_vender_Test.php` dice que `total_a_facturar` "no se puede medir en esta base"
 *  porque falta la tabla `afip_informations`. Son dos errores encadenados:
 *
 *   1. La tabla se llama `afip_information` (Laravel trata "information" como incontable, así
 *      que `AfipInformation` NO pluraliza) y EXISTE en `empresa_testing_s7`.
 *   2. Y sobre todo: la nota concluye que "no hay una segunda cuenta que pueda discrepar" porque
 *      `getImportes()` lee `sale->total`. Eso vale SOLO para la rama no-RI. La rama RI es
 *      exactamente la segunda cuenta que discrepaba.
 *
 *  Por eso acá se mide el camino de verdad, sin red: se arma un `AfipTicket` EN MEMORIA (sin
 *  `save()`) y se llama `(new AfipHelper($afip_ticket))->getImportes()`, que es literalmente lo
 *  que hace `SaleHelper::set_total_a_facturar()` en producción — y lo que vuelve a hacer
 *  `MakeAfipTicket` al facturar, sin releer la columna guardada. NUNCA se llama a
 *  `make_afip_ticket()`: eso pegaría contra ARCA de verdad.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LO QUE MÁS IMPORTA ACÁ NO ES EL TOTAL: ES EL DESGLOSE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Una factura de ARCA no lleva un total: lleva `ImpNeto` (neto gravado), `ImpIVA`, `ImpOpEx`
 *  (exento), `ImpTotConc` (no gravado) y el arreglo `Iva[]` con la base y el impuesto de cada
 *  alícuota. Si el descuento no se reparte entre los renglones con el mismo criterio que el
 *  resto, el desglose deja de sumar el total y ARCA rechaza el comprobante. Por eso el escenario
 *  central mezcla 21 %, 10,5 % y un exento, y se verifican los tres invariantes:
 *
 *   (a) `gravado + iva + exento + no_gravado == total`
 *   (b) `suma(Iva[].BaseImp) == gravado` y `suma(Iva[].Importe) == iva`
 *   (c) `total_sin_canje - total_con_canje == descuento_puntos`, EXACTO
 *
 * Sobre las aserciones de plata: `assertEqualsWithDelta()`, nunca `assertEquals()` con un cuarto
 * argumento — PHPUnit 9.6 lo descarta en silencio y compara con EPSILON 1e-10.
 *
 * @group puntos
 * @group facturacion
 */
class Facturacion_Canje_Baja_El_Importe_Facturado_Test extends EmpresaTestCase
{
    /** Tolerancia de plata para comparaciones normales. */
    const DELTA = 0.01;

    /** Tolerancia "exacta": para los invariantes que tienen que cerrar al centavo redondo. */
    const DELTA_EXACTO = 0.0001;

    /**
     * Tolerancia del invariante `suma(Iva[].BaseImp) == ImpNeto`.
     *
     * ⚠️ NO es 0.01 y no es un aflojar la aserción para que pase: es una PROPIEDAD PREEXISTENTE
     * de `AfipImportesCalculator`, medida el 21/8/2026 y ajena al canje. `ImpNeto` se acumula
     * SIN redondear y se redondea UNA sola vez al final (`calculate()`), mientras que cada
     * bucket de `Iva[]` se redondea RENGLÓN POR RENGLÓN (`AfipItemCalculator::get_importe_iva()`
     * hace `round(..., 2)`). Sumar-y-redondear no da lo mismo que redondear-y-sumar: cada bucket
     * puede aportar hasta medio centavo de diferencia, y con dos alícuotas gravadas eso es un
     * centavo.
     *
     * Se dispara cuando la base de un renglón cae justo en un medio centavo. Ejemplo real de
     * esta suite, con un canje de $7.777,77 sobre el bruto de $100.000:
     *
     *   ImpNeto  = round(75.000 x 0,9222223)          = 69.166,67
     *   BaseImp  = round(46.111,115) + round(23.055,5575) = 46.111,12 + 23.055,56 = 69.166,68
     *
     * `sales.descuento` produce exactamente el mismo residuo con el porcentaje adecuado, así que
     * no es algo que traiga el canje. Está dentro de la tolerancia de un centavo que ARCA admite
     * entre `ImpNeto` y la suma del arreglo `Iva[]`, y corregirlo cambiaría el cálculo de TODAS
     * las facturas del parque: queda reportado, fuera del alcance de este arreglo.
     *
     * El invariante que ARCA sí valida al centavo exacto —`ImpTotal == ImpTotConc + ImpNeto +
     * ImpOpEx + ImpIVA`— se sigue verificando con DELTA_EXACTO, porque `calculate()` arma el
     * total justamente sumando esas cuatro partes ya redondeadas.
     */
    const DELTA_REDONDEO_POR_BUCKET = 0.02;

    /**
     * Bruto de la venta del escenario mezclado. Sale de los tres renglones de `venta_mezclada()`.
     */
    const BRUTO = 100000.00;

    /** Pesos canjeados en el escenario central: el 5 % del bruto. */
    const CANJE = 5000.00;

    /**
     * Configuración fiscal EN MEMORIA (no toca la base) con la condición IVA pedida.
     *
     * @param  string  $condicion  Nombre de la `iva_condition`.
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
     * Corre el cálculo de importes de una venta sin tocar la red, igual que
     * `SaleHelper::set_total_a_facturar()`.
     *
     * @param  \App\Models\Sale  $sale
     * @param  string            $condicion  Condición IVA del EMISOR.
     * @param  array|null        $articles   Subconjunto de renglones a facturar (null = la venta entera).
     * @return array
     */
    protected function importes_de($sale, $condicion = 'Responsable inscripto', $articles = null)
    {
        $afip_ticket = new AfipTicket();
        $afip_ticket->facturar_importe_personalizado = null;
        $afip_ticket->importe_personalizado_ivas_json = null;
        $afip_ticket->afip_tipo_comprobante_id = 1;
        $afip_ticket->sale = $sale;
        $afip_ticket->setRelation('afip_information', $this->afip_information_en_memoria($condicion));

        if (is_null($articles)) {
            $afip_helper = new AfipHelper($afip_ticket);
        } else {
            $afip_helper = new AfipHelper($afip_ticket, $articles, [], null, $sale);
        }

        return $afip_helper->getImportes();
    }

    /**
     * Crea una venta mínima del comercio del fixture.
     *
     * @param  array  $overrides
     * @return \App\Models\Sale
     */
    protected function crear_venta($overrides = [])
    {
        $user = User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->first();

        $this->assertNotNull($user, 'Falta el usuario del fixture.');

        return Sale::create(array_merge([
            'user_id'                    => $user->id,
            'client_id'                  => null,
            'omitir_en_cuenta_corriente' => 0,
            'save_current_acount'        => 0,
            'terminada'                  => 1,
            'is_cerrada'                 => 0,
            'sub_total'                  => self::BRUTO,
            'total'                      => self::BRUTO,
            'moneda_id'                  => 1,
            'descuento'                  => 0,
        ], $overrides));
    }

    /**
     * Engancha un artículo del fixture a la venta con precio, cantidad y alícuota explícitos.
     *
     * `article_sale.iva_percentage` se escribe con la MISMA cadena que tiene el artículo en
     * `ivas.percentage` ('21', '10.5', 'Exento'): así `resolve_article_iva_percentage()` —que
     * prioriza el pivot— y `get_importe_gravado()` —que mira la relación— no pueden discrepar,
     * que es una fuente de falsos rojos que no tiene nada que ver con lo que mide esta suite.
     *
     * @param  \App\Models\Sale  $sale
     * @param  string            $nombre_articulo
     * @param  float             $price
     * @param  float             $amount
     * @return void
     */
    protected function enganchar($sale, $nombre_articulo, $price, $amount)
    {
        $articulo = $this->articulo($nombre_articulo);

        $this->assertNotNull($articulo, 'Falta el artículo "'.$nombre_articulo.'" del fixture.');
        $this->assertNotNull($articulo->iva, 'El artículo "'.$nombre_articulo.'" del fixture no tiene IVA.');

        $sale->articles()->attach($articulo->id, [
            'amount'          => $amount,
            'price'           => $price,
            'iva_percentage'  => $articulo->iva->percentage,
        ]);
    }

    /**
     * Venta con las tres clases de renglón que arman un desglose de verdad.
     *
     *   Martillo acero  21 %      605.00 x 100 =  60.500,00
     *   Cuchilla        10,5 %  1.105.00 x  25 =  27.625,00
     *   Cuchara         Exento  1.187.50 x  10 =  11.875,00
     *                                            ───────────
     *                                            100.000,00
     *
     * Los precios están elegidos para que las bases den redondas (60.500 / 1,21 = 50.000 y
     * 27.625 / 1,105 = 25.000) y el 5 % del canje también: así un rojo se lee como un error de
     * lógica y no como ruido de redondeo.
     *
     * @param  array  $overrides  Campos de la venta a pisar.
     * @return \App\Models\Sale
     */
    protected function venta_mezclada($overrides = [])
    {
        $sale = $this->crear_venta($overrides);

        $this->enganchar($sale, 'Martillo acero', 605.00, 100);
        $this->enganchar($sale, 'Cuchilla', 1105.00, 25);
        $this->enganchar($sale, 'Cuchara', 1187.50, 10);

        return $sale->fresh();
    }

    /**
     * Verifica los dos invariantes estructurales que ARCA valida sobre cualquier comprobante.
     *
     * @param  array  $importes  Resultado de `AfipHelper::getImportes()`.
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
            'la suma de las bases del arreglo Iva[] tiene que dar ImpNeto (ver DELTA_REDONDEO_POR_BUCKET)'
        );

        $this->assertEqualsWithDelta(
            $importes['iva'],
            $suma_ivas,
            self::DELTA_REDONDEO_POR_BUCKET,
            'la suma de los importes del arreglo Iva[] tiene que dar ImpIVA (ver DELTA_REDONDEO_POR_BUCKET)'
        );
    }

    /**
     * Test 1 — LÍNEA DE BASE. Sin canje, la venta mezclada factura 100.000 y el desglose cierra.
     * Si este test se pone rojo, los números de todos los demás dejan de significar algo.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function sin_canje_la_venta_mezclada_factura_su_bruto()
    {
        $sale = $this->venta_mezclada();

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(50000.00, $importes['ivas']['21']['BaseImp'], self::DELTA, 'base del 21 %');
        $this->assertEqualsWithDelta(10500.00, $importes['ivas']['21']['Importe'], self::DELTA, 'IVA del 21 %');
        $this->assertEqualsWithDelta(25000.00, $importes['ivas']['10']['BaseImp'], self::DELTA, 'base del 10,5 %');
        $this->assertEqualsWithDelta(2625.00, $importes['ivas']['10']['Importe'], self::DELTA, 'IVA del 10,5 %');
        $this->assertEqualsWithDelta(11875.00, $importes['exento'], self::DELTA, 'el renglón exento va a ImpOpEx entero, sin dividir');
        $this->assertEqualsWithDelta(0.00, $importes['neto_no_gravado'], self::DELTA, 'no hay renglones no gravados');
        $this->assertEqualsWithDelta(self::BRUTO, $importes['total'], self::DELTA, 'el total tiene que ser el bruto de los tres renglones');

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 2 — EL DEFECTO. Con canje, el importe facturado por un RI baja EXACTAMENTE los pesos
     * canjeados, y el descuento se prorratea entre las tres alícuotas sin romper el desglose.
     *
     * Números esperados (canje de 5.000 sobre 100.000 = 5 %):
     *
     *   21 %      60.500,00 x 0,95 = 57.475,00  →  base 47.500,00  +  IVA  9.975,00
     *   10,5 %    27.625,00 x 0,95 = 26.243,75  →  base 23.750,00  +  IVA  2.493,75
     *   Exento    11.875,00 x 0,95 = 11.281,25  →  todo a ImpOpEx
     *                                ──────────
     *                                 95.000,00
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function el_canje_baja_el_importe_facturado_prorrateado_entre_las_alicuotas()
    {
        $sale = $this->venta_mezclada(['descuento_puntos' => self::CANJE, 'puntos_canjeados' => 50, 'total' => self::BRUTO - self::CANJE]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(47500.00, $importes['ivas']['21']['BaseImp'], self::DELTA, 'base del 21 % ya neteada por el canje');
        $this->assertEqualsWithDelta(9975.00, $importes['ivas']['21']['Importe'], self::DELTA, 'IVA del 21 % ya neteado por el canje');
        $this->assertEqualsWithDelta(23750.00, $importes['ivas']['10']['BaseImp'], self::DELTA, 'base del 10,5 % ya neteada por el canje');
        $this->assertEqualsWithDelta(2493.75, $importes['ivas']['10']['Importe'], self::DELTA, 'IVA del 10,5 % ya neteado por el canje');
        $this->assertEqualsWithDelta(11281.25, $importes['exento'], self::DELTA, 'el renglón exento también recibe su parte del canje');
        $this->assertEqualsWithDelta(71250.00, $importes['gravado'], self::DELTA, 'ImpNeto');
        $this->assertEqualsWithDelta(12468.75, $importes['iva'], self::DELTA, 'ImpIVA');
        $this->assertEqualsWithDelta(95000.00, $importes['total'], self::DELTA, 'ImpTotal: lo que el cliente pagó de verdad');

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 3 — INVARIANTE (c). La diferencia entre facturar con canje y sin canje tiene que ser
     * EXACTAMENTE los pesos canjeados. Es la aserción que traduce "descuadre fiscal" a un número.
     *
     * Se barren montos de canje con decimales feos, para que no pase con números elegidos.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function la_diferencia_facturada_es_exactamente_el_canje()
    {
        $sin_canje = $this->importes_de($this->venta_mezclada());

        foreach ([0.01, 1234.56, 7777.77, 19999.99] as $canje) {

            $sale = $this->venta_mezclada([
                'descuento_puntos' => $canje,
                'total'            => self::BRUTO - $canje,
            ]);

            $importes = $this->importes_de($sale);

            $this->assertEqualsWithDelta(
                $canje,
                $sin_canje['total'] - $importes['total'],
                self::DELTA,
                'facturar con un canje de '.$canje.' tiene que dar exactamente eso menos que facturar sin canje'
            );

            $this->assert_desglose_cierra($importes);
        }
    }

    /**
     * Test 4 — ORDEN DE LAS CAPAS. `sales.descuento` es un porcentaje sobre los renglones y el
     * canje son pesos sobre lo que queda. El front arma la cuenta en ese orden
     * (`vender_set_total.js`: descuentos y recargos primero, canje después), y la factura tiene
     * que hacer lo mismo.
     *
     *   bruto 100.000 − 10 % = 90.000 ; − 5.000 de canje = 85.000
     *
     * Si el canje se aplicara ANTES del 10 %, daría (100.000 − 5.000) x 0,9 = 85.500: se
     * facturarían $500 de más y el canje habría "pagado" parte del descuento comercial.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function el_canje_va_despues_del_descuento_de_venta_no_antes()
    {
        $sale = $this->venta_mezclada([
            'descuento'        => 10,
            'descuento_puntos' => self::CANJE,
            'total'            => 85000.00,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(85000.00, $importes['total'], self::DELTA, 'el 10 % va primero y el canje después');
        $this->assertGreaterThan(
            1,
            abs(85500.00 - $importes['total']),
            'si diera 85.500 el canje se estaría aplicando antes del descuento de venta'
        );

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 5 — PRORRATEO EN UNA FACTURACIÓN PARCIAL (el molde de la nota de crédito por parte de
     * la mercadería). Se factura SOLO el renglón del 21 %: tiene que llevarse su 5 % de canje,
     * no los 5.000 enteros.
     *
     *   60.500,00 x 0,95 = 57.475,00 → se descontaron 3.025, que es lo que le toca a ese renglón.
     *
     * Con una resta en pesos al final del cálculo, este caso devolvería 55.500 y el comercio
     * estaría regalando 1.975.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function una_facturacion_parcial_se_lleva_solo_la_parte_del_canje_que_le_toca()
    {
        $sale = $this->venta_mezclada([
            'descuento_puntos' => self::CANJE,
            'total'            => self::BRUTO - self::CANJE,
        ]);

        /** Solo el renglón del 21 %, como haría una nota de crédito por ese artículo. */
        $solo_el_21 = $sale->articles->where('name', 'Martillo acero')->values();

        $this->assertCount(1, $solo_el_21, 'el subconjunto tiene que traer un solo renglón');

        $importes = $this->importes_de($sale, 'Responsable inscripto', $solo_el_21);

        $this->assertEqualsWithDelta(57475.00, $importes['total'], self::DELTA, 'el renglón parcial se lleva su 5 %, no el canje entero');
        $this->assertEqualsWithDelta(47500.00, $importes['ivas']['21']['BaseImp'], self::DELTA, 'base del 21 % del renglón parcial');
        $this->assertEqualsWithDelta(9975.00, $importes['ivas']['21']['Importe'], self::DELTA, 'IVA del 21 % del renglón parcial');

        $this->assert_desglose_cierra($importes);
    }

    /**
     * Test 6 — PISO EN CERO. Si el comercio configura el tope del programa al 100 % y el canje
     * supera al bruto facturable, el comprobante sale en 0, nunca con importes negativos (ARCA
     * los rechaza, y `AfipWsfeHelper::solicitar_cae()` corta antes de pedir el CAE con total 0).
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function un_canje_mas_grande_que_el_bruto_no_genera_importes_negativos()
    {
        $sale = $this->venta_mezclada([
            'descuento_puntos' => 200000.00,
            'total'            => 0,
        ]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(0.00, $importes['total'], self::DELTA, 'el comprobante se pisa en 0');
        $this->assertGreaterThanOrEqual(0, $importes['gravado'], 'ImpNeto nunca puede ser negativo');
        $this->assertGreaterThanOrEqual(0, $importes['iva'], 'ImpIVA nunca puede ser negativo');
        $this->assertGreaterThanOrEqual(0, $importes['exento'], 'ImpOpEx nunca puede ser negativo');

        foreach ($importes['ivas'] as $key => $bucket) {
            $this->assertGreaterThanOrEqual(0, $bucket['BaseImp'], 'la base de la alícuota '.$key.' nunca puede ser negativa');
            $this->assertGreaterThanOrEqual(0, $bucket['Importe'], 'el IVA de la alícuota '.$key.' nunca puede ser negativo');
        }
    }

    /**
     * Test 7 — NO REGRESIÓN DE LA RAMA NO-RI. Un emisor Monotributista siguió y sigue facturando
     * `sales.total`, que el front ya manda neteado: el arreglo NO puede descontar el canje una
     * segunda vez.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function en_un_emisor_no_responsable_inscripto_el_canje_no_se_descuenta_dos_veces()
    {
        $sale = $this->venta_mezclada([
            'descuento_puntos' => self::CANJE,
            'total'            => self::BRUTO - self::CANJE,
        ]);

        $importes = $this->importes_de($sale, 'Monotributista');

        $this->assertEqualsWithDelta(
            self::BRUTO - self::CANJE,
            $importes['total'],
            self::DELTA,
            'el monotributista factura sales.total, que ya viene neteado: no se vuelve a restar el canje'
        );
    }

    /**
     * Test 8 — NO REGRESIÓN DE LAS VENTAS SIN CANJE. Con `descuento_puntos` en null (el 99 % de
     * las ventas del parque) el cálculo tiene que dar idéntico a antes de esta misión.
     *
     * @group puntos
     * @group facturacion
     * @test
     */
    public function una_venta_sin_canje_factura_igual_que_siempre()
    {
        $sale = $this->venta_mezclada(['descuento_puntos' => null]);

        $importes = $this->importes_de($sale);

        $this->assertEqualsWithDelta(self::BRUTO, $importes['total'], self::DELTA, 'sin canje no cambia nada');
        $this->assertEqualsWithDelta(75000.00, $importes['gravado'], self::DELTA, 'ImpNeto sin canje');
        $this->assertEqualsWithDelta(13125.00, $importes['iva'], self::DELTA, 'ImpIVA sin canje');

        $this->assert_desglose_cierra($importes);
    }
}
