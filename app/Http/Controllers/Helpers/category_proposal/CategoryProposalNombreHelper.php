<?php

namespace App\Http\Controllers\Helpers\category_proposal;

use Illuminate\Support\Str;

/**
 * Normalización de nombres de categorías y subcategorías (misión categorizacion-tres-modelos,
 * 5/10/2026).
 *
 * Una sola función para todo: resolver los nombres que manda la skill en las asignaciones contra los
 * nodos de una propuesta, detectar nombres repetidos dentro de un nivel y reutilizar una categoría
 * que el dueño ya tenía con el mismo nombre.
 */
class CategoryProposalNombreHelper
{
    /**
     * La clave de comparación de un nombre: minúsculas, sin acentos, espacios colapsados y sin
     * espacios en los bordes. Es el criterio de `PropuestaBancosChequesIaHelper::nombre_normalizado`.
     *
     * 🔴 Es al menos tan permisiva como la collation `utf8mb4_unicode_ci` de las columnas `name`
     * (que ya iguala sin mayúsculas, sin acentos y sin espacios finales): así nunca se duplica una
     * categoría que un `where('name', ...)` del sistema ya vería igual. Por eso NO se usa
     * `strtolower` pelado (el importador lo usa y en PHP 7.4 sobre Windows corrompe los acentos).
     *
     * @param  string|null $nombre
     * @return string  '' si el nombre queda vacío tras normalizar.
     */
    public static function clave_de($nombre)
    {
        $nombre = Str::ascii((string) $nombre);
        $nombre = preg_replace('/\s+/u', ' ', $nombre);

        return mb_strtolower(trim($nombre));
    }

    /**
     * La clave de la categoría que tienda-api esconde por nombre (`La de siempre`): nunca se crea ni se
     * reutiliza una categoría con ese nombre.
     */
    const CLAVE_RESERVADA = 'la de siempre';

    /**
     * ¿El nombre se puede usar como categoría o subcategoría? No si queda vacío al normalizar o si es
     * el nombre reservado de la tienda.
     *
     * @param  string|null $nombre
     * @return bool
     */
    public static function es_usable($nombre)
    {
        $clave = self::clave_de($nombre);

        return $clave !== '' && $clave !== self::CLAVE_RESERVADA;
    }
}
