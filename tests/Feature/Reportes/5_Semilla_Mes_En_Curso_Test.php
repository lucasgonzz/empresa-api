<?php

namespace Tests\Feature\Reportes;

use App\Console\Commands\SembrarDatosDePrueba;
use App\Http\Controllers\Helpers\caja\CajaAperturaHelper;
use App\Http\Controllers\Helpers\CreditAccountHelper;
use App\Models\Address;
use App\Models\Caja;
use App\Models\Client;
use App\Models\DefaultPaymentMethodCaja;
use App\Models\Provider;
use Tests\Concerns\EscenariosDePlata;
use Tests\EmpresaTestCase;

/**
 * Misión "demo lista para grabar" (25/8/2026), punto 2 del plan.
 *
 * 🔴 QUÉ PROTEGE, Y POR QUÉ NO ALCANZABA CON `4_Semilla_Test.php`.
 *
 * El lead que entra a la demo por el magic link cae en
 * `/reportes/estado-de-resultados`, y ese reporte abre SIEMPRE en la solapa "Hoy"
 * (`empresa-spa/src/store/reportes/index.js`: `rango_temporal: 'dia-actual'`, que manda
 * `desde == hasta == hoy`). O sea: lo primero que ve un lead es el Estado de Resultados de UN
 * SOLO DÍA, el de la corrida.
 *
 * `4_Semilla_Test.php` siembra un mes CERRADO (`$es_mes_actual = false`) y compara totales
 * MENSUALES. Ese camino no puede ver el problema de esta misión, que es exclusivo del mes en
 * curso: `planificar_mes()` fecha todo lo que saca plata de una caja en
 * `$fecha_egresos_de_caja`, que en un mes cerrado es el último día del mes a las 20:00 pero en
 * el mes en curso era `Carbon::now()` — el día de la corrida. Con eso, la mitad de los gastos
 * del mes entero (los pagados) caía en el mismo día en que el lead abre el reporte, contra las
 * ventas de ese único día. Medido antes del arreglo: pérdida de $1.478.582 con ventas brutas de
 * $35.000 y gastos al 4.272% de las ventas.
 *
 * Rango de fechas propio, verificado archivo por archivo para no pisar a nadie:
 * `1_Estado_Resultados_Test.php` usa 2019 y 2016, `2_Posicion_Fiscal_Test.php` 2019 y 2020,
 * `3_Flujo_De_Caja_Test.php` 2021 y `4_Semilla_Test.php` abril de 2018. Este archivo usa
 * MAYO DE 2017, que no aparece en ninguno.
 *
 * El reloj se fija a propósito (no se usa la fecha real): `planificar_mes()` resuelve TODO el mes
 * en curso a partir de `Carbon::now()`, así que con el reloj fijado el escenario es
 * autoconsistente y determinista — corra el test el día del mes que corra.
 *
 * El fixture (`preparar_fixture_de_semilla()`) está duplicado de `4_Semilla_Test.php` a
 * propósito: ese archivo está declarado intocable en el PHPDoc de
 * `SembrarDatosDePrueba::CLAVE_POR_METODO`, así que no se extrae a un trait compartido.
 *
 * @group reportes
 * @group semilla
 */
class Semilla_Mes_En_Curso_Test extends EmpresaTestCase
{
    use EscenariosDePlata;

    /** Día del mes en curso en el que "corre la demo" y en el que el lead abre el reporte. */
    const DIA_DE_LA_CORRIDA = '2017-05-17';

    /**
     * Ventas de mostrador que `SembrarDatosDePrueba::sembrar_hoy()` deja en el día de la corrida:
     * 15.000 + 20.000. La venta sin terminar de 8.000 del mismo bloque NO suma acá —
     * `ContabilidadRepository::query_ventas_brutas()` filtra `sales.terminada = 1`.
     */
    const VENTAS_DEL_BLOQUE_DE_HOY = 35000;

    /** Métodos de pago de cuenta corriente que necesita el fixture (ver CurrentAcountPaymentMethodSeeder). */
    const METODOS_DE_PAGO = [
        3 => 'efectivo',
        6 => 'mercado_pago',
        4 => 'banco',
        1 => 'cheques',
    ];

    /** Renglón de control que devolvió `sembrar_mes()` para el mes en curso sembrado por este test. */
    protected $control;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->preparar_fixture_de_semilla();

        $this->fijar_reloj_en(self::DIA_DE_LA_CORRIDA.' 14:00:00');

        $comando = new SembrarDatosDePrueba();
        $comando->preparar_para_test();

        // 🔴 `true` = mes EN CURSO. Es el único valor que reproduce lo que ve un lead en la demo.
        $this->control = $comando->sembrar_mes(0, (float) config('semilla.ventas_mes_base'), true);

        // Y el bloque especial de hoy, que es de dónde salen las ÚNICAS ventas del día de la
        // corrida (2 de mostrador, 15.000 + 20.000). `handle()` lo llama después del loop de
        // meses; sin él, el día de la corrida no tiene una sola venta y el test mediría un
        // escenario que no existe en la demo.
        $this->fijar_reloj_en(self::DIA_DE_LA_CORRIDA.' 14:00:00');
        $comando->sembrar_hoy();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        try {
            $comando = new SembrarDatosDePrueba();
            $comando->preparar_para_test();
            $comando->limpiar_para_test();

            $this->limpiar_escenarios();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Mismo fixture que `4_Semilla_Test.php`: 4 sucursales, una caja por método de pago
     * (compartida entre las 4, ya abierta) y `credit_account` para cada cliente y proveedor.
     *
     * @return void
     */
    protected function preparar_fixture_de_semilla()
    {
        $user_id = config('semilla.user_id');

        $direcciones = Address::where('user_id', $user_id)->orderBy('id')->get();

        while ($direcciones->count() < 4) {
            $nueva = Address::create([
                'street'  => 'Sucursal semilla '.($direcciones->count() + 1),
                'user_id' => $user_id,
            ]);
            $direcciones->push($nueva);
        }

        DefaultPaymentMethodCaja::where('user_id', $user_id)
            ->whereIn('current_acount_payment_method_id', array_keys(self::METODOS_DE_PAGO))
            ->delete();

        $siguiente_num = (int) (Caja::where('user_id', $user_id)->max('num') ?? 0) + 1;

        foreach (self::METODOS_DE_PAGO as $metodo_id => $clave) {
            $caja = Caja::create([
                'num'     => $siguiente_num,
                'name'    => 'Caja semilla '.$clave,
                'user_id' => $user_id,
                'saldo'   => 0,
            ]);
            $siguiente_num++;

            (new CajaAperturaHelper($caja->id))->abrir_caja();

            foreach ($direcciones as $direccion) {
                DefaultPaymentMethodCaja::create([
                    'current_acount_payment_method_id' => $metodo_id,
                    'address_id'                       => $direccion->id,
                    'caja_id'                          => $caja->id,
                    'user_id'                          => $user_id,
                ]);
            }
        }

        foreach (Client::where('user_id', $user_id)->get() as $cliente) {
            CreditAccountHelper::crear_credit_accounts('client', $cliente->id, $user_id);
        }

        foreach (Provider::where('user_id', $user_id)->get() as $proveedor) {
            CreditAccountHelper::crear_credit_accounts('provider', $proveedor->id, $user_id);
        }
    }

    /**
     * @param string $desde
     * @param string $hasta
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
     * 🔴 EL TEST DE LA MISIÓN: el día de la corrida —el mismo que el lead ve en la solapa "Hoy"—
     * no puede cerrar en pérdida.
     *
     * @return void
     */
    public function test_el_dia_de_la_corrida_no_cierra_en_perdida()
    {
        $hoy = self::DIA_DE_LA_CORRIDA;

        $estado = $this->pedir_estado_resultados($hoy, $hoy);

        $this->assertGreaterThanOrEqual(
            0,
            (float) $estado['resultado_neto'],
            'El Estado de Resultados del día de la corrida cerró en pérdida ('.$estado['resultado_neto'].'). '.
            'Es la primera pantalla que ve un lead al entrar a la demo por el magic link. '.
            'Ventas brutas del día: '.$estado['ventas_brutas'].' · Gastos operativos: '.$estado['gastos_operativos'].'.'
        );
    }

    /**
     * La causa medida, aislada: ningún gasto del mes queda fechado en el día de la corrida. Es la
     * aserción que explica POR QUÉ el test de arriba pasa, y la que se rompe si alguien vuelve a
     * fechar los egresos del mes en curso en `Carbon::now()`.
     *
     * Se puede afirmar el CERO, no un umbral: `fecha_en_rango()` reparte los gastos impagos sobre
     * `dias_disponibles`, que sale de `diffInDays` y por lo tanto nunca alcanza el día de la
     * corrida; y los pagados van todos a `$fecha_egresos_de_caja`, que desde esta misión es el día
     * anterior a las 20:00.
     *
     * @return void
     */
    public function test_ningun_gasto_del_mes_cae_en_el_dia_de_la_corrida()
    {
        $hoy = self::DIA_DE_LA_CORRIDA;

        $del_dia = $this->pedir_estado_resultados($hoy, $hoy);

        $this->assertEqualsWithDelta(
            0,
            (float) $del_dia['gastos_operativos'],
            0.01,
            'Cayeron gastos del mes en el día de la corrida, que es el que ve el lead en la solapa "Hoy".'
        );

        // Y las ventas del bloque de hoy sí tienen que estar: 15.000 + 20.000 de sembrar_hoy().
        $this->assertEqualsWithDelta(
            self::VENTAS_DEL_BLOQUE_DE_HOY,
            (float) $del_dia['ventas_brutas'],
            0.01,
            'El día de la corrida no tiene las dos ventas de mostrador de sembrar_hoy().'
        );
    }

    /**
     * Control de que el arreglo no se comió plata: el mes completo tiene que seguir mostrando
     * exactamente los gastos que declara la planilla de `sembrar_mes()`.
     *
     * @return void
     */
    public function test_el_mes_en_curso_completo_conserva_los_gastos_de_la_planilla()
    {
        $desde = '2017-05-01';
        $hasta = self::DIA_DE_LA_CORRIDA;

        $del_mes = $this->pedir_estado_resultados($desde, $hasta);

        $this->assertEqualsWithDelta(
            (float) $this->control['gastos'],
            (float) $del_mes['gastos_operativos'],
            0.01,
            'Los gastos del mes en curso no coinciden con la planilla de control de sembrar_mes().'
        );

        // La planilla de `sembrar_mes()` no incluye el bloque de hoy (lo siembra `handle()` aparte),
        // así que el mes completo tiene que dar la planilla MÁS esas dos ventas de mostrador.
        $this->assertEqualsWithDelta(
            (float) $this->control['ventas_brutas'] + self::VENTAS_DEL_BLOQUE_DE_HOY,
            (float) $del_mes['ventas_brutas'],
            0.01,
            'Las ventas brutas del mes en curso no coinciden con la planilla de control más el bloque de hoy.'
        );
    }
}
