<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\combo\ComboStockHelper;
use App\Models\Combo;

/**
 * El stock del combo, siempre calculado (misión combos-calculados, 30/9/2026).
 *
 * El ejemplo de Lucas: A x2 con stock 2, B x3 con stock 3, C x4 con stock 4 -> se puede armar 1
 * combo, y el que limita manda. Cada borde de la regla tiene su test: NULL no limita, borrado es 0,
 * negativo es 0, y si ningún componente lleva stock el combo es `null` ("sin control").
 *
 * Dos capas: `ComboStockHelper::calcular()` suelto (función pura, con casos armados a mano) y el
 * accessor `stock_disponible` del modelo con artículos reales, incluido lo que sale por el endpoint
 * (el SPA lo lee de ahí).
 *
 * 🔴 EL STOCK NO SE GUARDA EN NINGUNA COLUMNA: hay un test que cambia `articles.stock` con una
 * consulta directa (la escritura cruda que ningún gancho ve) y comprueba que el combo lo refleja
 * en la lectura siguiente. Si alguien lo "cachea", ese test se pone rojo.
 *
 * @group combos-calculados
 */
class Stock_del_combo_Test extends ComboCalculadoTestCase
{
    /**
     * Arma un combo con tres componentes A x2, B x3, C x4 con los stocks pedidos.
     *
     * @param  mixed  $stock_a
     * @param  mixed  $stock_b
     * @param  mixed  $stock_c
     * @return \App\Models\Combo
     */
    protected function combo_abc($stock_a, $stock_b, $stock_c)
    {
        $a = $this->nuevo_articulo(['stock' => $stock_a]);
        $b = $this->nuevo_articulo(['stock' => $stock_b]);
        $c = $this->nuevo_articulo(['stock' => $stock_c]);

        return $this->combo([[$a, 2], [$b, 3], [$c, 4]]);
    }

    /**
     * @param  \App\Models\Combo  $combo
     * @return int|null
     */
    protected function stock_del($combo)
    {
        return Combo::find($combo->id)->stock_disponible;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  La función pura
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_ejemplo_de_lucas_da_un_combo()
    {
        $this->assertSame(1, ComboStockHelper::calcular([
            ['stock' => 2, 'amount' => 2],
            ['stock' => 3, 'amount' => 3],
            ['stock' => 4, 'amount' => 4],
        ]));
    }

    /**
     * El que limita manda: con C en 3, C x4 no alcanza para ninguno.
     *
     * @test
     */
    public function el_componente_limitante_manda()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            ['stock' => 2, 'amount' => 2],
            ['stock' => 3, 'amount' => 3],
            ['stock' => 3, 'amount' => 4],
        ]));

        // Y con stocks altos, el limitante es el más justo: floor(10/2)=5, floor(30/3)=10, floor(9/4)=2.
        $this->assertSame(2, ComboStockHelper::calcular([
            ['stock' => 10, 'amount' => 2],
            ['stock' => 30, 'amount' => 3],
            ['stock' => 9, 'amount' => 4],
        ]));
    }

    /**
     * Se redondea hacia abajo, también con stock decimal (artículos por peso).
     *
     * @test
     */
    public function el_stock_se_redondea_hacia_abajo()
    {
        $this->assertSame(3, ComboStockHelper::calcular([['stock' => 7, 'amount' => 2]]));
        $this->assertSame(3, ComboStockHelper::calcular([['stock' => '7.9', 'amount' => 2]]));
        $this->assertSame(0, ComboStockHelper::calcular([['stock' => 0.5, 'amount' => 1]]));
    }

    /**
     * Un componente con stock NULL no lleva control: NO limita.
     *
     * @test
     */
    public function un_componente_sin_control_de_stock_no_limita()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            ['stock' => null, 'amount' => 1],
            ['stock' => 5, 'amount' => 2],
        ]));
    }

    /**
     * Si NINGÚN componente lleva stock, el combo es null (sin control, hay siempre): no 0.
     *
     * @test
     */
    public function si_ningun_componente_lleva_stock_el_combo_no_tiene_control()
    {
        $this->assertNull(ComboStockHelper::calcular([
            ['stock' => null, 'amount' => 1],
            ['stock' => null, 'amount' => 3],
        ]));

        $this->assertNull(ComboStockHelper::calcular([]), 'un combo sin componentes tampoco tiene control');
    }

    /**
     * Un componente borrado: el combo no se puede armar (0), aunque los demás tengan stock de
     * sobra y aunque el borrado conserve un stock viejo (o NULL).
     *
     * @test
     */
    public function un_componente_borrado_deja_el_combo_en_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            ['stock' => 100, 'amount' => 1],
            ['stock' => 100, 'amount' => 1, 'borrado' => true],
        ]));

        $this->assertSame(0, ComboStockHelper::calcular([
            ['stock' => null, 'amount' => 1, 'borrado' => true],
        ]), 'un borrado sin stock (NULL) también corta: no cae en "no limita"');
    }

    /**
     * Stock negativo (se vendió sin stock): cuenta como 0, no resta y no da un combo negativo.
     *
     * @test
     */
    public function el_stock_negativo_cuenta_como_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            ['stock' => -3, 'amount' => 1],
            ['stock' => 10, 'amount' => 1],
        ]));
    }

    /**
     * Una cantidad en 0 o no numérica (combo mal cargado) no divide por cero ni limita.
     *
     * @test
     */
    public function una_cantidad_invalida_no_limita_ni_divide_por_cero()
    {
        $this->assertSame(4, ComboStockHelper::calcular([
            ['stock' => 8, 'amount' => 2],
            ['stock' => 1, 'amount' => 0],
            ['stock' => 1, 'amount' => null],
        ]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Con artículos reales
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function el_combo_del_ejemplo_de_lucas_con_articulos_reales()
    {
        $this->assertSame(1, $this->stock_del($this->combo_abc(2, 3, 4)));
        $this->assertSame(0, $this->stock_del($this->combo_abc(2, 3, 3)));
        $this->assertSame(2, $this->stock_del($this->combo_abc(4, 6, 8)));
    }

    /**
     * @test
     */
    public function un_articulo_sin_stock_no_limita_al_combo_real()
    {
        $combo = $this->combo_abc(null, 6, 8);

        $this->assertSame(2, $this->stock_del($combo), 'A no lleva stock; B da 2 y C da 2');
    }

    /**
     * @test
     */
    public function si_todos_los_articulos_reales_son_sin_stock_el_combo_es_null()
    {
        $this->assertNull($this->stock_del($this->combo_abc(null, null, null)));
    }

    /**
     * @test
     */
    public function un_articulo_borrado_deja_el_combo_real_en_cero()
    {
        $a = $this->nuevo_articulo(['stock' => 100]);
        $b = $this->nuevo_articulo(['stock' => 100]);

        $combo = $this->combo([[$a, 1], [$b, 1]]);

        $this->assertSame(100, $this->stock_del($combo));

        $b->delete();

        $this->assertSame(0, $this->stock_del($combo), 'el borrado sigue siendo un componente del combo, y no se puede armar');
    }

    /**
     * 🔴 EL STOCK SE CALCULA AL LEER. Una escritura cruda del stock del artículo (que ningún gancho
     * ve) se refleja en el combo en la lectura siguiente: no hay columna que quedarse vieja.
     *
     * @test
     */
    public function el_stock_del_combo_no_queda_viejo_ante_una_escritura_cruda()
    {
        $a = $this->nuevo_articulo(['stock' => 10]);

        $combo = $this->combo([[$a, 2]]);

        $this->assertSame(5, $this->stock_del($combo));

        $this->escribir_crudo($a, ['stock' => 3]);

        $this->assertSame(1, $this->stock_del($combo));

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('combos', 'stock'),
            'el stock del combo no se persiste: no puede haber una columna stock en combos'
        );
    }

    /**
     * El endpoint que lee el SPA (`GET api/combo/{id}` y el listado) trae `stock_disponible`.
     *
     * @test
     */
    public function el_stock_disponible_viaja_en_el_show_y_en_el_listado()
    {
        $combo = $this->combo_abc(2, 3, 4);

        $respuesta = $this->get('api/combo/' . $combo->id);
        $respuesta->assertStatus(200);

        $this->assertSame(1, $respuesta->json('model.stock_disponible'));

        $listado = $this->get('api/combo')->json('models');

        $encontrado = null;

        foreach ($listado as $modelo) {
            if ($modelo['id'] === $combo->id) {
                $encontrado = $modelo;
            }
        }

        $this->assertNotNull($encontrado, 'el combo tiene que estar en el listado');
        $this->assertSame(1, $encontrado['stock_disponible']);
    }

    /**
     * Un combo sin control de stock viaja con `stock_disponible: null` (no 0, no ausente).
     *
     * @test
     */
    public function un_combo_sin_control_viaja_con_stock_disponible_null()
    {
        $combo = $this->combo_abc(null, null, null);

        $json = $this->get('api/combo/' . $combo->id)->json('model');

        $this->assertArrayHasKey('stock_disponible', $json);
        $this->assertNull($json['stock_disponible']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  El mismo artículo en más de un renglón (F2)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * 🔴 El mismo artículo repetido en dos renglones se agrupa ANTES de dividir: A con stock 2 y dos
     * renglones de cantidad 1 lleva 2 unidades por combo, o sea 1 combo. Dividir cada renglón por
     * separado daba 2 y vendía de más.
     *
     * @test
     */
    public function el_mismo_articulo_en_dos_renglones_suma_sus_cantidades_antes_de_dividir()
    {
        $this->assertSame(1, ComboStockHelper::calcular([
            ['article_id' => 7, 'stock' => 2, 'amount' => 1],
            ['article_id' => 7, 'stock' => 2, 'amount' => 1],
        ]));

        // Y mezclado con otros artículos: A repetido (1 + 2 = 3 por combo, stock 7 -> 2) y B (stock 9 x 1 -> 9).
        $this->assertSame(2, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 7, 'amount' => 1],
            ['article_id' => 2, 'stock' => 9, 'amount' => 1],
            ['article_id' => 1, 'stock' => 7, 'amount' => 2],
        ]));
    }

    /**
     * Repetido más un componente sin control de stock: el sin control no limita ni "rescata" al
     * repetido.
     *
     * @test
     */
    public function un_articulo_repetido_con_otro_sin_control_de_stock_sigue_limitando()
    {
        $this->assertSame(1, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 2, 'amount' => 1],
            ['article_id' => 9, 'stock' => null, 'amount' => 1],
            ['article_id' => 1, 'stock' => 2, 'amount' => 1],
        ]));

        // Un renglón repetido con cantidad inválida no suma ni divide por cero.
        $this->assertSame(2, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 2, 'amount' => 1],
            ['article_id' => 1, 'stock' => 2, 'amount' => 0],
        ]));
    }

    /**
     * Un renglón repetido que está BORRADO deja el combo en 0 aunque el otro renglón tenga stock.
     *
     * @test
     */
    public function un_renglon_borrado_entre_repetidos_deja_el_combo_en_cero()
    {
        $this->assertSame(0, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 50, 'amount' => 1],
            ['article_id' => 1, 'stock' => 50, 'amount' => 1, 'borrado' => true],
        ]));
    }

    /**
     * Los dos casos literales de Lucas, con `article_id` como los arma `calcular_de_articulos()`:
     * A, B, C de amount 1 con stocks 2/3/4 -> 2; y con amounts 2/3/4 y stocks 2/3/4 -> 1.
     *
     * @test
     */
    public function los_casos_literales_de_lucas_dan_dos_y_uno()
    {
        $this->assertSame(2, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 2, 'amount' => 1],
            ['article_id' => 2, 'stock' => 3, 'amount' => 1],
            ['article_id' => 3, 'stock' => 4, 'amount' => 1],
        ]));

        $this->assertSame(1, ComboStockHelper::calcular([
            ['article_id' => 1, 'stock' => 2, 'amount' => 2],
            ['article_id' => 2, 'stock' => 3, 'amount' => 3],
            ['article_id' => 3, 'stock' => 4, 'amount' => 4],
        ]));
    }

    /**
     * Con artículos reales y el accessor del modelo: el mismo artículo cargado dos veces en el
     * combo (el ABM lo permite) da el stock agrupado.
     *
     * @test
     */
    public function un_articulo_cargado_dos_veces_en_el_combo_real_da_el_stock_agrupado()
    {
        $a = $this->nuevo_articulo(['stock' => 2]);

        $combo = $this->combo([[$a, 1], [$a, 1]]);

        $this->assertSame(1, $this->stock_del($combo));

        // El mismo combo con un solo renglón de cantidad 2 da lo mismo: son la misma receta.
        $this->assertSame(1, $this->stock_del($this->combo([[$a, 2]])));
    }
}
