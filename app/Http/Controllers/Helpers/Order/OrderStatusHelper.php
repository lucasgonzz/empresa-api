<?php

namespace App\Http\Controllers\Helpers\Order;

/**
 * La maquina de estados de un pedido online, en un solo lugar.
 *
 * 🔴 Existe porque desde el 22/8/2026 el estado se maneja UNICAMENTE desde el select del formulario
 * del pedido (se sacaron los botones "Confirmar pedido" y "Cancelar pedido" del modal). El select
 * ofrece todas las filas de `order_statuses`, asi que sin una regla el usuario podia mandar un
 * pedido ya entregado de vuelta a "Sin confirmar" y dejar una venta viva colgada de un pedido sin
 * confirmar.
 *
 * La regla, dictada por Lucas: **nunca para atras**. Se puede avanzar (salteando estados si hace
 * falta) y se puede cancelar, salvo desde los dos estados terminales.
 *
 * ⚠️ Los estados se resuelven por NOMBRE y no por id. `order_statuses` no tiene ids garantizados
 * entre instalaciones: cada base corre `OrderStatusSeeder` por su cuenta y un cliente viejo puede
 * tener otro orden. El nombre es lo unico estable.
 */
class OrderStatusHelper {

    /**
     * Los estados de avance, EN ORDEN. La posicion en este array es la que define que es "avanzar".
     *
     * @var array<int,string>
     */
    const AVANCE = ['Sin confirmar', 'Confirmado', 'Terminado', 'Entregado'];

    /**
     * @var string
     */
    const CANCELADO = 'Cancelado';

    /**
     * @var string
     */
    const SIN_CONFIRMAR = 'Sin confirmar';

    /**
     * @var string
     */
    const ENTREGADO = 'Entregado';

    /**
     * Estados desde los que ya no se puede mover el pedido a ningun otro.
     *
     * @var array<int,string>
     */
    const TERMINALES = ['Entregado', 'Cancelado'];

    /**
     * Si el pedido puede pasar de un estado a otro.
     *
     * @param  string|null  $desde  Nombre del estado actual.
     * @param  string|null  $hacia  Nombre del estado al que se lo quiere llevar.
     * @return bool
     */
    static function puede_pasar($desde, $hacia) {

        // Sin dato de alguno de los dos lados no se puede decidir nada: no se bloquea, porque el
        // que valida no es quien tiene que reportar un pedido con datos rotos.
        if (is_null($desde) || is_null($hacia)) {
            return true;
        }

        /**
         * 🔴 Quedarse en el mismo estado SIEMPRE se permite, incluso desde los terminales.
         *
         * No es una concesion: el formulario generico manda el modelo entero, asi que editar el
         * deposito o una nota de un pedido ya entregado reenvia su `order_status_id` sin cambiarlo.
         * Si eso diera 422, el pedido quedaria imposible de editar para siempre.
         */
        if ($desde == $hacia) {
            return true;
        }

        // Desde un terminal no se sale.
        if (in_array($desde, self::TERMINALES)) {
            return false;
        }

        // Cancelar se puede desde cualquier estado no terminal.
        if ($hacia == self::CANCELADO) {
            return true;
        }

        $pos_desde = array_search($desde, self::AVANCE);
        $pos_hacia = array_search($hacia, self::AVANCE);

        // Un estado que no esta en la cadena de avance (y no es Cancelado, ya descartado arriba)
        // no se sabe donde ubicar: se rechaza en vez de adivinar.
        if ($pos_desde === false || $pos_hacia === false) {
            return false;
        }

        // Avanzar si, incluso salteando. Retroceder no.
        return $pos_hacia > $pos_desde;
    }

    /**
     * Mensaje para el 422 cuando la transicion no se permite. Se le habla al usuario del sistema,
     * no al programador.
     *
     * @param  string  $desde
     * @param  string  $hacia
     * @return string
     */
    static function motivo_del_rechazo($desde, $hacia) {

        if (in_array($desde, self::TERMINALES)) {
            return 'El pedido está '.mb_strtolower($desde).' y no se puede cambiar de estado.';
        }

        return 'No se puede volver un pedido de "'.$desde.'" a "'.$hacia.'". El estado de un pedido solo puede avanzar o cancelarse.';
    }

    /**
     * Si esta transicion es la que confirma el pedido por primera vez, o sea la que tiene que hacer
     * nacer la venta.
     *
     * Vale tambien para los saltos: "Sin confirmar" -> "Entregado" confirma igual.
     *
     * @param  string|null  $desde
     * @param  string|null  $hacia
     * @return bool
     */
    static function es_la_confirmacion($desde, $hacia) {
        return $desde == self::SIN_CONFIRMAR
                && $hacia != self::SIN_CONFIRMAR
                && $hacia != self::CANCELADO;
    }

    /**
     * Si esta transicion cancela el pedido (y no es un pedido que ya estaba cancelado).
     *
     * @param  string|null  $desde
     * @param  string|null  $hacia
     * @return bool
     */
    static function es_la_cancelacion($desde, $hacia) {
        return $hacia == self::CANCELADO && $desde != self::CANCELADO;
    }
}
