<?php

namespace Tests\Feature\Reportes;

use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\Sale;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión saneo-ganancia-ventas (17/9/2026) — el renglón "Ventas netas" del Estado de Resultados va
 * NETO del IVA declarado.
 *
 * Hasta esta misión `ContabilidadRepository::ventas_brutas()` sumaba `sales.total` (CON IVA) y el
 * reporte le restaba un costo de mercadería vendida que sale de `article_sale.cost` (SIN IVA). El
 * "margen bruto %" salía inflado en todo el IVA débito, y el renglón se llamaba "Ventas netas", que
 * en contabilidad significa justamente sin IVA.
 *
 * 🔴 Igual que en la ganancia por venta, el IVA sale del COMPROBANTE y no de la condición fiscal:
 * una venta sin comprobante entra ENTERA, porque ahí no se declaró nada. Ese es el test 2, y es el
 * que se pone rojo si alguien reemplaza el criterio por un back-out por alícuota.
 *
 * Rango de fechas propio, verificado archivo por archivo: `1_Estado_Resultados_Test.php` usa 2016 y
 * 2019 (enero a junio), `2_Posicion_Fiscal_Test.php` 2019 (julio a diciembre) y 2020,
 * `3_Flujo_De_Caja_Test.php` 2021, `4_Semilla_Test.php` abril de 2018 y
 * `5_Semilla_Mes_En_Curso_Test.php` mayo de 2017. Este archivo usa **2015**, que no aparece en
 * ninguno, con un mes por test.
 *
 * Los comprobantes se crean directo con `AfipTicket::create()` sobre ventas hechas por el endpoint
 * real, el mismo patrón (y por el mismo motivo) que `2_Posicion_Fiscal_Test.php`: emitir de verdad
 * requiere hablar con el webservice de ARCA.
 *
 * @group reportes
 */
class Ventas_Netas_De_Iva_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la carpeta). */
    const DELTA = 0.01;

    /**
     * Ids de los `current_acounts` de nota de crédito creados por este archivo.
     *
     * @var array<int,int>
     */
    protected $notas_credito_sembradas = [];

    /**
     * Ids de los `afip_tickets` creados por este archivo (de ventas y de notas de crédito).
     *
     * @var array<int,int>
     */
    protected $afip_tickets_sembrados = [];

    /**
     * Ids de las ventas CONTENEDORAS de facturación creadas por este archivo.
     *
     * @var array<int,int>
     */
    protected $contenedoras_sembradas = [];

    /**
     * Limpia lo que sembró el test antes del rollback de `DatabaseTransactions` — red real
     * redundante, mismo criterio que `EscenariosDePlata::limpiar_escenarios()`.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (count($this->afip_tickets_sembrados) >= 1) {
            AfipTicket::whereIn('id', $this->afip_tickets_sembrados)->forceDelete();
        }

        if (count($this->notas_credito_sembradas) >= 1) {
            CurrentAcount::whereIn('id', $this->notas_credito_sembradas)->delete();
        }

        if (count($this->contenedoras_sembradas) >= 1) {
            Sale::whereIn('id', $this->contenedoras_sembradas)->forceDelete();
        }

        $this->limpiar_escenarios();

        parent::tearDown();
    }

    /**
     * Test 1 — Venta facturada: el renglón de ventas viene neto del IVA del comprobante, y el
     * drill-down suma exactamente lo mismo que la tarjeta.
     *
     * Venta de $121.000 con un comprobante que declara $21.000 → el reporte informa $100.000.
     *
     * La segunda mitad del test es el contrato tarjeta/detalle: si `ventas_brutas()` neteara y
     * `ventas_brutas_detalle()` no (o al revés), el usuario abriría el renglón y vería otro número.
     *
     * @group reportes
     * @test
     */
    public function venta_facturada_entra_neta_de_iva_y_el_detalle_coincide_con_la_tarjeta()
    {
        $this->fijar_reloj_en('2015-01-10 10:00:00');

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $this->facturar($venta, 21000);

        $estado = $this->pedir_estado_resultados('2015-01-01', '2015-01-31');

        $this->assertEqualsWithDelta(
            100000,
            (float) $estado['ventas_brutas'],
            self::DELTA,
            'La venta facturada tiene que entrar neta del IVA que declaró su comprobante.'
        );

        $this->assertEquals(
            0,
            (int) $estado['ventas_con_iva_sin_medir'],
            'El comprobante tiene su importe_iva medido: el aviso no tiene por qué encenderse.'
        );

        $detalle = $this->pedir_detalle('ventas_brutas', '2015-01-01', '2015-01-31');

        $suma_detalle = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_detalle += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta(
            (float) $estado['ventas_brutas'],
            $suma_detalle,
            self::DELTA,
            'La suma de las líneas del drill-down tiene que coincidir con la tarjeta.'
        );

        $this->assertEqualsWithDelta(
            (float) $estado['ventas_brutas'],
            (float) $detalle['total'],
            self::DELTA,
            'El "total" que devuelve el propio detalle tiene que coincidir con la tarjeta.'
        );

        $this->assertEquals(
            1,
            (int) $detalle['paginacion']['total_registros'],
            'El neteo no puede cambiar la cantidad de renglones del detalle: los joins de IVA no tienen que multiplicar filas.'
        );
    }

    /**
     * Test 2 — Venta SIN comprobante: entra ENTERA.
     *
     * 🔴 Es el caso mayoritario en producción (63 % de las ventas de ferretotal, 51 % de golonorte
     * al 17/9/2026) y el que rompe cualquier back-out de IVA por condición fiscal. Sin comprobante
     * no hay débito fiscal: el IVA cobrado queda en la casa y el ingreso es el total.
     *
     * @group reportes
     * @test
     */
    public function venta_sin_comprobante_entra_entera()
    {
        $this->fijar_reloj_en('2015-02-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $estado = $this->pedir_estado_resultados('2015-02-01', '2015-02-28');

        $this->assertEqualsWithDelta(
            121000,
            (float) $estado['ventas_brutas'],
            self::DELTA,
            'Una venta sin comprobante no declaró IVA: tiene que entrar entera al renglón de ventas.'
        );
    }

    /**
     * Test 3 — Comprobante autorizado SIN `importe_iva` medido: la venta entra entera (no se
     * inventa un IVA) y el aviso `ventas_con_iva_sin_medir` lo denuncia.
     *
     * Es el mismo criterio que `notas_credito_sin_medir()` en la Posición Fiscal: un renglón que
     * quedó sobrevaluado por falta de dato no puede verse igual que uno medido. Se salda midiendo
     * el IVA de esos comprobantes (ver el PHPDoc de `IvaDeVentaHelper`).
     *
     * @group reportes
     * @test
     */
    public function comprobante_sin_iva_medido_deja_la_venta_entera_y_enciende_el_aviso()
    {
        $this->fijar_reloj_en('2015-03-10 10:00:00');

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $this->facturar($venta, null);

        $estado = $this->pedir_estado_resultados('2015-03-01', '2015-03-31');

        $this->assertEqualsWithDelta(
            121000,
            (float) $estado['ventas_brutas'],
            self::DELTA,
            'Sin importe_iva medido no hay número con el cual netear: la venta queda entera, no se estima nada.'
        );

        $this->assertEquals(
            1,
            (int) $estado['ventas_con_iva_sin_medir'],
            'El reporte tiene que avisar que esa venta entró sin netear, para que el renglón alto no pase por medido.'
        );
    }

    /**
     * Test 4 — Nota de crédito facturada: la devolución se resta NETA de su propio IVA.
     *
     * Venta de $121.000 (IVA $21.000) y devolución de $12.100 (IVA $2.100):
     * ventas $100.000 − devoluciones $10.000 = ventas netas $90.000.
     *
     * 🔴 Si las ventas se netearan de IVA y las devoluciones no, el renglón restaría un importe CON
     * IVA a uno SIN IVA ($100.000 − $12.100 = $87.900) y las ventas netas quedarían subvaluadas: la
     * misma mezcla de bases que esta misión vino a sacar, cambiada de signo.
     *
     * @group reportes
     * @test
     */
    public function nota_de_credito_facturada_se_resta_neta_de_su_iva()
    {
        $this->fijar_reloj_en('2015-04-10 10:00:00');

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $this->facturar($venta, 21000);

        $this->crear_nota_credito_facturada($venta, 2100, 12100);

        $estado = $this->pedir_estado_resultados('2015-04-01', '2015-04-30');

        $this->assertEqualsWithDelta(
            10000,
            (float) $estado['devoluciones'],
            self::DELTA,
            'La devolución tiene que restar neta del IVA que canceló su nota de crédito.'
        );

        $this->assertEqualsWithDelta(
            90000,
            (float) $estado['ventas_netas'],
            self::DELTA,
            'Ventas netas = ventas (sin IVA) menos devoluciones (sin IVA).'
        );
    }

    /**
     * Test 5 — Nota de crédito SIN `importe_iva` medido (toda nota de crédito anterior al 1/9/2026
     * está así hasta que se corra `php artisan set_iva_notas_credito`): se resta entera, sin
     * inventar nada.
     *
     * @group reportes
     * @test
     */
    public function nota_de_credito_sin_iva_medido_se_resta_entera()
    {
        $this->fijar_reloj_en('2015-05-10 10:00:00');

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $this->crear_nota_credito_facturada($venta, null, 12100);

        $estado = $this->pedir_estado_resultados('2015-05-01', '2015-05-31');

        $this->assertEqualsWithDelta(
            12100,
            (float) $estado['devoluciones'],
            self::DELTA,
            'Sin IVA medido en la nota de crédito no hay con qué netear: la devolución se resta entera.'
        );
    }

    /**
     * Test 6 — Una venta CONSOLIDADA se netea con su parte del IVA del comprobante de la
     * contenedora, aunque la contenedora se haya creado fuera del período.
     *
     * 🔴 Es el caso que rompe la optimización obvia. Desde el 17/9/2026 las dos subqueries de
     * `IvaDeVentaHelper::aplicar_joins_de_iva()` van acotadas al cliente y al período —sin eso MySQL
     * materializa un `GROUP BY` sobre `afip_tickets` entera, dos veces por query, en cada Estado de
     * Resultados y en cada página del drill-down— y el recorte de cada una es DISTINTO:
     *
     *   - `iva_venta` se acota a las ventas del período (por `sales.id`);
     *   - `iva_consolidacion` se acota a las **contenedoras de esas ventas**, que es otro conjunto.
     *
     * Acotar las dos con el mismo recorte es un bug silencioso: la contenedora se crea el día en que
     * se consolida, que puede caer fuera del período de la venta original. Esa venta quedaría medida
     * en 0 y contada como si hubiera sido en negro — justo lo que `IvaDeVentaHelper` existe para no
     * hacer. Este test es el que se pone rojo si alguien "simplifica" ese recorte.
     *
     * Escenario: venta de $121.000 en junio, dentro de una contenedora de $242.000 creada en JULIO
     * con un comprobante que declaró $42.000 de IVA. Le toca la mitad: 121.000 − 21.000 = 100.000.
     *
     * @group reportes
     * @test
     */
    public function venta_consolidada_se_netea_aunque_la_contenedora_sea_de_otro_periodo()
    {
        $this->fijar_reloj_en('2015-06-10 10:00:00');

        $venta = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 121000);

        $contenedora = $this->crear_contenedora_de_facturacion(242000, '2015-07-05 10:00:00');

        Sale::where('id', $venta->id)->update(['consolidacion_facturacion_id' => $contenedora->id]);

        $this->facturar($contenedora, 42000);

        $estado = $this->pedir_estado_resultados('2015-06-01', '2015-06-30');

        $this->assertEqualsWithDelta(
            100000,
            (float) $estado['ventas_brutas'],
            self::DELTA,
            'La venta consolidada tiene que entrar neta de SU PARTE del IVA del comprobante de la '.
            'contenedora (121.000 − 42.000 × 121.000/242.000 = 100.000). Si dio 121.000, el recorte de '.
            'la subquery de consolidación dejó afuera a la contenedora por ser de otro mes.'
        );

        $detalle = $this->pedir_detalle('ventas_brutas', '2015-06-01', '2015-06-30');

        $suma_detalle = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_detalle += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta(
            (float) $estado['ventas_brutas'],
            $suma_detalle,
            self::DELTA,
            'El drill-down tiene que sumar lo mismo que la tarjeta también con el prorrateo de la consolidación.'
        );

        $this->assertEquals(
            1,
            (int) $detalle['paginacion']['total_registros'],
            'La contenedora no es una venta real: no puede aparecer como un renglón más del detalle.'
        );
    }

    // =========================================================================================
    // Helpers del archivo
    // =========================================================================================

    /**
     * Venta CONTENEDORA de facturación, como la que deja `ConsolidarFacturacionHelper::consolidar()`:
     * `is_consolidacion_facturacion = 1` (o sea que `scopeSoloVentasReales` la excluye del reporte) y
     * `total` igual a la suma de los totales de las ventas que agrupa.
     *
     * @param  float $total
     * @param  string $creada_el Fecha de creación, a propósito distinta de la de sus ventas.
     * @return \App\Models\Sale
     */
    protected function crear_contenedora_de_facturacion($total, $creada_el)
    {
        $contenedora = new Sale();

        $contenedora->user_id = $this->usuario_de_testing()->id;
        $contenedora->total = $total;
        $contenedora->terminada = 1;
        $contenedora->is_consolidacion_facturacion = 1;
        $contenedora->save();

        /* El created_at se escribe aparte porque el modelo lo pisa con el reloj del test al guardar. */
        Sale::where('id', $contenedora->id)->update(['created_at' => Carbon::parse($creada_el)]);

        $this->contenedoras_sembradas[] = $contenedora->id;

        return Sale::find($contenedora->id);
    }


    /**
     * Usuario dueño del fixture de testing.
     *
     * @return \App\Models\User
     */
    protected function usuario_de_testing()
    {
        return User::where('email', TestingFerreteriaSeeder::USER_EMAIL)->firstOrFail();
    }

    /**
     * "Factura" una venta: le crea su `AfipTicket` autorizado.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva Null simula el comprobante viejo con el IVA sin medir.
     * @return \App\Models\AfipTicket
     */
    protected function facturar($venta, $importe_iva)
    {
        $afip_ticket = AfipTicket::create([
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

        $this->afip_tickets_sembrados[] = $afip_ticket->id;

        return $afip_ticket;
    }

    /**
     * "Factura" una nota de crédito: su movimiento de cuenta corriente más el `AfipTicket`
     * autorizado, con la misma forma que deja `AfipNotaCreditoHelper::create_afip_ticket()`.
     *
     * 🔴 `sale_id` va en NULL y el vínculo viaja por `nota_credito_id` / `sale_nota_credito_id`:
     * es lo que hace que el comprobante de la NC no se cuente como IVA de la VENTA (que joinea por
     * `sale_id`). Un fixture con `sale_id` puesto estaría midiendo otro escenario.
     *
     * @param  \App\Models\Sale $venta
     * @param  float|null $importe_iva
     * @param  float $importe_total
     * @return \App\Models\CurrentAcount
     */
    protected function crear_nota_credito_facturada($venta, $importe_iva, $importe_total)
    {
        $nota_credito = CurrentAcount::create([
            'detalle'     => 'Nota Credito de test',
            'description' => 'Devolución de test',
            'haber'       => $importe_total,
            'status'      => 'nota_credito',
            'sale_id'     => $venta->id,
            'user_id'     => $this->usuario_de_testing()->id,
            'moneda_id'   => 1,
        ]);

        $afip_ticket = AfipTicket::create([
            'sale_id'              => null,
            'nota_credito_id'      => $nota_credito->id,
            'sale_nota_credito_id' => $venta->id,
            'resultado'            => 'A',
            'importe_iva'          => $importe_iva,
            'importe_total'        => $importe_total,
            'afip_fecha_emision'   => Carbon::now()->format('Y-m-d'),
            'cbte_numero'          => (string) $nota_credito->id,
            'cbte_letra'           => 'A',
            'cbte_tipo'            => 3,
            'cuit_negocio'         => '20000000000',
            'cae'                  => '00000000000000',
        ]);

        $this->notas_credito_sembradas[] = $nota_credito->id;
        $this->afip_tickets_sembrados[] = $afip_ticket->id;

        return $nota_credito;
    }

    /**
     * Pega contra `GET api/reportes/estado-resultados` y devuelve el array ya decodificado, o corta
     * el test con el cuerpo completo si la respuesta no fue 200.
     *
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_estado_resultados($desde, $hasta)
    {
        $response = $this->getJson('api/reportes/estado-resultados?desde='.$desde.'&hasta='.$hasta.'&moneda=pesos');

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/estado-resultados devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['estado_resultados'])) {
            $this->fail('La respuesta no trae la clave "estado_resultados". Cuerpo completo: '.$response->getContent());
        }

        return $body['estado_resultados'];
    }

    /**
     * Pega contra `GET api/reportes/detalle` (mismo helper que el resto de la carpeta).
     *
     * @param  string $concepto
     * @param  string $desde
     * @param  string $hasta
     * @return array
     */
    protected function pedir_detalle($concepto, $desde, $hasta)
    {
        $response = $this->getJson('api/reportes/detalle?'.http_build_query([
            'concepto' => $concepto,
            'desde'    => $desde,
            'hasta'    => $hasta,
        ]));

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/detalle devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        return json_decode($response->getContent(), true);
    }
}
