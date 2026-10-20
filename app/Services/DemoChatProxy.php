<?php

namespace App\Services;

use App\Http\Controllers\Helpers\DemoTrackingConfigHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** El navegador habla con su sesión; la credencial del canal queda siempre en el servidor. */
class DemoChatProxy
{
    public function solicitar($metodo, $sufijo = '', array $datos = [])
    {
        $config = DemoTrackingConfigHelper::actual();
        if (!$config || !$config->eventos_token) {
            return response()->json(['disponible' => false]);
        }

        $url = self::url($config->eventos_url, $sufijo);
        if (!$url) {
            return response()->json(['disponible' => false]);
        }

        try {
            $http = Http::withHeaders([
                'Accept' => 'application/json',
                'X-Demo-Eventos-Key' => $config->eventos_token,
            ])->timeout(8);
            $respuesta = $metodo === 'GET' ? $http->get($url) : $http->post($url, $datos);

            // Una actualización por etapas conserva el recorrido de la API anterior.
            if (in_array($respuesta->status(), [404, 405], true)) {
                return response()->json(['disponible' => false]);
            }
            if (!$respuesta->successful()) {
                $codigo = in_array($respuesta->status(), [409, 422, 429], true) ? $respuesta->status() : 503;
                return response()->json(['message' => 'No pudimos conectar con el asistente. Volvé a intentar.'], $codigo);
            }

            $cuerpo = $respuesta->json();
            if (!is_array($cuerpo) || !isset($cuerpo['disponible'])) {
                return response()->json(['message' => 'El asistente no respondió correctamente.'], 503);
            }
            return response()->json($cuerpo, $respuesta->status());
        } catch (\Throwable $e) {
            Log::warning('DemoChatProxy: no se pudo consultar al asistente: ' . DemoTrackingConfigHelper::ocultar_token($e->getMessage(), $config->eventos_token));
            return response()->json(['message' => 'No pudimos conectar con el asistente. Volvé a intentar.'], 503);
        }
    }

    public static function url($eventos_url, $sufijo = '')
    {
        $url = DemoMediaUrlsFetcher::url_del_endpoint($eventos_url);
        if (!$url) {
            return null;
        }
        // Solo el último segmento: ni el host ni una carpeta homónima se reescriben.
        $corte = strcspn($url, '?#');
        $camino = substr($url, 0, $corte);
        $resto = substr($url, $corte);
        if (!preg_match('~/demo-media-urls/?$~', $camino)) {
            return null;
        }
        return preg_replace('~/demo-media-urls/?$~', '/demo-chat' . $sufijo, $camino) . $resto;
    }
}
