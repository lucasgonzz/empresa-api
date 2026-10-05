<?php

namespace App\Http\Controllers\Helpers\category_proposal;

/**
 * Las reglas de plata y de Tienda Nube que bloquean elegir un sistema de categorías nuevo (misión
 * categorizacion-tres-modelos, 5/10/2026). Plan §4.5 y §4.6.
 *
 * 🔴 ESQUELETO DE LA BASE: las firmas son contrato entre los constructores (API-1 las consume desde
 * `actual` y `resumen`; API-2 las implementa). Mientras API-2 no entregó devuelven el valor seguro
 * por defecto ("no bloquea, sin advertencias"). API-2 reemplaza los cuerpos conservando las firmas.
 */
class CategoryMargenesHelper
{
    /**
     * ¿El dueño usa margen o listas de precio por categoría? Especificación EXACTA en
     * relevamiento/R1-efectos-de-aplicar-categorias.md §2.5 (seis SQL, motivos R1 a R6).
     *
     * @param  int $user_id  Un dueño o un empleado (se resuelve al dueño).
     * @return array  ['usa' => bool, 'motivos' => [['codigo' => 'R1', 'cantidad' => 3], ...], 'avisos' => [...]]
     */
    public static function usa_margenes_por_categoria($user_id)
    {
        return ['usa' => false, 'motivos' => [], 'avisos' => []];
    }

    /**
     * ¿El dueño usa Tienda Nube? (`config('app.USA_TIENDA_NUBE')` o la extensión `usa_tienda_nube`.)
     *
     * @param  int $user_id
     * @return bool
     */
    public static function usa_tienda_nube($user_id)
    {
        return false;
    }

    /**
     * El bloqueo de los sistemas `nueva` para este dueño: márgenes por categoría y Tienda Nube.
     * Es lo que muestra `actual` y lo que corta `elegir` (422). "Mantener las mías" nunca se bloquea.
     *
     * @param  int $user_id
     * @return array  ['nuevo_modelo_bloqueado' => bool, 'motivos' => [['codigo' => 'R1'|...|'tienda_nube', 'cantidad' => n]]]
     */
    public static function bloqueo_para($user_id)
    {
        return ['nuevo_modelo_bloqueado' => false, 'motivos' => []];
    }

    /**
     * Las advertencias que no bloquean pero el dueño tiene que ver antes de confirmar: códigos
     * `vinculacion_de_inventario`, `urls_de_la_tienda` y `precios_a_recalcular`.
     *
     * @param  int         $user_id
     * @param  string|null $tipo  `nueva` | `mantener` | null (las que valen para cualquier tipo).
     * @return array  Lista de códigos (strings).
     */
    public static function advertencias_para($user_id, $tipo = null)
    {
        return [];
    }
}
