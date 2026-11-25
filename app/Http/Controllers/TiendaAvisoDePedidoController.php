<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Helpers\asistente_ia\AvisoInmediatoDePedidoIaHelper;

/**
 * El empuje inmediato del aviso de pedido nuevo (misión whatsapp-aviso-pedido, 25/11/2026, contrato C2).
 *
 * `POST api/tienda/pedidos/{id}/aviso-al-asistente`: lo llama tienda-api apenas crea el pedido. Pública, sin
 * clave y con `throttle:60,1`; la razón de que sea segura está en AvisoInmediatoDePedidoIaHelper. La lógica
 * vive en el helper: acá sólo se traduce a HTTP.
 *
 * Respuestas: 202 `{motivo}` · 404 (pedido inexistente, de otro dueño o de más de 30 minutos) · 429 (throttle).
 *
 * PHP 7.4: sin match, sin nullsafe, sin str_contains.
 */
class TiendaAvisoDePedidoController extends Controller
{
    /**
     * Avisa (o no) al asistente del dueño del pedido nuevo.
     *
     * @param  mixed  $id  Id del pedido.
     * @return \Illuminate\Http\JsonResponse
     */
    public function avisar($id)
    {
        $resultado = AvisoInmediatoDePedidoIaHelper::avisar($id);

        if ($resultado['status'] === 404) {

            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        return response()->json(['motivo' => $resultado['motivo']], 202);
    }
}
