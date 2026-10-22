<?php

namespace App\Http\Controllers\Helpers\import\excel;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;

/**
 * Mantiene el contrato del importador entre OpenSpout 3 (PHP 7.4) y 4 (VPS).
 * La detección depende de la API instalada, nunca de la versión de PHP.
 */
class OpenSpoutCompatible
{
    public static function es_version_cuatro()
    {
        return method_exists(Cell::class, 'fromValue');
    }

    public static function createXLSXReader($preservar_filas_vacias = true)
    {
        if (!method_exists(Cell::class, 'fromValue')) {
            $reader = \OpenSpout\Reader\Common\Creator\ReaderEntityFactory::createXLSXReader();
            $reader->setShouldPreserveEmptyRows((bool) $preservar_filas_vacias);

            return $reader;
        }

        $opciones = new \OpenSpout\Reader\XLSX\Options();
        $opciones->SHOULD_PRESERVE_EMPTY_ROWS = (bool) $preservar_filas_vacias;

        return new \OpenSpout\Reader\XLSX\Reader($opciones);
    }

    public static function createCSVWriter()
    {
        if (!method_exists(Cell::class, 'fromValue')) {
            return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createCSVWriter();
        }

        return new \OpenSpout\Writer\CSV\Writer();
    }

    public static function createXLSXWriter()
    {
        if (!method_exists(Cell::class, 'fromValue')) {
            return \OpenSpout\Writer\Common\Creator\WriterEntityFactory::createXLSXWriter();
        }

        return new \OpenSpout\Writer\XLSX\Writer();
    }

    public static function createCell($valor, $estilo = null)
    {
        if (method_exists(Cell::class, 'fromValue')) {
            return Cell::fromValue($valor, $estilo);
        }

        return new Cell($valor, $estilo);
    }

    /** El sidecar contiene valores, no fórmulas para ejecutar al volver a escribir. */
    public static function celda_literal($valor, $estilo = null)
    {
        if (is_string($valor) && $valor !== '') {
            if (method_exists(Cell::class, 'fromValue')) {
                return new \OpenSpout\Common\Entity\Cell\StringCell($valor, $estilo);
            }

            $celda = new Cell($valor, $estilo);
            $celda->setType(Cell::TYPE_STRING);

            return $celda;
        }

        return self::createCell($valor, $estilo);
    }

    public static function createRowFromArray(array $valores = [], $estilo = null)
    {
        $celdas = array_map(function ($valor) {
            return self::createCell($valor);
        }, $valores);

        return new Row($celdas, $estilo);
    }

    /**
     * 4.32 quitó RowIterator::end() y Reader::close() deja su XML abierto hasta EOF.
     * El acceso queda aislado acá y probado con un iterador retenido tras cerrar;
     * evita consumir el resto de un archivo grande solamente para soltar el handle.
     * Al actualizar OpenSpout hay que volver a verificar este contrato privado.
     */
    public static function cerrar_hoja($hoja)
    {
        if (self::es_version_cuatro()) {
            $filas = $hoja->getRowIterator();
            if (!$filas instanceof \OpenSpout\Reader\XLSX\RowIterator) {
                throw new \RuntimeException('El lector de Excel no permite cerrar la hoja de forma compatible.');
            }
            $propiedad = new \ReflectionProperty(get_class($filas), 'xmlReader');
            $propiedad->setAccessible(true);
            $xml = $propiedad->getValue($filas);
            if (!$xml instanceof \XMLReader) {
                throw new \RuntimeException('El lector de Excel no permite cerrar la hoja de forma compatible.');
            }
            $xml->close();
        }
    }

    /** Conserva la distinción de OpenSpout entre una celda vacía y una de error. */
    public static function celda_vacia($celda)
    {
        if (method_exists($celda, 'isEmpty')) {
            return $celda->isEmpty();
        }

        return $celda instanceof \OpenSpout\Common\Entity\Cell\EmptyCell;
    }

    /** Conserva DateTime mutable, como lo entregaba el lector de OpenSpout 3. */
    public static function normalizar_fila(Row $fila, array $formulas_reales = [], $base_fecha = '1899-12-30')
    {
        if (!method_exists(Cell::class, 'fromValue')) {
            return $fila;
        }

        foreach ($fila->getCells() as $indice => $celda) {
            $valor = $celda->getValue();
            $cambio = false;

            if (method_exists($celda, 'getComputedValue') &&
                ($celda->getComputedValue() !== null || isset($formulas_reales[$indice]))) {
                $valor = $celda->getComputedValue();
                if (isset($formulas_reales[$indice]) && $formulas_reales[$indice] === '') {
                    $valor = '';
                }
                $cambio = true;
            }

            if ($valor instanceof \DateTimeImmutable) {
                $valor = \DateTime::createFromImmutable($valor);
                $cambio = true;
            }
            if ($valor instanceof \DateInterval) {
                $fecha = \DateTime::createFromFormat('|Y-m-d', $base_fecha);
                $valor = $fecha->add($valor);
                $cambio = true;
            }

            if ($cambio) {
                /* En 3 un error tiene valor null pero NO es una celda vacía. */
                $normalizada = $valor === null && isset($formulas_reales[$indice])
                    ? new \OpenSpout\Common\Entity\Cell\ErrorCell('', $celda->getStyle())
                    : self::celda_literal($valor, $celda->getStyle());
                $fila->setCellAtIndex($normalizada, $indice);
            }
        }

        return $fila;
    }
}
