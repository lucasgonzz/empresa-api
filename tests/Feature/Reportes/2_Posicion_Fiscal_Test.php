<?php

namespace Tests\Feature\Reportes;

use App\Models\AfipTicket;
use App\Models\CurrentAcount;
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
 * por test, todos entre julio de 2019 y julio de 2020, para no solaparse ni con los otros tests de
 * este archivo ni con los rangos que ya usa `1_Estado_Resultados_Test.php` (enero a junio de 2019
 * y todo 2016). Los meses de julio de 2019 a febrero de 2020 son de los tests 1 a 8; marzo a julio
 * de 2020 son de los tests 9 a 13 (renglón "IVA de notas de crédito emitidas") y agosto Y SEPTIEMBRE
 * de 2020 son del test 14 (`notas_credito_sin_medir`, que necesita dos meses para verificar que el
 * aviso queda acotado al período). Un test nuevo en este archivo tiene que elegir un mes que
 * no esté en esa lista: dos tests compartiendo mes se contaminan entre sí y el rojo aparece recién
 * cuando cambia el orden de la corrida.
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
     * Ids de los movimientos de cuenta corriente (`current_acounts`) creados por
     * `crear_nota_credito_facturada()`, para borrarlos en el tearDown.
     *
     * `EscenariosDePlata` no sabe nada de notas de crédito, así que `limpiar_escenarios()` no las
     * toca: si este archivo no las borra, no las borra nadie.
     *
     * @var array<int,int>
     */
    protected $notas_credito_sembradas = [];

    /**
     * Ids de los `afip_tickets` creados por `crear_nota_credito_facturada()`, para borrarlos en el
     * tearDown.
     *
     * @var array<int,int>
     */
    protected $afip_tickets_de_notas_credito_sembrados = [];

    /**
     * Libera todo lo que EscenariosDePlata haya creado en este test y reactiva el `SaleTax` de IIBB
     * si este test lo desactivó, antes del rollback de DatabaseTransactions. Corre siempre, incluso
     * si una aserción cortó el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Las notas de crédito van primero porque cuelgan de las ventas que borra
        // `limpiar_escenarios()`: al revés quedarían apuntando a una venta que ya no está.
        $this->limpiar_notas_credito_sembradas();

        $this->limpiar_escenarios();

        if ($this->iibb_desactivado_en_este_test) {
            $this->reactivar_iibb();
        }

        parent::tearDown();
    }

    /**
     * Borra los comprobantes de nota de crédito que sembró este test.
     *
     * Los `afip_tickets` se borran con `forceDelete()` y no con `delete()`: el modelo usa
     * `SoftDeletes`, así que un borrado normal dejaría la fila viva en la tabla con `deleted_at`
     * puesto. Para un fixture eso no alcanza — la idea es que la base quede como estaba.
     *
     * @return void
     */
    protected function limpiar_notas_credito_sembradas()
    {
        if (count($this->afip_tickets_de_notas_credito_sembrados)) {
            AfipTicket::whereIn('id', $this->afip_tickets_de_notas_credito_sembrados)->forceDelete();
            $this->afip_tickets_de_notas_credito_sembrados = [];
        }

        if (count($this->notas_credito_sembradas)) {
            CurrentAcount::whereIn('id', $this->notas_credito_sembradas)->delete();
            $this->notas_credito_sembradas = [];
        }
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
     * "Factura" una nota de crédito a mano: crea su movimiento de cuenta corriente y el AfipTicket
     * autorizado, con el mismo par de campos que ahora persiste
     * `AfipNotaCreditoHelper::update_afip_ticket()` (`importe_iva` + `afip_fecha_emision`). No hay
     * forma de emitir de verdad sin hablar con el webservice de ARCA, así que se siembra el
     * comprobante directo — mismo criterio que `crear_venta_facturada()`.
     *
     * 🔴 `sale_id` va en NULL a propósito y el vínculo a la venta viaja por `sale_nota_credito_id`:
     * es exactamente lo que deja `AfipNotaCreditoHelper::create_afip_ticket()`, y es lo que hace
     * que `iva_debito()` (que joinea por `sale_id`) NO vea la nota de crédito. Un fixture que le
     * pusiera `sale_id` estaría midiendo otro escenario — uno donde el doble conteo es imposible
     * por construcción del fixture y no por el comportamiento del sistema.
     *
     * @param \App\Models\Sale|null $venta Venta que originó la devolución (null: NC suelta).
     * @param float $importe_iva IVA de la nota de crédito.
     * @param float|null $importe_total Total de la NC; si es null, se usa el propio IVA.
     * @param array $overrides Claves `nota_credito` y/o `afip_ticket` para pisar campos del fixture.
     * @return array{nota_credito: \App\Models\CurrentAcount, afip_ticket: \App\Models\AfipTicket}
     */
    protected function crear_nota_credito_facturada($venta, $importe_iva, $importe_total = null, $overrides = [])
    {
        $total = is_null($importe_total) ? $importe_iva : $importe_total;

        $nota_credito = CurrentAcount::create(array_merge([
            'detalle'     => 'Nota Credito de test',
            'description' => 'Devolución de test',
            'haber'       => $total,
            'status'      => 'nota_credito',
            'sale_id'     => is_null($venta) ? null : $venta->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
        ], isset($overrides['nota_credito']) ? $overrides['nota_credito'] : []));

        $afip_ticket = AfipTicket::create(array_merge([
            'sale_id'              => null,
            'nota_credito_id'      => $nota_credito->id,
            'sale_nota_credito_id' => is_null($venta) ? null : $venta->id,
            'resultado'            => 'A',
            'importe_iva'          => $importe_iva,
            'importe_total'        => $total,
            'afip_fecha_emision'   => Carbon::now()->format('Y-m-d'),
            'cbte_numero'          => (string) $nota_credito->id,
            'cbte_letra'           => 'A',
            'cbte_tipo'            => 3,
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000000',
        ], isset($overrides['afip_ticket']) ? $overrides['afip_ticket'] : []));

        $this->notas_credito_sembradas[] = $nota_credito->id;
        $this->afip_tickets_de_notas_credito_sembrados[] = $afip_ticket->id;

        return ['nota_credito' => $nota_credito, 'afip_ticket' => $afip_ticket];
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
     * Pega contra `GET api/reportes/detalle` y devuelve el body ya decodificado, o corta el test con
     * `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * Copiado tal cual de `1_Estado_Resultados_Test.php`: el drill-down de la Posición Fiscal pega
     * contra el mismo endpoint genérico (`ReporteDetalleController`), así que el helper es el mismo
     * y no tiene sentido que diverja.
     *
     * @param string $concepto
     * @param string $desde
     * @param string $hasta
     * @param array $extra Query params adicionales (moneda, categoria, page, per_page).
     * @return array
     */
    protected function pedir_detalle($concepto, $desde, $hasta, $extra = [])
    {
        $query = array_merge([
            'concepto' => $concepto,
            'desde'    => $desde,
            'hasta'    => $hasta,
        ], $extra);

        $response = $this->getJson('api/reportes/detalle?'.http_build_query($query));

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/detalle devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true);
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

    /**
     * Test 9 — El renglón "IVA de notas de crédito emitidas" existe, suma SOLO las notas de crédito
     * que ARCA autorizó, y no se mezcla con el IVA débito.
     *
     * Las tres cosas van juntas en un test porque son la misma pregunta desde tres lados: qué es lo
     * que este renglón cuenta. Una NC rechazada (`resultado = 'R'`) no es un comprobante emitido —
     * el comercio nunca canceló ese débito — así que si sumara, el reporte estaría descontando IVA
     * que todavía se debe, o sea declarando de menos. Y el `iva_debito` tiene que quedar intacto:
     * el renglón nuevo es un renglón APARTE de la DDJJ, no un neteo del débito.
     *
     * El guard sobre `sale_id` no es decoración: si el fixture le pusiera `sale_id` a la NC, el
     * `iva_debito` la contaría y este test se caería por el fixture y no por el sistema. Se verifica
     * que la fila sembrada tiene la forma que deja `AfipNotaCreditoHelper::create_afip_ticket()`.
     *
     * Marzo de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function iva_notas_credito_suma_solo_las_autorizadas_y_no_toca_el_iva_debito()
    {
        $this->fijar_reloj_en('2020-03-09 10:00:00');

        $venta = $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 5000, 1000);

        $autorizada = $this->crear_nota_credito_facturada($venta, 210, 1210);

        // Nota de crédito rechazada por ARCA: existe la fila, pero el comprobante no se emitió.
        $this->crear_nota_credito_facturada($venta, 500, 2500, ['afip_ticket' => ['resultado' => 'R']]);

        $posicion = $this->pedir_posicion_fiscal('2020-03-01', '2020-03-31');
        $iva = $posicion['posicion_iva'];

        $this->assertArrayHasKey('iva_notas_credito', $iva, 'La posición de IVA tiene que traer el renglón iva_notas_credito discriminado, no neteado dentro de otro.');

        $this->assertEqualsWithDelta(210, (float) $iva['iva_notas_credito'], self::DELTA, 'Solo la nota de crédito autorizada (resultado A) suma: la rechazada no canceló ningún débito.');

        $this->assertEqualsWithDelta(1000, (float) $iva['iva_debito'], self::DELTA, 'El IVA débito tiene que quedar EXACTAMENTE en el IVA de la venta facturada: la nota de crédito no puede filtrarse ahí.');

        // Guard de fixture: la NC nace sin sale_id (así la crea create_afip_ticket()). Si esto
        // cambiara, el test dejaría de medir el cruce entre los dos renglones.
        $this->assertNull(
            AfipTicket::where('nota_credito_id', $autorizada['nota_credito']->id)->first()->sale_id,
            'El afip_ticket de una nota de crédito tiene que nacer con sale_id en NULL (AfipNotaCreditoHelper::create_afip_ticket()). Con sale_id seteado, este test no estaría probando que iva_debito la ignora.'
        );
    }

    /**
     * Test 10 — 🔴 El saldo de IVA resta el renglón, y el signo se da vuelta cuando las notas de
     * crédito superan al débito (el de mayor probabilidad de rojo de esta tanda).
     *
     * Un mes con más IVA cancelado por notas de crédito que IVA facturado deja al contribuyente con
     * saldo A FAVOR, igual que un mes con más compras que ventas. Es el escenario donde un signo
     * invertido pasa desapercibido: el monto "se ve bien" y lo único que delata el error es el campo
     * `tipo`. Por eso se assertea el par completo (monto positivo + tipo explícito) en los dos
     * sentidos, primero a pagar y después a favor, sobre el mismo período.
     *
     * Abril de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function el_saldo_de_iva_resta_las_notas_de_credito_y_puede_quedar_a_favor()
    {
        $this->fijar_reloj_en('2020-04-07 10:00:00');

        $venta = $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 6000, 1000);

        $this->crear_nota_credito_facturada($venta, 300, 1300);

        $iva = $this->pedir_posicion_fiscal('2020-04-01', '2020-04-30')['posicion_iva'];

        // Anclas: los dos montos que este mismo test acaba de sembrar.
        $this->assertEqualsWithDelta(1000, (float) $iva['iva_debito'], self::DELTA);
        $this->assertEqualsWithDelta(300, (float) $iva['iva_notas_credito'], self::DELTA);

        $this->assertEqualsWithDelta(700, (float) $iva['saldo'], self::DELTA, 'El saldo tiene que ser el débito menos las notas de crédito: 1000 - 300.');
        $this->assertEquals('a_pagar', $iva['tipo']);

        // Segunda nota de crédito: el total cancelado (300 + 900 = 1200) pasa al débito (1000), así
        // que el saldo crudo da -200 y tiene que informarse como saldo a favor, no como una deuda
        // negativa.
        $this->crear_nota_credito_facturada($venta, 900, 4900);

        $iva = $this->pedir_posicion_fiscal('2020-04-01', '2020-04-30')['posicion_iva'];

        $this->assertEqualsWithDelta(1200, (float) $iva['iva_notas_credito'], self::DELTA);

        $this->assertEquals('a_favor', $iva['tipo'], 'Con más IVA cancelado por notas de crédito que IVA facturado, el saldo tiene que informarse con tipo "a_favor".');
        $this->assertGreaterThan(0, (float) $iva['saldo'], 'El saldo a favor se expone SIEMPRE como monto positivo, nunca como la deuda negativa cruda.');
        $this->assertEqualsWithDelta(200, (float) $iva['saldo'], self::DELTA, 'El monto a favor tiene que ser el valor absoluto de 1000 - 1200.');
    }

    /**
     * Test 11 — El drill-down del renglón cierra contra el renglón.
     *
     * "Todo número es auditable con un clic" (principio de `ReporteDetalleController`): si el total
     * del detalle y el monto de la tarjeta pueden divergir, el reporte deja de ser auditable sin que
     * nadie se entere. Se verifica el total, la cantidad de comprobantes, la suma fila por fila, y
     * que el link apunte al movimiento de cuenta corriente de la nota de crédito — que es el
     * comprobante que el usuario quiere abrir, no el `afip_ticket`.
     *
     * La venta facturada del escenario está para que el detalle tenga de dónde traer un comprobante
     * de más si el `case` del controller estuviera mal armado.
     *
     * Mayo de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function el_detalle_de_iva_notas_credito_cierra_contra_el_renglon()
    {
        $this->fijar_reloj_en('2020-05-11 10:00:00');

        $venta = $this->crear_venta_facturada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 8000, 1500);

        $primera = $this->crear_nota_credito_facturada($venta, 120, 620);
        $segunda = $this->crear_nota_credito_facturada($venta, 380, 1880);

        $iva = $this->pedir_posicion_fiscal('2020-05-01', '2020-05-31')['posicion_iva'];
        $detalle = $this->pedir_detalle('iva_notas_credito', '2020-05-01', '2020-05-31');

        $this->assertEqualsWithDelta(120 + 380, (float) $detalle['total'], self::DELTA);
        $this->assertEqualsWithDelta((float) $iva['iva_notas_credito'], (float) $detalle['total'], self::DELTA, 'El total del detalle tiene que ser el mismo número que muestra la tarjeta del reporte.');

        $this->assertCount(2, $detalle['registros'], 'El detalle tiene que traer un registro por nota de crédito autorizada del período, ni uno más.');

        $suma_registros = 0;
        foreach ($detalle['registros'] as $registro) {
            $suma_registros += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta((float) $iva['iva_notas_credito'], $suma_registros, self::DELTA, 'La suma fila por fila del detalle tiene que reconstruir el renglón del reporte.');

        // El link tiene que llevar al movimiento de cuenta corriente de la NC (link_tipo
        // 'current_acount'), que es el comprobante real, no el afip_ticket.
        $ids_esperados = [$primera['nota_credito']->id, $segunda['nota_credito']->id];
        sort($ids_esperados);

        $ids_del_detalle = [];
        foreach ($detalle['registros'] as $registro) {
            $this->assertEquals('current_acount', $registro['link_tipo'], 'El drill-down tiene que linkear al movimiento de cuenta corriente de la nota de crédito.');
            $ids_del_detalle[] = (int) $registro['link_id'];
        }
        sort($ids_del_detalle);

        $this->assertEquals($ids_esperados, $ids_del_detalle, 'link_id tiene que ser el id del CurrentAcount de cada nota de crédito (con el join en el medio, un `id` sin calificar traería la fila equivocada).');
    }

    /**
     * Test 12 — El renglón está scopeado por usuario.
     *
     * El scope de este renglón viaja por `current_acounts.user_id` (no por `sales.user_id`), que es
     * una fuente de tenant distinta a la del resto del reporte: si estuviera mal joineado, un
     * comercio vería el IVA cancelado de otro comercio dentro de su propia DDJJ. Es el peor error
     * posible de este renglón y no se nota mirando el número.
     *
     * Junio de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function iva_notas_credito_no_cuenta_las_notas_de_credito_de_otro_usuario()
    {
        $otro_usuario = User::where('id', '!=', $this->usuario_de_testing()->id)->first();

        if (is_null($otro_usuario)) {
            $this->fail(
                'La base de testing tiene un solo usuario, así que este test no puede sembrar una nota de crédito '.
                'ajena y NO estaría midiendo nada (pasaría en verde con el scope roto). Hay que sembrar un segundo '.
                'usuario en la base de testing — esto es un problema del entorno para reportar, no algo para '.
                'ajustar en la aserción de abajo.'
            );
        }

        $this->fijar_reloj_en('2020-06-10 10:00:00');

        $this->crear_nota_credito_facturada(null, 100, 600);

        // Misma fecha, mismo período, otro dueño: el reporte del usuario del fixture no la puede ver.
        $this->crear_nota_credito_facturada(null, 999, 5999, ['nota_credito' => ['user_id' => $otro_usuario->id]]);

        $iva = $this->pedir_posicion_fiscal('2020-06-01', '2020-06-30')['posicion_iva'];

        $this->assertEqualsWithDelta(100, (float) $iva['iva_notas_credito'], self::DELTA, 'El renglón tiene que traer solo las notas de crédito cuyo movimiento de cuenta corriente pertenece al usuario del reporte.');
    }

    /**
     * Test 13 — El renglón fechea por `afip_fecha_emision`, no por `created_at`.
     *
     * Toda la Posición Fiscal imputa los comprobantes por fecha de EMISIÓN (misma regla que
     * `query_iva_debito()`): las dos puntas del mismo saldo tienen que fechar igual o el neteo no
     * cierra contra el Libro IVA. Este test siembra una tercera nota de crédito creada DENTRO de
     * julio pero emitida en agosto: si la query fechara por `created_at`, esa NC entraría y el
     * número saldría inflado en 999.
     *
     * De paso cubre el límite inclusivo del rango en los dos extremos (día 1 y día 31).
     *
     * Julio de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function iva_notas_credito_fechea_por_la_fecha_de_emision_del_comprobante()
    {
        $this->fijar_reloj_en('2020-07-01 08:00:00');
        $primer_dia = $this->crear_nota_credito_facturada(null, 50, 350);

        $this->fijar_reloj_en('2020-07-31 22:00:00');
        $ultimo_dia = $this->crear_nota_credito_facturada(null, 70, 470);

        // Guard duro: si las dos notas de crédito quedaron emitidas el mismo día, este test no está
        // probando el límite del rango. Se para y se reporta, no se toca la aserción de abajo.
        if ($primer_dia['afip_ticket']->afip_fecha_emision === $ultimo_dia['afip_ticket']->afip_fecha_emision) {
            $this->fail(
                'Las dos notas de crédito de este test quedaron emitidas el mismo día ('.$primer_dia['afip_ticket']->afip_fecha_emision.'), '.
                'en vez de en el primer y el último día del rango: fijar_reloj_en() no separó el reloj como se '.
                'esperaba. No se puede probar el límite inclusivo del rango en estas condiciones — esto es un '.
                'problema a reportar, no algo para ajustar en esta aserción.'
            );
        }

        // Creada con el reloj en julio, pero emitida en agosto: queda fuera del período.
        $this->crear_nota_credito_facturada(null, 999, 5999, ['afip_ticket' => ['afip_fecha_emision' => '2020-08-05']]);

        $iva = $this->pedir_posicion_fiscal('2020-07-01', '2020-07-31')['posicion_iva'];

        $this->assertEqualsWithDelta(50 + 70, (float) $iva['iva_notas_credito'], self::DELTA, 'Entran las dos notas de crédito emitidas en julio (extremos del rango incluidos) y NO la emitida en agosto: el renglón fechea por afip_fecha_emision, no por created_at.');
    }

    /**
     * Test 14 — 🔴 `notas_credito_sin_medir`: el payload distingue "no hubo devoluciones" de "todavía
     * no lo sé".
     *
     * Un $0 en el renglón "IVA de notas de crédito emitidas" es visualmente idéntico en los dos
     * casos, y la diferencia entre ellos es el impuesto que el comercio paga de más. Hasta el
     * 1/9/2026 ese IVA no se persistía, así que el día del despliegue TODO el histórico tiene
     * `importe_iva` en null hasta que se corra `php artisan set_iva_notas_credito`: si el reporte
     * mostrara el cero pelado, un comercio que en agosto emitió $40.000 de IVA en notas de crédito
     * lo vería igual que un mes sin ninguna devolución. Por eso el payload trae el contador, para
     * que la UI pueda decir "no lo sé todavía" en vez de un número que parece un dato.
     *
     * Se recorre el ciclo completo en un solo test porque es una sola pregunta: el contador tiene
     * que subir cuando falta medir y volver a cero cuando se midió. Un test que solo verificara el
     * "1" pasaría igual con un contador que nunca baja.
     *
     * 🔴 El contador fechea por `created_at` y no por `afip_fecha_emision`, al revés que todos los
     * demás renglones (`ContabilidadRepository::notas_credito_sin_medir()`). Una nota de crédito sin
     * `importe_iva` tampoco tiene `afip_fecha_emision` —las dos columnas se escriben juntas, ver
     * `SetIvaNotasCredito::persistir()`—, así que fechear por emisión las dejaría afuera a todas y el
     * contador daría 0 siempre, que es justo el silencio que existe para romper. Igual va acotado al
     * período: un aviso que sale en cualquier rango, incluso en uno enteramente posterior al cambio y
     * ya medido, se convierte en ruido y deja de leerse.
     *
     * Por eso el baseline de arranque se assertea explícitamente: sin él, un "1" podría venir de
     * cualquier otro test del archivo y no de lo que este siembra.
     *
     * Agosto de 2020, rango exclusivo de este test.
     *
     * @group reportes
     * @test
     */
    public function el_payload_informa_cuantas_notas_de_credito_todavia_no_tienen_el_iva_medido()
    {
        $this->fijar_reloj_en('2020-08-12 10:00:00');

        // Baseline: como el contador no filtra por fecha, arranca contando TODAS las notas de
        // crédito sin medir del usuario. Se ancla en 0 antes de sembrar nada.
        $iva = $this->pedir_posicion_fiscal('2020-08-01', '2020-08-31')['posicion_iva'];

        $this->assertArrayHasKey(
            'notas_credito_sin_medir',
            $iva,
            'La posición de IVA tiene que traer el contador explícito: sin él, la UI no puede distinguir un cero medido de un cero por falta de dato.'
        );

        $this->assertSame(
            0,
            (int) $iva['notas_credito_sin_medir'],
            'El contador tiene que arrancar en 0 en este escenario. Si no, hay notas de crédito sin medir '.
            'sembradas por otro lado y este test no estaría midiendo lo que siembra — es algo para reportar, '.
            'no para ajustar en las aserciones de abajo.'
        );

        /*
         * Nota de crédito autorizada por ARCA pero sin el IVA persistido: exactamente el estado en
         * el que quedaron todas las emitidas antes del 1/9/2026. Las dos columnas van en null
         * juntas, tal como las deja (y las escribe) el backfill.
         */
        $sin_medir = $this->crear_nota_credito_facturada(null, null, 600, [
            'afip_ticket' => [
                'importe_iva'        => null,
                'afip_fecha_emision' => null,
            ],
        ]);

        $iva = $this->pedir_posicion_fiscal('2020-08-01', '2020-08-31')['posicion_iva'];

        $this->assertSame(
            1,
            (int) $iva['notas_credito_sin_medir'],
            'Con una nota de crédito autorizada sin importe_iva, el payload tiene que informarla.'
        );

        $this->assertEqualsWithDelta(
            0,
            (float) $iva['iva_notas_credito'],
            self::DELTA,
            'El renglón sigue en $0 —no hay IVA que sumar— y por eso hace falta el contador: es el único '.
            'que explica que ese cero no está medido.'
        );

        /*
         * Y ahora se mide, escribiendo las dos columnas juntas (es lo único que hace el backfill
         * sobre un comprobante recuperable). El contador tiene que volver a 0 y el renglón tiene
         * que pasar a valer.
         */
        AfipTicket::where('id', $sin_medir['afip_ticket']->id)->update([
            'importe_iva'        => 210,
            'afip_fecha_emision' => '2020-08-12',
        ]);

        $iva = $this->pedir_posicion_fiscal('2020-08-01', '2020-08-31')['posicion_iva'];

        $this->assertSame(
            0,
            (int) $iva['notas_credito_sin_medir'],
            'Con todo medido, el contador tiene que volver a 0: si no bajara, la UI mostraría la advertencia para siempre y dejaría de significar algo.'
        );

        $this->assertEqualsWithDelta(
            210,
            (float) $iva['iva_notas_credito'],
            self::DELTA,
            'Una vez medida, la nota de crédito tiene que sumar al renglón: es lo que el backfill viene a recuperar.'
        );

        /*
         * Y el aviso está acotado al período: una nota de crédito sin medir creada en OTRO mes no
         * tiene que encender la advertencia acá. Sin esto, el aviso saldría en todos los rangos que
         * el usuario mire —incluido uno enteramente posterior al cambio, donde está todo medido— y
         * un aviso que sale siempre deja de leerse, que es la forma más silenciosa de perderlo.
         */
        $this->fijar_reloj_en('2020-09-15 10:00:00');

        $this->crear_nota_credito_facturada(null, null, 800, [
            'afip_ticket' => [
                'importe_iva'        => null,
                'afip_fecha_emision' => null,
            ],
        ]);

        $iva = $this->pedir_posicion_fiscal('2020-08-01', '2020-08-31')['posicion_iva'];

        $this->assertSame(
            0,
            (int) $iva['notas_credito_sin_medir'],
            'Una nota de crédito sin medir de septiembre no tiene que encender el aviso en el reporte de agosto.'
        );

        $iva_septiembre = $this->pedir_posicion_fiscal('2020-09-01', '2020-09-30')['posicion_iva'];

        $this->assertSame(
            1,
            (int) $iva_septiembre['notas_credito_sin_medir'],
            'Pero sí tiene que encenderlo en el reporte de septiembre, que es el período al que pertenece.'
        );
    }
}
