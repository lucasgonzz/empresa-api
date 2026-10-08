<?php

namespace Tests\Feature\PresupuestosConVariantes;

use App\Models\ArticleVariant;

/**
 * Archivo 1 — guardar y reabrir un presupuesto con el mismo artículo en dos o más variantes
 * (misión presupuestos-con-variantes, 8/10/2026). Casos 1, 2, 3, 4, 8 (guardado) y 9 del plan.
 *
 * @group presupuestos_con_variantes
 */
class Guardar_y_reabrir_presupuesto_con_variantes_Test extends PresupuestosConVariantesTestCase
{
    /**
     * Caso 1 — alta desde Vender (forma plana) con M×2 y L×3: dos filas en `article_budget`, cada
     * una con su variante y su descripción; el GET del presupuesto las devuelve en el pivot.
     *
     * @test
     */
    public function el_alta_guarda_un_renglon_por_variante_y_el_get_las_devuelve()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
            $this->renglon_plano($e['testigo'], null, 1, 100),
        ], $e['suc2']->id);

        $this->assertCount(2, $this->filas_presupuesto($budget, $e['articulo']), 'Un renglón por variante.');

        $m = $this->fila_presupuesto_de($budget, $e['articulo'], $e['m']);
        $l = $this->fila_presupuesto_de($budget, $e['articulo'], $e['l']);

        $this->assertNotNull($m, 'La fila de la M no guardó su variante.');
        $this->assertNotNull($l, 'La fila de la L no guardó su variante.');
        $this->assertEquals(2.0, (float) $m->amount);
        $this->assertEquals(3.0, (float) $l->amount);
        $this->assertEquals('Talle M', $m->variant_description);
        $this->assertEquals('Talle L', $l->variant_description);

        // El testigo, sin variante.
        $testigo = $this->filas_presupuesto($budget, $e['testigo']);
        $this->assertCount(1, $testigo);
        $this->assertNull($testigo[0]->article_variant_id);
        $this->assertNull($testigo[0]->variant_description);

        // La reapertura: el GET trae la variante en el pivot de cada renglón.
        $articulos = $this->getJson('api/budget/'.$budget->id)->assertStatus(200)->json('model.articles');

        $por_variante = [];

        foreach ($articulos as $articulo) {
            if ((int) $articulo['id'] == $e['articulo']->id) {
                $por_variante[(int) $articulo['pivot']['article_variant_id']] = $articulo['pivot'];
            }
        }

        $this->assertArrayHasKey($e['m']->id, $por_variante, 'El GET no devolvió el renglón de la M con su variante.');
        $this->assertArrayHasKey($e['l']->id, $por_variante, 'El GET no devolvió el renglón de la L con su variante.');
        $this->assertEquals(2.0, (float) $por_variante[$e['m']->id]['amount']);
        $this->assertEquals(3.0, (float) $por_variante[$e['l']->id]['amount']);
        $this->assertEquals('Talle L', $por_variante[$e['l']->id]['variant_description']);
    }

    /**
     * Caso 2 — variante elegida POR NOMBRE en el buscador: la fila de
     * `VenderSearchHelper::build_row` no trae `status`. Hasta hoy el alta moría con 500
     * (`Undefined index: status`, BudgetHelper::attachArticles). Tiene que guardar, con su variante.
     *
     * @test
     */
    public function una_variante_elegida_por_nombre_sin_status_se_guarda_y_no_da_500()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['l'], 2, self::PRECIO, ['status' => '__sacar__']),
        ], $e['suc2']->id);

        $fila = $this->fila_presupuesto_de($budget, $e['articulo'], $e['l']);

        $this->assertNotNull($fila, 'La fila no guardó la variante elegida por nombre.');
        $this->assertEquals(2.0, (float) $fila->amount);
        $this->assertEquals('Talle L', $fila->variant_description);
    }

    /**
     * Caso 3 — update desde Vender con la forma `for_update` (pivot + raíz) cambiando la cantidad
     * de la M: cada fila queda con su variante y la M con la cantidad nueva. Y el form genérico,
     * que re-manda el pivot que leyó (con `article_variant_id` adentro y sin la raíz), también
     * la conserva.
     *
     * @test
     */
    public function el_update_con_pivot_conserva_cada_variante_y_la_cantidad_nueva()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 4),
            $this->renglon_cargado($e['articulo'], $e['l'], 3),
        ])->assertStatus(200);

        $this->assertCount(2, $this->filas_presupuesto($budget, $e['articulo']));
        $this->assertEquals(4.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['m'])->amount, 'La M tiene que quedar con la cantidad nueva.');
        $this->assertEquals(3.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['l'])->amount, 'La L no se tocó.');

        // El form genérico: variante solo en el pivot, sin la clave en la raíz.
        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 4, self::PRECIO, ['article_variant_id' => '__sacar__'], ['article_variant_id' => $e['m']->id]),
            $this->renglon_cargado($e['articulo'], $e['l'], 3, self::PRECIO, ['article_variant_id' => '__sacar__'], ['article_variant_id' => $e['l']->id]),
        ])->assertStatus(200);

        $this->assertEquals(4.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['m'])->amount);
        $this->assertEquals(3.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['l'])->amount);
        $this->assertEquals('Talle M', $this->fila_presupuesto_de($budget, $e['articulo'], $e['m'])->variant_description);
    }

    /**
     * Caso 4 — update SIN la clave en ningún nivel (SPA vieja por la PWA, o un form que no reenvía
     * el pivot entero): se preserva la variante guardada de cada renglón emparejando por id +
     * precio + cantidad. Con la cantidad cambiada no hay pareja: ese renglón queda sin variante (el
     * modo de falla de hoy), y el otro conserva la suya.
     *
     * @test
     */
    public function el_update_sin_la_clave_preserva_la_variante_por_precio_y_cantidad()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        /* Sin cambios: las dos se preservan, cada una en su renglón (no se cruzan). */
        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['l'], 3, self::PRECIO, ['article_variant_id' => '__sacar__']),
            $this->renglon_cargado($e['articulo'], $e['m'], 2, self::PRECIO, ['article_variant_id' => '__sacar__']),
        ])->assertStatus(200);

        $this->assertEquals(2.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['m'])->amount, 'La M se tiene que haber preservado con su cantidad.');
        $this->assertEquals(3.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['l'])->amount, 'La L se tiene que haber preservado con su cantidad.');
        $this->assertEquals('Talle L', $this->fila_presupuesto_de($budget, $e['articulo'], $e['l'])->variant_description);

        /* La M cambia de cantidad (sin pareja): queda sin variante; la L se preserva. */
        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 5, self::PRECIO, ['article_variant_id' => '__sacar__']),
            $this->renglon_cargado($e['articulo'], $e['l'], 3, self::PRECIO, ['article_variant_id' => '__sacar__']),
        ])->assertStatus(200);

        $this->assertNull($this->fila_presupuesto_de($budget, $e['articulo'], $e['m']), 'Con la cantidad cambiada la M no puede conservar la variante.');
        $sin_variante = $this->fila_presupuesto_de($budget, $e['articulo'], null);
        $this->assertNotNull($sin_variante);
        $this->assertEquals(5.0, (float) $sin_variante->amount);
        $this->assertNull($sin_variante->variant_description);
        $this->assertEquals(3.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['l'])->amount, 'La L se preserva.');
    }

    /**
     * Caso 8 (guardado) — varios precios: dos filas de la MISMA variante con precios distintos.
     * Las dos se guardan con la variante.
     *
     * @test
     */
    public function varios_precios_de_una_variante_guardan_la_variante_en_cada_fila()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1, 300),
            $this->renglon_plano($e['articulo'], $e['m'], 2, 250),
        ], $e['suc2']->id);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertEquals($e['m']->id, (int) $fila->article_variant_id, 'Cada fila de varios precios lleva la variante.');
            $this->assertEquals('Talle M', $fila->variant_description);
        }
    }

    /**
     * Caso 9 — una variante que NO es de ese artículo (de otro artículo, o un id que no existe) se
     * guarda sin variante.
     *
     * @test
     */
    public function una_variante_que_no_es_del_articulo_se_guarda_sin_variante()
    {
        $e = $this->escenario();

        $ajena = ArticleVariant::create(['article_id' => $e['testigo']->id, 'variant_description' => 'Ajena', 'stock' => 0]);

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], null, 1, self::PRECIO, ['article_variant_id' => $ajena->id]),
            $this->renglon_plano($e['articulo'], null, 2, 280, ['article_variant_id' => 99999999]),
        ], $e['suc2']->id);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertNull($fila->article_variant_id, 'Una variante ajena no se guarda.');
            $this->assertNull($fila->variant_description);
        }
    }
}
