<?php

namespace App\Http\Controllers;

use App\Models\Envio;
use App\Services\Zipnova\ZipnovaEnvioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook público al que Zipnova avisa los cambios de estado de un envío (misión
 * zipnova-envios, 14/9/2026). Se registra al conectar (`ZipnovaConexionService::conectar()`,
 * tópico `status`) y Zipnova hace `POST` con
 * `{topic: 'status', timestamp, data: {account_id, shipment_id, external_id, status, status_code, direction}}`.
 *
 * 🔴 RESPONDE SIEMPRE 200, aunque no encuentre el envío o falle la sincronización. Un 4xx/5xx
 * hace que Zipnova reintente cada hora durante 12 horas, y un envío que no es de esta instancia
 * (bases compartidas viejas, un webhook huérfano de una cuenta reconectada) no va a aparecer por
 * más que se reintente. Lo que no matchea se deja en el log en warning y se descarta.
 *
 * No se confía en el `status` que viene en el aviso: se hace `GET /shipments/{id}` con las
 * credenciales del comercio dueño del envío (`ZipnovaEnvioService::sincronizar()`). Es lo que
 * garantiza que un POST forjado a esta URL —no hay firma— no pueda escribir un estado que
 * Zipnova no tenga: lo peor que logra es una consulta de más.
 *
 * Sin auth Sanctum, con `throttle:120,1`. El comando `zipnova:sincronizar-envios` es la red de
 * abajo si el WAF del hosting frena estos POST (pasó con Kapso).
 */
class ZipnovaWebhookController extends Controller
{
    /**
     * POST zipnova/webhook.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function receive(Request $request)
    {
        $data = $request->input('data');
        if (!is_array($data)) {
            $data = [];
        }

        $shipment_id = isset($data['shipment_id']) && is_scalar($data['shipment_id']) ? trim((string) $data['shipment_id']) : '';
        $account_id = isset($data['account_id']) && is_scalar($data['account_id']) ? trim((string) $data['account_id']) : '';

        if ($shipment_id === '') {
            Log::warning('ZipnovaWebhookController: aviso sin data.shipment_id, se descarta. topic=' . (string) $request->input('topic'));

            return $this->ok();
        }

        $query = Envio::where('proveedor', Envio::PROVEEDOR_ZIPNOVA)
            ->where('proveedor_envio_id', $shipment_id);

        // El `account_id` acota la búsqueda en una base compartida entre comercios: el mismo id
        // de envío no se repite dentro de Zipnova, pero un envío guardado sin cuenta (fila vieja)
        // tiene que seguir matcheando.
        if ($account_id !== '') {
            $query->where(function ($q) use ($account_id) {
                $q->whereNull('account_id')->orWhere('account_id', $account_id);
            });
        }

        $envio = $query->orderBy('id', 'DESC')->first();

        if (is_null($envio)) {
            Log::warning('ZipnovaWebhookController: aviso para el envío ' . $shipment_id . ' (cuenta ' . $account_id . ') que no está en esta instancia, se descarta.');

            return $this->ok();
        }

        try {
            (new ZipnovaEnvioService())->sincronizar($envio);
        } catch (\Throwable $e) {
            Log::warning('ZipnovaWebhookController: no se pudo sincronizar el envío ' . $envio->id . ' (Zipnova ' . $shipment_id . '): ' . $e->getMessage());
        }

        return $this->ok();
    }

    /**
     * La única respuesta que existe.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function ok()
    {
        return response()->json(['ok' => true], 200);
    }
}
