<?php

namespace App\Http\Controllers\Helpers;

/**
 * Tenencia de los registros de la tienda online que llegan por id de ruta (misión
 * tenencia-tienda-online, 19/10/2026).
 *
 * `DeliveryZoneController`, `OnlineConfigurationController`, `DeliveryDayController`,
 * `TitleController`, `PaymentMethodController`, `BuyerController` y `OrderController` resolvían
 * `show`/`update`/`destroy` con `Model::find($id)` (o `fullModel`) sin cruzar el id con el dueño
 * de la sesión. En una base compartida entre comercios (`u767360347_empresa`, ids correlativos) un
 * usuario podía leer, editar o borrar la forma de envío, la configuración online, el banner, el
 * método de pago (con sus credenciales de Mercado Pago), el comprador o el pedido de OTRO comercio
 * mandando su id.
 *
 * Criterio, el mismo de cheques, listas de precio y compras (ver `ChequeHelper::id_del_dueno()`):
 * lo ajeno, lo inexistente y lo que no es un id se contestan IGUAL —404 JSON con `message`— y se
 * resuelve ANTES de leer o escribir nada. Para el dueño (y para sus empleados, que `userId()`
 * resuelve al dueño) la respuesta es la de siempre.
 */
class TenenciaDeLaTiendaHelper
{
    /**
     * Devuelve null si el registro es del dueño de la sesión y, si no, el 404 JSON que el
     * llamador contesta tal cual (`return` del controller), sin tocar nada.
     *
     * @param  string  $clase     Clase del modelo (`DeliveryZone::class`). Tiene que tener `user_id`.
     * @param  mixed   $id        Lo que llegó en la ruta.
     * @param  mixed   $user_id   El dueño de la sesión (`$this->userId()`).
     * @param  string  $mensaje   Ej.: 'La forma de envío no existe o no es de tu cuenta.'
     * @return \Illuminate\Http\JsonResponse|null
     */
    public static function respuesta_si_no_es_del_dueno($clase, $id, $user_id, $mensaje)
    {
        $id_del_dueno = ChequeHelper::id_del_dueno($clase, $id, $user_id);

        if (is_null($id_del_dueno)) {

            return response()->json(['message' => $mensaje], 404);
        }

        return null;
    }
}
