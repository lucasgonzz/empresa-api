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
 * Correr esta clase en un proceso PHP aparte, con el estado global SIN preservar, es la solución
 * estándar para alias/overload mocks de Mockery: aísla esta clase a su propio proceso PHP, así el
 * alias mock muere con ese proceso y nunca contamina al resto de la suite. Las dos anotaciones que
 * lo hacen están abajo.
 *
 * 🔴 NO ESCRIBIR NINGUNA ANOTACIÓN CON ARROBA EN LA PROSA DE ESTE DOCBLOCK, ni siquiera entre
 * backticks (misión 14, 10/8/2026). El parser de anotaciones de PHPUnit no distingue prosa de
 * anotación: barre el docblock entero con una regex y acumula TODOS los pares nombre/valor con
 * arroba que encuentra, en orden de aparición. Y `Util\Test::getBooleanAnnotationSetting()` mira el
 * PRIMERO (`$annotations['class'][$setting][0]`). Este mismo párrafo decía antes
 * "preserveGlobalState disabled es la solución estándar para..." con la arroba puesta, así que el
 * valor [0] de la anotación era el renglón de prosa entero — ni 'enabled' ni 'disabled' — y el
 * parser devolvía null. Con null, TestBuilder nunca llama a setPreserveGlobalState() y queda el
 * default de PHPUnit, que es `protected $preserveGlobalState = true`: exactamente lo contrario de
 * lo que este docblock creía estar declarando. La anotación de al lado no se veía afectada porque
 * de ella sólo se pregunta isset(), nunca el valor. El riesgo no es exclusivo de estas dos: el
 * parser es igual de ciego para todas, así que un `covers` o un `dataProvider` escritos en prosa
 * romperían otra cosa distinta y con la misma falta de aviso.
 *
 * Y para que la regla de arriba no sea la ÚNICA defensa, abajo se declara además la propiedad
 * `$preserveGlobalState = false`. Es inmune al parser de docblocks y no pelea con la anotación:
 * `TestBuilder` sólo pisa la propiedad cuando la anotación resuelve a algo distinto de null, así
 * que si mañana alguien vuelve a romper el docblock, el default deja de ser `true` y el modo de
 * falla —verde filtrado, 13 rojos sólo en suite completa— no puede volver.
 *
 * @group tesoreria-unit
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class CajaLiquidacionHelperTest extends TestCase
{
    /**
     * Red de seguridad de la anotación de arriba, explicada en el docblock de la clase: si el
     * parser vuelve a leer mal el docblock, esto evita que el default `true` de PHPUnit haga que
     * el proceso hijo herede los archivos incluidos del padre.
     *
     * @var bool
     */
    protected $preserveGlobalState = false;

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

    /**
     * Prompt 380/01 — tiene_configuracion() con el mock de alias (sin override, `resolve_config()`
     * cae siempre al valor de la propia $caja): cubre los tres campos que SI cuentan como régimen
     * (dias_liquidacion en null/con valor/en 0 explícito, comision_porcentaje) y uno de los que NO
     * cuentan (expense_concept_id, que es parámetro del gasto, no del régimen). El caso del override
     * en 0 explícito (donde SÍ importa distinguir null de 0) queda para el feature test contra la
     * base real (`Tests\Feature\Tesoreria\Gasto_Comision_Test::override_con_dias_liquidacion_cero_sigue_contando_como_configuracion`),
     * porque este mock siempre devuelve "sin override".
     */

    /** @test */
    public function tiene_configuracion_da_false_sin_dias_ni_comision()
    {
        $caja = $this->caja();

        $this->assertFalse(CajaLiquidacionHelper::tiene_configuracion($caja, 1));
    }

    /** @test */
    public function tiene_configuracion_da_true_con_dias_liquidacion_cargado()
    {
        $caja = $this->caja(['dias_liquidacion' => 14]);

        $this->assertTrue(CajaLiquidacionHelper::tiene_configuracion($caja, 1));
    }

    /** @test */
    public function tiene_configuracion_da_true_con_dias_liquidacion_cero_explicito()
    {
        // 0 explicito en la propia caja (no un override): tiene que seguir contando, is_null(0) es
        // false. El caso mas delicado (0 en el OVERRIDE, donde resolve_config() tiene que preferir
        // el 0 por sobre el valor de la caja) esta cubierto en el feature test, ver docblock de arriba.
        $caja = $this->caja(['dias_liquidacion' => 0]);

        $this->assertTrue(CajaLiquidacionHelper::tiene_configuracion($caja, 1));
    }

    /** @test */
    public function tiene_configuracion_da_true_con_comision_porcentaje_cargado()
    {
        $caja = $this->caja(['comision_porcentaje' => 6.29]);

        $this->assertTrue(CajaLiquidacionHelper::tiene_configuracion($caja, 1));
    }

    /** @test */
    public function tiene_configuracion_da_false_con_solo_expense_concept_id_cargado()
    {
        // expense_concept_id es parametro del GASTO de comision (a donde se registra), no del
        // regimen de liquidacion: una caja con esto cargado pero sin dias ni porcentaje no liquida
        // nada, y tiene_configuracion() tiene que seguir dando false.
        $caja = $this->caja(['expense_concept_id' => 1]);

        $this->assertFalse(CajaLiquidacionHelper::tiene_configuracion($caja, 1));
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
    public function calcular_neta_iva_de_la_comision_cuando_no_esta_incluido()
    {
        // Mismo caso del hallazgo 20260810-el-neto-estimado-de-liquidacion-ignora-el-iva-de-la-comision:
        // comision 6290 (6.29% de 100000), IVA neto 1320.90 (6290 * 21 / 100), total retenido
        // 7610.90. Antes del fix el neto daba 93710 (solo restaba la comision); confirmado por
        // Lucas el 25/8/2026 que la entidad SI retiene comision + IVA, asi que el neto correcto
        // es 92389.10, el mismo total que crear_gasto_comision() ya le cobraba al Expense.
        $caja = $this->caja([
            'comision_porcentaje'   => 6.29,
            'comision_iva_alicuota' => 21,
            'comision_iva_incluido' => false,
        ]);
        $fecha = Carbon::parse('2026-01-03');

        $resultado = CajaLiquidacionHelper::calcular($caja, 1, 100000, $fecha);

        $this->assertEqualsWithDelta(6290, $resultado['comision_calculada'], self::DELTA);
        $this->assertEqualsWithDelta(92389.10, $resultado['monto_neto_estimado'], self::DELTA);
    }

    /** @test */
    public function calcular_no_neta_iva_de_la_comision_cuando_ya_esta_incluido()
    {
        // Con IVA incluido la comision YA es el total: no hay nada mas que descontar del neto.
        // 6290 * 21 / 121 = 1091.65 de IVA discriminado adentro de esos mismos 6290 (no se resta
        // de nuevo), asi que el neto es igual al de antes del fix: monto - comision, sin mas.
        $caja = $this->caja([
            'comision_porcentaje'   => 6.29,
            'comision_iva_alicuota' => 21,
            'comision_iva_incluido' => true,
        ]);
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
