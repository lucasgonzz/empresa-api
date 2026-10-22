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

    protected $excel_path;
    protected $preservar_filas_vacias;
    protected $lector_formulas;

    /**
     * @param \OpenSpout\Reader\XLSX\Reader $reader  Reader abierto, del que este objeto se hace cargo
     * @param \OpenSpout\Reader\XLSX\Sheet  $sheet   Hoja ya posicionada
     */
    public function __construct($reader, $sheet, $excel_path = null, $preservar_filas_vacias = true)
    {
        $this->reader = $reader;
        $this->sheet  = $sheet;
        $this->excel_path = $excel_path;
        $this->preservar_filas_vacias = (bool) $preservar_filas_vacias;
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
     * @return \Generator
     */
    public function filas()
    {
        if (!OpenSpoutCompatible::es_version_cuatro()) {
            foreach ($this->sheet->getRowIterator() as $numero => $fila) {
                yield $numero => $fila;
            }
            return;
        }

        $formulas = null;
        $base_fecha = '1899-12-30';
        if (OpenSpoutCompatible::es_version_cuatro() && $this->excel_path !== null) {
            $base_fecha = ExcelSheetInspector::base_de_fechas($this->excel_path);
            $formulas = ExcelSheetInspector::formulas_por_fila($this->excel_path, $this->sheet->getIndex(), function ($lector) {
                $this->lector_formulas = $lector;
            });
            $formulas->rewind();
        }

        $leidas = 0;
        foreach ($this->sheet->getRowIterator() as $numero => $fila) {
            $reales = [];
            if ($formulas !== null) {
                while ($formulas->valid() && $formulas->key() < $numero) {
                    $formulas->next();
                }
                if ($formulas->valid() && $formulas->key() === $numero) {
                    $reales = $formulas->current();
                }
            }

            $fila = OpenSpoutCompatible::normalizar_fila($fila, $reales, $base_fecha);
            if (!$this->preservar_filas_vacias) {
                $vacia = true;
                foreach ($fila->getCells() as $celda) {
                    if (!OpenSpoutCompatible::celda_vacia($celda)) {
                        $vacia = false;
                        break;
                    }
                }
                if ($vacia) {
                    continue;
                }
            }

            $leidas++;
            yield ($this->preservar_filas_vacias ? $numero : $leidas) => $fila;
        }
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
     * Después de un break también cierra el XML de la hoja elegida y el auxiliar de
     * fórmulas. OpenSpout 3 lo hace en Reader::close(); para 4 el adaptador lo cierra
     * explícitamente porque esa versión sólo lo libera al llegar al final del iterador.
     *
     * @return void
     */
    public function cerrar()
    {
        if ($this->cerrada) {
            return;
        }

        $this->cerrada = true;

        try {
            OpenSpoutCompatible::cerrar_hoja($this->sheet);
        } finally {
            try {
                $this->reader->close();
            } finally {
                if ($this->lector_formulas !== null) {
                    $this->lector_formulas->close();
                    $this->lector_formulas = null;
                }
            }
        }
    }
}
