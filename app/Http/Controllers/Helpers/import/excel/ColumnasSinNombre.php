<?php

namespace App\Http\Controllers\Helpers\import\excel;

/**
 * Reparte las columnas del Excel que no tienen título entre los ítems del mapeo que la IA
 * devuelve sin nombre de columna.
 *
 * Misión importacion-fila-sin-identificador (10/11/2026). Un ítem sin nombre no se puede cruzar
 * con ningún encabezado, y usar su posición dentro de la respuesta de la IA como índice de
 * columna solo acierta si la IA devolvió TODAS las columnas. En la planilla de Mayorista de pesca
 * no devolvió la columna A, también sin título, y la columna D (la de los costos) salió con la
 * letra C, la misma del nombre: dos filas de la pantalla apuntando a la misma columna del Excel.
 *
 * La regla vive acá para que los analizadores de artículos, clientes y proveedores no tengan
 * cada uno su copia.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 */
class ColumnasSinNombre
{
    /**
     * Elige la columna sin título que le corresponde a un ítem del mapeo y la saca de las libres.
     *
     * Orden de preferencia:
     *   1. La primera columna libre con índice mayor al del ítem anterior del mapeo, porque la IA
     *      devuelve las columnas en el orden de la planilla aunque se saltee alguna.
     *   2. Si no hay ninguna, la primera columna libre.
     *   3. Si no quedan libres, null: el llamador conserva su comportamiento de siempre.
     *
     * @param  array $indices_libres  Índices 0-based de las columnas sin título todavía no
     *                                asignadas, en orden ascendente. Se modifica por referencia.
     * @param  int   $indice_anterior Índice asignado al ítem anterior del mapeo; -1 si es el primero.
     * @return int|null               Índice elegido, o null si no quedan columnas libres.
     */
    public static function tomar_siguiente(array &$indices_libres, $indice_anterior)
    {
        if (count($indices_libres) === 0) {
            return null;
        }

        // Posición dentro de $indices_libres de la columna elegida; por defecto, la primera libre.
        $posicion_elegida = array_key_first($indices_libres);

        foreach ($indices_libres as $posicion => $indice) {

            if ($indice > $indice_anterior) {
                $posicion_elegida = $posicion;
                break;
            }
        }

        // Se guarda el índice antes de quitarlo, para que ningún otro ítem pueda volver a tomarlo.
        $indice_elegido = $indices_libres[$posicion_elegida];

        unset($indices_libres[$posicion_elegida]);

        return $indice_elegido;
    }
}
