<?php

namespace App\Http\Controllers\Helpers\Budget;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

/**
 * Guarda de esquema de `budget_combo` (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * 🔴 POR QUE EXISTE. La tabla `budget_combo` la crea una migracion de esta misma mision
 * (`2026_09_16_100300`). Un cliente de empresa puede estar corriendo este codigo ANTES de que esa
 * migracion haya pasado por su base —el deploy sube los archivos y DESPUES migra, y entre las dos
 * cosas hay una ventana—, y ahi cualquier `$budget->combos` es un `SQLSTATE[42S02]: Base table or
 * view not found`.
 *
 * Lo que ese error rompe no es "la parte de combos" del presupuesto: rompe EL MODULO ENTERO. Sin
 * esta guarda, en esa ventana el cliente no puede ABRIR el listado de presupuestos
 * (`Budget::scopeWithAll()` eager-loadea la relacion), no puede GUARDAR uno (`attachCombos()` y
 * `getTotal()` la tocan en cada alta), no puede CONFIRMARLO (`attachSaleCombos()`), no puede
 * DUPLICARLO (`BudgetDuplicarHelper::combos_to_payload()`) ni IMPRIMIRLO (`BudgetPdf::items()`).
 * O sea: mucho peor que el defecto que esta mision arregla (un combo que no se guardaba). Por eso
 * la guarda no es defensiva por las dudas: es el requisito para que este arreglo se pueda desplegar.
 *
 * Es la gemela de `App\Http\Controllers\Helpers\Order\ComboEsquemaHelper`, que hace lo mismo para
 * `order_combo`. Son dos clases y no una sola parametrizada a proposito: cada una memoiza SU tabla,
 * y un memo compartido obligaria a un mapa por nombre para no responder por la tabla equivocada.
 *
 * MEMOIZADA, y se evalua ANTES de tocar la relacion. `Schema::hasTable()` es una consulta a
 * `information_schema` y los caminos que la necesitan la preguntarian varias veces por request
 * (`scopeWithAll` en cada listado, `getTotal()` en cada alta y en cada update, `attachCombos()` en
 * las dos). Se pregunta una sola vez por proceso.
 *
 * Mismo criterio que las guardas de `buyer_tracking_events` (`SupportRetryPendingSyncs`,
 * `ActividadTiendaHelper`, `AgregarBuyerTracking`): la tabla que puede no estar se chequea, y si no
 * esta el camino sigue con el equivalente de "no hay nada", nunca con una excepcion.
 */
class ComboEsquemaHelper {

    /** Tabla pivote entre `budgets` y `combos`. */
    const TABLA = 'budget_combo';

    /**
     * Resultado memoizado de `Schema::hasTable(self::TABLA)`.
     *
     * `null` = todavia no se pregunto en este proceso. Mismo patron que el helper gemelo de
     * `order_combo`.
     *
     * @var bool|null
     */
    private static $existe = null;

    /**
     * ¿La base de este cliente ya tiene `budget_combo`?
     *
     * @return bool
     */
    static function hay_tabla() {

        if (is_null(Self::$existe)) {
            Self::$existe = Schema::hasTable(Self::TABLA);
        }

        return Self::$existe;
    }

    /**
     * Borra la memoizacion.
     *
     * La usa el test de la guarda, que necesita que la respuesta se vuelva a preguntar despues de
     * sacar y volver a poner la tabla. Sin esto, el memo de un test se le filtraria a todos los que
     * corren despues en el mismo proceso de PHPUnit.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existe = null;
    }

    /**
     * Los combos de un presupuesto, o una coleccion vacia si la base todavia no tiene la tabla.
     *
     * 🔴 El `hay_tabla()` va PRIMERO y corta: acceder a `$budget->combos` para despues preguntar ya
     * habria disparado la consulta que revienta.
     *
     * @param  \App\Models\Budget  $budget
     * @return \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection
     */
    static function combos_del_presupuesto($budget) {

        if (!Self::hay_tabla()) {
            return new Collection();
        }

        return $budget->combos;
    }

    /**
     * Relaciones de combos para el `with()` de `Budget::scopeWithAll()`.
     *
     * Devuelve `['combos.articles']` —los componentes, no el combo pelado, por el mismo motivo que
     * `Sale::scopeWithAll()`: quien lee los combos de un presupuesto es el que despues tiene que
     * descontar el stock de cada componente al confirmarlo— o un array vacio si la tabla no esta.
     *
     * @return array<int,string>
     */
    static function relaciones_de_combos() {

        if (!Self::hay_tabla()) {
            return [];
        }

        return ['combos.articles'];
    }

}
