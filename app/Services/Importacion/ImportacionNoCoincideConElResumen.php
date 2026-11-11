<?php

namespace App\Services\Importacion;

use RuntimeException;

/**
 * Lo que hizo una tanda de la importación desde soporte no es lo que el resumen le mostró al dueño (misión
 * importaciones-de-martin, 11/11/2026). Se lanza adentro de la transacción de la tanda: la deshace entera, para que
 * no quede ningún cambio —ni ningún peso— sin registro.
 */
class ImportacionNoCoincideConElResumen extends RuntimeException
{
}
