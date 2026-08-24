<?php

namespace Tests\Feature\Reportes;

use App\Models\AfipTicket;
use App\Models\SaleTax;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 245 · Prompt 02 — tests de la Posición Fiscal (`GET api/reportes/posicion-fiscal`, grupo
 * 225 prompt 02): IVA (débito/crédito/percepciones/retenciones), IIBB y su saldo a favor.
 *
 * Reemplaza las pruebas manuales pendientes 6, 7 y 8 del grupo
 * `reportes-estado-resultados-posicion-fiscal`.
 *
 * Estrategia (igual que el prompt 01 de este mismo grupo): cada test siembra su propio escenario
 * acotado con `Tests\Concerns\EscenariosDePlata` en un rango de fechas EXCLUSIVO — un mes distinto
 * por test, todos en julio a diciembre de 2019 o enero/febrero de 2020, para no solaparse ni con
 * los otros tests de este archivo ni con los rangos que ya usa `1_Estado_Resultados_Test.php`
 * (enero a junio de 2019 y todo 2016).
 *
 * El IVA débito (`ContabilidadRepository::iva_debito()`) sale de `afip_tickets` con
 * `resultado = 'A'`, unido a `sales`. `EscenariosDePlata` no tiene un método para facturar una
 * venta (eso requeriría hablar con el webservice real de AFIP), así que este archivo crea el
 * `AfipTicket` directo con `AfipTicket::create()` — mismo patrón que usa
 * `database/seeders/AfipTicketSeeder.php` para sembrar comprobantes de prueba — apoyado siempre en
 * una venta creada por el endpoint real (`crear_venta_cobrada()`). El IVA crédito, en cambio, sí
 * tiene un camino 100% por endpoint: `crear_gasto()` acepta `importe_iva` en sus overrides
 * (`POST api/expense`), que es una de las dos fuentes de `iva_credito()`.
 *
 * Ubicación del reloj: todo vía `fijar_reloj_en()` del trait (grupo 260), nunca `Carbon::setTestNow()`
 * directo.
 *
 * @group reportes
 */
class Posicion_Fiscal_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Marca si este test desactivó el `SaleTax` de IIBB del fixture, para reactivarlo en el
     * tearDown y no dejar el fixture roto para el resto de la suite (el resto de los tests de este
     * archivo asumen que el impuesto de IIBB está activo, tal cual lo deja `TestingFerreteriaSeeder`).
     *
     * @var bool
     */
    protected $iibb_desactivado_en_este_test = false;

    /**
     * Libera todo lo que EscenariosDePlata haya creado en este test y reactiva el `SaleTax` de IIBB
     * si este test lo desactivó, antes del rollback de DatabaseTransactions. Corre siempre, incluso
     * si una aserción cortó el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        if ($this->iibb_desactivado_en_este_test) {
            $this->reactivar_iibb();
        }

        parent::tearDown();
    }

    /**
     * Usuario dueño del fixture de testing (el mismo que autentica EmpresaTestCase::setUp()).
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * Desactiva el único `SaleTax` de IIBB del fixture (`activo = 0`), para simular un negocio que
     * todavía no configuró ningún impuesto sobre ventas. No hay endpoint de alta/baja de impuestos
     * en el flujo de este prompt, así que se escribe directo sobre el registro del fixture (misma
     * excepción que usa `configurar_override_caja()` en el propio trait: es setup del escenario, no
     * el comportamiento bajo prueba).
     *
     * @return void
     */
    protected function desactivar_iibb()
    {
        SaleTax::where('name', TestingFerreteriaSeeder::IMPUESTO_IIBB)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->update(['activo' => 0]);

        $this->iibb_desactivado_en_este_test = true;
    }

    /**
     * Reactiva el `SaleTax` de IIBB del fixture (`activo = 1`), dejándolo tal cual lo siembra
     * `TestingFerreteriaSeeder` para el resto de la suite.
     *
     * @return void
     */
    protected function reactivar_iibb()
    {
        SaleTax::where('name', TestingFerreteriaSeeder::IMPUESTO_IIBB)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->update(['activo' => 1]);

        $this->iibb_desactivado_en_este_test = false;
    }

    /**
     * Crea una venta cobrada de contado (`crear_venta_cobrada()` del trait) y la "factura" a mano
     * creando su `AfipTicket` autorizado (`resultado = 'A'`) con el IVA débito indicado — no hay
     * forma de facturar de verdad en un test sin hablar con el webservice real de AFIP, así que se
     * siembra el comprobante directo, igual que hace `database/seeders/AfipTicketSeeder.php`.
     *
     * La fecha de emisión del comprobante se toma de `Carbon::now()` (que respeta el
     * `Carbon::setTestNow()` vigente dejado por `fijar_reloj_en()`/`avanzar_reloj_de_ventas()`), para
     * que caiga en el mismo día que la propia venta.
     *
     * @param string $caja_nombre
     * @param string $metodo_pago_nombre
     * @param float $monto Monto cobrado de la venta.
     * @param float $importe_iva IVA débito que va a informar `posicion_iva.iva_debito`.
     * @return \App\Models\Sale Venta recién creada y "facturada".
     */
    protected function crear_venta_facturada($caja_nombre, $metodo_pago_nombre, $monto, $importe_iva)
    {
        $venta = $this->crear_venta_cobrada($caja_nombre, $metodo_pago_nombre, $monto);

        AfipTicket::create([
            'sale_id'            => $venta->id,
            'resultado'          => 'A',
            'importe_iva'        => $importe_iva,
            'importe_total'      => $venta->total,
            'afip_fecha_emision' => Carbon::now()->format('Y-m-d'),
            'cbte_numero'        => (string) $venta->id,
            'cbte_letra'         => 'A',
            'cbte_tipo'          => 1,
            'cuit_negocio'       => '20000000000',
            'cae'                => '00000000000000',
        ]);

        return $venta;
    }

    /**
     * Pega contra `GET api/reportes/posicion-fiscal` y devuelve el array `posicion_fiscal` ya
     * decodificado, o corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @return array
     */
    protected function pedir_posicion_fiscal($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/posicion-fiscal?desde='.$desde.'&hasta='.$hasta);

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/posicion-fiscal devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['posicion_fiscal'])) {
            $this->fail('La respuesta de GET api/reportes/posicion-fiscal no trae la clave "posicion_fiscal". Cuerpo completo: '.$response->getContent());
        }

        return $body['posicion_fiscal'];
    }

    /**
     * Test 1 — IVA renglón por renglón: con una venta facturada (IVA débito conocido) y un gasto con
     * IVA (crédito conocido) en julio de 2019 (rango exclusivo de este test), el payload de
     * `posicion_iva` trae las cuatro claves fiscales por separado — débito, crédito, percepciones y
     * retenciones de IVA sufridas — nunca un número único neteado, como pide la prueba manual. Sin
     * ninguna compra sembrada en este escenario, percepciones y retenciones sufridas dan 0 (el valor
     * que corresponde a lo efectivamente sembrado, no un cero arbitrario).
     *
     * @group reportes
     * @test
     */
    public function iva_trae_los_cuatro_renglones_separados_segun_lo_sembrado()
    {
        $this->fijar_reloj_en('2019-07-10 10:00:00');

        $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 1000, 210);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 700, ['importe_iva' => 150]);

        $posicion = $this->pedir_posicion_fiscal('2019-07-01', '2019-07-31');
        $iva = $posicion['posicion_iva'];

        $this->assertArrayHasKey('iva_debito', $iva);
        $this->assertArrayHasKey('iva_credito', $iva);
        $this->assertArrayHasKey('percepcion_iva_sufrida', $iva);
        $this->assertArrayHasKey('retencion_iva_sufrida', $iva);

        $this->assertEqualsWithDelta(210, (float) $iva['iva_debito'], self::DELTA);
        $this->assertEqualsWithDelta(150, (float) $iva['iva_credito'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $iva['percepcion_iva_sufrida'], self::DELTA, 'Sin ninguna compra sembrada en este escenario, la percepción de IVA sufrida tiene que dar 0.');
        $this->assertEqualsWithDelta(0, (float) $iva['retencion_iva_sufrida'], self::DELTA, 'Sin ninguna compra sembrada en este escenario, la retención de IVA sufrida tiene que dar 0.');
    }

    /**
     * Test 2 — IVA débito = suma del IVA de las ventas del período: dos ventas facturadas con IVA
     * conocido en agosto de 2019 (rango exclusivo), la suma tiene que coincidir con `iva_debito`.
     *
     * @group reportes
     * @test
     */
    public function iva_debito_es_la_suma_del_iva_de_las_ventas_del_periodo()
    {
        $this->fijar_reloj_en('2019-08-05 09:00:00');

        $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 2000, 100);
        $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 3500, 250);

        $posicion = $this->pedir_posicion_fiscal('2019-08-01', '2019-08-31');

        $this->assertEqualsWithDelta(100 + 250, (float) $posicion['posicion_iva']['iva_debito'], self::DELTA);
    }

    /**
     * Test 3 — IVA crédito = suma del IVA de las compras (gastos) del período: dos gastos con IVA
     * conocido en septiembre de 2019 (rango exclusivo), la suma tiene que coincidir con
     * `iva_credito`.
     *
     * @group reportes
     * @test
     */
    public function iva_credito_es_la_suma_del_iva_de_las_compras_del_periodo()
    {
        $this->fijar_reloj_en('2019-09-12 09:00:00');

        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 500, ['importe_iva' => 80]);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 900, ['importe_iva' => 120]);

        $posicion = $this->pedir_posicion_fiscal('2019-09-01', '2019-09-30');

        $this->assertEqualsWithDelta(80 + 120, (float) $posicion['posicion_iva']['iva_credito'], self::DELTA);
    }

    /**
     * Test 4 — 🔴 Saldo a favor (el más importante del prompt, declarado con mayor probabilidad de
     * rojo): un mes con más IVA de compras que de ventas tiene que informarse como saldo A FAVOR del
     * contribuyente, nunca como una deuda en negativo. En octubre de 2019 (rango exclusivo) se siembra
     * una venta con IVA débito chico (1000) y un gasto con IVA crédito grande (5500): el saldo crudo
     * (débito − crédito − percepciones − retenciones) da negativo, y `PosicionFiscalHelper::signo_y_monto()`
     * tiene que normalizarlo a un monto SIEMPRE positivo más el campo `tipo` explícito en 'a_favor'.
     *
     * Se verifica el signo de forma explícita (monto positivo + tipo == 'a_favor'), no solo que el
     * número "se vea bien": un signo invertido en una posición de IVA es indistinguible de un número
     * correcto a simple vista, que es exactamente el error que se busca atrapar acá.
     *
     * @group reportes
     * @test
     */
    public function mes_con_mas_iva_de_compras_que_de_ventas_se_informa_como_saldo_a_favor()
    {
        $this->fijar_reloj_en('2019-10-08 11:00:00');

        $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 4000, 1000);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 26000, ['importe_iva' => 5500]);

        $posicion = $this->pedir_posicion_fiscal('2019-10-01', '2019-10-31');
        $iva = $posicion['posicion_iva'];

        // Ancla: iva_debito e iva_credito son los montos que este mismo test acaba de sembrar.
        $this->assertEqualsWithDelta(1000, (float) $iva['iva_debito'], self::DELTA);
        $this->assertEqualsWithDelta(5500, (float) $iva['iva_credito'], self::DELTA);

        // El saldo crudo (débito - crédito - percepciones - retenciones) da negativo: 1000 - 5500 = -4500.
        $saldo_crudo = (float) $iva['iva_debito'] - (float) $iva['iva_credito'] - (float) $iva['percepcion_iva_sufrida'] - (float) $iva['retencion_iva_sufrida'];
        $this->assertLessThan(0, $saldo_crudo, 'El escenario tiene que sembrar más IVA de compras que de ventas, para que el saldo crudo dé negativo.');

        // Verificación explícita del signo: tipo == 'a_favor' y el monto expuesto SIEMPRE positivo
        // (nunca la deuda negativa cruda).
        $this->assertEquals('a_favor', $iva['tipo'], 'Un mes con más IVA de compras que de ventas tiene que informarse con tipo "a_favor", no "a_pagar".');
        $this->assertGreaterThan(0, (float) $iva['saldo'], 'El saldo a favor tiene que exponerse como monto positivo, nunca como una deuda en negativo.');
        $this->assertEqualsWithDelta(abs($saldo_crudo), (float) $iva['saldo'], self::DELTA, 'El monto del saldo a favor tiene que ser el valor absoluto del saldo crudo.');
    }

    /**
     * Test 5 — IIBB con impuesto configurado: con el `SaleTax` del fixture (`IMPUESTO_IIBB`, 3.5%,
     * `apply_to_all`) activo por default y una venta con un item de precio conocido en noviembre de
     * 2019 (rango exclusivo), la sección de IIBB trae el monto calculado.
     *
     * Se fuerza `iva.percentage = 0` en el item vendido (jerarquía de resolución de
     * `SaleHelper::get_price_sin_iva()`: revisa primero `$article_data['iva']['percentage']`) para
     * que `article_sale.price_sin_iva` quede persistido EXACTAMENTE igual al precio de venta, sin
     * depender de la alícuota de IVA real del artículo del fixture — así el monto esperado de IIBB
     * se puede derivar sin duplicar la lógica de redondeo de `get_price_sin_iva()`. El `price_sin_iva`
     * se lee directo de la fila persistida en `article_sale` (no se recalcula a mano) para no arrastrar
     * un desvío de redondeo propio frente al que hizo el sistema.
     *
     * @group reportes
     * @test
     */
    public function iibb_con_impuesto_configurado_trae_el_monto_calculado()
    {
        $this->fijar_reloj_en('2019-11-06 10:00:00');

        $articulo = $this->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);
        $precio_vender = 1000;
        $cantidad = 5;

        $venta = $this->crear_venta_cobrada(
            TestingFerreteriaSeeder::CAJA_EFECTIVO,
            TestingFerreteriaSeeder::PAGO_EFECTIVO,
            $precio_vender * $cantidad,
            [
                'items' => [
                    [
                        'is_article'   => true,
                        'id'           => $articulo->id,
                        'price_vender' => $precio_vender,
                        'amount'       => $cantidad,
                        'iva'          => ['percentage' => 0],
                    ],
                ],
            ]
        );

        $fila_pivot = DB::table('article_sale')->where('sale_id', $venta->id)->first();

        if (is_null($fila_pivot)) {
            $this->fail('POST api/sale no dejó ninguna fila en article_sale para la venta '.$venta->id.'; no se puede calcular el IIBB esperado.');
        }

        $sale_tax = SaleTax::where('name', TestingFerreteriaSeeder::IMPUESTO_IIBB)
            ->where('user_id', $this->usuario_de_testing()->id)
            ->firstOrFail();

        $base_gravada = (float) $fila_pivot->price_sin_iva * (float) $fila_pivot->amount;
        $iibb_esperado = $base_gravada * ((float) $sale_tax->percentage / 100);

        $posicion = $this->pedir_posicion_fiscal('2019-11-01', '2019-11-30');
        $iibb = $posicion['posicion_iibb'];

        $this->assertTrue($iibb['iibb_configurado'], 'Con el SaleTax del fixture activo, iibb_configurado tiene que ser true.');
        $this->assertEqualsWithDelta($iibb_esperado, (float) $iibb['iibb_determinado'], self::DELTA);
        $this->assertEqualsWithDelta($iibb_esperado, (float) $iibb['saldo'], self::DELTA, 'Sin percepciones ni retenciones de IIBB sembradas, el saldo tiene que ser igual al determinado.');
        $this->assertEquals('a_pagar', $iibb['tipo']);
    }

    /**
     * Test 6 — IIBB sin ningún impuesto configurado: se desactiva el único `SaleTax` del fixture
     * (`activo = 0`, restaurado en el tearDown) para diciembre de 2019 (rango exclusivo). La sección
     * de IIBB NO viene en cero sin más: viene con la marca explícita de "sin configurar"
     * (`iibb_configurado === false`) que la UI necesita para mostrar el link de configuración, en vez
     * de un cero que se vería igual a "no le tocó pagar nada este mes".
     *
     * @group reportes
     * @test
     */
    public function iibb_sin_impuesto_configurado_trae_la_marca_de_no_configurado_no_un_cero()
    {
        $this->desactivar_iibb();

        $this->fijar_reloj_en('2019-12-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 5000);

        $posicion = $this->pedir_posicion_fiscal('2019-12-01', '2019-12-31');
        $iibb = $posicion['posicion_iibb'];

        $this->assertArrayHasKey('iibb_configurado', $iibb, 'El payload tiene que traer la clave iibb_configurado explícita.');
        $this->assertFalse($iibb['iibb_configurado'], 'Sin ningún SaleTax activo, iibb_configurado tiene que ser false (la marca de "sin configurar"), no simplemente un iibb_determinado en 0.');
    }

    /**
     * Test 7 — Período vacío: sin ventas ni compras sembradas en enero de 2020 (rango exclusivo), el
     * endpoint responde 200 con las tres posiciones en cero y sin errores.
     *
     * @group reportes
     * @test
     */
    public function periodo_vacio_responde_200_con_todo_en_cero()
    {
        $posicion = $this->pedir_posicion_fiscal('2020-01-01', '2020-01-31');

        $iva = $posicion['posicion_iva'];
        $iibb = $posicion['posicion_iibb'];
        $ganancias = $posicion['pagos_a_cuenta_ganancias'];

        $this->assertEqualsWithDelta(0, (float) $iva['iva_debito'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $iva['iva_credito'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $iva['saldo'], self::DELTA);
        $this->assertEquals('a_pagar', $iva['tipo'], 'Un saldo en 0 no es negativo, así que tiene que quedar clasificado como "a_pagar".');

        $this->assertEqualsWithDelta(0, (float) $iibb['iibb_determinado'], self::DELTA);
        $this->assertEqualsWithDelta(0, (float) $iibb['saldo'], self::DELTA);

        $this->assertEqualsWithDelta(0, (float) $ganancias['retencion_ganancias_sufrida'], self::DELTA);
    }

    /**
     * Test 8 — Rango inclusivo en ambos extremos, igual que el test 5 del prompt 01: una venta
     * facturada el primer día de febrero de 2020 y otra el último (2020 es bisiesto: 29 días) tienen
     * que entrar las dos en `iva_debito`. Antes de assertear, se verifica que las dos ventas cayeron
     * efectivamente en días distintos (guard explícito del prompt): si `fijar_reloj_en()` no separó
     * el reloj como se espera, este test no estaría probando el límite del rango y hay que pararlo y
     * reportarlo, no ajustar la aserción.
     *
     * @group reportes
     * @test
     */
    public function rango_de_fechas_incluye_iva_debito_del_primer_dia_y_del_ultimo_dia()
    {
        $this->fijar_reloj_en('2020-02-01 08:00:00');
        $venta_primer_dia = $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 3000, 500);

        $this->fijar_reloj_en('2020-02-29 22:00:00');
        $venta_ultimo_dia = $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 4000, 700);

        // Guard duro del prompt: si las dos ventas cayeron el mismo día, no se está probando el
        // límite inclusivo del rango. Se para y se reporta, no se toca la aserción de abajo.
        if ($venta_primer_dia->created_at->format('Y-m-d') === $venta_ultimo_dia->created_at->format('Y-m-d')) {
            $this->fail(
                'Las dos ventas de este test cayeron el mismo día ('.$venta_primer_dia->created_at->format('Y-m-d').'), '.
                'en vez de en el primer y el último día del rango: fijar_reloj_en() no separó el reloj como se '.
                'esperaba. No se puede probar el límite inclusivo del rango en estas condiciones — esto es un '.
                'problema a reportar, no algo para ajustar en esta aserción.'
            );
        }

        $posicion = $this->pedir_posicion_fiscal('2020-02-01', '2020-02-29');

        $this->assertEqualsWithDelta(500 + 700, (float) $posicion['posicion_iva']['iva_debito'], self::DELTA);
    }
}
