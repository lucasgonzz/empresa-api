<?php

namespace Tests\Feature\PresupuestosConVariantes;

use App\Models\Budget;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 4 — los bordes que encontraron los chequeos independientes (misión
 * presupuestos-con-variantes, 8/10/2026):
 *
 *  - El emparejamiento por id + precio + cantidad de la SPA vieja (sin la clave) NO puede cruzar
 *    variantes: con talles al mismo precio y la misma cantidad (lo normal) hay varias filas
 *    candidatas, y quedarse con la primera le daba a la L la variante de la M. Al confirmar, eso
 *    descontaba el stock de la variante equivocada: peor que el "sin variante" de antes.
 *  - Una variante borrada después de guardar conserva su descripción (al editar y al duplicar).
 *  - Un artículo inactivo no recibe como nombre el de la fila de la variante.
 *  - El ciclo confirmar → anular → editar → reconfirmar → anular, con el stock por variante y
 *    sucursal en cada paso.
 *
 * @group presupuestos_con_variantes
 */
class Emparejamiento_seguro_de_variantes_Test extends PresupuestosConVariantesTestCase
{
    /** Forma de la SPA vieja: el renglón cargado sin la clave de la variante en ningún nivel. */
    const SIN_CLAVE = ['article_variant_id' => '__sacar__'];

    /**
     * M×1 y L×1 al mismo precio; la SPA vieja borra la M y manda solo la L, sin la clave: la fila
     * que queda no puede quedar como M. Hay dos candidatas con variantes distintas → sin variante.
     *
     * @test
     */
    public function la_spa_vieja_borra_la_m_y_la_l_nunca_queda_como_m()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1),
            $this->renglon_plano($e['articulo'], $e['l'], 1),
        ], $e['suc2']->id);

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['l'], 1, self::PRECIO, self::SIN_CLAVE),
        ])->assertStatus(200);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(1, $filas);
        $this->assertNull($this->fila_presupuesto_de($budget, $e['articulo'], $e['m']), 'La L quedó guardada como M.');
        $this->assertNull($filas[0]->article_variant_id, 'Con candidatas de variantes distintas el renglón va sin variante.');
        $this->assertNull($filas[0]->variant_description);
    }

    /**
     * M×1 y L×1 al mismo precio; la SPA vieja cambia la cantidad de la M a 2: ninguna fila puede
     * quedar con la variante de la otra.
     *
     * @test
     */
    public function la_spa_vieja_cambia_la_cantidad_de_la_m_y_ninguna_fila_se_lleva_la_variante_de_otra()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1),
            $this->renglon_plano($e['articulo'], $e['l'], 1),
        ], $e['suc2']->id);

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 2, self::PRECIO, self::SIN_CLAVE),
            $this->renglon_cargado($e['articulo'], $e['l'], 1, self::PRECIO, self::SIN_CLAVE),
        ])->assertStatus(200);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {

            if ((float) $fila->amount == 2.0) {
                $this->assertNotEquals($e['l']->id, (int) $fila->article_variant_id, 'La fila de la M (×2) quedó como L.');
            } else {
                $this->assertNotEquals($e['m']->id, (int) $fila->article_variant_id, 'La fila de la L (×1) quedó como M.');
            }
        }
    }

    /**
     * Un renglón con variante (M×1) y otro del mismo artículo SIN variante (×1), al mismo precio;
     * la SPA vieja borra el de la variante: el que queda sigue sin variante.
     *
     * @test
     */
    public function un_renglon_con_variante_y_otro_sin_variante_borrar_el_de_variante_deja_el_otro_sin_variante()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1),
            $this->renglon_plano($e['articulo'], null, 1),
        ], $e['suc2']->id);

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], null, 1, self::PRECIO, self::SIN_CLAVE),
        ])->assertStatus(200);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(1, $filas);
        $this->assertNull($filas[0]->article_variant_id, 'El renglón sin variante se llevó la de la M.');
    }

    /**
     * Dos renglones idénticos de la MISMA variante (M×1 a 300, dos veces), sin la clave: las
     * candidatas son unánimes, así que las dos se preservan como M.
     *
     * @test
     */
    public function dos_renglones_identicos_de_la_misma_variante_sin_la_clave_se_preservan()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1),
            $this->renglon_plano($e['articulo'], $e['m'], 1),
        ], $e['suc2']->id);

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 1, self::PRECIO, self::SIN_CLAVE),
            $this->renglon_cargado($e['articulo'], $e['m'], 1, self::PRECIO, self::SIN_CLAVE),
        ])->assertStatus(200);

        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(2, $filas);

        foreach ($filas as $fila) {
            $this->assertEquals($e['m']->id, (int) $fila->article_variant_id, 'Con candidatas unánimes la variante se preserva.');
            $this->assertEquals('Talle M', $fila->variant_description);
        }
    }

    /**
     * El ciclo completo en la sucursal 2: confirmar → anular → editar la M de 2 a 1 → reconfirmar
     * → anular, con el stock de cada variante en cada sucursal en cada paso.
     *
     * @test
     */
    public function ciclo_confirmar_anular_editar_reconfirmar_anular_mueve_cada_variante_en_su_sucursal()
    {
        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        $this->confirmar($budget);

        $this->assert_variante($e, 'm', 6, 2, 'Confirmar');
        $this->assert_variante($e, 'l', 5, 2, 'Confirmar');
        $this->assert_variante($e, 's', 3, 2, 'Confirmar');

        $this->postJson('api/budget/'.$budget->id.'/anular')->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'Anular');
        $this->assert_variante($e, 'l', 5, 5, 'Anular');
        $this->assert_variante($e, 's', 3, 2, 'Anular');

        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 1),
            $this->renglon_cargado($e['articulo'], $e['l'], 3),
        ])->assertStatus(200);

        $this->assert_variante($e, 'm', 6, 4, 'Editar (sin confirmar no mueve stock)');
        $this->assertEquals(1.0, (float) $this->fila_presupuesto_de($budget, $e['articulo'], $e['m'])->amount);

        $venta = $this->confirmar($budget->fresh());

        $this->assertEquals(1.0, (float) $this->fila_de($venta, $e['articulo'], $e['m'])->amount);
        $this->assertEquals(3.0, (float) $this->fila_de($venta, $e['articulo'], $e['l'])->amount);

        $this->assert_variante($e, 'm', 6, 3, 'Reconfirmar');
        $this->assert_variante($e, 'l', 5, 2, 'Reconfirmar');
        $this->assert_variante($e, 's', 3, 2, 'Reconfirmar');

        $this->postJson('api/budget/'.$budget->id.'/anular')->assertStatus(200);

        $this->assertNull(Sale::where('budget_id', $budget->id)->first());

        $this->assert_variante($e, 'm', 6, 4, 'Segundo anular');
        $this->assert_variante($e, 'l', 5, 5, 'Segundo anular');
        $this->assert_variante($e, 's', 3, 2, 'Segundo anular');
        $this->assertEquals(25.0, $this->stock($e['articulo']));
    }

    /**
     * Una variante borrada después de guardar el presupuesto: al editar con la clave (VENDER o el
     * form genérico re-mandan el id viejo) el renglón queda sin id de variante pero conserva
     * "Talle M"; y al duplicar, la copia también la conserva.
     *
     * @test
     */
    public function una_variante_borrada_conserva_la_descripcion_al_editar_y_al_duplicar()
    {
        $this->dar_extension_duplicar();

        $e = $this->escenario();

        $budget = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
        ], $e['suc2']->id);

        // La variante M se borra (las variantes no tienen papelera).
        DB::table('address_article_variant')->where('article_variant_id', $e['m']->id)->delete();
        DB::table('article_variants')->where('id', $e['m']->id)->delete();

        /* Duplicar con la variante ya borrada: la copia conserva la descripción. */
        $copia = Budget::find($this->postJson('api/budget/'.$budget->id.'/duplicate')->assertStatus(201)->json('model.id'));

        $this->assert_m_borrada_con_descripcion($copia, $e, 'Duplicado');

        /* Editar con la clave (el id de la variante borrada): conserva la descripción. */
        $this->actualizar_presupuesto($budget, [
            $this->renglon_cargado($e['articulo'], $e['m'], 2),
            $this->renglon_cargado($e['articulo'], $e['l'], 3),
        ])->assertStatus(200);

        $this->assert_m_borrada_con_descripcion($budget, $e, 'Editado');
    }

    /**
     * Un artículo INACTIVO elegido por su variante: la fila del buscador trae como `name` el de la
     * variante ("... Talle M"), y eso no puede pisar el nombre del artículo padre.
     *
     * @test
     */
    public function un_articulo_inactivo_no_toma_como_nombre_el_de_la_variante()
    {
        $e = $this->escenario();

        DB::table('articles')->where('id', $e['articulo']->id)->update(['status' => 'inactive']);

        $nombre = $e['articulo']->name;

        $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 1, self::PRECIO, [
                'status' => 'inactive',
                'name'   => $nombre.' Talle M',
            ]),
        ], $e['suc2']->id);

        $this->assertEquals($nombre, DB::table('articles')->where('id', $e['articulo']->id)->value('name'), 'El nombre del padre se pisó con el de la variante.');
    }

    /**
     * La fila de la M (×2) quedó sin id de variante, con "Talle M", y la L intacta.
     *
     * @param  \App\Models\Budget  $budget
     * @param  array               $e
     * @param  string              $paso
     * @return void
     */
    protected function assert_m_borrada_con_descripcion($budget, $e, $paso)
    {
        $filas = $this->filas_presupuesto($budget, $e['articulo']);

        $this->assertCount(2, $filas, $paso);

        foreach ($filas as $fila) {

            if ((float) $fila->amount == 2.0) {
                $this->assertNull($fila->article_variant_id, $paso.': la variante borrada no puede quedar guardada.');
                $this->assertEquals('Talle M', $fila->variant_description, $paso.': se perdió la descripción de la variante borrada.');
            } else {
                $this->assertEquals($e['l']->id, (int) $fila->article_variant_id, $paso.': la L se tiene que conservar.');
                $this->assertEquals('Talle L', $fila->variant_description, $paso);
            }
        }
    }
}
