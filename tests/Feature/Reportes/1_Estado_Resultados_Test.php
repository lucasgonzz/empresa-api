<?php

namespace Tests\Feature\Reportes;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Grupo 245 · Prompt 01 — tests del Estado de Resultados devengado (`GET api/reportes/estado-resultados`,
 * grupo 225 prompt 01) y de su drill-down (`GET api/reportes/detalle`, grupo 226 prompt 02).
 *
 * Reemplaza las pruebas manuales pendientes 1 a 5 del grupo `reportes-estado-resultados-posicion-fiscal`
 * y la 3 de `reportes-ui` (el total del detalle coincide con el de la tarjeta).
 *
 * Estrategia (ver el prompt): cada test siembra su propio escenario acotado con
 * `Tests\Concerns\EscenariosDePlata` (ventas de montos conocidos, gastos de montos conocidos) en un
 * rango de fechas EXCLUSIVO — un mes distinto por test, todos lejos en el pasado (2016 y 2019) — y
 * consulta el endpoint con ESE rango. Así el resultado no depende de lo que haya en la base ni de
 * otros tests. Las aserciones son sobre relaciones entre renglones (restas que tienen que cerrar),
 * nunca sobre números absolutos memorizados de antemano, salvo cuando el número es directamente el
 * monto que el propio test acaba de sembrar (ancla, no número mágico).
 *
 * Ubicación del reloj: todo se hace vía `fijar_reloj_en()` del trait (grupo 260). Está prohibido
 * llamar a `Carbon::setTestNow()` directo desde acá — ver el PHPDoc de ese método para el bug
 * concreto que evita.
 *
 * @group reportes
 */
class Estado_Resultados_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Delta de tolerancia para comparar floats (mismo criterio que el resto de la suite). */
    const DELTA = 0.01;

    /**
     * Slug de la extensión que habilita ventas en dólares (grupo 225). El fixture de testing
     * (`TestingFerreteriaSeeder`) no corre `ExtencionSeeder` ni la deja activada para ningún usuario,
     * así que los tests 3 y 4 la prenden/apagan a mano.
     */
    const SLUG_EXTENSION_DOLARES = 'ventas_en_dolares';

    /**
     * Marca si este test dejó la extensión de dólares prendida, para revertirla en el tearDown (ver
     * activar_extension_ventas_dolares()/desactivar_extension_ventas_dolares()).
     *
     * @var bool
     */
    protected $extension_dolares_activada_en_este_test = false;

    /**
     * Libera todo lo que EscenariosDePlata haya creado en este test (ventas, gastos, movimientos de
     * caja) y revierte la extensión de dólares si este test la prendió, antes del rollback de
     * DatabaseTransactions. Corre siempre, incluso si una aserción cortó el test a mitad de camino.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->limpiar_escenarios();

        if ($this->extension_dolares_activada_en_este_test) {
            $this->desactivar_extension_ventas_dolares();
        }

        parent::tearDown();
    }

    /**
     * Prende la extensión `ventas_en_dolares` para el usuario de testing. No hay endpoint de alta de
     * extensiones, así que se hace por relación directa (`attach`), único camino disponible — crea el
     * catálogo (`extencion_empresas`) si todavía no existe, ya que el fixture de testing no corre
     * `ExtencionSeeder`.
     *
     * @return void
     */
    protected function activar_extension_ventas_dolares()
    {
        $extension = ExtencionEmpresa::where('slug', self::SLUG_EXTENSION_DOLARES)->first();

        if (is_null($extension)) {
            $extension = new ExtencionEmpresa();
            $extension->name = 'Ventas en dólares';
            $extension->slug = self::SLUG_EXTENSION_DOLARES;
            $extension->save();
        }

        $this->usuario_de_testing()->extencions()->syncWithoutDetaching([$extension->id]);

        $this->extension_dolares_activada_en_este_test = true;
    }

    /**
     * Apaga la extensión de dólares para el usuario de testing (desvincula el pivot; el catálogo
     * queda, es dato compartido y no molesta a otros tests).
     *
     * @return void
     */
    protected function desactivar_extension_ventas_dolares()
    {
        $extension = ExtencionEmpresa::where('slug', self::SLUG_EXTENSION_DOLARES)->first();

        if (!is_null($extension)) {
            $this->usuario_de_testing()->extencions()->detach($extension->id);
        }

        $this->extension_dolares_activada_en_este_test = false;
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
     * Pega contra `GET api/reportes/estado-resultados` y devuelve el array `estado_resultados` ya
     * decodificado, o corta el test con `fail()` con el detalle completo si la respuesta no fue 200.
     *
     * @param string $desde
     * @param string $hasta
     * @param string $moneda 'pesos' | 'dolares' | 'consolidado'.
     * @return array
     */
    protected function pedir_estado_resultados($desde, $hasta, $moneda = 'pesos')
    {
        $response = $this->getJson('api/reportes/estado-resultados?desde='.$desde.'&hasta='.$hasta.'&moneda='.$moneda);

        if ($response->getStatusCode() !== 200) {
            $this->fail('GET api/reportes/estado-resultados devolvió '.$response->getStatusCode().'. Cuerpo completo: '.$response->getContent());
        }

        $body = json_decode($response->getContent(), true);

        if (!isset($body['estado_resultados'])) {
            $this->fail('La respuesta de GET api/reportes/estado-resultados no trae la clave "estado_resultados". Cuerpo completo: '.$response->getContent());
        }

        return $body['estado_resultados'];
    }

    /**
     * Pega contra `GET api/reportes/detalle` y devuelve el body ya decodificado, o corta el test con
     * `fail()` con el detalle completo si la respuesta no fue 200.
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
     * Test 1 — Cascada completa: 2 ventas y 1 gasto operativo conocidos en enero de 2019 (rango
     * exclusivo de este test, nadie más siembra nada ahí). Cada renglón intermedio del Estado de
     * Resultados tiene que ser consistente con el anterior: ventas netas = brutas − devoluciones;
     * margen bruto = netas − costo de ventas; resultado neto = margen bruto − gastos operativos. Se
     * assertean las restas, no valores absolutos memorizados: las ventas de este escenario no llevan
     * items ni comisión, así que devoluciones, costo de mercadería y comisiones de cobro dan 0, y sin
     * items tampoco hay base gravada para el IIBB determinado (también 0).
     *
     * @group reportes
     * @test
     */
    public function cascada_completa_de_ventas_y_gasto_operativo_cierra_renglon_por_renglon()
    {
        $this->fijar_reloj_en('2019-01-10 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 50000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 30000);

        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 10000);

        $estado = $this->pedir_estado_resultados('2019-01-01', '2019-01-31');

        // Ancla: la suma de las dos ventas y el gasto que este mismo test acaba de sembrar, no un
        // número mágico traído de afuera.
        $this->assertEqualsWithDelta(80000, (float) $estado['ventas_brutas'], self::DELTA);
        $this->assertEqualsWithDelta(10000, (float) $estado['gastos_operativos'], self::DELTA);

        // Cascada: cada renglón consistente con el anterior (restas, no memorizados).
        $this->assertEqualsWithDelta(
            (float) $estado['ventas_brutas'] - (float) $estado['devoluciones'],
            (float) $estado['ventas_netas'],
            self::DELTA,
            'ventas_netas tiene que ser ventas_brutas menos devoluciones.'
        );

        $this->assertEqualsWithDelta(
            (float) $estado['ventas_netas'] - ((float) $estado['costo_mercaderia_vendida'] - (float) $estado['costo_mercaderia_devuelta']),
            (float) $estado['resultado_bruto'],
            self::DELTA,
            'resultado_bruto tiene que ser ventas_netas menos el costo neto de mercadería.'
        );

        $this->assertEqualsWithDelta(
            (float) $estado['resultado_bruto'] - (float) $estado['gastos_operativos'],
            (float) $estado['resultado_operativo'],
            self::DELTA,
            'resultado_operativo tiene que ser resultado_bruto menos gastos_operativos.'
        );

        $this->assertEqualsWithDelta(
            (float) $estado['resultado_operativo'] - (float) $estado['iibb_determinado'] - (float) $estado['comisiones_de_cobro'],
            (float) $estado['resultado_neto'],
            self::DELTA,
            'resultado_neto tiene que ser resultado_operativo menos IIBB determinado menos comisiones de cobro.'
        );

        // Márgenes: derivados de las mismas restas, con ventas netas > 0 en este escenario.
        $this->assertEqualsWithDelta(
            ((float) $estado['resultado_bruto'] / (float) $estado['ventas_netas']) * 100,
            (float) $estado['margen_bruto_porcentaje'],
            self::DELTA
        );

        $this->assertEqualsWithDelta(
            ((float) $estado['resultado_neto'] / (float) $estado['ventas_netas']) * 100,
            (float) $estado['margen_neto_porcentaje'],
            self::DELTA
        );
    }

    /**
     * Test 2 — Mes sin ninguna venta: no se siembra nada en febrero de 2019 (rango exclusivo). El
     * endpoint tiene que responder 200 igual, y los márgenes vienen `null` (no `0`): la prueba manual
     * dice que en la UI se ven como una raya, y una raya en pantalla sale de un `null` en el payload,
     * no de un cero.
     *
     * @group reportes
     * @test
     */
    public function mes_sin_ventas_responde_200_con_margenes_en_null()
    {
        $estado = $this->pedir_estado_resultados('2019-02-01', '2019-02-28');

        $this->assertEqualsWithDelta(0, (float) $estado['ventas_netas'], self::DELTA);
        $this->assertNull($estado['margen_bruto_porcentaje'], 'margen_bruto_porcentaje debería ser null, no 0, en un mes sin ventas.');
        $this->assertNull($estado['margen_neto_porcentaje'], 'margen_neto_porcentaje debería ser null, no 0, en un mes sin ventas.');
    }

    /**
     * Test 3 — Consolidado con la extensión de dólares activa: un gasto operativo del mismo concepto
     * en pesos y otro en dólares (marzo de 2019, rango exclusivo) tienen que combinarse en UN solo
     * renglón de `gastos_por_categoria` (pesos + dólares×cotización), no en dos renglones duplicados
     * para el mismo concepto — que es exactamente donde vive la duplicación de "dos tandas" que
     * describe el prompt.
     *
     * @group reportes
     * @test
     */
    public function consolidado_con_extension_de_dolares_combina_gastos_del_mismo_concepto_en_un_solo_renglon()
    {
        $this->activar_extension_ventas_dolares();

        $this->fijar_reloj_en('2019-03-10 10:00:00');

        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 5000, ['moneda_id' => 1]);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 3, ['moneda_id' => 2]);

        $estado = $this->pedir_estado_resultados('2019-03-01', '2019-03-31', 'consolidado');

        $this->assertEquals('consolidado', $estado['moneda']);

        // Una sola grilla: un único renglón de gastos_por_categoria para el concepto sembrado, no dos.
        $this->assertCount(
            1,
            $estado['gastos_por_categoria'],
            'gastos_por_categoria debería traer un único renglón combinado (pesos+dólares) para el concepto, no uno por moneda.'
        );

        $cotizacion = (float) $this->usuario_de_testing()->dollar;

        // 5000 (pesos) + 3 * cotización (dólares convertidos), sin duplicar el renglón.
        $this->assertEqualsWithDelta(5000 + (3 * $cotizacion), (float) $estado['gastos_por_categoria'][0]['total'], self::DELTA);
    }

    /**
     * Test 4 — Sin la extensión de dólares (estado por default del fixture: ningún usuario la tiene
     * activada): aunque el request pida `moneda=consolidado`, el reporte no tiene forma de exponer esa
     * dimensión. Es un test de estructura, no visual: el payload vuelve forzado a pesos, sin
     * cotización estimada ni líneas marcadas como no atribuibles a una moneda que nunca se calculó.
     *
     * @group reportes
     * @test
     */
    public function sin_extension_de_dolares_el_payload_no_expone_la_dimension_de_moneda()
    {
        $estado = $this->pedir_estado_resultados('2019-04-01', '2019-04-30', 'consolidado');

        $this->assertEquals('pesos', $estado['moneda'], 'Sin la extensión, el parámetro moneda tiene que ignorarse y el reporte forzarse a pesos.');
        $this->assertFalse($estado['cotizacion_estimada']);
        $this->assertEquals([], $estado['lineas_no_atribuibles_a_moneda']);
    }

    /**
     * Test 5 — Rango de fechas inclusivo en ambos extremos: una venta el primer día de mayo de 2019 y
     * otra el último tienen que entrar las dos. Antes de assertear, se verifica que las dos ventas
     * cayeron efectivamente en días distintos (guard explícito del prompt): si `fijar_reloj_en()` no
     * separó el reloj como se espera, este test no estaría probando el límite del rango y hay que
     * pararlo y reportarlo, no ajustar la aserción.
     *
     * @group reportes
     * @test
     */
    public function rango_de_fechas_incluye_venta_del_primer_dia_y_del_ultimo_dia()
    {
        $this->fijar_reloj_en('2019-05-01 08:00:00');
        $venta_primer_dia = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 12000);

        $this->fijar_reloj_en('2019-05-31 22:00:00');
        $venta_ultimo_dia = $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 15000);

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

        $estado = $this->pedir_estado_resultados('2019-05-01', '2019-05-31');

        $this->assertEqualsWithDelta(12000 + 15000, (float) $estado['ventas_brutas'], self::DELTA);
    }

    /**
     * Test 6 — Drill-down consistente: la suma de las líneas de `GET api/reportes/detalle` para el
     * concepto `gastos` (junio de 2019, rango exclusivo) tiene que ser exactamente igual al monto de
     * la tarjeta "Gastos operativos" del Estado de Resultados para el mismo rango. Es la aserción que
     * atrapa que la tarjeta y el detalle usen filtros distintos (prueba manual 3 de `reportes-ui`).
     *
     * @group reportes
     * @test
     */
    public function tarjeta_de_gastos_operativos_coincide_con_la_suma_del_detalle()
    {
        $this->fijar_reloj_en('2019-06-15 10:00:00');

        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 7000);
        $this->crear_gasto(TestingFerreteriaSeeder::CONCEPTO_GASTO_OPERATIVO, 2500);

        $estado = $this->pedir_estado_resultados('2019-06-01', '2019-06-30');
        $monto_tarjeta = (float) $estado['gastos_operativos'];

        $detalle = $this->pedir_detalle('gastos', '2019-06-01', '2019-06-30');

        $suma_detalle = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_detalle += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta($monto_tarjeta, $suma_detalle, self::DELTA, 'La suma de las líneas del detalle tiene que coincidir con la tarjeta.');
        $this->assertEqualsWithDelta($monto_tarjeta, (float) $detalle['total'], self::DELTA, 'El "total" que devuelve el propio detalle tiene que coincidir con la tarjeta.');
    }

    /**
     * Test 7 — Drill-down paginado: pedir el detalle de ventas brutas en un rango grande (todo 2016,
     * rango exclusivo de este test) devuelve 200 y una estructura paginada completa, sin timeout.
     *
     * @group reportes
     * @test
     */
    public function drill_down_de_ventas_brutas_en_rango_grande_devuelve_estructura_paginada()
    {
        $this->fijar_reloj_en('2016-06-15 10:00:00');

        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 1000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 2000);
        $this->crear_venta_cobrada(TestingFerreteriaSeeder::CAJA_EFECTIVO, TestingFerreteriaSeeder::PAGO_EFECTIVO, 3000);

        $detalle = $this->pedir_detalle('ventas_brutas', '2016-01-01', '2016-12-31');

        $this->assertArrayHasKey('registros', $detalle);
        $this->assertArrayHasKey('paginacion', $detalle);
        $this->assertArrayHasKey('page', $detalle['paginacion']);
        $this->assertArrayHasKey('per_page', $detalle['paginacion']);
        $this->assertArrayHasKey('total_registros', $detalle['paginacion']);

        $this->assertEquals(3, $detalle['paginacion']['total_registros']);
        $this->assertLessThanOrEqual($detalle['paginacion']['per_page'], count($detalle['registros']));

        $suma_registros = 0.0;
        foreach ($detalle['registros'] as $registro) {
            $suma_registros += (float) $registro['monto'];
        }

        $this->assertEqualsWithDelta(1000 + 2000 + 3000, $suma_registros, self::DELTA);
    }
}
