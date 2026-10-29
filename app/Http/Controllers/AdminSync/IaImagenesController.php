<?php

namespace App\Http\Controllers\AdminSync;

use App\Http\Controllers\AdminSync\Concerns\ClaveEstrictaDeAdmin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Helpers\asistente_ia\AsistenteCanalHelper;
use App\Http\Controllers\Helpers\asistente_ia\PedidosDeImagenIaHelper;
use App\Models\IaImagenPedido;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El admin entrega una imagen generada para un pedido del agente (misión app-ia-y-memoria,
 * 29/10/2026, contrato C1.4): `POST admin-sync/ia-imagenes/{uuid}/resultado`, multipart.
 *
 * Campos: `estado` (lista | fallida, obligatorio), `imagen` (png/jpg/jpeg/webp ≤ 15 MB, obligatoria con
 * `lista`), `error` (≤ 500, opcional).
 *
 * 🔴 LA CLAVE SE EXIGE SIEMPRE (ClaveEstrictaDeAdmin): esta ruta ESCRIBE un archivo en el disco del
 * cliente y lo muestra en el chat del dueño. Y la tenencia es por dueño: en una base compartida, un
 * uuid de otro negocio da 404 igual que uno que no existe.
 *
 * Idempotente: un segundo `lista` del mismo uuid contesta 200 sin tocar nada (el admin reintenta ante un
 * timeout), y un `fallida` que llega después de una imagen que ya está no la borra.
 */
class IaImagenesController extends Controller
{
    use ClaveEstrictaDeAdmin;

    /**
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $uuid
     * @return \Illuminate\Http\JsonResponse  200 {ok, estado} · 401 · 404 pedido_inexistente · 422
     */
    public function resultado(Request $request, $uuid): JsonResponse
    {
        if (!$this->clave_valida($request)) {

            return response()->json([
                'error'   => 'unauthorized',
                'code'    => 'clave_invalida',
                'message' => 'Clave de admin inválida.',
            ], 401, [], JSON_UNESCAPED_UNICODE);
        }

        $query = IaImagenPedido::where('uuid', (string) $uuid);

        $dueno = AsistenteCanalHelper::dueno();

        if (!is_null($dueno)) {
            $query->where('user_id', (int) $dueno->id);
        }

        $pedido = $query->first();

        if (is_null($pedido)) {

            return response()->json([
                'code'    => 'pedido_inexistente',
                'message' => 'No hay un pedido de imagen con ese uuid.',
            ], 404, [], JSON_UNESCAPED_UNICODE);
        }

        $estado = (string) $request->input('estado');

        if (!in_array($estado, [IaImagenPedido::ESTADO_LISTA, IaImagenPedido::ESTADO_FALLIDA], true)) {

            return $this->invalido('`estado` tiene que ser lista o fallida.');
        }

        $error = $request->input('error');

        if (!is_null($error) && (!is_string($error) || mb_strlen($error) > 500)) {

            return $this->invalido('`error` tiene que ser un texto de hasta 500 caracteres.');
        }

        /* Ya está: un reintento del admin no cambia nada (ni valida de nuevo la imagen). */
        if ($pedido->estado === IaImagenPedido::ESTADO_LISTA) {

            return response()->json(['ok' => true, 'estado' => IaImagenPedido::ESTADO_LISTA], 200);
        }

        $imagen = $request->file('imagen');

        if ($estado === IaImagenPedido::ESTADO_LISTA) {

            $motivo = PedidosDeImagenIaHelper::motivo_de_rechazo_del_resultado($imagen);

            if (!is_null($motivo)) {

                return $this->invalido($motivo);
            }
        }

        $final = PedidosDeImagenIaHelper::recibir_resultado($pedido, $estado, $imagen, $error);

        return response()->json(['ok' => true, 'estado' => $final], 200);
    }

    /**
     * @param  string  $motivo
     * @return \Illuminate\Http\JsonResponse
     */
    protected function invalido($motivo): JsonResponse
    {
        return response()->json([
            'code'    => 'validacion',
            'message' => $motivo,
        ], 422, [], JSON_UNESCAPED_UNICODE);
    }
}
