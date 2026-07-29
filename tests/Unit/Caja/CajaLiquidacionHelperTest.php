<?php

namespace Tests\Unit\Caja;

use App\Http\Controllers\Helpers\caja\CajaLiquidacionHelper;
use App\Models\Caja;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Grupo 243 · Prompt 01 — unit tests puros de `CajaLiquidacionHelper::calcular()` y
 * `CajaLiquidacionHelper::calcular_iva_comision()`.
 *
 * Extiende `Tests\TestCase` directo (NO `Tests\EmpresaTestCase`): estos tests no tocan la base de
 * datos en ningún momento, así que no necesitan los guards de entorno (base de testing, motor
 * InnoDB, fixture sembrado) ni autenticar un usuario. El criterio de éxito del prompt es
 * verificable corriendo `--group tesoreria-unit` con la base de datos apagada.
 *
 * `resolve_config()` resuelve el override de `caja_liquidacion_configs` con una query interna
 * (`CajaLiquidacionConfig::where(...)->first()`) y no acepta el override como parámetro — no hay
 * forma de pasarle una instancia armada en memoria desde afuera. Por eso, siguiendo lo que indica
 * el prompt, la cascada de configuración (`resolve_config()`) queda para el prompt 02 como feature
 * test contra la base real, y acá sólo se prueban `calcular()` y `calcular_iva_comision()`.
 *
 * `calcular()` sigue llamando a `resolve_config()` puertas adentro, así que igual dispara esa
 * query interna. Para no tocar la base, se stubea la resolución del override con un mock "alias"
 * de Mockery sobre `CajaLiquidacionConfig`: intercepta la llamada estática a `where()->first()` y
 * la resuelve en memoria (siempre `null`, sin override), sin que la query llegue nunca a la
 * conexión real. El mock de alias reemplaza la clase completa por lo que sólo puede usarse si
 * `CajaLiquidacionConfig` no fue autoloadeada todavía en este proceso — acá se cumple porque los
 * demás tests que sí instancian ese modelo (`tests/Concerns/EscenariosDePlata.php`) lo hacen
 * dentro de métodos que no corren cuando se filtra `--group tesoreria-unit`.
 *
 * FIX (29/7/2026, fallo real contra el grupo 245): el supuesto de arriba ("no fue autoloadeada
 * todavía en este proceso") sólo es cierto si esta clase corre SOLA o filtrada. Corriendo la
 * suite completa, `Mockery::mock('alias:...')` reemplaza `App\Models\CajaLiquidacionConfig` en el
 * autoloader de TODO el proceso PHP, y `Mockery::close()` (que corre en el `tearDown` de todos los
 * tests) NO lo revierte — una clase ya declarada no se puede desdeclarar en PHP. Cualquier test que
 * corra DESPUÉS en el mismo proceso y haga un eager-load de `liquidacion_configs` (la relación de
 * `Caja`, ver `App\Models\Caja::liquidacion_configs()`) termina instanciando el mock en vez del
 * modelo real, llamando métodos no stubeados, y el `BadMethodCallException` resultante lo
 * enmascara Laravel como `RelationNotFoundException: Call to undefined relationship
 * [liquidacion_configs] on model [App\Models\Caja]` — un mensaje que no menciona Mockery para nada
 * y hace pensar que la relación no existe, cuando sí existe. Le costó 9 tests en rojo al grupo 245
 * (`tests/Feature/Reportes/`), que no tenían nada que ver: sus 15 tests pasan 15/15 corriendo
 * solos, y sólo fallan si esta clase corrió antes en el mismo proceso.
 *
 * `@runTestsInSeparateProcesses` + `@preserveGlobalState disabled` es la solución estándar para
 * alias/overload mocks de Mockery: aísla esta clase a su propio proceso PHP, así el alias mock
 * muere con ese proceso y nunca contamina al resto de la suite.
 *
 * @group tesoreria-unit
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CajaLiquidacionHelperTest extends TestCase
{
    /**
     * Delta de tolerancia para comparar floats (mismo criterio que la constante `DELTA` de
     * `Tests\Feature\Compras\ComprasTestCase` y sus hijas: comparar con `assertEqualsWithDelta`,
     * nunca `assertEquals` a secas sobre resultados de coma flotante).
     */
    const DELTA = 0.01;

    /**
     * Antes de cada test: reemplaza `CajaLiquidacionConfig` por un mock de alias de Mockery que
     * responde `null` a `where(...)->first()`, simulando "sin override" sin tocar la base.
     * `calcular()` sólo necesita esto para poder ejecutar `resolve_config()` puertas adentro; los
     * valores de configuración que importan a cada test se setean directo en la instancia de
     * `Caja` en memoria.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock de alias: intercepta la clase completa `CajaLiquidacionConfig` antes de que se
        // autoloadee la real, así ninguna llamada estática hace una query de verdad.
        $mock_config = \Mockery::mock('alias:App\Models\CajaLiquidacionConfig');
        $mock_config->shouldReceive('where')->andReturnSelf();
        $mock_config->shouldReceive('first')->andReturn(null);
    }

    /**
     * tearDown: cierra los mocks de Mockery abiertos en este test (buena práctica estándar de
     * Mockery + PHPUnit, evita que expectativas de un test se filtren al siguiente).
     *
     * @return void
     */
    protected function tearDown(): void
    {
        \Mockery::close();

        parent::tearDown();
    }

    /**
     * Arma una `Caja` en memoria (nunca persistida) con los 5 campos de configuración de
     * liquidación/comisión que necesita `resolve_config()`, con defaults en `null` salvo lo que
     * el test pise explícitamente.
     *
     * @param array<string,mixed> $overrides
     * @return \App\Models\Caja
     */
    protected function caja($overrides = [])
    {
        $caja = new Caja();

        $defaults = [
            'dias_liquidacion'       => null,
            'comision_porcentaje'    => null,
            'expense_concept_id'     => null,
            'comision_iva_alicuota'  => null,
            'comision_iva_incluido'  => null,
        ];

        foreach (array_merge($defaults, $overrides) as $campo => $valor) {
            $caja->$campo = $valor;
        }

        return $caja;
    }

    /** @test */
    public function calcular_con_dias_liquidacion_suma_dias_corridos_no_habiles()
    {
        // 2026-01-03 es sabado; +14 dias corridos cae otra vez sabado 2026-01-17 (no se salta
        // al lunes habil siguiente). Si alguien mete feriados/dias habiles, este test falla.
        $caja = $this->caja(['dias_liquidacion' => 14]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 1000, $fecha);

        $this->assertEquals('2026-01-17', $resultado['fecha_liquidacion_estimada']->format('Y-m-d'));
    }

    /** @test */
    public function calcular_con_dias_liquidacion_null_liquida_en_la_fecha_del_movimiento()
    {
        $caja = $this->caja(['dias_liquidacion' => null]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 1000, $fecha);

        $this->assertEquals('2026-01-03', $resultado['fecha_liquidacion_estimada']->format('Y-m-d'));
    }

    /** @test */
    public function calcular_con_dias_liquidacion_cero_liquida_en_la_fecha_del_movimiento()
    {
        $caja = $this->caja(['dias_liquidacion' => 0]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 1000, $fecha);

        $this->assertEquals('2026-01-03', $resultado['fecha_liquidacion_estimada']->format('Y-m-d'));
    }

    /** @test */
    public function calcular_comision_y_neto_con_porcentaje_configurado()
    {
        // Valores textuales del criterio de exito del grupo 223 / prueba manual 6: no recalcular.
        $caja = $this->caja(['comision_porcentaje' => 6.29]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 100000, $fecha);

        $this->assertEqualsWithDelta(6290, $resultado['comision_calculada'], self::DELTA);
        $this->assertEqualsWithDelta(93710, $resultado['monto_neto_estimado'], self::DELTA);
    }

    /** @test */
    public function calcular_sin_porcentaje_configurado_no_cobra_comision()
    {
        $caja = $this->caja(['comision_porcentaje' => null]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 100000, $fecha);

        $this->assertEqualsWithDelta(0, $resultado['comision_calculada'], self::DELTA);
        $this->assertEqualsWithDelta(100000, $resultado['monto_neto_estimado'], self::DELTA);
    }

    /** @test */
    public function calcular_iva_comision_con_iva_incluido()
    {
        // 6290 * 21 / 121 = 1091.65 (valor textual del grupo 223 / prueba manual 6, no recalcular).
        $iva = CajaLiquidacionHelper::calcular_iva_comision(6290, 21, true);

        $this->assertEqualsWithDelta(1091.65, $iva, self::DELTA);
    }

    /** @test */
    public function calcular_iva_comision_neta_de_iva()
    {
        // 6290 * 21 / 100 = 1320.90 (valor textual del grupo 223 / prueba manual 6, no recalcular).
        $iva = CajaLiquidacionHelper::calcular_iva_comision(6290, 21, false);

        $this->assertEqualsWithDelta(1320.90, $iva, self::DELTA);
    }

    /** @test */
    public function calcular_iva_comision_sin_alicuota_da_cero_sin_dividir_por_cero()
    {
        $this->assertEqualsWithDelta(0, CajaLiquidacionHelper::calcular_iva_comision(6290, 0, true), self::DELTA);
        $this->assertEqualsWithDelta(0, CajaLiquidacionHelper::calcular_iva_comision(6290, null, true), self::DELTA);
        $this->assertEqualsWithDelta(0, CajaLiquidacionHelper::calcular_iva_comision(6290, 0, false), self::DELTA);
        $this->assertEqualsWithDelta(0, CajaLiquidacionHelper::calcular_iva_comision(6290, null, false), self::DELTA);
    }
}
