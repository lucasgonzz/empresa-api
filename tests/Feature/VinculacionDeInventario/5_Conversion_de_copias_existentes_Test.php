<?php

namespace Tests\Feature\VinculacionDeInventario;

use App\Http\Controllers\Helpers\import\article\motor\PreciosEnLote;
use App\Models\Article;
use Illuminate\Support\Facades\DB;

/**
 * CA9 (E1 sin mover precios y sin efectos) y la parte de E1 de CA5b del PLAN D2-M R3.1.
 *
 * La fixture de CA9 tiene las salidas prendidas: la copia con `tiendanube_product_id` y
 * `disponible_tienda_nube`, USA_TIENDA_NUBE en true, ML habilitado y con publicación, y Galván con la
 * lista «Precio final». Un E1 que escribiera por `setFinalPrice` dejaría pivotes, colas y
 * `price_changes`: el test lo detecta con `foto_efectos()`.
 *
 * @group vinculacion-iva-copia
 */
class Conversion_de_copias_existentes_Test extends VinculacionDeInventarioTestCase
{
    /**
     * Arma la fixture de CA9 con una copia expuesta.
     *
     * @return \App\Models\Article La copia expuesta con las salidas prendidas.
     */
    private function fixture_con_salidas()
    {
        $this->escenario(['listas_de_precio' => 1]);
        $fuente = $this->crear_fuente();
        $copia = $this->copia_expuesta($fuente);
        $this->prender_salidas($copia);

        return Article::find($copia->id);
    }

    /**
     * @test
     */
    public function ca9a_en_simulacion_no_cambia_nada_ni_escribe_el_tsv()
    {
        $copia = $this->fixture_con_salidas();
        $antes = $this->foto_efectos([$copia->id]);

        $carpeta = $this->carpeta_de_salida();
        list($codigo, $salida) = $this->comando([
            '--cliente' => $this->galvan->id,
            '--ids'     => $this->ids_en_archivo([$copia->id]),
            '--salida'  => $carpeta,
        ]);

        $this->assertEquals(0, $codigo, $salida);
        $this->assertEquals($antes, $this->foto_efectos([$copia->id]), 'foto_efectos y la fila quedan idénticas');
        $this->assertCount(0, glob($carpeta . '/*'), 'no se escribe el TSV en simulación');
    }

    /**
     * @test
     */
    public function ca9b_con_aplicar_la_copia_queda_en_neto_y_el_resto_de_la_fila_igual()
    {
        $copia = $this->fixture_con_salidas();
        $antes = $this->foto_efectos([$copia->id]);
        $fila_antes = $antes['articulo_' . $copia->id];

        list($codigo, $salida) = $this->convertir([$copia->id]);
        $this->assertEquals(0, $codigo, $salida);

        $despues = $this->foto_efectos([$copia->id]);
        $fila_despues = $despues['articulo_' . $copia->id];

        $this->assertEqualsWithDelta(self::NETO, (float) $fila_despues['cost'], 0.0000005);
        $this->assertEquals(1, (int) $fila_despues['costo_neto_de_vinculacion']);

        foreach (['final_price', 'costo_real', 'previus_final_price', 'final_price_updated_at', 'updated_at'] as $columna) {
            $this->assertEquals($fila_antes[$columna], $fila_despues[$columna], $columna . ' no cambia');
        }

        unset($despues['articulo_' . $copia->id], $antes['articulo_' . $copia->id]);
        $this->assertEquals($antes, $despues, '0 pivotes, 0 sync_to_*, 0 price_changes y 0 jobs');
    }

    /**
     * CA9c y CA9d — la réplica de 161802 (cost 1532,95; final 1686,25; 21 %) queda excluida porque el
     * precio cambiaría (1686,24), sin efectos; y la suma de final_price es igual antes y después.
     *
     * @test
     */
    public function ca9c_la_replica_de_161802_queda_excluida_y_la_suma_de_precios_no_cambia()
    {
        $this->escenario(['listas_de_precio' => 1]);
        $normal = $this->copia_expuesta($this->crear_fuente());
        $fuente_161802 = $this->crear_fuente(1532.95);
        $replica = $this->crear_copia($fuente_161802);
        $replica = $this->fijar($replica, [
            'aplicar_iva' => 1, 'cost' => 1532.95, 'costo_real' => 1532.95, 'final_price' => 1686.25,
            'costo_neto_de_vinculacion' => 0,
        ]);
        $this->prender_salidas($normal);

        $suma_antes = (float) DB::table('articles')->whereIn('id', [$normal->id, $replica->id])->sum('final_price');
        $antes = $this->foto_efectos([$replica->id]);

        list($codigo, $salida) = $this->convertir([$normal->id, $replica->id]);

        $this->assertStringContainsString('el precio cambiaría (1686,24)', $salida);
        $this->assertEquals(0, $this->marca_de($replica->id), '161802 sin convertir');
        $this->assertEquals(1, $this->marca_de($normal->id), 'la otra sí');
        $this->assertEquals($antes['articulo_' . $replica->id], $this->foto_efectos([$replica->id])['articulo_' . $replica->id], 'fila de 161802 intacta');
        $this->assertEquals(0, DB::table('price_changes')->where('article_id', $replica->id)->count(), 'sin price_changes aunque la simulación pasó por PriceChangeController');
        $this->assertEqualsWithDelta($suma_antes, (float) DB::table('articles')->whereIn('id', [$normal->id, $replica->id])->sum('final_price'), 0.0001);
    }

    /**
     * CA9e — sin el interruptor prendido, E1 se niega y no escribe.
     *
     * @test
     */
    public function ca9e_sin_el_interruptor_prendido_e1_se_niega()
    {
        $this->escenario([], false);
        $copia = $this->copia_expuesta($this->crear_fuente());

        list($codigo, $salida) = $this->convertir([$copia->id]);

        $this->assertNotEquals(0, $codigo, $salida);
        $this->assertEquals(0, $this->marca_de($copia->id));
        $this->assertEqualsWithDelta(4828.0, $this->valores($copia->id)['cost'], 0.0000005);
    }

    /**
     * CA9f — con PreciosEnLote activo, aborta.
     *
     * @test
     */
    public function ca9f_con_precios_en_lote_activo_aborta()
    {
        $this->escenario();
        $copia = $this->copia_expuesta($this->crear_fuente());

        PreciosEnLote::activar();

        try {
            list($codigo, $salida) = $this->convertir([$copia->id]);
        } finally {
            PreciosEnLote::descartar();
        }

        $this->assertNotEquals(0, $codigo, $salida);
        $this->assertEquals(0, $this->marca_de($copia->id));
    }

    /**
     * CA5b, parte E1 — la copia inflada (los 208: cost 4828, costo_real 5841,88, final 6426,07, marca 0)
     * queda excluida por "inflada" y no se toca.
     *
     * @test
     */
    public function ca5b_e1_excluye_la_copia_inflada()
    {
        $this->escenario();
        $copia = $this->crear_copia($this->crear_fuente());
        $copia = $this->fijar($copia, [
            'aplicar_iva' => 1, 'cost' => 4828, 'costo_real' => 5841.88, 'final_price' => self::FINAL_INFLADO,
            'costo_neto_de_vinculacion' => 0,
        ]);
        $fila = (array) DB::table('articles')->where('id', $copia->id)->first();

        list($codigo, $salida) = $this->convertir([$copia->id]);

        $this->assertStringContainsString('inflada', $salida);
        $this->assertEquals($fila, (array) DB::table('articles')->where('id', $copia->id)->first());
    }
}
