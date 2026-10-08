<?php

namespace App\Http\Controllers\Helpers\Devoluciones;

use App\Http\Controllers\Helpers\ArticleHelper;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `article_current_acount.article_variant_id` (misión
 * variantes-mismo-articulo-en-vender, 8/10/2026).
 *
 * 🔴 Existe por la ventana del deploy: el upgrade sube los archivos y DESPUÉS corre las migraciones.
 * En ese rato la columna no existe, y nombrarla —en el `withPivot` de `CurrentAcount::articles()` o
 * en el `attach` de la NC— tumba toda lectura de una NC con artículos y toda devolución. Mismo patrón
 * que `RecargosEnPreciosEsquemaHelper`: se pregunta una vez por proceso y se recuerda.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class VarianteEnNotaCreditoEsquemaHelper {

    /** La tabla y la columna que pueden no estar todavía. */
    const TABLA = 'article_current_acount';
    const COLUMNA = 'article_variant_id';

    /**
     * null = todavía no se preguntó en este proceso.
     *
     * @var bool|null
     */
    private static $existe = null;

    /**
     * ¿Ya está la columna? Se pregunta una sola vez por proceso.
     *
     * @return bool
     */
    static function hay_columna() {

        if (is_null(Self::$existe)) {
            Self::$existe = Schema::hasColumn(Self::TABLA, Self::COLUMNA);
        }

        return Self::$existe;
    }

    /**
     * Olvida lo preguntado (para un proceso largo que corre la migración en el medio, o un test).
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe = null;
    }

    /**
     * Las columnas del `withPivot` de la relación, con la de la variante si existe.
     *
     * @param  array  $columnas
     * @return array
     */
    static function columnas_pivot($columnas) {

        if (Self::hay_columna()) {
            $columnas[] = Self::COLUMNA;
        }

        return $columnas;
    }

    /**
     * Agrega la variante del ítem devuelto al pivot de la NC, si la columna existe. La variante se
     * normaliza con el criterio de siempre (`ArticleHelper::misma_variante`): null, 0 y '' son "sin
     * variante" y se guardan como NULL.
     *
     * @param  array  $pivot  Lo que se va a adjuntar.
     * @param  array  $item   El ítem de la devolución.
     * @return array
     */
    static function agregar_al_pivot($pivot, $item) {

        if (!Self::hay_columna()) {
            return $pivot;
        }

        $variante = isset($item['article_variant_id']) ? $item['article_variant_id'] : null;

        $pivot[Self::COLUMNA] = ArticleHelper::misma_variante($variante, null) ? null : (int) $variante;

        return $pivot;
    }
}
