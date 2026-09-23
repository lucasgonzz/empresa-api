<?php

namespace App\Http\Controllers\Helpers;

use Illuminate\Support\Facades\Schema;

/**
 * Guarda de esquema de las cuatro tablas de la misión descuentos-recargos-por-cliente (23/9/2026):
 * `client_discount`, `client_surchage` (ficha del cliente) y `discount_order`, `order_surchage`
 * (pedido de la tienda).
 *
 * 🔴 POR QUÉ EXISTE, mismo motivo que `Order\ComboEsquemaHelper`: el deploy sube los archivos y
 * DESPUÉS migra, y un cliente viejo puede traer huecos en su tabla `migrations`. En esa ventana,
 * meter `discounts`/`surchages` a secas en el `withAll()` de `Client` o de `Order` es un
 * `SQLSTATE[42S02]: Base table or view not found`, y lo que se cae no es "la parte de descuentos":
 * se cae el LISTADO DE CLIENTES, el buscador de clientes de Vender, el listado de pedidos y la
 * confirmación de pedidos. Con la guarda, sin tablas el sistema se comporta exactamente como antes.
 *
 * Se memoiza SOLO el "sí": una vez que la tabla existe no desaparece, así que no se vuelve a
 * preguntar en el proceso. El "no" se vuelve a preguntar cada vez a propósito: un worker de cola
 * o un proceso largo que arrancó antes de la migración tiene que enterarse cuando la tabla aparece
 * sin esperar a que lo reinicien. Mientras falta, cuesta una consulta a `information_schema` por
 * llamada, y eso solo pasa durante la ventana del deploy.
 */
class AjustesDeClienteEsquemaHelper {

    /**
     * Tablas que ya se vieron existir en este proceso, por nombre.
     *
     * @var array<string,bool>
     */
    private static $existen = [];

    /**
     * ¿La base tiene la tabla?
     *
     * @param  string  $tabla
     * @return bool
     */
    static function hay_tabla($tabla) {

        if (isset(Self::$existen[$tabla])) {
            return true;
        }

        if (Schema::hasTable($tabla)) {
            Self::$existen[$tabla] = true;
            return true;
        }

        return false;
    }

    /**
     * ¿Están las dos tablas de la ficha del cliente?
     *
     * @return bool
     */
    static function hay_tablas_de_cliente() {
        return Self::hay_tabla('client_discount') && Self::hay_tabla('client_surchage');
    }

    /**
     * ¿Están las dos tablas del pedido?
     *
     * @return bool
     */
    static function hay_tablas_de_pedido() {
        return Self::hay_tabla('discount_order') && Self::hay_tabla('order_surchage');
    }

    /**
     * Relaciones para el `with()` de `Client::scopeWithAll()`, o nada si faltan las tablas.
     *
     * @return array<int,string>
     */
    static function relaciones_de_cliente() {

        if (!Self::hay_tablas_de_cliente()) {
            return [];
        }

        return ['discounts', 'surchages'];
    }

    /**
     * Relaciones para el `with()` de `Order::scopeWithAll()`, o nada si faltan las tablas.
     *
     * @return array<int,string>
     */
    static function relaciones_de_pedido() {

        if (!Self::hay_tablas_de_pedido()) {
            return [];
        }

        return ['discounts', 'surchages'];
    }

    /**
     * Borra la memoización. La usan los tests que esconden una tabla para medir la guarda.
     *
     * @return void
     */
    static function olvidar() {
        Self::$existen = [];
    }
}
