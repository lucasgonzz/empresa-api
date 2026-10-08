<?php

namespace App\Http\Controllers\Helpers\Budget;

use App\Http\Controllers\Helpers\ArticleHelper;
use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de `article_budget.article_variant_id` / `variant_description` (misión
 * presupuestos-con-variantes, 8/10/2026).
 *
 * 🔴 Existe por la ventana del deploy: el upgrade sube los archivos y DESPUÉS corre las migraciones.
 * En ese rato las columnas no existen, y nombrarlas —en el `withPivot` de `Budget::articles()` o en
 * el `attach` de `BudgetHelper::attachArticles()`— tumba toda lectura de presupuestos, todo PDF de
 * presupuesto y todo guardado. Mismo patrón que `VarianteEnNotaCreditoEsquemaHelper` y
 * `RecargosEnPreciosEsquemaHelper`: se pregunta una vez por proceso y se recuerda.
 *
 * Se pregunta solo por `article_variant_id`: las dos columnas nacen en la misma migración y una sin
 * la otra no tiene sentido. Sin ella, ninguna de las dos se nombra.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promoción de constructor, readonly, enum ni #[...].
 */
class VarianteEnPresupuestoEsquemaHelper {

    /** La tabla y las columnas que pueden no estar todavía. */
    const TABLA = 'article_budget';
    const COLUMNA = 'article_variant_id';
    const COLUMNA_DESCRIPCION = 'variant_description';

    /**
     * null = todavía no se preguntó en este proceso.
     *
     * @var bool|null
     */
    private static $existe = null;

    /**
     * ¿Ya están las columnas? Se pregunta una sola vez por proceso.
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
     * Las columnas del `withPivot` de `Budget::articles()`, con las dos de la variante si existen.
     *
     * @param  array  $columnas
     * @return array
     */
    static function columnas_pivot($columnas) {

        if (Self::hay_columna()) {
            $columnas[] = Self::COLUMNA;
            $columnas[] = Self::COLUMNA_DESCRIPCION;
        }

        return $columnas;
    }

    /**
     * Agrega la variante del renglón al pivot de `article_budget`, si las columnas existen. La
     * variante se normaliza con el criterio de siempre (`ArticleHelper::misma_variante`): null, 0 y
     * '' son "sin variante" y se guardan como NULL. La descripción se guarda tal cual venga (puede
     * quedar con la variante en NULL si la variante ya no existe: ver
     * `BudgetHelper::variante_del_renglon()`).
     *
     * @param  array        $pivot                Lo que se va a adjuntar.
     * @param  mixed        $article_variant_id   La variante del renglón (null = sin variante).
     * @param  string|null  $variant_description  Su descripción.
     * @return array
     */
    static function agregar_al_pivot($pivot, $article_variant_id, $variant_description) {

        if (!Self::hay_columna()) {
            return $pivot;
        }

        $pivot[Self::COLUMNA] = ArticleHelper::misma_variante($article_variant_id, null) ? null : (int) $article_variant_id;
        $pivot[Self::COLUMNA_DESCRIPCION] = ($variant_description === '' ? null : $variant_description);

        return $pivot;
    }

    /**
     * La variante guardada en el pivot de un renglón ya leído (null si no tiene, o si la columna
     * todavía no existe: sin columna el atributo no viene).
     *
     * @param  object  $pivot
     * @return int|null
     */
    static function variante_del_pivot($pivot) {

        if (!isset($pivot->{Self::COLUMNA})) {
            return null;
        }

        return ArticleHelper::misma_variante($pivot->{Self::COLUMNA}, null) ? null : (int) $pivot->{Self::COLUMNA};
    }

    /**
     * La descripción de variante guardada en el pivot de un renglón ya leído (null si no tiene).
     *
     * @param  object  $pivot
     * @return string|null
     */
    static function descripcion_del_pivot($pivot) {

        if (!isset($pivot->{Self::COLUMNA_DESCRIPCION}) || $pivot->{Self::COLUMNA_DESCRIPCION} === '') {
            return null;
        }

        return $pivot->{Self::COLUMNA_DESCRIPCION};
    }
}
