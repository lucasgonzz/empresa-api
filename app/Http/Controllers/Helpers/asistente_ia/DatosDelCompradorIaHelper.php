<?php

namespace App\Http\Controllers\Helpers\asistente_ia;

use App\Models\Order;
use Carbon\Carbon;

/**
 * Datos de contacto y de entrega del comprador de un pedido de la tienda (misión asistente-diario-20261128, P1 y P2).
 *
 * Los usan dos lugares con el mismo criterio: el aviso de «Entró un pedido nuevo» que le llega al dueño por
 * WhatsApp (`AvisosDelDuenoIaHelper`) y la consulta de un pedido desde el asistente
 * (`PropuestaAccionDePantallaIaHelper`), así los dos dicen lo mismo del mismo pedido.
 *
 * El contacto de un pedido es el del COMPRADOR de la tienda (`buyers`), no el de la ficha de clientes del
 * sistema (`clients`): son dos registros distintos y el comprador puede no estar vinculado a ningún cliente.
 *
 * Sin estado: todo son métodos estáticos puros sobre el pedido (lo único que consultan es lo que el pedido ya
 * trae cargado o sus relaciones). PHP 7.4.
 */
class DatosDelCompradorIaHelper
{
    /** Largo máximo de las observaciones del comprador (si pasan, se cortan y terminan en «…»). */
    const LARGO_MAXIMO_DE_OBSERVACIONES = 300;

    /**
     * «Nombre Apellido» del comprador, sin asteriscos (en WhatsApp serían negrita) o «un comprador» si no hay.
     *
     * @param  \App\Models\Order  $pedido
     * @return string
     */
    public static function nombre(Order $pedido): string
    {
        $comprador = $pedido->buyer;

        $nombre = is_null($comprador) ? '' : trim(trim((string) $comprador->name) . ' ' . trim((string) $comprador->surname));

        return $nombre === '' ? 'un comprador' : str_replace('*', '', $nombre);
    }

    /**
     * El teléfono del comprador, o null si no lo cargó.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function telefono(Order $pedido)
    {
        return self::texto_o_null(is_null($pedido->buyer) ? null : $pedido->buyer->phone);
    }

    /**
     * El mail del comprador, o null si no lo cargó.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function email(Order $pedido)
    {
        return self::texto_o_null(is_null($pedido->buyer) ? null : $pedido->buyer->email);
    }

    /**
     * La dirección de entrega con piso y referencia: «Ramón Cárcano 251, Córdoba, Torre B9 1B (Country, tiene
     * guardia)». Es null si el pedido no es con envío a domicilio o si no hay ninguna dirección cargada.
     * Prefiere la dirección formateada del pedido; si no, calle, número y ciudad; si no, el texto viejo `address`.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function direccion_de_entrega(Order $pedido)
    {
        if ((int) $pedido->deliver !== 1) {

            return null;
        }

        $direccion = is_array($pedido->envio_direccion) ? $pedido->envio_direccion : [];

        // La base: la dirección formateada o, si falta, calle + número y ciudad.
        $base = self::texto_o_null(isset($direccion['formatted_address']) ? $direccion['formatted_address'] : null);

        if (is_null($base)) {

            $calle = trim(self::texto(isset($direccion['street']) ? $direccion['street'] : null) . ' ' . self::texto(isset($direccion['street_number']) ? $direccion['street_number'] : null));
            $ciudad = self::texto(isset($direccion['city']) ? $direccion['city'] : null);

            $partes = array_filter([$calle, $ciudad], function ($parte) {
                return $parte !== '';
            });

            $base = count($partes) ? implode(', ', $partes) : null;
        }

        // Sin dirección útil en el pedido nuevo, el texto viejo de la columna `address`.
        if (is_null($base)) {

            return self::texto_o_null($pedido->address);
        }

        $piso = self::texto(isset($direccion['apartment']) ? $direccion['apartment'] : null);
        $referencia = self::texto(isset($direccion['reference']) ? $direccion['reference'] : null);

        return $base . ($piso === '' ? '' : ', ' . $piso) . ($referencia === '' ? '' : ' (' . $referencia . ')');
    }

    /**
     * La fecha de entrega que pidió el comprador como «27/11/2026», o null si no hay.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function fecha_de_entrega(Order $pedido)
    {
        $fecha = self::texto_o_null($pedido->fecha_entrega);

        if (is_null($fecha)) {

            return null;
        }

        try {

            return Carbon::parse($fecha)->format('d/m/Y');

        } catch (\Throwable $e) {

            return null;
        }
    }

    /**
     * Lo que anotó el comprador al pedir (`orders.description`), cortado a LARGO_MAXIMO_DE_OBSERVACIONES
     * caracteres (con «…» al final si se cortó, sin pasar del máximo), o null si no escribió nada.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function observaciones(Order $pedido)
    {
        $observaciones = self::texto_o_null($pedido->description);

        if (is_null($observaciones)) {

            return null;
        }

        if (mb_strlen($observaciones) <= self::LARGO_MAXIMO_DE_OBSERVACIONES) {

            return $observaciones;
        }

        return rtrim(mb_substr($observaciones, 0, self::LARGO_MAXIMO_DE_OBSERVACIONES - 1)) . '…';
    }

    /**
     * El nombre del cliente del sistema al que está vinculado el comprador (vivo: uno borrado llega como null),
     * o null si no está vinculado.
     *
     * @param  \App\Models\Order  $pedido
     * @return string|null
     */
    public static function cliente_vinculado(Order $pedido)
    {
        $comprador = $pedido->buyer;

        if (is_null($comprador) || is_null($comprador->comercio_city_client)) {

            return null;
        }

        return self::texto_o_null($comprador->comercio_city_client->name);
    }

    /**
     * Un valor cualquiera como texto sin espacios en los extremos ('' si es null o no es escalar).
     *
     * @param  mixed  $valor
     * @return string
     */
    protected static function texto($valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    /**
     * Como texto(), pero null si queda vacío.
     *
     * @param  mixed  $valor
     * @return string|null
     */
    protected static function texto_o_null($valor)
    {
        $texto = self::texto($valor);

        return $texto === '' ? null : $texto;
    }
}
