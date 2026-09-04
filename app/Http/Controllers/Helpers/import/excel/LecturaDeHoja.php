<?php

namespace App\Http\Controllers\Helpers\import\excel;

/**
 * Handle de una hoja ya abierta y posicionada, que devuelve
 * ExcelWorkbookReader::abrir().
 *
 * Existe para que el llamador nunca vea el $reader ni el getSheetIterator(): ese
 * `foreach ($reader->getSheetIterator() as $sheet) { ... break; }` repetido en doce
 * lugares es justamente el defecto que se vino a matar (siempre la primera hoja, sin
 * que nadie lo eligiera). Aca adentro el iterador de hojas ya se consumio hasta el
 * indice pedido y afuera solo queda filas().
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos
 * nombrados, union types, promocion de constructor, readonly, enum ni #[...].
 */
class LecturaDeHoja
{
    /** @var \OpenSpout\Reader\XLSX\Reader */
    protected $reader;

    /** @var \OpenSpout\Reader\XLSX\Sheet */
    protected $sheet;

    /** @var bool */
    protected $cerrada = false;

    /**
     * @param \OpenSpout\Reader\XLSX\Reader $reader  Reader abierto, del que este objeto se hace cargo
     * @param \OpenSpout\Reader\XLSX\Sheet  $sheet   Hoja ya posicionada
     */
    public function __construct($reader, $sheet)
    {
        $this->reader = $reader;
        $this->sheet  = $sheet;
    }

    /**
     * @return \OpenSpout\Reader\XLSX\Sheet
     */
    public function sheet()
    {
        return $this->sheet;
    }

    /**
     * Iterador de filas de la hoja elegida.
     *
     * @return \OpenSpout\Reader\XLSX\RowIterator
     */
    public function filas()
    {
        return $this->sheet->getRowIterator();
    }

    /**
     * @return string
     */
    public function nombre()
    {
        return $this->sheet->getName();
    }

    /**
     * Cierra el libro. Idempotente: llamarlo dos veces no rompe.
     *
     * Tambien es seguro despues de un `break` temprano del loop de filas: OpenSpout
     * Reader::close() termina en SheetIterator::end(), que cierra los row iterators de
     * TODAS las hojas, no solo la que se estaba leyendo (verificado en vendor/).
     *
     * @return void
     */
    public function cerrar()
    {
        if ($this->cerrada) {
            return;
        }

        $this->cerrada = true;

        $this->reader->close();
    }
}
