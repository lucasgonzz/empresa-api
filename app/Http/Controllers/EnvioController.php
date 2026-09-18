<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use App\Models\Order;
use App\Services\Zipnova\EnvioNoGenerableException;
use App\Services\Zipnova\ZipnovaEnvioService;
use App\Services\Zipnova\ZipnovaException;

/**
 * Gestión del envío de un pedido de la tienda desde el ERP (misión zipnova-envios, 14/9/2026):
 * generarlo en Zipnova, ver su estado, sincronizarlo, cancelarlo y bajar la etiqueta. Lo usa
 * el modal "Envío" de Tienda online -> Pedidos.
 *
 * Todo está scopeado por `user_id = $this->userId()`: un envío o un pedido de otro comercio es
 * 404, no 403, para no confirmar que existe (misma base compartida entre comercios en las
 * instalaciones viejas).
 *
 * Códigos:
 *  - 422 `{message}`: una precondición no se cumple (`EnvioNoGenerableException`) o Zipnova
 *    rechazó la operación (`ZipnovaException`). El mensaje ya viene escrito para el operador y
 *    el modal lo muestra tal cual.
 *  - 404: el pedido o el envío no es del comercio.
 *
 * `generar` responde el PEDIDO completo (`fullModel('Order')`, que ya trae `envio` por
 * `withAll`) y no el envío suelto, porque el modal y la fila del listado se pintan desde el
 * pedido (`order/setModel` en la SPA). Los demás responden el envío.
 */
class EnvioController extends Controller
{
    /**
     * POST envio/generar/{order_id}: genera el envío del pedido en Zipnova.
     *
     * @param int $order_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function generar($order_id)
    {
        $order = Order::where('user_id', $this->userId())->where('id', $order_id)->first();

        if (is_null($order)) {
            return response()->json(['message' => 'El pedido no existe.'], 404);
        }

        try {
            (new ZipnovaEnvioService())->crear_desde_pedido($order);
        } catch (EnvioNoGenerableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['model' => $this->fullModel('Order', $order->id)], 200);
    }

    /**
     * GET envio/{id}.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        $envio = $this->envio_del_comercio($id);

        if (is_null($envio)) {
            return response()->json(['message' => 'El envío no existe.'], 404);
        }

        return response()->json(['model' => $envio], 200);
    }

    /**
     * POST envio/{id}/sincronizar: trae el estado actual desde Zipnova.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar($id)
    {
        $envio = $this->envio_del_comercio($id);

        if (is_null($envio)) {
            return response()->json(['message' => 'El envío no existe.'], 404);
        }

        try {
            (new ZipnovaEnvioService())->sincronizar($envio);
        } catch (EnvioNoGenerableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['model' => $envio->fresh()], 200);
    }

    /**
     * POST envio/{id}/cancelar: pide la cancelación a Zipnova y sincroniza.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelar($id)
    {
        $envio = $this->envio_del_comercio($id);

        if (is_null($envio)) {
            return response()->json(['message' => 'El envío no existe.'], 404);
        }

        try {
            (new ZipnovaEnvioService())->cancelar($envio);
        } catch (EnvioNoGenerableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['model' => $envio->fresh()], 200);
    }

    /**
     * GET envio/{id}/etiqueta: el PDF de la etiqueta, inline, para que la SPA lo abra con
     * `window.open` (la sesión de Sanctum viaja en la cookie, igual que en los otros PDF).
     *
     * Zipnova responde 409 mientras la etiqueta no está lista (recién creado el envío tarda
     * unos segundos): se traduce a un 422 con un mensaje que dice qué hacer.
     *
     * @param int $id
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function etiqueta($id)
    {
        $envio = $this->envio_del_comercio($id);

        if (is_null($envio)) {
            return response()->json(['message' => 'El envío no existe.'], 404);
        }

        try {
            $bytes = (new ZipnovaEnvioService())->etiqueta_pdf($envio);
        } catch (EnvioNoGenerableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (ZipnovaException $e) {
            if ($e->getStatus() === 409) {
                return response()->json(['message' => 'La etiqueta todavía no está lista, probá en unos minutos'], 422);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $num = $this->numero_del_pedido($envio);

        return response($bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="etiqueta-envio-' . $num . '.pdf"');
    }

    /**
     * Envío por id, solo si es del comercio autenticado.
     *
     * @param int $id
     * @return Envio|null
     */
    protected function envio_del_comercio($id)
    {
        return Envio::where('user_id', $this->userId())->where('id', $id)->first();
    }

    /**
     * Número del pedido para el nombre del archivo: `orders.num` si lo tiene, si no el id del
     * pedido, si no el id del envío.
     *
     * @param Envio $envio
     * @return string
     */
    protected function numero_del_pedido(Envio $envio)
    {
        $order = $envio->order;

        if (!is_null($order)) {
            return (string) (!empty($order->num) ? $order->num : $order->id);
        }

        return (string) $envio->id;
    }
}
