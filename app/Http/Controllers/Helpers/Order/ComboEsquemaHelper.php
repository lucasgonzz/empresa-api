<?php

namespace App\Http\Controllers\Helpers\Order;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;

/**
 * Guarda de esquema de `order_combo` (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * 🔴 POR QUE EXISTE. La tabla `order_combo` la crea una migracion de esta misma mision
 * (`2026_09_16_100200`). Un cliente de empresa puede estar corriendo este codigo ANTES de que esa
 * migracion haya pasado por su base —el deploy sube los archivos y despues migra, y entre las dos
 * cosas hay una ventana—, y ahi cualquier `$order->combos` es un `SQLSTATE[42S02]: Base table or
 * view not found`.
 *
 * Lo que ese error rompe no es "la parte de combos": rompe la CONFIRMACION DEL PEDIDO entera, que
 * es lo que le da la venta al comercio. Un cliente sin la tabla quedaria sin poder confirmar NINGUN
 * pedido de su tienda, que es mucho peor que el defecto que esta mision arregla (una venta corta por
 * el importe del combo). Por eso la guarda no es defensiva por las dudas: es el requisito para que
 * este arreglo se pueda desplegar.
 *
 * MEMOIZADA, y se evalua ANTES de tocar la relacion. `Schema::hasTable()` es una consulta a
 * `information_schema` y los caminos que la necesitan la preguntarian varias veces por request
 * (`scopeWithAll` en cada listado de pedidos, `get_total()` en cada recalculo, `save_sale()` en cada
 * confirmacion). Se pregunta una sola vez por proceso.
 *
 * Mismo criterio que las guardas de `buyer_tracking_events` (`SupportRetryPendingSyncs`,
 * `ActividadTiendaHelper`, `AgregarBuyerTracking`): la tabla que puede no estar se chequea, y si no
 * esta el camino sigue con el equivalente de "no hay nada", nunca con una excepcion.
 */
class ComboEsquemaHelper {

    /** Tabla pivote entre `orders` y `combos`. */
    const TABLA = 'order_combo';

    /**
     * Resultado memoizado de `Schema::hasTable(self::TABLA)`.
     *
     * `null` = todavia no se pregunto en este proceso. Mismo patron que
     * `EmpresaTestCase::$motor_innodb_verificado`.
     *
     * @var bool|null
     */
    private static $existe = null;

    /**
     * ¿La base de este cliente ya tiene `order_combo`?
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
     * Los combos de un pedido, o una coleccion vacia si la base todavia no tiene la tabla.
     *
     * 🔴 El `hay_tabla()` va PRIMERO y corta: acceder a `$order->combos` para despues preguntar ya
     * habria disparado la consulta que revienta.
     *
     * @param  \App\Models\Order  $order
     * @return \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection
     */
    static function combos_del_pedido($order) {

        if (!Self::hay_tabla()) {
            return new Collection();
        }

        return $order->combos;
    }

    /**
     * Relaciones de combos para el `with()` de `Order::scopeWithAll()`.
     *
     * Devuelve `['combos.articles']` —los componentes, no el combo pelado, por el mismo motivo que
     * `Sale::scopeWithAll()` y `Budget::scopeWithAll()`: quien lee los combos de un pedido es el
     * que despues tiene que descontar el stock de cada componente— o un array vacio si la tabla no
     * esta.
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
