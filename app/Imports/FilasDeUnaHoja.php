<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lee las filas de UNA hoja tal como las recorre ClientImport / ProviderImport (misma librería, mismo
 * WithMultipleSheets, mismas celdas indexadas desde 0 y la fila 1 incluida). La usa la importación desde
 * soporte para armar el resumen antes de importar (misión importaciones-de-martin, 11/11/2026): lo que se le
 * muestra al dueño tiene que salir de las mismas filas que después importa el motor.
 */
class FilasDeUnaHoja implements ToCollection, WithMultipleSheets
{
    /** @var int */
    private $hoja;

    /** @var string|null */
    private $hoja_nombre;

    /** @var \Illuminate\Support\Collection */
    public $filas;

    public function __construct($hoja = 0, $hoja_nombre = null)
    {
        $this->hoja = (is_numeric($hoja) && (int) $hoja >= 0) ? (int) $hoja : 0;
        $this->hoja_nombre = (is_string($hoja_nombre) && trim($hoja_nombre) !== '') ? trim($hoja_nombre) : null;
        $this->filas = collect();
    }

    public function sheets(): array
    {
        if (!is_null($this->hoja_nombre)) {
            return [$this->hoja_nombre => $this];
        }

        return [$this->hoja => $this];
    }

    public function collection(Collection $rows)
    {
        $this->filas = $rows;
    }
}
