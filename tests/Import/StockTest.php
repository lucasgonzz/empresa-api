<?php

namespace Tests\Import;

use App\Models\Article;
use App\Models\StockMovement;

/**
 * Stock.
 *
 * La sutileza de ProcessRow::obtener_stock(): para un artículo YA EXISTENTE lo que
 * se guarda es el DELTA (excel - stock_actual), y para uno nuevo el valor ABSOLUTO.
 * Por eso no alcanza con mirar articles.stock: hay que mirar también que el
 * movimiento de stock generado sea el correcto, y que cuando no hay cambio NO se
 * genere movimiento.
 *
 * Archivo 04_stock.xlsx, importado SIN proveedor seleccionado:
 *   F2 PC-100     A1 stock 10 -> 25   (delta +15)
 *   F3 PC-200     A2 stock 20 -> 5    (delta -15)
 *   F4 PC-700     A7 stock 70 -> 70   (sin cambio, sin movimiento)
 *   F5 PC-STK-NEW nuevo, stock "1.500" como texto -> 1500
 *   F6 PC-800     A8 costo 850, columna stock vacía -> stock intacto (80)
 *   F7 PC-1200    A12 no matchea (provider_id null en base) -> crea duplicado
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe, argumentos
 * nombrados, union types, promoción de constructor, readonly, enum ni #[...].
 */
class StockTest extends ImportTestCase
{
    const ARCHIVO = '04_stock.xlsx';

    /**
     * @return void
     */
    public function test_stock_que_sube_y_que_baja()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertDecimal(25, $this->recargar('A1')->stock, 'A1 sube de 10 a 25');
        $this->assertDecimal(5,  $this->recargar('A2')->stock, 'A2 baja de 20 a 5');
    }

    /**
     * @return void
     */
    public function test_stock_sin_cambio_no_genera_movimiento()
    {
        /* Se mide el delta y no el total, por si el alta del artículo dejó algún
           movimiento propio: lo que importa es que la importación no agregue ninguno. */
        $antes = StockMovement::where('article_id', $this->seed['A7']->id)->count();

        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $this->assertDecimal(70, $this->recargar('A7')->stock, 'A7 queda igual');

        $this->assertSame(
            $antes,
            StockMovement::where('article_id', $this->seed['A7']->id)->count(),
            'Un stock que no cambia no puede generar movimiento'
        );
    }

    /**
     * @return void
     */
    public function test_columna_de_stock_vacia_no_pisa_el_stock()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $a8 = $this->recargar('A8');

        $this->assertDecimal(80,  $a8->stock, 'Sin valor en la columna, el stock no se toca');
        $this->assertDecimal(850, $a8->cost,  'Pero el costo sí se actualiza');
    }

    /**
     * Un stock escrito como "1.500" en el Excel es mil quinientos, no uno con cinco.
     *
     * @return void
     */
    public function test_stock_con_separador_de_miles()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $nuevo = Article::where('user_id', $this->tenant->id)
                        ->where('provider_code', 'PC-STK-NEW')
                        ->first();

        $this->assertNotNull($nuevo);
        $this->assertDecimal(1500, $nuevo->stock);
    }

    /**
     * El movimiento registrado tiene que ser el delta, no el valor absoluto del Excel.
     *
     * @return void
     */
    public function test_el_movimiento_registra_el_delta()
    {
        $this->importar(self::ARCHIVO, ['provider_id' => null]);

        $a1 = $this->recargar('A1');

        $movimiento = StockMovement::where('article_id', $a1->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($movimiento, 'Tiene que haber movimiento para A1');
        $this->assertDecimal(15, $movimiento->amount, 'El movimiento es +15, no 25');
    }
}
