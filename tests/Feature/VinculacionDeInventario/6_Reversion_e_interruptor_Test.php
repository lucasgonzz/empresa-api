<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * CA13 (interruptor) y CA14 (`--revertir-todas`, clase A) del PLAN D2-M R3.1.
 *
 * Formato de la salida del comando que fijan estos tests (el resto de la salida es libre):
 *   - `Excluida id=<id> clase=<A|C1|X…> motivo=<texto>` por cada fila excluida.
 *   - `Conteo global de marca 1: <n>` al final de `--revertir-todas` y de `--verificar`.
 *   - `Interruptores prendidos: <n>` en `--verificar`.
 *
 * @group vinculacion-iva-copia
 */
class Reversion_e_interruptor_Test extends VinculacionDeInventarioTestCase
{
    /**
     * Columnas de precio que la reversión no puede mover.
     *
     * @var array
     */
    const PRECIO = ['final_price', 'costo_real', 'previus_final_price', 'final_price_updated_at'];

    /**
     * Apaga todos los interruptores de la base (precondición de `--revertir-todas`).
     *
     * @return void
     */
    private function apagar_todos()
    {
        DB::table('inventory_linkages')->update(['copia_en_costo_neto' => 0]);
    }

    /**
     * CA13 — activar y desactivar escriben sólo `inventory_linkages.copia_en_costo_neto`; apagado,
     * el alta nueva vuelve a CA5a y las copias con marca 1 siguen recalculando en neto.
     *
     * @test
     */
    public function ca13_el_interruptor_escribe_una_sola_columna_y_las_copias_con_marca_siguen_en_neto()
    {
        $this->escenario([], false);
        $fuente = $this->crear_fuente();

        $vinculacion_antes = (array) DB::table('inventory_linkages')->where('id', $this->vinculacion->id)->first();

        list($codigo, $salida) = $this->comando(['--activar-vinculacion' => $this->vinculacion->id]);
        $this->assertEquals(0, $codigo, $salida);

        $activada = (array) DB::table('inventory_linkages')->where('id', $this->vinculacion->id)->first();
        $this->assertEquals(1, (int) $activada['copia_en_costo_neto']);
        unset($activada['copia_en_costo_neto'], $vinculacion_antes['copia_en_costo_neto']);
        $this->assertEquals($vinculacion_antes, $activada, 'sólo cambia el interruptor');

        $copia = $this->crear_copia($fuente);
        $this->assertEquals(1, $this->marca_de($copia->id));
        $filas_antes = DB::table('articles')->count();

        list($codigo, $salida) = $this->comando(['--desactivar-vinculacion' => $this->vinculacion->id]);
        $this->assertEquals(0, $codigo, $salida);
        $this->assertEquals(0, (int) DB::table('inventory_linkages')->where('id', $this->vinculacion->id)->value('copia_en_costo_neto'));
        $this->assertEquals($filas_antes, DB::table('articles')->count(), 'no escribe artículos');

        // El alta nueva vuelve a CA5a.
        $otra = $this->crear_fuente(1000.00);
        $objeto = $this->helper()->createClientArticle($otra);
        $this->assertEqualsWithDelta(1000.0, (float) $objeto->cost, 0.0000005);
        $this->assertEquals(0, $this->marca_de($objeto->id));

        // Las copias con marca 1 siguen recalculando en neto (CA3 y CA4 pasan igual).
        $fuente = $this->mover_fuente($fuente, 6050.00);
        $this->assertTrue($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, $fuente, Article::find($copia->id)));
        $this->assertEqualsWithDelta(5000.0, $this->valores($copia->id)['cost'], 0.0000005);
        $this->assertEquals(1, $this->marca_de($copia->id));
    }

    /**
     * Fixture de CA14: [c1 convertida por E1, c2 nacida con marca 1, c3 convertida y recalculada con la
     * fuente en F', c4 desincronizada, c5 con marca 0] y la foto de c1 antes de E1.
     *
     * @return array
     */
    private function fixture_ca14()
    {
        $this->escenario(['listas_de_precio' => 1]);

        $f1 = $this->crear_fuente();
        $c1 = $this->copia_expuesta($f1);
        $this->prender_salidas($c1);
        $fila_c1 = (array) DB::table('articles')->where('id', $c1->id)->first();
        list($codigo, $salida) = $this->convertir([$c1->id]);
        $this->assertEquals(0, $codigo, $salida);

        $c2 = $this->crear_copia($this->crear_fuente());

        $f3 = $this->crear_fuente();
        $c3 = $this->crear_copia($f3);
        $this->helper()->checkArticle($this->mover_fuente($f3, 6050.00));

        $f4 = $this->crear_fuente();
        $c4 = $this->crear_copia($f4);
        $this->mover_fuente($f4, 6050.00);

        $c5 = $this->copia_expuesta($this->crear_fuente());

        return compact('f1', 'c1', 'c2', 'f3', 'c3', 'f4', 'c4', 'c5', 'fila_c1');
    }

    /**
     * CA14a — con CUALQUIER interruptor prendido se niega, lista las vinculaciones prendidas y no
     * escribe; con `--cliente` y `--aplicar` juntos, también.
     *
     * @test
     */
    public function ca14a_se_niega_con_un_interruptor_prendido_y_con_cliente_mas_aplicar()
    {
        $x = $this->fixture_ca14();
        $antes = $this->foto_efectos([$x['c1']->id, $x['c2']->id]);

        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true]);
        $this->assertNotEquals(0, $codigo);
        $this->assertStringContainsString((string) $this->vinculacion->id, $salida, 'lista la vinculación prendida');
        $this->assertEquals($antes, $this->foto_efectos([$x['c1']->id, $x['c2']->id]));

        $this->apagar_todos();
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true, '--cliente' => $this->galvan->id]);
        $this->assertNotEquals(0, $codigo, 'cliente + aplicar se niega');
        $this->assertEquals($antes, $this->foto_efectos([$x['c1']->id, $x['c2']->id]));
    }

    /**
     * CA14b a g — simulación sin efectos, reversión con `--aplicar`, el cron no la reescribe, el
     * `checkArticle` la lleva a 6426,07 (no protege), la suma de precios no cambia y la excluida
     * deja conteo global 1 con código 1.
     *
     * @test
     */
    public function ca14_la_reversion_vuelve_al_bruto_sin_mover_el_precio()
    {
        $x = $this->fixture_ca14();
        $this->apagar_todos();
        $ids = [$x['c1']->id, $x['c2']->id, $x['c3']->id, $x['c4']->id, $x['c5']->id];

        // (b) simulación.
        $foto = $this->foto_efectos($ids);
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true]);
        $this->assertEquals($foto, $this->foto_efectos($ids), 'la simulación no escribe');

        $suma_antes = (float) DB::table('articles')->whereIn('id', $ids)->sum('final_price');
        $precios_antes = [];
        foreach ($ids as $id) {
            $precios_antes[$id] = (array) DB::table('articles')->where('id', $id)->first();
        }
        $efectos_antes = $this->foto_efectos();

        // (c) con --aplicar.
        list($codigo, $salida) = $this->comando(['--revertir-todas' => true, '--aplicar' => true]);

        foreach ([$x['c1'], $x['c2'], $x['c3']] as $c) {
            $fila = (array) DB::table('articles')->where('id', $c->id)->first();
            $fuente_final = (float) DB::table('articles')->where('id', $c->provider_article_id)->value('final_price');
            $this->assertEqualsWithDelta($fuente_final, (float) $fila['cost'], 0.0000005, 'cost = fuente.final_price (id ' . $c->id . ')');
            $this->assertEquals(0, (int) $fila['costo_neto_de_vinculacion'], 'marca 0 (id ' . $c->id . ')');
            foreach (self::PRECIO as $columna) {
                $this->assertEquals($precios_antes[$c->id][$columna], $fila[$columna], $columna . ' (id ' . $c->id . ')');
            }
        }

        // La convertida por E1 queda igual a su fila previa a E1 (todas las columnas de precio).
        $fila_c1 = (array) DB::table('articles')->where('id', $x['c1']->id)->first();
        foreach (['cost', 'costo_real', 'final_price', 'previus_final_price', 'final_price_updated_at'] as $columna) {
            $this->assertEquals($x['fila_c1'][$columna], $fila_c1[$columna], 'c1 vuelve a su fila previa a E1: ' . $columna);
        }

        // La desincronizada queda excluida sin escribir; la de marca 0 no se toca.
        $this->assertEquals($precios_antes[$x['c4']->id], (array) DB::table('articles')->where('id', $x['c4']->id)->first());
        $this->assertEquals($precios_antes[$x['c5']->id], (array) DB::table('articles')->where('id', $x['c5']->id)->first());
        $this->assertStringContainsString('Excluida id=' . $x['c4']->id . ' clase=A motivo=desincronizada', $salida);

        $efectos_despues = $this->foto_efectos();
        $this->assertEquals($efectos_antes, $efectos_despues, 'foto_efectos igual');

        // (g) conteo global 1 y código 1.
        $this->assertStringContainsString('Conteo global de marca 1: 1', $salida);
        $this->assertEquals(1, $codigo);

        // (f) la suma de final_price es igual.
        $this->assertEqualsWithDelta($suma_antes, (float) DB::table('articles')->whereIn('id', $ids)->sum('final_price'), 0.0001);

        // (d) el cron no la reescribe.
        $this->assertFalse($this->helper()->sync_client_article_price_and_bar_code($this->vinculacion, Article::find($x['f1']->id), Article::find($x['c1']->id)));
        $this->assertEqualsWithDelta((float) $precios_antes[$x['c1']->id]['final_price'], $this->valores($x['c1']->id)['final'], 0.0001);

        // (e) no la protege: un checkArticle la lleva a 6426,07, como hoy a las 596.
        $this->helper()->checkArticle(Article::find($x['f1']->id));
        $this->assertEqualsWithDelta(self::FINAL_INFLADO, $this->valores($x['c1']->id)['final'], 0.0001);
    }
}
